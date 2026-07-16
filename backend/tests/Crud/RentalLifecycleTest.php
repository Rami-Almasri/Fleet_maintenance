<?php

namespace Tests\Crud;

use App\Models\Contract;
use App\Models\Vehicle;

/**
 * The rental round-trip must maintain correct vehicle state IMMEDIATELY, with no reliance on the
 * nightly OfficeManager reconcile. These lock the stabilised lifecycle: create → car rented now,
 * no double-booking, return frees the car, and the return figures can't go backwards.
 */
class RentalLifecycleTest extends CrudTestCase
{
    /** Creating a rental flips the vehicle to 'rented' in the same request — not after a sync. */
    public function test_creating_rental_marks_vehicle_rented_immediately(): void
    {
        $vid = $this->makeVehicle(['odometer' => 40000]);
        $cid = $this->makeCustomer();

        $this->makeContract(['vehicle_id' => $vid, 'customer_id' => $cid, 'out_milage' => 40000]);

        $this->assertSame('rented', Vehicle::find($vid)->operational_status);
    }

    /** A second rental on a car already out on an open rental is refused (double-booking guard). */
    public function test_double_booking_is_blocked(): void
    {
        $vid = $this->makeVehicle();
        $cid = $this->makeCustomer();
        $this->makeContract(['vehicle_id' => $vid, 'customer_id' => $cid]);

        $second = $this->postJson('/api/Contract', [
            'contract_no'   => 'C-' . strtoupper(uniqid()),
            'contract_type' => 'C',
            'state'         => 'open',
            'vehicle_id'    => $vid,
            'customer_id'   => $this->makeCustomer(),
        ]);

        $second->assertStatus(422);
        // Still exactly one open rental on the car.
        $this->assertSame(1, Contract::where('vehicle_id', $vid)->where('contract_type', 'C')->currentlyOpen()->count());
    }

    /** Closing the rental (return) frees the vehicle back to 'available' right away. */
    public function test_return_frees_vehicle(): void
    {
        $vid = $this->makeVehicle(['odometer' => 40000]);
        $cid = $this->makeCustomer();
        $id  = $this->makeContract(['vehicle_id' => $vid, 'customer_id' => $cid, 'out_milage' => 40000]);

        $this->assertSame('rented', Vehicle::find($vid)->operational_status);

        $this->postJson("/api/Contract/$id/close", ['in_milage' => 40350])->assertSuccessful();

        $this->assertSame('available', Vehicle::find($vid)->operational_status);
        $this->assertSame('closed', Contract::find($id)->state);
    }

    /** A backward return odometer is rejected (V1/V3): in_milage can't be < out_milage. */
    public function test_backward_return_mileage_is_rejected(): void
    {
        $vid = $this->makeVehicle(['odometer' => 40000]);
        $id  = $this->makeContract(['vehicle_id' => $vid, 'customer_id' => $this->makeCustomer(), 'out_milage' => 40000]);

        $this->postJson("/api/Contract/$id/close", ['in_milage' => 39000])->assertStatus(422);

        // The car is untouched — still out on rent, contract still open.
        $this->assertSame('rented', Vehicle::find($vid)->operational_status);
        $this->assertSame('open', Contract::find($id)->state);
    }

    /** A rental (type-C) with no customer is refused (V5 — no orphan contracts). */
    public function test_rental_requires_customer(): void
    {
        $vid = $this->makeVehicle();

        $this->postJson('/api/Contract', [
            'contract_no'   => 'C-' . strtoupper(uniqid()),
            'contract_type' => 'C',
            'state'         => 'open',
            'vehicle_id'    => $vid,
        ])->assertStatus(422);
    }

    /** Closing an already-closed rental is an idempotent no-op (no double-close corruption). */
    public function test_double_close_is_idempotent(): void
    {
        $vid = $this->makeVehicle(['odometer' => 40000]);
        $id  = $this->makeContract(['vehicle_id' => $vid, 'customer_id' => $this->makeCustomer(), 'out_milage' => 40000]);

        $this->postJson("/api/Contract/$id/close", ['in_milage' => 40100])->assertSuccessful();
        // A second close (double-click / retry) must not error or re-write anything.
        $this->postJson("/api/Contract/$id/close", ['in_milage' => 99999])->assertSuccessful();

        $this->assertSame(40100, (int) Contract::find($id)->in_milage);
        $this->assertSame('available', Vehicle::find($vid)->operational_status);
    }
}
