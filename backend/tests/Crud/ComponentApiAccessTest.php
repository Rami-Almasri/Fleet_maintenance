<?php

namespace Tests\Crud;

use App\Models\ComponentCatalog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use Database\Seeders\ComponentCatalogSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * The Component read API — who can reach it, what it returns, and that it stays READ-ONLY.
 *
 * The routes were shipped behind `permission:components.view` with no test covering the gate, which
 * meant the seeder could widen or narrow that grant and nothing would notice. These pin the
 * contract from the HTTP side, which is the side an actual browser sees.
 */
class ComponentApiAccessTest extends CrudTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ComponentCatalogSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::create([
            'name'     => "Test {$role}",
            'email'    => $role . '.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function componentOn(Vehicle $vehicle): VehicleComponent
    {
        $catalog   = ComponentCatalog::where('slug', 'alternator')->firstOrFail();
        $component = new VehicleComponent([
            'component_catalog_id' => $catalog->id,
            'serial_no'            => 'API-' . uniqid(),
            'label'                => 'Denso Alternator',
            'source'               => VehicleComponent::SOURCE_WORKFLOW,
            'evidence_channel'     => VehicleComponent::EV_REPAIR_CAPTURE,
            'acquisition'          => VehicleComponent::ACQ_UNKNOWN,
            'installed_at'         => now()->subMonths(3),
            'installed_odometer'   => 100000,
        ]);
        $component->vehicle_id = $vehicle->id;
        $component->status     = VehicleComponent::STATUS_ACTIVE;
        $component->location   = VehicleComponent::LOC_ON_VEHICLE;
        $component->save();

        return $component->fresh();
    }

    // ── Access ──────────────────────────────────────────────────────────────────────────────────

    /** Roles the seeder grants components.view — each must actually reach the board. */
    public static function permittedRoles(): array
    {
        return [['manager'], ['maintenance'], ['supervisor'], ['inspector'], ['logistics'], ['finance'], ['viewer'], ['operations']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('permittedRoles')]
    public function test_roles_holding_components_view_can_read_the_dashboard(string $role): void
    {
        $this->actingAsRole($role);

        $this->getJson('/api/components/dashboard')->assertSuccessful();
    }

    public function test_a_role_without_components_view_is_refused(): void
    {
        // `driver` holds no components.view in the seeder — the gate must bite.
        $user = User::create([
            'name' => 'No Grant', 'email' => 'nogrant.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'), 'status' => 'active',
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/components/dashboard')->assertForbidden();
        $this->getJson('/api/components/catalog')->assertForbidden();
    }

    public function test_every_component_route_sits_behind_auth_and_the_view_permission(): void
    {
        // CrudTestCase authenticates every test as a super-admin, so an unauthenticated request
        // cannot be made from here. Assert the gate declaratively instead — that the routes are
        // registered with BOTH middlewares — which is the thing that would actually be lost if
        // someone moved them out of the group.
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/components')
                || str_contains($r->uri(), '/components'));

        $this->assertTrue($routes->isNotEmpty(), 'no component routes registered');

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, "{$route->uri()} is not behind auth:sanctum");
            $this->assertContains('permission:components.view', $middleware, "{$route->uri()} is not behind components.view");
        }
    }

    // ── Payloads ────────────────────────────────────────────────────────────────────────────────

    public function test_the_catalog_endpoint_returns_seeded_types(): void
    {
        $this->actingAsRole('maintenance');

        $res = $this->getJson('/api/components/catalog')->assertSuccessful();
        $body = json_encode($res->json());

        $this->assertStringContainsString('alternator', $body);
        $this->assertStringNotContainsString('"tracking_mode":"consumable"', $body,
            'the install picker must never offer a consumable');
    }

    public function test_a_vehicles_configuration_is_readable_and_carries_provenance(): void
    {
        // Fixtures are built as the pre-authenticated super-admin; the role under test is adopted
        // afterwards, so a 403 here can only ever be the component gate and never vehicles.manage.
        $vehicle   = Vehicle::findOrFail($this->makeVehicle());
        $component = $this->componentOn($vehicle);
        $this->actingAsRole('maintenance');

        $res = $this->getJson("/api/Vehicle/{$vehicle->id}/components")->assertSuccessful();
        $body = json_encode($res->json());

        $this->assertStringContainsString((string) $component->id, $body);
        $this->assertStringContainsString('Denso Alternator', $body);
    }

    public function test_a_component_dossier_is_readable(): void
    {
        $vehicle   = Vehicle::findOrFail($this->makeVehicle());
        $component = $this->componentOn($vehicle);
        $this->actingAsRole('maintenance');

        $this->getJson("/api/components/{$component->id}")->assertSuccessful();
    }

    public function test_an_empty_vehicle_returns_an_empty_configuration_not_an_error(): void
    {
        $vehicle = Vehicle::findOrFail($this->makeVehicle());
        $this->actingAsRole('maintenance');

        // The normal case for almost every car today — the ledger is young. It must render, not 404.
        $this->getJson("/api/Vehicle/{$vehicle->id}/components")->assertSuccessful();
    }

    public function test_an_unknown_component_is_a_clean_404(): void
    {
        $this->actingAsRole('maintenance');

        $this->getJson('/api/components/99999999')->assertNotFound();
    }

    // ── The read-only invariant ─────────────────────────────────────────────────────────────────

    public function test_there_is_no_write_route_on_the_component_api(): void
    {
        $vehicle = Vehicle::findOrFail($this->makeVehicle());
        $this->actingAsRole('manager');

        // "NOBODY ADDS A COMPONENT BY HAND" — the standing rule the whole layer rests on. If any of
        // these ever answers 2xx, a second source of truth has been created.
        foreach ([
            ['post',   '/api/components'],
            ['post',   "/api/Vehicle/{$vehicle->id}/components"],
            ['put',    '/api/components/1'],
            ['patch',  '/api/components/1'],
            ['delete', '/api/components/1'],
        ] as [$verb, $url]) {
            $status = $this->{$verb . 'Json'}($url)->getStatusCode();
            $this->assertGreaterThanOrEqual(400, $status, "{$verb} {$url} answered {$status} — a write door exists");
        }
    }
}
