<?php

namespace App\Intelligence\Recurrence;

use App\Intelligence\Coverage;
use DateTimeInterface;

/**
 * One recurrence measurement — what happened, never what it means.
 *
 * ── THE BOUNDARY THIS OBJECT ENFORCES ────────────────────────────────────────────────────────────
 * The repository answers "what happened?"; the domain services answer "what does it mean?". This
 * object is the shape of the first answer, and it deliberately carries NO score, NO grade, NO
 * case-mix expectation and NO verdict. A garage's expected rate depends on its work mix, which is a
 * judgement about garages; putting it here would drag that judgement into the measurement layer and
 * every future consumer would inherit it whether or not it applied to them.
 *
 * What it does carry is everything needed to decide whether to believe the number: the sample, the
 * coverage, and how fresh the corpus was.
 *
 * `rate()` returns null rather than 0.0 for an empty sample. A comeback rate of zero over no repairs
 * is the most flattering possible lie about a garage.
 */
final class RecurrenceStats
{
    public function __construct(
        /** Fully-observed events in scope — the denominator. */
        public readonly int $n,
        /** Of those, how many saw the same fault again inside the window. */
        public readonly int $returned,
        /** Events that made it through the window without the fault returning. */
        public readonly int $held,
        /** Came back within 30 days. */
        public readonly int $back30,
        /** Came back between 31 and 90 days. */
        public readonly int $back90,
        /**
         * MEAN days to return, over the ones that returned inside the window. Null when none did.
         *
         * Deliberately the mean and deliberately named so: it is what a single grouped aggregate can
         * produce in one pass. Time-to-return is right-skewed, so anything reported to a user should
         * be the MEDIAN — ask RecurrenceRepository::medianGap() for the grain you actually want.
         * This field exists for cheap internal comparison, never for display.
         */
        public readonly ?float $meanGapDays,
        public readonly Coverage $coverage,
        public readonly ?DateTimeInterface $asOf,
        public readonly RecurrenceWindow $window,
    ) {
    }

    /** An in-scope group with no fully-observed events — measured, and empty. Not zero. */
    public static function empty(RecurrenceWindow $window, Coverage $coverage, ?DateTimeInterface $asOf = null): self
    {
        return new self(0, 0, 0, 0, 0, null, $coverage, $asOf, $window);
    }

    /**
     * The comeback rate, as a percentage.
     *
     * NULL on an empty sample — never 0.0. Callers gate on their own floor before showing it; the
     * measurement layer does not decide what is "enough", it only reports what there was.
     */
    public function rate(): ?float
    {
        return $this->n > 0 ? round($this->returned / $this->n * 100, 2) : null;
    }

    /** The complement — the first-time-fix proxy. Same null rule. */
    public function heldRate(): ?float
    {
        return $this->n > 0 ? round($this->held / $this->n * 100, 2) : null;
    }

    public function meetsFloor(int $minSample): bool
    {
        return $this->n >= $minSample;
    }

    /** Fold another group in — used to roll domains up into a garage, or garages into the fleet. */
    public function merge(self $other): self
    {
        return new self(
            n:             $this->n + $other->n,
            returned:      $this->returned + $other->returned,
            held:          $this->held + $other->held,
            back30:        $this->back30 + $other->back30,
            back90:        $this->back90 + $other->back90,
            // Medians do not sum. A rolled-up group reports no median rather than a wrong one;
            // whoever needs it asks the repository for the level they actually want.
            meanGapDays: null,
            coverage:      new Coverage(
                $this->coverage->covered + $other->coverage->covered,
                $this->coverage->total + $other->coverage->total,
                $this->coverage->asOf ?? $other->coverage->asOf,
            ),
            asOf:          $this->asOf ?? $other->asOf,
            window:        $this->window,
        );
    }

    public function toArray(): array
    {
        return [
            'n'               => $this->n,
            'returned'        => $this->returned,
            'held'            => $this->held,
            'back_30'         => $this->back30,
            'back_90'         => $this->back90,
            'rate_pct'        => $this->rate(),
            'held_pct'        => $this->heldRate(),
            'mean_gap_days'   => $this->meanGapDays,
            'coverage'        => $this->coverage->toArray(),
            'as_of'           => $this->asOf?->format('Y-m-d'),
            'window'          => $this->window->toArray(),
        ];
    }
}
