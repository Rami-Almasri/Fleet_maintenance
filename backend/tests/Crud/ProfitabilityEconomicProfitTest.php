<?php

namespace Tests\Crud;

use App\Models\Vehicle;
use App\Services\DepreciationService;

/**
 * PR2 — economic profit on /Profitability.
 *
 * Locks the core identity (economic_profit = net − depreciation), the "unknown ≠ 0"
 * rule for cars with no purchase data, and that the additive economic keys exist
 * without disturbing the pre-existing profitability payload.
 */
class ProfitabilityEconomicProfitTest extends CrudTestCase
{
    public function test_economic_profit_equals_net_minus_depreciation_and_unknown_is_null(): void
    {
        // A car WITH purchase data → depreciates. 100k cost, 2 years old, default
        // policy (5y life, 20% residual) → ~32k accumulated, ~68k book value.
        $withDataId = $this->makeVehicle();
        Vehicle::whereKey($withDataId)->update([
            'purchase_price' => 100000,
            'purchase_date'  => now()->subYears(2)->toDateString(),
        ]);

        // A car with NO purchase price/date → depreciation is UNKNOWN, not zero.
        $noDataId = $this->makeVehicle();
        Vehicle::whereKey($noDataId)->update(['purchase_price' => null, 'purchase_date' => null]);

        $res = $this->getJson('/api/Profitability');
        $res->assertSuccessful();

        $rows = collect(data_get($res->json(), 'data.vehicles'));

        // Core acceptance: the identity holds for EVERY row that has a depreciation figure,
        // and economic_profit is NULL wherever depreciation is unknown.
        foreach ($rows as $row) {
            if ($row['depreciation'] !== null) {
                $this->assertEqualsWithDelta(
                    round($row['net'] - $row['depreciation'], 2),
                    $row['economic_profit'],
                    0.01,
                    "economic_profit must equal net − depreciation for vehicle {$row['vehicle_id']}",
                );
            } else {
                $this->assertNull(
                    $row['economic_profit'],
                    "economic_profit must be NULL when depreciation is unknown (vehicle {$row['vehicle_id']})",
                );
            }
        }

        // With-data car: real, sane numbers + merge wired to DepreciationService.
        $rowA     = $rows->firstWhere('vehicle_id', $withDataId);
        $expected = app(DepreciationService::class)->forVehicle(Vehicle::findOrFail($withDataId));
        $this->assertNotNull($rowA);
        $this->assertTrue($expected['has_data']);
        $this->assertEquals(100000, $rowA['purchase_price']);   // JSON round-trips whole floats to int
        $this->assertGreaterThan(0, $rowA['depreciation']);
        $this->assertEqualsWithDelta($expected['accumulated_depreciation'], $rowA['depreciation'], 1.0);
        $this->assertEqualsWithDelta(68000.0, $rowA['book_value'], 150.0);           // ~2y on 5y/20%
        $this->assertGreaterThanOrEqual(20000.0, $rowA['book_value']);               // never below residual
        $this->assertEqualsWithDelta(round($rowA['net'] - $rowA['depreciation'], 2), $rowA['economic_profit'], 0.01);

        // No-data car: everything economic is NULL, never 0.
        $rowB = $rows->firstWhere('vehicle_id', $noDataId);
        $this->assertNotNull($rowB);
        $this->assertNull($rowB['depreciation']);
        $this->assertNull($rowB['book_value']);
        $this->assertNull($rowB['economic_profit']);

        // Additive summary keys present; counts reflect known vs unknown.
        $summary = data_get($res->json(), 'data.summary');
        foreach (['total_depreciation', 'total_book_value', 'total_economic', 'depreciation_known', 'depreciation_unknown'] as $key) {
            $this->assertArrayHasKey($key, $summary);
        }
        $this->assertGreaterThanOrEqual(1, $summary['depreciation_known']);
        $this->assertGreaterThanOrEqual(1, $summary['depreciation_unknown']);

        // Backward-compatibility: the pre-existing keys are still there.
        foreach (['gross_revenue', 'operating_cost', 'maintenance', 'net', 'rentals', 'visits'] as $key) {
            $this->assertArrayHasKey($key, $rowA);
        }
    }
}
