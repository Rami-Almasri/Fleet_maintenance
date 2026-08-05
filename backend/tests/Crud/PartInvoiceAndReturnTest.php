<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\MaintenanceTask;
use App\Models\PartInvoice;
use App\Models\PartPurchase;
use App\Models\PartReturn;
use App\Models\Vehicle;
use App\Services\PartReturnService;
use App\Services\TicketCostJourneyService;
use Illuminate\Support\Carbon;

/**
 * The ticket's financial flow, pinned end to end:
 *
 *     Fault → Required Part → Purchase Source → Invoice → Installation Cost → Final Ticket Cost
 *
 * The rules under test are the ones that would silently corrupt a ticket's cost if they ever regressed:
 *
 *  1. A SUPPLIER part carries its own invoice; a GARAGE part is refused one (it is already a line on the
 *     garage's maintenance invoice, so a second document would charge the ticket twice).
 *  2. A return never deletes the buy — it credits it, and the ticket nets out.
 *  3. Only a REFUNDED return moves money; requested/sent leave the cost where it is.
 *  4. A fault ruled incorrect accepts no NEW cost — but money already spent on it is never erased.
 */
class PartInvoiceAndReturnTest extends CrudTestCase
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
        return MaintenanceTask::create([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $ticket->vehicle_id,
            'symptom'        => $symptom,
            'status'         => MaintenanceTask::STATUS_PENDING,
        ]);
    }

    /**
     * A purchase, with the request behind it. PartInstalled now accepts a null request id (consumables and
     * garage-supplied parts have none), so this is no longer load-bearing for the dispatch — it stays
     * because these tests cover the PROCURED path, and a procured part really does come from a request.
     */
    private function purchase(Maintenance $ticket, ?MaintenanceTask $task, array $overrides = []): PartPurchase
    {
        $request = \App\Models\PartRequest::create([
            'source'              => \App\Models\PartRequest::SOURCE_GARAGE,
            'status'              => \App\Models\PartRequest::STATUS_APPROVED,
            'vehicle_id'          => $ticket->vehicle_id,
            'maintenance_id'      => $ticket->id,
            'maintenance_task_id' => $task?->id,
            'part_name'           => $overrides['part_name'] ?? 'Brake Pad Set',
            'quantity'            => $overrides['quantity'] ?? 1,
            'reason'              => 'Worn beyond limit',
            'requested_at'        => Carbon::now(),
        ]);

        return PartPurchase::create(array_merge([
            'part_request_id'     => $request->id,
            'vehicle_id'          => $ticket->vehicle_id,
            'maintenance_id'      => $ticket->id,
            'maintenance_task_id' => $task?->id,
            'part_name'           => 'Brake Pad Set',
            'part_number'         => 'BP-100',
            'purchase_source'     => PartPurchase::SOURCE_SUPPLIER,
            'source_name'         => 'ABC Auto Parts',
            'purchase_price'      => 400,
            'currency'            => 'AED',
            'quantity'            => 1,
            'purchased_at'        => Carbon::now(),
        ], $overrides));
    }

    // ── 1. The supplier invoice ──────────────────────────────────────────────────────────────────────

    public function test_supplier_invoice_covers_its_parts_and_derives_its_total(): void
    {
        $ticket = $this->ticket();
        $a = $this->purchase($ticket, null, ['part_name' => 'Brake Pad Set', 'purchase_price' => 400]);
        $b = $this->purchase($ticket, null, ['part_name' => 'Brake Fluid', 'purchase_price' => 50, 'quantity' => 2]);

        $res = $this->postJson('/api/part-invoices', [
            'supplier_name' => 'ABC Auto Parts',
            'invoice_no'    => 'INV-2026-001',
            'invoice_date'  => '2026-08-04',
            'purchase_ids'  => [$a->id, $b->id],
        ]);
        $res->assertSuccessful();

        // 400 + (50 × 2) = 500, derived from the attached parts, never keyed by hand.
        $this->assertEquals(500.0, data_get($res->json(), 'data.total_amount'));
        $this->assertCount(2, data_get($res->json(), 'data.items'));
        $this->assertSame($this->idOf($res), $a->fresh()->part_invoice_id);
    }

    public function test_garage_sourced_part_is_refused_a_supplier_invoice(): void
    {
        $ticket = $this->ticket();
        $garagePart = $this->purchase($ticket, null, ['purchase_source' => PartPurchase::SOURCE_GARAGE]);

        $res = $this->postJson('/api/part-invoices', [
            'supplier_name' => 'ABC Auto Parts',
            'purchase_ids'  => [$garagePart->id],
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('twice', (string) data_get($res->json(), 'message'));
        // And nothing was written — a rejected attach leaves no half-made invoice behind.
        $this->assertSame(0, PartInvoice::count());
        $this->assertNull($garagePart->fresh()->part_invoice_id);
    }

    public function test_a_part_cannot_be_billed_on_two_invoices(): void
    {
        $ticket   = $this->ticket();
        $purchase = $this->purchase($ticket, null);

        $this->postJson('/api/part-invoices', ['invoice_no' => 'INV-1', 'purchase_ids' => [$purchase->id]])
            ->assertSuccessful();

        $this->postJson('/api/part-invoices', ['invoice_no' => 'INV-2', 'purchase_ids' => [$purchase->id]])
            ->assertStatus(422);
    }

    public function test_printed_total_that_disagrees_needs_an_explanation(): void
    {
        $ticket   = $this->ticket();
        $purchase = $this->purchase($ticket, null); // 400

        $this->postJson('/api/part-invoices', [
            'invoice_no'   => 'INV-3',
            'purchase_ids' => [$purchase->id],
            'stated_total' => 450,
        ])->assertStatus(422);

        $this->postJson('/api/part-invoices', [
            'invoice_no'           => 'INV-3',
            'purchase_ids'         => [$purchase->id],
            'stated_total'         => 450,
            'variance_explanation' => 'Supplier added a 50 AED delivery charge.',
        ])->assertSuccessful();
    }

    public function test_deleting_an_invoice_keeps_the_purchases(): void
    {
        $ticket   = $this->ticket();
        $purchase = $this->purchase($ticket, null);

        $res = $this->postJson('/api/part-invoices', ['invoice_no' => 'INV-4', 'purchase_ids' => [$purchase->id]]);
        $this->deleteJson('/api/part-invoices/' . $this->idOf($res))->assertSuccessful();

        $this->assertNotNull($purchase->fresh(), 'Deleting paper must never delete the spend.');
        $this->assertNull($purchase->fresh()->part_invoice_id);
    }

    // ── 2. Returns ───────────────────────────────────────────────────────────────────────────────────

    public function test_a_refunded_return_credits_the_ticket_and_nets_to_zero(): void
    {
        $ticket   = $this->ticket();
        $task     = $this->fault($ticket);
        $purchase = $this->purchase($ticket, $task);

        // Fit it: the purchase becomes a +400 line on the ticket.
        app(\App\Services\PartWorkflowService::class)->installPurchase($purchase, [], $this->admin);
        $this->assertSame(400.0, round((float) $ticket->fresh()->cost, 2));

        $res = $this->postJson("/api/part-purchases/{$purchase->id}/returns", [
            'reason_code' => PartReturn::REASON_WRONG_PART,
            'status'      => PartReturn::STATUS_REFUNDED,
        ]);
        $res->assertSuccessful();

        // The buy still exists, a credit line was written, and the ticket nets to zero.
        $this->assertNotNull($purchase->fresh());
        $this->assertSame(400.0, $purchase->fresh()->grossCost());
        $this->assertSame(0.0, $purchase->fresh()->netCost());
        $this->assertSame(0.0, round((float) $ticket->fresh()->cost, 2));
        $this->assertSame(2, MaintenanceLineItem::where('maintenance_id', $ticket->id)->count());
    }

    public function test_a_return_that_has_not_been_refunded_moves_no_money(): void
    {
        $ticket   = $this->ticket();
        $task     = $this->fault($ticket);
        $purchase = $this->purchase($ticket, $task);
        app(\App\Services\PartWorkflowService::class)->installPurchase($purchase, [], $this->admin);

        $this->postJson("/api/part-purchases/{$purchase->id}/returns", [
            'reason_code' => PartReturn::REASON_FAULTY_PART,
        ])->assertSuccessful();

        // Logged, not refunded — the money hasn't come back, so the ticket still carries the full cost.
        $this->assertSame(400.0, round((float) $ticket->fresh()->cost, 2));
        $this->assertSame(400.0, $purchase->fresh()->netCost());
    }

    public function test_a_restocking_fee_stays_in_the_ticket_cost(): void
    {
        $ticket   = $this->ticket();
        $task     = $this->fault($ticket);
        $purchase = $this->purchase($ticket, $task);
        app(\App\Services\PartWorkflowService::class)->installPurchase($purchase, [], $this->admin);

        $this->postJson("/api/part-purchases/{$purchase->id}/returns", [
            'reason_code'    => PartReturn::REASON_NOT_NEEDED,
            'restocking_fee' => 40,
            'status'         => PartReturn::STATUS_REFUNDED,
        ])->assertSuccessful();

        // 400 paid, 360 back, 40 kept by the supplier — and the 40 remains real cost on the ticket.
        $this->assertSame(40.0, round((float) $ticket->fresh()->cost, 2));
        $this->assertSame(40.0, $purchase->fresh()->netCost());
    }

    public function test_a_rejected_return_reverses_its_credit(): void
    {
        $ticket   = $this->ticket();
        $task     = $this->fault($ticket);
        $purchase = $this->purchase($ticket, $task);
        app(\App\Services\PartWorkflowService::class)->installPurchase($purchase, [], $this->admin);

        $return = app(PartReturnService::class)->create(
            $purchase,
            ['reason_code' => PartReturn::REASON_WRONG_PART, 'status' => PartReturn::STATUS_REFUNDED],
            $this->admin,
        );
        $this->assertSame(0.0, round((float) $ticket->fresh()->cost, 2));

        $this->postJson("/api/part-returns/{$return->id}/reject", ['reason' => 'Past the 7-day window.'])
            ->assertSuccessful();

        // The supplier refused it, so the cost comes back to us in full.
        $this->assertSame(400.0, round((float) $ticket->fresh()->cost, 2));
        $this->assertNull($return->fresh()->credit_line_item_id);
    }

    public function test_cannot_return_more_than_was_bought(): void
    {
        $ticket   = $this->ticket();
        $purchase = $this->purchase($ticket, null, ['quantity' => 2]);

        $this->postJson("/api/part-purchases/{$purchase->id}/returns", [
            'reason_code' => PartReturn::REASON_OVER_ORDERED,
            'quantity'    => 3,
        ])->assertStatus(422);
    }

    public function test_cannot_be_refunded_more_than_was_paid(): void
    {
        $ticket   = $this->ticket();
        $purchase = $this->purchase($ticket, null); // 400

        $this->postJson("/api/part-purchases/{$purchase->id}/returns", [
            'reason_code'   => PartReturn::REASON_WRONG_PART,
            'refund_amount' => 900,
        ])->assertStatus(422);
    }

    // ── 3. Incorrect faults ──────────────────────────────────────────────────────────────────────────

    public function test_an_incorrect_fault_cannot_be_put_on_an_invoice(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $ticket->update(['findings' => [['text' => 'Brake noise']]]);
        $task->forceFill(['marked_incorrect_at' => Carbon::now(), 'incorrect_reason' => 'Noise was the tyres.'])->save();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'is_internal' => true,
            'task_ids'    => [$task->id],
        ])->assertStatus(422);
    }

    public function test_an_incorrect_fault_cannot_take_a_new_cost_line(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $ticket->update(['findings' => [['text' => 'Brake noise']]]);
        $task->forceFill(['marked_incorrect_at' => Carbon::now()])->save();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'is_internal' => true,
            'line_items'  => [[
                'kind' => 'labor', 'description' => 'Diagnosis', 'finding_text' => 'Brake noise',
                'quantity' => 1, 'unit_price' => 150,
            ]],
        ])->assertStatus(422);
    }

    public function test_an_incorrect_fault_cannot_have_a_part_fitted_to_it(): void
    {
        $ticket   = $this->ticket();
        $task     = $this->fault($ticket);
        $purchase = $this->purchase($ticket, $task);
        $task->forceFill(['marked_incorrect_at' => Carbon::now()])->save();

        $this->postJson("/api/part-purchases/{$purchase->id}/install", [])->assertStatus(422);
    }

    public function test_money_spent_before_the_ruling_is_kept_and_reported_separately(): void
    {
        $ticket   = $this->ticket();
        $task     = $this->fault($ticket);
        $purchase = $this->purchase($ticket, $task);

        // Bought and fitted while the diagnosis still stood…
        app(\App\Services\PartWorkflowService::class)->installPurchase($purchase, [], $this->admin);
        // …and only THEN ruled a mis-diagnosis.
        $task->forceFill(['marked_incorrect_at' => Carbon::now(), 'incorrect_reason' => 'Noise was the tyres.'])->save();

        $journey = app(TicketCostJourneyService::class)->build($ticket->fresh());

        // The money is still on the ticket — it really left the company…
        $this->assertSame(400.0, round((float) $ticket->fresh()->cost, 2));
        $this->assertSame(400.0, $journey['totals']['net_total']);
        // …and it is reported under its own heading so diagnosis quality stays measurable.
        $this->assertSame(400.0, $journey['totals']['incorrect_fault_cost']['amount']);
        $this->assertSame(1, $journey['totals']['incorrect_fault_cost']['count']);
    }

    // ── 4. The journey ───────────────────────────────────────────────────────────────────────────────

    public function test_the_journey_separates_supplier_parts_from_garage_labour(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $ticket->update(['findings' => [['text' => 'Brake noise']]]);

        // Part from the supplier (400) …
        $purchase = $this->purchase($ticket, $task);
        $this->postJson('/api/part-invoices', [
            'supplier_name' => 'ABC Auto Parts',
            'invoice_no'    => 'INV-2026-001',
            'invoice_date'  => '2026-08-04',
            'purchase_ids'  => [$purchase->id],
        ])->assertSuccessful();
        app(\App\Services\PartWorkflowService::class)->installPurchase($purchase, [], $this->admin);

        // … fitted by the garage, which billed labour (150).
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'vendor_id'  => $this->makeVendor(),
            'invoice_no' => 'G-77',
            'task_ids'   => [$task->id],
            'line_items' => [[
                'kind' => 'labor', 'description' => 'Fit brake pads', 'finding_text' => 'Brake noise',
                'quantity' => 1, 'unit_price' => 150,
            ]],
        ])->assertSuccessful();

        $journey = app(TicketCostJourneyService::class)->build($ticket->fresh());

        $this->assertSame(400.0, $journey['totals']['supplier_parts']);
        $this->assertSame(0.0,   $journey['totals']['garage_parts']);
        $this->assertSame(150.0, $journey['totals']['labour']);
        $this->assertSame(550.0, $journey['totals']['net_total']);

        // The fault carries the supplier's paper, so the ticket can prove where the 400 came from.
        $part = $journey['faults'][0]['parts'][0];
        $this->assertSame('supplier', $part['origin']);
        $this->assertSame('INV-2026-001', $part['invoice']['invoice_no']);
        $this->assertSame('ABC Auto Parts', $part['invoice']['supplier']);
    }

    public function test_a_garage_supplied_part_counts_once_as_a_garage_part(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $ticket->update(['findings' => [['text' => 'Brake noise']]]);

        // The garage supplied the part AND the labour — one invoice, two lines, no part invoice anywhere.
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'vendor_id'  => $this->makeVendor(),
            'invoice_no' => 'G-88',
            'task_ids'   => [$task->id],
            'line_items' => [
                ['kind' => 'part',  'description' => 'Brake Pad Set', 'finding_text' => 'Brake noise', 'quantity' => 1, 'unit_price' => 400],
                ['kind' => 'labor', 'description' => 'Fit brake pads', 'finding_text' => 'Brake noise', 'quantity' => 1, 'unit_price' => 150],
            ],
        ])->assertSuccessful();

        $journey = app(TicketCostJourneyService::class)->build($ticket->fresh());

        $this->assertSame(0.0,   $journey['totals']['supplier_parts']);
        $this->assertSame(400.0, $journey['totals']['garage_parts']);
        $this->assertSame(550.0, $journey['totals']['net_total'], 'The part must be counted once, not twice.');
        $this->assertSame('garage_invoice', $journey['faults'][0]['parts'][0]['origin']);
    }

    public function test_a_bought_but_unfitted_part_is_committed_not_ticket_cost(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $this->purchase($ticket, $task); // bought, never installed

        $journey = app(TicketCostJourneyService::class)->build($ticket->fresh());

        $this->assertSame(0.0,   $journey['totals']['net_total'], 'An unfitted part is not ticket cost yet.');
        $this->assertSame(400.0, $journey['totals']['committed']);
        $this->assertCount(1, $journey['faults'][0]['awaiting_installation']);
    }

    public function test_a_supplier_purchase_with_no_invoice_is_flagged(): void
    {
        $ticket   = $this->ticket();
        $task     = $this->fault($ticket);
        $this->purchase($ticket, $task);

        $journey = app(TicketCostJourneyService::class)->build($ticket->fresh());

        $this->assertTrue($journey['faults'][0]['awaiting_installation'][0]['invoice_missing']);
    }
}
