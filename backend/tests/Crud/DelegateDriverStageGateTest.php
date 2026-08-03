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

    /** A user who may actually pick up / drop off cars. */
    private function logisticsDriver(): User
    {
        $driver = User::create([
            'name'     => 'Driver Abdullah',
            'email'    => 'drv.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);
        $driver->givePermissionTo('maintenance.logistics');

        return $driver;
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

    /** The driver still has to be a logistics driver — the stage gate did not replace that check. */
    public function test_a_non_logistics_user_is_still_rejected(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_AWAITING_DISPATCH);

        $officeUser = User::create([
            'name'     => 'Office Clerk',
            'email'    => 'clerk.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/delegate", [
            'driver_id' => $officeUser->id,
        ]);

        $res->assertStatus(422);
        $this->assertNull($ticket->refresh()->assigned_driver_id);
    }
}
