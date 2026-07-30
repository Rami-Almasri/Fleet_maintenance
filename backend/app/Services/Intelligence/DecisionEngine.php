<?php

namespace App\Services\Intelligence;

use App\Services\Intelligence\Contracts\IntelligenceCapability;
use Illuminate\Support\Facades\Log;

/**
 * Runs the registered capabilities and hands their cards to the arbitrator.
 *
 * The engine has exactly two jobs — COLLECT and DELEGATE — and they are deliberately separated:
 *
 *   collect()   knows about capabilities, and nothing about ranking.
 *   CardArbitrator knows about ranking, and nothing about capabilities.
 *
 * Because arbitrate() receives a flat DecisionCard[] with no registry, container or capability
 * reference, it is structurally incapable of favouring one source over another. Adding Garage
 * Recommendation, Procurement Intelligence or Repair-vs-Replace changes the list registered in
 * AppServiceProvider and nothing else in this pipeline.
 *
 * A capability that throws is logged and skipped. Intelligence is advisory; it must never break the
 * workflow it advises, and a 500 on the dispatch screen is a worse outcome than a missing card.
 */
class DecisionEngine
{
    /** @deprecated Read CardArbitrator::BUDGET_TOTAL — kept so existing callers/tests don't break. */
    public const BUDGET_TOTAL = CardArbitrator::BUDGET_TOTAL;

    /** @var IntelligenceCapability[] */
    private array $capabilities = [];

    /** @param IntelligenceCapability[] $capabilities */
    public function __construct(
        private readonly CardArbitrator $arbitrator = new CardArbitrator(),
        array $capabilities = [],
    ) {
        foreach ($capabilities as $capability) {
            $this->register($capability);
        }
    }

    public function register(IntelligenceCapability $capability): self
    {
        $this->capabilities[$capability->id()] = $capability;

        return $this;
    }

    /**
     * The full pipeline for one decision: collect → arbitrate.
     *
     * @param  string[]      $actorPermissions
     * @param  callable|null $overrideCounter fn(string $cardId): int
     * @return DecisionCard[]
     */
    public function decide(
        CapabilityContext $context,
        ?callable $overrideCounter = null,
        array $actorPermissions = [],
        ?callable $gate = null,
    ): array {
        return $this->arbitrator->arbitrate(
            $this->collect($context, $gate),
            $actorPermissions,
            $overrideCounter,
        );
    }

    /**
     * Run every applicable capability. Returns unranked, unfiltered cards — the raw evidence set.
     *
     * Exposed separately so the collection step can be tested, logged or replayed without the
     * arbitration, and so a caller that wants "everything we know here" (an audit view, say) can
     * have it without going through the budget.
     *
     * @param  callable|null $gate fn(IntelligenceCapability): bool — an ORCHESTRATION filter applied
     *                             before a capability is run at all. The engine deliberately does not
     *                             know what the caller is gating on (workflow state, cooldown,
     *                             feature flag); it only knows some capabilities are not wanted here.
     * @return DecisionCard[]
     */
    public function collect(CapabilityContext $context, ?callable $gate = null): array
    {
        $cards = [];

        foreach ($this->capabilities as $capability) {
            if ($gate !== null && ! $gate($capability)) {
                continue;
            }

            if (! $capability->appliesTo($context)) {
                continue;
            }

            try {
                $card = $capability->evaluate($context);
            } catch (\Throwable $e) {
                Log::warning('Intelligence capability failed', [
                    'capability' => $capability->id(),
                    'ticket'     => $context->ticketId(),
                    'error'      => $e->getMessage(),
                ]);

                continue;
            }

            if ($card !== null) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /** @return IntelligenceCapability[] */
    public function capabilities(): array
    {
        return $this->capabilities;
    }
}
