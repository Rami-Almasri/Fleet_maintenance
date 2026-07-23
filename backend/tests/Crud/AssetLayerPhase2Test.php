<?php

namespace Tests\Crud;

use App\Models\ComponentCatalog;
use App\Models\ComponentEvent;
use App\Models\PartPurchase;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use App\Models\VehicleLogEvent;
use App\Services\ComponentService;
use App\Services\PartWorkflowService;
use Database\Seeders\ComponentCatalogSeeder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Asset Layer — Phase 2a acceptance: the component lifecycle through ComponentService and the
 * flag-gated installPurchase hook (off / shadow / enforced contracts), per
 * docs/Asset-Layer-Phase2-Final-Review.md.
 */
class AssetLayerPhase2Test extends CrudTestCase
{
    private ComponentService $components;
    private PartWorkflowService $parts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ComponentCatalogSeeder::class);
        $this->components = app(ComponentService::class);
        $this->parts      = app(PartWorkflowService::class);
        config(['features.asset_layer' => 'off']);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────────────────────

    private function vehicleModel(): Vehicle
    {
        return Vehicle::findOrFail($this->makeVehicle());
    }

    private function catalogBySlug(string $slug): ComponentCatalog
    {
        return ComponentCatalog::where('slug', $slug)->firstOrFail();
    }

    private function makePurchase(Vehicle $vehicle, array $overrides = []): PartPurchase
    {
        return PartPurchase::create(array_merge([
            'vehicle_id'      => $vehicle->id,
            'part_name'       => 'Test Battery 80Ah',
            'part_number'     => 'BAT-80',
            'category_key'    => 'electrical',
            'purchase_source' => PartPurchase::SOURCE_SUPPLIER,
            'purchase_price'  => 450,
            'currency'        => 'AED',
            'quantity'        => 1,
            'purchased_at'    => now(),
        ], $overrides));
    }

    private function activeComponent(Vehicle $vehicle, string $slug = 'battery-12v', array $attrs = []): VehicleComponent
    {
        $catalog   = $this->catalogBySlug($slug);
        $component = new VehicleComponent(array_merge([
            'component_catalog_id' => $catalog->id,
            'serial_no'            => $catalog->isSerialized() ? 'SN-' . uniqid() : null,
            'brand'                => 'Bosch',
            'source'               => VehicleComponent::SOURCE_MANUAL,
            'installed_at'         => now()->subMonths(5),
            'installed_odometer'   => 120000,
            'warranty_months'      => 12,
        ], $attrs));
        $component->vehicle_id = $vehicle->id;
        $component->status     = VehicleComponent::STATUS_ACTIVE;
        $component->location   = VehicleComponent::LOC_ON_VEHICLE;
        $component->save();

        return $component->fresh();
    }

    private function expectHttp(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected HttpException {$status}, none thrown.");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode(), 'HTTP status: ' . $e->getMessage());
        }
    }

    // ── Flag contract on installPurchase ────────────────────────────────────────────────────────

    public function test_off_mode_writes_zero_asset_rows(): void
    {
        $vehicle  = $this->vehicleModel();
        $purchase = $this->makePurchase($vehicle);

        $this->parts->installPurchase($purchase, ['installed_odometer' => 120500], $this->admin);

        $this->assertNotNull($purchase->fresh()->installed_at);
        $this->assertSame(0, VehicleComponent::count());
        $this->assertSame(0, ComponentEvent::count());
    }

    public function test_shadow_mode_creates_component_on_happy_path(): void
    {
        config(['features.asset_layer' => 'shadow']);
        $vehicle  = $this->vehicleModel();
        $purchase = $this->makePurchase($vehicle);

        $this->parts->installPurchase($purchase, [
            'installed_odometer' => 120500,
            'warranty_months'    => 18,
            'component'          => [
                'component_catalog_id' => $this->catalogBySlug('battery-12v')->id,
                'serial_no'            => 'AM-102',
                'brand'                => 'Amaron',
                'technician_name'      => 'Salim',
            ],
        ], $this->admin);

        $component = VehicleComponent::sole();
        $this->assertSame(VehicleComponent::STATUS_ACTIVE, $component->status);
        $this->assertSame($vehicle->id, $component->vehicle_id);
        $this->assertSame('AM-102', $component->serial_no);
        $this->assertSame('Salim', $component->technician_name);
        $this->assertSame($purchase->id, $component->source_part_purchase_id);
        $this->assertSame(VehicleComponent::SOURCE_WORKFLOW, $component->source);
        $this->assertNotNull($component->warranty_until); // derived: installed_at + 18m
        $this->assertSame(now()->addMonths(18)->toDateString(), $component->warranty_until->toDateString());

        $this->assertSame(1, $component->events()->where('event', ComponentEvent::EVENT_INSTALLED)->count());
        $this->assertSame(1, VehicleLogEvent::where('vehicle_id', $vehicle->id)
            ->where('event_type', VehicleLogEvent::EVENT_COMPONENT_INSTALLED)->count());
    }

    public function test_shadow_mode_failure_never_blocks_the_billing_install(): void
    {
        config(['features.asset_layer' => 'shadow']);
        $vehicle  = $this->vehicleModel();
        // 'electrical' maps to SEVERAL catalogs and no explicit pick is given → the component write
        // throws (ambiguous catalog) inside the shadow try/catch. Billing must succeed untouched.
        $purchase = $this->makePurchase($vehicle);

        $this->parts->installPurchase($purchase, ['installed_odometer' => 120500], $this->admin);

        $this->assertNotNull($purchase->fresh()->installed_at);
        $this->assertSame(0, VehicleComponent::count());
    }

    public function test_consumable_purchase_installs_cleanly_in_every_mode_without_a_component(): void
    {
        // Consumables (oil, coolant) are legitimate PURCHASES but never components — the component
        // path must step aside silently, INCLUDING in enforced mode where an abort would block the
        // billing install itself (the latent bug this test pins down).
        foreach (['shadow', 'enforced'] as $mode) {
            config(['features.asset_layer' => $mode]);
            $vehicle  = $this->vehicleModel();
            $purchase = $this->makePurchase($vehicle, [
                'part_name' => 'Engine oil', 'category_key' => 'fluids', 'part_class' => 'consumable',
            ]);

            $this->parts->installPurchase($purchase, ['installed_odometer' => 120500], $this->admin);

            $this->assertNotNull($purchase->fresh()->installed_at, "billing install must succeed in {$mode}");
            $this->assertSame(0, VehicleComponent::count(), "no component may exist for a consumable ({$mode})");
        }
    }

    public function test_enforced_mode_rolls_back_everything_when_disposition_is_missing(): void
    {
        config(['features.asset_layer' => 'enforced']);
        $vehicle = $this->vehicleModel();
        $this->activeComponent($vehicle); // slot occupied → predecessor decision required
        $purchase = $this->makePurchase($vehicle);

        $this->expectHttp(422, fn () => $this->parts->installPurchase($purchase, [
            'installed_odometer' => 120500,
            'component'          => ['component_catalog_id' => $this->catalogBySlug('battery-12v')->id, 'serial_no' => 'AM-102'],
            // no 'predecessor' block → "what happened to the old battery?"
        ], $this->admin));

        // All-or-nothing: the billing install rolled back with the component write.
        $this->assertNull($purchase->fresh()->installed_at);
        $this->assertSame(1, VehicleComponent::count()); // only the pre-existing old battery
        $this->assertSame(VehicleComponent::STATUS_ACTIVE, VehicleComponent::sole()->status);
    }

    public function test_enforced_replacement_closes_predecessor_and_links_successor(): void
    {
        config(['features.asset_layer' => 'enforced']);
        $vehicle  = $this->vehicleModel();
        $old      = $this->activeComponent($vehicle); // Bosch, 5 months into 12m warranty
        $purchase = $this->makePurchase($vehicle);

        $this->parts->installPurchase($purchase, [
            'installed_odometer' => 150300,
            'warranty_months'    => 18,
            'component'          => ['component_catalog_id' => $this->catalogBySlug('battery-12v')->id, 'serial_no' => 'AM-102', 'brand' => 'Amaron'],
            'predecessor'        => [
                'removal_reason'   => VehicleComponent::REASON_FAILED,
                'disposition'      => VehicleComponent::DISP_WARRANTY_RETURN,
                'removed_odometer' => 150300,
                'removal_note'     => 'Failed inside warranty',
            ],
        ], $this->admin);

        $old = $old->fresh();
        $new = VehicleComponent::where('serial_no', 'AM-102')->sole();

        // Old: closed in place, never disappears.
        $this->assertSame(VehicleComponent::STATUS_RETIRED, $old->status);
        $this->assertSame(VehicleComponent::LOC_SUPPLIER, $old->location);
        $this->assertSame(VehicleComponent::REASON_FAILED, $old->removal_reason);
        $this->assertSame(VehicleComponent::DISP_WARRANTY_RETURN, $old->disposition);
        $this->assertSame($vehicle->id, $old->vehicle_id); // retired keeps the last vehicle for history
        $this->assertSame(150300, $old->removed_odometer);
        $this->assertSame(30300, $old->life_km);
        $this->assertSame($new->id, $old->replaced_by_component_id);

        // New: active with full install leg.
        $this->assertSame(VehicleComponent::STATUS_ACTIVE, $new->status);
        $this->assertEquals(450.0, (float) $new->purchase_cost);

        // Events: removed + warranty_claimed on old, installed on new; warranty flag captured.
        $this->assertSame(1, $old->events()->where('event', ComponentEvent::EVENT_REMOVED)->count());
        $claim = $old->events()->where('event', ComponentEvent::EVENT_WARRANTY_CLAIMED)->sole();
        $this->assertTrue((bool) data_get($claim->meta, 'in_warranty'));
        $this->assertSame(1, $new->events()->where('event', ComponentEvent::EVENT_INSTALLED)->count());
    }

    public function test_enforced_serialized_install_requires_serial(): void
    {
        config(['features.asset_layer' => 'enforced']);
        $vehicle  = $this->vehicleModel();
        $purchase = $this->makePurchase($vehicle);

        $this->expectHttp(422, fn () => $this->parts->installPurchase($purchase, [
            'component' => ['component_catalog_id' => $this->catalogBySlug('battery-12v')->id], // no serial
        ], $this->admin));

        $this->assertNull($purchase->fresh()->installed_at);
        $this->assertSame(0, VehicleComponent::count());
    }

    public function test_ambiguous_catalog_requires_explicit_pick(): void
    {
        config(['features.asset_layer' => 'enforced']);
        $vehicle  = $this->vehicleModel();
        $purchase = $this->makePurchase($vehicle); // category electrical → several catalogs

        $this->expectHttp(422, fn () => $this->parts->installPurchase($purchase, [], $this->admin));
    }

    // ── Slot rules ──────────────────────────────────────────────────────────────────────────────

    public function test_two_active_components_in_one_slot_is_a_409_never_a_guess(): void
    {
        config(['features.asset_layer' => 'enforced']);
        $vehicle = $this->vehicleModel();
        $this->activeComponent($vehicle, 'battery-12v');
        $this->activeComponent($vehicle, 'battery-12v'); // corrupt state, built directly

        $purchase = $this->makePurchase($vehicle);
        $this->expectHttp(409, fn () => $this->parts->installPurchase($purchase, [
            'component'   => ['component_catalog_id' => $this->catalogBySlug('battery-12v')->id, 'serial_no' => 'AM-102'],
            'predecessor' => ['removal_reason' => 'failed', 'disposition' => 'scrapped'],
        ], $this->admin));
    }

    public function test_positioned_catalog_rejects_wrong_position_and_requires_one_when_enforced(): void
    {
        config(['features.asset_layer' => 'enforced']);
        $vehicle = $this->vehicleModel();
        $stock   = $this->components->intake([
            'component_catalog_id' => $this->catalogBySlug('tyre')->id,
            'brand'                => 'Michelin',
        ], $this->admin);

        $this->expectHttp(422, fn () => $this->components->install($stock, ['vehicle_id' => $vehicle->id, 'position' => 'middle'], $this->admin));
        $this->expectHttp(422, fn () => $this->components->install($stock->fresh(), ['vehicle_id' => $vehicle->id], $this->admin)); // no position, enforced

        $fitted = $this->components->install($stock->fresh(), ['vehicle_id' => $vehicle->id, 'position' => 'front_left', 'odometer' => 61000], $this->admin);
        $this->assertSame('front_left', $fitted->position);
    }

    // ── Warehouse doors (Scenario 2) ────────────────────────────────────────────────────────────

    public function test_uninstalled_purchase_moves_to_stock_via_intake_exactly_once(): void
    {
        $vehicle  = $this->vehicleModel();
        $purchase = $this->makePurchase($vehicle, ['part_name' => 'Alternator', 'part_number' => 'ALT-1']);

        $component = $this->components->intake([
            'part_purchase_id'     => $purchase->id,
            'component_catalog_id' => $this->catalogBySlug('alternator')->id,
            'serial_no'            => 'ALT-991',
        ], $this->admin);

        $this->assertSame(VehicleComponent::STATUS_IN_STOCK, $component->status);
        $this->assertSame(VehicleComponent::LOC_WAREHOUSE, $component->location);
        $this->assertNull($component->vehicle_id);
        $this->assertEquals(450.0, (float) $component->purchase_cost); // provenance copied
        $this->assertSame(2, $component->events()->count());           // purchased + stored

        // The same purchase can never enter the ledger twice.
        $this->expectHttp(409, fn () => $this->components->intake([
            'part_purchase_id'     => $purchase->id,
            'component_catalog_id' => $this->catalogBySlug('alternator')->id,
            'serial_no'            => 'ALT-992',
        ], $this->admin));
    }

    // ── Spare re-install / transfer / removal / disposal ───────────────────────────────────────

    public function test_spare_reinstall_preserves_original_warranty(): void
    {
        $vehicle = $this->vehicleModel();
        $old     = $this->activeComponent($vehicle);
        $until   = $old->warranty_until->toDateString();

        // Remove to stock…
        $this->components->remove($old, [
            'removal_reason'   => VehicleComponent::REASON_UPGRADE,
            'disposition'      => VehicleComponent::DISP_STORED,
            'removed_odometer' => 125000,
        ], $this->admin);

        $spare = $old->fresh();
        $this->assertSame(VehicleComponent::STATUS_IN_STOCK, $spare->status);
        $this->assertNull($spare->vehicle_id);

        // …then fit it on another car: same row, same warranty clock.
        $other  = $this->vehicleModel();
        $fitted = $this->components->install($spare, ['vehicle_id' => $other->id, 'odometer' => 61000], $this->admin);

        $this->assertSame($old->id, $fitted->id);
        $this->assertSame(VehicleComponent::STATUS_ACTIVE, $fitted->status);
        $this->assertSame($other->id, $fitted->vehicle_id);
        $this->assertSame($until, $fitted->warranty_until->toDateString()); // NEVER reset
    }

    public function test_transfer_moves_one_row_with_a_two_sided_event(): void
    {
        $a    = $this->vehicleModel();
        $b    = $this->vehicleModel();
        $tyre = $this->activeComponent($a, 'tyre', ['position' => 'front_left', 'brand' => 'Michelin', 'serial_no' => null, 'warranty_months' => null]);

        $moved = $this->components->transfer($tyre, [
            'to_vehicle_id' => $b->id,
            'position'      => 'front_left',
            'from_odometer' => 88200,
            'to_odometer'   => 61050,
        ], $this->admin);

        $this->assertSame($tyre->id, $moved->id);                       // same physical row
        $this->assertSame($b->id, $moved->vehicle_id);
        $this->assertSame(VehicleComponent::STATUS_ACTIVE, $moved->status);

        $event = $moved->events()->where('event', ComponentEvent::EVENT_TRANSFERRED)->sole();
        $this->assertSame($a->id, $event->from_vehicle_id);
        $this->assertSame($b->id, $event->to_vehicle_id);
        $this->assertSame(88200, data_get($event->meta, 'from_odometer'));

        // Both timelines see it.
        foreach ([$a->id, $b->id] as $vid) {
            $this->assertSame(1, VehicleLogEvent::where('vehicle_id', $vid)
                ->where('event_type', VehicleLogEvent::EVENT_COMPONENT_TRANSFERRED)->count(), "timeline of vehicle {$vid}");
        }
    }

    public function test_transfer_into_an_occupied_slot_is_409(): void
    {
        $a = $this->vehicleModel();
        $b = $this->vehicleModel();
        $this->activeComponent($b, 'battery-12v'); // B already has a battery
        $battery = $this->activeComponent($a, 'battery-12v');

        $this->expectHttp(409, fn () => $this->components->transfer($battery, ['to_vehicle_id' => $b->id], $this->admin));
    }

    public function test_removal_without_disposition_is_impossible(): void
    {
        $vehicle = $this->vehicleModel();
        $battery = $this->activeComponent($vehicle);

        $this->expectHttp(422, fn () => $this->components->remove($battery, ['removal_reason' => 'failed'], $this->admin));
        $this->expectHttp(422, fn () => $this->components->remove($battery->fresh(), ['disposition' => 'scrapped'], $this->admin));

        $this->assertSame(VehicleComponent::STATUS_ACTIVE, $battery->fresh()->status); // untouched
    }

    public function test_shelf_disposal_is_terminal(): void
    {
        $stock = $this->components->intake([
            'component_catalog_id' => $this->catalogBySlug('alternator')->id,
            'serial_no'            => 'ALT-OLD',
        ], $this->admin);

        $gone = $this->components->dispose($stock, ['disposition' => VehicleComponent::DISP_SCRAPPED], $this->admin);

        $this->assertSame(VehicleComponent::STATUS_RETIRED, $gone->status);
        $this->assertSame(VehicleComponent::LOC_SCRAPPED, $gone->location);
        $this->assertSame(1, $gone->events()->where('event', ComponentEvent::EVENT_DISPOSED)->count());

        // Retired is absorbing: no path re-activates it.
        $this->expectHttp(422, fn () => $this->components->install($gone, ['vehicle_id' => $this->makeVehicle()], $this->admin));
        $this->expectHttp(422, fn () => $this->components->transfer($gone, ['to_vehicle_id' => $this->makeVehicle()], $this->admin));
    }

    // ── Vehicle sale settlement (Scenario 4) ───────────────────────────────────────────────────

    public function test_sale_settlement_requires_a_decision_for_every_active_component(): void
    {
        $vehicle = $this->vehicleModel();
        $battery = $this->activeComponent($vehicle, 'battery-12v');
        $tracker = $this->activeComponent($vehicle, 'gps-tracker', ['serial_no' => 'GPS-7', 'warranty_months' => null]);

        // Undecided component → 422, nothing settled.
        $this->expectHttp(422, fn () => $this->components->settleForVehicleSale($vehicle, [
            $battery->id => VehicleComponent::DISP_SOLD_WITH_VEHICLE,
        ], $this->admin));
        $this->assertSame(2, VehicleComponent::activeOn($vehicle->id)->count());

        // Full settlement: battery goes with the car, tracker strips to stock.
        $this->components->settleForVehicleSale($vehicle, [
            $battery->id => VehicleComponent::DISP_SOLD_WITH_VEHICLE,
            $tracker->id => VehicleComponent::DISP_STORED,
        ], $this->admin);

        $this->assertSame(0, VehicleComponent::activeOn($vehicle->id)->count()); // the invariant

        $battery = $battery->fresh();
        $this->assertSame(VehicleComponent::STATUS_RETIRED, $battery->status);
        $this->assertSame(VehicleComponent::LOC_SOLD, $battery->location);
        $this->assertSame($vehicle->id, $battery->vehicle_id); // stays in the car's frozen history

        $tracker = $tracker->fresh();
        $this->assertSame(VehicleComponent::STATUS_IN_STOCK, $tracker->status);
        $this->assertNull($tracker->vehicle_id);               // lives on in the warehouse
    }

    // ── Shadow trust markers + audit command (launch plan §7) ──────────────────────────────────

    public function test_write_mode_and_validation_status_are_stamped_per_flag_regime(): void
    {
        $vehicle = $this->vehicleModel();

        config(['features.asset_layer' => 'shadow']);
        $this->parts->installPurchase($this->makePurchase($vehicle), [
            'component' => ['component_catalog_id' => $this->catalogBySlug('battery-12v')->id, 'serial_no' => 'SH-1'],
        ], $this->admin);

        $shadowRow = VehicleComponent::where('serial_no', 'SH-1')->sole();
        $this->assertSame('shadow', $shadowRow->write_mode);
        $this->assertSame(VehicleComponent::VALIDATION_PROVISIONAL, $shadowRow->validation_status);

        config(['features.asset_layer' => 'enforced']);
        $other = $this->vehicleModel();
        $this->parts->installPurchase($this->makePurchase($other), [
            'component' => ['component_catalog_id' => $this->catalogBySlug('battery-12v')->id, 'serial_no' => 'EN-1'],
        ], $this->admin);

        $enforcedRow = VehicleComponent::where('serial_no', 'EN-1')->sole();
        $this->assertSame('enforced', $enforcedRow->write_mode);
        // Enforced rows passed blocking validation at write time → born validated.
        $this->assertSame(VehicleComponent::VALIDATION_VALIDATED, $enforcedRow->validation_status);
    }

    public function test_shadow_audit_reports_gaps_and_promotes_only_clean_rows(): void
    {
        config(['features.asset_layer' => 'shadow']);
        $vehicle = $this->vehicleModel();

        // One clean shadow write…
        $this->parts->installPurchase($this->makePurchase($vehicle), [
            'component' => ['component_catalog_id' => $this->catalogBySlug('battery-12v')->id, 'serial_no' => 'OK-1'],
        ], $this->admin);

        // …one M1 gap: an install whose shadow write failed (consumable → swallowed).
        $other = $this->vehicleModel();
        $this->parts->installPurchase(
            $this->makePurchase($other, ['part_name' => 'Engine oil', 'category_key' => 'fluids']),
            [],
            $this->admin
        );

        // Report run: exit FAILURE (there is a gap), nothing promoted.
        $this->artisan('components:shadow-audit')->assertExitCode(1);
        $this->assertSame(VehicleComponent::VALIDATION_PROVISIONAL,
            VehicleComponent::where('serial_no', 'OK-1')->sole()->validation_status);

        // Promote run: the clean row is validated; the gap still fails the run.
        $this->artisan('components:shadow-audit --promote')->assertExitCode(1);
        $this->assertSame(VehicleComponent::VALIDATION_VALIDATED,
            VehicleComponent::where('serial_no', 'OK-1')->sole()->validation_status);
    }

    public function test_quarantine_requires_a_reason_and_excludes_from_trusted_scope(): void
    {
        config(['features.asset_layer' => 'shadow']);
        $vehicle = $this->vehicleModel();
        $this->parts->installPurchase($this->makePurchase($vehicle), [
            'component' => ['component_catalog_id' => $this->catalogBySlug('battery-12v')->id, 'serial_no' => 'BAD-1'],
        ], $this->admin);
        $row = VehicleComponent::where('serial_no', 'BAD-1')->sole();

        $this->artisan("components:shadow-audit --quarantine={$row->id}")->assertExitCode(1); // no reason
        $this->artisan("components:shadow-audit --quarantine={$row->id} --reason=\"phantom: never fitted\"")->assertExitCode(0);

        $row = $row->fresh();
        $this->assertSame(VehicleComponent::VALIDATION_QUARANTINED, $row->validation_status);
        $this->assertSame(0, VehicleComponent::trusted()->whereKey($row->id)->count()); // excluded from reads
        $this->assertNotNull($row->fresh()); // but NEVER deleted — kept for audit
    }
}
