<?php

namespace Tests\Unit;

use App\Services\Intelligence\Capabilities\ComebackCapability;
use App\Services\Intelligence\CapabilityContext;
use App\Services\Intelligence\DecisionCard;
use App\Services\Intelligence\Evidence;
use App\Services\Intelligence\Readiness\PromotionGate;
use App\Services\RepairIntelligence\Query\HistoricalAnswer;
use App\Services\RepairIntelligence\Query\RepairHistoryQuery;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The capability, driven entirely by a fake query layer.
 *
 * THIS FILE IS THE ARGUMENT FOR THE QUERY LAYER. There is no database here, no projection, no
 * factory, no migration and no fixture — the capability's whole world is an interface returning
 * HistoricalAnswers, so its business logic can be exercised at any history we care to invent,
 * including histories the real corpus does not contain.
 */
class ComebackCapabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.intelligence.comeback_detection', true);
    }

    /**
     * The capability under test, with the promotion gate stubbed.
     *
     * Promotion is a PLATFORM decision recorded by a backtest, not something the capability computes,
     * so these tests state it directly rather than seeding a decision table. `$promoted` is what the
     * gate would answer.
     */
    private function capability(RepairHistoryQuery $query, bool $promoted = false): ComebackCapability
    {
        $gate = \Mockery::mock(PromotionGate::class);
        $gate->shouldReceive('isPromoted')->andReturn($promoted);

        return new ComebackCapability($query, $gate);
    }

    /** @param array<string, int> $episodesBySignature signature => how many prior cases */
    private function query(
        array $episodesBySignature,
        float $fleetRate = 0.467,
        int $fleetN = 1200,
        int $verdictN = 0,
        bool $priorSignedOff = false,
    ): RepairHistoryQuery {
        return new class($episodesBySignature, $fleetRate, $fleetN, $verdictN, $priorSignedOff) implements RepairHistoryQuery
        {
            public function __construct(
                private array $episodes,
                private float $rate,
                private int $n,
                private int $verdictN,
                private bool $priorSignedOff,
            ) {}

            public function version(): string
            {
                return 'fake';
            }

            public function findPreviousEpisodes(int $vehicleId, array $signatures, ?int $excludeTicketId = null, ?string $asOf = null, ?int $windowDays = null): HistoricalAnswer
            {
                $grouped = [];
                $id = 100;

                // Honour the filter the capability passed — otherwise this fake would answer a
                // question it was not asked, and the exclusion test would pass for the wrong reason.
                $asked = array_intersect_key($this->episodes, array_flip($signatures));

                foreach ($asked as $signature => $count) {
                    $rows = [];
                    for ($i = 0; $i < $count; $i++) {
                        $rows[] = (object) [
                            'maintenance_id' => $id++,
                            'signature'      => $signature,
                            'occurred_at'    => now()->subDays(10 + ($i * 15))->toDateString(),
                            'source'         => 'derived',
                        ];
                    }
                    $grouped[$signature] = new Collection($rows);
                }

                if ($grouped === []) {
                    return HistoricalAnswer::empty();
                }

                $all = collect($grouped)->flatten();

                return new HistoricalAnswer(
                    value: $grouped,
                    sampleSize: $all->count(),
                    sourceIds: $all->pluck('maintenance_id')->all(),
                    labelSource: Evidence::LABEL_DERIVED,
                    reconstructionTier: HistoricalAnswer::TIER_DIRECT,
                );
            }

            public function signatureReturnRate(string $signature, ?int $windowDays = null): HistoricalAnswer
            {
                return new HistoricalAnswer(
                    value: ['n' => $this->n, 'returned' => (int) round($this->n * $this->rate), 'rate' => $this->rate],
                    sampleSize: $this->n,
                    labelSource: Evidence::LABEL_MIXED,
                    isProxy: true,
                    proxyNote: 'return rate, not verified repair success',
                );
            }

            public function occurrencesBetween(int $vehicleId, array $signatures, string $from, string $to, ?int $excludeTicketId = null): HistoricalAnswer
            {
                return HistoricalAnswer::empty();
            }

            public function verifiedFailureRate(?string $signature = null): HistoricalAnswer
            {
                // Thin by default — the QC gate is new, so the card must fall back to the proxy.
                return new HistoricalAnswer(
                    value: ['n' => $this->verdictN, 'failed' => (int) round($this->verdictN * 0.3), 'rate' => 0.3],
                    sampleSize: $this->verdictN,
                    labelSource: Evidence::LABEL_HUMAN,
                    isProxy: false,
                );
            }

            public function repairVerdictsFor(array $ticketIds): HistoricalAnswer
            {
                if (! $this->priorSignedOff || $ticketIds === []) {
                    return HistoricalAnswer::empty();
                }

                return new HistoricalAnswer(
                    value: array_fill_keys($ticketIds, 'fixed'),
                    sampleSize: count($ticketIds),
                    labelSource: Evidence::LABEL_HUMAN,
                    isProxy: false,
                );
            }

            public function vehicleHistory(int $vehicleId, ?string $asOf = null, int $limit = 100): HistoricalAnswer
            {
                return HistoricalAnswer::empty();
            }
        };
    }

    private function context(array $signatures = ['COOLING']): CapabilityContext
    {
        return new CapabilityContext(vehicleId: 7, signatures: $signatures, workflowState: 'inspection_pending');
    }

    /** Silence is the normal case and must cost nothing. */
    public function test_no_prior_history_yields_no_card(): void
    {
        $capability = $this->capability($this->query([]));

        $this->assertNull($capability->evaluate($this->context()));
    }

    public function test_a_first_comeback_recommends_a_diagnostic_reset(): void
    {
        $card = ($this->capability($this->query(['COOLING' => 1])))->evaluate($this->context());

        $this->assertInstanceOf(DecisionCard::class, $card);
        $this->assertSame(DecisionCard::TIER_REWORK, $card->tier);
        $this->assertStringContainsString('occurrence 2 of COOLING', $card->observation);
        $this->assertStringContainsString('diagnostic reset', $card->recommendation);
        $this->assertStringNotContainsString('Escalate', $card->recommendation);
    }

    /**
     * THE ESCALATION CASE. A repair that has already come back once returns again 56.9% of the time,
     * so the second comeback must not read like the first.
     */
    public function test_a_repeat_comeback_escalates_and_outranks_a_first(): void
    {
        $escalated = ($this->capability($this->query(['COOLING' => 2])))->evaluate($this->context());
        $first     = ($this->capability($this->query(['COOLING' => 1])))->evaluate($this->context());

        $this->assertStringContainsString('Escalate to supervisor', $escalated->recommendation);
        $this->assertStringContainsString('56.9%', $escalated->reasoning);
        $this->assertTrue($escalated->evidence->facts['is_escalation']);
        $this->assertGreaterThan($first->score(0), $escalated->score(0));
    }

    /** The card interrupts for the worst offender, not whichever signature sorted first. */
    public function test_the_most_recurrent_signature_is_the_one_reported(): void
    {
        $card = ($this->capability($this->query(['BRAKES' => 1, 'COOLING' => 3])))
            ->evaluate($this->context(['BRAKES', 'COOLING']));

        $this->assertSame('COOLING', $card->evidence->facts['signature']);
        $this->assertSame(4, $card->evidence->facts['occurrence']);
    }

    /**
     * The proxy caveat survives the whole journey from query to card. Return rate is not verified
     * repair success, and a card that forgot to say so would be an accusation rather than a signal.
     */
    public function test_the_proxy_caveat_reaches_the_card_and_caps_its_confidence(): void
    {
        $card = ($this->capability($this->query(['COOLING' => 1])))->evaluate($this->context());

        $this->assertTrue($card->evidence->isProxy);
        $this->assertSame('return rate, not verified repair success', $card->evidence->proxyNote);
        $this->assertNotSame('must', $card->strength(), 'A proxy may never speak with full authority.');
    }

    /** Drill-through: the card names the exact prior cases behind it. */
    public function test_the_card_carries_its_prior_cases_for_audit(): void
    {
        $card = ($this->capability($this->query(['COOLING' => 2])))->evaluate($this->context());

        $this->assertCount(2, $card->evidence->sourceIds);
        $this->assertSame($card->evidence->facts['prior_case_ids'], $card->evidence->sourceIds);
    }

    /**
     * appliesTo() asks ONLY "do I have the inputs to answer?". The feature flag, the eligible
     * workflow states and the audience are orchestration, and live in the registered policy — a
     * capability that gated on them would be deciding things it has no view of.
     */
    public function test_applies_to_checks_inputs_only_not_orchestration(): void
    {
        $capability = $this->capability($this->query(['COOLING' => 2]));

        // The flag is the policy's business now, so flipping it changes nothing here.
        config()->set('features.intelligence.comeback_detection', false);
        $this->assertTrue($capability->appliesTo($this->context()));

        // What it DOES check: can this question even be asked?
        $this->assertFalse($capability->appliesTo(new CapabilityContext(vehicleId: 7, signatures: [])));
        $this->assertFalse($capability->appliesTo(new CapabilityContext(vehicleId: null, signatures: ['COOLING'])));
    }

    public function test_the_capability_declares_a_version_for_the_audit_trail(): void
    {
        // v2 = the backtested operating point. A recommendation stamped v1 was produced by the old
        // 90-day rule and must remain distinguishable from one produced today.
        $this->assertSame('v2', ($this->capability($this->query([])))->version());
    }

    // ── v2 operating point ────────────────────────────────────────────────────────────────────

    /**
     * THE NOISE FIX. Eight signatures were measured as no better than guessing — a service interval,
     * a symptom, the classifier's fallback bucket, and four that were actively anti-predictive.
     */
    public function test_excluded_signatures_never_fire_however_bad_the_history_looks(): void
    {
        $capability = $this->capability($this->query(['OIL_SERVICE' => 4, 'BATTERY' => 3]));

        $this->assertNull($capability->evaluate($this->context(['OIL_SERVICE', 'BATTERY'])));
    }

    public function test_an_excluded_signature_does_not_mask_a_real_one_on_the_same_ticket(): void
    {
        $card = ($this->capability($this->query(['OIL_SERVICE' => 9, 'COOLING' => 1])))
            ->evaluate($this->context(['OIL_SERVICE', 'COOLING']));

        // OIL_SERVICE has far more priors, but it is silent — so COOLING must still be reported.
        $this->assertNotNull($card);
        $this->assertSame('COOLING', $card->evidence->facts['signature']);
    }

    public function test_the_minimum_prior_count_is_enforced_from_config(): void
    {
        config()->set('features.intelligence.comeback.min_priors', 3);

        $this->assertNull(($this->capability($this->query(['COOLING' => 2])))->evaluate($this->context()));
        $this->assertNotNull(($this->capability($this->query(['COOLING' => 3])))->evaluate($this->context()));
    }

    /** A card may never cite a statistic it was not computed the same way as. */
    public function test_the_quoted_base_rate_uses_the_same_window_the_card_fired_on(): void
    {
        config()->set('features.intelligence.comeback.window_days', 14);

        $card = ($this->capability($this->query(['COOLING' => 1])))->evaluate($this->context());

        $this->assertSame(14, $card->evidence->facts['window_days']);
        $this->assertStringContainsString('within 14 days', $card->reasoning);
    }

    // ── the proxy, and how it ends ────────────────────────────────────────────────────────────

    /** Un-promoted, the card keeps the caveat and stays capped at moderate. */
    public function test_without_a_promotion_the_card_remains_a_proxy(): void
    {
        $card = ($this->capability($this->query(['COOLING' => 1], verdictN: 6), promoted: false))->evaluate($this->context());

        $this->assertTrue($card->evidence->isProxy);
        $this->assertSame('return rate (proxy)', $card->evidence->facts['basis']);
        $this->assertNotSame('must', $card->strength());
    }

    /**
     * THE DECISIVE ONE. Verdict volume alone must NOT promote the card — otherwise "we have enough
     * data now" silently becomes "the data is better now", which is the assumption the promotion
     * gate exists to test rather than trust.
     */
    public function test_verdict_volume_alone_does_not_promote_the_card(): void
    {
        $card = ($this->capability($this->query(['COOLING' => 1], verdictN: 5000), promoted: false))->evaluate($this->context());

        $this->assertTrue($card->evidence->isProxy, 'Volume is not evidence that the measured model predicts better.');
        $this->assertSame('return rate (proxy)', $card->evidence->facts['basis']);
    }

    /**
     * THE PROMOTION. Once a recorded backtest says the measured outcome predicts at least as well,
     * the card measures instead of estimating — and may finally speak with full authority.
     */
    public function test_a_recorded_promotion_moves_the_card_onto_measured_evidence(): void
    {
        $card = ($this->capability($this->query(['COOLING' => 1], verdictN: 120), promoted: true))->evaluate($this->context());

        $this->assertFalse($card->evidence->isProxy);
        $this->assertSame('verified QC verdicts', $card->evidence->facts['basis']);
        $this->assertSame('must', $card->strength(), 'A measured verdict may finally speak with full authority.');
        $this->assertStringContainsString('found not actually fixed', $card->reasoning);
    }

    /** A promotion cannot conjure evidence: with no verdicts at all the card stays on the proxy. */
    public function test_a_promotion_without_any_verdicts_still_falls_back(): void
    {
        $card = ($this->capability($this->query(['COOLING' => 1], verdictN: 0), promoted: true))->evaluate($this->context());

        $this->assertTrue($card->evidence->isProxy);
    }

    /**
     * "The fault came back" and "a repair a human passed has failed" are different claims, and the
     * second one is a different conversation with the garage.
     */
    public function test_a_signed_off_prior_repair_changes_the_claim(): void
    {
        $card = ($this->capability($this->query(['COOLING' => 1], priorSignedOff: true)))->evaluate($this->context());

        $this->assertTrue($card->evidence->facts['prior_signed_off']);
        $this->assertStringContainsString('signed off as fixed', $card->observation);
        $this->assertStringContainsString('failed repair rather than a new complaint', $card->reasoning);
    }
}
