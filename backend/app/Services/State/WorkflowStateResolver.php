<?php

namespace App\Services\State;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\PartRequest;

/**
 * Derives the service-axis (repair) condition of a maintenance ticket from the three persisted truths
 * (blueprint A1): the ticket LANE (`workflow_status`), the FAULT states (`maintenance_tasks.status`),
 * and the PROCUREMENT state (`part_requests` + `part_purchases` timestamps). It is the single owner of
 * the "is this repair blocked on parts?" question (blueprint A6, Invariant 2).
 *
 * PURE FUNCTION: input a Maintenance, output a {@see RepairState}. It performs no writes, emits no
 * events, logs nothing, calls no external service, and never touches `vehicles.operational_status`
 * (P1-D1 / A′). It only READS the ticket's already-loaded relations — callers that resolve many
 * tickets should eager-load `tasks.partRequests.purchases` first to avoid N+1 (done by CarStatusService
 * in Step 4); the resolver itself does not eager-load, so it stays a pure reader of what it is given.
 *
 * Scope (Phase 1, Step 2): the block determination only. The full lane→axis mapping (in_transit /
 * awaiting_return / healthy) and the availability axis are combined by CarStatusService (Step 4).
 * Advances Scenarios 1, 2, 3.
 */
final class WorkflowStateResolver
{
    /**
     * Lanes where the car is actively being worked, so a parts wait can genuinely block the repair.
     * Conservative on purpose: derived, and trivially extendable if operations later treat another lane
     * as "work in progress". Referenced from the Maintenance lane constants — never hard-coded strings.
     */
    public const IN_REPAIR_LANES = [
        Maintenance::WF_UNDER_REPAIR,
        Maintenance::WF_ON_SITE_PENDING,
    ];

    /** Fault statuses that mean the fault still needs work (so it can be blocked). */
    private const ACTIVE_FAULT_STATUSES = [
        MaintenanceTask::STATUS_PENDING,
        MaintenanceTask::STATUS_IN_PROGRESS,
    ];

    public function resolve(Maintenance $ticket): RepairState
    {
        if (! in_array($ticket->workflow_status, self::IN_REPAIR_LANES, true)) {
            return new RepairState(RepairState::NOT_IN_REPAIR);
        }

        $activeFaults = $ticket->tasks->whereIn('status', self::ACTIVE_FAULT_STATUSES);

        if ($activeFaults->isEmpty()) {
            // In a repair lane but nothing active to block — treat as active repair.
            return new RepairState(RepairState::ACTIVE_REPAIR);
        }

        $activeFaultIds  = array_values($activeFaults->pluck('id')->all());
        $blockingPartIds = [];
        $blockedFaults   = 0;

        foreach ($activeFaults as $fault) {
            $partIds = $this->blockingPartRequestIds($fault);
            if ($partIds !== []) {
                $blockedFaults++;
                $blockingPartIds = array_merge($blockingPartIds, $partIds);
            }
        }

        if ($blockedFaults === 0) {
            return new RepairState(RepairState::ACTIVE_REPAIR, $activeFaultIds);
        }

        $state = $blockedFaults === $activeFaults->count()
            ? RepairState::BLOCKED_WAITING_PARTS
            : RepairState::PARTIALLY_BLOCKED;

        return new RepairState($state, $activeFaultIds, array_values(array_unique($blockingPartIds)));
    }

    /**
     * part_request ids on this fault that are gating the repair: not yet installed/dropped, AND not yet
     * physically on-site. A part whose purchase is delivered (or installed) no longer blocks — the wait
     * was for DELIVERY, not for the fitting (blueprint Scenario 2).
     *
     * @return int[]
     */
    private function blockingPartRequestIds(MaintenanceTask $fault): array
    {
        return array_values(
            $fault->partRequests
                ->filter(fn (PartRequest $request) => $request->isOutstanding())
                ->pluck('id')
                ->all()
        );
    }
}
