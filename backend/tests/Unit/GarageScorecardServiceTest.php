<?php

namespace Tests\Unit;

use App\Services\Garage\GarageScorecardService;
use PHPUnit\Framework\TestCase;

/**
 * DB-free tests for the scorecard's scoring core.
 *
 * The regression these exist for: the first cut blended "whatever is measurable", which handed a
 * garage 87/100 whose repairs came back 60% of the time — it turned cars around quickly and had too
 * few repairs for reliability to be graded, so speed carried the whole score on its own. A garage
 * whose work cannot be shown to hold is an UNKNOWN garage, not a good one, and the gate below is
 * what makes it read as one.
 */
class GarageScorecardServiceTest extends TestCase
{
    private GarageScorecardService $svc;

    /** Mirrors config/garage_scorecard.php. */
    private array $cfg = [
        'weights'    => ['reliability' => 0.65, 'speed' => 0.35],
        'confidence' => ['high_min' => 200, 'medium_min' => 60],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new GarageScorecardService();
    }

    private function rel(bool $measured, ?float $actual, ?float $expected, ?string $reason = null): array
    {
        return ['measured' => $measured, 'comeback_pct' => $actual, 'expected_pct' => $expected, 'reason' => $reason];
    }

    private function speed(bool $measured, ?float $days, ?float $expected, ?string $reason = null): array
    {
        return ['measured' => $measured, 'days' => $days, 'expected_days' => $expected, 'reason' => $reason];
    }

    // ── ratioScore ──────────────────────────────────────────────────────────────────────────────────

    public function test_matching_the_expectation_scores_fifty(): void
    {
        $this->assertSame(50, $this->svc->ratioScore(40.0, 40.0));
    }

    public function test_better_than_expected_scores_above_fifty(): void
    {
        $this->assertGreaterThan(50, $this->svc->ratioScore(20.0, 40.0));
    }

    public function test_worse_than_expected_scores_below_fifty(): void
    {
        $this->assertLessThan(50, $this->svc->ratioScore(60.0, 40.0));
    }

    public function test_twice_the_expected_bottoms_out_at_zero(): void
    {
        $this->assertSame(0, $this->svc->ratioScore(81.0, 40.0));
    }

    public function test_a_near_zero_expectation_cannot_explode_the_ratio(): void
    {
        // Without smoothing, 1 vs 0 is an infinite ratio. It must stay inside the scale.
        $v = $this->svc->ratioScore(1.0, 0.0);
        $this->assertGreaterThanOrEqual(0, $v);
        $this->assertLessThanOrEqual(100, $v);
    }

    // ── the reliability gate ────────────────────────────────────────────────────────────────────────

    public function test_speed_alone_never_produces_a_score(): void
    {
        $out = $this->svc->composite(
            $this->rel(false, 60.0, 40.0, 'Only 10 attributable repairs.'),
            $this->speed(true, 1.0, 4.0),
            10,
            $this->cfg,
        );

        $this->assertNull($out['value'], 'A fast garage with ungraded reliability must not be scored.');
        $this->assertSame([], $out['coverage']);
        $this->assertStringContainsString('10 attributable repairs', $out['reason']);
    }

    public function test_reliability_alone_is_enough_to_score(): void
    {
        $out = $this->svc->composite(
            $this->rel(true, 20.0, 40.0),
            $this->speed(false, null, null, 'Only 2 timed repairs.'),
            120,
            $this->cfg,
        );

        $this->assertNotNull($out['value']);
        $this->assertSame(['reliability'], $out['coverage']);
        $this->assertStringContainsString('reliability', $out['reason']);
    }

    public function test_both_axes_blend_on_the_configured_weights(): void
    {
        $out = $this->svc->composite(
            $this->rel(true, 40.0, 40.0),      // exactly expected → 50
            $this->speed(true, 4.0, 4.0),      // exactly expected → 50
            250,
            $this->cfg,
        );

        $this->assertSame(50, $out['value']);
        $this->assertSame(['reliability', 'speed'], $out['coverage']);
        $this->assertNull($out['reason'], 'Full coverage needs no caveat.');
    }

    public function test_reliability_outweighs_speed(): void
    {
        // Identical garages except one is unreliable-but-fast and the other reliable-but-slow.
        $fastSloppy = $this->svc->composite($this->rel(true, 60.0, 40.0), $this->speed(true, 1.0, 4.0), 100, $this->cfg);
        $slowSolid  = $this->svc->composite($this->rel(true, 20.0, 40.0), $this->speed(true, 8.0, 4.0), 100, $this->cfg);

        $this->assertGreaterThan($fastSloppy['value'], $slowSolid['value']);
    }

    // ── confidence bands ────────────────────────────────────────────────────────────────────────────

    public function test_confidence_band_follows_the_sample(): void
    {
        $rel = $this->rel(true, 30.0, 40.0);
        $sp = $this->speed(false, null, null, 'thin');

        $this->assertSame('high', $this->svc->composite($rel, $sp, 200, $this->cfg)['band']);
        $this->assertSame('medium', $this->svc->composite($rel, $sp, 60, $this->cfg)['band']);
        $this->assertSame('low', $this->svc->composite($rel, $sp, 59, $this->cfg)['band']);
    }
}
