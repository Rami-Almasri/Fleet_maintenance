<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\PartInvoice;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\PartReturn;
use App\Services\PartWorkflowService;
use App\Services\ProcurementLifecycleService;
use App\Support\FinancialDocumentStatus as Status;
use Illuminate\Support\Carbon;

/**
 * The accounting lifecycle, pinned:
 *
 *  1. STATUS IS A MACHINE — you cannot pay a draft or resurrect a cancelled document.
 *  2. PAYMENT IS AN AMOUNT — part-payments derive PARTIALLY_PAID; over-payment is refused.
 *  3. REFUNDS DERIVE THEMSELVES — a credited invoice reports partially/fully refunded with nobody setting it.
 *  4. THE CHAIN IS HONEST — request → PO → invoice → received → installed → return → paid → closed, with
 *     `skipped` for the stages that genuinely do not apply, and `blocking` for the ones that are a gap.
 */
class FinancialLifecycleTest extends CrudTestCase
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

    private function fault(Maintenance $ticket, string $symptom = 'Brake noise'): MaintenanceTask
    {
        $ticket->update(['findings' => [['text' => $symptom]]]);

        return MaintenanceTask::create([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $ticket->vehicle_id,
            'symptom'        => $symptom,
            'status'         => MaintenanceTask::STATUS_PENDING,
        ]);
    }

    private function purchase(Maintenance $ticket, ?MaintenanceTask $task, array $overrides = []): PartPurchase
    {
        $request = PartRequest::create([
            'source'              => PartRequest::SOURCE_GARAGE,
            'status'              => PartRequest::STATUS_APPROVED,
            'vehicle_id'          => $ticket->vehicle_id,
            'maintenance_id'      => $ticket->id,
            'maintenance_task_id' => $task?->id,
            'part_name'           => $overrides['part_name'] ?? 'Brake Pad Set',
            'quantity'            => 1,
            'reason'              => 'Worn',
            'requested_at'        => Carbon::now(),
        ]);

        return PartPurchase::create(array_merge([
            'part_request_id'     => $request->id,
            'vehicle_id'          => $ticket->vehicle_id,
            'maintenance_id'      => $ticket->id,
            'maintenance_task_id' => $task?->id,
            'part_name'           => 'Brake Pad Set',
            'purchase_source'     => PartPurchase::SOURCE_SUPPLIER,
            'source_name'         => 'ABC Auto Parts',
            'purchase_price'      => 400,
            'currency'            => 'AED',
            'quantity'            => 1,
            'purchased_at'        => Carbon::now(),
        ], $overrides));
    }

    private function invoiceFor(PartPurchase $p, string $no = 'INV-1'): PartInvoice
    {
        $res = $this->postJson('/api/part-invoices', [
            'supplier_name' => 'ABC Auto Parts', 'invoice_no' => $no, 'purchase_ids' => [$p->id],
        ]);
        $res->assertSuccessful();

        return PartInvoice::findOrFail($this->idOf($res));
    }

    private function lifecycle(Maintenance $ticket): array
    {
        return app(ProcurementLifecycleService::class)->forTicket($ticket->fresh());
    }

    // ── 1. The status machine ────────────────────────────────────────────────────────────────────────

    public function test_a_new_invoice_starts_as_a_draft(): void
    {
        $ticket = $this->ticket();
        $inv = $this->invoiceFor($this->purchase($ticket, null));

        $this->assertSame(Status::DRAFT, $inv->documentStatus());
        $this->assertTrue($inv->isEditable());
        $this->assertFalse($inv->isCommitted(), 'A draft is not yet an obligation.');
    }

    public function test_a_draft_cannot_be_paid(): void
    {
        $ticket = $this->ticket();
        $inv = $this->invoiceFor($this->purchase($ticket, null));

        $this->postJson("/api/financial-documents/supplier-invoice/{$inv->id}/pay", ['amount' => 400])
            ->assertStatus(422);
    }

    public function test_the_full_path_draft_to_approved_to_paid(): void
    {
        $ticket = $this->ticket();
        $inv = $this->invoiceFor($this->purchase($ticket, null)); // 400

        $this->postJson("/api/financial-documents/supplier-invoice/{$inv->id}/submit")->assertSuccessful();
        $this->assertSame(Status::PENDING, $inv->fresh()->documentStatus());

        $res = $this->postJson("/api/financial-documents/supplier-invoice/{$inv->id}/approve");
        $res->assertSuccessful();
        $this->assertSame(Status::APPROVED, $inv->fresh()->documentStatus());
        // Approval is answerable: it carries a name.
        $this->assertSame($this->admin->name, $inv->fresh()->approved_by_name);
        $this->assertFalse($inv->fresh()->isEditable(), 'An approved obligation is no longer edited in place.');

        $this->postJson("/api/financial-documents/supplier-invoice/{$inv->id}/pay", ['reference' => 'TRF-88'])
            ->assertSuccessful();

        $fresh = $inv->fresh();
        $this->assertSame(Status::PAID, $fresh->documentStatus());
        $this->assertSame(400.0, round((float) $fresh->paid_amount, 2));
        $this->assertSame('TRF-88', $fresh->payment_reference);
        $this->assertSame(0.0, $fresh->outstandingAmount());
    }

    public function test_a_cancelled_document_cannot_be_resurrected(): void
    {
        $ticket = $this->ticket();
        $inv = $this->invoiceFor($this->purchase($ticket, null));

        $this->postJson("/api/financial-documents/supplier-invoice/{$inv->id}/cancel", ['reason' => 'Duplicate of INV-2'])
            ->assertSuccessful();
        $this->assertSame(Status::CANCELLED, $inv->fresh()->documentStatus());

        $this->postJson("/api/financial-documents/supplier-invoice/{$inv->id}/approve")->assertStatus(422);
        $this->assertSame(0.0, $inv->fresh()->outstandingAmount(), 'A cancelled document is owed nothing.');
    }

    // ── 2. Payment is an amount ──────────────────────────────────────────────────────────────────────

    public function test_a_part_payment_derives_partially_paid(): void
    {
        $ticket = $this->ticket();
        $inv = $this->invoiceFor($this->purchase($ticket, null)); // 400
        $this->postJson("/api/financial-documents/supplier-invoice/{$inv->id}/approve")->assertSuccessful();

        $this->postJson("/api/financial-documents/supplier-invoice/{$inv->id}/pay", ['amount' => 150])
            ->assertSuccessful();

        $fresh = $inv->fresh();
        $this->assertSame(Status::PARTIALLY_PAID, $fresh->documentStatus());
        $this->assertSame(250.0, $fresh->outstandingAmount());
        // The STORED status is still the decision that was taken; the money state is derived on top.
        $this->assertSame(Status::APPROVED, $fresh->status);

        $this->postJson("/api/financial-documents/supplier-invoice/{$inv->id}/pay")->assertSuccessful();
        $this->assertSame(Status::PAID, $inv->fresh()->documentStatus());
    }

    public function test_over_payment_is_refused(): void
    {
        $ticket = $this->ticket();
        $inv = $this->invoiceFor($this->purchase($ticket, null)); // 400
        $this->postJson("/api/financial-documents/supplier-invoice/{$inv->id}/approve")->assertSuccessful();

        $this->postJson("/api/financial-documents/supplier-invoice/{$inv->id}/pay", ['amount' => 900])
            ->assertStatus(422);
    }

    // ── 3. Refunds derive themselves ─────────────────────────────────────────────────────────────────

    public function test_a_credited_invoice_reports_itself_refunded(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $part   = $this->purchase($ticket, $task);
        $inv    = $this->invoiceFor($part);
        $this->postJson("/api/financial-documents/supplier-invoice/{$inv->id}/approve")->assertSuccessful();
        app(PartWorkflowService::class)->installPurchase($part, [], $this->admin);

        $this->postJson("/api/part-purchases/{$part->id}/returns", [
            'reason_code' => PartReturn::REASON_WRONG_PART,
            'status'      => PartReturn::STATUS_REFUNDED,
        ])->assertSuccessful();

        // Nobody set this status — it follows from the credit note.
        $this->assertSame(Status::REFUNDED, $inv->fresh()->documentStatus());
        $this->assertSame(0.0, $inv->fresh()->outstandingAmount(), 'Fully credited means nothing is owed.');
    }

    // ── 4. The chain ─────────────────────────────────────────────────────────────────────────────────

    public function test_the_chain_walks_request_to_installed(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $part   = $this->purchase($ticket, $task);
        $inv    = $this->invoiceFor($part);
        $this->postJson("/api/financial-documents/supplier-invoice/{$inv->id}/approve")->assertSuccessful();

        app(PartWorkflowService::class)->markDelivered($part, $this->admin);
        app(PartWorkflowService::class)->installPurchase($part->fresh(), [], $this->admin);

        $chain = $this->lifecycle($ticket)['chains'][0];
        $s = $chain['stages'];

        $this->assertSame('done', $s['purchase_request']['state']);
        $this->assertSame('skipped', $s['purchase_order']['state'], 'A direct buy raises no PO — not a gap.');
        $this->assertSame('done', $s['supplier_invoice']['state']);
        $this->assertSame('INV-1', $s['supplier_invoice']['document']);
        $this->assertSame('done', $s['goods_received']['state']);
        $this->assertSame('done', $s['part_installed']['state']);
        $this->assertSame('skipped', $s['return_credit']['state'], 'Nothing came back — the good outcome.');
        // Approved but unpaid → payment is where this part now sits.
        $this->assertSame('current', $s['supplier_payment']['state']);
        $this->assertNull($chain['blocking']);
    }

    public function test_a_supplier_buy_with_no_invoice_is_flagged_as_blocking(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $this->purchase($ticket, $task);   // bought, no invoice keyed

        $lifecycle = $this->lifecycle($ticket);
        $chain = $lifecycle['chains'][0];

        $this->assertSame('current', $chain['stages']['supplier_invoice']['state']);
        $this->assertStringContainsString('no supplier invoice', $chain['blocking']);
        $this->assertSame(1, $lifecycle['summary']['awaiting_invoice']);
        $this->assertSame(400.0, $lifecycle['summary']['awaiting_invoice_value']);
        $this->assertSame(['Brake Pad Set'], $lifecycle['summary']['blocked']);
    }

    public function test_a_garage_supplied_part_skips_the_supplier_stages(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $this->purchase($ticket, $task, ['purchase_source' => PartPurchase::SOURCE_GARAGE]);

        $chain = $this->lifecycle($ticket)['chains'][0];

        $this->assertSame('skipped', $chain['stages']['supplier_invoice']['state']);
        $this->assertSame('skipped', $chain['stages']['supplier_payment']['state']);
        $this->assertNull($chain['blocking'], 'A garage part is on the garage bill — never a missing document.');
    }

    public function test_the_summary_answers_ordered_received_installed_and_owed(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);

        $fitted = $this->purchase($ticket, $task, ['part_name' => 'Brake Pad Set']);
        $inv    = $this->invoiceFor($fitted, 'INV-A');
        $this->postJson("/api/financial-documents/supplier-invoice/{$inv->id}/approve")->assertSuccessful();
        app(PartWorkflowService::class)->installPurchase($fitted, [], $this->admin);

        $this->purchase($ticket, $task, ['part_name' => 'ABS Sensor', 'purchase_price' => 220]); // ordered only

        $summary = $this->lifecycle($ticket)['summary'];

        $this->assertSame(2, $summary['parts_total']);
        $this->assertSame(2, $summary['ordered']);
        $this->assertSame(1, $summary['installed']);
        $this->assertSame(0, $summary['returned']);
        $this->assertSame(1, $summary['awaiting_invoice'], 'The sensor has no document yet.');
        // 400 approved and unpaid is what we owe the supplier so far.
        $this->assertSame(400.0, $summary['outstanding_to_suppliers']);
    }
}
