<?php

namespace Tests\Unit;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Services\State\RepairState;
use App\Services\State\WorkflowStateResolver;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 1, Step 2 validation (blueprint A2/§4; advances Scenarios 1,2,3; upholds Invariant 2 — one
 * derived interpretation). Pure derivation: models are built IN MEMORY (setRelation, no DB), proving
 * the resolver is a deterministic function of loaded data with no writes/events/queries.
 */
class WorkflowStateResolverTest extends TestCase
{
    private WorkflowStateResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new WorkflowStateResolver();
    }

    // ── in-memory builders ────────────────────────────────────────────────────────────────────────

    private function purchase(int $id, ?string $deliveredAt = null, ?string $installedAt = null): PartPurchase
    {
        return (new PartPurchase())->forceFill([
            'id'           => $id,
            'delivered_at' => $deliveredAt,
            'installed_at' => $installedAt,
        ]);
    }

    /** @param PartPurchase[] $purchases */
    private function request(int $id, string $status, array $purchases = []): PartRequest
    {
        $request = (new PartRequest())->forceFill(['id' => $id, 'status' => $status]);
        $request->setRelation('purchases', collect($purchases));

        return $request;
    }

    /** @param PartRequest[] $requests */
    private function fault(int $id, string $status, array $requests = []): MaintenanceTask
    {
        $fault = (new MaintenanceTask())->forceFill(['id' => $id, 'status' => $status]);
        $fault->setRelation('partRequests', collect($requests));

        return $fault;
    }

    /** @param MaintenanceTask[] $faults */
    private function ticket(string $lane, array $faults = []): Maintenance
    {
        $ticket = (new Maintenance())->forceFill(['id' => 1, 'workflow_status' => $lane]);
        $ticket->setRelation('tasks', collect($faults));

        return $ticket;
    }

    // ── cases ─────────────────────────────────────────────────────────────────────────────────────

    public function test_blocked_when_every_active_fault_waits_on_an_unfulfilled_part(): void
    {
        $ticket = $this->ticket(Maintenance::WF_UNDER_REPAIR, [
            $this->fault(10, MaintenanceTask::STATUS_IN_PROGRESS, [
                $this->request(100, PartRequest::STATUS_APPROVED), // approved, no purchase yet → gating
            ]),
        ]);

        $state = $this->resolver->resolve($ticket);

        $this->assertSame(RepairState::BLOCKED_WAITING_PARTS, $state->state);
        $this->assertTrue($state->isBlocked());
        $this->assertSame([100], $state->blockingPartRequestIds);
        $this->assertSame([10], $state->activeFaultIds);
    }

    public function test_active_when_part_delivered_but_not_yet_installed(): void
    {
        // Scenario 2: the wait was for DELIVERY; once on-site the fault is no longer blocked.
        $ticket = $this->ticket(Maintenance::WF_UNDER_REPAIR, [
            $this->fault(10, MaintenanceTask::STATUS_IN_PROGRESS, [
                $this->request(100, PartRequest::STATUS_PURCHASED, [
                    $this->purchase(1000, deliveredAt: '2026-08-02 09:00:00', installedAt: null),
                ]),
            ]),
        ]);

        $state = $this->resolver->resolve($ticket);

        $this->assertSame(RepairState::ACTIVE_REPAIR, $state->state);
        $this->assertFalse($state->isWaitingForParts());
        $this->assertSame([], $state->blockingPartRequestIds);
    }

    public function test_partially_blocked_when_some_faults_are_blocked(): void
    {
        $ticket = $this->ticket(Maintenance::WF_UNDER_REPAIR, [
            $this->fault(10, MaintenanceTask::STATUS_IN_PROGRESS, [
                $this->request(100, PartRequest::STATUS_APPROVED), // blocked
            ]),
            $this->fault(20, MaintenanceTask::STATUS_IN_PROGRESS, []), // no parts → proceeding
        ]);

        $state = $this->resolver->resolve($ticket);

        $this->assertSame(RepairState::PARTIALLY_BLOCKED, $state->state);
        $this->assertTrue($state->isPartiallyBlocked());
        $this->assertSame([100], $state->blockingPartRequestIds);
        $this->assertEqualsCanonicalizing([10, 20], $state->activeFaultIds);
    }

    public function test_active_when_no_parts_are_involved(): void
    {
        $ticket = $this->ticket(Maintenance::WF_UNDER_REPAIR, [
            $this->fault(10, MaintenanceTask::STATUS_PENDING, []),
        ]);

        $state = $this->resolver->resolve($ticket);

        $this->assertSame(RepairState::ACTIVE_REPAIR, $state->state);
        $this->assertTrue($state->inRepair());
        $this->assertFalse($state->isWaitingForParts());
    }

    public function test_not_in_repair_when_lane_is_not_a_repair_lane(): void
    {
        // A closed ticket with an un-fulfilled part must NOT report a block — the lane gates it.
        $ticket = $this->ticket(Maintenance::WF_CLOSED, [
            $this->fault(10, MaintenanceTask::STATUS_IN_PROGRESS, [
                $this->request(100, PartRequest::STATUS_APPROVED),
            ]),
        ]);

        $state = $this->resolver->resolve($ticket);

        $this->assertSame(RepairState::NOT_IN_REPAIR, $state->state);
        $this->assertFalse($state->inRepair());
    }

    public function test_installed_or_dropped_part_never_blocks(): void
    {
        $ticket = $this->ticket(Maintenance::WF_UNDER_REPAIR, [
            $this->fault(10, MaintenanceTask::STATUS_IN_PROGRESS, [
                $this->request(100, PartRequest::STATUS_INSTALLED),
                $this->request(101, PartRequest::STATUS_CANCELLED),
            ]),
        ]);

        $state = $this->resolver->resolve($ticket);

        $this->assertSame(RepairState::ACTIVE_REPAIR, $state->state);
        $this->assertSame([], $state->blockingPartRequestIds);
    }

    public function test_active_when_faults_exist_but_none_are_active(): void
    {
        $ticket = $this->ticket(Maintenance::WF_UNDER_REPAIR, [
            $this->fault(10, MaintenanceTask::STATUS_COMPLETED, [
                $this->request(100, PartRequest::STATUS_APPROVED), // dangling, but its fault is done
            ]),
        ]);

        $state = $this->resolver->resolve($ticket);

        $this->assertSame(RepairState::ACTIVE_REPAIR, $state->state);
        $this->assertSame([], $state->activeFaultIds);
    }
}
