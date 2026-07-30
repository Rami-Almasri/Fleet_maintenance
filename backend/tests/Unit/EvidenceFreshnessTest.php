<?php

namespace Tests\Unit;

use App\Services\Intelligence\Readiness\EvidenceRequirement;
use Tests\TestCase;

/**
 * Evidence freshness — the failure mode that volume hides.
 *
 * A capability can clear its threshold and still be reasoning about a fleet that no longer exists.
 * None of this promotes or demotes anything; it says only that a previous conclusion may have
 * stopped describing today, which is a prompt to re-run the gate rather than a verdict on its answer.
 */
class EvidenceFreshnessTest extends TestCase
{
    private function requirement(array $overrides = []): EvidenceRequirement
    {
        return new EvidenceRequirement(...array_merge([
            'capabilityId' => 'c',
            'label'        => 'C',
            'evidence'     => 'verdicts',
            'current'      => 100,
            'threshold'    => 30,
        ], $overrides));
    }

    /** Threshold met and nobody has ever looked — the most actionable state on the board. */
    public function test_a_ready_but_never_evaluated_capability_is_flagged(): void
    {
        $r = $this->requirement(['lastEvaluatedAt' => null]);

        $this->assertTrue($r->needsReevaluation());
        $this->assertStringContainsString('never evaluated', $r->reevaluationReason());
    }

    /** Not ready and never evaluated is not stale — it is simply waiting, which the ledger says elsewhere. */
    public function test_an_unready_never_evaluated_capability_is_not_flagged_as_stale(): void
    {
        $r = $this->requirement(['current' => 5, 'threshold' => 30, 'lastEvaluatedAt' => null]);

        $this->assertFalse($r->needsReevaluation());
    }

    /** A rebuilt corpus changes every answer without changing a line of code. */
    public function test_a_moved_dataset_calls_for_re_evaluation(): void
    {
        $r = $this->requirement(['lastEvaluatedAt' => now()->subDays(3), 'datasetMoved' => true]);

        $this->assertStringContainsString('corpus has been rebuilt', $r->reevaluationReason());
    }

    /** Half again as much evidence is enough that the comparison could plausibly land differently. */
    public function test_material_evidence_growth_calls_for_re_evaluation(): void
    {
        $r = $this->requirement([
            'current' => 150, 'lastEvaluatedAt' => now()->subDays(3), 'evidenceAtLastEvaluation' => 100,
        ]);

        $this->assertStringContainsString('grown 50%', $r->reevaluationReason());
    }

    public function test_marginal_growth_does_not_churn_the_gate(): void
    {
        $r = $this->requirement([
            'current' => 110, 'lastEvaluatedAt' => now()->subDays(3), 'evidenceAtLastEvaluation' => 100,
        ]);

        $this->assertNull($r->reevaluationReason(), '10% more evidence will not change the answer.');
    }

    public function test_an_old_evaluation_is_flagged_even_when_nothing_else_moved(): void
    {
        $r = $this->requirement([
            'lastEvaluatedAt' => now()->subDays(120), 'evidenceAtLastEvaluation' => 100, 'current' => 100,
        ]);

        $this->assertStringContainsString('last evaluated 120 days ago', $r->reevaluationReason());
    }

    public function test_a_recent_evaluation_on_an_unchanged_corpus_still_stands(): void
    {
        $r = $this->requirement([
            'lastEvaluatedAt' => now()->subDays(5), 'evidenceAtLastEvaluation' => 100, 'current' => 100,
        ]);

        $this->assertNull($r->reevaluationReason());
        $this->assertFalse($r->needsReevaluation());
    }

    /**
     * VOLUME AND FRESHNESS FAIL INDEPENDENTLY. Five thousand observations with a two-year median is
     * not well-evidenced; it is well-evidenced about the past.
     */
    public function test_ample_but_old_evidence_is_stale(): void
    {
        $this->assertTrue($this->requirement(['current' => 5000, 'medianAgeDays' => 730])->isEvidenceStale());
        $this->assertFalse($this->requirement(['current' => 40, 'medianAgeDays' => 30])->isEvidenceStale());
    }

    /** A dead feed and a healthy one look identical on a count alone. */
    public function test_a_quiet_feed_is_detected(): void
    {
        $this->assertTrue($this->requirement(['newestAt' => now()->subDays(60)])->feedIsQuiet());
        $this->assertFalse($this->requirement(['newestAt' => now()->subDays(3)])->feedIsQuiet());
        $this->assertFalse($this->requirement(['newestAt' => null])->feedIsQuiet(), 'No evidence at all is a volume problem, not a freshness one.');
    }
}
