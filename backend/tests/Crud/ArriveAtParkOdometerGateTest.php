<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The return leg (garage → our park) is a DRIVEN trip, so the arrival-at-park odometer must be strictly
 * HIGHER than the garage-OUT reading — a car can't arrive back on the same odometer it left the garage on.
 */
class ArriveAtParkOdometerGateTest extends CrudTestCase
{
    private function ticketReadyForPickup(int $garageOut): Maintenance
    {
        $vehicleId = $this->makeVehicle(['odometer' => $garageOut]);
        Vehicle::find($vehicleId)->update(['operational_status' => 'maintenance']);

        return Maintenance::create([
            'vehicle_id'                => $vehicleId,
            'vendor_id'                 => $this->makeVendor(['name' => 'Ajman Sticar Shop']),
            'origin'                    => Maintenance::ORIGIN_MANUAL,
            'workflow_status'           => Maintenance::WF_READY_FOR_PICKUP,
            'event_status'              => 'OUT',
            'fault_severity'            => 'routine', // minor → auto-closes on a valid arrival
            'out_date'                  => now()->toDateString(),
            'findings'                  => [['text' => 'Coolant leak', 'source' => 'inspector']],
            'return_odometer'           => $garageOut, // the garage-OUT reading the arrival must beat
            'receive_odometer'          => $garageOut,
            'dispatch_odometer'         => $garageOut,
            // Custody: the collector must be the one arriving — make it the acting admin.
            'picked_up_from_garage_by'  => $this->admin->id,
            'picked_up_from_garage_at'  => now(),
        ])->fresh();
    }

    public function test_equal_arrival_reading_is_rejected(): void
    {
        Storage::fake('public');
        Storage::fake('s3');
        $ticket = $this->ticketReadyForPickup(62429);

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/arrive-at-park", [
            'park_odometer'  => 62429, // equal to the garage-OUT reading → impossible for a driven return
            'odometer_photo' => UploadedFile::fake()->image('park.jpg'),
        ]);

        $res->assertStatus(422);
        // The message spells out WHY: the car had to travel garage → parking.
        $this->assertStringContainsString('must be greater than the garage departure', $res->getContent());
        $this->assertStringContainsString('travelled from the garage to our parking', $res->getContent());
        $this->assertSame(Maintenance::WF_READY_FOR_PICKUP, $ticket->fresh()->workflow_status);
    }

    public function test_higher_arrival_reading_is_accepted(): void
    {
        Storage::fake('public');
        Storage::fake('s3');
        $ticket = $this->ticketReadyForPickup(62429);

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/arrive-at-park", [
            'park_odometer'  => 62435, // the return drive covered real distance
            'odometer_photo' => UploadedFile::fake()->image('park.jpg'),
        ]);

        $res->assertSuccessful();
        $this->assertNotSame(Maintenance::WF_READY_FOR_PICKUP, $ticket->fresh()->workflow_status);
    }
}
