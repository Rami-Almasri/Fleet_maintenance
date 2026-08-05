<?php

namespace Tests\Crud;

use App\Models\CostAdjustment;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\PartInvoice;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\PartReturn;
use App\Services\CostSourceResolver;
use App\Services\PartWorkflowService;
use App\Services\TicketCostJourneyService;
use Illuminate\Support\Carbon;

/**
 * The ticket's financial contract, pinned:
 *
 *  1. SIX BANDS — parts, labour, VAT, discounts, refunds, net total — and they always sum to the ticket.
 *  2. TWO EVENTS — a supplier's invoice and a garage's invoice stay separate and both stay visible.
 *  3. MANY INVOICES — several suppliers plus a garage still produce ONE summary.
 *  4. ALLOCATION — a supplier invoice covering several tickets contributes only this ticket's share.
 *  5. RETURNS TOUCH PARTS ONLY — labour can fall solely through an approved adjustment.
 *  6. EVERY DIRHAM IS TRACEABLE — or the audit says which one isn't.
 */
class TicketFinancialBandsTest extends CrudTestCase
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
        $ticket->update(['findings' => array_merge($ticket->findings ?? [], [['text' => $symptom]])]);

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
            'quantity'            => $overrides['quantity'] ?? 1,
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

    private function journey(Maintenance $ticket): array
    {
        return app(TicketCostJourneyService::class)->build($ticket->fresh());
    }

    // ── 1. The six bands ─────────────────────────────────────────────────────────────────────────────

    public function test_the_six_bands_always_sum_to_the_ticket_total(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'vendor_id'       => $this->makeVendor(),
            'invoice_no'      => 'G-1',
            'task_ids'        => [$task->id],
            'line_items'      => [
                ['kind' => 'part',  'description' => 'Brake Pad Set', 'finding_text' => 'Brake noise', 'quantity' => 1, 'unit_price' => 400],
                ['kind' => 'labor', 'description' => 'Fit pads',      'finding_text' => 'Brake noise', 'quantity' => 1, 'unit_price' => 150],
            ],
            'vat_amount'      => 27.5,
            'discount_amount' => 50,
        ])->assertSuccessful();

        $t = $this->journey($ticket)['totals'];

        $this->assertSame(400.0, $t['parts']);
        $this->assertSame(150.0, $t['labour']);
        $this->assertSame(27.5,  $t['vat']);
        $this->assertSame(-50.0, $t['discounts']);
        $this->assertSame(0.0,   $t['refunds']);

        // 400 + 150 + 27.50 − 50 = 527.50, and the ticket itself agrees.
        $this->assertSame(527.5, $t['net_total']);
        $this->assertSame(527.5, round((float) $ticket->fresh()->cost, 2));
    }

    public function test_vat_and_discount_are_ledger_lines_so_they_carry_their_invoice(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'vendor_id'  => $this->makeVendor(),
            'invoice_no' => 'G-2',
            'task_ids'   => [$task->id],
            'line_items' => [['kind' => 'labor', 'description' => 'Fit', 'finding_text' => 'Brake noise', 'quantity' => 1, 'unit_price' => 100]],
            'vat_amount' => 5,
        ])->assertSuccessful();

        $journey = $this->journey($ticket);
        $vatLine = collect($journey['general']['charges'])->firstWhere('kind_raw', 'vat');

        $this->assertNotNull($vatLine, 'VAT must exist as a real ledger row, not a header field.');
        $this->assertSame('garage_invoice', $vatLine['source']['type']);
        $this->assertTrue($vatLine['source']['traceable']);
        // And the ticket is still fully auditable with VAT on it.
        $this->assertTrue($journey['audit']['fully_traceable']);
    }

    public function test_editing_lines_without_touching_vat_keeps_the_vat(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'is_internal' => true,
            'task_ids'    => [$task->id],
            'line_items'  => [['kind' => 'labor', 'description' => 'Fit', 'finding_text' => 'Brake noise', 'quantity' => 1, 'unit_price' => 100]],
            'vat_amount'  => 5,
        ]);
        $res->assertSuccessful();
        $invoiceId = data_get($res->json(), 'data.invoices.0.id');

        $this->postJson("/api/maintenance-invoices/{$invoiceId}", [
            'line_items' => [['kind' => 'labor', 'description' => 'Fit', 'finding_text' => 'Brake noise', 'quantity' => 1, 'unit_price' => 120]],
        ])->assertSuccessful();

        $this->assertSame(5.0, $this->journey($ticket)['totals']['vat'], 'A line edit must not silently drop VAT.');
        $this->assertSame(125.0, round((float) $ticket->fresh()->cost, 2));
    }

    // ── 2 & 3. Two events, many invoices, one summary ────────────────────────────────────────────────

    public function test_several_suppliers_plus_a_garage_produce_one_summary(): void
    {
        $ticket = $this->ticket();
        $brakes = $this->fault($ticket, 'Brake noise');
        $sensor = $this->fault($ticket, 'Sensor fault');

        // Supplier A — brake pads.
        $pads = $this->purchase($ticket, $brakes, ['part_name' => 'Brake Pad Set', 'purchase_price' => 400]);
        $this->postJson('/api/part-invoices', [
            'supplier_name' => 'Supplier A', 'invoice_no' => 'A-1', 'purchase_ids' => [$pads->id],
        ])->assertSuccessful();
        app(PartWorkflowService::class)->installPurchase($pads, [], $this->admin);

        // Supplier B — sensors.
        $sens = $this->purchase($ticket, $sensor, ['part_name' => 'ABS Sensor', 'purchase_price' => 220]);
        $this->postJson('/api/part-invoices', [
            'supplier_name' => 'Supplier B', 'invoice_no' => 'B-1', 'purchase_ids' => [$sens->id],
        ])->assertSuccessful();
        app(PartWorkflowService::class)->installPurchase($sens, [], $this->admin);

        // Garage C — labour on both.
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'vendor_id'  => $this->makeVendor(),
            'invoice_no' => 'C-9',
            'task_ids'   => [$brakes->id, $sensor->id],
            'line_items' => [
                ['kind' => 'labor', 'description' => 'Fit pads',   'finding_text' => 'Brake noise',  'quantity' => 1, 'unit_price' => 150],
                ['kind' => 'labor', 'description' => 'Fit sensor', 'finding_text' => 'Sensor fault', 'quantity' => 1, 'unit_price' => 90],
            ],
        ])->assertSuccessful();

        $journey = $this->journey($ticket);

        $this->assertCount(2, $journey['invoices']['supplier'], 'Both supplier bills must show.');
        $this->assertCount(1, $journey['invoices']['garage']);
        $this->assertSame(620.0, $journey['totals']['supplier_parts']);
        $this->assertSame(240.0, $journey['totals']['labour']);
        $this->assertSame(860.0, $journey['totals']['net_total']);
        $this->assertTrue($journey['audit']['fully_traceable']);
    }

    // ── 4. Allocation across tickets ─────────────────────────────────────────────────────────────────

    public function test_a_supplier_invoice_shared_by_two_tickets_allocates_only_this_ticket_s_share(): void
    {
        $mine   = $this->ticket();
        $theirs = $this->ticket();
        $task   = $this->fault($mine);

        $minePart   = $this->purchase($mine, $task, ['part_name' => 'Brake Pad Set', 'purchase_price' => 400]);
        $theirsPart = $this->purchase($theirs, null, ['part_name' => 'Oil Filter', 'purchase_price' => 600]);

        // ONE document covering both cars — recorded once, never duplicated per ticket.
        $this->postJson('/api/part-invoices', [
            'supplier_name' => 'Shared Supplier',
            'invoice_no'    => 'SHARED-1',
            'purchase_ids'  => [$minePart->id, $theirsPart->id],
        ])->assertSuccessful();

        $this->assertSame(1, PartInvoice::count(), 'The invoice exists once, independent of any ticket.');

        app(PartWorkflowService::class)->installPurchase($minePart, [], $this->admin);
        $journey = $this->journey($mine);
        $invoice = $journey['invoices']['supplier'][0];

        $this->assertSame(1000.0, $invoice['total'], 'The document total is shown as context…');
        $this->assertSame(400.0,  $invoice['allocated'], '…but only this ticket’s share is its money.');
        $this->assertTrue($invoice['shared']);
        $this->assertSame([$theirs->id], $invoice['shared_with_ticket_ids']);
        $this->assertSame(400.0, $journey['totals']['net_total'], 'The other car’s 600 must never land here.');
    }

    // ── 5. Returns touch parts only ──────────────────────────────────────────────────────────────────

    public function test_a_return_reduces_parts_and_never_labour(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $part   = $this->purchase($ticket, $task);
        app(PartWorkflowService::class)->installPurchase($part, [], $this->admin);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'vendor_id'  => $this->makeVendor(),
            'task_ids'   => [$task->id],
            'line_items' => [['kind' => 'labor', 'description' => 'Fit', 'finding_text' => 'Brake noise', 'quantity' => 1, 'unit_price' => 150]],
        ])->assertSuccessful();

        $this->postJson("/api/part-purchases/{$part->id}/returns", [
            'reason_code' => PartReturn::REASON_WRONG_PART,
            'status'      => PartReturn::STATUS_REFUNDED,
        ])->assertSuccessful();

        $t = $this->journey($ticket)['totals'];

        $this->assertSame(400.0,  $t['parts'], 'The part was still charged at 400.');
        $this->assertSame(-400.0, $t['parts_returned']);
        $this->assertSame(0.0,    $t['labour_refunded'], 'A part return must NEVER touch labour.');
        $this->assertSame(150.0,  $t['labour'], 'Labour is untouched by the return.');
        $this->assertSame(150.0,  $t['net_total']);
    }

    public function test_labour_only_falls_through_an_approved_adjustment(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'vendor_id'  => $this->makeVendor(),
            'task_ids'   => [$task->id],
            'line_items' => [['kind' => 'labor', 'description' => 'Fit', 'finding_text' => 'Brake noise', 'quantity' => 1, 'unit_price' => 150]],
        ])->assertSuccessful();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/adjustments", [
            'applies_to'  => CostAdjustment::APPLIES_LABOUR,
            'direction'   => CostAdjustment::DIRECTION_CREDIT,
            'amount'      => 50,
            'reason_code' => CostAdjustment::REASON_LABOUR_REFUND,
            'reason_note' => 'Garage agreed to refund an hour it did not work.',
        ])->assertSuccessful();

        $t = $this->journey($ticket)['totals'];

        $this->assertSame(-50.0, $t['labour_refunded']);
        $this->assertSame(100.0, $t['net_total']);
        $this->assertTrue($this->journey($ticket)['audit']['fully_traceable']);
    }

    public function test_an_adjustment_without_an_explanation_is_refused(): void
    {
        $ticket = $this->ticket();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/adjustments", [
            'applies_to'  => CostAdjustment::APPLIES_LABOUR,
            'direction'   => CostAdjustment::DIRECTION_CREDIT,
            'amount'      => 50,
            'reason_code' => CostAdjustment::REASON_LABOUR_REFUND,
            'reason_note' => '',
        ])->assertStatus(422);
    }

    public function test_reversing_an_adjustment_keeps_the_record_and_removes_the_money(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'is_internal' => true,
            'task_ids'    => [$task->id],
            'line_items'  => [['kind' => 'labor', 'description' => 'Fit', 'finding_text' => 'Brake noise', 'quantity' => 1, 'unit_price' => 150]],
        ])->assertSuccessful();

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/adjustments", [
            'applies_to'  => CostAdjustment::APPLIES_LABOUR,
            'direction'   => CostAdjustment::DIRECTION_CREDIT,
            'amount'      => 50,
            'reason_code' => CostAdjustment::REASON_GOODWILL,
            'reason_note' => 'Agreed with the garage manager.',
        ]);
        $id = $this->idOf($res);
        $this->assertSame(100.0, round((float) $ticket->fresh()->cost, 2));

        $this->postJson("/api/cost-adjustments/{$id}/reverse", ['reason' => 'Garage went back on it.'])
            ->assertSuccessful();

        $this->assertSame(150.0, round((float) $ticket->fresh()->cost, 2));
        $this->assertNotNull(CostAdjustment::find($id), 'The record must survive its reversal.');
    }

    // ── 6. Traceability ──────────────────────────────────────────────────────────────────────────────

    public function test_a_hand_typed_lump_sum_is_reported_as_untraceable(): void
    {
        $ticket = $this->ticket();
        $ticket->forceFill(['cost' => 900, 'cost_is_itemized' => false])->save();

        $audit = app(CostSourceResolver::class)->auditTicket($ticket->fresh());

        $this->assertFalse($audit['fully_traceable']);
        $this->assertSame(900.0, $audit['untraceable']);
        $this->assertSame('lump_sum', $audit['untraceable_items'][0]['kind']);
    }

    public function test_a_supplier_part_with_no_invoice_is_reported_as_untraceable(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $part   = $this->purchase($ticket, $task);          // bought, no invoice keyed
        app(PartWorkflowService::class)->installPurchase($part, [], $this->admin);

        $audit = $this->journey($ticket)['audit'];

        $this->assertFalse($audit['fully_traceable']);
        $this->assertSame(400.0, $audit['untraceable']);
        $this->assertStringContainsString('no supplier invoice', $audit['untraceable_items'][0]['why']);
    }

    public function test_recording_the_invoice_makes_the_same_money_traceable(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $part   = $this->purchase($ticket, $task);
        app(PartWorkflowService::class)->installPurchase($part, [], $this->admin);

        $this->postJson('/api/part-invoices', [
            'supplier_name' => 'ABC Auto Parts', 'invoice_no' => 'INV-9', 'purchase_ids' => [$part->id],
        ])->assertSuccessful();

        $audit = $this->journey($ticket->fresh())['audit'];

        $this->assertTrue($audit['fully_traceable']);
        $this->assertSame(100.0, $audit['coverage_pct']);
        $this->assertSame(400.0, $audit['by_source'][CostSourceResolver::SOURCE_SUPPLIER_INVOICE]);
    }

    public function test_every_line_names_the_document_behind_it(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $part   = $this->purchase($ticket, $task);
        $this->postJson('/api/part-invoices', [
            'supplier_name' => 'ABC Auto Parts', 'invoice_no' => 'INV-7', 'purchase_ids' => [$part->id],
        ])->assertSuccessful();
        app(PartWorkflowService::class)->installPurchase($part, [], $this->admin);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'vendor_id'  => $this->makeVendor(),
            'invoice_no' => 'G-5',
            'task_ids'   => [$task->id],
            'line_items' => [['kind' => 'labor', 'description' => 'Fit', 'finding_text' => 'Brake noise', 'quantity' => 1, 'unit_price' => 150]],
        ])->assertSuccessful();

        $this->postJson("/api/part-purchases/{$part->id}/returns", [
            'reason_code' => PartReturn::REASON_FAULTY_PART,
            'status'      => PartReturn::STATUS_REFUNDED,
        ])->assertSuccessful();

        $journey = $this->journey($ticket);
        $fault   = $journey['faults'][0];

        $types = collect($fault['parts'])->merge($fault['labour'])->pluck('source.type')->all();
        $this->assertContains(CostSourceResolver::SOURCE_SUPPLIER_INVOICE, $types);
        $this->assertContains(CostSourceResolver::SOURCE_GARAGE_INVOICE, $types);
        $this->assertContains(CostSourceResolver::SOURCE_CREDIT_NOTE, $types);

        // Not one figure on the ticket is without a document.
        $this->assertTrue($journey['audit']['fully_traceable']);
        foreach (collect($fault['parts'])->merge($fault['labour']) as $row) {
            $this->assertTrue($row['source']['traceable'], "{$row['description']} has no source document.");
            $this->assertNotNull($row['source']['reference']);
        }
    }
}
