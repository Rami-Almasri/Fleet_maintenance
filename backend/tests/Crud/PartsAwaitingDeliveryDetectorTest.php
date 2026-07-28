<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Services\NotificationScanner;
use Illuminate\Support\Collection;

/**
 * Phase 1, Step 7 — the delivery-overdue detector (Option A). It does NOT own the delay rule: it
 * CONSUMES MaintenanceDelayResolver over the open-ticket graph and only decides "derived parts delay
 * whose promised ETA has passed → notify". Idempotent + auto-resolving via the existing scan infra.
 * Pure orchestration — no new writer, no duplicated derivation, no PartRequest creation.
 */
class PartsAwaitingDeliveryDetectorTest extends CrudTestCase
{
    /** A ticket with one active fault blocked on an undelivered part (overdue by default). */
    private function ticketWithBlockingPart(array $purchaseOverrides = []): Maintenance
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
        PartPurchase::create(array_merge([
            'part_request_id' => $request->id, 'vehicle_id' => $vehicleId, 'part_name' => 'Brake Pads',
            'category_key' => 'brakes', 'purchase_source' => PartPurchase::SOURCE_SUPPLIER, 'purchase_price' => 300,
            'currency' => 'AED', 'quantity' => 1, 'purchased_at' => now()->subDays(10),
            'expected_delivery_date' => now()->subDays(3)->toDateString(), 'source_name' => 'ABC Parts',
        ], $purchaseOverrides));

        return $ticket;
    }

    private function detect(): Collection
    {
        return collect(app(NotificationScanner::class)->detect());
    }

    public function test_overdue_parts_delay_is_detected_from_the_resolver(): void
    {
        $ticket = $this->ticketWithBlockingPart();

        $hit = $this->detect()->firstWhere('key', 'part_delivery_overdue:' . $ticket->id);

        $this->assertNotNull($hit);
        $this->assertSame('part_delivery_overdue', $hit['type']);
        $this->assertSame(3, $hit['meta']['days_overdue']);
        $this->assertSame('ABC Parts', $hit['meta']['supplier']); // sourced from the resolver's DTO
    }

    public function test_delivered_part_auto_resolves_out(): void
    {
        $ticket = $this->ticketWithBlockingPart(['delivered_at' => now()]);

        $this->assertNull($this->detect()->firstWhere('key', 'part_delivery_overdue:' . $ticket->id));
    }

    public function test_eta_not_passed_is_not_detected(): void
    {
        $ticket = $this->ticketWithBlockingPart(['expected_delivery_date' => now()->addDays(5)->toDateString()]);

        $this->assertNull($this->detect()->firstWhere('key', 'part_delivery_overdue:' . $ticket->id));
    }

    public function test_scan_is_idempotent_for_the_same_condition(): void
    {
        $ticket = $this->ticketWithBlockingPart();
        $scanner = app(NotificationScanner::class);

        $scanner->scan();
        $scanner->scan();

        $held = $this->admin->notifications()
            ->where('data->key', 'part_delivery_overdue:' . $ticket->id)
            ->count();

        $this->assertSame(1, $held);
    }
}
