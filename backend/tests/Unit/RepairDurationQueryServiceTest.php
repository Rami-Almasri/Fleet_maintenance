<?php

namespace Tests\Unit;

use App\Services\Knowledge\RepairDurationQueryService;
use PHPUnit\Framework\TestCase;

/**
 * DB-free tests for the pure Time-To-Fix cohort core (RepairDurationQueryService::summarize) — mirrors
 * the RepairCohortStatsTest / GarageRecommendationServiceTest style. Locks the LOCKED reporting decisions:
 * median headline + p25→p90 range (D1), and the right-skew property that makes the mean unsafe (A4).
 * The Eloquent scan (durations()) is left to feature tests; this pins the maths the ETA rests on.
 */
class RepairDurationQueryServiceTest extends TestCase
{
    private RepairDurationQueryService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new RepairDurationQueryService();
    }

    public function test_empty_cohort_is_all_null(): void
    {
        $s = $this->svc->summarize([]);
        $this->assertSame(0, $s['n']);
        $this->assertNull($s['median']);
        $this->assertNull($s['p25']);
        $this->assertNull($s['p90']);
        $this->assertNull($s['mean']);
        $this->assertNull($s['min']);
        $this->assertNull($s['max']);
    }

    public function test_single_value(): void
    {
        $s = $this->svc->summarize([3]);
        $this->assertSame(1, $s['n']);
        $this->assertSame(3.0, $s['median']);
        $this->assertSame(3.0, $s['p90']);
        $this->assertSame(3.0, $s['mean']);
        $this->assertSame(3, $s['min']);
        $this->assertSame(3, $s['max']);
    }

    /**
     * The whole reason for median+p90 over mean. The tail (one 60-day stall) drags the mean to 8.4,
     * but the TYPICAL repair is 3 days — which is what the headline must show.
     */
    public function test_right_skew_median_beats_mean(): void
    {
        $days = [1, 1, 2, 2, 3, 3, 3, 4, 5, 60];
        $s = $this->svc->summarize($days);

        $this->assertSame(10, $s['n']);
        $this->assertSame(3.0, $s['median'], 'typical case, unmoved by the outlier');
        $this->assertSame(8.4, $s['mean'], 'mean is dragged up by the 60-day tail');
        $this->assertSame(2.0, $s['p25']);
        $this->assertSame(10.5, $s['p90'], 'upper bound reflects the tail without being the max');
        $this->assertSame(1, $s['min']);
        $this->assertSame(60, $s['max']);
        // The headline (median) is far below the mean — the design intent.
        $this->assertLessThan($s['mean'], $s['median']);
    }

    /** Same-day repairs are real (day-granular data, A2), not noise: median can legitimately be 0. */
    public function test_all_same_day_repairs(): void
    {
        $s = $this->svc->summarize([0, 0, 0, 0]);
        $this->assertSame(4, $s['n']);
        $this->assertSame(0.0, $s['median']);
        $this->assertSame(0.0, $s['p90']);
        $this->assertSame(0.0, $s['mean']);
    }

    public function test_non_numeric_values_are_ignored(): void
    {
        $s = $this->svc->summarize([2, null, 'x', 4]);
        $this->assertSame(2, $s['n']);
        $this->assertSame(3.0, $s['median']);
    }

    /** Order must not matter — summarize sorts internally. */
    public function test_unsorted_input(): void
    {
        $this->assertSame(
            $this->svc->summarize([5, 1, 3, 2, 4]),
            $this->svc->summarize([1, 2, 3, 4, 5]),
        );
    }
}
