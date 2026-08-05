<?php

namespace Tests\Crud;

use App\Exceptions\WorkflowTransitionException;
use App\Models\CostAdjustment;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\PartInvoice;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\PartReturn;
use App\Services\FinancialCompletenessService;
use App\Services\FinancialTimelineService;
use App\Services\MaintenanceWorkflowService;
use App\Services\PartWorkflowService;
use Illuminate\Support\Carbon;

/**
 * The workflow can no longer be bypassed. These pin the three ways a user used to be able to leave money
 * unexplained, and the one legitimate way past each:
 *
 *  1. CLOSING a ticket over undocumented spend            → refused, naming the exact next action.
 *  2. TYPING a total over an itemised ticket              → refused; edit the invoice or adjust.
 *  3. A RETURN left in limbo, or an unapproved bill       → refused; settle it or approve it.
 *
 * Plus: the financial story is one ordered narrative from diagnosis to closure.
 */
class FinancialWorkflowGateTest extends CrudTestCase
{
    private function ticket(string $wf = Maintenance::WF_AWAITING_INVOICE): Maintenance
    {
        return Maintenance::create([
            'vehicle_id'      => $this->makeVehicle(),
            'type'            => 'Breakdown',
            'status'          => 'Open',
            'workflow_status' => $wf,
            'actual_in_date'  => Carbon::now()->toDateString(),
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

    private function approvedInvoiceFor(PartPurchase $p, string $no = 'INV-1'): PartInvoice
    {
        $res = $this->postJson('/api/part-invoices', [
            'supplier_name' => 'ABC Auto Parts', 'invoice_no' => $no, 'purchase_ids' => [$p->id],
        ]);
        $res->assertSuccessful();
        $id = $this->idOf($res);
        $this->postJson("/api/financial-documents/supplier-invoice/{$id}/approve")->assertSuccessful();

        return PartInvoice::findOrFail($id);
    }

    private function close(Maintenance $ticket): void
    {
        app(MaintenanceWorkflowService::class)->finalizeInvoice($ticket->fresh(), $this->admin);
    }

    // ── 1. Closure is gated ──────────────────────────────────────────────────────────────────────────

    public function test_a_ticket_cannot_close_over_a_part_with_no_supplier_invoice(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $part   = $this->purchase($ticket, $task);
        app(PartWorkflowService::class)->installPurchase($part, [], $this->admin);

        try {
            $this->close($ticket);
            $this->fail('A ticket closed while carrying spend with no document.');
        } catch (WorkflowTransitionException $e) {
            // The refusal must be actionable, not merely negative.
            $this->assertStringContainsString('no supplier invoice has been recorded', $e->getMessage());
            $this->assertStringContainsString('Record the supplier invoice', $e->getMessage());
        }

        $this->assertSame(Maintenance::WF_AWAITING_INVOICE, $ticket->fresh()->workflow_status);
    }

    public function test_recording_the_invoice_unblocks_the_close(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $part   = $this->purchase($ticket, $task);
        app(PartWorkflowService::class)->installPurchase($part, [], $this->admin);

        $this->approvedInvoiceFor($part);
        $this->close($ticket);

        $this->assertSame(Maintenance::WF_CLOSED, $ticket->fresh()->workflow_status);
    }

    public function test_an_adjustment_is_the_other_legitimate_way_past_the_gate(): void
    {
        $ticket = $this->ticket();
        $ticket->forceFill(['cost' => 900, 'cost_is_itemized' => false])->save();

        // A lump sum blocks the close…
        try {
            $this->close($ticket);
            $this->fail('A hand-typed lump sum passed the gate.');
        } catch (WorkflowTransitionException $e) {
            $this->assertStringContainsString('no lines and no document', $e->getMessage());
        }

        // …and explaining it with a signed, reasoned adjustment is a documented path, not a bypass.
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/adjustments", [
            'applies_to'  => CostAdjustment::APPLIES_OTHER,
            'direction'   => CostAdjustment::DIRECTION_DEBIT,
            'amount'      => 900,
            'reason_code' => CostAdjustment::REASON_KEYING_ERROR,
            'reason_note' => 'Legacy figure carried over from the paper log; no invoice was ever issued.',
        ])->assertSuccessful();

        // The adjustment itemises the ticket, so the lump sum is gone and the money now has a document.
        $ticket->refresh();
        $this->assertTrue((bool) $ticket->cost_is_itemized);
        $this->close($ticket);
        $this->assertSame(Maintenance::WF_CLOSED, $ticket->fresh()->workflow_status);
    }

    public function test_a_ticket_cannot_close_with_a_return_still_in_limbo(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $part   = $this->purchase($ticket, $task);
        $this->approvedInvoiceFor($part);
        app(PartWorkflowService::class)->installPurchase($part, [], $this->admin);

        // Sent back, money unresolved — the ticket is still carrying the full cost.
        $this->postJson("/api/part-purchases/{$part->id}/returns", [
            'reason_code' => PartReturn::REASON_FAULTY_PART,
            'status'      => PartReturn::STATUS_SENT,
        ])->assertSuccessful();

        try {
            $this->close($ticket);
            $this->fail('A ticket closed with an unsettled return.');
        } catch (WorkflowTransitionException $e) {
            $this->assertStringContainsString('refund has not been settled', $e->getMessage());
        }
    }

    public function test_a_ticket_cannot_close_on_an_unapproved_bill(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $part   = $this->purchase($ticket, $task);
        // Recorded but never approved — a draft is not an accepted obligation.
        $this->postJson('/api/part-invoices', [
            'supplier_name' => 'ABC Auto Parts', 'invoice_no' => 'INV-D', 'purchase_ids' => [$part->id],
        ])->assertSuccessful();
        app(PartWorkflowService::class)->installPurchase($part, [], $this->admin);

        try {
            $this->close($ticket);
            $this->fail('A ticket closed on an unapproved invoice.');
        } catch (WorkflowTransitionException $e) {
            $this->assertStringContainsString('still draft', $e->getMessage());
        }
    }

    public function test_an_unpaid_but_approved_bill_does_not_block_the_close(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $part   = $this->purchase($ticket, $task);
        $this->approvedInvoiceFor($part);   // approved, never paid
        app(PartWorkflowService::class)->installPurchase($part, [], $this->admin);

        $this->close($ticket);

        // Payment terms are the supplier's business, not the repair's — a warning, never a blocker.
        $this->assertSame(Maintenance::WF_CLOSED, $ticket->fresh()->workflow_status);
        $check = app(FinancialCompletenessService::class)->check($ticket->fresh());
        $this->assertTrue($check['complete']);
        $this->assertNotEmpty($check['warnings']);
        $this->assertSame('supplier_invoice_unpaid', $check['warnings'][0]['code']);
    }

    // ── 2. The hand-typed total is closed off ────────────────────────────────────────────────────────

    public function test_a_total_cannot_be_typed_over_an_itemised_ticket(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'is_internal' => true,
            'task_ids'    => [$task->id],
            'line_items'  => [['kind' => 'labor', 'description' => 'Fit', 'finding_text' => 'Brake noise', 'quantity' => 1, 'unit_price' => 150]],
        ])->assertSuccessful();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/cost", ['cost' => 999])
            ->assertStatus(422);

        $this->assertSame(150.0, round((float) $ticket->fresh()->cost, 2), 'The invoiced total must stand.');
    }

    // ── 3. The story ─────────────────────────────────────────────────────────────────────────────────

    public function test_the_financial_story_reads_in_order_from_diagnosis_to_closure(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $part   = $this->purchase($ticket, $task);
        $inv    = $this->approvedInvoiceFor($part);
        app(PartWorkflowService::class)->markDelivered($part, $this->admin);
        app(PartWorkflowService::class)->installPurchase($part->fresh(), [], $this->admin);
        $this->postJson("/api/financial-documents/supplier-invoice/{$inv->id}/pay")->assertSuccessful();
        $this->close($ticket);

        $res = $this->getJson("/api/maintenance-tickets/{$ticket->id}/financial-story");
        $res->assertSuccessful();
        $story = data_get($res->json(), 'data');

        $kinds = collect($story['timeline']['events'])->pluck('kind')->all();

        foreach ([
            'fault_diagnosed', 'part_requested', 'part_purchased', 'supplier_invoice_recorded',
            'invoice_approved', 'goods_received', 'part_installed', 'supplier_paid', 'ticket_closed',
        ] as $expected) {
            $this->assertContains($expected, $kinds, "The story is missing '{$expected}'.");
        }

        // Ordered oldest-first: a story is read forwards.
        $times = collect($story['timeline']['events'])->pluck('at')->filter()->values()->all();
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times);

        $this->assertTrue($story['complete']);
        $this->assertTrue($story['audit']['fully_traceable']);
    }

    public function test_the_story_names_what_is_blocking_before_anyone_presses_close(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $part   = $this->purchase($ticket, $task);
        app(PartWorkflowService::class)->installPurchase($part, [], $this->admin);

        $story = data_get($this->getJson("/api/maintenance-tickets/{$ticket->id}/financial-story")->json(), 'data');

        $this->assertFalse($story['can_close']);
        $this->assertSame('supplier_invoice_missing', $story['blockers'][0]['code']);
        $this->assertEquals(400.0, $story['blockers'][0]['amount']);
        $this->assertSame('/part-invoices', $story['blockers'][0]['route'], 'A blocker must say WHERE to fix it.');
    }

    public function test_a_refund_shows_as_money_coming_back(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $part   = $this->purchase($ticket, $task);
        $this->approvedInvoiceFor($part);
        app(PartWorkflowService::class)->installPurchase($part, [], $this->admin);
        $this->postJson("/api/part-purchases/{$part->id}/returns", [
            'reason_code' => PartReturn::REASON_WRONG_PART,
            'status'      => PartReturn::STATUS_REFUNDED,
        ])->assertSuccessful();

        $timeline = app(FinancialTimelineService::class)->forTicket($ticket->fresh());
        $refund = collect($timeline['events'])->firstWhere('kind', 'refund_received');

        $this->assertNotNull($refund);
        $this->assertSame(-400.0, $refund['signed_amount'], 'A refund reduces the ticket.');
        $this->assertSame(400.0, $timeline['totals']['money_out']);
        $this->assertSame(-400.0, $timeline['totals']['money_back']);
    }
}
