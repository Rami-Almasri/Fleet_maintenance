<?php

namespace App\Services\Intelligence;

use Illuminate\Support\Facades\Log;

/**
 * Chooses what is shown, knowing NOTHING about where the cards came from.
 *
 * This class cannot see a capability. It receives a flat list of Decision Cards and evaluates only
 * their generic properties — priority, confidence, actionability, evidence quality, suppression,
 * budget, audience, workflow state. Whether a card was produced by Comeback Detection, Garage
 * Recommendation, Procurement Intelligence or something not yet written is, by construction,
 * unavailable to it.
 *
 * That is deliberate and it is enforced by the type signature: arbitrate() takes DecisionCard[] and
 * no capability, registry or container. A future capability changes nothing here — which is the
 * entire point of the split.
 *
 *   Capabilities produce evidence · the Arbitrator decides · Operational Intelligence delivers.
 *
 * The card's `capabilityId` travels through as PROVENANCE for audit and learning. Reading it to
 * make a ranking decision would re-introduce the coupling this class exists to remove.
 */
class CardArbitrator
{
    public const BUDGET_PRIMARY = 1;
    public const BUDGET_SECONDARY = 2;
    public const BUDGET_TOTAL = self::BUDGET_PRIMARY + self::BUDGET_SECONDARY;

    /** Same-reason overrides in 30 days at which a card stops rendering and is flagged for review. */
    public const FATIGUE_SUPPRESS_AT = 6;

    /**
     * @param  DecisionCard[] $cards
     * @param  string[]       $actorPermissions permissions held by the person at this decision
     * @param  callable|null  $overrideCounter  fn(string $cardId): int — same-reason overrides, 30d
     * @return DecisionCard[] ordered, budgeted; primary first
     */
    public function arbitrate(array $cards, array $actorPermissions = [], ?callable $overrideCounter = null): array
    {
        $eligible = array_values(array_filter(
            $cards,
            fn (DecisionCard $card) => $this->isEligible($card, $actorPermissions, $overrideCounter)
        ));

        usort($eligible, function (DecisionCard $a, DecisionCard $b) use ($overrideCounter) {
            // PRECEDENCE FIRST — across tiers, score is irrelevant. A perfectly-scored cost card can
            // never displace a rework warning.
            if ($a->tier !== $b->tier) {
                return $a->tier <=> $b->tier;
            }

            return $b->score($this->overridesFor($b, $overrideCounter))
                <=> $a->score($this->overridesFor($a, $overrideCounter));
        });

        return array_slice($eligible, 0, self::BUDGET_TOTAL);
    }

    private function isEligible(DecisionCard $card, array $actorPermissions, ?callable $overrideCounter): bool
    {
        // THE ANTI-DASHBOARD GATE. If the user cannot act on it here, it does not render here.
        if (! $card->actionable) {
            return false;
        }

        // Evidence floor — below this the card has nothing defensible to say.
        if ($card->evidence->sampleSize < $card->minimumSample) {
            return false;
        }

        // Audience. An empty audience means "anyone at this decision"; otherwise the actor must hold
        // at least one of the listed permissions. Showing a supervisor's card to a driver is not a
        // harmless extra — it is a recommendation to someone who cannot act on it.
        if ($card->audience !== [] && $actorPermissions !== []
            && array_intersect($card->audience, $actorPermissions) === []) {
            return false;
        }

        // Anti-fatigue suppression. A card that is always dismissed is either wrong or badly timed,
        // and the platform should discover that about itself rather than wait to be told.
        $overrides = $this->overridesFor($card, $overrideCounter);
        if ($overrides >= self::FATIGUE_SUPPRESS_AT) {
            Log::info('Decision card suppressed by anti-fatigue — calibration review due', [
                'card' => $card->id, 'overrides_30d' => $overrides,
            ]);

            return false;
        }

        return true;
    }

    private function overridesFor(DecisionCard $card, ?callable $overrideCounter): int
    {
        return $overrideCounter ? (int) $overrideCounter($card->id) : 0;
    }
}
