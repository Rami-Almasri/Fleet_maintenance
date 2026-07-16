<?php

namespace Tests\Crud;

use App\Models\LogisticsTask;
use App\Models\User;
use App\Services\DriverAvailabilityService;
use Illuminate\Support\Facades\Hash;

/**
 * Driver Availability is sourced from the LogisticsTask ONLY, and a driver is busy ONLY while physically
 * transporting a car (a transport phase) — never while a task sits at its destination, and never off a
 * maintenance ticket's own status. These assertions lock that contract in.
 */
class DriverAvailabilityTest extends CrudTestCase
{
    private function driver(): User
    {
        $u = User::create([
            'name'     => 'Driver ' . uniqid(),
            'email'    => 'drv.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);
        $u->assignRole('logistics');

        return $u;
    }

    private function leg(int $vehicleId, int $driverId, string $status): LogisticsTask
    {
        return LogisticsTask::create([
            'vehicle_id'        => $vehicleId,
            'vehicle_plate'     => 'T-TEST',
            'destination'       => 'Al Quoz Garage',
            'round_trip'        => false,
            'assigned_to_id'    => $driverId,
            'assigned_to_name'  => 'Driver',
            'assigned_by_id'    => $this->admin->id,
            'assigned_by_name'  => 'Admin',
            'status'            => $status,
            'status_changed_at' => now(),
            'dispatched_at'     => now(),
            'claimed_at'        => now(),
        ]);
    }

    public function test_driver_with_no_task_is_available(): void
    {
        $driver = $this->driver();
        $svc    = app(DriverAvailabilityService::class);

        $this->assertSame('available', $svc->statusFor($driver->id));
        $this->assertArrayNotHasKey($driver->id, $svc->busyByUser([$driver->id]));
    }

    public function test_driver_is_busy_only_in_a_transport_phase(): void
    {
        $vehicle = $this->makeVehicle();
        $driver  = $this->driver();
        $svc     = app(DriverAvailabilityService::class);

        // Moving the car (picked_up) → busy.
        $task = $this->leg($vehicle, $driver->id, LogisticsTask::STATUS_PICKED_UP);
        $this->assertSame('busy', $svc->statusFor($driver->id));

        // Parked at the destination (delivered) but the task is still open → NOT transport → available.
        $task->update(['status' => LogisticsTask::STATUS_DELIVERED, 'status_changed_at' => now()]);
        $this->assertSame('available', $svc->statusFor($driver->id));

        // Task closed → available.
        $task->update(['status' => LogisticsTask::STATUS_RETURNED, 'completed_at' => now()]);
        $this->assertSame('available', $svc->statusFor($driver->id));
    }

    public function test_presence_endpoint_reports_the_transport_leg(): void
    {
        $vehicle = $this->makeVehicle();
        $driver  = $this->driver();
        $this->leg($vehicle, $driver->id, LogisticsTask::STATUS_EN_ROUTE);

        $res = $this->getJson('/api/team/presence');
        $res->assertSuccessful();

        $person = collect($res->json('data.team'))->firstWhere('id', $driver->id);
        $this->assertNotNull($person, 'driver should appear on the presence board');
        $this->assertSame('busy', $person['status']);
        $this->assertSame('move', data_get($person, 'activity.kind'));
    }
}
