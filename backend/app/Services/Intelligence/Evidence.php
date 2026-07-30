<?php

namespace App\Services\Intelligence;

use Carbon\CarbonInterface;

/**
 * What a capability found in history, and how much it is worth.
 *
 * This is the ONLY thing a capability is asked to produce. Everything downstream — confidence,
 * language, ranking, suppression, whether the card renders at all — is derived from it by the
 * shared pipeline. A capability answers "what does history tell us?" and nothing else.
 *
 * `sourceIds` are the actual rows behind the claim, so every card can be drilled into. A card that
 * cannot name its sources cannot be audited, and an unauditable recommendation is one nobody will
 * trust the second time it is wrong.
 */
final readonly class Evidence
{
    /**
     * The SHAPE of the evidence block, and of the confidence rules applied to it.
     *
     * Bump it when a field is added or removed, or when a band threshold moves — the stored evidence
     * json on old recommendations is then still readable, because it says which rules produced it.
     * Without this, changing the "strong" threshold from 30 to 50 would silently reinterpret every
     * recommendation the platform has ever made.
     *
     * v2 — `facts` added to toArray(). v1 rows are still readable; they simply have no facts block,
     * and their stamp says so. This is the versioning mechanism doing exactly its job on the first
     * real schema change, rather than the change being made silently.
     */
    public const SCHEMA_VERSION = 'v2';

    public const LABEL_HUMAN   = 'human';
    public const LABEL_DERIVED = 'derived';
    public const LABEL_MIXED   = 'mixed';

    /**
     * @param int          $sampleSize  how many historical cases back the claim
     * @param array<int>   $sourceIds   the rows themselves — drill-through and audit
     * @param string       $labelSource human | derived | mixed
     * @param string|null  $costTier    A (garage+date matched) | B (date proximate) | C (vehicle-level)
     * @param float|null   $coverage    0..1 share of the relevant population this rests on
     * @param bool         $isProxy     true when the metric stands in for something not measured
     * @param string|null  $proxyNote   what the proxy actually is — rendered inline, always
     * @param array        $facts       capability-specific payload, consumed only by its own card
     */
    public function __construct(
        public int $sampleSize,
        public array $sourceIds = [],
        public string $labelSource = self::LABEL_DERIVED,
        public ?string $costTier = null,
        public ?float $coverage = null,
        public bool $isProxy = false,
        public ?string $proxyNote = null,
        public array $facts = [],
        public ?CarbonInterface $asOf = null,
    ) {}

    /**
     * The confidence band, taken as the WEAKEST of every applicable input.
     *
     * A large sample of derived labels linked at Tier C is not strong evidence, and must not be
     * allowed to present as such just because one of its inputs is impressive.
     */
    public function confidence(): Confidence
    {
        $bands = [$this->bandForSample()];

        $bands[] = match ($this->labelSource) {
            self::LABEL_HUMAN   => Confidence::Strong,
            self::LABEL_MIXED   => Confidence::Moderate,
            default             => Confidence::Moderate, // derived labels cap the card at moderate
        };

        if ($this->costTier !== null) {
            $bands[] = match ($this->costTier) {
                'A'     => Confidence::Strong,
                'B'     => Confidence::Moderate,
                default => Confidence::Limited,
            };
        }

        if ($this->coverage !== null) {
            $bands[] = match (true) {
                $this->coverage >= 0.70 => Confidence::Strong,
                $this->coverage >= 0.40 => Confidence::Moderate,
                default                 => Confidence::Limited,
            };
        }

        // A proxy can never be strong. Return rate is not verified success, and saying so is the
        // difference between a usable signal and an accusation.
        if ($this->isProxy) {
            $bands[] = Confidence::Moderate;
        }

        // Stale knowledge degrades: the projection is rebuilt, the world moves.
        if ($this->asOf !== null && $this->asOf->diffInDays(now()) > 30) {
            $bands[] = Confidence::Moderate;
        }

        return Confidence::weakest(...$bands);
    }

    private function bandForSample(): Confidence
    {
        return match (true) {
            $this->sampleSize >= 30 => Confidence::Strong,
            $this->sampleSize >= 8  => Confidence::Moderate,
            default                 => Confidence::Limited,
        };
    }

    /**
     * Below this floor the pipeline shows the individual cases instead of a statistic. A "median
     * cost" backed by four observations is the fastest way to lose the room.
     */
    public function isTooThinForStatistics(): bool
    {
        return $this->sampleSize < 8;
    }

    public function toArray(): array
    {
        return [
            'sample_size'  => $this->sampleSize,
            'source_ids'   => $this->sourceIds,
            'label_source' => $this->labelSource,
            'cost_tier'    => $this->costTier,
            'coverage'     => $this->coverage,
            'is_proxy'     => $this->isProxy,
            'proxy_note'   => $this->proxyNote,
            'confidence'   => $this->confidence()->value,
            'as_of'        => $this->asOf?->toIso8601String(),
            // THE SPECIFICS — which signature, which prior cases, what rate. Omitting these was a
            // real bug: a frozen recommendation could say "this is a comeback" without recording
            // WHAT of, so ninety days later nothing could check whether it came true. A card that
            // cannot be judged later cannot teach the platform anything.
            'facts'        => $this->facts,
        ];
    }
}
