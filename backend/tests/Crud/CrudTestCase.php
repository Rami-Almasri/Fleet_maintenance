<?php

namespace Tests\Crud;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Base case for the live CRUD / notification smoke suite.
 *
 * Boots the real app against the isolated `laravel_test` MySQL schema, seeds the
 * RBAC roles/permissions ONCE, then authenticates each test as a fresh super-admin
 * (which bypasses every permission gate via Gate::before — see AppServiceProvider).
 * That lets us exercise every CRUD endpoint without wiring up per-role fixtures.
 */
abstract class CrudTestCase extends TestCase
{
    use RefreshDatabase;

    /** Seed the RBAC roles/permissions once, right after the single migrate:fresh. */
    protected $seed = true;
    protected $seeder = RolesAndPermissionsSeeder::class;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Test Super Admin',
            'email'    => 'super.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);
        $this->admin->assignRole('super-admin');

        Sanctum::actingAs($this->admin, ['*']);
    }

    /** Pull the `id` out of a { data: {...} } success envelope. */
    protected function idOf(TestResponse $res): int
    {
        return (int) data_get($res->json(), 'data.id');
    }

    // ── Fixture builders (via the real store endpoints, so they double as create tests) ──

    protected function makeVendor(array $overrides = []): int
    {
        $res = $this->postJson('/api/Vendor', array_merge([
            'name' => 'Test Garage ' . uniqid(),
            'type' => 'garage',
        ], $overrides));
        $res->assertSuccessful();

        return $this->idOf($res);
    }

    protected function makeCustomer(array $overrides = []): int
    {
        $res = $this->postJson('/api/Customer', array_merge([
            'name_en' => 'Test Customer ' . uniqid(),
            'mobile1' => '0500000000',
        ], $overrides));
        $res->assertSuccessful();

        return $this->idOf($res);
    }

    protected function makeVehicle(array $overrides = []): int
    {
        $res = $this->postJson('/api/Vehicle', array_merge([
            'vin'      => 'VIN' . strtoupper(uniqid()),
            'plate_no' => 'T-' . random_int(10000, 99999),
            'make'     => 'Toyota',
            'model'    => 'Camry',
            'year'     => 2022,
            'odometer' => 40000,
        ], $overrides));
        $res->assertSuccessful();

        return $this->idOf($res);
    }

    protected function makeContract(array $overrides = []): int
    {
        // A rental (type-C) now requires a vehicle and a customer, so a realistic contract auto-
        // provisions both unless the caller supplies them. Callers testing overlap/double-booking
        // pass an explicit vehicle_id to reuse the same car.
        $overrides['vehicle_id']  = $overrides['vehicle_id']  ?? $this->makeVehicle();
        $overrides['customer_id'] = $overrides['customer_id'] ?? $this->makeCustomer();

        $res = $this->postJson('/api/Contract', array_merge([
            'contract_no'   => 'C-' . strtoupper(uniqid()),
            'contract_type' => 'C',
            'state'         => 'open',
        ], $overrides));
        $res->assertSuccessful();

        return $this->idOf($res);
    }
}
