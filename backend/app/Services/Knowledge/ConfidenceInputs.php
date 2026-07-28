<?php

namespace App\Services\Knowledge;

/**
 * The EXACT, explicit inputs the ConfidenceScorer reasons over. A plain value object (no DB, no time
 * calls) so the scorer stays pure and unit-testable — the caller is responsible for deriving these from
 * the matched cohort (e.g. `daysSinceNewest` is computed with Carbon by the service, never inside the
 * scorer).
 *
 * These map 1:1 to the signals the product brief asked confidence to consider:
 *   sample_size · match_tier · repair_outcome · inspection_result · recency · recurrence · completeness.
 */
final class ConfidenceInputs
{
    public function __construct(
        // How many comparable past repairs backed the answer.
        public readonly int $sampleSize,
        // The STRONGEST tier present in the cohort: 1 same-vehicle · 2 model · 3 make · 4 fleet.
        public readonly int $bestTier,
        // Outcome distribution of those repairs (from repair_inspections / task status):
        public readonly int $verifiedFixed,   // passed post-repair inspection (strongest)
        public readonly int $fixed,            // marked fixed, not independently re-inspected
        public readonly int $failed,           // came back unfixed (still_exists)
        public readonly int $outcomeUnknown,   // completed but never re-inspected
        // 0..1 — share of the cohort that actually recurred after repair.
        public readonly float $recurrenceRate,
        // Age of the NEWEST comparable repair, in days (freshness).
        public readonly ?int $daysSinceNewest,
        // 0..1 — share of the cohort with BOTH cost and duration known (data completeness).
        public readonly float $completeness,
    ) {
    }
}
