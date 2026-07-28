<?php

namespace Tests\Unit;

use App\Models\Maintenance;
use App\Models\MaintenanceCheckpoint;
use App\Models\MaintenanceTask;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Services\State\MaintenanceDelay;
use App\Services\State\MaintenanceDelayResolver;
use App\Services\State\WorkflowStateResolver;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 1, Step 3 validation (blueprint §5; advances Scenarios 2, 9; upholds Invariant 1 — the delay
 * reason is inferred, never stored). Pure derivation over in-memory models (setRelation, no DB); the
 * clock is frozen so daysWaiting is deterministic.
 */
class MaintenanceDelayResolverTest extends TestCase
{
    private MaintenanceDelayResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new MaintenanceDelayResolver(new WorkflowStateResolver());
        Carbon::setTestNow('2026-08-06 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── in-memory builders ────────────────────────────────────────────────────────────────────────

    private function purchase(int $id, array $attrs = []): PartPurchase
    {
        return (new PartPurchase())->forceFill(array_merge(['id' => $id], $attrs));
    }

    private function request(int $id, string $status, array $attrs = [], array $purchases = []): PartRequest
    {
        $request = (new PartRequest())->forceFill(array_merge(['id' => $id, 'status' => $status], $attrs));
        $request->setRelation('purchases', collect($purchases));

        return $request;
    }

    private function fault(int $id, string $status, array $requests = []): MaintenanceTask
    {
        $fault = (new MaintenanceTask())->forceFill(['id' => $id, 'status' => $status]);
        $fault->setRelation('partRequests', collect($requests));

        return $fault;
    }

    private function checkpoint(array $attrs): MaintenanceCheckpoint
    {
        return (new MaintenanceCheckpoint())->forceFill($attrs);
    }

    private function ticket(string $lane, array $faults = [], array $checkpoints = [], array $attrs = []): Maintenance
    {
        $ticket = (new Maintenance())->forceFill(array_merge(['id' => 1, 'workflow_status' => $lane], $attrs));
        $ticket->setRelation('tasks', collect($faults));
        $ticket->setRelation('checkpoints', collect($checkpoints));

        return $ticket;
    }

    // ── cases ─────────────────────────────────────────────────────────────────────────────────────

    public function test_parts_delay_with_purchase_awaiting_delivery(): void
    {
        $ticket = $this->ticket(Maintenance::WF_UNDER_REPAIR, [
            $this->fault(10, MaintenanceTask::STATUS_IN_PROGRESS, [
                $this->request(100, PartRequest::STATUS_PURCHASED, [], [
                    $this->purchase(1000, [
                        'purchased_at'           => '2026-08-01 10:00:00', // 5 days ago
                        'expected_delivery_date' => '2026-08-08',
                        'delivered_at'           => null,
                        'source_name'            => 'ABC Parts',
                    ]),
                ]),
            ]),
        ], [
            $this->checkpoint(['status' => 'waiting_parts']),
        ], [
            'part_name' => 'Brake Pads',
        ]);
        // part_name lives on the request, set it there:
        $ticket->tasks->first()->partRequests->first()->part_name = 'Brake Pads';

        $delay = $this->resolver->resolve($ticket);

        $this->assertTrue($delay->isDelayed);
        $this->assertSame(MaintenanceDelay::REASON_WAITING_FOR_PARTS, $delay->delayReason);
        $this->assertSame(MaintenanceDelay::SOURCE_DERIVED_PARTS, $delay->delaySource);
        $this->assertSame(5, $delay->daysWaiting);
        $this->assertSame('2026-08-08', $delay->expectedResolutionDate);
        $this->assertSame('ABC Parts', $delay->supplierName);
        $this->assertSame('waiting_parts', $delay->checkpointStatus);
        $this->assertSame('Waiting for Brake Pads', $delay->headline);
    }

    public function test_parts_delay_before_any_purchase_uses_approved_anchor_and_null_supplier(): void
    {
        $req = $this->request(100, PartRequest::STATUS_APPROVED, ['approved_at' => '2026-08-04 10:00:00']);
        $req->part_name = 'Alternator';

        $ticket = $this->ticket(Maintenance::WF_UNDER_REPAIR, [
            $this->fault(10, MaintenanceTask::STATUS_IN_PROGRESS, [$req]),
        ]);

        $delay = $this->resolver->resolve($ticket);

        $this->assertTrue($delay->isDelayed);
        $this->assertSame(MaintenanceDelay::SOURCE_DERIVED_PARTS, $delay->delaySource);
        $this->assertSame(2, $delay->daysWaiting);            // 08-04 → 08-06
        $this->assertNull($delay->expectedResolutionDate);    // not ordered yet → no ETA
        $this->assertNull($delay->supplierName);
        $this->assertSame('Waiting for Alternator', $delay->headline);
    }

    public function test_multiple_blocking_parts_headline_and_latest_eta(): void
    {
        $a = $this->request(100, PartRequest::STATUS_PURCHASED, [], [
            $this->purchase(1000, ['expected_delivery_date' => '2026-08-08', 'purchased_at' => '2026-08-01 10:00:00']),
        ]);
        $a->part_name = 'Radiator';
        $b = $this->request(101, PartRequest::STATUS_PURCHASED, [], [
            $this->purchase(1001, ['expected_delivery_date' => '2026-08-11', 'purchased_at' => '2026-08-02 10:00:00']),
        ]);
        $b->part_name = 'Hose';

        $ticket = $this->ticket(Maintenance::WF_UNDER_REPAIR, [
            $this->fault(10, MaintenanceTask::STATUS_IN_PROGRESS, [$a, $b]),
        ]);

        $delay = $this->resolver->resolve($ticket);

        $this->assertSame('Waiting for Radiator (+1 more)', $delay->headline);
        $this->assertSame('2026-08-11', $delay->expectedResolutionDate); // latest of the two ETAs
    }

    public function test_delivered_part_is_not_a_parts_delay(): void
    {
        $ticket = $this->ticket(Maintenance::WF_UNDER_REPAIR, [
            $this->fault(10, MaintenanceTask::STATUS_IN_PROGRESS, [
                $this->request(100, PartRequest::STATUS_PURCHASED, [], [
                    $this->purchase(1000, ['delivered_at' => '2026-08-05 09:00:00']),
                ]),
            ]),
        ], [
            $this->checkpoint(['status' => 'under_repair']),
        ]);

        $delay = $this->resolver->resolve($ticket);

        $this->assertFalse($delay->isDelayed);
        $this->assertNull($delay->delayReason);
        $this->assertSame('under_repair', $delay->checkpointStatus);
    }

    public function test_manual_checkpoint_fallback_when_overdue_and_not_parts_blocked(): void
    {
        // Not parts-blocked, but the promised completion date (2026-08-04) is now in the past → delayed,
        // with the reason taken from the latest update's recorded ETA-change reason.
        $ticket = $this->ticket(Maintenance::WF_UNDER_REPAIR, [
            $this->fault(10, MaintenanceTask::STATUS_IN_PROGRESS, []), // no parts
        ], [
            $this->checkpoint([
                'status'       => 'workshop_busy',
                'delay_reason' => 'workshop_busy',
            ]),
        ], [
            'expected_completion_date' => '2026-08-04',
        ]);

        $delay = $this->resolver->resolve($ticket);

        $this->assertTrue($delay->isDelayed);
        $this->assertSame('workshop_busy', $delay->delayReason);
        $this->assertSame(MaintenanceDelay::SOURCE_CHECKPOINT_MANUAL, $delay->delaySource);
        $this->assertSame('2026-08-04', $delay->expectedResolutionDate);
        $this->assertSame('workshop_busy', $delay->checkpointStatus);
    }

    public function test_not_delayed_when_not_parts_blocked_and_on_schedule(): void
    {
        // Promised date (2026-08-09) is still in the future → on schedule, not delayed.
        $ticket = $this->ticket(Maintenance::WF_UNDER_REPAIR, [
            $this->fault(10, MaintenanceTask::STATUS_IN_PROGRESS, []),
        ], [
            $this->checkpoint(['status' => 'testing']),
        ], [
            'expected_completion_date' => '2026-08-09',
        ]);

        $delay = $this->resolver->resolve($ticket);

        $this->assertFalse($delay->isDelayed);
        $this->assertNull($delay->delaySource);
        $this->assertSame('testing', $delay->checkpointStatus);
    }

    public function test_not_delayed_with_no_checkpoint_and_no_parts(): void
    {
        $ticket = $this->ticket(Maintenance::WF_UNDER_REPAIR, [
            $this->fault(10, MaintenanceTask::STATUS_PENDING, []),
        ]);

        $delay = $this->resolver->resolve($ticket);

        $this->assertFalse($delay->isDelayed);
        $this->assertNull($delay->checkpointStatus);
        $this->assertNull($delay->headline);
    }
}
