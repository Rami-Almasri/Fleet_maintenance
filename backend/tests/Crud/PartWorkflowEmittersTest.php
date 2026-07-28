<?php

namespace Tests\Crud;

use App\Events\PartDelivered;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\VehicleLogEvent;
use App\Services\PartWorkflowService;
use App\Services\State\RepairState;
use App\Services\State\WorkflowStateResolver;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 1, Step 6 — emitters fire AFTER real transitions and the new markDelivered write-point works
 * (blueprint §3d, Scenarios 1/2). Rich timeline writes are PRESERVED (Option B); the events additionally
 * drive the derived state / cache. A delivered part unblocks the repair in the resolver (Scenario 2).
 */
class PartWorkflowEmittersTest extends CrudTestCase
{
    private function service(): PartWorkflowService
    {
        return app(PartWorkflowService::class);
    }

    private function blockedGraph(): array
    {
        $vehicleId = $this->makeVehicle();
        $ticket = Maintenance::create([
            'vehicle_id' => $vehicleId, 'workflow_status' => Maintenance::WF_UNDER_REPAIR, 'event_status' => 'OUT',
        ]);
        $task = MaintenanceTask::create([
            'maintenance_id' => $ticket->id, 'vehicle_id' => $vehicleId,
            'symptom' => 'Grinding', 'status' => MaintenanceTask::STATUS_IN_PROGRESS,
        ]);
        $request = PartRequest::create([
            'maintenance_id' => $ticket->id, 'maintenance_task_id' => $task->id, 'vehicle_id' => $vehicleId,
            'source' => PartRequest::SOURCE_GARAGE, 'status' => PartRequest::STATUS_PURCHASED,
            'part_name' => 'Brake Pads', 'category_key' => 'brakes', 'quantity' => 1, 'reason' => 'Worn',
        ]);
        $purchase = PartPurchase::create([
            'part_request_id' => $request->id, 'vehicle_id' => $vehicleId, 'part_name' => 'Brake Pads',
            'category_key' => 'brakes', 'purchase_source' => PartPurchase::SOURCE_SUPPLIER, 'purchase_price' => 300,
            'currency' => 'AED', 'quantity' => 1, 'purchased_at' => now(),
            'expected_delivery_date' => now()->addDays(2)->toDateString(),
        ]);

        return compact('ticket', 'purchase');
    }

    private function resolve(int $ticketId): RepairState
    {
        $fresh = Maintenance::with(['tasks.partRequests.purchases'])->find($ticketId);

        return app(WorkflowStateResolver::class)->resolve($fresh);
    }

    public function test_mark_delivered_stamps_logs_and_emits(): void
    {
        Event::fake([PartDelivered::class]);
        ['purchase' => $purchase] = $this->blockedGraph();

        $result = $this->service()->markDelivered($purchase, $this->admin);

        $this->assertNotNull($result->delivered_at);
        Event::assertDispatched(PartDelivered::class, fn (PartDelivered $e) => $e->partPurchaseId === $purchase->id);
        $this->assertDatabaseHas('vehicle_log_events', [
            'vehicle_id' => $purchase->vehicle_id,
            'event_type' => VehicleLogEvent::EVENT_PART_DELIVERED,
            'source_tag' => 'parts',
        ]);
    }

    public function test_mark_delivered_twice_is_rejected(): void
    {
        ['purchase' => $purchase] = $this->blockedGraph();
        $this->service()->markDelivered($purchase, $this->admin);

        $this->expectException(HttpException::class);
        $this->service()->markDelivered($purchase->fresh(), $this->admin);
    }

    public function test_mark_delivered_unblocks_the_repair(): void
    {
        ['ticket' => $ticket, 'purchase' => $purchase] = $this->blockedGraph();

        $this->assertSame(RepairState::BLOCKED_WAITING_PARTS, $this->resolve($ticket->id)->state);

        $this->service()->markDelivered($purchase, $this->admin);

        $this->assertSame(RepairState::ACTIVE_REPAIR, $this->resolve($ticket->id)->state);
    }

    public function test_purchase_preserves_rich_timeline_without_emitting(): void
    {
        // Per the agreed Step-6 scope, purchase is NOT one of the four emitters; its rich timeline write
        // (Option B) must remain intact regardless.
        $vehicleId = $this->makeVehicle();
        $ticket = Maintenance::create([
            'vehicle_id' => $vehicleId, 'workflow_status' => Maintenance::WF_UNDER_REPAIR, 'event_status' => 'OUT',
        ]);
        $request = PartRequest::create([
            'maintenance_id' => $ticket->id, 'vehicle_id' => $vehicleId, 'source' => PartRequest::SOURCE_GARAGE,
            'status' => PartRequest::STATUS_APPROVED, 'part_name' => 'Alternator', 'category_key' => 'electrical',
            'quantity' => 1, 'reason' => 'Dead',
        ]);

        $this->service()->purchase($request, ['purchase_source' => 'supplier', 'purchase_price' => 500], $this->admin);

        $this->assertDatabaseHas('vehicle_log_events', [
            'vehicle_id' => $vehicleId,
            'event_type' => VehicleLogEvent::EVENT_PART_PURCHASED,
            'source_tag' => 'parts',
        ]);
    }
}
