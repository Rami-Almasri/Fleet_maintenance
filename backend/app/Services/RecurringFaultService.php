<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\MaintenanceTask;
use App\Models\RecurringFaultReview;
use App\Models\RepairInspection;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

/**
 * Recurring-Fault Intelligence — detects a vehicle returning with the SAME confirmed problem after a
 * completed repair, and opens a management review case, WITHOUT raising false duplicate alerts.
 *
 * The flow is deliberately staged (see the feature spec):
 *   1. REPORT time  → flagPossibleRecurrence(): a silent background check. If the same fault was already
 *                     FIXED on this car, we raise a soft `recurrence_flagged` marker on the new fault.
 *                     It never blocks the workflow — a report is just a claim until the workshop checks it.
 *   2. WORKSHOP      → the technician confirms each fault (MaintenanceTaskService::confirmFault). ONLY a
 *                     `confirmed` verdict calls onFaultConfirmed() here.
 *   3. onFaultConfirmed() → if the confirmed fault matches a previous FIXED occurrence, open a
 *                     RecurringFaultReview (idempotent, one per fault). Management decides from there.
 *
 * Deliberate rules (edge-case guards):
 *   • Never flag/open unless the previous fault was actually marked Fixed (status completed + resolved_at).
 *   • Never against the current ticket, and never while the car is still under the previous repair
 *     (an in-progress prior fault is `pending`/`in_progress`, so the completed-only filter excludes it).
 *   • The garage is NEVER auto-blamed — we only open the case; a human assigns responsibility.
 *
 * The detector aligns with PartIntelligenceService::detectRecurrence + RepairInspectionService::findPriorFix
 * (same 90-day window, same category/symptom match) and additionally: requires "Fixed", prefers a verified
 * post-repair inspection, and computes distance-driven-since-repair from the odometer chain.
 */
class RecurringFaultService
{
    /** Days within which a returning fault counts as a recurrence (shared with the parts recurrence window). */
    private function windowDays(): int
    {
        return (int) config('parts_intelligence.recurrence.window_days', 90);
    }

    /**
     * REPORT-TIME background check. Silently marks the fault as a "possible recurring fault" when the same
     * fault was previously FIXED on this vehicle. Never throws, never blocks — safe to call on every new
     * fault created from a report.
     */
    public function flagPossibleRecurrence(MaintenanceTask $fault): void
    {
        $match = $this->detectPriorFix($fault);
        if (! $match) {
            return;
        }

        // saveQuietly: this is a background annotation — it must not trigger the parent-ticket roll-up.
        $fault->forceFill([
            'recurrence_flagged'          => true,
            'recurrence_previous_task_id' => $match['previous_task']->id,
        ])->saveQuietly();
    }

    /**
     * WORKSHOP-CONFIRMED trigger. Called only when a fault's verdict becomes `confirmed`. If the confirmed
     * fault matches a previous FIXED occurrence, open (once) a RecurringFaultReview. Returns the review, an
     * existing one, or null when there is no recurrence.
     */
    public function onFaultConfirmed(MaintenanceTask $fault, User $actor): ?RecurringFaultReview
    {
        // Idempotent: one review per confirmed fault, even if it is re-confirmed.
        $existing = RecurringFaultReview::where('maintenance_task_id', $fault->id)->first();
        if ($existing) {
            return $existing;
        }

        $match = $this->detectPriorFix($fault);
        if (! $match) {
            return null;
        }

        return $this->openReview($fault, $actor, $match);
    }

    /**
     * The DETECTOR. For a given fault, find the latest previous occurrence on the same vehicle that was
     * FIXED (status completed + resolved_at) within the recurrence window, matched on category_key (else
     * lower/trim symptom), excluding this fault and its own ticket. Returns a rich snapshot or null.
     *
     * @return array{previous_task:MaintenanceTask, previous_maintenance:?\App\Models\Maintenance,
     *               repair_inspection:?RepairInspection, occurrence_count:int, previous_result:string,
     *               previous_garage_id:?int, previous_garage_name:?string, previous_repaired_at:?Carbon,
     *               days_since_repair:?int, previous_odometer:?int, current_odometer:?int,
     *               distance_since_repair:?int, parts:array}|null
     */
    public function detectPriorFix(MaintenanceTask $fault): ?array
    {
        if (! $fault->vehicle_id) {
            return null;
        }

        $since = Carbon::now()->subDays($this->windowDays());

        $base = MaintenanceTask::query()
            ->where('vehicle_id', $fault->vehicle_id)
            ->where('id', '!=', $fault->id)
            ->where('maintenance_id', '!=', $fault->maintenance_id) // never the current ticket
            ->where('status', MaintenanceTask::STATUS_COMPLETED)     // RULE: previous must be Fixed
            // Event Type layer: a recurring FAULT must not be "confirmed" by a prior planned service.
            ->when(\App\Support\EventKind::enforced(), fn ($q) => $q->faults())
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $since);

        if ($fault->category_key) {
            $base->where('category_key', $fault->category_key);
        } else {
            $base->whereRaw('LOWER(TRIM(symptom)) = ?', [mb_strtolower(trim((string) $fault->symptom))]);
        }

        /** @var MaintenanceTask|null $previous */
        $previous = (clone $base)
            ->with(['lineItems', 'currentVendor:id,name', 'maintenance'])
            ->orderByDesc('resolved_at')
            ->first();
        if (! $previous) {
            return null;
        }

        // How many times this fault has been FIXED before (within the window) — the recurrence count.
        $priorFixedCount = (clone $base)->count();

        // Prefer a verified post-repair inspection sign-off for that previous fault (stronger than "completed").
        $inspection = RepairInspection::query()
            ->where('fault_id', $previous->id)
            ->where('result', RepairInspection::RESULT_FIXED)
            ->latest('inspection_date')
            ->first();

        $prevTicket = $previous->maintenance;

        // Odometer at the previous repair — take the most trustworthy stage reading available, else the
        // odometer at which a part was installed on that fault.
        $previousOdometer = $prevTicket
            ? ($prevTicket->reinspect_odometer ?? $prevTicket->return_odometer ?? $prevTicket->receive_odometer)
            : null;
        if ($previousOdometer === null) {
            $installed = $previous->lineItems->firstWhere(fn ($li) => $li->installed_odometer !== null);
            $previousOdometer = $installed?->installed_odometer;
        }

        $currentOdometer = Vehicle::whereKey($fault->vehicle_id)->value('odometer');

        $distance = ($previousOdometer !== null && $currentOdometer !== null && (int) $currentOdometer >= (int) $previousOdometer)
            ? (int) $currentOdometer - (int) $previousOdometer
            : null;

        $repairedAt = $previous->resolved_at;
        $daysSince  = $repairedAt
            ? (int) $repairedAt->copy()->startOfDay()->diffInDays(Carbon::now()->startOfDay())
            : null;

        $parts = $previous->lineItems
            ->where('kind', 'part')
            ->map(fn ($li) => ['description' => $li->description, 'part_number' => $li->part_number])
            ->values()
            ->all();

        return [
            'previous_task'         => $previous,
            'previous_maintenance'  => $prevTicket,
            'repair_inspection'     => $inspection,
            'occurrence_count'      => $priorFixedCount + 1, // prior fixes + this recurrence
            'previous_result'       => $inspection ? RecurringFaultReview::RESULT_VERIFIED_FIXED : RecurringFaultReview::RESULT_FIXED,
            'previous_garage_id'    => $previous->current_vendor_id,
            'previous_garage_name'  => $previous->currentVendor?->name,
            'previous_repaired_at'  => $repairedAt,
            'days_since_repair'     => $daysSince,
            'previous_odometer'     => $previousOdometer !== null ? (int) $previousOdometer : null,
            'current_odometer'      => $currentOdometer !== null ? (int) $currentOdometer : null,
            'distance_since_repair' => $distance,
            'parts'                 => $parts,
        ];
    }

    /**
     * Record a management decision on a review (the ONLY place responsibility is assigned — by a human).
     */
    public function decide(RecurringFaultReview $review, User $actor, string $decision, ?string $note = null): RecurringFaultReview
    {
        if (! in_array($decision, RecurringFaultReview::DECISIONS, true)) {
            throw new WorkflowTransitionException('Unknown decision: ' . $decision, ['field' => 'decision']);
        }

        $review->forceFill([
            'status'          => RecurringFaultReview::STATUS_DECIDED,
            'decision'        => $decision,
            'decision_note'   => $note !== null ? (trim($note) ?: null) : null,
            'decided_by'      => $actor->id,
            'decided_by_name' => $actor->name ?: $actor->email,
            'decided_at'      => Carbon::now(),
        ])->save();

        return $review->fresh();
    }

    /**
     * The Recurring Fault Reviews list, filtered for the management page.
     *
     * @param array{status?:?string, decision?:?string, vehicle_id?:?int} $filters
     */
    public function index(array $filters = [])
    {
        return RecurringFaultReview::query()
            ->with([
                'vehicle:id,plate_no,make,model',
                'task:id,symptom,severity,repair_gate',
                'previousGarage:id,name',
            ])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['decision'] ?? null, fn ($q, $d) => $q->where('decision', $d))
            ->when($filters['vehicle_id'] ?? null, fn ($q, $v) => $q->where('vehicle_id', $v))
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->limit(300)
            ->get();
    }

    // ── Internals ──────────────────────────────────────────────────────────────────────────────────

    /** Build + persist the review case from a detector match. */
    private function openReview(MaintenanceTask $fault, User $actor, array $match): RecurringFaultReview
    {
        $previous   = $match['previous_task'];
        $prevTicket = $match['previous_maintenance'];
        $inspection = $match['repair_inspection'];

        $context = [
            'symptom'               => $fault->symptom,
            'category_key'          => $fault->category_key,
            'window_days'           => $this->windowDays(),
            'verified'              => (bool) $inspection,
            'occurrence_count'      => $match['occurrence_count'],
            'days_since_repair'     => $match['days_since_repair'],
            'distance_since_repair' => $match['distance_since_repair'],
            'previous'              => [
                'ticket_id'   => $previous->maintenance_id,
                'task_id'     => $previous->id,
                'garage'      => $match['previous_garage_name'],
                'result'      => $match['previous_result'],
                'repaired_at' => optional($match['previous_repaired_at'])->toIso8601String(),
                'odometer'    => $match['previous_odometer'],
                'parts'       => $match['parts'],
            ],
            'current' => [
                'ticket_id' => $fault->maintenance_id,
                'task_id'   => $fault->id,
                'odometer'  => $match['current_odometer'],
            ],
        ];

        $review = RecurringFaultReview::create([
            'status'                  => RecurringFaultReview::STATUS_OPEN,
            'vehicle_id'              => $fault->vehicle_id,
            'maintenance_id'          => $fault->maintenance_id,
            'maintenance_task_id'     => $fault->id,
            'previous_maintenance_id' => $prevTicket?->id,
            'previous_task_id'        => $previous->id,
            'repair_inspection_id'    => $inspection?->id,
            'symptom'                 => $fault->symptom,
            'category_key'            => $fault->category_key,
            'previous_garage_id'      => $match['previous_garage_id'],
            'previous_garage_name'    => $match['previous_garage_name'],
            'previous_result'         => $match['previous_result'],
            'previous_repaired_at'    => $match['previous_repaired_at'],
            'days_since_repair'       => $match['days_since_repair'],
            'previous_odometer'       => $match['previous_odometer'],
            'current_odometer'        => $match['current_odometer'],
            'distance_since_repair'   => $match['distance_since_repair'],
            'occurrence_count'        => $match['occurrence_count'],
            'parts'                   => $match['parts'],
            'context'                 => $context,
            'opened_by'               => $actor->id,
            'opened_by_name'          => $actor->name ?: $actor->email,
            'opened_at'               => Carbon::now(),
        ]);

        $this->announceReview($review, $fault, $actor, $match);

        return $review;
    }

    /**
     * Tell management a repeat-fault case just opened. The audience is by ROLE (super-admin + admin), not by
     * permission: this is an oversight alert — the people accountable for a garage's rework must hear about it
     * even if `maintenance.recurring.view` was never re-synced onto their role. The actor (the technician who
     * confirmed the fault) is excluded — they already know; they just did it.
     *
     * Deliberately non-fatal: a mail/DB hiccup must never roll back a review case that the workshop's
     * confirmation already justified. NotificationScanner is resolved lazily so the fault-write path
     * (flagPossibleRecurrence runs on EVERY reported fault) does not eagerly build the scanner's whole
     * dependency chain.
     */
    private function announceReview(RecurringFaultReview $review, MaintenanceTask $fault, User $actor, array $match): void
    {
        try {
            $vehicle = $fault->vehicle_id ? Vehicle::find($fault->vehicle_id) : null;
            $label   = $vehicle
                ? trim($vehicle->plate_no . ' · ' . trim($vehicle->make . ' ' . $vehicle->model))
                : 'Vehicle #' . $fault->vehicle_id;

            // The three numbers that decide whether this is rework or bad luck.
            $facts = [];
            if ($match['days_since_repair'] !== null) {
                $facts[] = $match['days_since_repair'] . ' days after the last repair';
            }
            if ($match['distance_since_repair'] !== null) {
                $facts[] = number_format($match['distance_since_repair']) . ' km driven since';
            }
            if ($match['previous_garage_name']) {
                $facts[] = 'previously repaired by ' . $match['previous_garage_name'];
            }
            $facts[] = 'occurrence #' . $match['occurrence_count'];

            app(\App\Services\NotificationScanner::class)->notifyByRole(['super-admin', 'admin'], [
                'type'     => 'maint_recurring_fault_review',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => '🔁 Same fault came back · ' . $label,
                'body'     => trim('"' . $fault->symptom . '" was confirmed again on ' . $label . ' — '
                                . implode(', ', $facts) . '. The repair is blocked pending a management ruling.'),
                'url'      => '/recurring-fault-reviews',
                'key'      => 'recurring_fault_review:' . $review->id,
                'icon'     => 'wrench',
                'meta'     => [
                    'review_id'             => $review->id,
                    'ticket_id'             => $fault->maintenance_id,
                    'task_id'               => $fault->id,
                    'vehicle_id'            => $fault->vehicle_id,
                    'plate'                 => $vehicle?->plate_no,
                    'symptom'               => $fault->symptom,
                    'previous_ticket_id'    => $match['previous_task']->maintenance_id,
                    'previous_garage'       => $match['previous_garage_name'],
                    'days_since_repair'     => $match['days_since_repair'],
                    'distance_since_repair' => $match['distance_since_repair'],
                    'occurrence_count'      => $match['occurrence_count'],
                    'confirmed_by'          => $actor->name ?: $actor->email,
                ],
            ], $actor->id);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
