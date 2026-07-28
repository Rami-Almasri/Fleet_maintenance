<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\PartRfq;
use App\Models\SupplierQuote;
use App\Models\Vendor;
use App\Services\ProcurementService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 2, Step P2-2 — ProcurementService owns the RFQ → Quotes half only. It writes ONLY the new
 * procurement tables: it creates no purchase order, never touches part_purchases, and never changes a
 * part_request's status or the maintenance workflow.
 */
class ProcurementRfqQuotesTest extends CrudTestCase
{
    private function service(): ProcurementService
    {
        return app(ProcurementService::class);
    }

    private function approvedRequest(array $overrides = []): PartRequest
    {
        $vehicleId = $this->makeVehicle();
        $ticket = Maintenance::create([
            'vehicle_id' => $vehicleId, 'workflow_status' => Maintenance::WF_UNDER_REPAIR, 'event_status' => 'OUT',
        ]);
        $task = MaintenanceTask::create([
            'maintenance_id' => $ticket->id, 'vehicle_id' => $vehicleId,
            'symptom' => 'Grinding', 'status' => MaintenanceTask::STATUS_IN_PROGRESS,
        ]);

        return PartRequest::create(array_merge([
            'maintenance_id' => $ticket->id, 'maintenance_task_id' => $task->id, 'vehicle_id' => $vehicleId,
            'source' => PartRequest::SOURCE_GARAGE, 'status' => PartRequest::STATUS_APPROVED,
            'part_name' => 'Brake Pads', 'category_key' => 'brakes', 'quantity' => 2, 'reason' => 'Worn',
        ], $overrides));
    }

    public function test_open_rfq_creates_header_and_one_line_per_request(): void
    {
        $a = $this->approvedRequest();
        $b = $this->approvedRequest();

        $rfq = $this->service()->openRfq([$a->id, $b->id], $this->admin, ['needed_by_date' => '2026-08-10']);

        $this->assertSame(PartRfq::STATUS_OPEN, $rfq->status);
        $this->assertSame(2, $rfq->lines()->count());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $rfq->lines->pluck('part_request_id')->all());

        // Invariants: no purchase order created, and the sourced requests are UNCHANGED.
        $this->assertSame(0, PartPurchase::count());
        $this->assertSame(PartRequest::STATUS_APPROVED, $a->fresh()->status);
        $this->assertSame(PartRequest::STATUS_APPROVED, $b->fresh()->status);
    }

    public function test_open_rfq_rejects_a_non_approved_request(): void
    {
        $req = $this->approvedRequest(['status' => PartRequest::STATUS_REQUESTED]);

        $this->expectException(HttpException::class);
        $this->service()->openRfq([$req->id], $this->admin);
    }

    public function test_record_quote_writes_a_submitted_bid_on_a_line(): void
    {
        $req = $this->approvedRequest();
        $rfq = $this->service()->openRfq([$req->id], $this->admin);
        $line = $rfq->lines->first();
        $vendorId = $this->makeVendor(['name' => 'ABC Parts', 'type' => 'parts_supplier']);

        $quote = $this->service()->recordQuote($line, Vendor::find($vendorId), [
            'unit_price' => 120.50, 'lead_time_days' => 3, 'expected_delivery_date' => '2026-08-09',
        ], $this->admin);

        $this->assertSame(SupplierQuote::STATUS_SUBMITTED, $quote->status);
        $this->assertSame($line->id, $quote->rfq_line_id);
        $this->assertSame($vendorId, $quote->vendor_id);
        $this->assertSame('2.00', (string) $quote->quantity); // defaulted from the line's quantity (2)
        $this->assertSame(0, PartPurchase::count());          // still no PO
    }

    public function test_record_quote_rejected_when_rfq_not_open(): void
    {
        $req = $this->approvedRequest();
        $rfq = $this->service()->openRfq([$req->id], $this->admin);
        $line = $rfq->lines->first();
        $vendorId = $this->makeVendor(['name' => 'ABC Parts', 'type' => 'parts_supplier']);

        $rfq->update(['status' => PartRfq::STATUS_CLOSED]);

        $this->expectException(HttpException::class);
        $this->service()->recordQuote($line->fresh(), Vendor::find($vendorId), ['unit_price' => 100], $this->admin);
    }
}
