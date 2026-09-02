<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\MaintenanceRequiredPart;
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

    /**
     * Record a part ON a ticket, and return the `part_source` pair that lets it be BILLED.
     *
     * MaintenanceInvoiceService::assertPartBillable refuses a part line that names no record on the
     * ticket ("part_not_on_ticket"), because a price with nothing behind it is a number nobody can
     * check — and because a part billed both here and on a supplier's parts invoice is charged twice.
     * So a test that bills a part must first record one, exactly as a user would.
     *
     * The required-part line is the lightest of the three origins (bought / requested / listed as
     * required): it carries no supplier and no price, so it adds nothing to the ticket's money and
     * leaves the test measuring only what it meant to.
     *
     * @return array{part_source:string, part_source_id:int}  merge straight into a part line
     */
    protected function billablePart(Maintenance $ticket, string $name = 'Brake Pad Set', string $finding = 'Brake noise'): array
    {
        $required = MaintenanceRequiredPart::create([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $ticket->vehicle_id,
            'finding_text'   => $finding,
            'part_name'      => $name,
            'quantity'       => 1,
        ]);

        return [
            'part_source'    => MaintenanceLineItem::PART_SOURCE_REQUIRED,
            'part_source_id' => $required->id,
        ];
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
