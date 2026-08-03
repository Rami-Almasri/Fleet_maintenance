<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\MaintenanceTask;
use App\Models\RecurringFaultReview;
use App\Models\RepairInspection;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
     * Is this event even a candidate for recurrence analysis? ONLY a fault is.
     *
     * A planned service RECURRING IS NORMAL — that is the defining difference between the two types
     * (see the ADR §0 type table: service = "recurring is normal"). Flagging a second oil change as a
     * "possible recurring fault" is not a display glitch: it persists `recurrence_flagged` on the row and
     * opens a RecurringFaultReview case for management. So this guard is deliberately UNCONDITIONAL —
     * it is not gated on EventKind::enforced(), because the rollout flag governs how fault ANALYTICS are
     * READ, never whether a write is correct. Inspections are excluded for the same reason.
     */
    private function isRecurrenceEligible(MaintenanceTask $fault): bool
    {
        // Reads the RELIABILITY predicate rather than `isFault()` so the rule stays one decision as the
        // domain grows. Damage is the reason this matters now: a car whose rims are kerbed twice is a
        // statement about its drivers, not a returning fault, and opening a RecurringFaultReview for it
        // would put a management case on the wrong table entirely.
        return $fault->affectsReliability();
    }

    /**
     * REPORT-TIME background check. Silently marks the fault as a "possible recurring fault" when the same
     * fault was previously FIXED on this vehicle. Never throws, never blocks — safe to call on every new
     * fault created from a report.
     */
    public function flagPossibleRecurrence(MaintenanceTask $fault): void
    {
        if (! $this->isRecurrenceEligible($fault)) {
            return;
        }

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
        if (! $this->isRecurrenceEligible($fault)) {
            return null;
        }

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
        if (! $fault->vehicle_id || ! $this->isRecurrenceEligible($fault)) {
            return null;
        }

        $since = Carbon::now()->subDays($this->windowDays());

        $base = MaintenanceTask::query()
            ->where('vehicle_id', $fault->vehicle_id)
            ->where('id', '!=', $fault->id)
            ->where('maintenance_id', '!=', $fault->maintenance_id) // never the current ticket
            ->where('status', MaintenanceTask::STATUS_COMPLETED)     // RULE: previous must be Fixed
            // Event Type layer: a recurring FAULT must not be "confirmed" by a prior planned service.
            // UNCONDITIONAL — not gated on EventKind::enforced(). This detector WRITES (recurrence_flagged,
            // recurrence_previous_task_id, recurring_fault_reviews), and a rollout flag must never decide
            // whether persisted data is correct. See docs/Service-Fault-Separation-Audit.md C3.
            ->whereIn('kind', MaintenanceTask::RELIABILITY_KINDS)
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

    /**
     * FLEET-WIDE analytics for the review dashboard. Deliberately NOT filtered by the table's status /
     * decision selectors: the table answers "what must I rule on now", the charts answer "how is rework
     * trending across the fleet". Running them off the filtered list would make the decision mix read as
     * ~100% "awaiting a ruling" whenever the page sits on its default `open` filter.
     *
     * Every number here is a FACT counted from recurring_fault_reviews — no scoring, no inference.
     *
     * The two "who keeps coming back" rankings each carry their OWN date window, applied to when the
     * case was opened. They are the rankings that go stale: a brake batch replaced in March tops the
     * all-time fault list months after it stopped recurring, and a car sold in spring stays on the
     * worst-offenders list forever. Each window scopes its own ranking and nothing else — the KPIs, the
     * 12-month trend, the speed histogram and the garage roll-up stay all-time whatever is passed, so
     * narrowing one panel can never move a number the reader is not looking at.
     *
     * @param array{days?:?int, from?:?string, to?:?string} $faultWindow scopes "faults that keep coming
     *        back": trailing days (0/absent = all time), or an explicit from/to range which overrides
     *        `days` when either end is set.
     * @param array{days?:?int, from?:?string, to?:?string} $carWindow   the same, for "cars that keep
     *        coming back". Independent of $faultWindow.
     */
    public function stats(array $faultWindow = [], array $carWindow = []): array
    {
        $now         = Carbon::now();
        $windowStart = $now->copy()->startOfMonth()->subMonths(11); // 12 calendar months inclusive

        // One pass over the columns the charts need; the table is small (one row per recurrence) and this
        // keeps the month/bucket/garage roll-ups consistent with each other.
        $rows = RecurringFaultReview::query()
            ->select([
                'id', 'status', 'decision', 'vehicle_id', 'symptom', 'category_key',
                'previous_garage_id', 'previous_garage_name', 'previous_result',
                'days_since_repair', 'distance_since_repair', 'occurrence_count', 'opened_at',
            ])
            ->with(['vehicle:id,plate_no,make,model'])
            ->get();

        $total   = $rows->count();
        $open    = $rows->where('status', RecurringFaultReview::STATUS_OPEN)->count();
        $decided = $total - $open;

        // Momentum: this 30 days vs the 30 before it. The delta is what an admin actually reacts to.
        $last30 = $rows->filter(fn ($r) => $r->opened_at && $r->opened_at->gte($now->copy()->subDays(30)))->count();
        $prev30 = $rows->filter(fn ($r) => $r->opened_at
            && $r->opened_at->lt($now->copy()->subDays(30))
            && $r->opened_at->gte($now->copy()->subDays(60)))->count();

        // Median, not mean: one fault that came back after 89 days must not drag the headline.
        $dayValues = $rows->pluck('days_since_repair')->filter(fn ($d) => $d !== null)->sort()->values();
        $medianDays = $dayValues->isEmpty() ? null : (int) round(
            $dayValues->count() % 2
                ? $dayValues[intdiv($dayValues->count(), 2)]
                : ($dayValues[$dayValues->count() / 2 - 1] + $dayValues[$dayValues->count() / 2]) / 2
        );

        // ── Trend: recurrences opened per calendar month (12 months, zero-filled) ──────────────────
        $byMonth = $rows->filter(fn ($r) => $r->opened_at && $r->opened_at->gte($windowStart))
            ->groupBy(fn ($r) => $r->opened_at->format('Y-m'));
        $trend = [];
        for ($i = 0; $i < 12; $i++) {
            $m     = $windowStart->copy()->addMonths($i);
            $bucket = $byMonth->get($m->format('Y-m'));
            $trend[] = [
                'key'      => $m->format('Y-m'),
                'label'    => $m->format('M'),
                'value'    => $bucket?->count() ?? 0,
                'verified' => $bucket?->where('previous_result', RecurringFaultReview::RESULT_VERIFIED_FIXED)->count() ?? 0,
            ];
        }

        // ── Decision mix (all-time) — where responsibility actually landed ─────────────────────────
        $decisions = collect(RecurringFaultReview::DECISIONS)
            ->map(fn ($d) => ['key' => $d, 'value' => $rows->where('decision', $d)->count()])
            ->filter(fn ($d) => $d['value'] > 0)
            ->sortByDesc('value')
            ->values()
            ->all();

        // How a recurrence is named on the charts: the ontology category when it tagged the fault,
        // otherwise the raw symptom the workshop typed. Keyed case-insensitively so "AC not cooling"
        // and "ac not cooling" are one fault, not two.
        $faultKey   = fn ($r) => $r->category_key ?: mb_strtolower(trim((string) $r->symptom));
        $faultLabel = fn ($r) => $r->category_key
            ? str_replace('_', ' ', (string) $r->category_key)
            : (trim((string) $r->symptom) ?: 'Unspecified');

        // A rank is a number until you can see what it is made of. Both "keeps coming back" rankings
        // carry a breakdown of the OTHER dimension — the cars behind a fault, the faults behind a car —
        // so hovering a bar answers "made of what?" without opening anything. Top 4 plus a "+N more"
        // count, the same shape the speed histogram already uses.
        $breakdown = function (Collection $group, callable $key, callable $label) {
            $ranked = $group->groupBy($key)
                ->map(fn ($g) => ['label' => $label($g->first()), 'value' => $g->count()])
                ->sortByDesc('value')
                ->values();

            return [$ranked->take(4)->all(), max(0, $ranked->count() - 4)];
        };

        // ── How fast faults come back — the speed-of-failure histogram ─────────────────────────────
        // Each bar also carries WHICH faults returned in that window: a bucket of 9 is a shrug until you
        // see that 6 of them are the same brake noise.
        $buckets = [
            ['key' => '0-7',   'label' => '≤ 7 days',    'max' => 7],
            ['key' => '8-30',  'label' => '8–30 days',   'max' => 30],
            ['key' => '31-60', 'label' => '31–60 days',  'max' => 60],
            ['key' => '61+',   'label' => '61+ days',    'max' => PHP_INT_MAX],
        ];
        $speed = [];
        $floor = -1;
        foreach ($buckets as $b) {
            $in = $rows->filter(fn ($r) => $r->days_since_repair !== null
                && $r->days_since_repair > $floor
                && $r->days_since_repair <= $b['max']);

            // How many distinct faults did NOT make the top-4 cut, so the tooltip can say "+3 more"
            // instead of silently truncating.
            [$topFaults, $moreFaults] = $breakdown($in, $faultKey, $faultLabel);

            $speed[] = [
                'key'    => $b['key'],
                'label'  => $b['label'],
                'value'  => $in->count(),
                'faults' => $topFaults,
                'more'   => $moreFaults,
            ];
            $floor = $b['max'];
        }

        // ── Garages whose repairs came back. NOT a blame ranking: it is a count of returns after that
        //    garage's repair. `workshop` is how often a human actually ruled it the workshop's fault. ──
        $garages = $rows->filter(fn ($r) => $r->previous_garage_name)
            ->groupBy('previous_garage_name')
            ->map(fn ($g, $name) => [
                'label'    => $name,
                'value'    => $g->count(),
                'open'     => $g->where('status', RecurringFaultReview::STATUS_OPEN)->count(),
                'verified' => $g->where('previous_result', RecurringFaultReview::RESULT_VERIFIED_FIXED)->count(),
                'workshop' => $g->where('decision', RecurringFaultReview::DECISION_WORKSHOP_RESPONSIBILITY)->count(),
            ])
            ->sortByDesc('value')
            ->take(8)
            ->values()
            ->all();

        // ── Cars that keep coming back ─────────────────────────────────────────────────────────────
        // Scoped by $carWindow, and ranked AFTER the window is applied — see the fault ranking below for
        // why filtering an all-time top-8 would be the wrong shape.
        $carWin  = $this->resolveWindow($carWindow, $now);
        $carRows = $this->within($rows, $carWin);

        $vehicles = $carRows->filter(fn ($r) => $r->vehicle_id)
            ->groupBy('vehicle_id')
            ->map(function ($g, $vehicleId) use ($breakdown, $faultKey, $faultLabel) {
                $v = $g->first()->vehicle;
                // WHICH faults this car keeps coming back with. "4 cases" says a car is a problem;
                // "3 of them the same AC fault" says what the problem IS.
                [$faults, $more] = $breakdown($g, $faultKey, $faultLabel);

                return [
                    'vehicle_id' => (int) $vehicleId,
                    'label'      => $v?->plate_no ?: '#' . $vehicleId,
                    'sub'        => trim(($v?->make ?? '') . ' ' . ($v?->model ?? '')) ?: null,
                    'value'      => $g->count(),
                    'open'       => $g->where('status', RecurringFaultReview::STATUS_OPEN)->count(),
                    'faults'     => $faults,
                    'more'       => $more,
                ];
            })
            ->sortByDesc('value')
            ->take(8)
            ->values()
            ->all();

        // ── Which faults recur — category_key when the ontology tagged it, else the raw symptom ────
        // Scoped by $faultWindow: the ranking is taken AFTER the window is applied, never by filtering
        // an all-time top-8. A fault that is 9th over two years can be the worst thing in the fleet this
        // month, and truncating first would hide it.
        $faultWin  = $this->resolveWindow($faultWindow, $now);
        $faultRows = $this->within($rows, $faultWin);

        $carLabel = fn ($r) => $r->vehicle?->plate_no ?: ($r->vehicle_id ? '#' . $r->vehicle_id : 'Unknown car');

        $faults = $faultRows->groupBy($faultKey)
            ->map(function ($g) use ($breakdown, $carLabel, $faultLabel) {
                // WHICH cars are behind this fault. "Brake failure, 10 cases" reads very differently
                // once you see it is one car ten times rather than ten cars once each.
                [$cars, $more] = $breakdown($g, fn ($r) => $r->vehicle_id ?: 0, $carLabel);

                return [
                    'label'     => $faultLabel($g->first()),
                    'value'     => $g->count(),
                    'cars'      => $g->pluck('vehicle_id')->filter()->unique()->count(),
                    'top_cars'  => $cars,
                    'cars_more' => $more,
                ];
            })
            ->sortByDesc('value')
            ->take(8)
            ->values()
            ->all();

        return [
            'kpi' => [
                'open'                 => $open,
                'decided'              => $decided,
                'total'                => $total,
                'last_30_days'         => $last30,
                'prev_30_days'         => $prev30,
                'median_days'          => $medianDays,
                // Counted off ALL rows, never $carRows: a KPI that moved when someone narrowed the car
                // ranking would be reporting a different fleet than the one it is labelled with.
                'repeat_vehicles'      => $rows->filter(fn ($r) => $r->vehicle_id)
                    ->groupBy('vehicle_id')->filter(fn ($g) => $g->count() > 1)->count(),
                'window_days'          => $this->windowDays(),
            ],
            'trend'     => $trend,
            'decisions' => $decisions,
            'speed'     => $speed,
            'garages'   => $garages,
            'vehicles'  => $vehicles,
            'faults'    => $faults,
            // Echoed back so each panel can say what it is showing rather than silently ranking a subset.
            'faults_window' => $this->windowMeta($faultWin, $faultRows),
            'cars_window'   => $this->windowMeta($carWin, $carRows),
        ];
    }

    // ── Internals ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Resolve one panel's date window into concrete bounds.
     *
     * An explicit from/to range wins over the trailing-days preset — the picker sends both, and a stale
     * `days` must not silently clip a range the user drew by hand. Dates are inclusive of their whole
     * day, so picking the same day for both ends means "that day", not an empty window.
     *
     * @param  array{days?:?int, from?:?string, to?:?string} $in
     * @return array{days:int, from:?Carbon, to:?Carbon}
     */
    private function resolveWindow(array $in, Carbon $now): array
    {
        $from = ($in['from'] ?? null) ? Carbon::parse($in['from'])->startOfDay() : null;
        $to   = ($in['to'] ?? null) ? Carbon::parse($in['to'])->endOfDay() : null;

        if ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        if ($from || $to) {
            return ['days' => 0, 'from' => $from, 'to' => $to];
        }

        $days = max(0, (int) ($in['days'] ?? 0));

        return [
            'days' => $days,
            'from' => $days > 0 ? $now->copy()->subDays($days)->startOfDay() : null,
            'to'   => null,
        ];
    }

    /**
     * The review cases that fall inside a resolved window. An unbounded window returns the rows
     * untouched — a case with no opened_at is only dropped once someone actually asks for a date range.
     *
     * @param array{from:?Carbon, to:?Carbon} $w
     */
    private function within(Collection $rows, array $w): Collection
    {
        if (!$w['from'] && !$w['to']) {
            return $rows;
        }

        return $rows->filter(fn ($r) => $r->opened_at
            && (!$w['from'] || $r->opened_at->gte($w['from']))
            && (!$w['to'] || $r->opened_at->lte($w['to'])));
    }

    /** What a panel's caption needs to describe the slice it is ranking. */
    private function windowMeta(array $w, Collection $rows): array
    {
        return [
            'days'  => $w['days'],
            'from'  => $w['from']?->toDateString(),
            'to'    => $w['to']?->toDateString(),
            'cases' => $rows->count(),
        ];
    }

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
