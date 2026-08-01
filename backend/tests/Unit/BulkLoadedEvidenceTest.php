<?php

namespace Tests\Unit;

use App\Services\Intelligence\Readiness\CapabilityHealth;
use App\Services\Intelligence\Readiness\EvidenceRequirement;
use Tests\TestCase;

/**
 * A pile of rows handed over in one afternoon is not the same asset as the same count accumulated by
 * a running process.
 *
 * This exists because the ledger was fooled exactly once, in the most convincing way available: 468
 * of 478 part purchases were written in a single backfill, and every readiness signal agreed the
 * capability was in great shape. Volume cleared its threshold. Freshness was perfect — the evidence
 * was hours old. The arrival rate read 1,397 per week, a figure no fleet has ever produced, because
 * a lifetime average cannot tell one event from a habit. Parts Recommendation went from BLOCKED to
 * READY and became the platform's top recommendation without a single new repair being recorded.
 *
 * Readiness is a claim about the FUTURE: that the feed will keep running, and that a model trained
 * today will still have inputs next month. Imported history cannot support that claim, however much
 * of it there is.
 */
class BulkLoadedEvidenceTest extends TestCase
{
    private function requirement(array $overrides = []): EvidenceRequirement
    {
        return new EvidenceRequirement(
            capabilityId: $overrides['id'] ?? 'parts-recommendation',
            label: 'Parts Recommendation',
            evidence: 'part lines attached to repairs',
            current: $overrides['current'] ?? 3000,
            threshold: $overrides['threshold'] ?? 2000,
            weeklyRate: $overrides['rate'] ?? 1400.0,
            quality: EvidenceRequirement::QUALITY_MEASURED,
            coverage: 0.4,
            medianAgeDays: 0,
            singleDayShare: $overrides['share'] ?? null,
        );
    }

    public function test_a_steady_feed_is_not_flagged(): void
    {
        $this->assertFalse($this->requirement(['share' => 0.08])->wasBulkLoaded());
    }

    /** THE CASE THAT HAPPENED. */
    public function test_evidence_that_arrived_in_one_day_is_flagged(): void
    {
        $this->assertTrue($this->requirement(['share' => 0.98])->wasBulkLoaded());
    }

    /**
     * The floor, added after the check fired on the comeback card at NINE verdicts — five of which
     * happened to land on the same Thursday — and announced its evidence was "an import, not a feed".
     * It is neither. It is nine rows. Sample-size discipline applies to the platform's diagnostics as
     * much as to its cards.
     */
    public function test_a_busy_day_in_a_tiny_dataset_is_just_a_busy_day(): void
    {
        $this->assertFalse(
            $this->requirement(['current' => 9, 'threshold' => 30, 'share' => 0.56])->wasBulkLoaded(),
            'Nine observations cannot distinguish an import from an ordinary Thursday.',
        );
    }

    /**
     * The threshold IS met, and pretending otherwise would be a different lie. What is not
     * established is that the fleet produces this data on its own.
     */
    public function test_it_still_reads_as_ready_but_says_where_the_evidence_came_from(): void
    {
        $r = $this->requirement(['share' => 0.98]);

        $this->assertSame(EvidenceRequirement::STATUS_READY, $r->status());
        $this->assertSame('READY (imported)', $r->readinessLabel());
    }

    /**
     * Extrapolating a rate that is really one import gives a confident date days away that will never
     * arrive — the thing being projected forward already happened and will not happen again tomorrow.
     */
    public function test_no_readiness_date_is_projected_from_an_import(): void
    {
        $growing = $this->requirement(['current' => 500, 'threshold' => 2000, 'share' => 0.98]);

        $this->assertNull($growing->readyAt());
        $this->assertSame('one import, no ongoing feed', $growing->readinessLabel());
    }

    /** A genuine feed short of its threshold must still get a projection. */
    public function test_a_real_feed_still_gets_a_readiness_date(): void
    {
        $growing = $this->requirement(['current' => 500, 'threshold' => 2000, 'rate' => 100.0, 'share' => 0.05]);

        $this->assertNotNull($growing->readyAt());
    }

    /**
     * Checked BEFORE the re-evaluation prompt. "Threshold met, nobody has evaluated it" invites
     * running a backtest on a corpus that will not grow, which is the wrong action.
     */
    public function test_the_attention_line_names_the_import_rather_than_asking_for_a_backtest(): void
    {
        $health = CapabilityHealth::for($this->requirement(['share' => 0.98]));

        $this->assertStringContainsString('arrived on one day', $health->attention);
        $this->assertStringContainsString('import, not a feed', $health->attention);
    }

    /** Halved, not zeroed: the rows are real and usable — the guarantee that more are coming is not. */
    public function test_volume_is_discounted_but_not_erased(): void
    {
        $steady = CapabilityHealth::for($this->requirement(['share' => 0.05]));
        $bulk   = CapabilityHealth::for($this->requirement(['share' => 0.98]));

        $this->assertSame(1.0, $steady->dimensions['volume']);
        $this->assertSame(0.5, $bulk->dimensions['volume']);
        $this->assertLessThan($steady->score, $bulk->score);
    }

    /** No burst measurement at all must never be read as "it was bulk loaded". */
    public function test_an_unknown_share_is_not_treated_as_an_import(): void
    {
        $this->assertFalse($this->requirement(['share' => null])->wasBulkLoaded());
    }

    /**
     * A share above 1.0 is arithmetically impossible and means the measurement is broken — which it
     * once was, reporting 22,511% because a query read the DATE column instead of the COUNT.
     */
    public function test_the_share_can_never_exceed_one(): void
    {
        $r = $this->requirement(['share' => 0.98]);

        $this->assertLessThanOrEqual(1.0, $r->singleDayShare);
    }
}
