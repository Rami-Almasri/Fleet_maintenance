<?php

namespace Tests\Unit;

use App\Services\Intelligence\CardArbitrator;
use App\Services\Intelligence\DecisionCard;
use App\Services\Intelligence\Evidence;
use Tests\TestCase;

/**
 * The arbitrator's contract — and the proof that it is capability-agnostic.
 *
 * These tests never construct a capability, a registry or a container. They hand the arbitrator a
 * flat list of cards, which is the only thing it is ever given in production either. If a future
 * change made ranking depend on where a card came from, this file could not be written.
 */
class CardArbitratorTest extends TestCase
{
    private CardArbitrator $arbitrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arbitrator = new CardArbitrator();
    }

    private function card(
        string $id,
        int $tier,
        float $leverage = 0.5,
        int $n = 50,
        bool $actionable = true,
        int $minimumSample = 1,
        array $audience = [],
        string $capabilityId = 'some-capability',
    ): DecisionCard {
        return new DecisionCard(
            id: $id,
            tier: $tier,
            audience: $audience,
            observation: 'o',
            recommendation: 'r',
            reasoning: 'w',
            evidence: new Evidence(sampleSize: $n, labelSource: Evidence::LABEL_HUMAN),
            leverage: $leverage,
            actionable: $actionable,
            minimumSample: $minimumSample,
            capabilityId: $capabilityId,
        );
    }

    /** @param DecisionCard[] $cards @return string[] */
    private function ids(array $cards): array
    {
        return array_map(fn (DecisionCard $c) => $c->id, $cards);
    }

    /**
     * THE AGNOSTICISM TEST. Two identical cards from wildly different capabilities must rank purely
     * on their generic properties. Provenance travels with the card for audit; it must not touch
     * the ordering.
     */
    public function test_provenance_does_not_influence_ranking(): void
    {
        $a = $this->card('a', DecisionCard::TIER_ROUTING, leverage: 0.9, capabilityId: 'comeback-warning');
        $b = $this->card('b', DecisionCard::TIER_ROUTING, leverage: 0.4, capabilityId: 'garage-recommendation');

        $this->assertSame(['a', 'b'], $this->ids($this->arbitrator->arbitrate([$a, $b])));

        // Swap only the capability ids. The order must be unchanged.
        $a2 = $this->card('a', DecisionCard::TIER_ROUTING, leverage: 0.9, capabilityId: 'garage-recommendation');
        $b2 = $this->card('b', DecisionCard::TIER_ROUTING, leverage: 0.4, capabilityId: 'comeback-warning');

        $this->assertSame(['a', 'b'], $this->ids($this->arbitrator->arbitrate([$a2, $b2])));
    }

    public function test_precedence_beats_score_across_tiers(): void
    {
        $cards = [
            $this->card('cost', DecisionCard::TIER_COST, leverage: 1.0),
            $this->card('safety', DecisionCard::TIER_SAFETY, leverage: 0.05),
        ];

        $this->assertSame('safety', $this->ids($this->arbitrator->arbitrate($cards))[0]);
    }

    public function test_budget_is_absolute(): void
    {
        $cards = array_map(fn ($i) => $this->card("c$i", DecisionCard::TIER_ROUTING), range(1, 9));

        $this->assertCount(CardArbitrator::BUDGET_TOTAL, $this->arbitrator->arbitrate($cards));
    }

    /**
     * AUDIENCE. Showing a supervisor's card to a driver is not a harmless extra — it is a
     * recommendation aimed at someone who cannot act on it.
     */
    public function test_cards_outside_the_actors_audience_are_withheld(): void
    {
        $cards = [
            $this->card('supervisor-only', DecisionCard::TIER_ROUTING, audience: ['maintenance.delegate']),
            $this->card('everyone', DecisionCard::TIER_ROUTING, audience: []),
        ];

        $asDriver = $this->arbitrator->arbitrate($cards, ['maintenance.logistics']);
        $this->assertSame(['everyone'], $this->ids($asDriver));

        $asSupervisor = $this->arbitrator->arbitrate($cards, ['maintenance.delegate']);
        $this->assertContains('supervisor-only', $this->ids($asSupervisor));
    }

    public function test_unknown_actor_permissions_do_not_filter(): void
    {
        // No permissions supplied (e.g. a system context) ⇒ audience is not enforced.
        $cards = [$this->card('x', DecisionCard::TIER_ROUTING, audience: ['maintenance.delegate'])];

        $this->assertCount(1, $this->arbitrator->arbitrate($cards, []));
    }

    public function test_non_actionable_cards_are_ineligible(): void
    {
        $cards = [$this->card('fyi', DecisionCard::TIER_SAFETY, leverage: 1.0, actionable: false)];

        $this->assertSame([], $this->arbitrator->arbitrate($cards));
    }

    public function test_cards_below_their_evidence_floor_are_suppressed(): void
    {
        $cards = [$this->card('thin', DecisionCard::TIER_ROUTING, n: 5, minimumSample: 30)];

        $this->assertSame([], $this->arbitrator->arbitrate($cards));
    }

    public function test_anti_fatigue_decays_then_suppresses(): void
    {
        $cards = [
            $this->card('nagging', DecisionCard::TIER_ROUTING, leverage: 0.9),
            $this->card('quiet', DecisionCard::TIER_ROUTING, leverage: 0.6),
        ];

        $this->assertSame('nagging', $this->ids($this->arbitrator->arbitrate($cards, [], fn () => 0))[0]);

        $decayed = $this->arbitrator->arbitrate($cards, [], fn ($id) => $id === 'nagging' ? 3 : 0);
        $this->assertSame('quiet', $this->ids($decayed)[0]);

        $suppressed = $this->arbitrator->arbitrate($cards, [], fn ($id) => $id === 'nagging' ? CardArbitrator::FATIGUE_SUPPRESS_AT : 0);
        $this->assertNotContains('nagging', $this->ids($suppressed));
    }

    public function test_an_empty_card_set_is_fine(): void
    {
        $this->assertSame([], $this->arbitrator->arbitrate([]));
    }
}
