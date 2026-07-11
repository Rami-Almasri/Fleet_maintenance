<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\FleetAlert;
use App\Services\MaintenanceWorkflowService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * A BACKWARD odometer reading that is recorded (not hard-blocked) — e.g. a garage-transfer reading below
 * the car's last mileage — must alert the supervisors/controllers, not just sit passively on the audit
 * board. Exercises MaintenanceWorkflowService::recordOdometerFlag → notifyOdometerDiscrepancy.
 */
class OdometerDiscrepancyNotifyTest extends CrudTestCase
{
    public function test_backward_reading_flags_discrepancy_and_alerts_supervisors(): void
    {
        $vehicleId = $this->makeVehicle(['odometer' => 40000]);
        $vehicle   = Vehicle::find($vehicleId);

        // A supervisor who should be alerted (holds the dispatch permission the alert targets).
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

        // Record a garage-transfer reading 100 km BELOW the car's mileage — an impossible backwards move.
        $flag = app(MaintenanceWorkflowService::class)
            ->recordGarageTransferOdometer($ticket, 39900, null, $this->admin);

        $this->assertSame('discrepancy', $flag['status']);

        // The supervisor gets a FleetAlert about the backward reading.
        Notification::assertSentTo($supervisor, FleetAlert::class);
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
