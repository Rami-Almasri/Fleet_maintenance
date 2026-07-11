<?php

namespace Tests\Crud;

use App\Models\OdometerBlockEvent;
use Illuminate\Http\UploadedFile;

/**
 * A rejected odometer entry at a strict-match park stage must be AUDITED (who tried it, what value) even
 * though the workflow throws the attempt away — and it must surface on /oversight/mileage as a "blocked"
 * row. Covers the write side (logOdometerBlock) end-to-end via the real start-diagnostic endpoint, and the
 * read side (WorkflowOversightController::mileageDiscrepancies merges the block-events table).
 */
class OdometerBlockAuditTest extends CrudTestCase
{
    public function test_out_of_range_test_odometer_is_blocked_audited_and_surfaced(): void
    {
        $vehicleId = $this->makeVehicle(['odometer' => 40000]);

        // Start a diagnostic ("Being Inspected") with a reading 200 km over the car's mileage — beyond the
        // 5 km allowance, so the strict-match gate rejects it.
        $res = $this->postJson('/api/maintenance-tickets', [
            'vehicle_id'     => $vehicleId,
            'trigger_reason' => 'test_drive',
            'test_odometer'  => 40200,
            'odometer_photo' => UploadedFile::fake()->image('odo.jpg'),
        ]);

        // The attempt is rejected (a WorkflowTransitionException → 4xx) and spawns NO ticket.
        $this->assertTrue($res->status() >= 400, 'Out-of-range reading should be rejected');
        $this->assertDatabaseCount('maintenances', 0);

        // …but the blocked attempt is durably audited: who tried it, and the exact rejected value.
        $this->assertDatabaseHas('odometer_block_events', [
            'vehicle_id' => $vehicleId,
            'stage_key'  => 'test_drive',
            'status'     => 'exact_required',
            'reading'    => 40200,
            'previous'   => 40000,
            'actor_id'   => $this->admin->id,
        ]);
        $this->assertSame(1, OdometerBlockEvent::count());

        // …and it surfaces on /oversight/mileage as a top-priority "blocked" row.
        $page = $this->getJson('/api/Oversight/mileage-discrepancies');
        $page->assertSuccessful();
        $page->assertJsonPath('data.blocked', 1);

        $rows = collect($page->json('data.rows'));
        $blocked = $rows->firstWhere('kind', 'blocked');
        $this->assertNotNull($blocked, 'A blocked row should be present');
        $this->assertSame('blocked', $blocked['outcome']);
        $this->assertSame(40200, $blocked['reading']);
        $this->assertSame($this->admin->name, $blocked['entered_by']);
    }

    public function test_an_exact_reading_starts_the_diagnostic_with_no_block(): void
    {
        $vehicleId = $this->makeVehicle(['odometer' => 40000]);

        $res = $this->postJson('/api/maintenance-tickets', [
            'vehicle_id'     => $vehicleId,
            'trigger_reason' => 'test_drive',
            'test_odometer'  => 40000, // exact match — clean
            'odometer_photo' => UploadedFile::fake()->image('odo.jpg'),
        ]);

        $res->assertSuccessful();
        $this->assertDatabaseCount('maintenances', 1);
        $this->assertSame(0, OdometerBlockEvent::count());
    }
}
