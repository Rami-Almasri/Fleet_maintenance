<?php

namespace Tests\Unit;

use App\Models\CapabilityPromotion;
use App\Services\Intelligence\Readiness\EvidenceLedger;
use App\Services\Intelligence\Readiness\EvidenceRequirement;
use App\Services\Intelligence\Readiness\PromotionGate;
use App\Services\RepairIntelligence\Backtest\ComebackBacktest;
use Tests\TestCase;

/**
 * The promotion gate's contract: evidence-driven, never calendar-driven, and able to say no.
 *
 * The temptation this guards against is assuming that better-quality evidence must produce a better
 * model. The QC verdict is scarcer than return-rate, arrives later, and covers a different slice of
 * tickets — so it might well predict worse. If it does, the honest outcome is to keep the proxy and
 * record why, which is why a refusal is a first-class result here rather than an error.
 */
class PromotionGateTest extends TestCase
{
    private function ledger(int $current, int $threshold, ?string $blocker = null): EvidenceLedger
    {
        $requirement = new EvidenceRequirement(
            capabilityId: 'comeback-warning',
            label: 'Comeback',
            evidence: 'QC verdicts',
            current: $current,
            threshold: $threshold,
            weeklyRate: 3.0,
            blocker: $blocker,
        );

        $ledger = \Mockery::mock(EvidenceLedger::class);
        $ledger->shouldReceive('for')->andReturn($requirement);

        return $ledger;
    }

    private function backtest(array $proxy, array $measured): ComebackBacktest
    {
        $b = \Mockery::mock(ComebackBacktest::class);
        $b->shouldReceive('run')->with(ComebackBacktest::OUTCOME_RECURRENCE)->andReturn($proxy);
        $b->shouldReceive('run')->with(ComebackBacktest::OUTCOME_VERDICT)->andReturn($measured);

        // Provenance is recorded on every decision, including the ones that never run a comparison.
        $b->shouldReceive('modelVersion')->with('recurrence')->andReturn('comeback/v2+w14m1x8/recurrence');
        $b->shouldReceive('modelVersion')->with('verdict')->andReturn('comeback/v2+w14m1x8/verdict');
        $b->shouldReceive('datasetVersion')->andReturn('proj/v1:49501:2026-07-14');
        $b->shouldReceive('operatingPoint')->andReturn(['window_days' => 14, 'min_priors' => 1, 'excluded_signatures' => []]);

        return $b;
    }

    private function metrics(float $lift, float $precision = 55.0, bool $sufficient = true): array
    {
        return [
            'outcome' => 'x', 'opportunities' => 500, 'positives' => 200, 'base_rate' => 40.0,
            'fired' => 100, 'tickets' => 100, 'precision' => $precision, 'recall' => 12.0,
            'lift' => $lift, 'fpr' => 6.0, 'fnr' => 88.0, 'sufficient' => $sufficient,
        ];
    }

    /** Reaching the threshold buys the right to run the comparison — nothing more. */
    public function test_below_the_threshold_no_comparison_is_run(): void
    {
        $gate = new PromotionGate($this->ledger(6, 30), $this->backtest($this->metrics(1.4), $this->metrics(1.9)));

        $result = $gate->evaluate(persist: false);

        $this->assertSame('not-yet', $result['decision']);
        $this->assertFalse($result['promoted']);
        $this->assertNull($result['measured'], 'The comparison must not even run below the threshold.');
        $this->assertStringContainsString('6 of 30', $result['reason']);
    }

    public function test_at_the_threshold_a_better_measured_model_is_promoted(): void
    {
        $gate = new PromotionGate($this->ledger(30, 30), $this->backtest($this->metrics(1.45), $this->metrics(1.90)));

        $result = $gate->evaluate(persist: false);

        $this->assertTrue($result['promoted']);
        $this->assertStringContainsString('Promoted', $result['reason']);
    }

    /**
     * THE REFUSAL. More trustworthy evidence that predicts worse is still worse — and the platform
     * has to be able to discover that about itself.
     */
    public function test_a_worse_measured_model_is_refused_even_with_ample_evidence(): void
    {
        $gate = new PromotionGate($this->ledger(5000, 30), $this->backtest($this->metrics(1.45), $this->metrics(0.90)));

        $result = $gate->evaluate(persist: false);

        $this->assertFalse($result['promoted']);
        $this->assertStringContainsString('Refused', $result['reason']);
    }

    /** A marginally worse model still promotes — the tolerance stops thrashing on noise. */
    public function test_a_marginally_worse_model_is_within_tolerance(): void
    {
        $gate = new PromotionGate($this->ledger(30, 30), $this->backtest($this->metrics(1.45), $this->metrics(1.44)));

        $this->assertTrue($gate->evaluate(persist: false)['promoted']);
    }

    /** A comparison on too few cases is noise wearing the costume of evidence. */
    public function test_an_insufficient_measured_backtest_is_refused_not_promoted(): void
    {
        $gate = new PromotionGate(
            $this->ledger(30, 30),
            $this->backtest($this->metrics(1.45), $this->metrics(9.9, sufficient: false)),
        );

        $result = $gate->evaluate(persist: false);

        $this->assertFalse($result['promoted'], 'A spectacular lift on six cases is not a promotion.');
        $this->assertStringContainsString('too thin', $result['reason']);
    }

    // ── evaluation provenance ─────────────────────────────────────────────────────────────────

    /**
     * A decision must be reconstructable. Without the model, dataset and methodology versions, a
     * refusal today and a promotion next quarter are indistinguishable from a change of mind — when
     * they may be two correct answers to two different questions.
     */
    public function test_every_evaluation_records_what_it_compared_and_on_what(): void
    {
        $gate = new PromotionGate($this->ledger(6, 30), $this->backtest($this->metrics(1.4), $this->metrics(1.9)));

        $p = $gate->evaluate(persist: false)['provenance'];

        foreach (['proxy_model_version', 'measured_model_version', 'dataset_version', 'backtest_version', 'capability_version', 'query_layer_version'] as $key) {
            $this->assertArrayHasKey($key, $p);
            $this->assertNotSame('', $p[$key], "{$key} must be populated.");
        }

        // The two models differ ONLY in the outcome they are scored against.
        $this->assertStringEndsWith('/recurrence', $p['proxy_model_version']);
        $this->assertStringEndsWith('/verdict', $p['measured_model_version']);
    }

    /**
     * The same capability version tuned differently is a DIFFERENT model. A version string that
     * ignored the operating point would make a retune invisible in the audit trail.
     */
    public function test_retuning_the_operating_point_changes_the_model_version(): void
    {
        $backtest = new \App\Services\RepairIntelligence\Backtest\ComebackBacktest();

        config()->set('features.intelligence.comeback.window_days', 14);
        $narrow = $backtest->modelVersion('recurrence');

        config()->set('features.intelligence.comeback.window_days', 90);
        $wide = $backtest->modelVersion('recurrence');

        $this->assertNotSame($narrow, $wide, 'A different window is a different model.');
        $this->assertStringContainsString('w14', $narrow);
        $this->assertStringContainsString('w90', $wide);
    }

    /**
     * A standing decision rests on a dataset and a methodology. When either moves, the decision is
     * not wrong — it was correct for what it evaluated — but it has stopped describing the present.
     */
    public function test_a_decision_is_superseded_when_the_dataset_or_method_moves(): void
    {
        $decision = new CapabilityPromotion([
            'capability_id' => 'comeback-warning',
            'dataset_version' => 'proj/v1:49501:2026-07-14',
            'backtest_version' => 'v1',
            'proxy_model_version' => 'comeback/v2+w14m1x8/recurrence',
        ]);

        $this->assertFalse($decision->isSupersededBy([
            'dataset_version' => 'proj/v1:49501:2026-07-14', 'backtest_version' => 'v1',
        ]), 'Nothing moved.');

        $this->assertTrue($decision->isSupersededBy([
            'dataset_version' => 'proj/v2:52000:2026-09-01', 'backtest_version' => 'v1',
        ]), 'A projection rebuild changes every answer without changing a line of code.');

        $this->assertTrue($decision->isSupersededBy([
            'dataset_version' => 'proj/v1:49501:2026-07-14', 'backtest_version' => 'v2',
        ]), 'A methodology change asks a different question.');
    }

    /**
     * A "not enough evidence to look" outcome is part of the decision's history too. A gate that only
     * wrote rows when it had something interesting to say would leave gaps exactly where someone
     * later asks whether anyone was paying attention.
     */
    public function test_a_below_threshold_evaluation_is_still_a_recorded_outcome(): void
    {
        $gate = new PromotionGate($this->ledger(6, 30), $this->backtest($this->metrics(1.4), $this->metrics(1.9)));

        $result = $gate->evaluate(persist: false);

        $this->assertSame('not-yet', $result['decision']);
        $this->assertNotEmpty($result['provenance'], 'Even a not-yet carries the full bundle.');
        $this->assertSame('proj/v1:49501:2026-07-14', $result['provenance']['dataset_version']);
    }

    /**
     * The bundle is enforced at the model, not merely supplied by the gate — so a future evaluation
     * path that forgets a field fails at the moment it is written, rather than silently years later
     * when someone asks why a capability was promoted.
     */
    public function test_a_decision_without_full_provenance_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/full provenance/');

        CapabilityPromotion::create([
            'capability_id' => 'comeback-warning',
            'from_basis'    => 'proxy',
            'to_basis'      => 'measured',
            'promoted'      => true,
            'reason'        => 'looked good to me',
            'decided_at'    => now(),
        ]);
    }

    /** Decisions are claims about the world: recorded, and never edited. */
    public function test_a_decision_is_append_only(): void
    {
        $promotion = new CapabilityPromotion([
            'capability_id' => 'x', 'from_basis' => 'proxy', 'to_basis' => 'measured',
            'promoted' => false, 'reason' => 'r', 'decided_at' => now(),
        ]);
        $promotion->exists = true;

        $this->expectException(\RuntimeException::class);
        $promotion->update(['promoted' => true]);
    }
}
