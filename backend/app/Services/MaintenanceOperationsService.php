<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\MaintenanceCheckpoint;
use App\Models\MaintenanceTask;
use App\Models\PartRequest;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Maintenance Operations — the operational control center for every vehicle CURRENTLY IN THE WORKSHOP.
 *
 * Where CarStatusService powers the analytics-heavy "Car Status" intelligence page and
 * MaintenanceOpsCenterService forecasts which cars SHOULD go in (service-due), THIS service answers the
 * single operational question a fleet manager asks each morning: "for every car that is in maintenance
 * right now — who is affected, why is it in, what faults exist, what progress has been made, is it late,
 * what is blocking it, and what happens next?"
 *
 * It is PURE READ. It composes helpers that already exist — Maintenance::livePosition() (the unified
 * "where is the car"), tasksProgress(), effectiveExpectedCompletion(), the FAULT_SEVERITY_META / type
 * vocabularies, MaintenanceCheckpointService::monitorState() (ETA + escalation), the checkpoint history
 * (previous_expected_date → next_expected_date + delay_reason) and the append-only VehicleLogEvent trail.
 * Nothing is re-implemented or guessed, so the numbers agree with the workshop board and the checkpoints.
 */
class MaintenanceOperationsService
{
    /**
     * The workflow states that mean a car is genuinely a live maintenance JOB right now (committed to the
     * pipeline or an on-site/awaiting-parts job). The earliest pre-ticket inspection/diagnostic/triage
     * gates — where the car is not yet committed to the shop — are deliberately excluded; this board is
     * "in maintenance", not "might need maintenance" (that is the Service-Due / Recommendation queues).
     */
    private const IN_MAINTENANCE_STATES = [
        Maintenance::WF_INSPECTION_PENDING,
        Maintenance::WF_ON_SITE_PENDING,
        Maintenance::WF_AWAITING_DISPATCH,
        Maintenance::WF_IN_TRANSIT,
        Maintenance::WF_UNDER_REPAIR,
        Maintenance::WF_REPAIR_REVIEW,
        Maintenance::WF_READY_REINSPECTION,
        Maintenance::WF_REINSPECTION_FAILED,
        Maintenance::WF_READY_FOR_PICKUP,
    ];

    /** States where the car sits blocked on a human sign-off. */
    private const WAITING_APPROVAL_STATES = [
        Maintenance::WF_INSPECTION_PENDING,
        Maintenance::WF_REPAIR_REVIEW,
        Maintenance::WF_REINSPECTION_FAILED,
    ];

    /** A repair that has run this many days (or more) is flagged "long running". */
    private const LONG_RUNNING_DAYS = 14;

    public function __construct(
        private readonly MaintenanceCheckpointService $checkpoints,
    ) {
    }

    // ── Board ────────────────────────────────────────────────────────────────────────────────────────

    /** The whole Maintenance Operations board: KPI summary + one rich card per in-shop vehicle. */
    public function board(): array
    {
        $tickets = $this->openTickets();
        $now     = Carbon::now();

        // The active RENTAL (type-C, currently out) per vehicle — who is affected by the car being in.
        $rentals = $this->activeRentalsByVehicle($tickets->pluck('vehicle_id')->filter()->unique()->all());

        $cards = $tickets
            ->map(fn (Maintenance $t) => $this->card($t, $rentals->get($t->vehicle_id), $now))
            ->sortByDesc(fn ($c) => $c['sort_weight'])
            ->values();

        return [
            'summary'      => $this->summary($cards),
            'vehicles'     => $cards->all(),
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /** Every open in-maintenance ticket with the relations the card needs, eager-loaded. */
    private function openTickets(): Collection
    {
        return Maintenance::query()
            ->whereIn('workflow_status', self::IN_MAINTENANCE_STATES)
            ->whereNotNull('vehicle_id')
            ->with([
                'vehicle:id,plate_no,make,model,year,vin,location,odometer,operational_status,condition_grade',
                'vendor',
                'assignedDriver:id,name',
                'inspector:id,name',
                'responsibles:id,name',
                'activeMove',
                'transferToVendor:id,name',
                // partRequests.purchases carries delivered_at/installed_at, which PartRequest::isOutstanding()
                // needs to tell "still waiting" from "already landed" — without it this would lazy-load once
                // per request across up to 500 tickets.
                'tasks' => fn ($q) => $q->with([
                    'identifiedBy:id,name',
                    'resolvedBy:id,name',
                    'partRequests:id,maintenance_task_id,part_name,status',
                    'partRequests.purchases:id,part_request_id,delivered_at,installed_at',
                ]),
                'checkpoints' => fn ($q) => $q->with('submitter:id,name'),
            ])
            ->orderByDesc('last_state_change_at')
            ->limit(500)
            ->get()
            // One card per vehicle — a car with two open tickets shows its most recently-active job
            // (the query is already ordered newest-state-change first, so unique() keeps the right one).
            ->unique('vehicle_id')
            ->values();
    }

    /**
     * The active rental contract (type C, currently out) per vehicle id → who is currently using the car.
     *
     * @param  array<int,int>  $vehicleIds
     * @return Collection<int,Contract>
     */
    private function activeRentalsByVehicle(array $vehicleIds): Collection
    {
        if (empty($vehicleIds)) {
            return collect();
        }

        return Contract::query()
            ->where('contract_type', 'C')
            ->currentlyOpen()
            ->whereIn('vehicle_id', $vehicleIds)
            ->with('customer:id,name_en,name_ar')
            ->orderByDesc('out_date')
            ->get()
            ->groupBy('vehicle_id')
            ->map(fn ($g) => $g->first());
    }

    // ── One card ───────────────────────────────────────────────────────────────────────────────────

    /** Assemble the full operational card for one in-shop ticket. */
    private function card(Maintenance $t, ?Contract $rental, Carbon $now): array
    {
        $pos      = $t->livePosition();
        $progress = $t->tasksProgress();
        $sev      = $t->faultSeverityMeta();
        $monitor  = $this->safeMonitor($t);
        $faults   = $this->faults($t);
        $latestCp = $t->checkpoints->first();
        $eta      = $this->etaHistory($t, $monitor);

        $startedAt   = $t->repair_started_at ?? $t->created_at ?? $t->requested_at;
        $daysInShop  = $startedAt ? (int) $startedAt->diffInDays($now) : null;
        $daysOverdue = (int) ($monitor['days_over'] ?? 0);
        $isOverdue   = (bool) ($monitor['overdue'] ?? false);
        $criticalFaults = collect($faults)->whereIn('severity', [
            Maintenance::FAULT_SEVERITY_CRITICAL, Maintenance::FAULT_SEVERITY_HIGH,
        ])->count();

        // One source for "waiting on a part": the faults' own open part requests. The recommendation
        // queue's parallel awaiting_parts state is gone — see [[inspection-required-parts-split]].
        $waitingParts    = collect($faults)->contains(fn ($f) => $f['waiting_parts']);
        $waitingApproval = in_array($t->workflow_status, self::WAITING_APPROVAL_STATES, true);
        $readyForPickup  = $t->workflow_status === Maintenance::WF_READY_FOR_PICKUP;
        $noRecentUpdate  = (bool) ($monitor['needs_update'] ?? false);
        $longRunning     = $daysInShop !== null && $daysInShop >= self::LONG_RUNNING_DAYS;
        $blocksRelease   = ! $t->isDeferrableForRental();

        $indicators = array_values(array_filter([
            $isOverdue          ? 'overdue' : null,
            $longRunning        ? 'long_running' : null,
            $criticalFaults > 0 ? 'critical_faults' : null,
            $waitingParts       ? 'waiting_parts' : null,
            $noRecentUpdate     ? 'no_recent_update' : null,
        ]));

        // Sort weight — the manager's triage order (most-attention first): overdue + criticality + age.
        $sortWeight = ($isOverdue ? 1000 : 0)
            + ($daysOverdue * 20)
            + ($criticalFaults * 50)
            + ($waitingParts ? 30 : 0)
            + ($noRecentUpdate ? 40 : 0)
            + ($daysInShop ?? 0);

        return [
            'ticket_id'  => $t->id,
            'vehicle_id' => $t->vehicle_id,
            'sort_weight' => $sortWeight,

            // ── Vehicle ──
            'vehicle' => [
                'plate_no'        => $t->vehicle?->plate_no,
                'make'            => $t->vehicle?->make,
                'model'           => $t->vehicle?->model,
                'car'             => $this->carLabel($t->vehicle),
                'year'            => $t->vehicle?->year,
                'vin'             => $t->vehicle?->vin,
                'odometer'        => $t->vehicle?->odometer,
                'location'        => $t->vehicle?->location,
                'condition_grade' => $t->vehicle?->condition_grade,
                'image_url'       => $t->vehicle?->image_url,
            ],

            // ── Current location / holder — WHERE the car is and WHO has it right now, derived from the
            // live workflow position + logistics move + active rental (never a manually-entered field). ──
            'location' => $this->currentLocation($t, $pos, $rental),

            // ── Who is affected (the active rental behind the car), + where it belongs. ──
            'affected' => [
                'customer'       => $rental?->customer?->name_en ?: $rental?->customer?->name_ar,
                'contract_no'    => $rental?->contract_no,
                'contract_start' => optional($rental?->out_date)->toDateString(),
                'contract_end'   => optional($rental?->in_date)->toDateString(),
                'branch'         => $t->vehicle?->location,
                'on_rent'        => $rental !== null,
            ],

            // ── Maintenance summary ──
            'maintenance' => [
                'workflow_status'    => $t->workflow_status,
                'stage_label'        => $pos['label'],
                'stage_detail'       => $pos['detail'],
                'stage_tone'         => $pos['tone'],
                'workshop'           => $t->vendor?->name ?: ($t->garage ?: null),
                'workshop_contact'   => $t->vendor?->phone ?? null,
                'workshop_kind'      => $t->repair_location === 'on_site' ? 'on_site' : 'in_shop',
                'type'               => $this->maintenanceType($t),
                'reason'             => $this->reasonLabel($t, $faults),
                'other_faults'       => max(0, $progress['open'] - 1),
                'opened_at'          => optional($startedAt)->toIso8601String(),
                'eta'                => $eta['current'],
                'eta_original'       => $eta['original'],
                'eta_changes'        => $eta['changes'],
                'eta_estimated'      => (bool) ($monitor['is_estimated'] ?? false),
                'days_in_workshop'   => $daysInShop,
                'days_overdue'       => $daysOverdue,
                'is_overdue'         => $isOverdue,
                'severity'           => $t->fault_severity,
                'severity_label'     => $sev['label'] ?? null,
                'severity_emoji'     => $sev['emoji'] ?? null,
                'severity_tone'      => $sev['tone'] ?? 'slate',
                'active_faults'      => $progress['open'],
                'fault_total'        => $progress['total'],
                'blocks_release'     => $blocksRelease,
            ],

            // ── Faults ──
            'faults' => $faults,

            // ── Progress (latest checkpoint) ──
            'progress' => [
                'has_update'         => $latestCp !== null,
                'latest_note'        => $latestCp?->summary,
                'workshop_status'    => $latestCp?->status,
                'last_updated_by'    => $latestCp?->submitted_by_name ?? $latestCp?->submitter?->name,
                'last_update_at'     => optional($latestCp?->created_at)->toIso8601String(),
                'current_eta'        => optional($latestCp?->next_expected_date)->toDateString()
                    ?? ($monitor['expected_on'] ?? null),
                'previous_eta'       => optional($latestCp?->previous_expected_date)->toDateString(),
                'eta_change_reason'  => $this->delayReasonLabel($latestCp),
                'escalation'         => $monitor['escalation'] ?? null,
                'needs_update'       => $noRecentUpdate,
            ],

            // ── Operational ──
            'operational' => [
                'fleet_manager'    => $this->fleetManager($t),
                'workshop_contact' => $t->vendor?->phone ?? null,
                'workshop_name'    => $t->vendor?->name,
                'driver'           => $t->assignedDriver?->name,
                'inspector'        => $t->inspector?->name,
                'technician'       => null,   // no dedicated technician field in the schema yet
                'waiting_parts'    => $waitingParts,
                'waiting_approval' => $waitingApproval,
                'ready_for_pickup' => $readyForPickup,
                'missing_parts'    => $this->missingParts($t),
            ],

            // ── Visual indicators ──
            'indicators' => $indicators,

            // ── What needs to happen next — derived from the live workflow + operational signals only
            // (never manual). Falls back to "No next action" when nothing is outstanding. ──
            'next_action' => $this->nextAction($t, [
                'ready_for_pickup' => $readyForPickup,
                'waiting_parts'    => $waitingParts,
                'waiting_approval' => $waitingApproval,
                'no_recent_update' => $noRecentUpdate,
            ]),

            'responsible'  => $this->fleetManager($t) ?? $pos['label'],
            'last_activity' => optional($t->last_state_change_at ?? $t->updated_at)->toIso8601String(),
        ];
    }

    /**
     * WHERE is the car and WHO holds it right now — the manager's first question. Derived purely from the
     * ticket's live position (which already fuses an in-flight logistics move → the ticket's garage → the
     * lifecycle stage) plus the active rental, so it is never a manually-entered field:
     *
     *   With Driver · <name>        — an active logistics move (in transit / being collected)
     *   In Workshop · <garage>      — physically at the garage (under repair / review)
     *   Ready for Pickup · <garage> — signed off, awaiting the driver's collection FROM the garage
     *   Under Inspection · <name>   — with the inspector for a test-drive / final QA
     *   With Customer · <name>      — an on-site/paused job on a car that is out on an active rental
     *   At Branch · <location>      — parked at base (awaiting dispatch, or a mobile job with no renter)
     *
     * @param  array<string,mixed>  $pos  the ticket's livePosition()
     */
    private function currentLocation(Maintenance $t, array $pos, ?Contract $rental): array
    {
        $phase     = $pos['phase'] ?? 'open';
        $garage    = $pos['garage'] ?? $t->vendor?->name;
        $driver    = $pos['driver'] ?? $t->assignedDriver?->name;
        $inspector = $t->inspector?->name;
        $customer  = $rental?->customer?->name_en ?: $rental?->customer?->name_ar;
        $branch    = $t->vehicle?->location;

        $moving       = ! empty($pos['moving']) || $phase === 'in_transit';
        $atGarage     = in_array($phase, ['in_workshop', 'repair_review', 'awaiting_pickup', 'reinspection_failed'], true);
        $withInspector = in_array($phase, ['inspection_requested', 'under_diagnosis', 'awaiting_reinspection'], true);

        [$holderType, $holder, $label] = match (true) {
            $moving                    => ['driver', $driver, $driver ? "With Driver · {$driver}" : 'With Driver (in transit)'],
            $phase === 'ready_for_pickup' => ['ready', $garage, $garage ? "Ready for Pickup · {$garage}" : 'Ready for Pickup'],
            $atGarage                  => ['workshop', $garage, $garage ? "In Workshop · {$garage}" : 'In Workshop'],
            $withInspector             => ['inspector', $inspector, $inspector ? "Under Inspection · {$inspector}" : 'Under Inspection'],
            $customer !== null         => ['customer', $customer, "With Customer · {$customer}"],
            $branch !== null           => ['branch', $branch, "At Branch · {$branch}"],
            default                    => ['system', null, $pos['label'] ?? 'In workflow'],
        };

        return [
            'holder_type' => $holderType,
            'holder'      => $holder,
            'label'       => $label,
            'tone'        => $pos['tone'] ?? 'slate',
            'detail'      => $pos['detail'] ?? null,
        ];
    }

    /**
     * The ETA story — the original promise, the current promise, and how many times it moved. Reconstructed
     * from the checkpoint history (each checkpoint snapshots previous_expected_date → next_expected_date),
     * so a car that has slipped three times reads that at a glance without a manual field.
     *
     * @param  array<string,mixed>  $monitor
     * @return array{original:?string, current:?string, changes:int}
     */
    private function etaHistory(Maintenance $t, array $monitor): array
    {
        $current = $monitor['expected_on'] ?? optional($t->effectiveExpectedCompletion())->toDateString();

        // The oldest checkpoint carries the ETA that was in force before any update. Order by id (not
        // created_at) so two updates filed the SAME day still resolve the true first one deterministically.
        $cps    = $t->checkpoints->sortBy('id')->values();
        $oldest = $cps->first();
        $original = $oldest
            ? optional($oldest->previous_expected_date ?? $oldest->next_expected_date)->toDateString()
            : $current;

        $changes = $cps->filter(fn (MaintenanceCheckpoint $c) => $c->previous_expected_date
            && $c->next_expected_date
            && ! $c->previous_expected_date->equalTo($c->next_expected_date))->count();

        return ['original' => $original ?: $current, 'current' => $current, 'changes' => $changes];
    }

    /** monitorState is best-effort — a presentation glitch must never take the board down. */
    private function safeMonitor(Maintenance $t): array
    {
        try {
            return $this->checkpoints->monitorState($t, $t->checkpoints->first());
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Every reported fault on the ticket, worst severity first. Terminal (cancelled/not-found) faults are
     * kept but flagged closed so the operator sees the full picture without them counting as "open".
     *
     * @return array<int,array<string,mixed>>
     */
    private function faults(Maintenance $t): array
    {
        $rank = fn (MaintenanceTask $x) => MaintenanceTask::SEVERITY_RANK[$x->severity] ?? 0;

        return $t->tasks
            ->sortByDesc(fn ($task) => [in_array($task->status, MaintenanceTask::TERMINAL, true) ? 0 : 1, $rank($task)])
            ->values()
            ->map(function (MaintenanceTask $task) use ($t) {
                $sev  = Maintenance::FAULT_SEVERITY_META[$task->severity] ?? null;
                $open = ! in_array($task->status, MaintenanceTask::TERMINAL, true);
                // Waiting ends at DELIVERY, not at the fitting — isOutstanding() reads the purchase's
                // delivered_at, so a landed part stops raising this even while its request says `purchased`.
                $waitingParts = $task->relationLoaded('partRequests')
                    ? $task->partRequests->contains(fn (PartRequest $r) => $r->isOutstanding())
                    : false;

                return [
                    'id'            => $task->id,
                    'title'         => $task->symptom ?: $this->categoryLabel($task->category_key),
                    'category'      => $this->categoryLabel($task->category_key),
                    'kind'          => $task->kind,
                    'severity'      => $task->severity,
                    'severity_label' => $sev['label'] ?? null,
                    'severity_emoji' => $sev['emoji'] ?? null,
                    'severity_tone' => $sev['tone'] ?? 'slate',
                    'status'        => $task->status,
                    'open'          => $open,
                    'reported_by'   => $task->identifiedBy?->name ?? ($task->source === 'garage' ? 'Garage' : 'Inspector'),
                    'reported_at'   => optional($task->identified_at ?? $task->created_at)->toIso8601String(),
                    'diagnostic'    => $task->root_cause ?: $task->confirmation_status,
                    'resolution'    => $task->resolution_note,
                    'waiting_parts' => $waitingParts,
                    // Whether this fault blocks the car's release. Mandatory (non-deferrable) tickets block
                    // outright; a fault fenced behind a repair gate (recurring-fault approval) also blocks.
                    'blocking'      => $open && (! $t->isDeferrableForRental() || (bool) $task->repair_gate),
                ];
            })
            ->all();
    }

    /**
     * The part names the ticket is still WAITING on — the "waiting for these parts" list. Same rule as
     * every other surface (PartRequest::isOutstanding): the wait ends when the part lands, not when it
     * is fitted.
     */
    private function missingParts(Maintenance $t): array
    {
        return $t->tasks
            ->flatMap(fn ($task) => $task->relationLoaded('partRequests') ? $task->partRequests : collect())
            ->filter(fn (PartRequest $r) => $r->isOutstanding())
            ->pluck('part_name')->filter()->unique()->values()->all();
    }

    /**
     * The single most useful thing to do next for this car — derived purely from the live workflow stage
     * and the operational signals the card already computed (never a manually-entered instruction). When
     * nothing is outstanding it returns a "none" action so the UI shows "No next action" rather than
     * inventing work.
     *
     * @param  array{ready_for_pickup:bool, waiting_parts:bool, waiting_approval:bool, no_recent_update:bool}  $signals
     * @return array{key:string, label:string, tone:string}
     */
    private function nextAction(Maintenance $t, array $signals): array
    {
        return match (true) {
            $signals['ready_for_pickup']                              => ['key' => 'ready_for_pickup',    'label' => 'Ready for pickup — arrange collection', 'tone' => 'green'],
            $signals['waiting_parts']                                 => ['key' => 'waiting_parts',        'label' => 'Waiting for parts',                     'tone' => 'violet'],
            $t->workflow_status === Maintenance::WF_READY_REINSPECTION => ['key' => 'complete_inspection', 'label' => 'Complete re-inspection',                'tone' => 'amber'],
            $signals['waiting_approval']                              => ['key' => 'waiting_approval',     'label' => 'Waiting for approval',                  'tone' => 'amber'],
            $signals['no_recent_update']                              => ['key' => 'add_update',           'label' => 'Add a checkpoint update',               'tone' => 'blue'],
            default                                                   => ['key' => 'none',                'label' => 'No next action',                        'tone' => 'slate'],
        };
    }

    /** The responsible follow-up owner(s) for this ticket — the checkpoint responsibles, comma-joined. */
    private function fleetManager(Maintenance $t): ?string
    {
        $names = $t->relationLoaded('responsibles')
            ? $t->responsibles->pluck('name')->filter()->values()
            : collect();

        return $names->isNotEmpty() ? $names->join(', ') : null;
    }

    /** Preventive / Corrective / Accident / Inspection — the operational classification of the visit. */
    private function maintenanceType(Maintenance $t): string
    {
        if (in_array($t->maintenance_type, [Maintenance::TYPE_INS_INCIDENT, Maintenance::TYPE_NON_INS_INCIDENT], true)) {
            return 'Accident';
        }
        if ($t->maintenance_type === Maintenance::TYPE_ROUTINE
            || $t->trigger_reason === Maintenance::TRIGGER_PERIODIC
            || $t->visit_context === Maintenance::CONTEXT_ROUTINE) {
            return 'Preventive';
        }
        if (in_array($t->trigger_reason, [Maintenance::TRIGGER_TEST_DRIVE, Maintenance::TRIGGER_PICKUP], true)) {
            return 'Inspection';
        }
        // A driver reported an actual fault — corrective work, never preventive.
        if ($t->trigger_reason === Maintenance::TRIGGER_DRIVER_REPORTED) {
            return 'Corrective';
        }

        return 'Corrective';
    }

    /** The headline "why is it in the shop" — worst open fault, else the customer complaint / type. */
    private function reasonLabel(Maintenance $t, array $faults): ?string
    {
        $primary = collect($faults)->first(fn ($f) => $f['open']) ?? collect($faults)->first();
        if ($primary && ! empty($primary['title'])) {
            return $primary['title'];
        }
        if ($t->customer_complaint) {
            return Str::limit(trim($t->customer_complaint), 90);
        }

        return Maintenance::MAINTENANCE_TYPES[$t->maintenance_type] ?? null;
    }

    /** Human label for a checkpoint's ETA-change reason. */
    private function delayReasonLabel(?MaintenanceCheckpoint $cp): ?string
    {
        if (! $cp || ! $cp->delay_reason) {
            return null;
        }
        if ($cp->delay_reason === 'other') {
            return $cp->delay_reason_other ?: 'Other';
        }

        return Str::of($cp->delay_reason)->replace('_', ' ')->title();
    }

    // ── KPI summary ─────────────────────────────────────────────────────────────────────────────────

    /** @param Collection<int,array<string,mixed>> $cards */
    private function summary(Collection $cards): array
    {
        return [
            'in_maintenance'   => $cards->pluck('vehicle_id')->filter()->unique()->count(),
            'overdue'          => $cards->filter(fn ($c) => $c['maintenance']['is_overdue'])->count(),
            'waiting_parts'    => $cards->filter(fn ($c) => $c['operational']['waiting_parts'])->count(),
            'waiting_approval' => $cards->filter(fn ($c) => $c['operational']['waiting_approval'])->count(),
            'ready_for_pickup' => $cards->filter(fn ($c) => $c['operational']['ready_for_pickup'])->count(),
            'critical'         => $cards->filter(fn ($c) => in_array('critical_faults', $c['indicators'], true))->count(),
            'long_running'     => $cards->filter(fn ($c) => in_array('long_running', $c['indicators'], true))->count(),
            'no_recent_update' => $cards->filter(fn ($c) => in_array('no_recent_update', $c['indicators'], true))->count(),
        ];
    }

    // ── Vehicle detail (drawer): full timeline ──────────────────────────────────────────────────────

    /**
     * The per-vehicle detail behind a card — every checkpoint update (progress timeline) plus the
     * workflow audit trail, so a manager can see how the job has progressed. The card already carries the
     * live snapshot; this fills in the history.
     */
    public function vehicleDetail(Vehicle $vehicle): array
    {
        $ticket = Maintenance::query()
            ->whereIn('workflow_status', self::IN_MAINTENANCE_STATES)
            ->where('vehicle_id', $vehicle->id)
            ->with([
                'vendor',
                'assignedDriver:id,name',
                'inspector:id,name',
                'responsibles:id,name',
                // partRequests.purchases carries delivered_at/installed_at, which PartRequest::isOutstanding()
                // needs to tell "still waiting" from "already landed" — without it this would lazy-load once
                // per request across up to 500 tickets.
                'tasks' => fn ($q) => $q->with([
                    'identifiedBy:id,name',
                    'resolvedBy:id,name',
                    'partRequests:id,maintenance_task_id,part_name,status',
                    'partRequests.purchases:id,part_request_id,delivered_at,installed_at',
                ]),
                'checkpoints' => fn ($q) => $q->with('submitter:id,name', 'media'),
                'activeMove',
                'transferToVendor:id,name',
            ])
            ->orderByDesc('last_state_change_at')
            ->first();

        $now     = Carbon::now();
        $rental  = $this->activeRentalsByVehicle([$vehicle->id])->get($vehicle->id);

        return [
            'vehicle_id'  => $vehicle->id,
            'ticket_id'   => $ticket?->id,
            'card'        => $ticket ? $this->card($ticket, $rental, $now) : null,
            'checkpoints' => $ticket ? $this->checkpointTimeline($ticket) : [],
            'timeline'    => $this->workflowTimeline($vehicle, $ticket),
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /** The ticket's checkpoint history (newest first) — the progress updates a manager filed. */
    private function checkpointTimeline(Maintenance $t): array
    {
        return $t->checkpoints->sortByDesc('id')->values()->map(fn (MaintenanceCheckpoint $c) => [
            'id'                 => $c->id,
            'status'             => $c->status,
            'summary'            => $c->summary,
            'delay_reason'       => $this->delayReasonLabel($c),
            'previous_eta'       => optional($c->previous_expected_date)->toDateString(),
            'next_eta'           => optional($c->next_expected_date)->toDateString(),
            'eta_changed'        => $c->previous_expected_date && $c->next_expected_date
                && ! $c->previous_expected_date->equalTo($c->next_expected_date),
            'submitted_by'       => $c->submitted_by_name ?? $c->submitter?->name,
            'created_at'         => optional($c->created_at)->toIso8601String(),
            'media'              => $c->relationLoaded('media') ? $c->media->map(fn ($m) => [
                'id'   => $m->id,
                'kind' => $m->kind,
                'url'  => $m->viewUrl(),
            ])->values()->all() : [],
        ])->all();
    }

    /**
     * The maintenance workflow audit trail for this car — the stage-by-stage transitions from the
     * append-only vehicle_log_events, scoped to the active ticket when we have one (else the whole car).
     */
    private function workflowTimeline(Vehicle $vehicle, ?Maintenance $ticket): array
    {
        $query = VehicleLogEvent::query()
            ->where('vehicle_id', $vehicle->id)
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->limit(80);

        if ($ticket) {
            $query->where(fn ($q) => $q->where('maintenance_id', $ticket->id)->orWhereNull('maintenance_id'));
        }

        return $query->get()->map(fn (VehicleLogEvent $e) => [
            'id'          => $e->id,
            'event_type'  => $e->event_type,
            'description' => $e->description,
            'workflow_status' => $e->workflow_status,
            'actor'       => $e->actor?->name,
            'occurred_at' => optional($e->occurred_at)->toIso8601String(),
        ])->all();
    }

    // ── Small shared presenters ─────────────────────────────────────────────────────────────────────

    private function carLabel(?Vehicle $v): ?string
    {
        if (! $v) {
            return null;
        }

        return trim(($v->make ?? '') . ' ' . ($v->model ?? '')) ?: null;
    }

    private function categoryLabel(?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        return Str::of($key)->replace(['_', '-'], ' ')->title();
    }
}
