<?php

namespace Tests\Crud;

use App\Models\ComponentCatalog;
use App\Models\ComponentEvent;
use App\Models\ServiceRecord;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use Database\Seeders\ComponentCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Asset Layer — Phase 1 acceptance: schema integrity, model relationships, state-machine
 * invariants, the consumable guard, seeder idempotency and permission boundaries.
 *
 * Phase 1 is ADDITIVE-ONLY: no workflow behavior exists yet (ComponentService is Phase 2),
 * so these tests exercise the tables/models/constants/permissions directly.
 */
class AssetLayerPhase1Test extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;
    protected $seeder = RolesAndPermissionsSeeder::class;

    // ── Fixtures ────────────────────────────────────────────────────────────────────────────────

    private function makeCatalog(array $overrides = []): ComponentCatalog
    {
        return ComponentCatalog::create(array_merge([
            'slug'          => 'test-' . uniqid(),
            'name'          => 'Test Component',
            'category_key'  => 'electrical',
            'tracking_mode' => ComponentCatalog::TRACKING_SERIALIZED,
        ], $overrides));
    }

    private function makeVehicle(): Vehicle
    {
        return Vehicle::create([
            'vin'      => 'VIN' . strtoupper(uniqid()),
            'plate_no' => 'T-' . random_int(10000, 99999),
            'make'     => 'Toyota',
            'model'    => 'Corolla',
            'year'     => 2023,
            'odometer' => 50000,
        ]);
    }

    /** Build a component through explicit assignment — the way ComponentService will (status/location are not fillable). */
    private function makeComponent(ComponentCatalog $catalog, ?Vehicle $vehicle, string $status, string $location, array $attrs = []): VehicleComponent
    {
        $component = new VehicleComponent(array_merge([
            'component_catalog_id' => $catalog->id,
            'serial_no'            => 'SN-' . uniqid(),
            'brand'                => 'Bosch',
            'source'               => VehicleComponent::SOURCE_MANUAL,
        ], $attrs));

        $component->vehicle_id = $vehicle?->id;
        $component->status     = $status;
        $component->location   = $location;
        $component->save();

        return $component->fresh();
    }

    // ── 1. Migration integrity ──────────────────────────────────────────────────────────────────

    public function test_all_asset_layer_tables_and_key_columns_exist(): void
    {
        foreach (['component_catalog', 'vehicle_components', 'component_events', 'service_records'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing table: {$table}");
        }

        $this->assertTrue(Schema::hasColumns('component_catalog', [
            'slug', 'category_key', 'tracking_mode', 'position_scheme', 'expected_life_km', 'is_active',
        ]));
        $this->assertTrue(Schema::hasColumns('vehicle_components', [
            'component_catalog_id', 'vehicle_id', 'serial_no', 'position', 'status', 'location',
            'installed_at', 'installed_odometer', 'installer_vendor_id', 'supplier_vendor_id',
            'purchase_cost', 'warranty_months', 'warranty_until',
            'source_part_purchase_id', 'source_line_item_id', 'source',
            'removed_at', 'removed_odometer', 'removal_reason', 'disposition',
            'replaced_by_component_id', 'removal_maintenance_id',
        ]));
        $this->assertTrue(Schema::hasColumns('component_events', [
            'vehicle_component_id', 'event', 'from_vehicle_id', 'to_vehicle_id',
            'odometer', 'maintenance_id', 'maintenance_task_id', 'at', 'meta',
        ]));
        $this->assertTrue(Schema::hasColumns('service_records', [
            'vehicle_id', 'maintenance_id', 'service_type', 'performed_at', 'odometer',
            'workshop_vendor_id', 'labor_cost', 'materials_cost', 'result',
            'related_component_id', 'source', 'source_invoice_item_id',
        ]));

        // The single existing-table touch.
        $this->assertTrue(Schema::hasColumns('maintenance_media', ['vehicle_component_id', 'component_event_id']));
    }

    public function test_asset_layer_flag_defaults_off(): void
    {
        $this->assertSame('off', config('features.asset_layer'));
    }

    // ── 2. Model relationships ──────────────────────────────────────────────────────────────────

    public function test_relationship_graph_resolves_end_to_end(): void
    {
        $catalog   = $this->makeCatalog();
        $vehicle   = $this->makeVehicle();
        $component = $this->makeComponent($catalog, $vehicle, VehicleComponent::STATUS_ACTIVE, VehicleComponent::LOC_ON_VEHICLE);

        $event = ComponentEvent::create([
            'vehicle_component_id' => $component->id,
            'event'                => ComponentEvent::EVENT_INSTALLED,
            'to_vehicle_id'        => $vehicle->id,
            'odometer'             => 50000,
            'at'                   => now(),
        ]);

        $record = ServiceRecord::create([
            'vehicle_id'           => $vehicle->id,
            'service_type'         => 'repair_labor',
            'description'          => 'Fitted test component',
            'performed_at'         => now()->toDateString(),
            'result'               => ServiceRecord::RESULT_COMPLETED,
            'related_component_id' => $component->id,
            'source'               => ServiceRecord::SOURCE_MANUAL,
        ]);

        // Component side.
        $this->assertTrue($component->catalog->is($catalog));
        $this->assertTrue($component->vehicle->is($vehicle));
        $this->assertTrue($component->events->first()->is($event));
        $this->assertTrue($component->serviceRecords->first()->is($record));

        // Vehicle side — the "physical truth" reads.
        $this->assertTrue($vehicle->activeComponents->first()->is($component));
        $this->assertTrue($vehicle->serviceRecords->first()->is($record));

        // Event side.
        $this->assertTrue($event->component->is($component));
        $this->assertTrue($event->toVehicle->is($vehicle));

        // Catalog side.
        $this->assertTrue($catalog->components->first()->is($component));
    }

    public function test_successor_chain_links(): void
    {
        $catalog = $this->makeCatalog();
        $vehicle = $this->makeVehicle();

        $old = $this->makeComponent($catalog, $vehicle, VehicleComponent::STATUS_ACTIVE, VehicleComponent::LOC_ON_VEHICLE);
        $new = $this->makeComponent($catalog, $vehicle, VehicleComponent::STATUS_ACTIVE, VehicleComponent::LOC_ON_VEHICLE);

        // Retire the old one the way ComponentService will (explicit assignment).
        $old->status                    = VehicleComponent::STATUS_RETIRED;
        $old->location                  = VehicleComponent::LOC_SCRAPPED;
        $old->removal_reason            = VehicleComponent::REASON_WORN_OUT;
        $old->disposition               = VehicleComponent::DISP_SCRAPPED;
        $old->removed_at                = now();
        $old->replaced_by_component_id  = $new->id;
        $old->save();

        $this->assertTrue($old->fresh()->replacedBy->is($new));
        $this->assertTrue($new->fresh()->replaces->is($old));
    }

    // ── 3. Status/location invariants ───────────────────────────────────────────────────────────

    public function test_every_invalid_status_location_pair_is_rejected(): void
    {
        $catalog = $this->makeCatalog();
        $vehicle = $this->makeVehicle();

        foreach (VehicleComponent::STATUSES as $status) {
            foreach (VehicleComponent::LOCATIONS as $location) {
                $valid = in_array($location, VehicleComponent::VALID_STATUS_LOCATIONS[$status], true);
                if ($valid) {
                    continue; // valid pairs are covered by the fixture tests above
                }

                try {
                    $needsVehicle = $status === VehicleComponent::STATUS_ACTIVE;
                    $this->makeComponent($catalog, $needsVehicle ? $vehicle : null, $status, $location);
                    $this->fail("Expected DomainException for pair [{$status}, {$location}]");
                } catch (\DomainException $e) {
                    $this->assertStringContainsString('Invalid component state', $e->getMessage());
                }
            }
        }
    }

    public function test_active_requires_a_vehicle_and_in_stock_forbids_one(): void
    {
        $catalog = $this->makeCatalog();
        $vehicle = $this->makeVehicle();

        try {
            $this->makeComponent($catalog, null, VehicleComponent::STATUS_ACTIVE, VehicleComponent::LOC_ON_VEHICLE);
            $this->fail('An active component without a vehicle must be rejected.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('must be attached to a vehicle', $e->getMessage());
        }

        try {
            $this->makeComponent($catalog, $vehicle, VehicleComponent::STATUS_IN_STOCK, VehicleComponent::LOC_WAREHOUSE);
            $this->fail('A stock component attached to a vehicle must be rejected.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('cannot be attached to a vehicle', $e->getMessage());
        }

        // retired MAY keep the last vehicle (sold_with_vehicle / legacy history).
        $kept = $this->makeComponent($catalog, $vehicle, VehicleComponent::STATUS_RETIRED, VehicleComponent::LOC_SOLD);
        $this->assertSame($vehicle->id, $kept->vehicle_id);
    }

    public function test_warranty_until_is_derived_from_install_date(): void
    {
        $catalog   = $this->makeCatalog();
        $vehicle   = $this->makeVehicle();
        $component = $this->makeComponent($catalog, $vehicle, VehicleComponent::STATUS_ACTIVE, VehicleComponent::LOC_ON_VEHICLE, [
            'installed_at'    => '2026-01-10 09:00:00',
            'warranty_months' => 12,
        ]);

        $this->assertSame('2027-01-10', $component->warranty_until->toDateString());

        // No warranty_months → derived expiry clears.
        $component->warranty_months = null;
        $component->save();
        $this->assertNull($component->fresh()->warranty_until);
    }

    public function test_status_and_removal_leg_are_not_mass_assignable(): void
    {
        $component = new VehicleComponent([
            'status'          => VehicleComponent::STATUS_RETIRED,   // must be ignored
            'location'        => VehicleComponent::LOC_SCRAPPED,     // must be ignored
            'removal_reason'  => VehicleComponent::REASON_FAILED,    // must be ignored
            'disposition'     => VehicleComponent::DISP_SCRAPPED,    // must be ignored
            'brand'           => 'Bosch',                            // fillable — must survive
        ]);

        $this->assertNull($component->status);
        $this->assertNull($component->location);
        $this->assertNull($component->removal_reason);
        $this->assertNull($component->disposition);
        $this->assertSame('Bosch', $component->brand);
    }

    // ── 4. Consumables can never become components ─────────────────────────────────────────────

    public function test_consumable_catalog_entry_cannot_instantiate_a_component(): void
    {
        $oil     = $this->makeCatalog(['tracking_mode' => ComponentCatalog::TRACKING_CONSUMABLE, 'slug' => 'oil-' . uniqid()]);
        $vehicle = $this->makeVehicle();

        try {
            $this->makeComponent($oil, $vehicle, VehicleComponent::STATUS_ACTIVE, VehicleComponent::LOC_ON_VEHICLE);
            $this->fail('A consumable must never become a vehicle component.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('consumable', $e->getMessage());
        }

        $this->assertSame(0, VehicleComponent::count());

        // The same work IS recordable as a service — the approved home for consumables.
        $record = ServiceRecord::create([
            'vehicle_id'     => $vehicle->id,
            'service_type'   => 'oil_change',
            'description'    => 'Engine oil 5W-30',
            'performed_at'   => now()->toDateString(),
            'materials_cost' => 120,
            'result'         => ServiceRecord::RESULT_COMPLETED,
            'source'         => ServiceRecord::SOURCE_MANUAL,
        ]);
        $this->assertDatabaseHas('service_records', ['id' => $record->id, 'service_type' => 'oil_change']);
    }

    // ── 5. Catalog seeder ───────────────────────────────────────────────────────────────────────

    public function test_catalog_seeder_is_idempotent_and_honors_tracking_modes(): void
    {
        $this->seed(ComponentCatalogSeeder::class);
        $count = ComponentCatalog::count();
        $this->assertGreaterThan(0, $count);

        // Second run: zero new rows.
        $this->seed(ComponentCatalogSeeder::class);
        $this->assertSame($count, ComponentCatalog::count());

        // Spot-check the contract: battery serialized, brake pads batch+axle, oil consumable.
        $this->assertSame(ComponentCatalog::TRACKING_SERIALIZED, ComponentCatalog::where('slug', 'battery-12v')->value('tracking_mode'));
        $pads = ComponentCatalog::where('slug', 'brake-pads')->first();
        $this->assertSame(ComponentCatalog::TRACKING_BATCH, $pads->tracking_mode);
        $this->assertSame(['front', 'rear'], $pads->positionsFor());
        $this->assertSame(ComponentCatalog::TRACKING_CONSUMABLE, ComponentCatalog::where('slug', 'engine-oil')->value('tracking_mode'));

        // A DB-side retirement survives re-seeding (additive-only rule).
        $pads->update(['is_active' => false]);
        $this->seed(ComponentCatalogSeeder::class);
        $this->assertFalse((bool) $pads->fresh()->is_active);
    }

    // ── 6. Permission boundaries ────────────────────────────────────────────────────────────────

    public function test_component_permissions_land_on_exactly_the_approved_roles(): void
    {
        $manage = [
            'admin'       => true,  // full set (assignable everything-role)
            'manager'     => true,
            'maintenance' => true,  // workshop manager
            'supervisor'  => true,  // authorized maintenance delegates
            'operations'  => false,
            'inspector'   => false, // technician-tier: explicit per-user grant only
            'logistics'   => false, // technician-tier: explicit per-user grant only
            'finance'     => false,
            'viewer'      => false,
        ];

        foreach ($manage as $role => $expected) {
            $this->assertSame(
                $expected,
                Role::findByName($role)->hasPermissionTo('components.manage'),
                "components.manage on role [{$role}]"
            );
        }

        // Every role in the system can at least SEE the asset truth.
        foreach (array_keys($manage) as $role) {
            $this->assertTrue(
                Role::findByName($role)->hasPermissionTo('components.view'),
                "components.view on role [{$role}]"
            );
        }

        // Backfill is admin-tier only.
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('components.backfill'));
        foreach (['manager', 'maintenance', 'supervisor'] as $role) {
            $this->assertFalse(
                Role::findByName($role)->hasPermissionTo('components.backfill'),
                "components.backfill must NOT be on role [{$role}]"
            );
        }
    }

    // ── 7. Regression fence: Phase 1 writes nothing by itself ──────────────────────────────────

    public function test_new_tables_stay_empty_without_explicit_writes(): void
    {
        // RolesAndPermissionsSeeder ran in setUp; no workflow hook exists in Phase 1 —
        // nothing may have written to the asset tables as a side effect.
        $this->assertSame(0, VehicleComponent::count());
        $this->assertSame(0, ComponentEvent::count());
        $this->assertSame(0, ServiceRecord::count());
    }
}
