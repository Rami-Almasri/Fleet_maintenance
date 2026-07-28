<?php

namespace Tests\Unit;

use App\Services\Knowledge\ConfidenceInputs;
use App\Services\Knowledge\ConfidenceScore;
use App\Services\Knowledge\ConfidenceScorer;
use PHPUnit\Framework\TestCase;

/**
 * DB-free tests for the pure confidence core (ConfidenceScorer::score). Config is injected exactly like
 * GarageRecommendationServiceTest, so these lock the banding + the "never hide uncertainty" guards.
 */
class ConfidenceScorerTest extends TestCase
{
    private ConfidenceScorer $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ConfidenceScorer();
    }

    /** Mirrors config/knowledge.php → confidence. */
    private function cfg(): array
    {
        return [
            'credibility_n' => 12,
            'weights' => ['evidence' => 0.30, 'tier' => 0.20, 'success' => 0.25, 'recency' => 0.10, 'completeness' => 0.15],
            'recurrence_penalty' => 0.25,
            'tier_factor' => [1 => 1.0, 2 => 0.8, 3 => 0.6, 4 => 0.45],
            'recency_fresh_days' => 90, 'recency_stale_days' => 540,
            'success_neutral' => 0.6,
            'high_score' => 70, 'high_n' => 12, 'med_score' => 45, 'med_n' => 5,
            'min_completeness' => 0.5,
        ];
    }

    private function inputs(array $o = []): ConfidenceInputs
    {
        return new ConfidenceInputs(
            sampleSize: $o['sampleSize'] ?? 12,
            bestTier: $o['bestTier'] ?? 1,
            verifiedFixed: $o['verifiedFixed'] ?? 10,
            fixed: $o['fixed'] ?? 2,
            failed: $o['failed'] ?? 0,
            outcomeUnknown: $o['outcomeUnknown'] ?? 0,
            recurrenceRate: $o['recurrenceRate'] ?? 0.0,
            daysSinceNewest: $o['daysSinceNewest'] ?? 30,
            completeness: $o['completeness'] ?? 1.0,
        );
    }

    public function test_empty_cohort_is_low_zero(): void
    {
        $r = $this->svc->score($this->inputs(['sampleSize' => 0]), $this->cfg());
        $this->assertSame(0, $r->score);
        $this->assertSame(ConfidenceScore::LOW, $r->band);
    }

    public function test_strong_recent_same_vehicle_cohort_is_high(): void
    {
        $r = $this->svc->score($this->inputs(), $this->cfg());
        $this->assertSame(ConfidenceScore::HIGH, $r->band);
        $this->assertGreaterThanOrEqual(70, $r->score);
    }

    public function test_thin_cohort_is_forced_low_even_if_score_would_pass(): void
    {
        // 3 flawless same-vehicle repairs — blend is high, but n < med_n must force LOW.
        $r = $this->svc->score($this->inputs([
            'sampleSize' => 3, 'verifiedFixed' => 3, 'fixed' => 0,
        ]), $this->cfg());
        $this->assertSame(ConfidenceScore::LOW, $r->band);
        $this->assertNotEmpty(array_filter($r->reasons, fn ($x) => str_contains($x, 'comparable repair')));
    }

    public function test_fleet_only_match_is_capped_at_medium(): void
    {
        $r = $this->svc->score($this->inputs(['bestTier' => 4, 'sampleSize' => 40, 'verifiedFixed' => 40, 'fixed' => 0]), $this->cfg());
        $this->assertNotSame(ConfidenceScore::HIGH, $r->band);
        $this->assertContains('All evidence is fleet-wide, not this model', $r->reasons);
    }

    public function test_incomplete_data_is_capped_and_explained(): void
    {
        $r = $this->svc->score($this->inputs(['completeness' => 0.2]), $this->cfg());
        $this->assertNotSame(ConfidenceScore::HIGH, $r->band);
        $this->assertNotEmpty(array_filter($r->reasons, fn ($x) => str_contains($x, 'less than half')));
    }

    public function test_unverified_outcomes_add_a_reason(): void
    {
        $r = $this->svc->score($this->inputs([
            'verifiedFixed' => 0, 'fixed' => 0, 'failed' => 0, 'outcomeUnknown' => 12,
        ]), $this->cfg());
        $this->assertNotEmpty(array_filter($r->reasons, fn ($x) => str_contains($x, 'not independently verified')));
    }

    public function test_recurrence_lowers_score_and_is_reported(): void
    {
        $clean = $this->svc->score($this->inputs(['recurrenceRate' => 0.0]), $this->cfg());
        $recur = $this->svc->score($this->inputs(['recurrenceRate' => 0.5]), $this->cfg());
        $this->assertLessThan($clean->score, $recur->score);
        $this->assertNotEmpty(array_filter($recur->reasons, fn ($x) => str_contains($x, 'recurred')));
    }

    public function test_failed_outcomes_score_below_verified(): void
    {
        $good = $this->svc->score($this->inputs(['verifiedFixed' => 12, 'failed' => 0]), $this->cfg());
        $bad  = $this->svc->score($this->inputs(['verifiedFixed' => 6, 'failed' => 6]), $this->cfg());
        $this->assertLessThan($good->score, $bad->score);
    }
}
