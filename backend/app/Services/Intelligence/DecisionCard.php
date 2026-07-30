<?php

namespace App\Services\Intelligence;

/**
 * One recommendation, at one decision, with its working shown.
 *
 * FOUR SECTIONS, ALWAYS, IN ORDER — observation (what happened), evidence (why we believe it),
 * recommendation (what to do), reasoning (why that is right). A card missing any of them is not a
 * card; it is a statistic that has wandered into a workflow.
 *
 * The capability authors the text. It does NOT decide the card's confidence (computed from
 * Evidence), whether it renders (DecisionEngine), where it renders (Operational Intelligence), or
 * in what order (precedence tier + score). That separation is what lets a new capability be added
 * without touching the delivery pipeline.
 */
final readonly class DecisionCard
{
    // Hard precedence tiers. Across tiers, precedence decides; within a tier, score decides.
    public const TIER_SAFETY        = 0;  // never outrankable
    public const TIER_IRREVERSIBLE  = 1;  // money at risk, hard to undo
    public const TIER_REWORK        = 2;  // highest measured ROI in this fleet
    public const TIER_ROUTING       = 3;
    public const TIER_EFFICIENCY    = 4;
    public const TIER_COST          = 5;
    public const TIER_CONTEXT       = 6;  // orientation only

    /**
     * @param string   $id            stable slug, e.g. 'comeback-warning'
     * @param int      $tier          TIER_* — see the constants above
     * @param string[] $audience      permission strings this card is for
     * @param array    $actions       [['label' => 'Start diagnostic reset', 'effect' => 'diagnostic_reset'], …]
     * @param float    $leverage      0..1 how much the decision changes if this card is right
     * @param bool     $actionable    false ⇒ the user cannot act here ⇒ the card is INELIGIBLE at
     *                                this state. This is a gate, not a penalty: it is what stops the
     *                                pipeline silting up into a dashboard.
     * @param int      $minimumSample below this the Decision Engine suppresses the card entirely
     */
    public function __construct(
        public string $id,
        public int $tier,
        public array $audience,
        public string $observation,
        public string $recommendation,
        public string $reasoning,
        public Evidence $evidence,
        public array $actions = [],
        public float $leverage = 0.5,
        public bool $actionable = true,
        public int $minimumSample = 1,
        /**
         * PROVENANCE ONLY — which capability produced this card, carried for audit, feedback and
         * learning. [[CardArbitrator]] never reads it: ranking must depend on the card's generic
         * properties alone, or the pipeline slowly re-acquires per-capability special cases.
         */
        public string $capabilityId = '',
    ) {}

    public function confidence(): Confidence
    {
        return $this->evidence->confidence();
    }

    /**
     * A copy carrying the audience the POLICY declares.
     *
     * Which roles may see a card is an orchestration rule, not an evidential one, so capabilities
     * leave `audience` empty and Operational Intelligence stamps it from the registered policy
     * before arbitration. The arbitrator still filters on the card, exactly as before — it remains
     * generic and unaware that policies exist.
     */
    public function withAudience(array $audience): self
    {
        return new self(
            id: $this->id,
            tier: $this->tier,
            audience: $audience,
            observation: $this->observation,
            recommendation: $this->recommendation,
            reasoning: $this->reasoning,
            evidence: $this->evidence,
            actions: $this->actions,
            leverage: $this->leverage,
            actionable: $this->actionable,
            minimumSample: $this->minimumSample,
            capabilityId: $this->capabilityId,
        );
    }

    /** must | should | consider — derived from confidence, never authored. */
    public function strength(): string
    {
        return $this->confidence()->strength();
    }

    /**
     * Within-tier score. Across tiers this is irrelevant — precedence wins — so a perfectly scored
     * cost card can never displace a rework warning.
     *
     * @param int $recentOverrides same-reason overrides by this actor in the last 30 days
     */
    public function score(int $recentOverrides = 0): float
    {
        if (! $this->actionable) {
            return 0.0;
        }

        // Anti-fatigue: a card overridden three times for the same reason halves in weight.
        $decay = pow(0.5, $recentOverrides / 3);

        return $this->leverage * $this->confidence()->weight() * $decay;
    }

    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'capability_id'  => $this->capabilityId,
            'tier'           => $this->tier,
            'audience'       => $this->audience,
            'observation'    => $this->observation,
            'recommendation' => $this->recommendation,
            'reasoning'      => $this->reasoning,
            'strength'       => $this->strength(),
            'confidence'     => $this->confidence()->value,
            'confidence_label' => $this->confidence()->label(),
            'actions'        => $this->actions,
            'evidence'       => $this->evidence->toArray(),
            // Below the statistics floor the UI must show the cases, not the number.
            'show_cases_not_statistics' => $this->evidence->isTooThinForStatistics(),
        ];
    }
}
