<?php

/**
 * Tuning surface for the Fleet Knowledge Engine — P0 ("Previous Similar Repairs + Recommendation
 * Explanation"). See docs/Fleet-Knowledge-Engine-P0-Plan.md and [[fleet-knowledge-engine-arch]].
 *
 * The engine is a READ layer over the maintenance tables (L1). Nothing here persists — these are the
 * knobs the query + confidence services read, so retrieval breadth, the four-tier weighting and the
 * confidence banding can all be re-tuned WITHOUT a migration (same "config is the tuning surface"
 * pattern as config/garage_recommendation.php).
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Retrieval (RepairHistoryQueryService)
    |--------------------------------------------------------------------------
    */
    'retrieval' => [
        // Only look this far back for a comparable repair. Keeps the cohort relevant + the scan bounded.
        'window_days'          => 1095,   // ~3 years
        // Cap the repairs returned PER tier for display (stats still use the full matched cohort).
        'per_tier_limit'       => 10,
        // How many past repairs to surface as the "why" evidence list.
        'evidence_limit'       => 6,
        // How many distinct parts to list as "likely to be replaced".
        'expected_parts_limit' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Confidence scoring (ConfidenceScorer) — pure, injected into score()
    |--------------------------------------------------------------------------
    | One 0..1 blend ×100. Every knob the scorer reads lives here so the band thresholds and the
    | "never hide uncertainty" guards are tunable without code changes.
    */
    'confidence' => [
        // Evidence credibility ramp: full credit once the cohort has this many matching repairs
        // (evidence factor = min(1, sqrt(n)/sqrt(this))). Mirrors GarageRecommendationService::cr().
        'credibility_n' => 12,

        // How much each 0..1 signal contributes (weights are normalised by their sum).
        'weights' => [
            'evidence'     => 0.30,   // volume of comparable repairs (√-ramped)
            'tier'         => 0.20,   // how specific the match is (same vehicle … fleet-wide)
            'success'      => 0.25,   // how well those repairs actually held
            'recency'      => 0.10,   // how fresh the newest comparable repair is
            'completeness' => 0.15,   // share of the cohort with cost + duration known
        ],

        // Recurrence drags confidence down: subtract this × the cohort's recurrence rate.
        'recurrence_penalty' => 0.25,

        // Tier specificity factor (1 = same vehicle … 4 = fleet-wide).
        'tier_factor' => [1 => 1.0, 2 => 0.8, 3 => 0.6, 4 => 0.45],

        // Recency decay: full credit if the newest match is within `fresh` days, decaying to 0 by `stale`.
        'recency_fresh_days' => 90,
        'recency_stale_days' => 540,

        // Neutral success factor used when NO repair in the cohort was re-inspected (outcome unknown).
        'success_neutral' => 0.6,

        // Banding thresholds. A band needs BOTH its score floor and its sample floor.
        'high_score' => 70, 'high_n' => 12,
        'med_score'  => 45, 'med_n'  => 5,

        // Uncertainty guards (the "never hide uncertainty" rules):
        //  - below this completeness, a high band is capped to medium (+ a reason).
        'min_completeness' => 0.5,
        //  - a cohort matched ONLY fleet-wide (best tier 4) can never be "high".
        //  - fewer than med_n repairs forces "low" regardless of score.
    ],
];
