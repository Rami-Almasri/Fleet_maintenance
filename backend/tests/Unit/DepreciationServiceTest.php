<?php

namespace Tests\Unit;

use App\Services\DepreciationService;
use PHPUnit\Framework\TestCase;

/**
 * Straight-line depreciation — pure math, so a plain PHPUnit TestCase (no DB/app
 * boot). The policy is injected explicitly so these lock the numbers regardless
 * of config/depreciation.php. Dates use identical month-day pairs so the year
 * diff is a clean integer (no leap-year fraction to fight).
 */
class DepreciationServiceTest extends TestCase
{
    /** Default P1 policy: straight-line, 5-year life, 20% residual. */
    private function service(array $policy = []): DepreciationService
    {
        return new DepreciationService($policy + [
            'method'            => 'straight_line',
            'useful_life_years' => 5,
            'residual_pct'      => 0.20,
            'categories'        => [],
        ]);
    }

    public function test_straight_line_midpoint(): void
    {
        // 100,000 cost, 20% residual → 20,000 salvage, 80,000 depreciable over 5y
        // = 16,000/yr. At exactly 2 years: 32,000 gone, 68,000 book value.
        $r = $this->service()->compute(100000, '2021-01-01', null, '2023-01-01');

        $this->assertTrue($r['has_data']);
        $this->assertEqualsWithDelta(20000.0, $r['residual_value'], 0.01);
        $this->assertEqualsWithDelta(16000.0, $r['annual_depreciation'], 0.01);
        $this->assertEqualsWithDelta(1333.33, $r['monthly_depreciation'], 0.01);
        $this->assertEqualsWithDelta(43.84, $r['daily_depreciation'], 0.01);
        $this->assertEqualsWithDelta(2.0, $r['age_years'], 0.01);
        $this->assertEqualsWithDelta(32000.0, $r['accumulated_depreciation'], 0.5);
        $this->assertEqualsWithDelta(68000.0, $r['book_value'], 0.5);
        $this->assertEqualsWithDelta(0.4, $r['percent_depreciated'], 0.001);
        $this->assertFalse($r['fully_depreciated']);
    }

    public function test_fully_depreciated_floors_at_residual_and_caps_accumulation(): void
    {
        // 8 years elapsed on a 5-year life → book value pinned at the 20,000 salvage,
        // accumulated capped at the 80,000 depreciable base (never over-depreciates).
        $r = $this->service()->compute(100000, '2015-01-01', null, '2023-01-01');

        $this->assertTrue($r['fully_depreciated']);
        $this->assertEqualsWithDelta(20000.0, $r['book_value'], 0.5);
        $this->assertEqualsWithDelta(80000.0, $r['accumulated_depreciation'], 0.5);
        $this->assertLessThanOrEqual(1.0, $r['percent_depreciated']);
    }

    public function test_book_value_never_below_residual_even_far_past_life(): void
    {
        $r = $this->service()->compute(50000, '2005-01-01', null, '2023-01-01');
        $this->assertGreaterThanOrEqual($r['residual_value'], $r['book_value']);
        $this->assertEqualsWithDelta(10000.0, $r['book_value'], 0.5); // 20% of 50,000
    }

    public function test_null_price_is_unknown_not_zero(): void
    {
        $r = $this->service()->compute(null, '2021-01-01', null, '2023-01-01');

        $this->assertFalse($r['has_data']);
        $this->assertNull($r['book_value']);
        $this->assertNull($r['accumulated_depreciation']);
        $this->assertNull($r['annual_depreciation']);
    }

    public function test_null_date_is_unknown_not_zero(): void
    {
        $r = $this->service()->compute(100000, null, null, '2023-01-01');

        $this->assertFalse($r['has_data']);
        $this->assertNull($r['book_value']);
        $this->assertSame(100000.0, $r['purchase_price']); // price still echoed back
    }

    public function test_zero_or_negative_price_is_unknown(): void
    {
        $this->assertFalse($this->service()->compute(0, '2021-01-01', null, '2023-01-01')['has_data']);
        $this->assertFalse($this->service()->compute(-500, '2021-01-01', null, '2023-01-01')['has_data']);
    }

    public function test_future_purchase_date_reads_as_age_zero(): void
    {
        // asOf is before the purchase date → nothing depreciated yet.
        $r = $this->service()->compute(100000, '2025-01-01', null, '2023-01-01');

        $this->assertTrue($r['has_data']);
        $this->assertEqualsWithDelta(0.0, $r['age_years'], 0.01);
        $this->assertEqualsWithDelta(0.0, $r['accumulated_depreciation'], 0.01);
        $this->assertEqualsWithDelta(100000.0, $r['book_value'], 0.01);
        $this->assertFalse($r['fully_depreciated']);
    }

    public function test_category_override_wins_over_defaults(): void
    {
        // Luxury: 10-year life, 30% residual. 100,000 cost → 30,000 salvage,
        // 70,000 depreciable over 10y = 7,000/yr. At 5 years: 35,000 gone, 65,000 book.
        $svc = $this->service([
            'categories' => ['Luxury' => ['useful_life_years' => 10, 'residual_pct' => 0.30]],
        ]);
        $r = $svc->compute(100000, '2018-01-01', 'Luxury', '2023-01-01');

        $this->assertSame(10, $r['useful_life_years']);
        $this->assertEqualsWithDelta(0.30, $r['residual_pct'], 0.0001);
        $this->assertEqualsWithDelta(30000.0, $r['residual_value'], 0.01);
        $this->assertEqualsWithDelta(7000.0, $r['annual_depreciation'], 0.01);
        $this->assertEqualsWithDelta(65000.0, $r['book_value'], 0.5);
    }

    public function test_unknown_category_falls_back_to_defaults(): void
    {
        $r = $this->service([
            'categories' => ['Luxury' => ['useful_life_years' => 10, 'residual_pct' => 0.30]],
        ])->compute(100000, '2021-01-01', 'Economy', '2023-01-01');

        $this->assertSame(5, $r['useful_life_years']);       // default life, not the Luxury override
        $this->assertEqualsWithDelta(0.20, $r['residual_pct'], 0.0001);
    }
}
