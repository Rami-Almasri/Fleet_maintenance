<?php

namespace Tests\Unit;

use App\Services\Knowledge\RepairCohortStats;
use PHPUnit\Framework\TestCase;

/**
 * DB-free tests for the pure cohort aggregation (percentiles, modal cause, top parts, recurrence,
 * per-garage breakdown). Hand-built rows, no DB — mirrors the GarageRecommendationServiceTest style.
 */
class RepairCohortStatsTest extends TestCase
{
    private RepairCohortStats $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = new RepairCohortStats();
    }

    private function row(array $o = []): array
    {
        return array_merge([
            'garage' => 'APEX', 'garage_vendor_id' => 1,
            'total_cost' => 800.0, 'cost_known' => true,
            'duration_days' => 5, 'root_cause' => 'Timing chain wear',
            'parts' => [['part_number' => 'TC-1', 'name' => 'Timing chain']],
            'outcome' => 'verified_fixed', 'recurred' => false,
        ], $o);
    }

    public function test_cost_band_excludes_unknown_costs(): void
    {
        $rows = [
            $this->row(['total_cost' => 700]),
            $this->row(['total_cost' => 800]),
            $this->row(['total_cost' => 900]),
            $this->row(['total_cost' => null, 'cost_known' => false]),
        ];
        $band = $this->s->band($rows, 'total_cost');
        $this->assertSame(3, $band['n']);           // the null is excluded
        $this->assertSame(800.0, $band['median']);
        $this->assertEqualsWithDelta(750.0, $band['p25'], 0.01);
        $this->assertEqualsWithDelta(850.0, $band['p75'], 0.01);
    }

    public function test_percentile_interpolates(): void
    {
        $this->assertSame(2.0, $this->s->percentile([1.0, 2.0, 3.0], 0.5));
        $this->assertSame(1.5, $this->s->percentile([1.0, 2.0], 0.5));
        $this->assertNull($this->s->percentile([], 0.5));
    }

    public function test_modal_root_cause_and_share(): void
    {
        $rows = [
            $this->row(['root_cause' => 'Timing chain wear']),
            $this->row(['root_cause' => 'Timing chain wear']),
            $this->row(['root_cause' => 'Loose heat shield']),
            $this->row(['root_cause' => null]),
        ];
        $m = $this->s->modal($rows, 'root_cause');
        $this->assertSame('Timing chain wear', $m['value']);
        $this->assertSame(2, $m['count']);
        $this->assertEqualsWithDelta(0.67, $m['share'], 0.01);  // 2 of 3 KNOWN
    }

    public function test_top_parts_collapse_by_number_then_name(): void
    {
        $rows = [
            $this->row(['parts' => [['part_number' => 'TC-1', 'name' => 'Timing chain'], ['part_number' => null, 'name' => 'Tensioner']]]),
            $this->row(['parts' => [['part_number' => 'TC-1', 'name' => 'Timing chain']]]),
            $this->row(['parts' => [['part_number' => null, 'name' => 'tensioner']]]),  // case-collapse with above
        ];
        $parts = $this->s->topParts($rows, 5);
        $this->assertSame('TC-1', $parts[0]['part_number']);
        $this->assertSame(2, $parts[0]['freq']);
        $this->assertSame(2, $parts[1]['freq']);  // Tensioner + tensioner
    }

    public function test_recurrence_rate(): void
    {
        $rows = [$this->row(['recurred' => true]), $this->row(['recurred' => false]), $this->row(['recurred' => false]), $this->row(['recurred' => false])];
        $this->assertSame(0.25, $this->s->recurrenceRate($rows));
    }

    public function test_completeness_needs_both_cost_and_duration(): void
    {
        $rows = [
            $this->row(),                                              // full
            $this->row(['cost_known' => false, 'total_cost' => null]), // missing cost
            $this->row(['duration_days' => null]),                    // missing duration
        ];
        $this->assertEqualsWithDelta(1 / 3, $this->s->completeness($rows), 0.001);
    }

    public function test_garage_breakdown_ranks_proven_over_tiny(): void
    {
        $rows = array_merge(
            // APEX: 6 jobs, all verified fixed
            array_fill(0, 6, $this->row(['garage' => 'APEX', 'garage_vendor_id' => 1, 'outcome' => 'verified_fixed'])),
            // TINY: 1 job, verified fixed (100% but no credibility)
            [$this->row(['garage' => 'TINY', 'garage_vendor_id' => 2, 'outcome' => 'verified_fixed'])],
        );
        $g = $this->s->garageBreakdown($rows);
        $this->assertSame(1, $g[0]['vendor_id']);   // APEX ranks first
        $this->assertSame(6, $g[0]['jobs']);
        $this->assertSame(1.0, $g[0]['success_rate']);
        $this->assertSame(800.0, $g[0]['avg_cost']);
    }

    public function test_garage_breakdown_success_rate_reflects_failures(): void
    {
        $rows = [
            $this->row(['garage_vendor_id' => 9, 'outcome' => 'verified_fixed']),
            $this->row(['garage_vendor_id' => 9, 'outcome' => 'failed']),
        ];
        $g = $this->s->garageBreakdown($rows);
        $this->assertSame(0.5, $g[0]['success_rate']);   // (1.0 + 0.0) / 2
    }

    public function test_mean_excludes_nulls(): void
    {
        $rows = [$this->row(['duration_days' => 4]), $this->row(['duration_days' => 6]), $this->row(['duration_days' => null])];
        $this->assertSame(5.0, $this->s->mean($rows, 'duration_days'));
        $this->assertNull($this->s->mean([], 'duration_days'));
    }

    public function test_success_rate_counts_non_failed(): void
    {
        $rows = [
            $this->row(['outcome' => 'verified_fixed']),
            $this->row(['outcome' => 'fixed']),
            $this->row(['outcome' => 'failed']),
        ];
        $this->assertSame(0.67, $this->s->successRate($rows));   // 2 of 3 concluded didn't fail
        $this->assertNull($this->s->successRate([$this->row(['outcome' => 'unknown'])]));
    }
}
