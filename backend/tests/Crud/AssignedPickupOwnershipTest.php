<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * Once a Supervisor names the driver for a pickup, that car is THAT driver's job. Every other driver's
 * queue shows "Assigned to <name>" instead of a Pick up button, and the server refuses them here — so a
 * stale tab or a direct API call cannot let two drivers race for the same car.
 *
 * A pickup with NO driver named stays open to the pool: that is how a garage transfer is raised (the leg
 * is deliberately left unassigned so whoever is free claims it), and this pins that it still works.
 */
class AssignedPickupOwnershipTest extends CrudTestCase
{
    private function driver(string $name): User
    {
        $driver = User::create([
            'name'     => $name,
            'email'    => 'u.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);
        $driver->assignRole('logistics');

        return $driver;
    }

    /** A car parked with us at 40 000 km, garage chosen, waiting for a driver to collect it. */
    private function awaitingPickup(?User $assignee): Maintenance
    {
        $vehicleId = $this->makeVehicle(['odometer' => 40000]);
        $vendorId  = $this->makeVendor();

        $ticket = new Maintenance();
        $ticket->vehicle_id         = $vehicleId;
        $ticket->origin             = Maintenance::ORIGIN_MANUAL;
        $ticket->workflow_status    = Maintenance::WF_AWAITING_DISPATCH;
        $ticket->event_status       = 'IN';
        $ticket->vendor_id          = $vendorId;
        $ticket->assigned_driver_id = $assignee?->id;
        if ($assignee) {
            $ticket->delegation_task   = Maintenance::DELEGATION_PICKUP;
            $ticket->delegation_status = Maintenance::DELEGATION_ASSIGNED;
        }
        $ticket->save();

        return $ticket;
    }

    /** The pickup reading must match the car's last recorded mileage — the park strict-match gate. */
    private function pickUp(Maintenance $ticket)
    {
        return $this->postJson("/api/maintenance-tickets/{$ticket->id}/dispatch", [
            'dispatch_odometer' => 40000,
        ]);
    }

    public function test_the_assigned_driver_can_pick_the_car_up(): void
    {
        $abdullah = $this->driver('Driver Abdullah');
        $ticket   = $this->awaitingPickup($abdullah);

        Sanctum::actingAs($abdullah, ['*']);
        $this->pickUp($ticket)->assertSuccessful();

        $ticket->refresh();
        $this->assertSame(Maintenance::WF_IN_TRANSIT, $ticket->workflow_status);
        $this->assertSame($abdullah->id, $ticket->dispatched_by);
    }

    public function test_another_driver_is_refused_and_told_whose_job_it_is(): void
    {
        $abdullah = $this->driver('Driver Abdullah');
        $sameer   = $this->driver('Driver Sameer');
        $ticket   = $this->awaitingPickup($abdullah);

        Sanctum::actingAs($sameer, ['*']);
        $res = $this->pickUp($ticket);

        $res->assertStatus(422);
        // The refusal names the assignee — the driver must know who to hand it back to.
        $this->assertStringContainsString('Driver Abdullah', (string) $res->json('message'));

        $ticket->refresh();
        $this->assertSame(Maintenance::WF_AWAITING_DISPATCH, $ticket->workflow_status, 'the car must not move');
        $this->assertNull($ticket->dispatched_by);
    }

    public function test_an_unassigned_pickup_stays_open_to_the_pool(): void
    {
        $anyDriver = $this->driver('Driver On Shift');
        $ticket    = $this->awaitingPickup(null);

        Sanctum::actingAs($anyDriver, ['*']);
        $this->pickUp($ticket)->assertSuccessful();

        $this->assertSame(Maintenance::WF_IN_TRANSIT, $ticket->refresh()->workflow_status);
    }
}
