<?php

namespace App\Intelligence;

use App\Kpi\Kpi;

/**
 * Base for everything that turns a MetricContext into one Kpi.
 *
 * The template method is the point. `resolve()` is final: a resolver supplies `compute()` and the
 * base decides what happens when the sample is too small. If each resolver applied the gate itself,
 * one of them would eventually forget — and the metric that forgets is the one that reports 100%
 * first-time-fix over three repairs, which is exactly the failure Kpi was built to prevent.
 *
 * @see MetricRegistry for how resolvers are addressed by code.
 */
abstract class MetricResolver
{
    /** Stable metric code, e.g. `garage.recurrence_days`. Unique across the platform. */
    abstract public function code(): string;

    /** Human label. Rendered through tf() on the frontend; this is the fallback. */
    abstract public function label(): string;

    /**
     * Do the work. Return a Kpi built with the normal factories.
     *
     * A resolver MAY return Kpi::unavailable() when the underlying field cannot support the metric
     * at all (see the approval-status case — a column with zero variance is not a small sample, it
     * is an absent process, and the two must not look alike).
     */
    abstract protected function compute(MetricContext $context): Kpi;

    final public function resolve(MetricContext $context): Kpi
    {
        $kpi = $this->compute($context);

        // An explicit "cannot be computed" is a considered answer — never second-guess it.
        if (! $kpi->available) {
            return $kpi;
        }

        if ($kpi->sampleSize < $context->minSample) {
            return Kpi::insufficient(
                $kpi->key,
                $kpi->label,
                $kpi->sampleSize,
                $context->minSample,
                $kpi->unit,
                $kpi->direction,
            );
        }

        return $kpi;
    }
}
