<?php

namespace Tests\Crud;

/**
 * Full create → read (index + show) → update → delete cycles for the core
 * fleet entities, driven through the real API + permission stack.
 */
class CoreCrudTest extends CrudTestCase
{
    // ── Vendors ──────────────────────────────────────────────────────────────
    public function test_vendor_crud_cycle(): void
    {
        $id = $this->makeVendor(['name' => 'Alpha Motors', 'type' => 'garage']);

        $this->getJson('/api/Vendor')->assertSuccessful();
        $this->getJson("/api/Vendor/$id")->assertSuccessful()
            ->assertJsonPath('data.name', 'Alpha Motors');

        $this->postJson("/api/Vendor/$id", ['name' => 'Alpha Motors Renamed', 'type' => 'garage'])
            ->assertSuccessful()->assertJsonPath('data.name', 'Alpha Motors Renamed');

        $this->deleteJson("/api/Vendor/$id")->assertSuccessful();
        $this->getJson("/api/Vendor/$id")->assertStatus(404);
    }

    // ── Drivers ──────────────────────────────────────────────────────────────
    public function test_driver_crud_cycle(): void
    {
        $res = $this->postJson('/api/Driver', ['name' => 'Sami Driver', 'phone' => '0501234567', 'status' => 'active']);
        $res->assertSuccessful();
        $id = $this->idOf($res);

        $this->getJson('/api/Driver')->assertSuccessful();
        $this->getJson("/api/Driver/$id")->assertSuccessful()->assertJsonPath('data.name', 'Sami Driver');

        $this->postJson("/api/Driver/$id", ['name' => 'Sami Updated', 'status' => 'suspended'])
            ->assertSuccessful()->assertJsonPath('data.name', 'Sami Updated');

        $this->deleteJson("/api/Driver/$id")->assertSuccessful();
    }

    // ── Customers ────────────────────────────────────────────────────────────
    public function test_customer_crud_cycle(): void
    {
        $id = $this->makeCustomer(['name_en' => 'Khalid Ahmed', 'mobile1' => '0509998888']);

        $this->getJson('/api/Customer')->assertSuccessful();
        $this->getJson("/api/Customer/$id")->assertSuccessful()->assertJsonPath('data.name_en', 'Khalid Ahmed');
        $this->getJson("/api/Customer/$id/profile")->assertSuccessful();
        $this->getJson("/api/Customer/$id/balance")->assertSuccessful();

        $this->postJson("/api/Customer/$id", ['name_en' => 'Khalid Renamed', 'mobile1' => '0509998888'])
            ->assertSuccessful()->assertJsonPath('data.name_en', 'Khalid Renamed');

        $this->deleteJson("/api/Customer/$id")->assertSuccessful();
    }

    public function test_customer_requires_identity(): void
    {
        // Empty POST must be rejected (the historical "nameless customer" guard).
        $this->postJson('/api/Customer', [])->assertStatus(422);
    }

    // ── Vehicles ─────────────────────────────────────────────────────────────
    public function test_vehicle_crud_cycle(): void
    {
        $id = $this->makeVehicle(['make' => 'Nissan', 'model' => 'Sunny']);

        $this->getJson('/api/Vehicle')->assertSuccessful();
        $this->getJson("/api/Vehicle/$id")->assertSuccessful()->assertJsonPath('data.model', 'Sunny');
        $this->getJson("/api/Vehicle/$id/profile")->assertSuccessful();

        // A >10km odometer jump is a "significant change" that requires an approval note (the
        // OdometerChangeRequest guard), so supply one — the edit still succeeds (odometer filed
        // for admin approval, the rest applies now).
        $this->postJson("/api/Vehicle/$id", [
            'model' => 'Sunny SV',
            'odometer' => 41000,
            'odometer_change_note' => 'Correcting mileage after service.',
        ])->assertSuccessful();

        $this->deleteJson("/api/Vehicle/$id")->assertSuccessful();
    }

    public function test_vehicle_requires_vin(): void
    {
        $this->postJson('/api/Vehicle', ['make' => 'Kia'])->assertStatus(422);
    }

    public function test_vehicle_vin_must_be_unique(): void
    {
        $this->makeVehicle(['vin' => 'DUPLICATEVIN123']);
        $this->postJson('/api/Vehicle', ['vin' => 'DUPLICATEVIN123'])->assertStatus(422);
    }

    // ── Contracts ────────────────────────────────────────────────────────────
    public function test_contract_crud_cycle(): void
    {
        $vehicle  = $this->makeVehicle();
        $customer = $this->makeCustomer();

        $res = $this->postJson('/api/Contract', [
            'contract_no'   => 'C-CYCLE-1',
            'contract_type' => 'C',
            'state'         => 'open',
            'vehicle_id'    => $vehicle,
            'customer_id'   => $customer,
            'day_price'     => 150,
        ]);
        $res->assertSuccessful();
        $id = $this->idOf($res);

        $this->getJson('/api/Contract')->assertSuccessful();
        $this->getJson("/api/Contract/$id")->assertSuccessful();

        $this->postJson("/api/Contract/$id", ['contract_no' => 'C-CYCLE-1', 'contract_type' => 'C', 'day_price' => 175])
            ->assertSuccessful();

        $this->deleteJson("/api/Contract/$id")->assertSuccessful();
    }

    public function test_contract_next_no_endpoint(): void
    {
        $this->getJson('/api/Contract/next-no')->assertSuccessful();
    }

    // ── Invoices (manual, service-log) ───────────────────────────────────────
    public function test_invoice_crud_cycle(): void
    {
        $contract = $this->makeContract();

        $res = $this->postJson('/api/Invoice', [
            'contract_id' => $contract,
            'invoice_date' => '2026-07-01',
            'total_value' => 500,
            'vat_percentage' => 5,
        ]);
        $res->assertSuccessful();
        $id = $this->idOf($res);

        $this->getJson('/api/Invoice')->assertSuccessful();
        $this->getJson("/api/Invoice?contract_id=$contract")->assertSuccessful();
        $this->getJson("/api/Invoice/$id")->assertSuccessful();
        $this->getJson('/api/Invoice/status-summary')->assertSuccessful();

        $this->postJson("/api/Invoice/$id", ['total_value' => 600])->assertSuccessful();
        $this->deleteJson("/api/Invoice/$id")->assertSuccessful();
    }

    public function test_invoice_requires_contract(): void
    {
        $this->postJson('/api/Invoice', ['total_value' => 100])->assertStatus(422);
    }

    // ── Payments ─────────────────────────────────────────────────────────────
    public function test_payment_crud_cycle(): void
    {
        $contract = $this->makeContract();

        $res = $this->postJson('/api/Payment', [
            'contract_id' => $contract,
            'amount' => 250.50,
            'paid_on' => '2026-07-02',
            'method' => 'cash',
        ]);
        $res->assertSuccessful();
        $id = $this->idOf($res);

        $this->getJson('/api/Payment')->assertSuccessful();
        $this->getJson("/api/Payment/$id")->assertSuccessful();
        $this->postJson("/api/Payment/$id", ['amount' => 300])->assertSuccessful();
        $this->deleteJson("/api/Payment/$id")->assertSuccessful();
    }

    public function test_payment_requires_positive_amount(): void
    {
        $contract = $this->makeContract();
        $this->postJson('/api/Payment', ['contract_id' => $contract, 'amount' => 0])->assertStatus(422);
    }
}
