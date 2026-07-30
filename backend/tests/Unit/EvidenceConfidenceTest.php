<?php

namespace Tests\Unit;

use App\Services\Intelligence\Confidence;
use App\Services\Intelligence\Evidence;
use Tests\TestCase;

/**
 * Confidence is COMPUTED, never authored — this is what stops optimism leaking into the UI.
 *
 * The rule under test is that the WEAKEST input wins. A large sample of derived labels linked at
 * Tier C is not strong evidence, and must not present as such just because one of its inputs looks
 * impressive.
 */
class EvidenceConfidenceTest extends TestCase
{
    public function test_sample_size_sets_the_baseline_band(): void
    {
        $this->assertSame(Confidence::Strong, (new Evidence(sampleSize: 30, labelSource: Evidence::LABEL_HUMAN))->confidence());
        $this->assertSame(Confidence::Moderate, (new Evidence(sampleSize: 8, labelSource: Evidence::LABEL_HUMAN))->confidence());
        $this->assertSame(Confidence::Limited, (new Evidence(sampleSize: 7, labelSource: Evidence::LABEL_HUMAN))->confidence());
    }

    /** Derived labels cap a card at moderate however many of them there are. */
    public function test_derived_labels_cap_confidence(): void
    {
        $evidence = new Evidence(sampleSize: 5000, labelSource: Evidence::LABEL_DERIVED);

        $this->assertSame(Confidence::Moderate, $evidence->confidence());
    }

    /** A big sample linked at Tier C is still weak — the weakest input decides. */
    public function test_the_weakest_input_wins(): void
    {
        $evidence = new Evidence(
            sampleSize: 5000,
            labelSource: Evidence::LABEL_HUMAN,
            costTier: 'C',
        );

        $this->assertSame(Confidence::Limited, $evidence->confidence());
    }

    public function test_thin_population_coverage_downgrades(): void
    {
        $evidence = new Evidence(sampleSize: 500, labelSource: Evidence::LABEL_HUMAN, coverage: 0.30);

        $this->assertSame(Confidence::Limited, $evidence->confidence());
    }

    /** A proxy can never be strong. Return rate is not verified success. */
    public function test_a_proxy_metric_can_never_be_strong(): void
    {
        $evidence = new Evidence(
            sampleSize: 10_000,
            labelSource: Evidence::LABEL_HUMAN,
            costTier: 'A',
            coverage: 1.0,
            isProxy: true,
            proxyNote: 'return rate, not verified repair success',
        );

        $this->assertSame(Confidence::Moderate, $evidence->confidence());
        $this->assertSame('return rate, not verified repair success', $evidence->toArray()['proxy_note']);
    }

    public function test_stale_knowledge_degrades(): void
    {
        $fresh = new Evidence(sampleSize: 100, labelSource: Evidence::LABEL_HUMAN, asOf: now());
        $stale = new Evidence(sampleSize: 100, labelSource: Evidence::LABEL_HUMAN, asOf: now()->subDays(45));

        $this->assertSame(Confidence::Strong, $fresh->confidence());
        $this->assertSame(Confidence::Moderate, $stale->confidence());
    }

    /** Below eight cases the pipeline must show the cases themselves, not a statistic. */
    public function test_flags_when_a_sample_is_too_thin_for_statistics(): void
    {
        $this->assertTrue((new Evidence(sampleSize: 7))->isTooThinForStatistics());
        $this->assertFalse((new Evidence(sampleSize: 8))->isTooThinForStatistics());
    }

    /** Every claim must be drillable back to the rows behind it. */
    public function test_carries_its_source_rows(): void
    {
        $evidence = new Evidence(sampleSize: 3, sourceIds: [11, 22, 33]);

        $this->assertSame([11, 22, 33], $evidence->toArray()['source_ids']);
    }
}
