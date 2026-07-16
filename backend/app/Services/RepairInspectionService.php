<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\MaintenanceTask;
use App\Models\RepairInspection;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Post-Repair Inspection layer — records the structured QC verdict a car gets at the
 * WF_READY_REINSPECTION gate and drives the "did the fix hold?" intelligence on top of it.
 *
 * This service NEVER moves a ticket through the state machine — the existing PASS (close) / FAIL
 * (markReinspectionFailed) transitions in MaintenanceWorkflowService still own that. It sits alongside
 * them: the controller calls the workflow to advance the ticket AND this service to persist the durable
 * repair_inspections row + fire the repeated-failure / part-failure alerts. Keeping it separate is what
 * makes the feature a clean layer — a legacy ticket that never records an inspection still closes fine.
 */
class RepairInspectionService
{
    /** How far back a prior FIXED repair of the same fault still counts as a recurrence (a returned fix). */
    public const RECURRENCE_LOOKBACK_DAYS = 90;

    private const NOTIFY_DISPATCHER  = 'maintenance.delegate';  // Supervisors — they re-dispatch a returned car
    private const NOTIFY_CONTROLLERS = 'maintenance.manage';    // Controllers/managers — quality oversight

    public function __construct(private NotificationScanner $notifier)
    {
    }

    /**
     * Record ONE fault's post-repair verdict (Case A "fixed" or Case B "still_exists"). Snapshots the
     * repair being judged, detects a repeated failure (same fault fixed recently, now back), and — for a
     * still_exists — raises the Repair Failure Alert (HIGH-PRIORITY when it is a recurrence). The actual
     * fault reopen / completion is done by the caller via MaintenanceTaskService; this only records + alerts.
     */
    public function recordForFault(
        Maintenance $ticket,
        MaintenanceTask $fault,
        string $result,
        ?string $failureReason,
        ?string $notes,
        User $actor,
    ): RepairInspection {
        $result = in_array($result, RepairInspection::RESULTS, true) ? $result : RepairInspection::RESULT_FIXED;
        $isFail = $result === RepairInspection::RESULT_STILL_EXISTS;

        // The repair THIS ticket's garage just performed on the fault — the default "previous repair" for
        // the failure card (garage + when it reported done).
        $vendorId   = $fault->current_vendor_id ?: $ticket->vendor_id;
        $repairedAt = $ticket->returned_at ?: $ticket->ready_at ?: $fault->resolved_at;

        // Repeated-failure watchdog — was this SAME fault signed off as fixed on a recent PRIOR ticket?
        // If so, THAT is the repair that failed to hold, so the card should blame it (its garage + date),
        // and the alert is escalated. Only meaningful for a still_exists verdict.
        $recurrence = $isFail ? $this->findPriorFix($ticket, $fault) : null;
        if ($recurrence) {
            $vendorId   = $recurrence->previous_vendor_id ?: $recurrence->maintenance?->vendor_id ?: $vendorId;
            $repairedAt = $recurrence->inspection_date ?: $repairedAt;
        }

        $daysSince = $repairedAt ? max(0, $repairedAt->copy()->startOfDay()->diffInDays(Carbon::now()->startOfDay())) : null;

        $inspection = RepairInspection::create([
            'maintenance_id'       => $ticket->id,
            'vehicle_id'           => $ticket->vehicle_id,
            'fault_id'             => $fault->id,
            'inspector_id'         => $actor->id,
            'result'               => $result,
            'failure_reason'       => $isFail ? $this->normReason($failureReason) : null,
            'notes'                => $this->clean($notes),
            'previous_vendor_id'   => $vendorId,
            'previous_repaired_at' => $repairedAt,
            'days_since_repair'    => $daysSince,
            'is_recurrence'        => (bool) $recurrence,
            'inspection_date'      => Carbon::now(),
        ]);

        if ($isFail) {
            $this->alertRepairFailure($ticket, $fault, $inspection, (bool) $recurrence);
        }

        return $inspection;
    }

    /**
     * Case C — the inspection surfaced a BRAND-NEW problem (the original is fixed, but something else is
     * wrong). Records the verdict against the freshly-created fault so the new fault is traceable back to
     * the inspection that found it. The new MaintenanceTask itself is created by the caller.
     */
    public function recordNewIssue(Maintenance $ticket, MaintenanceTask $newFault, ?string $notes, User $actor): RepairInspection
    {
        return RepairInspection::create([
            'maintenance_id'  => $ticket->id,
            'vehicle_id'      => $ticket->vehicle_id,
            'new_fault_id'    => $newFault->id,
            'inspector_id'    => $actor->id,
            'result'          => RepairInspection::RESULT_NEW_ISSUE,
            'notes'           => $this->clean($notes),
            'inspection_date' => Carbon::now(),
        ]);
    }

    // ── Intelligence reads ─────────────────────────────────────────────────────────────────────────

    /**
     * Repair-quality per garage ("technician" in the spec — this system's repair actor is the garage
     * vendor, which has no login). Repairs completed vs how many came back still broken → success rate.
     * Ranked worst-first so a repeat offender surfaces. Garages with no completed repairs are omitted.
     */
    public function technicianQuality(): array
    {
        // Completed repairs per garage — a fault resolved at a garage stint (the work it actually did).
        $completed = DB::table('maintenance_tasks')
            ->select('current_vendor_id as vendor_id', DB::raw('count(*) as n'))
            ->whereNotNull('current_vendor_id')
            ->where('status', MaintenanceTask::STATUS_COMPLETED)
            ->groupBy('current_vendor_id')
            ->pluck('n', 'vendor_id');

        // Returned problems per garage — still_exists verdicts blamed on that garage's repair.
        $returned = DB::table('repair_inspections')
            ->select('previous_vendor_id as vendor_id', DB::raw('count(*) as n'))
            ->whereNotNull('previous_vendor_id')
            ->whereIn('result', RepairInspection::RETURNED_RESULTS)
            ->groupBy('previous_vendor_id')
            ->pluck('n', 'vendor_id');

        $vendorIds = $completed->keys()->merge($returned->keys())->unique()->filter()->values();
        if ($vendorIds->isEmpty()) {
            return [];
        }
        $names = Vendor::whereIn('id', $vendorIds)->pluck('name', 'id');

        $rows = $vendorIds->map(function ($vid) use ($completed, $returned, $names) {
            $done     = (int) ($completed[$vid] ?? 0);
            $back     = (int) ($returned[$vid] ?? 0);
            // Successful ≈ completed repairs that did NOT come back (floored at 0 for the odd case where a
            // returned fault has no matching completed row, e.g. a legacy import).
            $success  = max(0, $done - $back);
            $rate     = $done > 0 ? round($success / $done * 100) : null;
            return [
                'vendor_id'          => (int) $vid,
                'garage'             => $names[$vid] ?? ('Garage #' . $vid),
                'repairs_completed'  => $done,
                'returned_problems'  => $back,
                'successful'         => $success,
                'success_rate'       => $rate,
            ];
        })
        ->sortBy(fn ($r) => $r['success_rate'] ?? 101)   // worst rate first; unknown rate last
        ->values()
        ->all();

        return $rows;
    }

    /**
     * Parts Intelligence — a part that was installed and whose fault then came back still broken is a
     * "Possible Part Failure". Links vehicle + fault + part + inspection: joins still_exists verdicts to
     * the part lines on the same fault, so a suspect part surfaces for review.
     */
    public function partFailureSignals(int $limit = 100): array
    {
        $fails = RepairInspection::returned()
            ->whereNotNull('fault_id')
            ->with(['vehicle:id,plate_no,make,model', 'previousVendor:id,name', 'fault:id,symptom'])
            ->latest('inspection_date')
            ->limit($limit)
            ->get();

        $signals = [];
        foreach ($fails as $insp) {
            $parts = MaintenanceLineItem::where('maintenance_task_id', $insp->fault_id)
                ->where('kind', MaintenanceLineItem::KIND_PART)
                ->get(['id', 'description', 'part_number', 'installed_on']);

            foreach ($parts as $part) {
                $signals[] = [
                    'inspection_id'   => $insp->id,
                    'vehicle_id'      => $insp->vehicle_id,
                    'plate'           => $insp->vehicle?->plate_no,
                    'fault'           => $insp->fault?->symptom,
                    'part'            => $part->description,
                    'part_number'     => $part->part_number,
                    'installed_on'    => optional($part->installed_on)->toDateString(),
                    'garage'          => $insp->previousVendor?->name,
                    'failure_reason'  => $insp->failure_reason,
                    'days_since_repair' => $insp->days_since_repair,
                    'is_recurrence'   => (bool) $insp->is_recurrence,
                    'inspected_at'    => optional($insp->inspection_date)->toIso8601String(),
                ];
            }
        }

        return $signals;
    }

    /** Every recorded verdict for one vehicle, newest first — the vehicle's repair-quality history. */
    public function vehicleHistory(int $vehicleId): \Illuminate\Support\Collection
    {
        return RepairInspection::forVehicle($vehicleId)
            ->with(['fault:id,symptom', 'newFault:id,symptom', 'inspector:id,name', 'previousVendor:id,name'])
            ->latest('inspection_date')
            ->get();
    }

    // ── Internals ───────────────────────────────────────────────────────────────────────────────────

    /**
     * Find the most recent PRIOR "fixed" verdict for the same fault on the same car (a repair that held
     * long enough to close, now come back). Match on category_key when the fault carries one, else on the
     * (lower-cased) symptom text. Bounded to RECURRENCE_LOOKBACK_DAYS so an ancient unrelated fix doesn't
     * trip it. Excludes this ticket's own rows.
     */
    private function findPriorFix(Maintenance $ticket, MaintenanceTask $fault): ?RepairInspection
    {
        $since = Carbon::now()->subDays(self::RECURRENCE_LOOKBACK_DAYS);

        return RepairInspection::query()
            ->where('vehicle_id', $ticket->vehicle_id)
            ->where('maintenance_id', '!=', $ticket->id)
            ->where('result', RepairInspection::RESULT_FIXED)
            ->where('inspection_date', '>=', $since)
            ->whereHas('fault', function ($q) use ($fault) {
                if ($fault->category_key) {
                    $q->where('category_key', $fault->category_key);
                } else {
                    $q->whereRaw('LOWER(TRIM(symptom)) = ?', [mb_strtolower(trim((string) $fault->symptom))]);
                }
            })
            ->with('maintenance:id,vendor_id')
            ->latest('inspection_date')
            ->first();
    }

    /**
     * Repair Failure Alert — tell the supervisors (who re-dispatch) and controllers (quality oversight)
     * that a repair did not hold. A recurrence (same fault back within the window) is escalated to a
     * HIGH-PRIORITY "Repeated Repair Failure" so a chronic problem is not lost in the noise.
     */
    private function alertRepairFailure(Maintenance $ticket, MaintenanceTask $fault, RepairInspection $insp, bool $recurrence): void
    {
        $vehicle = $ticket->loadMissing('vehicle')->vehicle;
        $label   = $vehicle
            ? trim(($vehicle->plate_no ? $vehicle->plate_no . ' · ' : '') . trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '')))
            : ('Ticket #' . $ticket->id);
        $garage  = $insp->previousVendor?->name ?: ($ticket->vendor?->name ?: 'the garage');
        $reason  = $this->reasonLabel($insp->failure_reason);

        $title = $recurrence
            ? '🔁 Repeated repair failure · ' . $label
            : '⚠️ Repair failed re-inspection · ' . $label;

        $body = $recurrence
            ? trim($label . ' returned with the SAME fault "' . $fault->symptom . '"'
                . ($insp->days_since_repair !== null ? ' — ' . $insp->days_since_repair . ' day(s) after it was fixed at ' . $garage : '')
                . '. Reason: ' . $reason . '. Please investigate a chronic problem.')
            : trim($garage . ' returned ' . $label . ' but "' . $fault->symptom . '" is still not fixed. Reason: ' . $reason . '.');

        $this->notifier->notifyByAnyPermission([self::NOTIFY_DISPATCHER, self::NOTIFY_CONTROLLERS], [
            'type'     => $recurrence ? 'repair_repeated_failure' : 'repair_failed_reinspection',
            'category' => 'maintenance',
            'severity' => $recurrence ? 'critical' : 'warning',
            'title'    => $title,
            'body'     => $body,
            'url'      => '/maintenance-workflow/' . $ticket->id,
            'key'      => 'repair_insp:' . $insp->id,
            'icon'     => 'wrench',
            'meta'     => [
                'ticket_id'      => $ticket->id,
                'fault_id'       => $fault->id,
                'inspection_id'  => $insp->id,
                'plate'          => $vehicle?->plate_no,
                'garage'         => $garage,
                'failure_reason' => $insp->failure_reason,
                'is_recurrence'  => $recurrence,
                'days_since'     => $insp->days_since_repair,
            ],
        ]);
    }

    private function normReason(?string $reason): string
    {
        $reason = $reason ? trim($reason) : '';
        return in_array($reason, RepairInspection::REASONS, true) ? $reason : RepairInspection::REASON_UNKNOWN;
    }

    private function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            RepairInspection::REASON_WRONG_DIAGNOSIS   => 'Wrong diagnosis',
            RepairInspection::REASON_PART_FAILED       => 'Part failed',
            RepairInspection::REASON_REPAIR_INCOMPLETE => 'Repair incomplete',
            RepairInspection::REASON_WRONG_PART        => 'Wrong part',
            RepairInspection::REASON_CUSTOMER_COMPLAINT => 'Customer complaint',
            default                                     => 'Unknown',
        };
    }

    private function clean(?string $s): ?string
    {
        $s = $s !== null ? trim($s) : null;
        return $s === '' ? null : $s;
    }
}
