<?php

namespace Tests\Crud;

use App\Models\Vehicle;

/**
 * PR6 — /api/intelligence/service-due.
 *
 * Verifies the board envelope + that an overdue car (distance since last service ≥ interval) is
 * classified 'overdue' and surfaced, and that the status counts are present. Reuses the existing
 * MaintenanceForecastService / Vehicle::serviceStatus() km rule (no forecasting logic rebuilt).
 */
class IntelligenceServiceDueTest extends CrudTestCase
{
    public function test_overdue_car_appears_on_the_board(): void
    {
        $vid = $this->makeVehicle();
        // Active fleet (ready/rented are the scanned statuses); 10,000 km since a 8,000 km interval → overdue.
        Vehicle::whereKey($vid)->update([
            'status'                => 'ready',
            'odometer'              => 15000,
            'last_service_odometer' => 5000,
            'service_interval_km'   => 8000,
        ]);

        $res = $this->getJson('/api/intelligence/service-due');
        $res->assertSuccessful();

        $data = data_get($res->json(), 'data');
        $this->assertIsArray($data['vehicles']);
        foreach (['overdue', 'due_soon', 'ok', 'no_data', 'total', 'actionable'] as $key) {
            $this->assertArrayHasKey($key, $data['summary']);
        }
        $this->assertArrayHasKey('near_due_km', $data['thresholds']);

        $row = collect($data['vehicles'])->firstWhere('vehicle_id', $vid);
        $this->assertNotNull($row, 'overdue car should be on the board');
        $this->assertSame('overdue', $row['service_status']);
        $this->assertSame(-2000, $row['remaining_km']);   // 8000 interval − 10000 driven
        $this->assertSame(2000, $row['overdue_km']);
        $this->assertGreaterThanOrEqual(1, $data['summary']['overdue']);
        $this->assertGreaterThanOrEqual($data['summary']['overdue'], $data['summary']['actionable']);
    }
}
