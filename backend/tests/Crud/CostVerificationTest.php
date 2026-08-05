<?php

namespace Tests\Crud;

use App\Models\CostAdjustment;
use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\MaintenanceTask;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\PartReturn;
use App\Services\CostSourceResolver;
use App\Services\CostVerificationService;
use App\Services\PartWorkflowService;
use Illuminate\Support\Carbon;

/**
 * The structured origin and the legacy migration metric.
 *
 *  1. EVERY AMOUNT CARRIES ITS ORIGIN as a column, not as a read-time lookup — so spend can be reported
 *     by category and by document with one grouped query.
 *  2. THE ORIGIN STAYS TRUE. Attaching an invoice later fills it in; detaching clears it. A line must
 *     never point at a document that no longer backs it.
 *  3. LEGACY IS NOT FAKED. Pre-document cost is marked, counted separately from post-rules failures, and
 *     migrates to verified only when a real document or an approved adjustment explains it.
 */
class CostVerificationTest extends CrudTestCase
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
            'part_name'           => 'Brake Pad Set',
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
            'category_key'        => 'brakes',
            'purchase_source'     => PartPurchase::SOURCE_SUPPLIER,
            'source_name'         => 'ABC Auto Parts',
            'purchase_price'      => 400,
            'currency'            => 'AED',
            'quantity'            => 1,
            'purchased_at'        => Carbon::now(),
        ], $overrides));
    }

    // ── 1. The origin is a column ────────────────────────────────────────────────────────────────────

    public function test_a_garage_invoice_line_carries_its_invoice_as_a_structured_origin(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'is_internal' => true,
            'task_ids'    => [$task->id],
            'line_items'  => [['kind' => 'labor', 'description' => 'Fit', 'finding_text' => 'Brake noise', 'quantity' => 1, 'unit_price' => 150]],
            'vat_amount'  => 7.5,
        ]);
        $res->assertSuccessful();
        $invoiceId = data_get($res->json(), 'data.invoices.0.id');

        // Both the work line AND the VAT line name the bill they came from.
        foreach (MaintenanceLineItem::where('maintenance_id', $ticket->id)->get() as $line) {
            $this->assertSame(CostSourceResolver::SOURCE_GARAGE_INVOICE, $line->source_type, $line->description);
            $this->assertSame((int) $invoiceId, (int) $line->source_id);
        }
    }

    public function test_a_return_credit_and_an_adjustment_each_name_their_own_document(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $part   = $this->purchase($ticket, $task);
        app(PartWorkflowService::class)->installPurchase($part, [], $this->admin);

        $this->postJson("/api/part-purchases/{$part->id}/returns", [
            'reason_code' => PartReturn::REASON_WRONG_PART,
            'status'      => PartReturn::STATUS_REFUNDED,
        ])->assertSuccessful();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/adjustments", [
            'applies_to'  => CostAdjustment::APPLIES_OTHER,
            'direction'   => CostAdjustment::DIRECTION_DEBIT,
            'amount'      => 25,
            'reason_code' => CostAdjustment::REASON_GOODWILL,
            'reason_note' => 'Agreed handling charge.',
        ])->assertSuccessful();

        $types = MaintenanceLineItem::where('maintenance_id', $ticket->id)->pluck('source_type')->filter()->all();

        $this->assertContains(CostSourceResolver::SOURCE_CREDIT_NOTE, $types);
        $this->assertContains(CostSourceResolver::SOURCE_ADJUSTMENT, $types);
    }

    // ── 2. The origin stays true ─────────────────────────────────────────────────────────────────────

    public function test_recording_the_invoice_later_fills_in_the_origin(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $part   = $this->purchase($ticket, $task);

        // Fitted BEFORE the paper arrived — the line starts with no origin, and that is honest.
        app(PartWorkflowService::class)->installPurchase($part, [], $this->admin);
        $line = MaintenanceLineItem::findOrFail($part->fresh()->maintenance_line_item_id);
        $this->assertNull($line->source_type, 'A purchase is not itself a document.');

        $res = $this->postJson('/api/part-invoices', [
            'supplier_name' => 'ABC Auto Parts', 'invoice_no' => 'INV-1', 'purchase_ids' => [$part->id],
        ]);
        $res->assertSuccessful();

        $line->refresh();
        $this->assertSame(CostSourceResolver::SOURCE_SUPPLIER_INVOICE, $line->source_type);
        $this->assertSame($this->idOf($res), (int) $line->source_id);
    }

    public function test_detaching_a_part_from_an_invoice_clears_the_origin(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);
        $part   = $this->purchase($ticket, $task);
        app(PartWorkflowService::class)->installPurchase($part, [], $this->admin);

        $res = $this->postJson('/api/part-invoices', [
            'supplier_name' => 'ABC Auto Parts', 'invoice_no' => 'INV-2', 'purchase_ids' => [$part->id],
        ]);
        $invoiceId = $this->idOf($res);

        // Take the part off the invoice — the line must stop claiming that document backs it.
        $this->postJson("/api/part-invoices/{$invoiceId}", ['purchase_ids' => []])->assertSuccessful();

        $line = MaintenanceLineItem::findOrFail($part->fresh()->maintenance_line_item_id);
        $this->assertNull($line->source_type, 'A line must never point at a document that no longer backs it.');
    }

    // ── 3. Legacy is marked, never faked ─────────────────────────────────────────────────────────────

    public function test_legacy_and_post_rules_failures_are_counted_separately(): void
    {
        // Pre-document cost: marked as legacy, a known backlog.
        $legacy = $this->ticket();
        $legacy->forceFill([
            'cost' => 900, 'cost_is_itemized' => false,
            'cost_legacy_at' => Carbon::now(), 'cost_legacy_note' => 'Pre-document era.',
        ])->save();

        // The same failure WITHOUT the legacy stamp is a live problem, not a historical one.
        $fresh = $this->ticket();
        $fresh->forceFill(['cost' => 500, 'cost_is_itemized' => false])->save();

        $svc = app(CostVerificationService::class);

        $this->assertSame(CostVerificationService::STATE_LEGACY, $svc->stateOf($legacy->fresh())['state']);
        $this->assertSame(CostVerificationService::STATE_UNVERIFIED, $svc->stateOf($fresh->fresh())['state']);

        $summary = $svc->fleetSummary();
        $this->assertSame(900.0, $summary['legacy_cost']);
        $this->assertSame(500.0, $summary['unverified_cost'], 'A post-rules gap must not hide in the backlog.');
        $this->assertSame(0.0, $summary['verified_cost']);
    }

    public function test_an_approved_adjustment_migrates_legacy_cost_to_verified(): void
    {
        $ticket = $this->ticket();
        $ticket->forceFill([
            'cost' => 900, 'cost_is_itemized' => false, 'cost_legacy_at' => Carbon::now(),
        ])->save();

        $svc = app(CostVerificationService::class);
        $this->assertSame(CostVerificationService::STATE_LEGACY, $svc->stateOf($ticket->fresh())['state']);
        $this->assertSame(900.0, $svc->fleetSummary()['legacy_cost']);

        // Explaining it with a reason, an explanation and an approver is the legitimate migration path.
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/adjustments", [
            'applies_to'  => CostAdjustment::APPLIES_OTHER,
            'direction'   => CostAdjustment::DIRECTION_DEBIT,
            'amount'      => 900,
            'reason_code' => CostAdjustment::REASON_KEYING_ERROR,
            'reason_note' => 'Legacy paper-log figure; no invoice was ever issued for this repair.',
        ])->assertSuccessful();

        $after = $svc->fleetSummary();
        $this->assertSame(CostVerificationService::STATE_VERIFIED, $svc->stateOf($ticket->fresh())['state']);
        $this->assertSame(900.0, $after['verified_cost']);
        $this->assertSame(0.0, $after['legacy_cost'], 'Migrated money leaves the backlog.');
        $this->assertSame(100.0, $after['coverage_pct']);
    }

    public function test_the_migration_queue_is_ordered_by_undocumented_value(): void
    {
        foreach ([300, 1200, 700] as $cost) {
            $t = $this->ticket();
            $t->forceFill(['cost' => $cost, 'cost_is_itemized' => false, 'cost_legacy_at' => Carbon::now()])->save();
        }

        $queue = app(CostVerificationService::class)->migrationQueue(10);

        $this->assertSame([1200.0, 700.0, 300.0], collect($queue)->pluck('unverified')->all());
        $this->assertNotNull($queue[0]['reason'], 'The queue must say WHY each ticket is unverified.');
    }

    public function test_the_snapshot_records_the_trend(): void
    {
        $t = $this->ticket();
        $t->forceFill(['cost' => 900, 'cost_is_itemized' => false, 'cost_legacy_at' => Carbon::now()])->save();

        $snapshot = app(CostVerificationService::class)->snapshot();

        $this->assertSame(900.0, (float) $snapshot->total_cost);
        $this->assertSame(900.0, (float) $snapshot->legacy_cost);
        $this->assertSame(0.0, (float) $snapshot->coverage_pct);
    }

    // ── 4. The reporting question this all exists for ────────────────────────────────────────────────

    public function test_spend_by_category_separates_documented_from_undocumented(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'is_internal' => true,
            'task_ids'    => [$task->id],
            'line_items'  => [[
                'kind' => 'part', 'description' => 'Brake Pad Set', 'finding_text' => 'Brake noise',
                'category_key' => 'brakes', 'quantity' => 1, 'unit_price' => 400,
            ]],
        ])->assertSuccessful();

        // A second part on the same category with NO document behind it.
        $loose = $this->purchase($ticket, $task, ['part_name' => 'Brake Disc', 'purchase_price' => 200]);
        app(PartWorkflowService::class)->installPurchase($loose, [], $this->admin);

        $brakes = collect(app(CostVerificationService::class)->spendByCategory())
            ->firstWhere('category_key', 'brakes');

        $this->assertNotNull($brakes, 'Spend must be reportable by category without reading any text.');
        $this->assertSame(600.0, $brakes['total']);
        $this->assertSame(400.0, $brakes['documented']);
        $this->assertSame(200.0, $brakes['undocumented']);
        $this->assertSame(66.7, $brakes['coverage_pct']);
    }
}
