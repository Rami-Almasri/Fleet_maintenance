<?php

namespace App\Kpi;

use App\Intelligence\Coverage;
use DateTimeInterface;

/**
 * One operational measurement, with everything needed to judge whether to believe it.
 *
 * A KPI reported as a bare number is not usable for deciding anything. "First-time fix is 59.6%"
 * means one thing over 31,782 repairs and nothing at all over 24 — and the second case is the norm
 * in a platform whose workflow engine is newer than its data. So sample size travels with the value,
 * and a metric that cannot yet be computed says WHY rather than returning zero.
 *
 * Returning 0 for "not measurable" is the specific failure this guards against: a dashboard showing
 * 0% capture-abandonment looks like a triumph and is actually an unwired frontend.
 *
 * ── FLEET INTELLIGENCE EXTENSION (2026-08) ───────────────────────────────────────────────────────
 * Four fields were added for the Fleet Intelligence platform, ALL with defaults, and four keys were
 * appended to toArray(). Nothing existing changed: every prior caller, and every consumer reading the
 * original nine keys, behaves exactly as before. The additions answer questions a bare value cannot:
 *
 *   coverage        — how much of the underlying population this number actually saw
 *   asOf            — how fresh the data behind it is (the expense ledger stops at 2026-03-31)
 *   confidence      — verified | partial | estimated, derived centrally, never hand-set
 *   evidenceQueryId — the drill-down that shows the rows behind the claim
 *
 * Sample size answers "is this enough?". Coverage answers "enough OF WHAT?" — a distinction that
 * matters here because several metrics are computed over a quarter of the corpus (only 25.7% of
 * tickets ever record a return date) and would otherwise read as fleet-wide truth.
 */
final class Kpi
{
    public const HIGHER_BETTER = 'higher_better';
    public const LOWER_BETTER  = 'lower_better';
    public const NEUTRAL       = 'neutral';

    /** Coverage is complete and the data is current — show the number plainly. */
    public const CONFIDENCE_VERIFIED = 'verified';
    /** Computed over a subset, or over data that has stopped being updated. */
    public const CONFIDENCE_PARTIAL = 'partial';
    /** Derived through a proxy (category averages, mapped classes) rather than measured directly. */
    public const CONFIDENCE_ESTIMATED = 'estimated';

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?float $value,
        public readonly string $unit,            // percent | days | hours | count | seconds | currency
        public readonly int $sampleSize,
        public readonly string $direction,
        public readonly bool $available,
        public readonly ?string $blockedReason = null,
        public readonly array $context = [],
        public readonly ?Coverage $coverage = null,
        public readonly ?DateTimeInterface $asOf = null,
        public readonly string $confidence = self::CONFIDENCE_VERIFIED,
        public readonly ?string $evidenceQueryId = null,
    ) {
    }

    public static function measured(
        string $key,
        string $label,
        float $value,
        string $unit,
        int $sampleSize,
        string $direction = self::HIGHER_BETTER,
        array $context = [],
        ?Coverage $coverage = null,
        ?DateTimeInterface $asOf = null,
        ?string $evidenceQueryId = null,
    ): self {
        return new self(
            $key, $label, $value, $unit, $sampleSize, $direction, true, null, $context,
            $coverage, $asOf, self::confidenceFor($coverage, $asOf), $evidenceQueryId,
        );
    }

    /**
     * A number reached through a proxy rather than measured directly.
     *
     * G17 "cost of rework" is the motivating case: it multiplies recurrence counts by fleet-average
     * category costs, because expenses carry no garage. That is a legitimate and useful figure, and
     * it is NOT the same kind of fact as "this garage was paid AED N". Making it a distinct
     * constructor stops "estimated" from being a word someone remembers to put in a label.
     */
    public static function estimated(
        string $key,
        string $label,
        float $value,
        string $unit,
        int $sampleSize,
        string $direction = self::HIGHER_BETTER,
        array $context = [],
        ?Coverage $coverage = null,
        ?DateTimeInterface $asOf = null,
        ?string $evidenceQueryId = null,
    ): self {
        return new self(
            $key, $label, $value, $unit, $sampleSize, $direction, true, null, $context,
            $coverage, $asOf, self::CONFIDENCE_ESTIMATED, $evidenceQueryId,
        );
    }

    /**
     * A KPI we have agreed to track but cannot compute yet.
     *
     * Kept in the list rather than omitted, because a metric that silently disappears is one nobody
     * remembers is missing — and the reason string is the actual work item.
     */
    public static function unavailable(string $key, string $label, string $reason, string $unit = 'percent', string $direction = self::HIGHER_BETTER): self
    {
        return new self($key, $label, null, $unit, 0, $direction, false, $reason);
    }

    /** Too little data to mean anything, even though the query ran. */
    public static function insufficient(string $key, string $label, int $sampleSize, int $required, string $unit = 'percent', string $direction = self::HIGHER_BETTER): self
    {
        return new self(
            $key, $label, null, $unit, $sampleSize, $direction, false,
            "Only {$sampleSize} record(s); at least {$required} needed before this number means anything.",
        );
    }

    /**
     * Confidence is DERIVED, never passed in.
     *
     * Leaving it to the caller would make it a label rather than a property: whoever wrote the
     * resolver would decide how confident to look. The rules live here so every metric in the
     * platform is graded the same way, and so "no number without its confidence" is structural
     * rather than a convention people remember.
     */
    private static function confidenceFor(?Coverage $coverage, ?DateTimeInterface $asOf): string
    {
        if ($coverage !== null && $coverage->percent() < 90.0) {
            return self::CONFIDENCE_PARTIAL;
        }

        // Data that stopped being updated is not "verified" however complete the historical rows are.
        if ($asOf !== null && $asOf->diff(new \DateTimeImmutable())->days > 60) {
            return self::CONFIDENCE_PARTIAL;
        }

        return self::CONFIDENCE_VERIFIED;
    }

    public function toArray(): array
    {
        return [
            'key'            => $this->key,
            'label'          => $this->label,
            'value'          => $this->value === null ? null : round($this->value, 2),
            'unit'           => $this->unit,
            'sample_size'    => $this->sampleSize,
            'direction'      => $this->direction,
            'available'      => $this->available,
            'blocked_reason' => $this->blockedReason,
            'context'        => $this->context,

            // --- Fleet Intelligence additions (2026-08). Appended, never replacing the above. ---
            'coverage'          => $this->coverage?->toArray(),
            'as_of'             => $this->asOf?->format('Y-m-d'),
            'confidence'        => $this->confidence,
            'evidence_query_id' => $this->evidenceQueryId,
        ];
    }
}
