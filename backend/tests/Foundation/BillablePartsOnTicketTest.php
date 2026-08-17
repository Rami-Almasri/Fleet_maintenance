<?php

namespace Tests\Foundation;

use App\Models\ComponentCatalog;
use App\Models\Maintenance;
use App\Models\MaintenanceRequiredPart;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\Vehicle;

/**
 * The invoice form must offer the parts THIS TICKET already named.
 *
 * By the time a bill is keyed the part has usually been named three times — required by the
 * inspector, requested by the coordinator, bought at a price we recorded. An empty catalog search at
 * the till throws all of it away: the quantity is re-typed, the price is re-typed off a receipt we
 * already hold, and the resulting line joins to none of the parts record.
 *
 * What this locks down is that the three stages are ONE part, not three: they dedupe by identity,
 * the most-advanced record wins, and money we already know travels with it.
 */
class BillablePartsOnTicketTest extends FoundationTestCase
{
    private Vehicle $vehicle;
    private Maintenance $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vehicle = $this->makeVehicle();
        $this->ticket  = Maintenance::create([
            'vehicle_id'       => $this->vehicle->id,
            'maintenance_type' => 'Breakdown',
            'status'           => 'pending',
            'date'             => now()->toDateString(),
            'findings'         => [['text' => 'A/C not cooling', 'source' => 'inspector']],
        ]);
    }

    /** @return array<int,array> the parts the form would offer */
    private function offered(): array
    {
        return $this->getJson("/api/maintenance-tickets/{$this->ticket->id}/billable-parts")
            ->assertOk()
            ->json('data.parts');
    }

    private function purchase(ComponentCatalog $part, array $overrides = []): PartPurchase
    {
        return PartPurchase::create(array_merge([
            'vehicle_id'           => $this->vehicle->id,
            'maintenance_id'       => $this->ticket->id,
            'part_name'            => $part->name,
            'component_catalog_id' => $part->id,
            'purchase_source'      => 'supplier',
            'purchase_price'       => 330.19,
            'currency'             => 'AED',
            'quantity'             => 2,
        ], $overrides));
    }

    /** The price we paid comes to the invoice with the part — nobody re-reads it off the receipt. */
    public function test_a_purchase_brings_its_price_and_quantity(): void
    {
        $part = ComponentCatalog::where('slug', 'alternator')->firstOrFail();
        $this->purchase($part);

        $offered = $this->offered();

        $this->assertCount(1, $offered);
        $this->assertSame('purchase', $offered[0]['source']);
        $this->assertSame($part->id, $offered[0]['component_catalog_id']);
        $this->assertSame(330.19, $offered[0]['unit_price']);
        $this->assertEquals(2, $offered[0]['quantity']);
    }

    /** Required → requested → bought is one part at three stages. The furthest along is the one shown. */
    public function test_the_three_stages_of_one_part_collapse_to_the_most_advanced(): void
    {
        $part = ComponentCatalog::where('slug', 'alternator')->firstOrFail();

        MaintenanceRequiredPart::create([
            'maintenance_id' => $this->ticket->id, 'vehicle_id' => $this->vehicle->id,
            'part_name' => $part->name, 'component_catalog_id' => $part->id, 'quantity' => 1,
            'status' => MaintenanceRequiredPart::STATUS_REQUESTED,
        ]);
        PartRequest::create([
            'maintenance_id' => $this->ticket->id, 'vehicle_id' => $this->vehicle->id,
            'part_name' => $part->name, 'component_catalog_id' => $part->id, 'quantity' => 1,
            'source' => 'maintenance', 'status' => 'requested', 'reason' => 'A/C not cooling',
        ]);
        $this->purchase($part);

        $offered = $this->offered();

        $this->assertCount(1, $offered, 'three records of one part must not be offered three times');
        $this->assertSame('purchase', $offered[0]['source'], 'the record that knows the price wins');
    }

    /** Fitting a purchase already writes a cost line. Offering it again would bill it twice. */
    public function test_a_purchase_already_turned_into_a_cost_line_is_flagged_not_offered_again(): void
    {
        $part = ComponentCatalog::where('slug', 'alternator')->firstOrFail();
        $line = $this->ticket->lineItems()->create([
            'vehicle_id' => $this->vehicle->id, 'kind' => 'part', 'description' => $part->name,
            'finding_text' => 'A/C not cooling', 'quantity' => 1, 'unit_price' => 330.19,
        ]);
        $this->purchase($part, ['maintenance_line_item_id' => $line->id]);

        $this->assertTrue($this->offered()[0]['already_billed']);
    }

    /** A foreign-currency buy ships no price rather than an invented conversion. */
    public function test_a_non_base_currency_purchase_offers_no_price(): void
    {
        $part = ComponentCatalog::where('slug', 'alternator')->firstOrFail();
        $this->purchase($part, ['currency' => 'USD']);

        $offered = $this->offered();

        $this->assertNull($offered[0]['unit_price']);
        $this->assertSame('USD', $offered[0]['currency']);
    }

    /** A part the coordinator decided not to source is not a thing to bill. */
    public function test_a_dismissed_required_part_is_not_offered(): void
    {
        MaintenanceRequiredPart::create([
            'maintenance_id' => $this->ticket->id, 'vehicle_id' => $this->vehicle->id,
            'part_name' => 'Cabin Filter', 'quantity' => 1,
            'status' => MaintenanceRequiredPart::STATUS_DISMISSED,
        ]);

        $this->assertSame([], $this->offered());
    }
}
