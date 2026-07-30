<?php

namespace Tests\Unit;

use App\Services\Intelligence\Confidence;
use App\Services\Intelligence\Evidence;
use App\Services\RepairIntelligence\Query\HistoricalAnswer;
use Tests\TestCase;

/**
 * The query layer's contract: an answer carries its own trustworthiness.
 *
 * This is what stops five capabilities computing five different confidences for the same data. The
 * grading inputs come from the query — the layer that actually knows how the answer was
 * assembled — and a capability can add wording without being able to restate them.
 */
class HistoricalAnswerTest extends TestCase
{
    public function test_an_answer_grades_itself_without_the_capability_doing_arithmetic(): void
    {
        $answer = new HistoricalAnswer(
            value: ['rate' => 0.467],
            sampleSize: 1200,
            labelSource: Evidence::LABEL_MIXED,
            reconstructionTier: HistoricalAnswer::TIER_DIRECT,
            isProxy: true,
            proxyNote: 'return rate, not verified repair success',
        );

        // Big sample, direct tier — but a proxy, so it can never present as strong.
        $this->assertSame(Confidence::Moderate, $answer->confidence());
        $this->assertTrue($answer->evidence()->isProxy);
        $this->assertSame('return rate, not verified repair success', $answer->evidence()->proxyNote);
    }

    /** A thin answer must degrade the card even when everything else about it looks respectable. */
    public function test_a_thin_answer_is_limited(): void
    {
        $answer = new HistoricalAnswer(value: [1, 2], sampleSize: 2, labelSource: Evidence::LABEL_HUMAN);

        $this->assertSame(Confidence::Limited, $answer->confidence());
        $this->assertTrue($answer->evidence()->isTooThinForStatistics());
    }

    /** Quoting a precise history alongside a weak base rate is only as good as the base rate. */
    public function test_combining_answers_takes_the_weakest(): void
    {
        $precise = new HistoricalAnswer(value: [1], sampleSize: 400, labelSource: Evidence::LABEL_HUMAN);
        $weak    = new HistoricalAnswer(value: [1], sampleSize: 3, labelSource: Evidence::LABEL_DERIVED);

        $this->assertSame(Confidence::Strong, $precise->confidence());
        $this->assertSame(Confidence::Limited, HistoricalAnswer::weakestOf($precise, $weak));
    }

    public function test_a_capability_may_add_facts_but_not_restate_the_metadata(): void
    {
        $answer = new HistoricalAnswer(
            value: [1],
            sampleSize: 50,
            sourceIds: [10, 11],
            labelSource: Evidence::LABEL_DERIVED,
            facts: ['window_days' => 90],
        );

        $evidence = $answer->evidence(facts: ['signature' => 'COOLING'], sourceIds: [11, 12]);

        $this->assertSame(90, $evidence->facts['window_days']);
        $this->assertSame('COOLING', $evidence->facts['signature']);
        $this->assertSame([10, 11, 12], $evidence->sourceIds, 'Source ids merge and de-duplicate.');
        // The trust inputs are the query's, untouched by the caller.
        $this->assertSame(50, $evidence->sampleSize);
        $this->assertSame(Evidence::LABEL_DERIVED, $evidence->labelSource);
    }

    public function test_empty_is_a_normal_answer_not_an_error(): void
    {
        $this->assertTrue(HistoricalAnswer::empty()->isEmpty());
        $this->assertTrue((new HistoricalAnswer(value: [], sampleSize: 0))->isEmpty());
        $this->assertFalse((new HistoricalAnswer(value: [1], sampleSize: 1))->isEmpty());
    }
}
