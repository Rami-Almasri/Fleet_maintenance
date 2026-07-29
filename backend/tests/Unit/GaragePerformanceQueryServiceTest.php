<?php

namespace Tests\Unit;

use App\Services\Knowledge\GaragePerformanceQueryService;
use PHPUnit\Framework\TestCase;

/**
 * DB-free tests for the pure Garage Performance scoring core (relativeToFleet / speedScore /
 * confidenceBand / composite). Locks the honest-by-construction rules: faster→higher, same-day categories
 * don't divide by zero, thin cohorts read "low", and the composite blends ONLY available KPIs.
 */
class GaragePerformanceQueryServiceTest extends TestCase
{
    private GaragePerformanceQueryService $svc;

    /** Mirrors config/repair_intelligence.php. */
    private array $cfg = [
        'min_n'      => ['level1' => 8, 'level2' => 15],
        'confidence' => ['high_min_n' => 20],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new GaragePerformanceQueryService();
    }

    // ── relativeToFleet ─────────────────────────────────────────────────────────────────────────────

    public function test_faster_than_fleet_is_positive(): void
    {
        // garage 2.0d vs fleet 3.0d → 33% faster
        $r = $this->svc->relativeToFleet(2.0, 3.0);
        $this->assertSame(33, $r['pct']);
        $this->assertStringContainsString('faster', $r['label']);
    }

    public function test_slower_than_fleet_is_negative(): void
    {
        $r = $this->svc->relativeToFleet(6.0, 3.0);
        $this->assertSame(-100, $r['pct']);
        $this->assertStringContainsString('slower', $r['label']);
    }

    public function test_on_par_band(): void
    {
        $r = $this->svc->relativeToFleet(3.0, 3.0);
        $this->assertSame(0, $r['pct']);
        $this->assertSame('on par with fleet', $r['label']);
    }

    public function test_same_day_category_does_not_divide_by_zero(): void
    {
        // fleet median 0 (e.g. tyres): guarded denominator, no error, on-par
        $r = $this->svc->relativeToFleet(0.0, 0.0);
        $this->assertSame(0, $r['pct']);
    }

    public function test_null_inputs_yield_no_comparison(): void
    {
        $this->assertSame(['pct' => null, 'label' => 'no comparison'], $this->svc->relativeToFleet(null, 3.0));
    }

    // ── speedScore ──────────────────────────────────────────────────────────────────────────────────

    public function test_fleet_average_scores_fifty(): void
    {
        $this->assertSame(50, $this->svc->speedScore(3.0, 3.0));
    }

    public function test_faster_scores_above_fifty_slower_below(): void
    {
        $this->assertGreaterThan(50, $this->svc->speedScore(1.0, 3.0));
        $this->assertLessThan(50, $this->svc->speedScore(6.0, 3.0));
    }

    public function test_speed_score_is_clamped_0_100(): void
    {
        // Smoothing means a wildly-faster garage approaches but doesn't overshoot 100 (here 99); the
        // clamp is the safety rail. A wildly-slower garage is clamped hard to 0.
        $fast = $this->svc->speedScore(0.0, 50.0);
        $this->assertGreaterThanOrEqual(99, $fast);
        $this->assertLessThanOrEqual(100, $fast);
        $this->assertSame(0, $this->svc->speedScore(50.0, 0.0));     // wildly slower, clamped low
    }

    public function test_speed_score_null_when_unknown(): void
    {
        $this->assertNull($this->svc->speedScore(null, 3.0));
    }

    // ── confidenceBand ──────────────────────────────────────────────────────────────────────────────

    public function test_confidence_bands(): void
    {
        $this->assertSame('high', $this->svc->confidenceBand(20, $this->cfg));
        $this->assertSame('medium', $this->svc->confidenceBand(8, $this->cfg));
        $this->assertSame('low', $this->svc->confidenceBand(7, $this->cfg));
        $this->assertSame('low', $this->svc->confidenceBand(0, $this->cfg));
    }

    // ── composite ───────────────────────────────────────────────────────────────────────────────────

    public function test_composite_over_single_available_kpi(): void
    {
        // Phase 1 reality: only duration available → composite == its score, coverage lists just duration
        $c = $this->svc->composite(['duration' => ['score' => 72, 'available' => true]]);
        $this->assertSame(72, $c['value']);
        $this->assertSame(['duration'], $c['coverage']);
        $this->assertSame(1, $c['kpi_count']);
    }

    public function test_composite_renormalizes_over_available_kpis(): void
    {
        // duration(w .30, 80) + qc_pass(w .25, 60); unavailable ones excluded and weights renormalized
        $c = $this->svc->composite([
            'duration'     => ['score' => 80, 'available' => true],
            'qc_pass'      => ['score' => 60, 'available' => true],
            'on_time'      => ['score' => null, 'available' => false],
            'repeat_fault' => ['score' => 90, 'available' => false],
        ]);
        // (80*.30 + 60*.25) / (.30+.25) = 44/.55 = 80.0? → (24+15)/0.55 = 70.9 → 71
        $this->assertSame(71, $c['value']);
        $this->assertSame(['duration', 'qc_pass'], $c['coverage']);
    }

    public function test_composite_null_when_nothing_available(): void
    {
        $c = $this->svc->composite([
            'duration' => ['score' => 80, 'available' => false],
            'on_time'  => ['score' => null, 'available' => false],
        ]);
        $this->assertNull($c['value']);
        $this->assertSame(0, $c['kpi_count']);
    }
}
