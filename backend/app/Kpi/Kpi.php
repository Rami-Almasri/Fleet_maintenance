<?php

namespace App\Kpi;

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
 */
final class Kpi
{
    public const HIGHER_BETTER = 'higher_better';
    public const LOWER_BETTER  = 'lower_better';
    public const NEUTRAL       = 'neutral';

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?float $value,
        public readonly string $unit,            // percent | days | hours | count | seconds
        public readonly int $sampleSize,
        public readonly string $direction,
        public readonly bool $available,
        public readonly ?string $blockedReason = null,
        public readonly array $context = [],
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
    ): self {
        return new self($key, $label, $value, $unit, $sampleSize, $direction, true, null, $context);
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
        ];
    }
}
