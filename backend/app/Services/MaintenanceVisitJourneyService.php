<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\VehicleLogEvent;
use Illuminate\Support\Collection;

/**
 * THE VISIT JOURNEY — everything that happened to a car under ONE maintenance contract.
 *
 * A type-'U' contract is opened the moment a car is booked in for a look ("Needs Test Drive") and closed
 * when Final QA signs it back into service ([[workflow-owned-maintenance-contract]]). Between those two
 * dates the car is inspected, faults are found, it is driven to a garage, more faults are found there,
 * each one is worked and either fixed or ruled out, and it comes home. All of that already exists in the
 * system — but scattered across the ticket, its faults, their garage stints and the vehicle event log,
 * which means the contract itself, the one row that represents the whole visit, showed nothing but dates
 * and money.
 *
 * This service assembles that story in one place, and answers, per fault: WHO found it (the inspector on
 * the test drive, or the workshop once the car was on the lift), WHERE it was fixed, and HOW LONG it took.
 *
 * ── Evidence classes ─────────────────────────────────────────────────────────────────────────────────
 * Produces: the visit journey read model (read-only; this service writes nothing).
 * Consumes: E-tickets (maintenances), E-faults (maintenance_tasks + assignments), E-events
 *           (vehicle_log_events), E-contract (contracts).
 *
 *   F (Fact)      — dates, odometer readings, garage names, who stamped what, recorded labor hours.
 *   D (Derived)   — elapsed times, day counts, roll-ups and totals; every one of them computed here from
 *                   the facts above and never stored.
 *   J (Judgement) — none. This service classifies nothing and scores nothing; where a value is a human
 *                   verdict (a fault's status, its severity) it is passed through as the human left it.
 *
 * The per-fault clock is NOT re-implemented here: it comes from FaultRepairTimeService, the one owner of
 * "how long did this fault take" ([[fault-repair-time-contract]]). That distinction matters and is
 * carried through to the payload: `work_seconds` is time actually spent on THIS fault, `custody_seconds`
 * is how long the car sat at the garage for it, and a fault that never recorded a per-fault start signal
 * reports work_seconds = null rather than quietly borrowing the car's shared workshop time.
 */
class MaintenanceVisitJourneyService
{
    public function __construct(private FaultRepairTimeService $repairTime)
    {
    }

    /**
     * The whole visit behind one contract.
     *
     * A contract normally holds exactly one ticket, but it can hold more: the link is "whichever tickets
     * were live under this contract", and a car that fails Final QA and goes back out is still the same
     * visit. So the payload is a LIST of visits with a roll-up across them, never a single object that
     * would silently show only the first.
     */
    public function forContract(Contract $contract): array
    {
        $tickets = Maintenance::query()
            ->where('linked_contract_id', $contract->id)
            ->with([
                'vendor',
                'requester', 'reviewer', 'inspector', 'assignedDriver',
                'tasks.assignments.vendor',
                'tasks.workSessions',
                'tasks.faultCatalog', 'tasks.serviceCatalog', 'tasks.inspectionType', 'tasks.damageCatalog',
                'tasks.identifiedBy', 'tasks.resolvedBy',
            ])
            ->orderBy('id')
            ->get();

        $visits = $tickets->map(fn (Maintenance $t) => $this->visit($t))->all();

        return [
            'contract' => $this->contractHeader($contract, $tickets),
            'visits'   => $visits,
            'totals'   => $this->rollUp($visits),
        ];
    }

    // ── The contract header ──────────────────────────────────────────────────────────────────────────

    private function contractHeader(Contract $contract, Collection $tickets): array
    {
        $out = $contract->out_date ? \Carbon\Carbon::parse($contract->out_date) : null;
        $in  = $contract->in_date ? \Carbon\Carbon::parse($contract->in_date) : null;

        return [
            'id'            => $contract->id,
            'contract_no'   => $contract->contract_no,
            'contract_type' => $contract->contract_type,
            'state'         => $contract->state,
            'is_open'       => $in === null,
            // Ours or OfficeManager's. A visit journey on an OM contract is still readable — we just
            // didn't open it — and saying so stops "why is there no open event?" being a mystery.
            'opened_by_workflow' => $contract->source === 'workflow',
            'out_date'      => optional($out)->toDateString(),
            'in_date'       => optional($in)->toDateString(),
            'out_milage'    => $out_m = $this->milage($contract->out_milage),
            'in_milage'     => $in_m  = $this->milage($contract->in_milage),
            // Distance covered under the contract — only when BOTH ends are genuinely known. An open
            // contract carries in_milage = 0, which is "not read yet", not "the car is at zero km";
            // subtracting it would report the whole odometer back as distance travelled. Never negative.
            'km_covered'    => ($out_m !== null && $in_m !== null) ? max(0, $in_m - $out_m) : null,
            // Calendar days the car was on this contract; an open contract counts up to today.
            'days_open'     => $out ? $out->diffInDays($in ?? \Carbon\Carbon::today()) : null,
            'ticket_count'  => $tickets->count(),
        ];
    }

    // ── One ticket = one pass through the workshop ───────────────────────────────────────────────────

    private function visit(Maintenance $ticket): array
    {
        $faults = $ticket->tasks->map(fn (MaintenanceTask $task) => $this->fault($task))->all();

        return [
            'ticket'   => [
                'id'              => $ticket->id,
                'workflow_status' => $ticket->workflow_status,
                'trigger_reason'  => $ticket->trigger_reason,
                'request_origin'  => $ticket->request_origin,
                'visit_context'   => $ticket->visit_context,
                'fault_severity'  => $ticket->fault_severity,
                'garage'          => $ticket->vendor?->name ?: $ticket->garage,
                'responsible'     => $ticket->responsible,
                'opened_at'       => optional($ticket->created_at)->toIso8601String(),
                'closed_at'       => optional($ticket->wf_closed_at)->toIso8601String(),
                'is_open'         => $ticket->wf_closed_at === null,
                // Wall-clock from the moment the inspector started the test drive to the sign-off. The
                // downtime clock the rest of the system quotes is FleetUtilizationService's, which
                // measures the CONTRACT window ([[maintenance-days-single-source]]) — this is the
                // ticket's own span and is offered as such, not as a second answer to the same question.
                'ticket_seconds'  => $this->span($ticket->test_started_at, $ticket->wf_closed_at),
            ],
            'stages'   => $this->stages($ticket),
            'odometer' => $this->odometerChain($ticket),
            'faults'   => $faults,
            'events'   => $this->events($ticket),
            'totals'   => $this->faultTotals($faults),
        ];
    }

    // ── The stage spine — who moved the car, when, and at what reading ───────────────────────────────

    /**
     * The visit's handoff stamps in lifecycle order, with the ones that never happened dropped. Each is a
     * FACT: a timestamp somebody's action wrote. `key` is stable and untranslated — the frontend owns the
     * wording, exactly as it does for the board lanes.
     */
    private function stages(Maintenance $ticket): array
    {
        $steps = [
            ['requested',      $ticket->requested_at,             $ticket->requester?->name,       null],
            ['reviewed',       $ticket->reviewed_at,              $ticket->reviewer?->name,        null],
            ['inspected',      $ticket->inspected_at,             $ticket->inspector?->name,       $ticket->test_odometer],
            // No 'reported' stamp: filing the report writes no timestamp of its own (only
            // report_odometer). It appears in the event trail as `report_filed` instead — inventing a
            // stage row here from a nearby timestamp would put a time on the page that nobody recorded.
            ['dispatched',     $ticket->dispatched_at,            $ticket->driver,                 $ticket->dispatch_odometer],
            ['repair_started', $ticket->repair_started_at,        $ticket->vendor?->name,          $ticket->receive_odometer],
            ['ready',          $ticket->ready_at,                 $ticket->vendor?->name,          null],
            ['collected',      $ticket->picked_up_from_garage_at, $ticket->pickedUpFromGarageBy?->name, $ticket->return_odometer],
            ['park_arrived',   $ticket->park_arrived_at,          null,                            $ticket->park_odometer],
            ['closed',         $ticket->wf_closed_at,             null,                            $ticket->reinspect_odometer],
        ];

        $out  = [];
        $prev = null;
        foreach ($steps as [$key, $at, $by, $odometer]) {
            if (! $at) {
                continue; // a stage this visit never reached — silence beats a row of dashes
            }
            $out[] = [
                'key'      => $key,
                'at'       => $at->toIso8601String(),
                'by'       => $by,
                'odometer' => $odometer !== null ? (int) $odometer : null,
                // How long the car waited in the PREVIOUS stage before reaching this one. Derived, and
                // null on the first stamp, which has nothing behind it to measure from.
                'since_previous_seconds' => $prev ? $this->span($prev, $at) : null,
            ];
            $prev = $at;
        }

        return $out;
    }

    /**
     * The odometer chain for the visit: every reading captured, in the order the car passed the
     * checkpoints, each carrying the continuity verdict recorded at capture time (`odometer_flags`) so a
     * reading that went to the approval board is visible here as such rather than looking clean.
     */
    private function odometerChain(Maintenance $ticket): array
    {
        $flags = $ticket->odometer_flags ?? [];
        $readings = [
            // Keys are the odometer_flags keys the workflow writes, so the verdict lines up with its
            // reading. 'report' (not 'test_end') is the end-of-test-drive capture — test_end is the
            // continuity STAGE that classifies it, which is a different vocabulary.
            ['test_drive', $ticket->test_odometer],
            ['report',     $ticket->report_odometer],
            ['dispatch',   $ticket->dispatch_odometer],
            ['receive',    $ticket->receive_odometer],
            // A garage→garage transfer has NO column of its own — the reading exists only on its flag,
            // so this row is sourced from there (see the fallback below).
            ['transfer',   null],
            ['return',     $ticket->return_odometer],
            ['park',       $ticket->park_odometer],
            ['reinspect',  $ticket->reinspect_odometer],
        ];

        $out = [];
        foreach ($readings as [$key, $value]) {
            $flag = $flags[$key] ?? null;
            // The column is the reading's home when it has one; the flag carries it otherwise.
            $value ??= isset($flag['reading']) ? (int) $flag['reading'] : null;
            if ($value === null) {
                continue; // this checkpoint never happened on this visit
            }
            $out[] = [
                'key'      => $key,
                'reading'  => (int) $value,
                'status'   => $flag['status'] ?? null,
                'delta'    => isset($flag['delta']) ? (int) $flag['delta'] : null,
                'note'     => $flag['note'] ?? null,
                'confirmed'=> $flag['confirmed'] ?? null,
            ];
        }

        return $out;
    }

    // ── The faults — the heart of the journey ────────────────────────────────────────────────────────

    /**
     * One fault, with the two questions the contract page exists to answer: where was it fixed, and how
     * long did it take.
     *
     * `source` is the FACT of who raised it — 'inspector' (Abu Maroof, on the test drive, before the car
     * ever left) or 'garage' (the workshop, once it was on the lift). That split is the whole reason a
     * supervisor reads this page: it says how much of the work was foreseen and how much the garage found
     * on its own.
     */
    private function fault(MaintenanceTask $task): array
    {
        $timing = $this->repairTime->forTask($task);
        $stints = $task->assignments;

        // WHERE it was fixed: the garage holding the fault when it reached a terminal state. For a fault
        // that moved between garages this is deliberately the LAST one — the one that actually finished
        // it — not the first it was sent to.
        $fixedAt = null;
        if (in_array($task->status, MaintenanceTask::TERMINAL, true)) {
            $closing = $stints->last();
            $fixedAt = $closing?->vendor?->name;
        }

        $catalog = $task->catalog();

        return [
            'id'       => $task->id,
            // The fault's identity IS its catalog name ([[findings-vocabulary-contract]]); `symptom` is
            // the free-text the reporter typed and is kept beside it, never in place of it.
            'name'     => $catalog?->name,
            'symptom'  => $task->symptom,
            'kind'     => $task->kind,
            'quantity' => (int) ($task->quantity ?: 1),
            'severity' => $task->severity,
            'status'   => $task->status,
            'is_terminal' => in_array($task->status, MaintenanceTask::TERMINAL, true),
            // Terminal but NOT a repair (cancelled / not found) — the fault closed without work happening,
            // and a page that showed it as "fixed" would be overstating what the garage did.
            'was_repaired' => $task->status === MaintenanceTask::STATUS_COMPLETED,

            // WHO FOUND IT — the split this page is for.
            'source'        => $task->source,
            'found_by'      => $task->identifiedBy?->name,
            'found_at'      => optional($task->identified_at)->toIso8601String(),

            // WHERE IT WAS FIXED, and everywhere it went to get there.
            'fixed_at_garage' => $fixedAt,
            'resolved_at'     => optional($task->resolved_at)->toIso8601String(),
            'resolved_by'     => $task->resolvedBy?->name,
            'resolution_note' => $task->resolution_note,
            'garages'         => $stints->map(fn ($s) => [
                'garage'      => $s->vendor?->name,
                'assigned_at' => optional($s->assigned_at)->toIso8601String(),
                'started_at'  => optional($s->work_started_at)->toIso8601String(),
                'released_at' => optional($s->released_at)->toIso8601String(),
                'outcome'     => $s->outcome,
                'labor_hours' => $s->labor_hours !== null ? (float) $s->labor_hours : null,
            ])->values()->all(),

            // HOW LONG IT TOOK — straight from the one service that owns this question. work_seconds is
            // null (never 0, never the custody figure) when this fault recorded no per-fault start
            // signal; `basis` says which of the two the numbers rest on.
            'timing' => [
                'work_seconds'    => $timing['cumulative_work_seconds'],
                'custody_seconds' => $timing['cumulative_custody_seconds'],
                'labor_hours'     => $timing['cumulative_labor_hours'],
                'attempts'        => $timing['attempt_count'],
                'open_attempt'    => $timing['open_attempt'],
                'basis'           => $timing['basis'],
            ],

            // Money, as the fault's own line items rolled it up.
            'parts_cost' => $task->parts_cost !== null ? (float) $task->parts_cost : null,
            'labor_cost' => $task->labor_cost !== null ? (float) $task->labor_cost : null,

            // The Quality-Control tail: this fault came back from the garage still broken N times.
            'reinspection_failures' => (int) ($task->reinspection_failures ?: 0),
        ];
    }

    /** Roll one ticket's faults up into the counts a supervisor reads first. All DERIVED. */
    private function faultTotals(array $faults): array
    {
        $c = collect($faults);

        return [
            'faults'          => $c->count(),
            'found_by_inspector' => $c->where('source', Maintenance::FINDING_INSPECTOR)->count(),
            'found_by_garage'    => $c->where('source', Maintenance::FINDING_GARAGE)->count(),
            'repaired'        => $c->where('was_repaired', true)->count(),
            'still_open'      => $c->where('is_terminal', false)->count(),
            // Closed without a repair — ruled a non-issue or never found. Counted separately so
            // "5 faults, 3 repaired" doesn't read as two failures.
            'closed_unrepaired' => $c->where('is_terminal', true)->where('was_repaired', false)->count(),
            'came_back'       => $c->where('reinspection_failures', '>', 0)->count(),
            // Summed only over the faults that HAVE a per-fault work signal, so a partial corpus can't
            // masquerade as a total. `work_seconds_known` says how many that was.
            'work_seconds'       => (int) $c->pluck('timing.work_seconds')->filter(fn ($v) => $v !== null)->sum(),
            'work_seconds_known' => $c->pluck('timing.work_seconds')->filter(fn ($v) => $v !== null)->count(),
            'parts_cost'      => round((float) $c->sum('parts_cost'), 2),
            'labor_cost'      => round((float) $c->sum('labor_cost'), 2),
        ];
    }

    /** The same roll-up across every ticket on the contract. */
    private function rollUp(array $visits): array
    {
        $rows = collect($visits)->pluck('totals');
        $keys = [
            'faults', 'found_by_inspector', 'found_by_garage', 'repaired', 'still_open',
            'closed_unrepaired', 'came_back', 'work_seconds', 'work_seconds_known',
        ];

        $out = [];
        foreach ($keys as $k) {
            $out[$k] = (int) $rows->sum($k);
        }
        $out['parts_cost'] = round((float) $rows->sum('parts_cost'), 2);
        $out['labor_cost'] = round((float) $rows->sum('labor_cost'), 2);
        $out['total_cost'] = round($out['parts_cost'] + $out['labor_cost'], 2);

        return $out;
    }

    // ── The event trail ──────────────────────────────────────────────────────────────────────────────

    /**
     * Every logged event for this ticket, oldest first — the narrative under the stage spine.
     *
     * Read via `maintenance_ref`, the archival twin of maintenance_id, so events survive a ticket that was
     * later deleted (see VehicleLogEvent — maintenance_id is nulled by the cascade, maintenance_ref never
     * is). Capped: a long-running visit can accumulate hundreds of status pings, and the page is a story,
     * not a log dump. When the cap bites we say so rather than silently truncating.
     */
    private function events(Maintenance $ticket): array
    {
        $limit = 250;

        $query = VehicleLogEvent::query()
            ->where(fn ($q) => $q->where('maintenance_id', $ticket->id)->orWhere('maintenance_ref', $ticket->id))
            ->with('actor')
            ->orderBy('occurred_at')
            ->orderBy('id');

        $total = (clone $query)->count();
        $rows  = $query->limit($limit)->get();

        return [
            'truncated' => $total > $limit,
            'total'     => $total,
            'items'     => $rows->map(fn (VehicleLogEvent $e) => [
                'id'          => $e->id,
                'type'        => $e->event_type,
                'description' => $e->description,
                'by'          => $e->actor?->name,
                'at'          => optional($e->occurred_at)->toIso8601String(),
                'task_id'     => $e->maintenance_task_id,
            ])->all(),
        ];
    }

    /**
     * A contract mileage column read honestly. Both ends default to 0 rather than NULL in this schema,
     * and an unread end is exactly the case where 0 is a placeholder, not a measurement — a car is never
     * genuinely at 0 km when we book it in. So 0 reads as "not recorded".
     */
    private function milage($value): ?int
    {
        return ($value === null || (int) $value <= 0) ? null : (int) $value;
    }

    /** Seconds between two moments, or null when either end is missing. Never negative. */
    private function span(?\DateTimeInterface $from, ?\DateTimeInterface $to): ?int
    {
        if (! $from || ! $to) {
            return null;
        }

        return max(0, $to->getTimestamp() - $from->getTimestamp());
    }
}
