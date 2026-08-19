<?php

namespace Tests\Feature;

use App\Models\ComponentCatalog;
use App\Models\PartRequest;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use App\Services\Components\ComponentReadModel;
use App\Services\PartWorkflowService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * END-TO-END guard for the replacement-limit snapshot: request → approve → purchase → install,
 * through the REAL PartWorkflowService, ending at the payload the vehicle Components tab renders.
 *
 * WHY A FEATURE TEST AND NOT A UNIT ONE. The snapshot is written in ComponentService::makeComponent,
 * which is three call-layers below the door an operator actually uses. A unit test on makeComponent
 * would prove the copy happens and still miss the two things that historically break this path:
 * the asset_layer flag silently swallowing the component write in shadow mode, and installFromPurchase
 * passing its own attribute array that could shadow the catalog defaults.
 *
 * @see ComponentLifecycleTest for the pure precedence rules (recorded vs catalog vs none).
 *
 * REQUIRES the `fleet_e2e_scratch` MySQL database — run with `-c phpunit.e2e.xml`, which documents
 * how to build it. There is no RefreshDatabase here on purpose: the full migration set cannot be
 * replayed from empty (a raw MySQL-only ALTER in 2026_06_11_140001 breaks it), so the schema is
 * CLONED from live and the tables this test touches are truncated instead.
 */
class ComponentLimitSnapshotTest extends TestCase
{
    /** Only the tables this test writes to — truncated per test so each starts clean. */
    private const TOUCHED = [
        'vehicle_components', 'component_events', 'component_catalog',
        'part_requests', 'part_purchases', 'maintenance_line_items',
        'vehicle_log_events', 'vehicles', 'users',
    ];

    private User $actor;
    private Vehicle $vehicle;
    private ComponentCatalog $catalog;

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

        // The asset layer is OFF by default (byte-identical legacy behaviour). These tests are
        // about what it writes, so they run it for real.
        config()->set('features.asset_layer', 'enforced');

        $this->actor = User::factory()->create();

        $this->vehicle = Vehicle::create([
            'plate_no' => 'E2E-1910',
            'odometer' => 100_000,
        ]);

        // The real-world case the user reported: 12 months OR 20,000 km, whichever runs out first.
        $this->catalog = ComponentCatalog::create([
            'slug'                 => 'cabin-filter-e2e',
            'name'                 => 'Cabin Filter',
            'category_key'         => 'ac',
            'tracking_mode'        => 'individual',
            'expected_life_months' => 12,
            'expected_life_km'     => 20_000,
            'is_active'            => true,
        ]);
    }

    /** Drive the whole procurement chain and return the component it produced. */
    private function fitAPartThroughTheWorkflow(array $installOverrides = []): ?VehicleComponent
    {
        $svc = app(PartWorkflowService::class);

        $req = $svc->createRequest([
            'source'               => PartRequest::SOURCE_GARAGE,
            'vehicle_id'           => $this->vehicle->id,
            'part_name'            => 'Cabin Filter',
            'component_catalog_id' => $this->catalog->id,
            'category_key'         => $this->catalog->category_key,
            'quantity'             => 1,
            'reason'               => 'Cabin filter clogged at service',
        ], $this->actor);

        $this->assertSame(PartRequest::STATUS_REQUESTED, $req->status, 'the chain must start at requested');

        $req = $svc->approve($req, $this->actor, 'approved by test');
        $this->assertSame('approved', $req->status);

        $result = $svc->purchase($req, [
            'purchase_source' => \App\Models\PartPurchase::SOURCE_SUPPLIER,
            'source_name'     => 'Test Supplier',
            'purchase_price'  => 92.50,
            'currency'        => 'AED',
            'quantity'        => 1,
        ], $this->actor);
        $purchase = $result['purchase'];

        $svc->installPurchase($purchase, array_merge([
            'installed_odometer' => (int) $this->vehicle->odometer,
            'warranty_months'    => 6,
            'component'          => ['component_catalog_id' => $this->catalog->id, 'brand' => 'Bosch'],
            'predecessor'        => ['removal_reason' => 'worn_out', 'disposition' => 'scrapped'],
        ], $installOverrides), $this->actor);

        return VehicleComponent::where('source_part_purchase_id', $purchase->id)->first();
    }

    /**
     * THE HEADLINE. A part fitted through the normal procurement chain must land on the car
     * carrying the limit that was in force the day it was fitted.
     */
    public function test_the_limit_is_stamped_on_a_part_fitted_through_the_full_workflow(): void
    {
        $component = $this->fitAPartThroughTheWorkflow();

        $this->assertNotNull($component, 'the install door produced no component at all');
        $this->assertSame(20_000, $component->expected_life_km);
        $this->assertSame(12, $component->expected_life_months);
    }

    /**
     * The point of the whole change: editing the catalog must NOT retro-score a part already fitted.
     * This is the regression that made the Replaced view lie about what a part was bought for.
     */
    public function test_editing_the_catalog_afterwards_does_not_move_a_fitted_part(): void
    {
        $component = $this->fitAPartThroughTheWorkflow();

        // Somebody corrects the type on /parts-catalog, long after this part went on the car.
        $this->catalog->update(['expected_life_km' => 5_000, 'expected_life_months' => 3]);

        $payload = $this->installedRowFor($component->id);

        $this->assertSame(20_000, $payload['service_life']['expected_life_km'], 'the fitted part must keep its own limit');
        $this->assertSame(12, $payload['service_life']['expected_life_months']);
        $this->assertSame('recorded', $payload['service_life']['limit_source']);
    }

    /** The page must say the limit came from the fitting, not from today's catalogue. */
    public function test_the_components_page_payload_reports_the_limit_and_its_source(): void
    {
        $component = $this->fitAPartThroughTheWorkflow();
        $life      = $this->installedRowFor($component->id)['service_life'];

        $this->assertSame('recorded', $life['limit_source']);
        $this->assertSame(20_000, $life['expected_life_km']);
        $this->assertSame(12, $life['expected_life_months']);
    }

    /**
     * A row written before the snapshot columns existed has no limit of its own. It must fall back
     * to the catalog AND be labelled as such — never passed off as a recorded historical fact.
     */
    public function test_a_legacy_row_falls_back_to_the_catalog_and_says_so(): void
    {
        $component = $this->fitAPartThroughTheWorkflow();

        // Exactly the state of every row that predates the migration.
        $component->forceFill(['expected_life_km' => null, 'expected_life_months' => null])->save();

        $life = $this->installedRowFor($component->id)['service_life'];

        $this->assertSame('catalog', $life['limit_source']);
        $this->assertSame(20_000, $life['expected_life_km'], 'the catalogue still supplies the number');
    }

    /** Pull one row out of the payload the Components tab actually consumes. */
    private function installedRowFor(int $componentId): array
    {
        $out = app(ComponentReadModel::class)->vehicleConfiguration($this->vehicle->fresh());

        foreach (array_merge($out['installed'], $out['history']) as $row) {
            if (($row['id'] ?? null) === $componentId) {
                return $row;
            }
        }

        $this->fail("component #{$componentId} is missing from the vehicle configuration payload");
    }
}
