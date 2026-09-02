<?php

namespace Tests\Feature;

use App\Models\ComponentCatalog;
use App\Models\Maintenance;
use App\Models\MaintenanceInvoice;
use App\Models\MaintenanceLineItem;
use App\Models\PartPurchase;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use App\Models\Vendor;
use App\Services\GarageLineItemLedgerService;
use App\Services\PartSpendService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * END-TO-END guard for the defect this feature exists to close: a part a GARAGE supplied and billed
 * for used to be a price and nothing else — never a purchase, never a component, never in the part's
 * own history. A supplier-bought battery and a garage-fitted battery were two universes.
 *
 * WHAT THESE LOCK, in order of what would actually go wrong:
 *
 *   1. The MONEY. The single most dangerous outcome of connecting a bill to the parts ledger is
 *      counting the same dirham twice. PartSpendService already excludes purchases that carry a
 *      maintenance_line_item_id — these tests prove the ledger keeps carrying one, so the exclusion
 *      keeps working. If a future change drops that link, the fleet's parts spend silently doubles
 *      and nothing else in the suite would notice.
 *   2. IDEMPOTENCY, twice over: re-running the backfill, and re-saving the bill (which DELETES and
 *      RECREATES its line rows, so the naive key does not survive an edit).
 *   3. The REFUSALS. Every one of them is a decision not to invent a fact, and each is worth as much
 *      as the writes — a test suite that only proves the happy path would let "raise the success
 *      rate by guessing" pass review.
 *
 * REQUIRES the `fleet_e2e_scratch` MySQL database — run with `-c phpunit.e2e.xml`. No
 * RefreshDatabase: the full migration set cannot be replayed from empty, so the schema is cloned
 * from live and only the touched tables are truncated. @see SpareKeyLifecycleTest.
 */
class GarageSuppliedPartLifecycleTest extends TestCase
{
    private const TOUCHED = [
        'part_purchases', 'vehicle_components', 'component_events', 'maintenance_line_items',
        'maintenance_invoices', 'maintenances', 'vehicle_log_events', 'vehicles', 'vendors', 'users',
    ];

    private User $actor;
    private Vehicle $vehicle;
    private Vendor $garage;
    private Maintenance $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDatabaseName() !== 'fleet_e2e_scratch') {
            $this->markTestSkipped('needs the fleet_e2e_scratch database — run with -c phpunit.e2e.xml');
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::TOUCHED as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // Pinned to the PRODUCTION default. Step 1 fixes a lifecycle gap; it does not move the asset
        // layer to enforced, and these tests must prove the behaviour that actually ships.
        config()->set('features.asset_layer', 'shadow');

        $this->actor = User::create([
            'name'     => 'Invoice Clerk',
            'email'    => 'clerk.' . uniqid() . '@fleet.test',
            'password' => bcrypt('password'),
            'status'   => 'active',
        ]);

        $this->vehicle = Vehicle::create([
            'plate_no' => '55123',
            'make'     => 'NISSAN',
            'model'    => 'PATROL',
            'year'     => 2024,
            'odometer' => 40_000,
            'status'   => 'available',
        ]);

        $this->garage = Vendor::create(['name' => 'Garage X']);

        $this->ticket = Maintenance::create([
            'vehicle_id' => $this->vehicle->id,
            'vendor_id'  => $this->garage->id,
        ]);
    }

    // ───────────────────────────── fixtures ─────────────────────────────

    private function catalog(string $slug, string $name, string $mode, ?string $scheme = null): ComponentCatalog
    {
        return ComponentCatalog::firstOrCreate(
            ['slug' => $slug],
            [
                'name'            => $name,
                'category_key'    => 'engine',
                'tracking_mode'   => $mode,
                'position_scheme' => $scheme,
                'is_active'       => true,
            ]
        );
    }

    /** A part line exactly as the invoice service writes one. */
    private function partLine(array $overrides = []): MaintenanceLineItem
    {
        return MaintenanceLineItem::create(array_merge([
            'maintenance_id' => $this->ticket->id,
            'vehicle_id'     => $this->vehicle->id,
            'kind'           => MaintenanceLineItem::KIND_PART,
            'description'    => 'Alternator',
            'quantity'       => 1,
            'uom'            => 'unit',
            'unit_price'     => 900,
            'entry_source'   => 'manual',
            'created_by'     => $this->actor->id,
        ], $overrides));
    }

    private function ledger(): GarageLineItemLedgerService
    {
        return app(GarageLineItemLedgerService::class);
    }

    // ───────────────────────────── the part becomes a part ─────────────────────────────

    public function test_a_garage_billed_part_becomes_a_purchase_traceable_to_the_garage(): void
    {
        $catalog = $this->catalog('alternator', 'Alternator', ComponentCatalog::TRACKING_SERIALIZED);
        $line    = $this->partLine(['component_catalog_id' => $catalog->id, 'unit_price' => 900]);

        $this->ledger()->syncLine($line, $this->actor);

        $purchase = PartPurchase::where('maintenance_line_item_id', $line->id)->first();

        $this->assertNotNull($purchase, 'a billed part must reach the parts ledger');
        $this->assertSame(PartPurchase::SOURCE_GARAGE, $purchase->purchase_source);
        $this->assertSame($this->garage->id, $purchase->source_vendor_id, 'the garage on the ticket is who supplied it');
        $this->assertSame($this->vehicle->id, $purchase->vehicle_id);
        $this->assertSame($catalog->id, $purchase->component_catalog_id, 'it is the SAME canonical part a supplier buy would name');
        $this->assertEquals(900, (float) $purchase->purchase_price);
    }

    public function test_the_part_reaches_the_car_as_a_physical_component(): void
    {
        $catalog = $this->catalog('alternator', 'Alternator', ComponentCatalog::TRACKING_SERIALIZED);
        $line    = $this->partLine([
            'component_catalog_id' => $catalog->id,
            'installed_on'         => '2026-05-12',
            'installed_odometer'   => 41_000,
        ]);

        $res = $this->ledger()->syncLine($line, $this->actor);

        $component = $res['component'];
        $this->assertNotNull($component, 'the car must know what is fitted to it');
        $this->assertSame($this->vehicle->id, $component->vehicle_id);
        $this->assertSame(VehicleComponent::STATUS_ACTIVE, $component->status);
        $this->assertSame('2026-05-12', $component->installed_at->toDateString());
        $this->assertSame($line->id, $component->source_line_item_id, 'back to the bill line');
        $this->assertSame($res['purchase']->id, $component->source_part_purchase_id, 'and back to the purchase');
        $this->assertSame($this->garage->id, $component->supplier_vendor_id);
    }

    public function test_a_garage_supplied_part_carries_no_invented_warranty(): void
    {
        // The catalog offers 24 months. A garage-supplied part is not ACQ_WARRANTABLE — that warranty
        // is a conversation with the garage, not an entitlement on our ledger — so it must NOT be
        // applied just because the catalog has a number in it.
        $catalog = $this->catalog('alternator', 'Alternator', ComponentCatalog::TRACKING_SERIALIZED);
        $catalog->update(['default_warranty_months' => 24]);

        $line = $this->partLine(['component_catalog_id' => $catalog->id, 'installed_on' => '2026-05-12']);

        $component = $this->ledger()->syncLine($line, $this->actor)['component'];

        $this->assertSame(VehicleComponent::ACQ_GARAGE_SUPPLIED, $component->acquisition);
        $this->assertNull($component->warranty_months, 'no default warranty may be manufactured');
        $this->assertNull($component->warranty_until);
    }

    public function test_a_warranty_the_bill_states_is_honoured(): void
    {
        $catalog = $this->catalog('alternator', 'Alternator', ComponentCatalog::TRACKING_SERIALIZED);
        $line    = $this->partLine([
            'component_catalog_id' => $catalog->id,
            'installed_on'         => '2026-05-12',
            'warranty_months'      => 12,
        ]);

        $component = $this->ledger()->syncLine($line, $this->actor)['component'];

        $this->assertSame(12, (int) $component->warranty_months);
        $this->assertSame('2027-05-12', $component->warranty_until->toDateString());
    }

    public function test_an_unknown_install_date_stays_unknown(): void
    {
        // 80 of the 87 historical lines carry no installed_on. Dating them to the day the backfill
        // ran would make every one of them look freshly fitted.
        $catalog = $this->catalog('alternator', 'Alternator', ComponentCatalog::TRACKING_SERIALIZED);
        $line    = $this->partLine(['component_catalog_id' => $catalog->id, 'installed_on' => null]);

        $component = $this->ledger()->syncLine($line, $this->actor)['component'];

        $this->assertNotNull($component, 'unknown WHEN is not a reason to lose the fact that it is fitted');
        $this->assertNull($component->installed_at);
        $this->assertNull($component->warranty_until, 'and no warranty clock can start from a date we do not have');
    }

    // ───────────────────────────── the refusals ─────────────────────────────

    public function test_a_line_with_no_canonical_part_is_left_for_review_and_gets_no_purchase(): void
    {
        $line = $this->partLine(['component_catalog_id' => null, 'description' => 'blue thing']);

        $res = $this->ledger()->syncLine($line, $this->actor);

        $this->assertNull($res['purchase'], 'guessing which part this is would fill the ledger with confident nonsense');
        $this->assertSame('no_canonical_part', $res['reason']);
        $this->assertSame(0, PartPurchase::count());
    }

    public function test_a_consumable_is_bought_but_never_becomes_a_component(): void
    {
        $catalog = $this->catalog('engine-oil', 'Engine Oil', ComponentCatalog::TRACKING_CONSUMABLE);
        $line    = $this->partLine(['component_catalog_id' => $catalog->id, 'description' => 'Engine Oil 5W-30']);

        $res = $this->ledger()->syncLine($line, $this->actor);

        $this->assertNotNull($res['purchase'], 'oil IS bought and billed — the purchase is real');
        $this->assertNull($res['component'], 'consumables are work, not assets');
        $this->assertSame('consumable_never_a_component', $res['reason']);
        $this->assertSame(0, VehicleComponent::count());
    }

    public function test_a_part_needing_a_position_the_bill_does_not_state_is_deferred_not_guessed(): void
    {
        $catalog = $this->catalog('brake-pads', 'Brake Pads', ComponentCatalog::TRACKING_BATCH, ComponentCatalog::SCHEME_AXLE);
        $line    = $this->partLine(['component_catalog_id' => $catalog->id, 'description' => 'Front brake pads (set)']);

        $res = $this->ledger()->syncLine($line, $this->actor);

        $this->assertNotNull($res['purchase']);
        $this->assertNull($res['component'], 'the bill does not say which axle — inventing one would occupy the real slot');
        $this->assertSame('deferred_missing_position', $res['reason']);

        // The reason must be readable on the row itself, not only in a report that has scrolled away.
        $this->assertStringContainsString('does not say which', (string) $res['purchase']->notes);
    }

    public function test_an_occupied_slot_defers_rather_than_inventing_a_disposition(): void
    {
        $catalog = $this->catalog('alternator', 'Alternator', ComponentCatalog::TRACKING_SERIALIZED);

        // The car already has one, and the bill does not say what happened to it.
        $this->ledger()->syncLine($this->partLine(['component_catalog_id' => $catalog->id]), $this->actor);
        $this->assertSame(1, VehicleComponent::count());

        $second = $this->partLine(['component_catalog_id' => $catalog->id, 'unit_price' => 950]);
        $res    = $this->ledger()->syncLine($second, $this->actor);

        $this->assertNotNull($res['purchase'], 'the money is still real and still traceable');
        $this->assertNull($res['component']);
        $this->assertSame('deferred_slot_occupied', $res['reason']);
        $this->assertSame(1, VehicleComponent::count(), 'no second active alternator on one car');
    }

    // ───────────────────────────── idempotency ─────────────────────────────

    public function test_running_the_backfill_twice_changes_nothing_the_second_time(): void
    {
        $catalog = $this->catalog('alternator', 'Alternator', ComponentCatalog::TRACKING_SERIALIZED);
        $line    = $this->partLine(['component_catalog_id' => $catalog->id]);

        $this->ledger()->syncLine($line, $this->actor);
        $this->ledger()->syncLine($line, $this->actor);
        $this->ledger()->syncLine($line, $this->actor);

        $this->assertSame(1, PartPurchase::count(), 'one physical part, one purchase');
        $this->assertSame(1, VehicleComponent::count(), 'and one component');
    }

    public function test_the_database_itself_refuses_a_second_purchase_for_one_bill_line(): void
    {
        // The backstop under the service's own check: if a future caller forgets to look first, the
        // unique index still makes duplicate stock impossible.
        $catalog = $this->catalog('alternator', 'Alternator', ComponentCatalog::TRACKING_SERIALIZED);
        $line    = $this->partLine(['component_catalog_id' => $catalog->id]);

        $this->ledger()->syncLine($line, $this->actor);

        $this->expectException(\Illuminate\Database\QueryException::class);

        PartPurchase::create([
            'vehicle_id'               => $this->vehicle->id,
            'maintenance_line_item_id' => $line->id,
            'part_name'                => 'Alternator',
            'purchase_source'          => PartPurchase::SOURCE_GARAGE,
            'purchase_price'           => 900,
            'quantity'                 => 1,
            'currency'                 => 'AED',
            'result'                   => PartPurchase::RESULT_SUCCESS,
        ]);
    }

    public function test_re_saving_the_bill_reconciles_instead_of_duplicating(): void
    {
        // The trap: MaintenanceInvoiceService DELETES and RECREATES every work line on each save, so
        // the line id the ledger keyed on no longer exists after an edit.
        $catalog = $this->catalog('alternator', 'Alternator', ComponentCatalog::TRACKING_SERIALIZED);
        $invoice = MaintenanceInvoice::create(['maintenance_id' => $this->ticket->id, 'vendor_id' => $this->garage->id]);

        $first = $this->partLine(['component_catalog_id' => $catalog->id, 'maintenance_invoice_id' => $invoice->id]);
        $this->ledger()->syncInvoice($invoice, $this->actor);
        $this->assertSame(1, PartPurchase::count());

        // The clerk corrects the price: same part, brand new line row.
        $first->delete();
        $this->partLine([
            'component_catalog_id'   => $catalog->id,
            'maintenance_invoice_id' => $invoice->id,
            'unit_price'             => 850,
        ]);

        $this->ledger()->syncInvoice($invoice, $this->actor);

        $this->assertSame(1, PartPurchase::count(), 'an edit must not raise a second purchase for the same part');
        $this->assertEquals(850, (float) PartPurchase::first()->purchase_price, 'the bill is the authority on price');
        $this->assertSame(1, VehicleComponent::count());
    }

    // ───────────────────────────── the money ─────────────────────────────

    public function test_connecting_the_ledger_does_not_double_count_a_single_dirham(): void
    {
        $catalog = $this->catalog('alternator', 'Alternator', ComponentCatalog::TRACKING_SERIALIZED);
        $line    = $this->partLine(['component_catalog_id' => $catalog->id, 'unit_price' => 900, 'quantity' => 1]);

        $spend  = app(PartSpendService::class);
        $before = $spend->ranked('part', 50)['totals']['spend'];

        $this->ledger()->syncLine($line, $this->actor);

        $after = $spend->ranked('part', 50)['totals']['spend'];

        $this->assertEquals($before, $after,
            'the line item is the canonical dirham; the purchase carries a maintenance_line_item_id and is excluded');
        $this->assertEquals(900, $after, 'and the part is still counted exactly once');
    }

    // ───────────────────────────── the live path ─────────────────────────────

    public function test_saving_a_garage_invoice_puts_its_parts_into_the_ledger(): void
    {
        // The point of the whole exercise: this happens on the REAL write path, not only in a
        // one-off backfill, so the gap cannot silently reopen for new work.
        $catalog = $this->catalog('alternator', 'Alternator', ComponentCatalog::TRACKING_SERIALIZED);
        $invoice = MaintenanceInvoice::create(['maintenance_id' => $this->ticket->id, 'vendor_id' => $this->garage->id]);

        $this->partLine(['component_catalog_id' => $catalog->id, 'maintenance_invoice_id' => $invoice->id]);
        // Labour must stay out of the parts ledger entirely.
        $this->partLine([
            'kind'                   => MaintenanceLineItem::KIND_LABOR,
            'description'            => 'Diagnostics',
            'component_catalog_id'   => null,
            'maintenance_invoice_id' => $invoice->id,
            'unit_price'             => 500,
        ]);

        $tally = $this->ledger()->syncInvoice($invoice, $this->actor);

        $this->assertSame(1, $tally['created']);
        $this->assertSame(1, $tally['components']);
        $this->assertSame(1, PartPurchase::count(), 'labour is not a part');
    }

    public function test_the_full_chain_is_navigable_in_both_directions(): void
    {
        // The acceptance test, stated as an assertion: bill → line → canonical part → vehicle →
        // component, and back again, with nothing disappearing into a garage-only universe.
        $catalog = $this->catalog('alternator', 'Alternator', ComponentCatalog::TRACKING_SERIALIZED);
        $invoice = MaintenanceInvoice::create(['maintenance_id' => $this->ticket->id, 'vendor_id' => $this->garage->id]);
        $line    = $this->partLine([
            'component_catalog_id'   => $catalog->id,
            'maintenance_invoice_id' => $invoice->id,
            'installed_on'           => '2026-05-12',
        ]);

        $this->ledger()->syncInvoice($invoice, $this->actor);

        // Forwards.
        $purchase  = PartPurchase::where('maintenance_line_item_id', $line->id)->firstOrFail();
        $component = $purchase->component()->firstOrFail();

        $this->assertSame($invoice->id, $purchase->maintenance_invoice_id);
        $this->assertSame($catalog->id, $component->component_catalog_id);
        $this->assertSame($this->vehicle->id, $component->vehicle_id);

        // Backwards.
        $this->assertSame($line->id, $component->sourceLineItem->id);
        $this->assertSame($invoice->id, $component->sourceLineItem->invoice->id);
        $this->assertSame($this->garage->id, $purchase->garageInvoice->vendor_id);
        $this->assertSame('Alternator', $purchase->catalogPart->name);
    }
}
