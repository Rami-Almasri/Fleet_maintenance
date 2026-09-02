<?php

namespace App\Http\Controllers;

use App\Exceptions\WorkflowTransitionException;
use App\Helpers\ResponseHelper;
use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Services\MaintenanceWorkflowService;
use App\Services\OperationsService;
use App\Services\VehicleLogService;
use App\Services\VehicleReadinessService;
use App\Services\VehicleStatusDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Vehicle Status Dashboard — the team's all-day follow-up screen. One derived row per vehicle:
 * live status, current owner, last & next action, days-in-status and whether it's blocked. Read-only
 * aggregation over the app's existing sources (see VehicleStatusDashboardService), plus one action:
 * a Supervisor's "Set to Ready" that returns a repaired car to the Available pool.
 */
class VehicleStatusController extends Controller
{
    public function __construct(
        private VehicleStatusDashboardService $dashboard,
        private MaintenanceWorkflowService $workflow,
        private OperationsService $ops,
        private VehicleReadinessService $readiness,
        private VehicleLogService $log,
        private \App\Services\MaintenanceTaskService $tasks,
    ) {
    }

    /** The full board: one row per vehicle + the KPI counts. */
    public function index()
    {
        try {
            return ResponseHelper::SuccessResponse($this->dashboard->build(), 'Vehicle status board retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The period overview — every maintenance journey with activity in the selected window (default: this
     * month). Feeds the board's "Last month / this month" History mode: one summary row per journey.
     */
    public function history(Request $request)
    {
        try {
            [$from, $to] = $this->periodWindow($request);
            return ResponseHelper::SuccessResponse($this->dashboard->history($from, $to), 'Maintenance history retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * One car's full stage-by-stage maintenance history: each journey broken into stages, with who was
     * responsible and how long each stage took. Powers the drill-down drawer. Honours the same window.
     */
    public function timeline(Request $request, Vehicle $vehicle)
    {
        try {
            [$from, $to] = $this->periodWindow($request);
            return ResponseHelper::SuccessResponse($this->dashboard->vehicleTimeline($vehicle, $from, $to), 'Vehicle maintenance timeline retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Resolve the requested reporting window into [from, to] Carbon bounds (or nulls for "all time").
     * Accepts a named `period` (this_month | last_month | last_3_months | all) or an explicit from/to
     * date pair, so the frontend can offer quick presets and a custom range from the same endpoint.
     */
    private function periodWindow(Request $request): array
    {
        if ($request->filled('from') || $request->filled('to')) {
            $from = $request->filled('from') ? \Illuminate\Support\Carbon::parse($request->query('from'))->startOfDay() : null;
            $to   = $request->filled('to') ? \Illuminate\Support\Carbon::parse($request->query('to'))->endOfDay() : null;
            return [$from, $to];
        }

        $now = \Illuminate\Support\Carbon::now();
        return match ($request->query('period', 'this_month')) {
            'all'           => [null, null],
            'last_month'    => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'last_3_months' => [$now->copy()->subMonthsNoOverflow(3)->startOfDay(), $now->copy()->endOfDay()],
            default         => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()], // this_month
        };
    }

    /**
     * "Set to Ready" — a Supervisor signs a repaired car back into service (Maintenance → Available).
     *
     * It only ever does what is safe and honest, so it can never corrupt the workflow state machine
     * nor bypass a quality gate:
     *   1. A formal ticket already at the re-inspection stage is signed off through the workflow engine
     *      (the only state from which a close is a legal transition). A ticket that is still mid-repair
     *      is refused with a clear message pointing to the Workflow board — we never force an illegal jump.
     *   2. Any open type-U maintenance contract is closed (the car came back).
     *   3. Any hand-entered garage event still open is marked returned ('IN').
     *   4. The car's operational_status is re-derived so every surface agrees.
     */
    public function setReady(Request $request, Vehicle $vehicle)
    {
        $actor = $request->user();

        // The car's live open ticket, if it's in the formal workflow.
        $ticket = Maintenance::openWorkflow()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('workflow_status', Maintenance::WF_TICKET_STATES)
            ->orderByDesc('id')
            ->first();

        // WORKFLOW-INTEGRITY GUARD (NOT a readiness block): a mid-repair ticket must be finished on the
        // Workflow board — only re-inspection is a legal predecessor of a close. This protects the
        // maintenance state machine itself (see directive: the state machine and the rental guard stay
        // independent); it is deliberately kept even though the readiness checklist is now advisory.
        if ($ticket && $ticket->workflow_status !== Maintenance::WF_READY_REINSPECTION) {
            return ResponseHelper::FailureResponse(
                ['workflow_status' => $ticket->workflow_status, 'ticket_id' => $ticket->id],
                'This car is still mid-repair in the workflow — finish it through re-inspection on the Workflow board before setting it Ready.',
                422,
            );
        }

        // ── Readiness checklist: ADVISORY ONLY (soft side) ─────────────────────────────────────────
        // The Pre-Delivery checklist NO LONGER blocks the sign-off. We evaluate it purely to (a) tell
        // the user what is still open and (b) audit it: if the car has any open advisory (dirty, overdue
        // service, expired docs, a Red/Yellow grade, unreviewed damage …) and the user proceeds anyway,
        // that is a "Warning Override" and is logged with the reason. It never stops the action.
        $eval      = $this->readiness->evaluate($vehicle);
        $advisories = array_values(array_filter(
            $eval['checks'],
            // Everything the user is knowingly overriding — excluding the open maintenance this very
            // action resolves (resolved_by_signoff), which is not a warning to override.
            fn ($c) => in_array($c['status'], ['warn', 'fail'], true) && ! $c['resolved_by_signoff'],
        ));
        $overridden = ! empty($advisories);
        $reason     = trim((string) $request->input('override_reason', '')) ?: null;

        try {
            DB::transaction(function () use ($ticket, $vehicle, $actor) {
                // 1) Sign the re-inspection ticket off through the engine (marks it IN + cascades the
                //    car free), after completing any still-open faults.
                if ($ticket) {
                    // Route through the task service, NOT a raw update. A bare status write leaves the
                    // fault's garage stint open forever (no released_at, no outcome) and leaves its work
                    // clock running, so the fault would keep accruing time after the car was back in
                    // service — the timeline equivalent of never clocking out.
                    foreach ($ticket->tasks()->whereNotIn('status', \App\Models\MaintenanceTask::TERMINAL)->get() as $task) {
                        $this->tasks->setStatus($task, \App\Models\MaintenanceTask::STATUS_COMPLETED, $actor);
                    }
                    $this->workflow->close($ticket, [], $actor);
                }

                // 2) Close any open type-U maintenance contract — the car is back.
                Contract::where('contract_type', 'U')->currentlyOpen()
                    ->where('vehicle_id', $vehicle->id)
                    ->get()
                    ->each(fn (Contract $c) => $this->ops->closeOperation($c));

                // 3) Mark any still-open hand-entered garage event as returned.
                Maintenance::query()
                    ->where('origin', Maintenance::ORIGIN_MANUAL)
                    ->where('vehicle_id', $vehicle->id)
                    ->where('event_status', '<>', 'IN')
                    ->update(['event_status' => 'IN', 'actual_in_date' => now()->toDateString()]);
            });
        } catch (WorkflowTransitionException $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }

        // 4) Re-derive the live status so the board, dashboard and grid all agree.
        $status = $this->ops->reconcileVehicleOperationalStatus($vehicle->fresh());

        // 5) Audit — WHO signed the car ready, WHEN, and the checklist state at the time. Best-effort:
        //    the log never blocks the sign-off (see VehicleLogService). When the car had open advisories
        //    and the user proceeded anyway, this is a WARNING OVERRIDE — logged as its own event type
        //    with the SPECIFIC reason and the exact advisories that were overridden.
        $this->log->recordVehicle(
            $vehicle,
            $overridden ? VehicleLogEvent::EVENT_READINESS_OVERRIDE : VehicleLogEvent::EVENT_READINESS_CONFIRMED,
            $actor,
            [
                'description' => $overridden
                    ? 'Set to Ready — warning override (' . count($advisories) . ' advisory' . (count($advisories) === 1 ? '' : ' items') . ' overridden)'
                    : 'Set to Ready — no open advisories',
                'meta' => [
                    'source'          => 'set_ready',
                    'ticket_id'       => $ticket?->id,
                    'overridden'      => $overridden,
                    'override_reason' => $reason,
                    'pillars'         => array_map(
                        fn ($c) => ['pillar' => $c['pillar'], 'label' => $c['label'], 'status' => $c['status']],
                        $eval['checks'],
                    ),
                    // The specific advisories the user proceeded past (empty on a clean sign-off).
                    'overrides' => array_map(
                        fn ($c) => ['pillar' => $c['pillar'], 'label' => $c['label'], 'status' => $c['status'], 'detail' => $c['detail']],
                        $advisories,
                    ),
                ],
            ],
        );

        return ResponseHelper::SuccessResponse(
            [
                'id'                 => $vehicle->id,
                'operational_status' => $status,
                'overridden'         => $overridden,
                'advisories'         => $advisories,
            ],
            $overridden
                ? 'Vehicle set to Ready — proceeded past ' . count($advisories) . ' open advisory' . (count($advisories) === 1 ? '' : ' items') . ' (logged)'
                : 'Vehicle set to Ready — back in the Available pool',
            200,
        );
    }
}
