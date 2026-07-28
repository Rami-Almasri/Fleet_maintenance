<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\PartRfq;
use App\Models\RfqLine;
use App\Models\SupplierQuote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2, Step P2-1 validation (blueprint §3a–e). Schema only: the new RFQ tables exist, the
 * part_purchases / vendors columns are added (additive, nullable), and the models' relations + casts
 * wire up. No logic — this pins the substrate later procurement steps build on.
 */
class RfqProcurementSchemaTest extends CrudTestCase
{
    private function partRequest(): PartRequest
    {
        $vehicleId = $this->makeVehicle();
        $ticket = Maintenance::create([
            'vehicle_id' => $vehicleId, 'workflow_status' => Maintenance::WF_UNDER_REPAIR, 'event_status' => 'OUT',
        ]);
        $task = MaintenanceTask::create([
            'maintenance_id' => $ticket->id, 'vehicle_id' => $vehicleId,
            'symptom' => 'Grinding', 'status' => MaintenanceTask::STATUS_IN_PROGRESS,
        ]);

        return PartRequest::create([
            'maintenance_id' => $ticket->id, 'maintenance_task_id' => $task->id, 'vehicle_id' => $vehicleId,
            'source' => PartRequest::SOURCE_GARAGE, 'status' => PartRequest::STATUS_APPROVED,
            'part_name' => 'Brake Pads', 'category_key' => 'brakes', 'quantity' => 1, 'reason' => 'Worn',
        ]);
    }

    public function test_new_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('part_rfqs'));
        $this->assertTrue(Schema::hasTable('rfq_lines'));
        $this->assertTrue(Schema::hasTable('supplier_quotes'));

        $this->assertTrue(Schema::hasColumns('part_purchases', ['rfq_line_id', 'supplier_quote_id', 'po_number']));
        $this->assertTrue(Schema::hasColumn('vendors', 'default_lead_time_days'));
    }

    public function test_rfq_line_quote_relations_and_casts(): void
    {
        $request = $this->partRequest();
        $vendorId = $this->makeVendor(['name' => 'ABC Parts', 'type' => 'parts_supplier']);

        $rfq  = PartRfq::create(['status' => PartRfq::STATUS_OPEN, 'needed_by_date' => '2026-08-10']);
        $line = RfqLine::create(['part_rfq_id' => $rfq->id, 'part_request_id' => $request->id, 'quantity' => 2]);
        $quote = SupplierQuote::create([
            'rfq_line_id' => $line->id, 'vendor_id' => $vendorId,
            'unit_price' => 120.50, 'quantity' => 2, 'currency' => 'AED',
            'lead_time_days' => 3, 'expected_delivery_date' => '2026-08-09',
            'status' => SupplierQuote::STATUS_SUBMITTED,
        ]);

        // Relations
        $this->assertSame(1, $rfq->lines()->count());
        $this->assertSame($request->id, $line->request->id);
        $this->assertSame(1, $line->quotes()->count());
        $this->assertSame($line->id, $quote->line->id);
        $this->assertSame('ABC Parts', $quote->supplier->name);

        // Casts
        $this->assertInstanceOf(Carbon::class, $rfq->needed_by_date);
        $this->assertInstanceOf(Carbon::class, $quote->fresh()->expected_delivery_date);
        $this->assertSame(3, $quote->fresh()->lead_time_days);

        // Award per line (app-owned link, no DB FK)
        $line->update(['awarded_quote_id' => $quote->id]);
        $this->assertSame($quote->id, $line->fresh()->awardedQuote->id);
    }

    public function test_part_purchase_links_to_awarded_line_and_quote(): void
    {
        $request = $this->partRequest();
        $vendorId = $this->makeVendor(['name' => 'ABC Parts', 'type' => 'parts_supplier']);
        $rfq  = PartRfq::create(['status' => PartRfq::STATUS_AWARDED]);
        $line = RfqLine::create(['part_rfq_id' => $rfq->id, 'part_request_id' => $request->id, 'quantity' => 1]);
        $quote = SupplierQuote::create([
            'rfq_line_id' => $line->id, 'vendor_id' => $vendorId, 'unit_price' => 300, 'status' => SupplierQuote::STATUS_SELECTED,
        ]);

        $purchase = PartPurchase::create([
            'part_request_id' => $request->id, 'rfq_line_id' => $line->id, 'supplier_quote_id' => $quote->id,
            'po_number' => 'PO-1001', 'vehicle_id' => $request->vehicle_id, 'part_name' => 'Brake Pads',
            'category_key' => 'brakes', 'purchase_source' => PartPurchase::SOURCE_SUPPLIER,
            'purchase_price' => 300, 'currency' => 'AED', 'quantity' => 1, 'purchased_at' => now(),
        ])->fresh();

        $this->assertSame($line->id, $purchase->rfqLine->id);
        $this->assertSame($quote->id, $purchase->supplierQuote->id);
        $this->assertSame('PO-1001', $purchase->po_number);
    }
}
