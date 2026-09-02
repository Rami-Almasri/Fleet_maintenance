<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * "Assign driver" (delegation) belongs to ONE stage: Awaiting Pickup. That is the only moment where the
 * garage is decided, the car is still parked with us, and the open question is who drives it. The UI now
 * shows the button only there; these tests hold the server to the same rule so a stale tab or a direct
 * API call cannot assign a driver at any other stage.
 *
 * They also pin the second half of the change: the caller no longer sends `delegation_task` — at Awaiting
 * Pickup the leg is always a pickup, so the server stamps it.
 */
class DelegateDriverStageGateTest extends CrudTestCase
{
    /** A ticket on a real car, parked with us, sitting at the given stage. */
    private function ticketAt(string $status, string $eventStatus = 'IN'): Maintenance
    {
        $vehicleId = $this->makeVehicle(['odometer' => 40000]);

        $ticket = new Maintenance();
        $ticket->vehicle_id      = $vehicleId;
        $ticket->origin          = Maintenance::ORIGIN_MANUAL;
        $ticket->workflow_status = $status;
        $ticket->event_status    = $eventStatus;
        $ticket->save();
        $ticket->setRelation('vehicle', Vehicle::find($vehicleId));

        return $ticket;
    }

    /** A member of the field driver pool — the `logistics` ROLE, not just the permission. */
    private function logisticsDriver(string $name = 'Driver Abdullah'): User
    {
        $driver = $this->makeUser($name);
        $driver->assignRole('logistics');

        return $driver;
    }

    private function makeUser(string $name): User
    {
        return User::create([
            'name'     => $name,
            'email'    => 'u.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);
    }

    public function test_a_driver_can_be_assigned_at_awaiting_pickup(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_AWAITING_DISPATCH);
        $driver = $this->logisticsDriver();

        // No delegation_task in the body — the client no longer asks the supervisor for it.
        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/delegate", [
            'driver_id' => $driver->id,
        ]);

        $res->assertSuccessful();

        $ticket->refresh();
        $this->assertSame($driver->id, $ticket->assigned_driver_id);
        $this->assertSame(Maintenance::DELEGATION_ASSIGNED, $ticket->delegation_status);
        $this->assertSame($this->admin->id, $ticket->delegated_by);
        // The leg is stamped by the server, not chosen: at this stage the car is with us, so it's a pickup.
        $this->assertSame(Maintenance::DELEGATION_PICKUP, $ticket->delegation_task);
        // The driver watches the ticket from now on.
        $this->assertTrue($ticket->watchers()->where('users.id', $driver->id)->exists());
    }

    /**
     * Every other stage is refused — before Awaiting Pickup there is nothing to collect, and after it the
     * car has already moved (custody belongs to the dispatch / collect actions).
     */
    #[DataProvider('forbiddenStages')]
    public function test_other_stages_refuse_the_assignment(string $status, string $eventStatus): void
    {
        $ticket = $this->ticketAt($status, $eventStatus);
        $driver = $this->logisticsDriver();

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/delegate", [
            'driver_id' => $driver->id,
        ]);

        $res->assertStatus(422);

        $ticket->refresh();
        $this->assertNull($ticket->assigned_driver_id);
        $this->assertNull($ticket->delegation_status);
    }

    public static function forbiddenStages(): array
    {
        return [
            'not dispatched yet'  => [Maintenance::WF_INSPECTION_PENDING, 'IN'],
            'en route'            => [Maintenance::WF_IN_TRANSIT,         'OUT'],
            'at the garage'       => [Maintenance::WF_UNDER_REPAIR,       'OUT'],
            'repair signed off'   => [Maintenance::WF_READY_FOR_PICKUP,   'OUT'],
            'back in our park'    => [Maintenance::WF_IN_OUR_PARK,        'IN'],
            'closed'              => [Maintenance::WF_CLOSED,             'IN'],
        ];
    }

    /** The target still has to be a driver — the stage gate did not replace that check. */
    public function test_a_non_driver_user_is_still_rejected(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_AWAITING_DISPATCH);

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/delegate", [
            'driver_id' => $this->makeUser('Office Clerk')->id,
        ]);

        $res->assertStatus(422);
        $this->assertNull($ticket->refresh()->assigned_driver_id);
    }

    /**
     * A supervisor IS assignable — as the fallback when no driver is free.
     *
     * This test previously asserted the opposite. MaintenanceWorkflowController::assignableDrivers now
     * queries `role(['logistics', SUPERVISOR_ROLE])` and says why in its own comment: drivers are the
     * default answer and supervisors are the fallback, so a car is never stuck because the pool is
     * empty. The pool being WIDER than the driver roster is the deliberate change; what stays true is
     * that it is a ROLE pool, which is what the manager assertion below still pins.
     */
    public function test_a_supervisor_can_be_assigned_as_the_fallback_driver(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_AWAITING_DISPATCH);

        $supervisor = $this->makeUser('Supervisor Waleed');
        $supervisor->assignRole('supervisor');

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/delegate", [
            'driver_id' => $supervisor->id,
        ]);

        $res->assertSuccessful();
        $this->assertSame($supervisor->id, $ticket->refresh()->assigned_driver_id);
    }

    /** Somebody in neither pool is still refused — the pool is a ROLE, not "anyone with the permission". */
    public function test_a_workshop_manager_is_not_an_assignable_driver(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_AWAITING_DISPATCH);

        $manager = $this->makeUser('Workshop Lin');
        $manager->assignRole('maintenance');

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/delegate", [
            'driver_id' => $manager->id,
        ]);

        $res->assertStatus(422);
        $this->assertNull($ticket->refresh()->assigned_driver_id);
    }

    /** Nobody assigns the car to themselves — that is "Pick up", not a delegation. */
    public function test_you_cannot_assign_the_car_to_yourself(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_AWAITING_DISPATCH);
        $this->admin->assignRole('logistics'); // even when the actor IS in the driver pool

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/delegate", [
            'driver_id' => $this->admin->id,
        ]);

        $res->assertStatus(422);
        $this->assertNull($ticket->refresh()->assigned_driver_id);
    }

    /** The picker lists the two role pools — drivers first, supervisors as fallback, never yourself. */
    public function test_the_picker_lists_drivers_then_supervisors_and_never_yourself(): void
    {
        $driver     = $this->logisticsDriver('Driver Abdullah');
        $supervisor = $this->makeUser('Supervisor Waleed');
        $supervisor->assignRole('supervisor');
        $manager = $this->makeUser('Workshop Lin');
        $manager->assignRole('maintenance');
        $this->admin->assignRole('logistics'); // the actor is in the pool too — still excluded from their own list

        $ids = collect($this->getJson('/api/maintenance-tickets/assignable-drivers')->assertSuccessful()->json('data'))
            ->pluck('id')->all();

        $this->assertContains($driver->id, $ids);
        // The supervisor is offered as the FALLBACK — see the fallback test above.
        $this->assertContains($supervisor->id, $ids);
        // A workshop manager is in neither pool, and nobody is ever offered themselves.
        $this->assertNotContains($manager->id, $ids);
        $this->assertNotContains($this->admin->id, $ids);

        // Drivers lead the list; the supervisor is the answer of last resort, not the first suggestion.
        $this->assertSame($driver->id, $ids[0], 'a real driver must be offered before the fallback');
    }

    /** A suspended driver is off the roster and must not be offered. */
    public function test_the_picker_skips_suspended_drivers(): void
    {
        $active = $this->logisticsDriver('Driver Active');
        $gone   = $this->logisticsDriver('Driver Suspended');
        $gone->status = 'suspended';
        $gone->save();

        $ids = collect($this->getJson('/api/maintenance-tickets/assignable-drivers')->assertSuccessful()->json('data'))
            ->pluck('id')->all();

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($gone->id, $ids);
    }
}
