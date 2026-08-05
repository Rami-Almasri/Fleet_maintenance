<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\PartInvoice;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\PaymentAllocation;
use App\Models\SupplierPayment;
use App\Services\PartWorkflowService;
use App\Services\ProcurementReportService;
use App\Support\FinancialDocumentStatus as Status;
use Illuminate\Support\Carbon;

/**
 * Supplier payments and the procurement reports on top of them.
 *
 *  1. A PAYMENT IS A RECORD — two payments on one invoice keep two dates and two references.
 *  2. ONE PAYMENT, MANY BILLS — a single transfer settles several invoices, split by allocation.
 *  3. paid_amount IS DERIVED — voiding a payment restores the debt without anyone editing a total.
 *  4. GUARDS — never pay more than is owed, never pay an unapproved or cancelled bill.
 *  5. REPORTS — payables age correctly; supplier performance separates documented from undocumented.
 */
class SupplierPaymentTest extends CrudTestCase
{
    private function ticket(): Maintenance
    {
        return Maintenance::create([
            'vehicle_id'     => $this->makeVehicle(),
            'type'           => 'Breakdown',
            'status'         => 'Open',
            'actual_in_date' => Carbon::now()->toDateString(),
        ]);
    }

    private function fault(Maintenance $ticket): MaintenanceTask
    {
        $ticket->update(['findings' => [['text' => 'Brake noise']]]);

        return MaintenanceTask::create([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $ticket->vehicle_id,
            'symptom'        => 'Brake noise',
            'status'         => MaintenanceTask::STATUS_PENDING,
        ]);
    }

    private function purchase(Maintenance $ticket, ?MaintenanceTask $task, float $price = 400): PartPurchase
    {
        $request = PartRequest::create([
            'source'              => PartRequest::SOURCE_GARAGE,
            'status'              => PartRequest::STATUS_APPROVED,
            'vehicle_id'          => $ticket->vehicle_id,
            'maintenance_id'      => $ticket->id,
            'maintenance_task_id' => $task?->id,
            'part_name'           => 'Brake Pad Set',
            'quantity'            => 1,
            'reason'              => 'Worn',
            'requested_at'        => Carbon::now(),
        ]);

        return PartPurchase::create([
            'part_request_id'     => $request->id,
            'vehicle_id'          => $ticket->vehicle_id,
            'maintenance_id'      => $ticket->id,
            'maintenance_task_id' => $task?->id,
            'part_name'           => 'Brake Pad Set',
            'category_key'        => 'brakes',
            'purchase_source'     => PartPurchase::SOURCE_SUPPLIER,
            'source_name'         => 'ABC Auto Parts',
            'purchase_price'      => $price,
            'currency'            => 'AED',
            'quantity'            => 1,
            'purchased_at'        => Carbon::now(),
        ]);
    }

    /** An approved supplier invoice for one purchase — the payable state. */
    private function payableInvoice(Maintenance $ticket, float $price = 400, string $no = 'INV-1'): PartInvoice
    {
        $part = $this->purchase($ticket, $this->fault($ticket), $price);
        $res = $this->postJson('/api/part-invoices', [
            'supplier_name' => 'ABC Auto Parts', 'invoice_no' => $no, 'purchase_ids' => [$part->id],
        ]);
        $res->assertSuccessful();
        $id = $this->idOf($res);
        $this->postJson("/api/financial-documents/supplier-invoice/{$id}/approve")->assertSuccessful();
        app(PartWorkflowService::class)->installPurchase($part->fresh(), [], $this->admin);

        return PartInvoice::findOrFail($id);
    }

    private function pay(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/supplier-payments', array_merge([
            'payee_name'   => 'ABC Auto Parts',
            'payment_date' => Carbon::now()->toDateString(),
            'amount'       => 400,
            'method'       => SupplierPayment::METHOD_BANK_TRANSFER,
            'reference'    => 'TRF-1',
        ], $overrides));
    }

    // ── 1. A payment is a record ─────────────────────────────────────────────────────────────────────

    public function test_two_payments_on_one_invoice_keep_both_records(): void
    {
        $inv = $this->payableInvoice($this->ticket());   // 400

        $this->pay([
            'amount' => 150, 'reference' => 'TRF-A',
            'allocations' => [['document_type' => PaymentAllocation::DOC_SUPPLIER_INVOICE, 'document_id' => $inv->id, 'amount' => 150]],
        ])->assertSuccessful();

        $this->pay([
            'amount' => 250, 'reference' => 'TRF-B',
            'allocations' => [['document_type' => PaymentAllocation::DOC_SUPPLIER_INVOICE, 'document_id' => $inv->id, 'amount' => 250]],
        ])->assertSuccessful();

        // Both survive — the second no longer overwrites the first.
        $this->assertSame(2, SupplierPayment::count());
        $this->assertEqualsCanonicalizing(['TRF-A', 'TRF-B'], SupplierPayment::pluck('reference')->all());

        $fresh = $inv->fresh();
        $this->assertSame(400.0, round((float) $fresh->paid_amount, 2));
        $this->assertSame(Status::PAID, $fresh->documentStatus());
        $this->assertSame(0.0, $fresh->outstandingAmount());
    }

    public function test_a_part_payment_leaves_the_invoice_partially_paid(): void
    {
        $inv = $this->payableInvoice($this->ticket());

        $this->pay([
            'amount' => 100,
            'allocations' => [['document_type' => PaymentAllocation::DOC_SUPPLIER_INVOICE, 'document_id' => $inv->id, 'amount' => 100]],
        ])->assertSuccessful();

        $this->assertSame(Status::PARTIALLY_PAID, $inv->fresh()->documentStatus());
        $this->assertSame(300.0, $inv->fresh()->outstandingAmount());
    }

    // ── 2. One payment, many bills ───────────────────────────────────────────────────────────────────

    public function test_one_transfer_settles_several_invoices(): void
    {
        $a = $this->payableInvoice($this->ticket(), 400, 'INV-A');
        $b = $this->payableInvoice($this->ticket(), 250, 'INV-B');

        $res = $this->pay([
            'amount'    => 650,
            'reference' => 'TRF-BULK',
            'allocations' => [
                ['document_type' => PaymentAllocation::DOC_SUPPLIER_INVOICE, 'document_id' => $a->id, 'amount' => 400],
                ['document_type' => PaymentAllocation::DOC_SUPPLIER_INVOICE, 'document_id' => $b->id, 'amount' => 250],
            ],
        ]);
        $res->assertSuccessful();

        // ONE payment document, split across the bills it covers.
        $this->assertSame(1, SupplierPayment::count());
        $this->assertSame(2, PaymentAllocation::count());
        $this->assertSame(Status::PAID, $a->fresh()->documentStatus());
        $this->assertSame(Status::PAID, $b->fresh()->documentStatus());
    }

    public function test_money_paid_on_account_is_reported_as_unallocated(): void
    {
        $res = $this->pay(['amount' => 1000, 'allocations' => []]);
        $res->assertSuccessful();

        $this->assertEquals(1000.0, data_get($res->json(), 'data.unallocated'));
        $this->assertEquals(0.0, data_get($res->json(), 'data.allocated'));
    }

    public function test_paying_on_account_can_be_allocated_later(): void
    {
        $inv = $this->payableInvoice($this->ticket());
        $res = $this->pay(['amount' => 1000, 'allocations' => []]);
        $paymentId = $this->idOf($res);

        $this->postJson("/api/supplier-payments/{$paymentId}/allocate", [
            'allocations' => [['document_type' => PaymentAllocation::DOC_SUPPLIER_INVOICE, 'document_id' => $inv->id, 'amount' => 400]],
        ])->assertSuccessful();

        $this->assertSame(Status::PAID, $inv->fresh()->documentStatus());
        $this->assertSame(600.0, SupplierPayment::find($paymentId)->unallocatedTotal());
    }

    // ── 3. paid_amount is derived ────────────────────────────────────────────────────────────────────

    public function test_voiding_a_payment_restores_the_debt(): void
    {
        $inv = $this->payableInvoice($this->ticket());
        $res = $this->pay([
            'allocations' => [['document_type' => PaymentAllocation::DOC_SUPPLIER_INVOICE, 'document_id' => $inv->id]],
        ]);
        $paymentId = $this->idOf($res);
        $this->assertSame(Status::PAID, $inv->fresh()->documentStatus());

        $this->postJson("/api/supplier-payments/{$paymentId}/cancel", ['reason' => 'The transfer bounced.'])
            ->assertSuccessful();

        $fresh = $inv->fresh();
        $this->assertSame(0.0, round((float) $fresh->paid_amount, 2), 'paid_amount is a sum of live payments.');
        $this->assertSame(400.0, $fresh->outstandingAmount());
        $this->assertSame(Status::APPROVED, $fresh->documentStatus());
        // The record survives — a bounced transfer is history, not something to erase.
        $this->assertNotNull(SupplierPayment::find($paymentId));
    }

    // ── 4. Guards ────────────────────────────────────────────────────────────────────────────────────

    public function test_a_bill_cannot_be_over_paid_across_two_payments(): void
    {
        $inv = $this->payableInvoice($this->ticket());   // 400

        $this->pay([
            'amount' => 300,
            'allocations' => [['document_type' => PaymentAllocation::DOC_SUPPLIER_INVOICE, 'document_id' => $inv->id, 'amount' => 300]],
        ])->assertSuccessful();

        // A second person paying 300 against the same bill must be refused, not silently accepted.
        $this->pay([
            'amount' => 300,
            'allocations' => [['document_type' => PaymentAllocation::DOC_SUPPLIER_INVOICE, 'document_id' => $inv->id, 'amount' => 300]],
        ])->assertStatus(422);

        $this->assertSame(300.0, round((float) $inv->fresh()->paid_amount, 2));
    }

    public function test_an_unapproved_bill_cannot_be_paid(): void
    {
        $ticket = $this->ticket();
        $part = $this->purchase($ticket, $this->fault($ticket));
        $res = $this->postJson('/api/part-invoices', [
            'supplier_name' => 'ABC Auto Parts', 'invoice_no' => 'INV-DRAFT', 'purchase_ids' => [$part->id],
        ]);
        $draftId = $this->idOf($res);

        $this->pay([
            'allocations' => [['document_type' => PaymentAllocation::DOC_SUPPLIER_INVOICE, 'document_id' => $draftId]],
        ])->assertStatus(422);

        $this->assertSame(0, SupplierPayment::count(), 'A rejected allocation leaves no half-made payment.');
    }

    public function test_allocations_cannot_exceed_the_payment_itself(): void
    {
        $a = $this->payableInvoice($this->ticket(), 400, 'INV-A');
        $b = $this->payableInvoice($this->ticket(), 400, 'INV-B');

        $this->pay([
            'amount' => 500,
            'allocations' => [
                ['document_type' => PaymentAllocation::DOC_SUPPLIER_INVOICE, 'document_id' => $a->id, 'amount' => 400],
                ['document_type' => PaymentAllocation::DOC_SUPPLIER_INVOICE, 'document_id' => $b->id, 'amount' => 400],
            ],
        ])->assertStatus(422);
    }

    // ── 5. The reports ───────────────────────────────────────────────────────────────────────────────

    public function test_payables_age_from_the_due_date(): void
    {
        // Payment terms changed what "old" means: a bill ages from the date it FELL DUE, not from the
        // date it was written. The due date is stamped at approval, so back-dating this one means moving
        // its due date — back-dating `invoice_date` afterwards deliberately changes nothing.
        $old = $this->payableInvoice($this->ticket(), 400, 'INV-OLD');
        $old->forceFill(['due_date' => Carbon::now()->subDays(75)->toDateString()])->save();
        $this->payableInvoice($this->ticket(), 250, 'INV-NEW');

        $payables = app(ProcurementReportService::class)->payables();

        $this->assertSame(650.0, $payables['total_outstanding']);
        $this->assertSame(400.0, $payables['buckets']['0-90'], 'A bill 75 days past due lands in the 61–90 band.');
        // The newer bill has no agreed terms, so it fell due on receipt — TODAY. Due today is not yet
        // late: the aging buckets count what is PAST its date, so this one is owed but not chased.
        $this->assertSame(0.0, $payables['buckets']['0-30']);
        $this->assertSame(250.0, $payables['not_yet_due_total']);
        $this->assertSame(400.0, $payables['overdue_total']);
        // Grouped by who you actually pay.
        $this->assertSame('ABC Auto Parts', $payables['by_payee'][0]['payee']);
        $this->assertSame(2, $payables['by_payee'][0]['invoices']);
    }

    public function test_supplier_performance_separates_documented_spend(): void
    {
        $ticket = $this->ticket();
        $this->payableInvoice($ticket, 400, 'INV-DOC');       // documented
        $this->purchase($ticket, $this->fault($ticket), 200); // bought, never invoiced

        $rows = app(ProcurementReportService::class)->supplierPerformance();
        $abc = collect($rows)->firstWhere('supplier', 'ABC Auto Parts');

        $this->assertSame(600.0, $abc['spend']);
        $this->assertSame(400.0, $abc['documented']);
        $this->assertSame(200.0, $abc['undocumented']);
        $this->assertSame(66.7, $abc['documentation_pct']);
        // Nothing was ever stamped delivered — an unmeasured lead time must not read as an excellent one.
        $this->assertNull($abc['avg_lead_days']);
    }

    public function test_the_overview_carries_the_traceability_figure_with_the_money(): void
    {
        $inv = $this->payableInvoice($this->ticket());
        $this->pay([
            'allocations' => [['document_type' => PaymentAllocation::DOC_SUPPLIER_INVOICE, 'document_id' => $inv->id]],
        ])->assertSuccessful();

        $overview = app(ProcurementReportService::class)->overview();

        $this->assertSame(400.0, $overview['committed']);
        $this->assertSame(400.0, $overview['paid']);
        $this->assertSame(0.0, $overview['outstanding']);
        // A spend report must never travel without saying how much of it is provable.
        $this->assertArrayHasKey('coverage_pct', $overview['traceability']);
    }
}
