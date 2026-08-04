<?php

namespace App\Intelligence;

use DateTimeInterface;

/**
 * How much of the underlying population a number actually saw.
 *
 * Sample size and coverage are different questions and the platform needs both. "Average repair
 * duration is 2.5 days over 6,881 repairs" sounds authoritative until you know those 6,881 are the
 * 25.7% of tickets that ever recorded a return date — the other 74% are still open or were never
 * closed, and they are not a random sample of the rest. A number without its coverage invites the
 * reader to assume 100%, which is the one value it is almost never.
 *
 * This object exists so that assumption cannot be made silently: any metric computed over a subset
 * carries the subset's size, and {@see \App\Kpi\Kpi::confidenceFor()} downgrades it automatically.
 */
final class Coverage
{
    public function __construct(
        public readonly int $covered,
        public readonly int $total,
        public readonly ?DateTimeInterface $asOf = null,
        public readonly ?string $note = null,
    ) {
    }

    /**
     * Coverage of a population we know to be complete — e.g. all 438 vehicles.
     *
     * Named rather than inferred from `covered === total` so the intent is explicit at the call site.
     */
    public static function complete(int $total, ?DateTimeInterface $asOf = null): self
    {
        return new self($total, $total, $asOf);
    }

    public function percent(): float
    {
        if ($this->total <= 0) {
            return 0.0;
        }

        return round($this->covered / $this->total * 100, 1);
    }

    /** The rows the metric could NOT see — the number worth showing next to a partial figure. */
    public function missing(): int
    {
        return max(0, $this->total - $this->covered);
    }

    public function isComplete(): bool
    {
        return $this->total > 0 && $this->covered >= $this->total;
    }

    public function toArray(): array
    {
        return [
            'covered' => $this->covered,
            'total'   => $this->total,
            'missing' => $this->missing(),
            'percent' => $this->percent(),
            'as_of'   => $this->asOf?->format('Y-m-d'),
            'note'    => $this->note,
        ];
    }
}
