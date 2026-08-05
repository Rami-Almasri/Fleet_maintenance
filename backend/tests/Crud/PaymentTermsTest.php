<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\PartInvoice;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\Vendor;
use App\Services\ProcurementReportService;
use Illuminate\Support\Carbon;

/**
 * Payment terms — "overdue" must mean late against what we AGREED, not merely old.
 *
 *  1. TERMS LIVE ON THE VENDOR, because the agreement is with them, not with each bill.
 *  2. THE DUE DATE IS STAMPED at approval — renegotiating terms next year must not rewrite whether last
 *     year's bill was paid late.
 *  3. AGEING IS AGAINST THE DUE DATE. A 45-day-old bill on net-60 is not late; a 20-day-old bill due on
 *     receipt is. Ageing both from the invoice date sends people chasing the wrong supplier.
 *  4. NO TERMS = DUE ON RECEIPT, which reproduces exactly the behaviour that existed before terms.
 */
class PaymentTermsTest extends CrudTestCase
{
    private function ticket(): Maintenance
    {
        return Maintenance::create([
            'vehicle_id'     => $this->makeVehicle(),
            'type'           => 'Breakdown',
            'status'         => 'Open',
            'actual_in_date' => Carbon::now()->toDateString(),
            'findings'       => [['text' => 'Brake noise']],
        ]);
    }

    private function fault(Maintenance $ticket): MaintenanceTask
    {
        return MaintenanceTask::create([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $ticket->vehicle_id,
            'symptom'        => 'Brake noise',
            'status'         => MaintenanceTask::STATUS_PENDING,
        ]);
    }

    /** A supplier with agreed terms. */
    private function supplier(?int $termsDays, string $name = 'ABC Auto Parts'): Vendor
    {
        return Vendor::create([
            'name'               => $name . ' ' . uniqid(),
            'type'               => 'parts_supplier',
            'payment_terms_days' => $termsDays,
        ]);
    }

    /** An approved supplier invoice dated `$daysAgo`, from `$vendor`. */
    private function approvedInvoice(Vendor $vendor, int $daysAgo, float $price = 400): PartInvoice
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);

        $request = PartRequest::create([
            'source'              => PartRequest::SOURCE_GARAGE,
            'status'              => PartRequest::STATUS_APPROVED,
            'vehicle_id'          => $ticket->vehicle_id,
            'maintenance_id'      => $ticket->id,
            'maintenance_task_id' => $task->id,
            'part_name'           => 'Brake Pad Set',
            'quantity'            => 1,
            'reason'              => 'Worn',
            'requested_at'        => Carbon::now(),
        ]);

        $purchase = PartPurchase::create([
            'part_request_id'     => $request->id,
            'vehicle_id'          => $ticket->vehicle_id,
            'maintenance_id'      => $ticket->id,
            'maintenance_task_id' => $task->id,
            'part_name'           => 'Brake Pad Set',
            'purchase_source'     => PartPurchase::SOURCE_SUPPLIER,
            'source_vendor_id'    => $vendor->id,
            'purchase_price'      => $price,
            'currency'            => 'AED',
            'quantity'            => 1,
            'purchased_at'        => Carbon::now(),
        ]);

        $res = $this->postJson('/api/part-invoices', [
            'vendor_id'    => $vendor->id,
            'invoice_no'   => 'INV-' . uniqid(),
            'invoice_date' => Carbon::now()->subDays($daysAgo)->toDateString(),
            'purchase_ids' => [$purchase->id],
        ]);
        $res->assertSuccessful();

        $id = $this->idOf($res);
        $this->postJson("/api/financial-documents/supplier-invoice/{$id}/approve")->assertSuccessful();

        return PartInvoice::findOrFail($id);
    }

    // ── 1 & 2. Terms are stamped at approval ─────────────────────────────────────────────────────────

    public function test_approval_stamps_the_due_date_from_the_suppliers_terms(): void
    {
        $vendor  = $this->supplier(30);
        $invoice = $this->approvedInvoice($vendor, 10);

        $this->assertSame(30, $invoice->terms_days);
        $this->assertSame(
            Carbon::now()->subDays(10)->addDays(30)->toDateString(),
            $invoice->due_date->toDateString(),
        );
    }

    public function test_changing_the_suppliers_terms_later_does_not_rewrite_an_existing_bill(): void
    {
        $vendor  = $this->supplier(30);
        $invoice = $this->approvedInvoice($vendor, 10);
        $stamped = $invoice->due_date->toDateString();

        // Renegotiate to net-90 AFTER the bill was approved.
        $vendor->forceFill(['payment_terms_days' => 90])->save();

        $this->assertSame($stamped, $invoice->fresh()->due_date->toDateString(),
            'A stamped due date is history — new terms apply to new bills only.');
    }

    public function test_no_agreed_terms_means_due_on_receipt(): void
    {
        $vendor  = $this->supplier(null);
        $invoice = $this->approvedInvoice($vendor, 5);

        $this->assertNull($invoice->terms_days);
        // Identical to how payables aged before terms existed.
        $this->assertSame(Carbon::now()->subDays(5)->toDateString(), $invoice->due_date->toDateString());
        $this->assertTrue($invoice->isOverdue());
        $this->assertSame(5, $invoice->daysOverdue());
    }

    // ── 3. Ageing is against the due date ────────────────────────────────────────────────────────────

    public function test_a_bill_inside_its_terms_is_not_overdue(): void
    {
        // 45 days old, but the supplier gave us 60.
        $invoice = $this->approvedInvoice($this->supplier(60), 45);

        $this->assertFalse($invoice->isOverdue());
        $this->assertSame(-15, $invoice->daysOverdue(), 'Negative reads as "due in 15 days".');
    }

    public function test_the_older_bill_can_be_the_one_that_is_not_late(): void
    {
        $patient = $this->approvedInvoice($this->supplier(60), 45, 400);  // older, not yet due
        $strict  = $this->approvedInvoice($this->supplier(0), 20, 250);   // newer, 20 days late

        $payables = app(ProcurementReportService::class)->payables();

        $this->assertSame(650.0, $payables['total_outstanding']);
        // Only one of these is a problem, and it is not the older one.
        $this->assertSame(250.0, $payables['overdue_total']);
        $this->assertSame(1, $payables['overdue_count']);
        $this->assertSame(400.0, $payables['not_yet_due_total']);

        // The list is worked most-overdue-first, so the late one leads.
        $this->assertSame($strict->id, $payables['invoices'][0]['document_id']);
        $this->assertSame(20, $payables['invoices'][0]['days_overdue']);
        $this->assertFalse($payables['invoices'][1]['overdue']);
    }

    public function test_buckets_count_days_late_not_days_old(): void
    {
        // 100 days old on net-90 terms → only 10 days late, so it belongs in the first band.
        $this->approvedInvoice($this->supplier(90), 100, 500);

        $payables = app(ProcurementReportService::class)->payables();

        $this->assertSame(500.0, $payables['buckets']['0-30']);
        $this->assertSame(0.0, $payables['buckets']['90+'], 'A 100-day-old bill on net-90 is 10 days late.');
    }

    public function test_a_settled_bill_is_neither_owed_nor_overdue(): void
    {
        $invoice = $this->approvedInvoice($this->supplier(0), 30);

        $this->postJson("/api/financial-documents/supplier-invoice/{$invoice->id}/pay")->assertSuccessful();

        $fresh = $invoice->fresh();
        $this->assertNull($fresh->daysOverdue(), 'Nothing outstanding means nothing overdue.');
        $this->assertFalse($fresh->isOverdue());
        $this->assertSame(0.0, app(ProcurementReportService::class)->payables()['overdue_total']);
    }

    public function test_payables_by_payee_reports_what_is_late_per_supplier(): void
    {
        $late = $this->supplier(0, 'Strict Supplier');
        $this->approvedInvoice($late, 30, 300);
        $this->approvedInvoice($this->supplier(60, 'Patient Supplier'), 10, 900);

        $rows = app(ProcurementReportService::class)->payables()['by_payee'];

        // Sorted by what is actually LATE, not by what is merely owed — the patient supplier is owed
        // three times as much and correctly appears second.
        $this->assertStringContainsString('Strict Supplier', $rows[0]['payee']);
        $this->assertSame(300.0, $rows[0]['overdue']);
        $this->assertSame(30, $rows[0]['most_overdue']);
        $this->assertSame(0.0, $rows[1]['overdue']);
    }
}
