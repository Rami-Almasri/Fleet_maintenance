<?php

namespace App\Services\RepairIntelligence\Query;

use App\Services\Intelligence\Confidence;
use App\Services\Intelligence\Evidence;
use Carbon\CarbonInterface;

/**
 * The answer to a historical question — data AND its own trustworthiness, together.
 *
 * Every method on [[RepairHistoryQuery]] returns one of these rather than a bare array or
 * collection, and that is the whole point. If queries returned raw rows, each capability would have
 * to work out for itself how many cases were behind them, how they were linked, whether the labels
 * were human or derived and whether the metric is a proxy — and five capabilities would arrive at
 * five different answers to the same question about the same data.
 *
 * Here the query layer states it once, because it is the only layer that actually knows. The
 * capability then calls evidence() and is done; it never assembles an Evidence object by hand and
 * therefore cannot understate or overstate what history supports.
 *
 * `value` is deliberately untyped: each query defines its own shape and its own reader. The
 * metadata around it is identical for every query, which is what lets the pipeline treat answers
 * from Comeback, Garage Recommendation or Cost Intelligence as the same kind of thing.
 */
final readonly class HistoricalAnswer
{
    /** How the underlying facts were reconstructed. Mirrors the tiering in the cost-join study. */
    public const TIER_DIRECT     = 'A'; // recorded as-is on the ticket — no reconstruction
    public const TIER_MATCHED    = 'B'; // joined across sources on a strong key (garage + date window)
    public const TIER_INFERRED   = 'C'; // vehicle-level attribution only

    /**
     * @param mixed                $value             the payload, shaped by the query that produced it
     * @param int                  $sampleSize        historical cases actually behind the answer
     * @param array<int>           $sourceIds         the rows themselves, for drill-through and audit
     * @param string               $labelSource       Evidence::LABEL_* — human, derived or mixed
     * @param string|null          $reconstructionTier A | B | C
     * @param float|null           $completeness      0..1 share of the relevant population covered
     * @param bool                 $isProxy           the metric stands in for something not measured
     * @param string|null          $proxyNote         what it actually measures — always rendered inline
     * @param array                $facts             query-specific detail for the card's wording
     * @param CarbonInterface|null $asOf              when the underlying data was current
     */
    public function __construct(
        public mixed $value,
        public int $sampleSize = 0,
        public array $sourceIds = [],
        public string $labelSource = Evidence::LABEL_DERIVED,
        public ?string $reconstructionTier = null,
        public ?float $completeness = null,
        public bool $isProxy = false,
        public ?string $proxyNote = null,
        public array $facts = [],
        public ?CarbonInterface $asOf = null,
    ) {}

    /** Nothing in history matched. The overwhelmingly common case, and never an error. */
    public static function empty(mixed $value = null): self
    {
        return new self(value: $value ?? [], sampleSize: 0, asOf: now());
    }

    public function isEmpty(): bool
    {
        return $this->sampleSize === 0
            || $this->value === null
            || $this->value === []
            || (is_countable($this->value) && count($this->value) === 0);
    }

    /**
     * Hand the pipeline what it needs to grade this answer.
     *
     * `$facts` are merged over the query's own so a capability can add wording detail without being
     * able to quietly restate the metadata — the trust inputs come from the query, always.
     *
     * `$sourceIds` are merged too, for the case where a card is built on one answer's statistics but
     * should drill through to another's rows. Those ids are an audit trail, not a trust input: they
     * name the evidence, they do not grade it.
     */
    public function evidence(array $facts = [], array $sourceIds = []): Evidence
    {
        return new Evidence(
            sampleSize: $this->sampleSize,
            sourceIds: array_values(array_unique([...$this->sourceIds, ...$sourceIds])),
            labelSource: $this->labelSource,
            costTier: $this->reconstructionTier,
            coverage: $this->completeness,
            isProxy: $this->isProxy,
            proxyNote: $this->proxyNote,
            facts: array_merge($this->facts, $facts),
            asOf: $this->asOf ?? now(),
        );
    }

    /** The band this answer alone supports, before a capability combines it with anything else. */
    public function confidence(): Confidence
    {
        return $this->evidence()->confidence();
    }

    /**
     * Fold several answers into one confidence — the weakest input wins.
     *
     * A capability that quotes a precise vehicle history alongside a thin fleet base rate is only as
     * trustworthy as the base rate, and must not be allowed to sound otherwise.
     */
    public static function weakestOf(self ...$answers): Confidence
    {
        return Confidence::weakest(...array_map(fn (self $a) => $a->confidence(), $answers));
    }
}
