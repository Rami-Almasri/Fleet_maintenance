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
 * Phase 2, Step P2-3 — Award + PO issuance. ProcurementService owns award (writes only the procurement
 * tables) and DELEGATES the PO write to PartWorkflowService (the sole writer of part_purchases). The PO
 * carries the RFQ linkage + the awarded quote's price/supplier/ETA.
 */
class ProcurementAwardPoTest extends CrudTestCase
{
    private function service(): ProcurementService
    {
        return app(ProcurementService::class);
    }

    /** approved request → RFQ → one submitted quote. */
    private function rfqWithQuote(): array
    {
        $vehicleId = $this->makeVehicle();
        $ticket = Maintenance::create([
            'vehicle_id' => $vehicleId, 'workflow_status' => Maintenance::WF_UNDER_REPAIR, 'event_status' => 'OUT',
        ]);
        $task = MaintenanceTask::create([
            'maintenance_id' => $ticket->id, 'vehicle_id' => $vehicleId,
            'symptom' => 'Grinding', 'status' => MaintenanceTask::STATUS_IN_PROGRESS,
        ]);
        $req = PartRequest::create([
            'maintenance_id' => $ticket->id, 'maintenance_task_id' => $task->id, 'vehicle_id' => $vehicleId,
            'source' => PartRequest::SOURCE_GARAGE, 'status' => PartRequest::STATUS_APPROVED,
            'part_name' => 'Brake Pads', 'category_key' => 'brakes', 'quantity' => 2, 'reason' => 'Worn',
        ]);

        $rfq  = $this->service()->openRfq([$req->id], $this->admin);
        $line = $rfq->lines->first();
        $vendorId = $this->makeVendor(['name' => 'ABC Parts', 'type' => 'parts_supplier']);
        $quote = $this->service()->recordQuote($line, Vendor::find($vendorId), [
            'unit_price' => 150, 'currency' => 'AED', 'expected_delivery_date' => '2026-08-12',
        ], $this->admin);

        return compact('req', 'line', 'quote', 'vendorId');
    }

    public function test_award_marks_quote_selected_and_line_awarded(): void
    {
        ['line' => $line, 'quote' => $quote] = $this->rfqWithQuote();

        $awarded = $this->service()->award($line, $quote, $this->admin);

        $this->assertSame($quote->id, $awarded->awarded_quote_id);
        $this->assertSame(SupplierQuote::STATUS_SELECTED, $quote->fresh()->status);
        $this->assertSame(PartRfq::STATUS_AWARDED, $line->rfq->fresh()->status); // single line → fully awarded
        $this->assertSame(0, PartPurchase::count());                              // award ≠ purchase
    }

    public function test_issue_po_delegates_to_partworkflow_and_links_the_rfq(): void
    {
        ['req' => $req, 'line' => $line, 'quote' => $quote, 'vendorId' => $vendorId] = $this->rfqWithQuote();
        $this->service()->award($line, $quote, $this->admin);

        $purchase = $this->service()->issuePurchaseOrder($line->fresh(), $this->admin)['purchase'];

        $this->assertSame($line->id, $purchase->rfq_line_id);
        $this->assertSame($quote->id, $purchase->supplier_quote_id);
        $this->assertNotNull($purchase->po_number);
        $this->assertSame($vendorId, $purchase->source_vendor_id);
        $this->assertSame('150.00', (string) $purchase->purchase_price);
        $this->assertSame('2026-08-12', optional($purchase->expected_delivery_date)->toDateString());
        $this->assertSame(PartRequest::STATUS_PURCHASED, $req->fresh()->status); // moved by PartWorkflowService
        $this->assertSame(1, PartPurchase::count());
    }

    public function test_issue_po_requires_an_award_first(): void
    {
        ['line' => $line] = $this->rfqWithQuote();

        $this->expectException(HttpException::class);
        $this->service()->issuePurchaseOrder($line, $this->admin);
    }

    public function test_award_rejects_a_quote_from_another_line(): void
    {
        ['line' => $line] = $this->rfqWithQuote();
        $foreign = $this->rfqWithQuote();

        $this->expectException(HttpException::class);
        $this->service()->award($line, $foreign['quote'], $this->admin);
    }
}
