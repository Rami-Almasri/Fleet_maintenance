<?php

namespace Tests\Unit;

use App\Services\CostIntelligenceService;
use PHPUnit\Framework\TestCase;

/**
 * Cost Intelligence — the pure ratio math (divide-by-zero / missing denominator → NULL, never 0/Inf).
 * Plain PHPUnit: the service's constructor deps aren't needed to exercise computeRatios(), so we
 * build it without the container (reflection-free — the method is independent of the injected engines).
 */
class CostIntelligenceServiceTest extends TestCase
{
    private function svc(): CostIntelligenceService
    {
        // computeRatios() touches none of the constructor deps; a bare instance is fine to build via
        // reflection without booting the container.
        return (new \ReflectionClass(CostIntelligenceService::class))->newInstanceWithoutConstructor();
    }

    public function test_normal_ratios(): void
    {
        $r = $this->svc()->computeRatios(1000.0, 5000, 200, 10);

        $this->assertEqualsWithDelta(0.2, $r['cost_per_km'], 0.0001);    // 1000 / 5000
        $this->assertEqualsWithDelta(5.0, $r['cost_per_day'], 0.01);     // 1000 / 200
        $this->assertEqualsWithDelta(100.0, $r['cost_per_rental'], 0.01); // 1000 / 10
    }

    public function test_zero_or_null_denominators_are_null_not_inf(): void
    {
        $r = $this->svc()->computeRatios(1000.0, 0, 0, 0);
        $this->assertNull($r['cost_per_km']);
        $this->assertNull($r['cost_per_day']);
        $this->assertNull($r['cost_per_rental']);

        $n = $this->svc()->computeRatios(1000.0, null, null, 0);
        $this->assertNull($n['cost_per_km']);
        $this->assertNull($n['cost_per_day']);
        $this->assertNull($n['cost_per_rental']);
    }

    public function test_negative_distance_is_null(): void
    {
        // A rolled-back odometer must never produce a (negative) cost-per-km.
        $r = $this->svc()->computeRatios(1000.0, -500, 100, 5);
        $this->assertNull($r['cost_per_km']);
        $this->assertNotNull($r['cost_per_day']);
        $this->assertNotNull($r['cost_per_rental']);
    }

    public function test_zero_maintenance_is_a_real_zero_ratio_not_null(): void
    {
        // A car with valid distance but no maintenance spend genuinely costs 0/km — that's a real
        // value, distinct from an unknown (null) denominator.
        $r = $this->svc()->computeRatios(0.0, 5000, 200, 10);
        $this->assertSame(0.0, $r['cost_per_km']);
        $this->assertSame(0.0, $r['cost_per_day']);
        $this->assertSame(0.0, $r['cost_per_rental']);
    }
}
