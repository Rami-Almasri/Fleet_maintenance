<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MaintenanceCheckpoint;
use App\Models\MaintenanceTask;
use App\Models\PartRequest;
use App\Services\State\MaintenanceDelay;
use App\Services\State\MaintenanceDelayResolver;
use App\Services\State\RepairState;
use App\Services\State\WorkflowStateResolver;
use Carbon\Carbon;

/**
 * The OPERATIONS card for one open maintenance ticket — the answer to the six questions an Operations
 * or Maintenance Manager asks about a car in the shop, resolved server-side so every surface reads the
 * same verdict:
 *
 *   1. WHY did this car come in?           → reason      (the primary complaint + how many ride along)
 *   2. WHAT is happening right now?        → state       (Diagnosing / Waiting for Parts / Road Test / …)
 *   3. WHAT is blocking it?                → blocker     (Waiting for Parts / Approval / Supplier / …)
 *   4. WHERE has it got to?                → checkpoint + progress
 *   5. WHO is responsible?                 → responsibility (garage · stage owner · follow-up owners)
 *   6. Does it need escalating?            → alerts      (no updates / ETA overdue / sent back / …)
 *
 * Everything here is DERIVED FROM STORED DATA ONLY — the ticket's workflow state, its faults, its open
 * part requests and its latest progress checkpoint. Nothing is guessed or scored: if the data doesn't
 * say it, the field is null and the card renders "—". See [[traceability-visibility-requirement]] and
 * [[treat-data-as-source-of-truth]].
 *
 * This service PRESENTS; it does not re-derive what the State layer already owns. "Is this repair
 * blocked on parts?" and "why is it delayed?" come from WorkflowStateResolver / MaintenanceDelayResolver
 * (their blueprint Invariants 1 & 2), so the card, the notification scanner and the operational read model
 * can never give three different answers about the same car.
 *
 * Shape is stable for the frontend contract — see MaintenanceWorkflowResource's `ops` key.
 */
class MaintenanceOpsCardService
{
    public function __construct(
        private readonly WorkflowStateResolver $repairState,
        private readonly MaintenanceDelayResolver $delays,
    ) {
    }

    /** Checkpoint STATUS → the operational state it implies while the car is at the garage. */
    private const CHECKPOINT_STATE = [
        'waiting_parts' => ['waiting_parts',  'Waiting for Parts',  'violet'],
        'under_repair'  => ['repair',         'Repair in Progress', 'orange'],
        'painting'      => ['painting',       'Painting',           'orange'],
        'testing'       => ['road_test',      'Road Test',          'cyan'],
        'ready_today'   => ['ready_today',    'Ready Today',        'emerald'],
        'delayed'       => ['delayed',        'Delayed',            'red'],
        'other'         => ['repair',         'Repair in Progress', 'orange'],
    ];

    /** Checkpoint STATUS → human label (for the "current checkpoint" line). */
    private const CHECKPOINT_LABEL = [
        'waiting_parts' => 'Waiting for parts',
        'under_repair'  => 'Repair started',
        'painting'      => 'Painting',
        'testing'       => 'Road test',
        'ready_today'   => 'Ready today',
        'delayed'       => 'Delayed',
        'other'         => 'Progress update',
    ];

    /** Checkpoint DELAY REASON → the blocker it names. [key, label, tone] */
    private const DELAY_BLOCKER = [
        'waiting_parts'      => ['waiting_parts',     'Waiting for Parts',           'violet'],
        'vendor_delay'       => ['waiting_supplier',  'Waiting for Supplier',        'amber'],
        'workshop_busy'      => ['garage_backlog',    'Garage Backlog',              'amber'],
        'customer_approval'  => ['customer_decision', 'Waiting for Customer Decision', 'amber'],
        'insurance_approval' => ['insurance',         'Waiting for Insurance Approval', 'amber'],
        'additional_damage'  => ['additional_damage', 'Additional Damage Found',     'red'],
    ];

    /**
     * workflow_status → the plain operational state, for every stage where the ticket's own status
     * already tells the whole story. `under_repair` is handled separately (parts / gates / checkpoint
     * refine it). [key, label, tone]
     */
    private const STATUS_STATE = [
        Maintenance::WF_INSPECTION_REQUESTED     => ['awaiting_test_drive', 'Awaiting Test Drive',            'violet'],
        Maintenance::WF_INSPECTION_DIAGNOSTIC    => ['diagnosing',          'Diagnosing',                     'violet'],
        Maintenance::WF_COMPLAINT_TRIAGE         => ['triage',              'In Triage',                      'red'],
        Maintenance::WF_TRIAGE_APPROVAL_PENDING  => ['routing_approval',    'Waiting for Routing Approval',   'violet'],
        Maintenance::WF_INSPECTION_PENDING       => ['awaiting_dispatch',   'Waiting for Dispatch Decision',  'violet'],
        Maintenance::WF_AWAITING_DISPATCH        => ['awaiting_pickup',     'Waiting for Driver Pickup',      'amber'],
        Maintenance::WF_IN_TRANSIT               => ['in_transit',          'En Route to Garage',             'blue'],
        Maintenance::WF_ON_SITE_PENDING          => ['on_site',             'On-Site Service',                'cyan'],
        Maintenance::WF_REPAIR_REVIEW            => ['video_review',        'Waiting for Supervisor Review',  'violet'],
        Maintenance::WF_READY_FOR_PICKUP         => ['ready_for_pickup',    'Ready for Pickup',               'emerald'],
        Maintenance::WF_IN_OUR_PARK              => ['in_our_park',         'Back at Our Park',               'emerald'],
        Maintenance::WF_READY_REINSPECTION       => ['final_inspection',    'Final Inspection',               'violet'],
        Maintenance::WF_REINSPECTION_FAILED      => ['qa_failed',           'Sent Back — QA Failed',          'red'],
    ];

    /**
     * The repair-progress spine every card renders, in order. A ticket's position on it is derived from
     * its workflow status (see stageRank) — NOT from anything hand-entered.
     */
    private const MILESTONES = [
        ['inspection', 'Inspection'],
        ['diagnosis',  'Diagnosis'],
        ['dispatch',   'Approval & Dispatch'],
        ['parts',      'Parts'],
        ['repair',     'Repair'],
        ['qa',         'Road Test & QA'],
        ['delivery',   'Delivery'],
    ];

    /** workflow_status → how far along MILESTONES the ticket has travelled (index into the list). */
    private const STAGE_RANK = [
        Maintenance::WF_INSPECTION_REQUESTED    => 0,
        Maintenance::WF_COMPLAINT_TRIAGE        => 0,
        Maintenance::WF_INSPECTION_DIAGNOSTIC   => 1,
        Maintenance::WF_INSPECTION_PENDING      => 2,
        Maintenance::WF_TRIAGE_APPROVAL_PENDING => 2,
        Maintenance::WF_AWAITING_DISPATCH       => 2,
        Maintenance::WF_IN_TRANSIT              => 2,
        Maintenance::WF_UNDER_REPAIR            => 4,
        Maintenance::WF_ON_SITE_PENDING         => 4,
        Maintenance::WF_REPAIR_REVIEW           => 4,
        Maintenance::WF_READY_FOR_PICKUP        => 5,
        Maintenance::WF_IN_OUR_PARK             => 5,
        Maintenance::WF_READY_REINSPECTION      => 5,
        Maintenance::WF_REINSPECTION_FAILED     => 4,
        Maintenance::WF_AWAITING_INVOICE        => 6,
        Maintenance::WF_CLOSED                  => 6,
    ];

    /**
     * Build the whole ops card. Safe to call on any ticket: every section degrades to null / [] when the
     * data behind it isn't loaded, so it never fires a lazy query on a board of 200 tickets.
     */
    public function build(Maintenance $t): array
    {
        // The State layer's verdicts — only when the caller loaded the graph they read, so a pure
        // resolver is never the thing that fires a lazy query on a 200-card board. When it isn't
        // loaded the card degrades to the ticket's own parts list (named, but not adjudicated).
        $graph  = $this->resolverGraphLoaded($t);
        $repair = $graph ? $this->repairState->resolve($t) : null;
        $delay  = $graph ? $this->delays->resolve($t) : null;

        $parts      = $this->openParts($t, $repair);
        $checkpoint = $this->checkpoint($t);
        $blocker    = $this->blocker($t, $parts, $checkpoint, $delay);
        $timing     = $this->timing($t, $checkpoint);

        return [
            'reason'         => $this->reason($t),
            'state'          => $this->state($t, $parts, $checkpoint, $repair),
            'checkpoint'     => $checkpoint,
            'blocker'        => $blocker,
            'parts'          => $parts,
            'timing'         => $timing,
            'responsibility' => $this->responsibility($t),
            'progress'       => $this->progress($t, $parts),
            'alerts'         => $this->alerts($t, $blocker, $timing, $checkpoint),
        ];
    }

    /**
     * Is the whole ticket graph the State resolvers read (faults → part requests → purchases, plus the
     * checkpoints) already in memory? They are documented as pure readers that never eager-load, so the
     * caller must have done it — this is the check that keeps that contract honest.
     */
    private function resolverGraphLoaded(Maintenance $t): bool
    {
        if (! $t->relationLoaded('tasks') || ! $t->relationLoaded('checkpoints')) {
            return false;
        }

        foreach ($t->tasks as $task) {
            if (! $task->relationLoaded('partRequests')) {
                return false;
            }
            foreach ($task->partRequests as $request) {
                if (! $request->relationLoaded('purchases')) {
                    return false;
                }
            }
        }

        return true;
    }

    // ── 1. Why is the car here ────────────────────────────────────────────────

    /**
     * The primary maintenance reason — the headline of the card. Order of truth: the first OPEN fault's
     * symptom (what the workshop is actually working on), else the first fault recorded, else the
     * customer's own words, else the trigger that raised the ticket. `extra` counts the other faults
     * still open, so the card can read "Engine Noise (+3 more)".
     */
    private function reason(Maintenance $t): array
    {
        $tasks = $t->relationLoaded('tasks') ? $t->tasks : collect();
        $open  = $tasks->reject(fn (MaintenanceTask $x) => $x->isTerminal())->values();

        $primaryTask = $open->first() ?: $tasks->first();
        $primary     = $primaryTask?->symptom ?: ($t->customer_complaint ?: null);
        $source      = $primaryTask?->symptom
            ? 'fault'
            : ($t->customer_complaint ? 'complaint' : ($t->trigger_reason ? 'trigger' : null));

        if ($primary === null && $t->trigger_reason) {
            $primary = Maintenance::MAINTENANCE_TYPES[$t->maintenance_type] ?? $t->trigger_reason;
        }

        return [
            'primary'  => $primary,
            'source'   => $source,            // fault | complaint | trigger — the card's Data Origin line
            'severity' => $primaryTask?->severity ?: $t->fault_severity,
            'extra'    => max(0, $open->count() - 1),
            'all'      => $open->pluck('symptom')->filter()->values()->all(),
        ];
    }

    // ── 2. What is happening right now ────────────────────────────────────────

    /**
     * The real operational state — what is happening to the car at this moment, not merely which lane it
     * sits in. Physical-position facts (paused / released / rolling) OUTRANK the lane, then the workshop
     * refinements (approval gate → parts → the garage's own checkpoint), then the plain stage map.
     */
    private function state(Maintenance $t, array $parts, ?array $checkpoint, ?RepairState $repair): array
    {
        $wrap = fn (string $k, string $l, string $tone) => ['key' => $k, 'label' => $l, 'tone' => $tone];

        if ($t->isReturnedPendingHandover()) {
            return $wrap('return_handover', 'Awaiting Return Handover', 'orange');
        }
        if ($t->workflow_status === Maintenance::WF_PAUSED_RETURNED_TO_SERVICE) {
            return $wrap('paused', 'Paused — Car Released', 'slate');
        }
        if ($t->isTemporarilyReleased()) {
            return $wrap('temp_released', 'Out on Temporary Release', 'amber');
        }

        // In-workshop refinements — the stage says "under repair"; these say what kind of "under repair".
        if (in_array($t->workflow_status, [Maintenance::WF_UNDER_REPAIR, Maintenance::WF_ON_SITE_PENDING], true)) {
            if ($this->hasPendingRepairGate($t)) {
                return $wrap('repair_approval', 'Waiting for Repair Approval', 'red');
            }
            // WorkflowStateResolver owns the parts-block verdict; the raw parts list is only the fallback
            // for callers that didn't load its graph.
            $blockedOnParts = $repair ? $repair->isWaitingForParts() : $parts !== [];
            if ($blockedOnParts) {
                return $repair && $repair->isPartiallyBlocked()
                    ? $wrap('partially_blocked', 'Repair in Progress — Some Parts Awaited', 'amber')
                    : $wrap('waiting_parts', 'Waiting for Parts', 'violet');
            }
            $cp = $checkpoint['status'] ?? null;
            if ($cp && isset(self::CHECKPOINT_STATE[$cp])) {
                [$k, $l, $tone] = self::CHECKPOINT_STATE[$cp];
                return $wrap($k, $l, $tone);
            }
            return $t->workflow_status === Maintenance::WF_ON_SITE_PENDING
                ? $wrap('on_site', 'On-Site Service', 'cyan')
                : $wrap('repair', 'Repair in Progress', 'orange');
        }

        if (isset(self::STATUS_STATE[$t->workflow_status])) {
            [$k, $l, $tone] = self::STATUS_STATE[$t->workflow_status];
            return $wrap($k, $l, $tone);
        }

        return $wrap('unknown', $t->workflow_status ? ucfirst(str_replace('_', ' ', $t->workflow_status)) : 'Unknown', 'slate');
    }

    // ── 3. What checkpoint has been reached ───────────────────────────────────

    /**
     * The latest progress checkpoint the workshop filed, plus how stale it is. Null when the ticket has
     * never had one — which is itself the signal the "no updates" alert fires on. `days_since` measures
     * from the checkpoint (or, when none was ever filed, from when the repair actually started), so a
     * brand-new ticket is never wrongly flagged as neglected.
     */
    private function checkpoint(Maintenance $t): ?array
    {
        /** @var MaintenanceCheckpoint|null $cp */
        $cp = $t->relationLoaded('latestCheckpoint')
            ? $t->latestCheckpoint
            // checkpoints() is ->latest(), so the first row IS the newest — no extra query when the
            // caller loaded the collection for the State resolvers.
            : ($t->relationLoaded('checkpoints') ? $t->checkpoints->first() : null);
        $at = $t->last_checkpoint_at ?: $cp?->created_at;

        if (! $at && ! $cp) {
            return null;
        }

        return [
            'status'       => $cp?->status,
            'label'        => $cp?->status ? (self::CHECKPOINT_LABEL[$cp->status] ?? 'Progress update') : 'Progress update',
            'summary'      => $cp?->summary,
            'delay_reason' => $cp?->delay_reason,
            'by'           => $cp?->submitted_by_name,
            'at'           => optional($at)->toIso8601String(),
            'eta_set'      => optional($cp?->next_expected_date)->toDateString(),
            'days_since'   => $at ? $this->daysSince($at) : null,
        ];
    }

    // ── 4. What is blocking progress ──────────────────────────────────────────

    /**
     * The single most important thing standing between this car and the road. Evaluated hardest-blocker
     * first so the card never shows a soft "waiting for a garage update" while a repair approval is
     * actually frozen. Null = nothing is blocking; the job is simply in flight.
     */
    private function blocker(Maintenance $t, array $parts, ?array $checkpoint, ?MaintenanceDelay $delay): ?array
    {
        $wrap = fn (string $k, string $l, string $tone, ?string $detail = null, string $source = 'workflow_stage') => [
            'key' => $k, 'label' => $l, 'tone' => $tone, 'detail' => $detail, 'source' => $source,
        ];

        if ($this->hasPendingRepairGate($t)) {
            return $wrap('repair_approval', 'Waiting for Approval', 'red', 'A recurring fault needs a manager’s go-ahead before the repair may start', 'repair_gate');
        }
        if ($t->workflow_status === Maintenance::WF_TRIAGE_APPROVAL_PENDING) {
            return $wrap('routing_approval', 'Waiting for Supervisor Approval', 'violet', 'Routing recommendation awaiting sign-off');
        }
        if ($t->isReturnedPendingHandover()) {
            return $wrap('return_handover', 'Waiting for Return Handover', 'orange', 'Car is physically back — the resume handover is outstanding');
        }

        // PARTS — MaintenanceDelayResolver is the single owner of this verdict, and it carries the detail
        // a manager actually wants: which part, from which supplier, how long it has been waited on and
        // when it is due. Falls back to naming the ticket's own open requests when the resolver graph
        // wasn't loaded.
        if ($delay?->delayReason === MaintenanceDelay::REASON_WAITING_FOR_PARTS) {
            $bits = array_filter([
                $delay->headline,
                $delay->supplierName ? 'Supplier: ' . $delay->supplierName : null,
                $delay->daysWaiting !== null ? $delay->daysWaiting . ' ' . $this->plural($delay->daysWaiting, 'day') . ' waiting' : null,
                $delay->expectedResolutionDate ? 'Due ' . $delay->expectedResolutionDate : null,
            ]);
            return $wrap(
                $delay->supplierName ? 'waiting_supplier' : 'waiting_parts',
                $delay->supplierName ? 'Waiting for Supplier' : 'Waiting for Parts',
                'violet',
                implode(' · ', $bits) ?: null,
                'derived_parts',
            );
        }
        if ($delay === null && $parts !== []) {
            $names = array_slice(array_column($parts, 'name'), 0, 4);
            return $wrap('waiting_parts', 'Waiting for Parts', 'violet', implode(', ', $names), 'open_part_requests');
        }

        // The garage's own stated reason the date moved — the resolver's manual-checkpoint fallback.
        $reason = $delay?->delaySource === MaintenanceDelay::SOURCE_CHECKPOINT_MANUAL
            ? $delay->delayReason
            : ($this->atGarage($t) ? ($checkpoint['delay_reason'] ?? null) : null);
        if ($reason && isset(self::DELAY_BLOCKER[$reason])) {
            [$k, $l, $tone] = self::DELAY_BLOCKER[$reason];
            return $wrap($k, $l, $tone, 'Reported on the last checkpoint', 'checkpoint_manual');
        }

        return match ($t->workflow_status) {
            Maintenance::WF_INSPECTION_REQUESTED => $wrap('waiting_inspector', 'Waiting for Inspector', 'violet', 'Test drive not started'),
            Maintenance::WF_INSPECTION_PENDING   => $wrap('waiting_dispatch', 'Waiting for Dispatch Decision', 'violet', 'No garage chosen yet'),
            Maintenance::WF_AWAITING_DISPATCH    => $t->assigned_driver_id
                ? $wrap('waiting_driver', 'Waiting for Driver', 'amber', 'Assigned — pickup not started')
                : $wrap('waiting_driver', 'Waiting for Driver', 'amber', 'No driver assigned yet'),
            Maintenance::WF_READY_FOR_PICKUP     => $wrap('waiting_driver', 'Waiting for Driver', 'amber', 'Repair done — nobody has collected it'),
            Maintenance::WF_READY_REINSPECTION   => $wrap('waiting_qa', 'Waiting for Final QA', 'violet', 'Back at our park, sign-off outstanding'),
            Maintenance::WF_REINSPECTION_FAILED  => $wrap('waiting_dispatch', 'Waiting for Re-dispatch', 'red', 'Failed QA — needs sending back'),
            Maintenance::WF_REPAIR_REVIEW        => $wrap('waiting_review', 'Waiting for Supervisor Review', 'violet', 'Repair video sign-off outstanding'),
            // Nothing concrete is blocking this car. A garage that has gone quiet is NOT a blocker — the
            // card already reports that twice over (the checkpoint line's "updated Nd ago" and the
            // `no_checkpoint` alert), and silence is the absence of a cause, not a cause. Leave it to them.
            default => null,
        };
    }

    // ── 5. Parts ──────────────────────────────────────────────────────────────

    /**
     * Every part the car is still owed — the OPEN (non-terminal) part requests across its faults, named
     * and staged, so the card can list them without opening the Parts board. `blocking` says whether THIS
     * request is what's actually holding the repair up, taken from WorkflowStateResolver's verdict (a part
     * that has arrived but isn't fitted yet is outstanding, but blocks nothing). Empty when nothing is
     * outstanding or the part requests aren't eager-loaded.
     *
     * @return array<int,array{id:int,name:string,status:string,quantity:?float,blocking:bool}>
     */
    private function openParts(Maintenance $t, ?RepairState $repair): array
    {
        if (! $t->relationLoaded('tasks')) {
            return [];
        }

        $blockingIds = $repair?->blockingPartRequestIds ?? [];

        return $t->tasks
            ->flatMap(fn (MaintenanceTask $task) => $task->relationLoaded('partRequests') ? $task->partRequests : collect())
            ->reject(fn (PartRequest $r) => in_array($r->status, PartRequest::TERMINAL, true))
            ->filter(fn (PartRequest $r) => (bool) $r->part_name)
            ->unique('part_name')
            ->map(fn (PartRequest $r) => [
                'id'       => $r->id,
                'name'     => $r->part_name,
                'status'   => $r->status,
                'quantity' => $r->quantity === null ? null : (float) $r->quantity,
                'blocking' => in_array($r->id, $blockingIds, true),
            ])
            ->values()
            ->all();
    }

    // ── 6. Time & ETA ─────────────────────────────────────────────────────────

    /**
     * The operational clock: how long the car has been in maintenance, the promised ready-by date and
     * how far past it we are, and how long since ANYONE moved this ticket (a checkpoint or a stage
     * change — whichever is more recent). All day-math, all from stored anchors.
     */
    private function timing(Maintenance $t, ?array $checkpoint): array
    {
        // The CANONICAL repair ETA — the ticket's own gauge, so this card, the checkpoint monitor and the
        // dashboard can never disagree on which day a car turns red. "Days in maintenance" is read off the
        // SAME anchor (days_elapsed), so the clock and the deadline always describe one timeline.
        $eta   = $t->repairEta();
        $start = $eta['started_on'] ? Carbon::parse($eta['started_on']) : $t->created_at;

        $lastMoved = collect([$t->last_checkpoint_at, $t->last_state_change_at, $t->created_at])
            ->filter()
            ->max();

        return [
            'started_at'            => optional($start)->toIso8601String(),
            'days_in_maintenance'   => $eta['days_elapsed'] ?? ($start ? $this->daysSince($start) : null),
            'eta'                   => $eta['expected_on'],
            'eta_is_estimated'      => (bool) $eta['is_estimated'],
            'eta_status'            => $eta['status'],          // on_track | due_today | overdue | unknown
            'days_left'             => (int) $eta['days_left'],
            'days_over'             => (int) $eta['days_over'],
            'days_since_update'     => $lastMoved ? $this->daysSince($lastMoved) : null,
            'days_since_checkpoint' => $checkpoint['days_since'] ?? null,
        ];
    }

    // ── 7. Who is responsible ─────────────────────────────────────────────────

    /**
     * Accountability, in one block: the garage holding the car, the person on the hook for the CURRENT
     * stage (the inspector on the drive, the driver on a leg, the garage in the shop), and the ticket's
     * standing follow-up owners. There is no "technician" field in the system — we never invent one; the
     * garage IS the accountable party for workshop time.
     */
    private function responsibility(Maintenance $t): array
    {
        $garage = $t->vendor?->name ?: ($t->garage ?: null);

        [$role, $owner] = match ($t->workflow_status) {
            Maintenance::WF_INSPECTION_REQUESTED,
            Maintenance::WF_INSPECTION_DIAGNOSTIC,
            Maintenance::WF_COMPLAINT_TRIAGE,
            Maintenance::WF_ON_SITE_PENDING        => ['inspector', $t->inspector?->name],
            Maintenance::WF_AWAITING_DISPATCH      => ['driver', $t->assignedDriver?->name],
            Maintenance::WF_IN_TRANSIT             => ['driver', $t->driver ?: $t->assignedDriver?->name],
            Maintenance::WF_UNDER_REPAIR           => ['garage', $garage],
            Maintenance::WF_READY_FOR_PICKUP       => ['driver', $t->pickedUpFromGarageBy?->name ?: $t->assignedDriver?->name],
            Maintenance::WF_INSPECTION_PENDING,
            Maintenance::WF_TRIAGE_APPROVAL_PENDING,
            Maintenance::WF_REPAIR_REVIEW,
            Maintenance::WF_REINSPECTION_FAILED    => ['supervisor', null],
            Maintenance::WF_READY_REINSPECTION,
            Maintenance::WF_IN_OUR_PARK            => ['inspector', null],
            default                                => ['none', null],
        };

        return [
            'garage'       => $garage,
            'transfer_to'  => $t->transfer_to_vendor_id ? ($t->transferToVendor?->name ?: null) : null,
            'owner_role'   => $role,
            'owner_name'   => $owner,
            'followers'    => $t->relationLoaded('responsibles')
                ? $t->responsibles->pluck('name')->filter()->values()->all()
                : [],
        ];
    }

    // ── 8. Repair progress ────────────────────────────────────────────────────

    /**
     * The seven-step repair spine with each step marked done / active / todo. The PARTS step is only ever
     * "active" when parts are genuinely outstanding; when a job never needed a part it simply reads done
     * once the car is past it, so the spine doesn't imply a step that never happened.
     *
     * @return array<int,array{key:string,label:string,state:string}>
     */
    private function progress(Maintenance $t, array $parts): array
    {
        $rank = self::STAGE_RANK[$t->workflow_status] ?? null;
        if ($rank === null) {
            $rank = 0;
        }
        // Outstanding parts hold the spine at the Parts step no matter which lane the ticket is in.
        if ($parts !== [] && $rank > 3) {
            $rank = 3;
        }

        $out = [];
        foreach (self::MILESTONES as $i => [$key, $label]) {
            $out[] = [
                'key'   => $key,
                'label' => $label,
                'state' => $i < $rank ? 'done' : ($i === $rank ? 'active' : 'todo'),
            ];
        }

        return $out;
    }

    // ── 9. Operational alerts ─────────────────────────────────────────────────

    /**
     * The things that should pull a manager's eye to this card. Each alert names a condition that is TRUE
     * of the stored data right now — never a prediction. Ordered most-urgent first so the card can show
     * the top one or two and the page can sort by it.
     *
     * @return array<int,array{key:string,label:string,tone:string}>
     */
    private function alerts(Maintenance $t, ?array $blocker, array $timing, ?array $checkpoint): array
    {
        $alerts = [];
        $add = function (string $key, string $label, string $tone) use (&$alerts) {
            $alerts[] = ['key' => $key, 'label' => $label, 'tone' => $tone];
        };

        if (($timing['days_over'] ?? 0) > 0) {
            $add('eta_overdue', 'ETA overdue by ' . $timing['days_over'] . ' ' . $this->plural($timing['days_over'], 'day'), 'red');
        }

        if ($this->atGarage($t) && $this->hasGoneQuiet($checkpoint, $timing)) {
            $days = $this->daysOfSilence($checkpoint, $timing);
            $add('no_checkpoint', 'No checkpoint updates for ' . $days . ' ' . $this->plural($days, 'day'), 'amber');
        }

        if ($blocker && in_array($blocker['key'], [
            'waiting_parts', 'waiting_supplier', 'repair_approval', 'routing_approval',
            'customer_decision', 'insurance', 'additional_damage',
        ], true)) {
            $add('blocked_' . $blocker['key'], $blocker['label'], $blocker['tone']);
        }

        if ($t->workflow_status === Maintenance::WF_REINSPECTION_FAILED) {
            $add('qa_failed', 'Failed final QA — needs re-dispatch', 'red');
        }

        $sentBack = collect($t->follow_ups ?? [])
            ->filter(fn ($f) => is_array($f) && (($f['kind'] ?? null) === 'sent_back'))
            ->count();
        if ($sentBack > 0) {
            $add('escalated', 'Sent back ' . $sentBack . ' ' . $this->plural($sentBack, 'time'), 'red');
        }

        if (in_array($t->fault_severity, [Maintenance::FAULT_SEVERITY_CRITICAL, Maintenance::FAULT_SEVERITY_HIGH], true)) {
            $meta = Maintenance::FAULT_SEVERITY_META[$t->fault_severity] ?? null;
            if ($meta) {
                $add('severity', $meta['label'] . ' fault', $meta['tone'] ?? 'red');
            }
        }

        return $alerts;
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** Whole days from a past timestamp to now (0 for anything today or in the future). */
    private function daysSince(Carbon|\DateTimeInterface $at): int
    {
        $at = $at instanceof Carbon ? $at : Carbon::instance($at);
        return max(0, (int) $at->copy()->startOfDay()->diffInDays(today(), false));
    }

    /** Is the car physically at a garage right now (the window where a checkpoint is owed)? */
    private function atGarage(Maintenance $t): bool
    {
        return in_array($t->workflow_status, [
            Maintenance::WF_UNDER_REPAIR, Maintenance::WF_REPAIR_REVIEW, Maintenance::WF_READY_FOR_PICKUP,
        ], true);
    }

    /** Any fault frozen on the recurring-fault repair gate? */
    private function hasPendingRepairGate(Maintenance $t): bool
    {
        return $t->relationLoaded('tasks')
            && $t->tasks->contains(fn (MaintenanceTask $x) => $x->repair_gate === 'pending');
    }

    /**
     * How long this repair has gone without anyone reporting progress. Measured from the last checkpoint
     * when there is one; from the car's arrival in maintenance when there has never been one (a repair with
     * no update at all has been silent for its whole life, not for zero days).
     */
    private function daysOfSilence(?array $checkpoint, array $timing): ?int
    {
        return $checkpoint === null
            ? ($timing['days_in_maintenance'] ?? null)
            : ($timing['days_since_checkpoint'] ?? null);
    }

    /**
     * Has the garage gone quiet long enough to chase? The SINGLE condition behind both the
     * "Waiting for Garage Update" blocker and the "No checkpoint updates" alert — they are one signal
     * presented twice, so they must never disagree about whether it is firing.
     */
    private function hasGoneQuiet(?array $checkpoint, array $timing): bool
    {
        $days = $this->daysOfSilence($checkpoint, $timing);

        return $days !== null && $days >= $this->staleDays();
    }

    /** How many days without a progress update counts as neglected. */
    private function staleDays(): int
    {
        return max(1, (int) config('maintenance.checkpoint.stale_days', 3));
    }

    private function plural(int $n, string $word): string
    {
        return $n === 1 ? $word : $word . 's';
    }
}
