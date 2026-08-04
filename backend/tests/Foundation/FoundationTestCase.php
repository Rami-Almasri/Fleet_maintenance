<?php

namespace Tests\Foundation;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Base case for the foundation suite (schema, seeder, Parts Catalog, Warranties).
 *
 * DatabaseTransactions, deliberately, NOT RefreshDatabase: every test runs inside a transaction
 * that is rolled back afterwards, so no test ever issues DDL. See phpunit.foundation.xml for why
 * that matters here — `migrate:fresh` is broken on this MySQL install and poisons any schema it
 * touches. The trade-off is that the schema and the RBAC/catalog baseline must already exist in
 * `fleet_test`; the config header lists the one-time setup.
 *
 * Each test authenticates as a fresh super-admin (Gate::before bypasses every permission check —
 * see AppServiceProvider), so endpoint tests exercise behaviour rather than role wiring. Tests that
 * care about permissions build their own user and assign roles explicitly.
 */
abstract class FoundationTestCase extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Foundation Admin',
            'email'    => 'foundation.'.uniqid().'@fleet.test',
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

    /**
     * A vehicle created directly rather than through the API — this suite is about the parts and
     * warranty layer, and a fixture that fails because an unrelated endpoint's validation changed
     * is a false alarm, not a finding.
     */
    protected function makeVehicle(array $overrides = []): \App\Models\Vehicle
    {
        return \App\Models\Vehicle::create(array_merge([
            'vin'      => 'VIN'.strtoupper(uniqid()),
            'plate_no' => 'T-'.random_int(10000, 99999),
            'make'     => 'Toyota',
            'model'    => 'Camry',
            'year'     => 2022,
            'odometer' => 40000,
        ], $overrides));
    }

    protected function makeVendor(array $overrides = []): \App\Models\Vendor
    {
        return \App\Models\Vendor::create(array_merge([
            'name' => 'Test Garage '.uniqid(),
            'type' => 'garage',
        ], $overrides));
    }
}
