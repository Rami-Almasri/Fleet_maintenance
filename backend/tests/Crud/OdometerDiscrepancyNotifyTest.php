<?php

namespace Tests\Crud;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\FleetAlert;
use App\Services\MaintenanceWorkflowService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * An odometer reading can never DECREASE the car's mileage, at any workflow stage — including a
 * garage-to-garage transfer. Exercises MaintenanceWorkflowService::recordGarageTransferOdometer
 * → assertNoDecrease (hard block, no acknowledgment override, whatever the stage).
 */
class OdometerDiscrepancyNotifyTest extends CrudTestCase
{
    public function test_backward_transfer_reading_is_hard_blocked(): void
    {
        $vehicleId = $this->makeVehicle(['odometer' => 40000]);
        $vehicle   = Vehicle::find($vehicleId);

        // A supervisor who would have been alerted under the old accept-and-notify behavior — kept to
        // prove NO alert fires now that the reading is rejected outright instead of recorded.
        $supervisor = User::create([
            'name'     => 'Supervisor Waleed',
            'email'    => 'sup.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);
        $supervisor->givePermissionTo('maintenance.delegate');

        // A minimal ticket on the car, mid-repair (as if being transferred between garages).
        $ticket = new Maintenance();
        $ticket->vehicle_id      = $vehicleId;
        $ticket->origin          = Maintenance::ORIGIN_MANUAL;
        $ticket->workflow_status = Maintenance::WF_UNDER_REPAIR;
        $ticket->event_status    = 'OUT';
        $ticket->save();
        $ticket->setRelation('vehicle', $vehicle);

        Notification::fake();

        // A garage-transfer reading 100 km BELOW the car's mileage is impossible — an odometer never
        // runs backwards, whatever the stage — so it's rejected, not recorded-with-a-note.
        $this->expectException(WorkflowTransitionException::class);

        try {
            app(MaintenanceWorkflowService::class)
                ->recordGarageTransferOdometer($ticket, 39900, null, $this->admin);
        } finally {
            Notification::assertNothingSent();
        }
    }

    public function test_a_normal_forward_reading_raises_no_discrepancy_alert(): void
    {
        $vehicleId = $this->makeVehicle(['odometer' => 40000]);
        $vehicle   = Vehicle::find($vehicleId);

        $ticket = new Maintenance();
        $ticket->vehicle_id      = $vehicleId;
        $ticket->origin          = Maintenance::ORIGIN_MANUAL;
        $ticket->workflow_status = Maintenance::WF_UNDER_REPAIR;
        $ticket->event_status    = 'OUT';
        $ticket->save();
        $ticket->setRelation('vehicle', $vehicle);

        Notification::fake();

        // Forward travel on a transfer is expected — no discrepancy, no alert.
        $flag = app(MaintenanceWorkflowService::class)
            ->recordGarageTransferOdometer($ticket, 40200, null, $this->admin);

        $this->assertNotSame('discrepancy', $flag['status']);
        Notification::assertNothingSent();
    }
}
