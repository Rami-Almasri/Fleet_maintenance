<?php

namespace Tests\Crud;

use App\Models\Contract;
use App\Models\Maintenance;

/**
 * PR4 — /api/intelligence/cost.
 *
 * Verifies the endpoint envelope + that the service wires the reused sources correctly:
 * maintenance spend (numerator) ÷ validated distance / rentals (denominators), with the
 * ratio identity holding for every row and NULL denominators never producing a value.
 */
class IntelligenceCostTest extends CrudTestCase
{
    public function test_cost_endpoint_returns_ratios_and_holds_the_identity(): void
    {
        $vid = $this->makeVehicle();

        // Numerator: a manual workshop-log maintenance row (origin ∈ WORKSHOP_LOG_ORIGINS) with cost.
        Maintenance::create([
            'vehicle_id' => $vid,
            'origin'     => 'manual',
            'cost'       => 500,
            'out_date'   => now()->subDays(30)->toDateString(),
        ]);

        // Denominators: a closed rental (type-C) with odometer OUT/IN → 5,000 km validated travel.
        Contract::create([
            'vehicle_id'    => $vid,
            'contract_no'   => 'C-' . strtoupper(uniqid()),
            'contract_type' => 'C',
            'state'         => 'closed',
            'out_date'      => now()->subDays(20)->toDateString(),
            'in_date'       => now()->subDays(10)->toDateString(),
            'out_milage'    => 1000,
            'in_milage'     => 6000,
        ]);

        $res = $this->getJson('/api/intelligence/cost');
        $res->assertSuccessful();

        $payload = data_get($res->json(), 'data');
        $this->assertIsArray($payload['vehicles']);
        foreach (['vehicles', 'total_maintenance', 'total_km', 'total_rentals', 'fleet_cost_per_km', 'km_unknown'] as $key) {
            $this->assertArrayHasKey($key, $key === 'vehicles' ? $payload : $payload['summary']);
        }

        // Identity holds for EVERY row: a ratio equals cost ÷ denominator, or is NULL when the
        // denominator is missing/≤0 (never 0-divide or Inf).
        foreach ($payload['vehicles'] as $row) {
            $m = (float) $row['maintenance_cost'];
            if ($row['distance_km'] !== null && $row['distance_km'] > 0) {
                $this->assertEqualsWithDelta(round($m / $row['distance_km'], 4), $row['cost_per_km'], 0.0001);
            } else {
                $this->assertNull($row['cost_per_km']);
            }
            if ($row['rentals'] > 0) {
                $this->assertEqualsWithDelta(round($m / $row['rentals'], 2), $row['cost_per_rental'], 0.01);
            } else {
                $this->assertNull($row['cost_per_rental']);
            }
        }

        // The seeded car: numerator + distance + rentals wired through correctly.
        $row = collect($payload['vehicles'])->firstWhere('vehicle_id', $vid);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(500.0, $row['maintenance_cost'], 0.01);
        $this->assertSame(5000, $row['distance_km']);
        $this->assertSame(1, $row['rentals']);
        $this->assertEqualsWithDelta(0.1, $row['cost_per_km'], 0.0001);       // 500 / 5000
        $this->assertEqualsWithDelta(500.0, $row['cost_per_rental'], 0.01);   // 500 / 1
    }
}
