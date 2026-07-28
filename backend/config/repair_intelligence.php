<?php

/**
 * Repair Intelligence / ETA Prediction — tunables (docs/Repair-Intelligence-Architecture.md).
 *
 * These are decisions D4/A5 made data-config rather than code, so they can be re-tuned from measured
 * prediction error (once the §11 feedback loop exists) WITHOUT a code change.
 */
return [

    // Cascade minimum sample sizes (D4). A cohort must have at least this many historical repair
    // cycles for the predictor to trust it before falling back to the next, broader level.
    'min_n' => [
        'level1' => 8,   // reason × garage  — chosen so Level-1 fires for the ~46 dense combos measured
        'level2' => 15,  // reason-only or garage-only
        // Level-3 (fleet baseline) has no floor — it is always available as the last resort.
    ],

    // Outlier guard (A5): durations beyond this many CALENDAR days are treated as unclosed-row
    // artifacts, not real repairs, and excluded from every cohort. Mirrors
    // RepairDurationQueryService::MAX_DAYS.
    'max_duration_days' => 120,

    // Confidence banding inputs (§5) — reused by the ConfidenceScorer at prediction time.
    'confidence' => [
        'high_min_n'          => 20,   // Level-1 + n≥this + tight spread → "high"
        // relative dispersion = (p90 - median) / max(median, 1); above this a cohort is "loose"
        'loose_dispersion'    => 3.0,
    ],
];
