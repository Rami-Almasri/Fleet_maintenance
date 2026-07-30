<?php

namespace Tests\Unit;

use App\Services\Intelligence\CapabilityContext;
use App\Services\Intelligence\CardArbitrator;
use App\Services\Intelligence\Confidence;
use App\Services\Intelligence\Contracts\IntelligenceCapability;
use App\Services\Intelligence\DecisionCard;
use App\Services\Intelligence\DecisionEngine;
use App\Services\Intelligence\Evidence;
use Tests\TestCase;

/**
 * The shared pipeline's contract. Every capability built from here inherits this behaviour, so
 * these tests protect far more than one feature.
 */
class DecisionEngineTest extends TestCase
{
    private function card(string $id, int $tier, float $leverage = 0.5, int $n = 50, bool $actionable = true, int $minimumSample = 1): DecisionCard
    {
        return new DecisionCard(
            id: $id,
            tier: $tier,
            audience: ['maintenance.delegate'],
            observation: 'observation',
            recommendation: 'recommendation',
            reasoning: 'reasoning',
            evidence: new Evidence(sampleSize: $n, labelSource: Evidence::LABEL_HUMAN),
            leverage: $leverage,
            actionable: $actionable,
            minimumSample: $minimumSample,
        );
    }

    private function capability(DecisionCard|null $card, string $id = 'cap', bool $applies = true): IntelligenceCapability
    {
        return new class($card, $id, $applies) implements IntelligenceCapability
        {
            public function __construct(private ?DecisionCard $card, private string $id, private bool $applies) {}

            public function id(): string { return $this->id; }

            public function version(): string { return 'v1'; }

            public function appliesTo(CapabilityContext $context): bool { return $this->applies; }

            public function evaluate(CapabilityContext $context): ?DecisionCard { return $this->card; }
        };
    }

    /**
     * PRECEDENCE BEATS SCORE. A perfectly-scored cost card must never displace a rework warning —
     * that is the whole reason arbitration is tiered rather than blended.
     */
    public function test_a_lower_tier_wins_regardless_of_score(): void
    {
        $engine = new DecisionEngine(new CardArbitrator(), [
            $this->capability($this->card('cost', DecisionCard::TIER_COST, leverage: 1.0), 'cost'),
            $this->capability($this->card('rework', DecisionCard::TIER_REWORK, leverage: 0.1), 'rework'),
        ]);

        $cards = $engine->decide(new CapabilityContext());

        $this->assertSame('rework', $cards[0]->id, 'Rework prevention must outrank cost even at a tenth of the leverage.');
    }

    public function test_within_a_tier_the_higher_score_leads(): void
    {
        $engine = new DecisionEngine(new CardArbitrator(), [
            $this->capability($this->card('weak', DecisionCard::TIER_ROUTING, leverage: 0.2), 'weak'),
            $this->capability($this->card('strong', DecisionCard::TIER_ROUTING, leverage: 0.9), 'strong'),
        ]);

        $cards = $engine->decide(new CapabilityContext());

        $this->assertSame('strong', $cards[0]->id);
    }

    /** The budget is absolute: one primary, two secondary. Eligibility beyond that is not a layout problem. */
    public function test_the_card_budget_is_enforced(): void
    {
        $capabilities = [];
        foreach (range(1, 8) as $i) {
            $capabilities[] = $this->capability($this->card("card-$i", DecisionCard::TIER_ROUTING), "card-$i");
        }

        $cards = (new DecisionEngine(new CardArbitrator(), $capabilities))->decide(new CapabilityContext());

        $this->assertCount(DecisionEngine::BUDGET_TOTAL, $cards);
        $this->assertSame(3, DecisionEngine::BUDGET_TOTAL);
    }

    /**
     * THE ANTI-DASHBOARD GATE. If the user cannot act on it at this state, it does not render at
     * this state — an eligibility gate, not a scoring penalty.
     */
    public function test_a_non_actionable_card_is_ineligible(): void
    {
        $engine = new DecisionEngine(new CardArbitrator(), [
            $this->capability($this->card('fyi', DecisionCard::TIER_SAFETY, leverage: 1.0, actionable: false), 'fyi'),
        ]);

        $this->assertSame([], $engine->decide(new CapabilityContext()));
    }

    public function test_a_card_below_its_evidence_floor_is_suppressed(): void
    {
        $engine = new DecisionEngine(new CardArbitrator(), [
            $this->capability($this->card('thin', DecisionCard::TIER_ROUTING, n: 5, minimumSample: 30), 'thin'),
        ]);

        $this->assertSame([], $engine->decide(new CapabilityContext()));
    }

    /** A capability that throws must never break the workflow it advises. */
    public function test_a_failing_capability_is_skipped_not_fatal(): void
    {
        $exploding = new class implements IntelligenceCapability
        {
            public function id(): string { return 'boom'; }

            public function version(): string { return 'v1'; }

            public function appliesTo(CapabilityContext $context): bool { return true; }

            public function evaluate(CapabilityContext $context): ?DecisionCard
            {
                throw new \RuntimeException('query blew up');
            }
        };

        $engine = new DecisionEngine(new CardArbitrator(), [$exploding, $this->capability($this->card('ok', DecisionCard::TIER_ROUTING), 'ok')]);

        $cards = $engine->decide(new CapabilityContext());

        $this->assertCount(1, $cards);
        $this->assertSame('ok', $cards[0]->id);
    }

    public function test_capabilities_that_do_not_apply_are_not_evaluated(): void
    {
        $engine = new DecisionEngine(new CardArbitrator(), [
            $this->capability($this->card('x', DecisionCard::TIER_ROUTING), 'x', applies: false),
        ]);

        $this->assertSame([], $engine->decide(new CapabilityContext()));
    }

    /** Silence is the normal case: most capabilities have nothing to say about most decisions. */
    public function test_a_capability_may_return_nothing(): void
    {
        $engine = new DecisionEngine(new CardArbitrator(), [$this->capability(null, 'quiet')]);

        $this->assertSame([], $engine->decide(new CapabilityContext()));
    }

    /**
     * ANTI-FATIGUE. Repeated same-reason overrides decay a card's score, and at six it stops
     * rendering — a card that is always dismissed is either wrong or badly timed, and the platform
     * should discover that about itself.
     */
    public function test_repeated_overrides_decay_then_suppress_a_card(): void
    {
        $engine = new DecisionEngine(new CardArbitrator(), [
            $this->capability($this->card('nagging', DecisionCard::TIER_ROUTING, leverage: 0.9), 'nagging'),
            $this->capability($this->card('quiet', DecisionCard::TIER_ROUTING, leverage: 0.6), 'quiet'),
        ]);

        $fresh = $engine->decide(new CapabilityContext(), fn ($id) => 0);
        $this->assertSame('nagging', $fresh[0]->id);

        // Three overrides halve it — the quieter card now leads.
        $decayed = $engine->decide(new CapabilityContext(), fn ($id) => $id === 'nagging' ? 3 : 0);
        $this->assertSame('quiet', $decayed[0]->id);

        // Six and it stops rendering entirely.
        $suppressed = $engine->decide(new CapabilityContext(), fn ($id) => $id === 'nagging' ? 6 : 0);
        $this->assertNotContains('nagging', array_map(fn ($c) => $c->id, $suppressed));
    }

    /** Confidence drives the verb. The platform may never sound more certain than its evidence. */
    public function test_confidence_sets_the_language_strength(): void
    {
        $strong = $this->card('a', DecisionCard::TIER_ROUTING, n: 100);
        $this->assertSame(Confidence::Strong, $strong->confidence());
        $this->assertSame('must', $strong->strength());

        $thin = new DecisionCard(
            id: 'b', tier: DecisionCard::TIER_ROUTING, audience: [],
            observation: 'o', recommendation: 'r', reasoning: 'w',
            evidence: new Evidence(sampleSize: 4, labelSource: Evidence::LABEL_DERIVED),
        );
        $this->assertSame(Confidence::Limited, $thin->confidence());
        $this->assertSame('consider', $thin->strength());
        $this->assertTrue($thin->toArray()['show_cases_not_statistics']);
    }
}
