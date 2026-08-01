<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\PartRequest;
use App\Models\RepairInspection;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Vendor;
use App\Services\State\EffectiveState;
use App\Services\State\MaintenanceDelayResolver;
use App\Services\State\OperationalStateLoader;
use App\Services\State\WorkflowStateResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Car Status — the Maintenance Intelligence Center. Read-only.
 *
 * Powers the enterprise Car Status dashboard (KPIs + live workshop table + operational widgets) and the
 * per-vehicle Intelligence Center (health/reliability scoring, fault analytics, garage performance, repair
 * history). It is PURE READ: it never mutates a ticket, never touches MaintenanceWorkflowService (the state
 * machine), and derives every number from columns/relations that already exist. It reuses the canonical
 * Maintenance workflow constants (WF_*, FAULT_SEVERITY_*, TRIGGER_* and TYPE_*) and helpers (livePosition(),
 * tasksProgress()) so the numbers match the board exactly — nothing is re-implemented or guessed.
 */
class CarStatusService
{
    /** Default per-stage SLA (hours) when a ticket carries no explicit expected_return_date. */
    private const STAGE_SLA_HOURS = 72;

    /**
     * Per workflow_status: the responsible PARTY (inspector/supervisor/garage/driver/vendor/system), the
     * human "waiting reason", and the operational-ownership label. One source of truth for the live table's
     * responsible column, the operational widgets, and the vehicle's Current-Ownership banner. Any status
     * not listed falls through to a supervisor/"In workflow" default.
     *
     *   status => [party, waiting_reason, ownership_label]
     */
    private const STAGE_META = [
        Maintenance::WF_PENDING_REVIEW           => ['system',     'Awaiting controller review',              'Waiting Approval'],
        Maintenance::WF_INSPECTION_REQUESTED     => ['inspector',  'Awaiting inspection / test drive',        'With Inspector'],
        Maintenance::WF_COMPLAINT_TRIAGE         => ['system',     'Awaiting complaint triage',               'In Triage'],
        Maintenance::WF_TRIAGE_APPROVAL_PENDING  => ['supervisor', 'Awaiting routing approval',               'Waiting Approval'],
        Maintenance::WF_INSPECTION_DIAGNOSTIC    => ['inspector',  'Test-drive diagnostic in progress',       'With Inspector'],
        Maintenance::WF_RECOMMENDATION_PENDING   => ['supervisor', 'Awaiting recommendation approval',        'Waiting Approval'],
        Maintenance::WF_INSPECTION_PENDING       => ['supervisor', 'Awaiting dispatch decision',              'Ready for Dispatch'],
        Maintenance::WF_ON_SITE_PENDING          => ['garage',     'Pending on-site service',                 'On-Site Service'],
        Maintenance::WF_AWAITING_DISPATCH        => ['driver',     'Awaiting driver pickup',                  'Ready for Dispatch'],
        Maintenance::WF_IN_TRANSIT               => ['driver',     'In transit to garage',                    'With Driver'],
        Maintenance::WF_UNDER_REPAIR             => ['garage',     'Under repair at garage',                  'At Garage'],
        Maintenance::WF_REPAIR_REVIEW            => ['supervisor', 'Awaiting repair review',                  'Waiting Approval'],
        Maintenance::WF_READY_REINSPECTION       => ['inspector',  'Awaiting final QA re-inspection',         'With Inspector'],
        Maintenance::WF_REINSPECTION_FAILED      => ['supervisor', 'Awaiting re-dispatch after failed QA',    'With Supervisor'],
        Maintenance::WF_READY_FOR_PICKUP         => ['driver',     'Ready — awaiting pickup from garage',     'Ready for Delivery'],
        Maintenance::WF_IN_OUR_PARK              => ['system',     'Back at base — auto-evaluating',          'Back at Base'],
        Maintenance::WF_PAUSED_RETURNED_TO_SERVICE => ['supervisor', 'Maintenance paused — car in service',   'Paused'],
    ];

    /** Rough completion % per workflow_status — the dashboard row's progress bar + the header gauge. */
    private const PROGRESS = [
        Maintenance::WF_PENDING_REVIEW           => 5,
        Maintenance::WF_COMPLAINT_TRIAGE         => 5,
        Maintenance::WF_INSPECTION_REQUESTED     => 8,
        Maintenance::WF_INSPECTION_DIAGNOSTIC    => 12,
        Maintenance::WF_TRIAGE_APPROVAL_PENDING  => 15,
        Maintenance::WF_RECOMMENDATION_PENDING   => 22,
        Maintenance::WF_INSPECTION_PENDING       => 25,
        Maintenance::WF_ON_SITE_PENDING          => 28,
        Maintenance::WF_AWAITING_DISPATCH        => 40,
        Maintenance::WF_IN_TRANSIT               => 50,
        Maintenance::WF_UNDER_REPAIR             => 65,
        Maintenance::WF_REPAIR_REVIEW            => 75,
        Maintenance::WF_REINSPECTION_FAILED      => 70,
        Maintenance::WF_READY_REINSPECTION       => 82,
        Maintenance::WF_READY_FOR_PICKUP         => 90,
        Maintenance::WF_IN_OUR_PARK              => 95,
        Maintenance::WF_PAUSED_RETURNED_TO_SERVICE => 60,
        Maintenance::WF_CLOSED                   => 100,
    ];

    /**
     * The canonical committed pipeline for the per-vehicle Live Workflow stage tracker. Each step lists the
     * workflow states that sit "at" it; the tracker marks earlier steps complete, this one current, later
     * ones waiting, and flags a blocked state (awaiting parts / failed re-inspection).
     *
     *   [step label, [states at this step]]
     */
    private const LIVE_PIPELINE = [
        ['Inspection',   [Maintenance::WF_INSPECTION_REQUESTED, Maintenance::WF_INSPECTION_DIAGNOSTIC]],
        ['Decision',     [Maintenance::WF_PENDING_REVIEW, Maintenance::WF_COMPLAINT_TRIAGE, Maintenance::WF_TRIAGE_APPROVAL_PENDING, Maintenance::WF_RECOMMENDATION_PENDING, Maintenance::WF_INSPECTION_PENDING, Maintenance::WF_ON_SITE_PENDING]],
        ['Dispatch',     [Maintenance::WF_AWAITING_DISPATCH]],
        ['In Transit',   [Maintenance::WF_IN_TRANSIT]],
        ['Under Repair', [Maintenance::WF_UNDER_REPAIR, Maintenance::WF_REPAIR_REVIEW]],
        ['Re-inspection', [Maintenance::WF_READY_REINSPECTION, Maintenance::WF_REINSPECTION_FAILED]],
        ['Ready',        [Maintenance::WF_READY_FOR_PICKUP, Maintenance::WF_IN_OUR_PARK]],
        ['Closed',       [Maintenance::WF_CLOSED]],
    ];

    /** States that mean the ticket is BLOCKED (not progressing) — surfaced on the row + stage tracker. */
    private const BLOCKED_STATES = [Maintenance::WF_REINSPECTION_FAILED];

    /** "In Workshop" = the car is physically at a garage right now (being repaired / reviewed / awaiting pickup). */
    private const IN_WORKSHOP = [Maintenance::WF_UNDER_REPAIR, Maintenance::WF_REPAIR_REVIEW, Maintenance::WF_READY_FOR_PICKUP];

    /** KPI groupings — each maps to the workflow_status set it counts (shared with the frontend filters). */
    private const KPI_STATES = [
        'waiting_dispatch'      => [Maintenance::WF_INSPECTION_PENDING],
        'waiting_garage'        => [Maintenance::WF_AWAITING_DISPATCH, Maintenance::WF_IN_TRANSIT],
        'waiting_approval'      => [Maintenance::WF_PENDING_REVIEW, Maintenance::WF_TRIAGE_APPROVAL_PENDING, Maintenance::WF_RECOMMENDATION_PENDING, Maintenance::WF_REPAIR_REVIEW],
        'under_repair'          => [Maintenance::WF_UNDER_REPAIR],
        'waiting_test_drive'    => [Maintenance::WF_INSPECTION_REQUESTED, Maintenance::WF_INSPECTION_DIAGNOSTIC],
        'waiting_reinspection'  => [Maintenance::WF_READY_REINSPECTION, Maintenance::WF_REINSPECTION_FAILED],
        'ready_for_delivery'    => [Maintenance::WF_READY_FOR_PICKUP],
    ];

    // ── Dashboard ────────────────────────────────────────────────────────────────────────────────

    /** The whole Car Status dashboard: KPI strip, live workshop rows, and the operational widgets. */
    public function __construct(
        private readonly OperationalStateLoader $loader,
        private readonly WorkflowStateResolver $repairResolver,
        private readonly MaintenanceDelayResolver $delayResolver,
    ) {}

    public function dashboard(): array
    {
        $open = $this->loader->openTickets();

        $now  = Carbon::now();
        $rows = $open->map(fn (Maintenance $t) => $this->ticketRow($t, $now))->values();

        $repeatRepairs = collect($this->repeatRepairsThisMonth());

        return [
            'kpis'         => $this->dashboardKpis($rows, $repeatRepairs->count()),
            'top_models'   => $this->topModels(),
            'rows'         => $rows,
            'widgets'      => [
                'waiting_parts'          => $this->waitingForParts(),
                'exceeding_sla'          => $rows->whereIn('delay_status', ['at_risk', 'overdue'])->sortByDesc('days_in_maintenance')->values(),
                'repeat_repairs'         => $repeatRepairs->values(),
                'repeat_failures'        => $this->repeatFailuresThisMonth(),
                'waiting_approval'       => $this->waitingForApproval($rows),
                'waiting_garage_accept'  => $rows->whereIn('workflow_status', self::KPI_STATES['waiting_garage'])->values(),
                'highest_cost'           => $this->highestCostThisMonth(),
                'longest_open'           => $rows->sortByDesc('days_in_maintenance')->take(10)->values(),
                'recently_finished'      => $this->recentlyFinished(),
                'high_severity'          => $rows->whereIn('severity', [Maintenance::FAULT_SEVERITY_CRITICAL, Maintenance::FAULT_SEVERITY_HIGH])->values(),
                'waiting_invoice'        => $this->waitingForInvoice(),
                'management_attention'   => $this->managementAttention($rows),
            ],
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /** One live-workshop row. All real data: stage from livePosition(), party/reason from STAGE_META. */
    private function ticketRow(Maintenance $t, Carbon $now): array
    {
        $pos      = $t->livePosition();
        $progress = $t->tasksProgress();
        $sev      = Maintenance::FAULT_SEVERITY_META[$t->fault_severity] ?? null;
        $meta     = self::STAGE_META[$t->workflow_status] ?? ['supervisor', 'In workflow', 'In Workflow'];

        // Operational-row truth — the single interpretation (blueprint DP3-A): resolver-derived,
        // composed here over the loader's eager-loaded graph (no queries).
        $repair    = $this->repairResolver->resolve($t);
        $delay     = $this->delayResolver->resolve($t);
        $effective = EffectiveState::reduce($t->vehicle?->operational_status, $repair);

        // Open (non-terminal) part names on the ticket — a display list, from the loaded graph.
        $openPartNames = $t->tasks
            ->flatMap(fn ($task) => $task->partRequests)
            ->reject(fn (PartRequest $r) => in_array($r->status, PartRequest::TERMINAL, true))
            ->pluck('part_name')->filter()->unique()->values()->all();

        // waiting_parts: now a single source — the mid-repair resolver, which reads the ticket's open part
        // requests. The old pre-ticket awaiting_parts lane is gone; see [[inspection-required-parts-split]].
        $waitingParts = $repair->isWaitingForParts();

        // Days the car has been inside maintenance (since the ticket opened).
        $openedAt        = $t->created_at ?? $t->requested_at;
        $daysInMaint     = $openedAt ? (int) $openedAt->diffInDays($now) : null;

        // SLA target = explicit due date, else the current stage's age vs the default stage SLA.
        $due             = $t->expected_return_date ? Carbon::parse($t->expected_return_date) : null;
        $daysRemaining   = $due ? (int) round($now->copy()->startOfDay()->diffInDays($due->copy()->startOfDay(), false)) : null;
        $stageAgeHours   = $t->last_state_change_at ? (int) round($t->last_state_change_at->diffInHours($now)) : null;
        $slaRemainingHrs = $due
            ? (int) round($now->diffInHours($due, false))
            : ($stageAgeHours !== null ? self::STAGE_SLA_HOURS - $stageAgeHours : null);

        $isOverdue = ($daysRemaining !== null && $daysRemaining < 0) || ($slaRemainingHrs !== null && $slaRemainingHrs < 0);
        $atRisk    = ! $isOverdue && $slaRemainingHrs !== null && $slaRemainingHrs <= 24;

        // SLA tone for the card's badge. Deliberately NOT named $delay: that name already holds the
        // resolver's DelayStatus object (line ~171), and reusing it here silently turned every
        // ->isDelayed read below into a property access on a string — a fatal that killed the whole
        // board. Two different concepts, two different names.
        $delayTone = $isOverdue ? 'overdue' : ($atRisk ? 'at_risk' : 'on_track');

        // The two questions a manager asks in the first 2 seconds: WHY is it here, and WHERE is it in the
        // pipeline. Both derived from data that already exists — no new columns, no guessing.
        $reason = $this->maintenanceReason($t);
        $pipe   = $this->pipelineFor($t->workflow_status);

        // When the ticket opened (used both for "days in maintenance" and as the started-at on the card).
        $startedAt = $t->repair_started_at ?? $t->created_at ?? $t->requested_at;

        return [
            'ticket_id'          => $t->id,
            'ticket_no'          => '#' . $t->id,
            'vehicle_id'         => $t->vehicle_id,
            'plate_no'           => $t->vehicle?->plate_no,
            'car'                => $this->carLabel($t->vehicle),
            'workflow_status'    => $t->workflow_status,
            'reason'             => $reason,
            'pipeline'           => $pipe['steps'],
            'started_at'         => optional($startedAt)->toIso8601String(),
            'stage'              => $pos['label'],
            'stage_detail'       => $pos['detail'],
            'stage_tone'         => $pos['tone'],
            'party'              => $meta[0],
            'responsible'        => $this->partyName($t, $meta[0]),
            'waiting_reason'     => $meta[1],
            'ownership'          => $meta[2],
            'garage'             => $t->vendor?->name,
            'active_faults'      => $progress['open'],
            'fault_total'        => $progress['total'],
            'severity'           => $t->fault_severity,
            'severity_label'     => $sev['label'] ?? null,
            'severity_emoji'     => $sev['emoji'] ?? null,
            'severity_tone'      => $sev['tone'] ?? null,
            'priority'           => $t->priority,
            'progress'           => self::PROGRESS[$t->workflow_status] ?? 30,
            'blocked'            => $repair->isBlocked() || in_array($t->workflow_status, self::BLOCKED_STATES, true),
            'reinspection_required' => in_array($t->workflow_status, self::KPI_STATES['waiting_reinspection'], true),
            'waiting_parts'      => $waitingParts,
            'missing_parts'      => $openPartNames,
            'effective_state'    => [
                'headline'     => $effective->headline,
                'service'      => $effective->service,
                'availability' => $effective->availability,
            ],
            'delay'              => [
                'is_delayed'          => $delay->isDelayed,
                'reason'              => $delay->delayReason,
                'source'              => $delay->delaySource,
                'days_waiting'        => $delay->daysWaiting,
                'expected_resolution' => $delay->expectedResolutionDate,
                'supplier'            => $delay->supplierName,
                'checkpoint_status'   => $delay->checkpointStatus,
                'headline'            => $delay->headline,
            ],
            'days_in_maintenance' => $daysInMaint,
            'expected_completion' => optional($due)->toIso8601String(),
            'sla_remaining_hours' => $slaRemainingHrs,
            'days_remaining'     => $daysRemaining,
            'delay_status'       => $delayTone,
            'is_overdue'         => $isOverdue,
            'days_overdue'       => $isOverdue && $daysRemaining !== null ? abs($daysRemaining) : ($isOverdue && $slaRemainingHrs !== null ? (int) ceil(abs($slaRemainingHrs) / 24) : 0),
            'last_update'        => optional($t->last_state_change_at ?? $t->updated_at)->toIso8601String(),
        ];
    }

    /**
     * "Why is this vehicle in maintenance?" — the single headline the ops board must answer at a glance.
     * Resolved from data that already exists, in priority order: the customer complaint / breakdown reason,
     * the primary (worst open) fault, or the ticket's classification. The `source` says where the reason
     * came from (Inspection / Diagnostic / Scheduled Maintenance / Breakdown Report / Customer Report) and
     * the tone/emoji come from the ticket's inspector-assigned fault severity.
     */
    private function maintenanceReason(Maintenance $t): array
    {
        $tasks = $t->relationLoaded('tasks') ? $t->tasks : collect();
        $rank  = fn ($x) => MaintenanceTask::SEVERITY_RANK[$x->severity] ?? 0;

        // Primary fault = the worst-graded still-open fault, else the worst fault of any state.
        $openTasks = $tasks->whereNotIn('status', MaintenanceTask::TERMINAL);
        $primary   = $openTasks->sortByDesc($rank)->first() ?? $tasks->sortByDesc($rank)->first();
        $faultLabel = $primary?->symptom ?: $this->categoryLabel($primary?->category_key);

        $trigger   = $t->trigger_reason;
        $type      = $t->maintenance_type;
        $complaint = $t->customer_complaint ? Str::limit(trim($t->customer_complaint), 90) : null;

        $isBreakdown  = $type === Maintenance::TYPE_BREAKDOWN || $trigger === Maintenance::TRIGGER_BREAKDOWN;
        $isRoutine    = $type === Maintenance::TYPE_ROUTINE || $trigger === Maintenance::TRIGGER_PERIODIC || $t->visit_context === Maintenance::CONTEXT_ROUTINE;
        $isCustomer   = $trigger === Maintenance::TRIGGER_CUSTOMER;
        $isInspection = in_array($trigger, [Maintenance::TRIGGER_TEST_DRIVE, Maintenance::TRIGGER_PICKUP], true);
        // A fault someone who drove the car reported — checked BEFORE the routine test, so a ticket the
        // inspector later classifies as routine work still reads as a driver-reported issue here.
        $isDriverReported = $trigger === Maintenance::TRIGGER_DRIVER_REPORTED;

        if ($isBreakdown) {
            [$label, $source, $key] = [$complaint ?: $faultLabel ?: 'Breakdown', 'Breakdown Report', 'breakdown'];
        } elseif ($isDriverReported) {
            [$label, $source, $key] = [$complaint ?: $faultLabel ?: 'Driver-reported issue', 'Driver Observation', 'driver'];
        } elseif ($isRoutine) {
            [$label, $source, $key] = [$faultLabel ?: 'Scheduled Service', 'Scheduled Maintenance', 'scheduled'];
        } elseif ($isCustomer) {
            [$label, $source, $key] = [$complaint ?: $faultLabel ?: 'Customer Complaint', 'Customer Report', 'customer'];
        } elseif ($isInspection) {
            [$label, $source, $key] = [$faultLabel ?: 'Inspection Finding', 'Inspection', 'inspection'];
        } else {
            [$label, $source, $key] = [$faultLabel ?: ($complaint ?: (Maintenance::MAINTENANCE_TYPES[$type] ?? 'Diagnostic Finding')), 'Diagnostic', 'diagnostic'];
        }

        $sev = Maintenance::FAULT_SEVERITY_META[$t->fault_severity] ?? null;

        return [
            'label'           => $label,
            'source'          => $source,
            'source_key'      => $key,
            'tone'            => $sev['tone'] ?? 'slate',
            'emoji'           => $sev['emoji'] ?? null,
            'category'        => $this->categoryLabel($primary?->category_key),
            // Extra open faults beyond the headline one — "+2 more issues".
            'other_faults'    => max(0, $openTasks->count() - 1),
        ];
    }

    /**
     * The committed maintenance pipeline for one ticket, each step marked completed / current / blocked /
     * waiting. Powers the card's workflow timeline (Inspection → Decision → … → Ready) so a manager reads
     * WHERE the car is rather than a static progress number. Read-only — mirrors liveWorkflow().
     *
     * @return array{steps: array<int, array{label: string, state: string}>, current_index: int}
     */
    private function pipelineFor(?string $status): array
    {
        $blocked    = in_array($status, self::BLOCKED_STATES, true);
        $currentIdx = 0;
        foreach (self::LIVE_PIPELINE as $i => [$label, $states]) {
            if (in_array($status, $states, true)) {
                $currentIdx = $i;
                break;
            }
        }

        $steps = [];
        foreach (self::LIVE_PIPELINE as $i => [$label, $states]) {
            $steps[] = [
                'label' => $label,
                'state' => $i < $currentIdx ? 'completed' : ($i === $currentIdx ? ($blocked ? 'blocked' : 'current') : 'waiting'),
            ];
        }

        return ['steps' => $steps, 'current_index' => $currentIdx];
    }

    /** The KPI strip — every count derived from the live rows (no extra queries) + the repeat-repair count. */
    private function dashboardKpis(Collection $rows, int $repeatRepairs): array
    {
        $inState = fn (array $states) => $rows->whereIn('workflow_status', $states)->count();

        return [
            'in_maintenance'      => $rows->pluck('vehicle_id')->filter()->unique()->count(),
            'in_workshop'         => $inState(self::IN_WORKSHOP),
            'waiting_dispatch'    => $inState(self::KPI_STATES['waiting_dispatch']),
            'waiting_garage'      => $inState(self::KPI_STATES['waiting_garage']),
            'waiting_approval'    => $inState(self::KPI_STATES['waiting_approval']),
            'waiting_parts'       => $rows->where('waiting_parts', true)->count(),
            'under_repair'        => $inState(self::KPI_STATES['under_repair']),
            'waiting_test_drive'  => $inState(self::KPI_STATES['waiting_test_drive']),
            'waiting_reinspection' => $inState(self::KPI_STATES['waiting_reinspection']),
            'ready_for_delivery'  => $inState(self::KPI_STATES['ready_for_delivery']),
            'overdue'             => $rows->where('is_overdue', true)->count(),
            'high_severity'       => $rows->whereIn('severity', [Maintenance::FAULT_SEVERITY_CRITICAL, Maintenance::FAULT_SEVERITY_HIGH])->count(),
            'repeat_repairs'      => $repeatRepairs,
        ];
    }

    /**
     * The car MODELS that most often go to maintenance — a fleet-intelligence KPI ("what type of car keeps
     * ending up in the shop?"). Counts every workflow ticket ever opened, grouped by make+model, top 8.
     */
    private function topModels(): array
    {
        return Maintenance::query()
            ->whereNotNull('maintenances.workflow_status')
            ->whereNotNull('maintenances.vehicle_id')
            ->join('vehicles', 'vehicles.id', '=', 'maintenances.vehicle_id')
            ->selectRaw('vehicles.make as make, vehicles.model as model, COUNT(*) as c')
            ->groupBy('vehicles.make', 'vehicles.model')
            ->orderByDesc('c')
            ->limit(8)
            ->get()
            ->map(fn ($r) => ['label' => trim(($r->make ?? '') . ' ' . ($r->model ?? '')) ?: 'Unknown', 'value' => (int) $r->c])
            ->values()->all();
    }

    /** The name of the responsible person for a party, from the eager-loaded relations (else the role). */
    private function partyName(Maintenance $t, string $party): string
    {
        return match ($party) {
            'garage'    => $t->vendor?->name ?? 'Garage',
            'vendor'    => $t->vendor?->name ?? 'Vendor',
            'driver'    => $t->assignedDriver?->name ?? 'Driver',
            'inspector' => $t->inspector?->name ?? 'Inspector',
            'system'    => 'System',
            default     => 'Supervisor',
        };
    }

    // ── Operational widgets ─────────────────────────────────────────────────────────────────────

    /** Vehicles repaired 2+ times THIS month — recurring problems. Rolled up from this month's closed tickets. */
    private function repeatRepairsThisMonth(): array
    {
        $since = Carbon::now()->startOfMonth();

        return Maintenance::query()
            ->where('workflow_status', Maintenance::WF_CLOSED)
            ->where('wf_closed_at', '>=', $since)
            ->whereNotNull('vehicle_id')
            ->with(['vehicle:id,plate_no,make,model', 'vendor:id,name', 'tasks:id,maintenance_id,category_key,symptom,kind'])
            ->get()
            ->groupBy('vehicle_id')
            ->filter(fn ($g) => $g->count() >= 2)
            ->map(function ($g) {
                $vehicle   = $g->first()->vehicle;
                // Event Type layer: repeat-FAULT detection ignores planned services once enforced.
                $catCounts = $g->flatMap(fn ($t) => (\App\Support\EventKind::enforced() ? $t->tasks->where('kind', MaintenanceTask::KIND_FAULT) : $t->tasks)->pluck('category_key'))->filter()->countBy();
                return [
                    'vehicle_id'    => $g->first()->vehicle_id,
                    'plate_no'      => $vehicle?->plate_no,
                    'car'           => $this->carLabel($vehicle),
                    'repairs'       => $g->count(),
                    'repeat_faults' => $catCounts->filter(fn ($c) => $c > 1)
                        ->map(fn ($c, $key) => ['category' => $this->categoryLabel($key), 'count' => $c])
                        ->sortByDesc('count')->values()->all(),
                    'garages'       => $g->pluck('vendor.name')->filter()->unique()->values()->all(),
                    'total_cost'    => round((float) $g->sum('cost'), 2),
                ];
            })
            ->sortByDesc('repairs')->values()->all();
    }

    /** Vehicles that failed a QC re-inspection (a fix that didn't hold) this month — the repeat-failure watchlist. */
    private function repeatFailuresThisMonth(): array
    {
        return RepairInspection::query()
            ->where('is_recurrence', true)
            ->where('inspection_date', '>=', Carbon::now()->startOfMonth())
            ->with('vehicle:id,plate_no,make,model')
            ->get()
            ->groupBy('vehicle_id')
            ->map(fn ($g) => [
                'vehicle_id'  => $g->first()->vehicle_id,
                'plate_no'    => $g->first()->vehicle?->plate_no,
                'car'         => $this->carLabel($g->first()->vehicle),
                'occurrences' => $g->count(),
                'last_seen'   => optional($g->max('inspection_date'))->toIso8601String(),
            ])
            ->sortByDesc('occurrences')->values()->all();
    }

    /** Every vehicle currently waiting on a part — one row per open part request. */
    private function waitingForParts(): array
    {
        $now = Carbon::now();

        return PartRequest::query()
            ->whereNotIn('status', PartRequest::TERMINAL)
            ->with([
                'vehicle:id,plate_no,make,model',
                'maintenance:id,vendor_id',
                'maintenance.vendor:id,name',
                'purchases:id,part_request_id,source_vendor_id,source_name',
                'purchases.sourceVendor:id,name',
            ])
            ->orderBy('requested_at')
            ->limit(400)
            ->get()
            ->map(function (PartRequest $r) use ($now) {
                $purchase = $r->purchases->last();
                return [
                    'request_id'      => $r->id,
                    'ticket_id'       => $r->maintenance_id,
                    'vehicle_id'      => $r->vehicle_id,
                    'plate_no'        => $r->vehicle?->plate_no,
                    'car'             => $this->carLabel($r->vehicle),
                    'part'            => $r->part_name,
                    'quantity'        => (float) $r->quantity,
                    'purchase_status' => $r->status,
                    'vendor'          => $purchase?->sourceVendor?->name ?? $purchase?->source_name ?? $r->maintenance?->vendor?->name,
                    'days_waiting'    => $r->requested_at ? (int) $r->requested_at->diffInDays($now) : null,
                    'estimated_price' => $r->estimated_price !== null ? (float) $r->estimated_price : null,
                ];
            })->all();
    }

    /** Every vehicle blocked on a human sign-off — reuses the live rows (pure in-memory). */
    private function waitingForApproval(Collection $rows): array
    {
        return $rows
            ->whereIn('workflow_status', self::KPI_STATES['waiting_approval'])
            ->map(fn ($r) => array_merge($r, [
                'approval_type' => $r['waiting_reason'],
                'approver'      => $r['responsible'],
            ]))
            ->sortByDesc('days_in_maintenance')->values()->all();
    }

    /** The most expensive repairs closed this month (money — gated in the UI by SHOW_FINANCIALS). */
    private function highestCostThisMonth(): array
    {
        return Maintenance::query()
            ->where('workflow_status', Maintenance::WF_CLOSED)
            ->where('wf_closed_at', '>=', Carbon::now()->startOfMonth())
            ->whereNotNull('vehicle_id')
            ->where('cost', '>', 0)
            ->with(['vehicle:id,plate_no,make,model', 'vendor:id,name'])
            ->orderByDesc('cost')
            ->limit(10)
            ->get()
            ->map(fn (Maintenance $t) => [
                'ticket_id'  => $t->id,
                'vehicle_id' => $t->vehicle_id,
                'plate_no'   => $t->vehicle?->plate_no,
                'car'        => $this->carLabel($t->vehicle),
                'garage'     => $t->vendor?->name,
                'cost'       => (float) $t->cost,
                'closed_at'  => optional($t->wf_closed_at)->toIso8601String(),
            ])->all();
    }

    /** Vehicles whose repair was signed off this week — the "just delivered" board. */
    private function recentlyFinished(): array
    {
        $tickets = Maintenance::query()
            ->where('workflow_status', Maintenance::WF_CLOSED)
            ->where('wf_closed_at', '>=', Carbon::now()->startOfWeek())
            ->whereNotNull('vehicle_id')
            ->with(['vehicle:id,plate_no,make,model', 'vendor:id,name'])
            ->orderByDesc('wf_closed_at')
            ->limit(200)
            ->get();

        $closerNames = User::whereIn('id', $tickets->pluck('wf_closed_by')->filter()->unique())->pluck('name', 'id');
        $todayStart  = Carbon::now()->startOfDay();

        return $tickets->map(function (Maintenance $t) use ($todayStart, $closerNames) {
            $closed = $t->wf_closed_at;
            return [
                'ticket_id'    => $t->id,
                'vehicle_id'   => $t->vehicle_id,
                'plate_no'     => $t->vehicle?->plate_no,
                'car'          => $this->carLabel($t->vehicle),
                'garage'       => $t->vendor?->name,
                'closed_at'    => optional($closed)->toIso8601String(),
                'today'        => $closed && $closed->gte($todayStart),
                'closed_by'    => $closerNames[$t->wf_closed_by] ?? null,
                'cost'         => $t->cost !== null ? (float) $t->cost : null,
                'repair_hours' => ($t->repair_started_at && $closed) ? (int) round($t->repair_started_at->diffInHours($closed)) : null,
            ];
        })->all();
    }

    /** Cars back in service with an outstanding garage invoice (awaiting_invoice) — the finance chase list. */
    private function waitingForInvoice(): array
    {
        $now = Carbon::now();

        return Maintenance::query()
            ->where('workflow_status', Maintenance::WF_AWAITING_INVOICE)
            ->whereNotNull('vehicle_id')
            ->with(['vehicle:id,plate_no,make,model', 'vendor:id,name'])
            ->orderBy('awaiting_invoice_since')
            ->limit(200)
            ->get()
            ->map(function (Maintenance $t) use ($now) {
                $since = $t->awaiting_invoice_since;
                return [
                    'ticket_id'   => $t->id,
                    'vehicle_id'  => $t->vehicle_id,
                    'plate_no'    => $t->vehicle?->plate_no,
                    'car'         => $this->carLabel($t->vehicle),
                    'garage'      => $t->vendor?->name,
                    'days_waiting' => $since ? (int) $since->diffInDays($now) : null,
                    'overdue'     => $since ? $since->diffInDays($now) > 3 : false, // INVOICE_SLA_DAYS = 3
                ];
            })->all();
    }

    /**
     * Vehicles requiring management attention — the single roll-up a manager scans first. Each open ticket
     * is scored for attention reasons (overdue, blocked/parts, waiting approval/invoice, high severity,
     * excessive duration) and only cars with at least one reason surface, worst first.
     */
    private function managementAttention(Collection $rows): array
    {
        return $rows->map(function ($r) {
            $reasons = [];
            if ($r['is_overdue']) {
                $reasons[] = 'Overdue';
            }
            if ($r['workflow_status'] === Maintenance::WF_REINSPECTION_FAILED) {
                $reasons[] = 'Recurring failure';
            }
            if (in_array($r['workflow_status'], self::KPI_STATES['waiting_approval'], true)) {
                $reasons[] = 'Waiting approval';
            }
            if ($r['waiting_parts']) {
                $reasons[] = 'Waiting parts';
            }
            if (in_array($r['severity'], [Maintenance::FAULT_SEVERITY_CRITICAL, Maintenance::FAULT_SEVERITY_HIGH], true)) {
                $reasons[] = 'High severity';
            }
            if ($r['days_in_maintenance'] !== null && $r['days_in_maintenance'] >= 14) {
                $reasons[] = 'Excessive duration';
            }
            return array_merge($r, ['reasons' => $reasons, 'attention' => count($reasons)]);
        })
        ->filter(fn ($r) => $r['attention'] > 0)
        ->sortByDesc('attention')
        ->take(30)
        ->values()->all();
    }

    // ── Vehicle Maintenance Intelligence Center ─────────────────────────────────────────────────────

    /** The full analytics profile for ONE vehicle. Loads its whole history once, derives everything in memory. */
    public function vehicleIntelligence(Vehicle $vehicle): array
    {
        $tickets = Maintenance::query()
            ->where('vehicle_id', $vehicle->id)
            ->with([
                'vendor:id,name',
                'tasks',
                'tasks.currentVendor:id,name',
                'tasks.resolvedBy:id,name',
                'lineItems:id,maintenance_id,maintenance_task_id,kind,line_total,warranty_until,category_key',
            ])
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        $tasks       = $tickets->flatMap->tasks;
        // Event Type layer: once enforced, the FAULT metrics (health, KPIs, analytics, reliability, fault
        // history) count only kind=fault — planned services drop out. Cost + the "why in shop" headline keep
        // ALL tasks (a service still has cost and can be the reason a car is in the shop).
        $faultTasks  = \App\Support\EventKind::enforced() ? $tasks->where('kind', MaintenanceTask::KIND_FAULT)->values() : $tasks;
        $inspections = RepairInspection::query()
            ->where('vehicle_id', $vehicle->id)
            ->orderByDesc('inspection_date')
            ->limit(500)
            ->get();
        $inspByTicket = $inspections->groupBy('maintenance_id');

        // The full append-only workflow audit trail for this car — loaded ONCE and reused two ways:
        // grouped by task it powers each fault's lifecycle strip; grouped by ticket it powers the
        // stage-by-stage Workflow Journey. This is the SAME VehicleLogEvent trail the (retired) profile
        // timeline read — no new source, no new write path.
        $logEvents = \App\Models\VehicleLogEvent::query()
            ->where('vehicle_id', $vehicle->id)
            ->with('actor:id,name')
            ->orderBy('occurred_at')
            ->limit(3000)
            ->get();
        $logByTask = $logEvents->whereNotNull('maintenance_task_id')->groupBy('maintenance_task_id');

        // The car's live open ticket (if any) → the header, ownership banner and embedded Live Workflow.
        // A car can hold more than one open ticket (e.g. a fresh pending_review request alongside an active
        // repair still in re-inspection). Prefer the real, COMMITTED maintenance job (a non-pre-ticket state
        // — the one actually in the workshop pipeline, with findings/audit/odometer) over a pre-ticket gate,
        // so the embedded workflow shows the meaningful ticket, not an near-empty new request.
        $notTerminal = fn ($t) => $t->workflow_status && ! in_array($t->workflow_status, Maintenance::WF_TERMINAL, true);
        $openTicket = $tickets->first(fn ($t) => $notTerminal($t) && ! in_array($t->workflow_status, Maintenance::WF_PRE_TICKET, true))
            ?? $tickets->first($notTerminal);

        $health = $this->healthScore($tickets, $faultTasks, $inspections);
        $kpis   = $this->vehicleKpis($tickets, $faultTasks, $inspections);

        return [
            'vehicle'      => [
                'id'                 => $vehicle->id,
                'plate_no'           => $vehicle->plate_no,
                'car'                => $this->carLabel($vehicle),
                'vin'                => $vehicle->vin,
                'odometer'           => $vehicle->odometer,
                'operational_status' => $vehicle->operational_status,
                'condition_grade'    => $vehicle->condition_grade,
            ],
            'header'       => $this->header($vehicle, $openTicket, $health, $kpis),
            'ownership'    => $this->currentOwnership($openTicket),
            'live_workflow' => $this->liveWorkflow($openTicket),
            'kpis'         => $kpis,
            'history_summary' => $this->historySummary($vehicle, $tickets),
            'fault_analytics' => $this->faultAnalytics($faultTasks),
            'costs'        => $this->costBreakdown($tickets, $tasks),
            'parts'        => $this->partsIntelligence($vehicle),
            'reliability'  => $this->reliability($tickets, $faultTasks, $inspections),
            'garages'      => $this->garagePerformance($tickets, $inspections),
            'history'      => $this->repairHistory($tickets, $inspByTicket),
            'faults'       => $this->faultHistory($faultTasks, $logByTask),
            'workflow_journey' => $this->workflowJourney($logEvents),
            'documents'    => $this->documents($vehicle, $tickets),
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * The header strip — the "what is happening now?" answer: current stage/owner/garage, expected
     * completion, how long the current repair has run, health, progress and the derived risk level.
     */
    private function header(Vehicle $vehicle, ?Maintenance $openTicket, array $health, array $kpis): array
    {
        $pos      = $openTicket?->livePosition();
        $meta     = $openTicket ? (self::STAGE_META[$openTicket->workflow_status] ?? ['supervisor', 'In workflow', 'In Workflow']) : null;
        $repairSince = $openTicket?->repair_started_at ?? $openTicket?->created_at;

        return [
            'plate_no'        => $vehicle->plate_no,
            'car'             => $this->carLabel($vehicle),
            'image_url'       => $vehicle->image_url ?? null,
            'in_maintenance'  => $openTicket !== null,
            'ticket_id'       => $openTicket?->id,
            'stage'           => $pos['label'] ?? 'Not in Maintenance',
            'stage_tone'      => $pos['tone'] ?? 'emerald',
            'owner'           => $openTicket ? $this->partyName($openTicket, $meta[0]) : null,
            'owner_party'     => $meta[0] ?? null,
            'garage'          => $openTicket?->vendor?->name,
            'expected_completion' => optional($openTicket?->expected_return_date ? Carbon::parse($openTicket->expected_return_date) : null)->toIso8601String(),
            'repair_days'     => $repairSince ? (int) $repairSince->diffInDays(Carbon::now()) : null,
            'health_score'    => $health['score'],
            'health_grade'    => $health['grade'],
            'health_tone'     => $health['tone'],
            'progress'        => $openTicket ? (self::PROGRESS[$openTicket->workflow_status] ?? 30) : 100,
            'risk'            => $this->riskLevel($openTicket, $health['score'], $kpis),
        ];
    }

    /** Derived operational risk for the header — critical/high/medium/low from severity, overdue, health. */
    private function riskLevel(?Maintenance $openTicket, int $health, array $kpis): array
    {
        if (! $openTicket) {
            return ['level' => 'none', 'tone' => 'slate', 'label' => 'Not in maintenance'];
        }
        $overdue    = $openTicket->expected_return_date && Carbon::parse($openTicket->expected_return_date)->isPast();
        $highSev    = in_array($openTicket->fault_severity, [Maintenance::FAULT_SEVERITY_CRITICAL, Maintenance::FAULT_SEVERITY_HIGH], true);
        $failed     = $openTicket->workflow_status === Maintenance::WF_REINSPECTION_FAILED;

        if (($overdue && $highSev) || $health < 40) {
            return ['level' => 'critical', 'tone' => 'red', 'label' => 'Critical'];
        }
        if ($overdue || $highSev || $failed) {
            return ['level' => 'high', 'tone' => 'red', 'label' => 'High'];
        }
        if ($kpis['open_faults'] > 0 || $health < 70) {
            return ['level' => 'medium', 'tone' => 'amber', 'label' => 'Medium'];
        }
        return ['level' => 'low', 'tone' => 'emerald', 'label' => 'Low'];
    }

    /**
     * The Live Workflow stage tracker for the car's open ticket — the canonical pipeline with each step
     * marked completed / current / waiting, plus a blocked flag. Read-only presentation; the ACTUAL stage
     * actions still live on the Maintenance Workflow ticket (ticket_id), which the page deep-links to.
     */
    private function liveWorkflow(?Maintenance $openTicket): ?array
    {
        if (! $openTicket) {
            return null;
        }
        $status  = $openTicket->workflow_status;
        $blocked = in_array($status, self::BLOCKED_STATES, true);

        // Which pipeline step the ticket currently sits at.
        $currentIdx = 0;
        foreach (self::LIVE_PIPELINE as $i => [$label, $states]) {
            if (in_array($status, $states, true)) {
                $currentIdx = $i;
                break;
            }
        }

        $steps = [];
        foreach (self::LIVE_PIPELINE as $i => [$label, $states]) {
            $state = $i < $currentIdx ? 'completed' : ($i === $currentIdx ? ($blocked ? 'blocked' : 'current') : 'waiting');
            $steps[] = ['label' => $label, 'state' => $state];
        }

        $pos = $openTicket->livePosition();
        return [
            'ticket_id'   => $openTicket->id,
            'stage'       => $pos['label'],
            'stage_detail' => $pos['detail'],
            'stage_tone'  => $pos['tone'],
            'blocked'     => $blocked,
            'progress'    => self::PROGRESS[$status] ?? 30,
            'steps'       => $steps,
        ];
    }

    /**
     * Clickable maintenance-history summary counts — the "don't browse 100 events" widgets. Case types
     * come from the tickets; the activity counts (test drives, dispatches, transfers, inspections) come
     * from the append-only vehicle_log_events audit, grouped once.
     */
    private function historySummary(Vehicle $vehicle, Collection $tickets): array
    {
        $events = \App\Models\VehicleLogEvent::query()
            ->where('vehicle_id', $vehicle->id)
            ->selectRaw('event_type, COUNT(*) as c')
            ->groupBy('event_type')
            ->pluck('c', 'event_type');

        $breakdowns = $tickets->filter(fn ($t) => $t->maintenance_type === Maintenance::TYPE_BREAKDOWN || $t->trigger_reason === Maintenance::TRIGGER_BREAKDOWN)->count();
        $routine    = $tickets->filter(fn ($t) => $t->maintenance_type === Maintenance::TYPE_ROUTINE || $t->trigger_reason === Maintenance::TRIGGER_PERIODIC || $t->visit_context === Maintenance::CONTEXT_ROUTINE)->count();
        $accidents  = $tickets->whereIn('maintenance_type', [Maintenance::TYPE_INS_INCIDENT, Maintenance::TYPE_NON_INS_INCIDENT])->count();

        return [
            ['key' => 'all',          'label' => 'Total Events',     'count' => $tickets->count()],
            ['key' => 'breakdown',    'label' => 'Breakdowns',       'count' => $breakdowns],
            ['key' => 'routine',      'label' => 'Routine Services',  'count' => $routine],
            ['key' => 'accident',     'label' => 'Accidents',        'count' => $accidents],
            ['key' => 'test_drive',   'label' => 'Test Drives',      'count' => (int) $events->get(\App\Models\VehicleLogEvent::EVENT_DIAGNOSTIC_STARTED, 0)],
            ['key' => 'inspection',   'label' => 'Inspections',      'count' => (int) $events->get(\App\Models\VehicleLogEvent::EVENT_INSPECTION_REQUESTED, 0)],
            ['key' => 'dispatch',     'label' => 'Dispatches',       'count' => (int) $events->get(\App\Models\VehicleLogEvent::EVENT_DISPATCHED, 0)],
            ['key' => 'transfer',     'label' => 'Garage Transfers',  'count' => (int) $events->get(\App\Models\VehicleLogEvent::EVENT_TASK_TRANSFERRED, 0)],
        ];
    }

    /**
     * Cost intelligence — monthly spend (with a cumulative running total), parts vs labor, recurring-fault
     * spend, and a per-garage cost comparison. All from the car's tickets + line items (money — the UI
     * gates the display behind SHOW_FINANCIALS).
     */
    private function costBreakdown(Collection $tickets, Collection $tasks): array
    {
        $lineItems = $tickets->flatMap->lineItems;
        $monthly   = $this->monthlySums($tickets->map(fn ($t) => ['at' => $t->wf_closed_at ?? $t->created_at, 'value' => (float) $t->cost]), 12);

        // Running total layered onto the monthly series.
        $run = 0;
        $monthly = array_map(function ($m) use (&$run) {
            $run += $m['value'];
            return array_merge($m, ['running' => round($run, 2)]);
        }, $monthly);

        // Recurring-fault spend — cost carried by tasks whose symptom recurred on this car.
        $repeatedSymptoms = $tasks->filter(fn ($t) => $t->symptom)
            ->groupBy(fn ($t) => Str::lower(trim($t->symptom)))
            ->filter(fn ($g) => $g->count() >= 2)->keys();
        $recurringCost = (float) $tasks->filter(fn ($t) => $t->symptom && $repeatedSymptoms->contains(Str::lower(trim($t->symptom))))
            ->sum(fn ($t) => (float) $t->parts_cost + (float) $t->labor_cost);

        $byGarage = $tickets->filter(fn ($t) => $t->vendor_id)
            ->groupBy('vendor_id')
            ->map(fn ($g) => ['label' => $g->first()->vendor?->name ?? ('Garage #' . $g->first()->vendor_id), 'value' => round((float) $g->sum('cost'), 2)])
            ->sortByDesc('value')->values()->all();

        return [
            'total'        => round((float) $tickets->sum('cost'), 2),
            'parts'        => round((float) $lineItems->where('kind', 'part')->sum('line_total'), 2),
            'labor'        => round((float) $lineItems->where('kind', 'labor')->sum('line_total'), 2),
            'recurring'    => round($recurringCost, 2),
            'monthly'      => $monthly,
            'by_garage'    => $byGarage,
        ];
    }

    /**
     * Parts intelligence for the vehicle — installed / pending / rejected counts, components replaced more
     * than once, and per-supplier performance (buys + average delivery time from purchase → install).
     */
    private function partsIntelligence(Vehicle $vehicle): array
    {
        $requests = PartRequest::query()
            ->where('vehicle_id', $vehicle->id)
            ->with(['purchases.sourceVendor:id,name', 'purchases:id,part_request_id,source_vendor_id,source_name,purchased_at,installed_at'])
            ->limit(500)
            ->get();

        $installed = $requests->where('status', PartRequest::STATUS_INSTALLED)->count()
            + $requests->where('status', PartRequest::STATUS_COMPLETED)->count();
        $pending   = $requests->whereIn('status', [PartRequest::STATUS_REQUESTED, PartRequest::STATUS_UNDER_REVIEW, PartRequest::STATUS_APPROVED, PartRequest::STATUS_PURCHASED])->count();
        $rejected  = $requests->where('status', PartRequest::STATUS_REJECTED)->count();

        // Components replaced 2+ times (by part name) — the "keeps failing" parts.
        $repeated = $requests->filter(fn ($r) => $r->part_name)
            ->groupBy(fn ($r) => Str::lower(trim($r->part_name)))
            ->filter(fn ($g) => $g->count() >= 2)
            ->map(fn ($g) => ['part' => $g->first()->part_name, 'times' => $g->count()])
            ->sortByDesc('times')->values()->all();

        // Per-supplier: number of buys + average purchase→install delivery time (days).
        $purchases = $requests->flatMap->purchases;
        $suppliers = $purchases->filter(fn ($p) => $p->sourceVendor || $p->source_name)
            ->groupBy(fn ($p) => $p->sourceVendor?->name ?? $p->source_name)
            ->map(function ($g, $name) {
                $deliv = $g->filter(fn ($p) => $p->purchased_at && $p->installed_at)
                    ->map(fn ($p) => $p->purchased_at->diffInDays($p->installed_at))->filter(fn ($d) => $d >= 0);
                return [
                    'supplier'       => $name,
                    'purchases'      => $g->count(),
                    'avg_delivery_days' => $deliv->isEmpty() ? null : (int) round($deliv->avg()),
                ];
            })
            ->sortByDesc('purchases')->values()->all();

        return [
            'installed'  => $installed,
            'pending'    => $pending,
            'rejected'   => $rejected,
            'repeated'   => $repeated,
            'suppliers'  => $suppliers,
        ];
    }

    /** The car's operational owner right now (from its open ticket), or "Available" when not in the shop. */
    private function currentOwnership(?Maintenance $openTicket): array
    {
        if (! $openTicket) {
            return ['label' => 'Not in Maintenance', 'party' => null, 'reason' => 'No open maintenance ticket', 'tone' => 'emerald', 'ticket_id' => null];
        }
        $pos  = $openTicket->livePosition();
        $meta = self::STAGE_META[$openTicket->workflow_status] ?? ['supervisor', 'In workflow', 'In Workflow'];
        return [
            'label'     => $meta[2],
            'party'     => $meta[0],
            'reason'    => $meta[1],
            'stage'     => $pos['label'],
            'tone'      => $pos['tone'],
            'garage'    => $openTicket->vendor?->name,
            'ticket_id' => $openTicket->id,
        ];
    }

    /** Vehicle-level KPIs — every figure summed/counted from the loaded tickets, faults and inspections. */
    private function vehicleKpis(Collection $tickets, Collection $tasks, Collection $inspections): array
    {
        $closed       = $tickets->where('workflow_status', Maintenance::WF_CLOSED);
        $openFaults   = $tasks->whereNotIn('status', MaintenanceTask::TERMINAL);
        $closedFaults = $tasks->where('status', MaintenanceTask::STATUS_COMPLETED);
        $lineItems    = $tickets->flatMap->lineItems;
        $totalCost    = (float) $tickets->sum('cost');
        $cases        = $tickets->count();

        $repairDur = $tickets->filter(fn ($t) => $t->repair_started_at && $t->wf_closed_at)
            ->map(fn ($t) => $t->repair_started_at->diffInHours($t->wf_closed_at))->filter(fn ($h) => $h >= 0);

        $downtimeHours = $this->downtimeHours($tickets);

        // Preventive (routine/scheduled) vs corrective (breakdown/incident) split.
        $preventive = $tickets->filter(fn ($t) => $t->maintenance_type === Maintenance::TYPE_ROUTINE
            || $t->trigger_reason === Maintenance::TRIGGER_PERIODIC
            || $t->visit_context === Maintenance::CONTEXT_ROUTINE)->count();
        $corrective = max(0, $cases - $preventive);

        $highSeverity = $tasks->whereIn('severity', [Maintenance::FAULT_SEVERITY_CRITICAL, Maintenance::FAULT_SEVERITY_HIGH])->count();

        return [
            'health_score'      => $this->healthScore($tickets, $tasks, $inspections)['score'],
            'reliability_score' => $this->reliabilityScore($tickets, $tasks, $inspections),
            'total_cost'        => round($totalCost, 2),
            'avg_repair_cost'   => $closed->count() ? round($totalCost / max(1, $closed->count()), 2) : 0,
            'avg_repair_hours'  => $repairDur->isEmpty() ? null : (int) round($repairDur->avg()),
            'lifetime_downtime_days' => (int) round($downtimeHours / 24),
            'total_cases'       => $cases,
            'repeat_repairs'    => $inspections->where('is_recurrence', true)->count(),
            'open_faults'       => $openFaults->count(),
            'closed_faults'     => $closedFaults->count(),
            'high_severity_faults' => $highSeverity,
            'warranty_repairs'  => $lineItems->whereNotNull('warranty_until')->pluck('maintenance_id')->unique()->count(),
            'preventive'        => $preventive,
            'corrective'        => $corrective,
            'preventive_ratio'  => $cases ? round($preventive / $cases * 100) : 0,
            'parts_cost'        => round((float) $lineItems->where('kind', 'part')->sum('line_total'), 2),
            'labor_cost'        => round((float) $lineItems->where('kind', 'labor')->sum('line_total'), 2),
        ];
    }

    /**
     * Fault analytics — "why does this car keep entering maintenance?". Per-category aggregates (count,
     * share, cost, avg repair time, parts consumed, garages), the most-common / most-expensive / most-
     * repeated fault, the recurrence rate, and monthly + yearly trends. All from the car's own tasks.
     */
    private function faultAnalytics(Collection $tasks): array
    {
        $total = $tasks->count();

        $categories = $tasks->filter(fn ($t) => $t->category_key)
            ->groupBy('category_key')
            ->map(function ($g, $key) use ($total) {
                $cost  = (float) $g->sum(fn ($t) => (float) $t->parts_cost + (float) $t->labor_cost);
                $hours = $g->map(fn ($t) => $t->repair_hours !== null ? (float) $t->repair_hours : null)->filter();
                $parts = $g->flatMap(fn ($t) => $t->relationLoaded('lineItems') ? $t->lineItems : collect())->where('kind', 'part')->count();
                return [
                    'key'       => $key,
                    'label'     => $this->categoryLabel($key),
                    'count'     => $g->count(),
                    'percent'   => $total ? round($g->count() / $total * 100, 1) : 0,
                    'cost'      => round($cost, 2),
                    'avg_hours' => $hours->isEmpty() ? null : (int) round($hours->avg()),
                    'avg_cost'  => round($cost / max(1, $g->count()), 2),
                    'parts'     => $parts,
                    'garages'   => $g->pluck('currentVendor.name')->filter()->unique()->values()->all(),
                ];
            })
            ->sortByDesc('count')->values();

        // Per-symptom recurrence (2+ occurrences) → most-repeated + recurrence rate.
        $bySymptom = $tasks->filter(fn ($t) => $t->symptom)->groupBy(fn ($t) => Str::lower(trim($t->symptom)));
        $repeated  = $bySymptom->filter(fn ($g) => $g->count() >= 2);
        $mostRepeated = $repeated->sortByDesc(fn ($g) => $g->count())->first();

        return [
            'total'          => $total,
            'categories'     => $categories->all(),
            'most_common'    => $categories->first(),
            'most_expensive' => $categories->sortByDesc('cost')->first(),
            'most_repeated'  => $mostRepeated ? [
                'symptom'     => $mostRepeated->first()->symptom,
                'category'    => $this->categoryLabel($mostRepeated->first()->category_key),
                'occurrences' => $mostRepeated->count(),
            ] : null,
            'recurrence_rate' => $total ? round($repeated->sum(fn ($g) => $g->count()) / $total * 100) : 0,
            'monthly_trend'  => $this->monthlyCounts($tasks->filter(fn ($t) => $t->identified_at)->map(fn ($t) => $t->identified_at), 12),
            'yearly_trend'   => $this->yearlyCounts($tasks->filter(fn ($t) => $t->identified_at)->map(fn ($t) => $t->identified_at), 5),
        ];
    }

    /**
     * Reliability analytics — MTBF, MTTR, repair frequency, days/km between failures, cost per km/case, and
     * the failure + cost trends. Derived from the ordered case history and the odometer captured per case.
     */
    private function reliability(Collection $tickets, Collection $tasks, Collection $inspections): array
    {
        // Case open dates, oldest → newest, for MTBF + frequency.
        $dates = $tickets->map(fn ($t) => $t->created_at)->filter()->sort()->values();
        $span  = $dates->count() >= 2 ? $dates->first()->diffInDays($dates->last()) : 0;
        $mtbf  = $dates->count() >= 2 ? (int) round($span / ($dates->count() - 1)) : null;

        // Odometer captured at each case (first available checkpoint), for km-between-failures + cost/km.
        $readings = $tickets->map(function ($t) {
            $odo = $t->report_odometer ?? $t->intake_odometer ?? $t->receive_odometer ?? $t->test_odometer ?? $t->dispatch_odometer;
            return ['at' => $t->created_at, 'odo' => $odo ? (int) $odo : null];
        })->filter(fn ($r) => $r['odo'] && $r['at'])->sortBy('at')->values();
        $kmSpan = $readings->count() >= 2 ? max(0, $readings->last()['odo'] - $readings->first()['odo']) : 0;
        $kmBetween = $readings->count() >= 2 ? (int) round($kmSpan / ($readings->count() - 1)) : null;

        $mttr = $tickets->filter(fn ($t) => $t->repair_started_at && $t->wf_closed_at)
            ->map(fn ($t) => $t->repair_started_at->diffInHours($t->wf_closed_at))->filter(fn ($h) => $h >= 0);

        $totalCost = (float) $tickets->sum('cost');
        $cases     = $tickets->count();

        return [
            'mtbf_days'          => $mtbf,
            'mttr_hours'         => $mttr->isEmpty() ? null : (int) round($mttr->avg()),
            'repair_frequency'   => $span > 30 ? round($cases / ($span / 30), 1) : null, // cases per 30 days
            'avg_days_between_failures' => $mtbf,
            'avg_km_between_failures'   => $kmBetween,
            'cost_per_km'        => $kmSpan > 0 ? round($totalCost / $kmSpan, 2) : null,
            'cost_per_case'      => $cases ? round($totalCost / $cases, 2) : 0,
            'failure_trend'      => $this->monthlyCounts($tickets->map(fn ($t) => $t->created_at)->filter(), 12),
            'cost_trend'         => $this->monthlySums($tickets->map(fn ($t) => ['at' => $t->wf_closed_at ?? $t->created_at, 'value' => (float) $t->cost]), 12),
        ];
    }

    /**
     * Per-garage performance FOR THIS VEHICLE — repairs, avg duration, avg cost, comeback rate (a fix that
     * later failed QC), repeat failures, and a quality score. Plus the fastest / best / worst callouts.
     */
    private function garagePerformance(Collection $tickets, Collection $inspections): array
    {
        // Comebacks are keyed by the garage that did the earlier repair (previous_vendor_id).
        $comebacksByVendor = $inspections->where('is_recurrence', true)->groupBy('previous_vendor_id');
        $failsByVendor     = $inspections->whereIn('result', ['still_exists', 'new_issue'])->groupBy('previous_vendor_id');

        $garages = $tickets->filter(fn ($t) => $t->vendor_id)
            ->groupBy('vendor_id')
            ->map(function ($g, $vendorId) use ($comebacksByVendor, $failsByVendor) {
                $repairs = $g->count();
                $dur = $g->filter(fn ($t) => $t->repair_started_at && $t->wf_closed_at)
                    ->map(fn ($t) => $t->repair_started_at->diffInHours($t->wf_closed_at))->filter(fn ($h) => $h >= 0);
                $comebacks = ($comebacksByVendor->get($vendorId) ?? collect())->count();
                $fails     = ($failsByVendor->get($vendorId) ?? collect())->count();
                $comebackRate = $repairs ? round($comebacks / $repairs * 100) : 0;

                return [
                    'vendor_id'    => (int) $vendorId,
                    'garage'       => $g->first()->vendor?->name ?? ('Garage #' . $vendorId),
                    'repairs'      => $repairs,
                    'avg_hours'    => $dur->isEmpty() ? null : (int) round($dur->avg()),
                    'avg_cost'     => round((float) $g->avg('cost'), 2),
                    'comebacks'    => $comebacks,
                    'comeback_rate' => $comebackRate,
                    'repeat_failures' => $fails,
                    'quality_score' => max(0, 100 - $comebackRate - $fails * 5),
                ];
            })
            ->sortByDesc('repairs')->values();

        $withDur = $garages->filter(fn ($x) => $x['avg_hours'] !== null);

        return [
            'rows'    => $garages->all(),
            'fastest' => $withDur->sortBy('avg_hours')->first()['garage'] ?? null,
            'best'    => $garages->sortByDesc('quality_score')->first()['garage'] ?? null,
            'worst'   => $garages->count() > 1 ? $garages->sortBy('quality_score')->first()['garage'] : null,
        ];
    }

    /**
     * Compact per-case repair-history summary (replaces the raw timeline). One row per maintenance case with
     * its outcome flags (returned / reopened / failed-inspection / repeat-repair) derived from real data.
     */
    private function repairHistory(Collection $tickets, Collection $inspByTicket): array
    {
        return $tickets->take(60)->map(function (Maintenance $t) use ($inspByTicket) {
            $insp        = $inspByTicket->get($t->id, collect());
            $failedInsp  = $insp->contains(fn ($i) => in_array($i->result, ['still_exists', 'new_issue'], true));
            $repeatRepair = $insp->contains(fn ($i) => $i->is_recurrence) || $t->tasks->contains(fn ($x) => $x->recurrence_flagged);
            $reopened    = $t->tasks->contains(fn ($x) => (int) $x->reinspection_failures > 0);
            $mainFault   = $t->tasks->sortByDesc(fn ($x) => MaintenanceTask::SEVERITY_RANK[$x->severity] ?? 0)->first()?->symptom;
            $parts       = $t->lineItems->where('kind', 'part')->count();

            // Case type — lets the history-summary tiles filter this table (breakdown/routine/accident/other).
            $type = 'other';
            if ($t->maintenance_type === Maintenance::TYPE_BREAKDOWN || $t->trigger_reason === Maintenance::TRIGGER_BREAKDOWN) {
                $type = 'breakdown';
            } elseif ($t->maintenance_type === Maintenance::TYPE_ROUTINE || $t->trigger_reason === Maintenance::TRIGGER_PERIODIC || $t->visit_context === Maintenance::CONTEXT_ROUTINE) {
                $type = 'routine';
            } elseif (in_array($t->maintenance_type, [Maintenance::TYPE_INS_INCIDENT, Maintenance::TYPE_NON_INS_INCIDENT], true)) {
                $type = 'accident';
            }

            $closed = $t->wf_closed_at;
            return [
                'ticket_id'   => $t->id,
                'case_no'     => '#' . $t->id,
                'type'        => $type,
                'open_date'   => optional($t->created_at)->toIso8601String(),
                'close_date'  => optional($closed)->toIso8601String(),
                'duration_hours' => ($t->created_at && $closed) ? (int) round($t->created_at->diffInHours($closed)) : null,
                'garage'      => $t->vendor?->name,
                'main_fault'  => $mainFault,
                'faults'      => $t->tasks->count(),
                'parts_used'  => $parts,
                'total_cost'  => $t->cost !== null ? (float) $t->cost : null,
                'result'      => $t->workflow_status === Maintenance::WF_CLOSED ? 'Completed' : Str::title(str_replace('_', ' ', (string) $t->workflow_status)),
                'is_open'     => ! in_array($t->workflow_status, Maintenance::WF_TERMINAL, true),
                'returned'    => $t->vehicle_returned_at !== null,
                'reopened'    => $reopened,
                'failed_inspection' => $failedInsp,
                'repeat_repair' => $repeatRepair,
            ];
        })->all();
    }

    /** Display label + tone for a fault's (maintenance_task) status. */
    private const FAULT_STATUS_META = [
        MaintenanceTask::STATUS_PENDING     => ['Pending', 'slate'],
        MaintenanceTask::STATUS_IN_PROGRESS => ['In Progress', 'blue'],
        MaintenanceTask::STATUS_COMPLETED   => ['Resolved', 'emerald'],
        MaintenanceTask::STATUS_TRANSFERRED => ['Transferred', 'violet'],
        MaintenanceTask::STATUS_CANCELLED   => ['Cancelled', 'slate'],
        MaintenanceTask::STATUS_NOT_FOUND   => ['Not Found', 'slate'],
    ];

    /**
     * Fault History — the primary section. Each maintenance_task IS one fault; here it becomes a card
     * carrying its own lifecycle. Everything about a fault stays together (never scattered): its grade,
     * garage, mechanic, cost, recurrence, and a chronological lifecycle strip built from the task-scoped
     * VehicleLogEvent trail already loaded ($logByTask) — detected → assigned → transferred → parts →
     * repaired → re-inspected → closed. Nothing is re-derived; timestamps come straight off the task.
     */
    private function faultHistory(Collection $tasks, Collection $logByTask): array
    {
        return $tasks->map(function (MaintenanceTask $t) use ($logByTask) {
            $sev      = Maintenance::FAULT_SEVERITY_META[$t->severity] ?? ['label' => 'Unspecified', 'tone' => 'slate'];
            [$stLabel, $stTone] = self::FAULT_STATUS_META[$t->status] ?? [Str::title(str_replace('_', ' ', (string) $t->status)), 'slate'];

            $opened   = $t->identified_at ?? $t->created_at;
            $resolved = $t->resolved_at;
            $cost     = (float) $t->parts_cost + (float) $t->labor_cost;

            // Lifecycle strip — the fault's own audit rows, oldest-first. Each carries the human line the
            // team wrote (description) or a humanised event name, plus who/where.
            $lifecycle = ($logByTask->get($t->id) ?? collect())
                ->sortBy('occurred_at')
                ->map(function ($e) {
                    $meta = (array) ($e->meta ?? []);
                    return [
                        'event'  => $e->event_type,
                        'label'  => $e->description ?: Str::title(str_replace('_', ' ', (string) $e->event_type)),
                        'at'     => optional($e->occurred_at)->toIso8601String(),
                        'actor'  => $e->actor?->name,
                        'source' => $e->source_tag,
                        'garage' => $meta['garage'] ?? null,
                    ];
                })->values()->all();

            return [
                'id'            => $t->id,
                'ticket_id'     => $t->maintenance_id,
                'title'         => $t->symptom ?: 'Unlabelled fault',
                'category'      => $this->categoryLabel($t->category_key),
                'severity'      => $t->severity,
                'severity_label' => $sev['label'],
                'severity_tone' => $sev['tone'],
                'severity_rank' => MaintenanceTask::SEVERITY_RANK[$t->severity] ?? 0,
                'status'        => $t->status,
                'status_label'  => $stLabel,
                'status_tone'   => $stTone,
                'is_open'       => ! in_array($t->status, MaintenanceTask::TERMINAL, true),
                'garage'        => $t->currentVendor?->name,
                'mechanic'      => $t->resolvedBy?->name,
                'root_cause'    => $t->root_cause,
                'opened_at'     => optional($opened)->toIso8601String(),
                'started_at'    => optional($t->started_at)->toIso8601String(),
                'resolved_at'   => optional($resolved)->toIso8601String(),
                'duration_hours' => ($opened && $resolved) ? (int) round($opened->diffInHours($resolved)) : null,
                'cost'          => round($cost, 2),
                'parts_cost'    => round((float) $t->parts_cost, 2),
                'labor_cost'    => round((float) $t->labor_cost, 2),
                'recurrence'    => (bool) $t->recurrence_flagged,
                'reinspection_failures' => (int) $t->reinspection_failures,
                'notes'         => $t->notes,
                'resolution_note' => $t->resolution_note,
                'lifecycle'     => $lifecycle,
            ];
        })
            ->sortByDesc(fn ($f) => $f['opened_at'] ?? '')
            ->values()->all();
    }

    /**
     * Workflow Journey — the SAME VehicleLogEvent trail reshaped from a flat feed into one stage timeline
     * PER ticket: every workflow_status the car passed through, timed to the next transition (the last open
     * stage runs to now; a terminal stage carries no clock). Consecutive rows sharing a status collapse into
     * one segment; sub-events with a null workflow_status happen inside the current stage. The frontend maps
     * each status → label/tone; here we only compute the timeline + durations.
     */
    private function workflowJourney(Collection $logEvents): array
    {
        $now      = Carbon::now();
        $terminal = Maintenance::WF_TERMINAL;

        return $logEvents
            ->filter(fn ($e) => $e->maintenance_id && $e->occurred_at)
            ->groupBy('maintenance_id')
            ->map(function ($events, $ticketId) use ($now, $terminal) {
                $ordered = $events->sortBy('occurred_at')->values();

                $stages = [];
                foreach ($ordered as $e) {
                    if ($e->workflow_status === null) {
                        continue;
                    }
                    $last = empty($stages) ? null : $stages[count($stages) - 1];
                    if ($last && $last['workflow_status'] === $e->workflow_status) {
                        continue;
                    }
                    $meta = (array) ($e->meta ?? []);
                    $stages[] = [
                        'workflow_status' => $e->workflow_status,
                        'entered_at'      => $e->occurred_at->toIso8601String(),
                        '_entered'        => $e->occurred_at,
                        'actor'           => $e->actor?->name,
                        'source'          => $e->source_tag,
                        'garage'          => $meta['garage'] ?? null,
                        'description'     => $e->description,
                    ];
                }
                if (empty($stages)) {
                    return null;
                }

                $count      = count($stages);
                $lastStatus = $stages[$count - 1]['workflow_status'];
                $isOpen     = ! in_array($lastStatus, $terminal, true);
                foreach ($stages as $i => &$stage) {
                    $start = $stage['_entered'];
                    $end   = $i + 1 < $count ? $stages[$i + 1]['_entered'] : ($isOpen ? $now : null);
                    $stage['seconds'] = $end ? max(0, $start->diffInSeconds($end)) : null;
                    unset($stage['_entered']);
                }
                unset($stage);

                return [
                    'ticket_id'     => (int) $ticketId,
                    'opened_at'     => $stages[0]['entered_at'],
                    'closed_at'     => $isOpen ? null : $stages[$count - 1]['entered_at'],
                    'is_open'       => $isOpen,
                    'current_status' => $lastStatus,
                    'stage_count'   => $count,
                    'total_seconds' => collect($stages)->sum(fn ($s) => $s['seconds'] ?? 0),
                    'stages'        => $stages,
                ];
            })
            ->filter()
            ->sortByDesc('opened_at')
            ->values()->all();
    }

    /**
     * Documents — every downloadable artefact the car accumulated: photos & videos captured during
     * inspection/repair (maintenance_media, real signed files) plus the parts purchase-order records. Kept
     * as one place so a manager never hunts a photo across tickets. Reuses existing models — no new store.
     */
    private function documents(Vehicle $vehicle, Collection $tickets): array
    {
        $ticketIds = $tickets->pluck('id');

        $media = \App\Models\MaintenanceMedia::query()
            ->whereIn('maintenance_id', $ticketIds)
            ->with('task:id,symptom')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(fn ($m) => [
                'id'          => $m->id,
                'kind'        => $m->kind,
                'name'        => $m->original_name ?: ($m->kind === 'video' ? 'Video' : 'Photo'),
                'url'         => $m->viewUrl(),
                'uploaded_by' => $m->uploaded_by_name,
                'at'          => optional($m->created_at)->toIso8601String(),
                'ticket_id'   => $m->maintenance_id,
                'fault'       => $m->task?->symptom,
                'note'        => $m->note,
            ])
            ->values()->all();

        // Parts purchase orders — the buy record for each ordered part (a downloadable/auditable document).
        $purchaseOrders = PartRequest::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', [PartRequest::STATUS_PURCHASED, PartRequest::STATUS_INSTALLED, PartRequest::STATUS_COMPLETED])
            ->with(['task:id,symptom', 'purchases:id,part_request_id,source_name,purchased_at'])
            ->orderByDesc('approved_at')
            ->limit(300)
            ->get()
            ->map(fn ($r) => [
                'id'        => $r->id,
                'part'      => $r->part_name,
                'quantity'  => $r->quantity,
                'price'     => $r->estimated_price !== null ? (float) $r->estimated_price : null,
                'supplier'  => $r->purchases->first()?->source_name,
                'at'        => optional($r->purchases->first()?->purchased_at ?? $r->approved_at)->toIso8601String(),
                'ticket_id' => $r->maintenance_id,
                'fault'     => $r->task?->symptom,
                'status'    => $r->status,
            ])
            ->values()->all();

        $photos = collect($media)->where('kind', 'image')->count();
        $videos = collect($media)->where('kind', 'video')->count();

        return [
            'media'           => $media,
            'purchase_orders' => $purchaseOrders,
            'counts'          => [
                'photos'          => $photos,
                'videos'          => $videos,
                'purchase_orders' => count($purchaseOrders),
                'total'           => count($media) + count($purchaseOrders),
            ],
        ];
    }

    // ── Scores ──────────────────────────────────────────────────────────────────────────────────

    /** 0–100 health score — deducts on repair frequency, repeat failures, downtime, cost, open faults. */
    private function healthScore(Collection $tickets, Collection $tasks, Collection $inspections): array
    {
        $closed     = $tickets->where('workflow_status', Maintenance::WF_CLOSED)->count();
        $repeats    = $inspections->where('is_recurrence', true)->count();
        $openFaults = $tasks->whereNotIn('status', MaintenanceTask::TERMINAL)->count();
        $totalCost  = (float) $tickets->sum('cost');
        $downtimeDays = $this->downtimeHours($tickets) / 24;

        $factors = [
            ['key' => 'repair_frequency', 'label' => 'Repair frequency', 'penalty' => min(25, $closed * 3)],
            ['key' => 'repeat_failures',  'label' => 'Repeat failures',  'penalty' => min(25, $repeats * 8)],
            ['key' => 'downtime',         'label' => 'Downtime',         'penalty' => min(20, (int) round($downtimeDays))],
            ['key' => 'cost',             'label' => 'Maintenance cost', 'penalty' => min(20, (int) round($totalCost / 1000))],
            ['key' => 'open_faults',      'label' => 'Open faults',      'penalty' => min(10, $openFaults * 5)],
        ];
        $score = max(0, 100 - array_sum(array_column($factors, 'penalty')));

        return [
            'score'   => $score,
            'grade'   => $score >= 80 ? 'excellent' : ($score >= 60 ? 'good' : ($score >= 40 ? 'fair' : 'poor')),
            'tone'    => $score >= 80 ? 'emerald' : ($score >= 60 ? 'blue' : ($score >= 40 ? 'amber' : 'red')),
            'factors' => $factors,
        ];
    }

    /**
     * 0–100 reliability score — how dependable the car is between failures. Rewards a long mean time between
     * failures and penalises a high fault-recurrence rate (a fix that didn't hold hurts reliability most).
     */
    private function reliabilityScore(Collection $tickets, Collection $tasks, Collection $inspections): int
    {
        $cases = $tickets->count();
        if ($cases === 0) {
            return 100;
        }
        $dates = $tickets->map(fn ($t) => $t->created_at)->filter()->sort()->values();
        $mtbf  = $dates->count() >= 2 ? $dates->first()->diffInDays($dates->last()) / ($dates->count() - 1) : 365;

        $bySymptom = $tasks->filter(fn ($t) => $t->symptom)->groupBy(fn ($t) => Str::lower(trim($t->symptom)));
        $repeated  = $bySymptom->filter(fn ($g) => $g->count() >= 2)->sum(fn ($g) => $g->count());
        $recRate   = $tasks->count() ? $repeated / $tasks->count() : 0;

        // MTBF component (up to 55, 180d+ ≈ full) + a base that erodes with the recurrence rate (up to 45).
        $mtbfComponent = min(55, $mtbf / 180 * 55);
        $recComponent  = (1 - $recRate) * 45;

        return (int) round(max(0, min(100, $mtbfComponent + $recComponent)));
    }

    // ── Shared helpers ──────────────────────────────────────────────────────────────────────────────

    /** Σ (repair start → collected/closed/now) across all tickets, in hours — the lifetime downtime. */
    private function downtimeHours(Collection $tickets): float
    {
        return (float) $tickets->filter(fn ($t) => $t->repair_started_at)
            ->map(function ($t) {
                $end = $t->picked_up_from_garage_at ?? $t->wf_closed_at ?? Carbon::now();
                return $t->repair_started_at->diffInHours($end);
            })->filter(fn ($h) => $h >= 0)->sum();
    }

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
        static $labels = null;
        if ($labels === null) {
            $labels = collect(config('maintenance_findings.categories', []))
                ->mapWithKeys(fn ($c) => [$c['key'] => $c['label']])->all();
        }
        return $labels[$key] ?? Str::title(str_replace(['_', '-'], ' ', $key));
    }

    /** Count Carbon dates into the last $months calendar buckets, zero-filled, oldest→newest. */
    private function monthlyCounts(Collection $dates, int $months): array
    {
        $buckets = $this->emptyMonths($months);
        foreach ($dates as $d) {
            $key = Carbon::parse($d)->format('Y-m');
            if (isset($buckets[$key])) {
                $buckets[$key]['value']++;
            }
        }
        return array_values($buckets);
    }

    /** Count Carbon dates into the last $years calendar-year buckets, zero-filled. */
    private function yearlyCounts(Collection $dates, int $years): array
    {
        $buckets = [];
        $start   = (int) Carbon::now()->year - ($years - 1);
        for ($y = $start; $y <= (int) Carbon::now()->year; $y++) {
            $buckets[(string) $y] = ['label' => (string) $y, 'value' => 0];
        }
        foreach ($dates as $d) {
            $key = (string) Carbon::parse($d)->year;
            if (isset($buckets[$key])) {
                $buckets[$key]['value']++;
            }
        }
        return array_values($buckets);
    }

    /** Sum {at, value} rows into the last $months calendar buckets, zero-filled. */
    private function monthlySums(Collection $rows, int $months): array
    {
        $buckets = $this->emptyMonths($months);
        foreach ($rows as $row) {
            if (! $row['at']) {
                continue;
            }
            $key = Carbon::parse($row['at'])->format('Y-m');
            if (isset($buckets[$key])) {
                $buckets[$key]['value'] = round($buckets[$key]['value'] + (float) $row['value'], 2);
            }
        }
        return array_values($buckets);
    }

    private function emptyMonths(int $months): array
    {
        $buckets = [];
        $cursor  = Carbon::now()->startOfMonth()->subMonths($months - 1);
        for ($i = 0; $i < $months; $i++) {
            $buckets[$cursor->format('Y-m')] = ['label' => $cursor->format('M y'), 'value' => 0];
            $cursor->addMonth();
        }
        return $buckets;
    }
}
