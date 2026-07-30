<?php

namespace Tests\Unit;

use App\Services\Intelligence\Readiness\CapabilityHealth;
use App\Services\Intelligence\Readiness\EvidenceRequirement;
use Tests\TestCase;

/**
 * The health score's contract — and the two traps a composite number sets.
 *
 * It summarises the readiness and freshness tables; it must never be able to disagree with them, and
 * it must never let a good average disguise a capability that cannot work at all.
 */
class CapabilityHealthTest extends TestCase
{
    private function requirement(array $overrides = []): EvidenceRequirement
    {
        return new EvidenceRequirement(...array_merge([
            'capabilityId' => 'c',
            'label'        => 'C',
            'evidence'     => 'verdicts',
            'current'      => 100,
            'threshold'    => 100,
            'coverage'     => 1.0,
            'medianAgeDays' => 10,
            'newestAt'     => now()->subDay(),
            'lastEvaluatedAt' => now()->subDays(5),
            'evidenceAtLastEvaluation' => 100,
            'quality'      => EvidenceRequirement::QUALITY_MEASURED,
        ], $overrides));
    }

    public function test_a_capability_with_everything_in_place_scores_healthy(): void
    {
        $h = CapabilityHealth::for($this->requirement(), promoted: true);

        $this->assertSame('healthy', $h->band());
        $this->assertGreaterThanOrEqual(95, $h->score);
    }

    /**
     * TRAP ONE: AVERAGING HIDES BLOCKERS. A capability that cannot work at all would otherwise score
     * respectably on freshness and coverage and land mid-table looking merely mediocre.
     */
    public function test_a_blocker_caps_the_score_rather_than_averaging_away(): void
    {
        $perfect = CapabilityHealth::for($this->requirement(), promoted: true);
        $blocked = CapabilityHealth::for($this->requirement(['blocker' => 'durations run backwards']), promoted: true);

        $this->assertGreaterThanOrEqual(95, $perfect->score);
        $this->assertLessThanOrEqual(35, $blocked->score, 'Identical in every other respect — the blocker must dominate.');
        $this->assertSame('blocked', $blocked->band());
        $this->assertStringContainsString('Blocked by data', $blocked->attention);
    }

    /**
     * TRAP TWO: THE TOTAL HIDES WHICH THING IS WRONG. Ready-but-stale and fresh-but-underpowered are
     * different problems that score alike, so the score always names its weakest dimension.
     */
    public function test_the_score_names_the_dimension_that_is_failing(): void
    {
        $stale = CapabilityHealth::for($this->requirement([
            'medianAgeDays' => 900, 'lastEvaluatedAt' => now()->subDays(2),
        ]), promoted: true);

        $thin = CapabilityHealth::for($this->requirement([
            'current' => 5, 'threshold' => 100, 'lastEvaluatedAt' => now()->subDays(2),
        ]), promoted: true);

        $this->assertSame('freshness', $stale->weakest);
        $this->assertSame('volume', $thin->weakest);
        $this->assertStringContainsString('ageing', $stale->attention);
        $this->assertStringContainsString('more verdicts', $thin->attention);
    }

    /** Every component is exposed, so the total can always be taken apart. */
    public function test_all_dimensions_are_reported_alongside_the_total(): void
    {
        $h = CapabilityHealth::for($this->requirement(), promoted: true);

        foreach (['coverage', 'volume', 'freshness', 'evaluation', 'trust'] as $dimension) {
            $this->assertArrayHasKey($dimension, $h->dimensions);
            $this->assertGreaterThanOrEqual(0.0, $h->dimensions[$dimension]);
            $this->assertLessThanOrEqual(1.0, $h->dimensions[$dimension]);
        }
    }

    /**
     * A proxy knowingly kept after a comparison is more trustworthy than one nobody has examined —
     * "refused" and "never tested" must not score alike.
     */
    public function test_an_examined_proxy_outranks_an_untested_one(): void
    {
        $base = ['quality' => EvidenceRequirement::QUALITY_PROXY];

        $examined = CapabilityHealth::for($this->requirement($base), promoted: false);
        $untested = CapabilityHealth::for($this->requirement($base), promoted: null);

        $this->assertGreaterThan($untested->dimensions['trust'], $examined->dimensions['trust']);
    }

    /** Evidence sitting at the threshold that nobody has ever evaluated is the most actionable state. */
    public function test_ready_but_never_evaluated_scores_zero_on_evaluation(): void
    {
        $h = CapabilityHealth::for($this->requirement(['lastEvaluatedAt' => null]), promoted: null);

        $this->assertSame(0.0, $h->dimensions['evaluation']);
        $this->assertStringContainsString('Re-evaluate', $h->attention);
    }

    /** A feed that has gone quiet is worse than old evidence — nothing is arriving to fix it. */
    public function test_a_quiet_feed_halves_the_freshness_score(): void
    {
        $live  = CapabilityHealth::for($this->requirement(['newestAt' => now()->subDay()]), promoted: true);
        $quiet = CapabilityHealth::for($this->requirement(['newestAt' => now()->subDays(90)]), promoted: true);

        $this->assertGreaterThan($quiet->dimensions['freshness'], $live->dimensions['freshness']);
    }
}
