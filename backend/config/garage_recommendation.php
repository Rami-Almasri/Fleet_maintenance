<?php

/**
 * Tuning surface for the Garage Recommendation engine (App\Services\GarageRecommendationService).
 *
 * This is the DATA-DRIVEN cousin of config/garage_routing.php. Where the Smart Routing engine scores
 * garages against hand-curated policy rules (garage_routing_rules), this engine learns from the actual
 * maintenance history — which garages have PROVEN experience with a given vehicle model + fault, how
 * CONCENTRATED that work is (a true specialist vs. a busy generalist), and how far that exceeds the
 * fleet average (LIFT). Nobody maintains a rules table; the signal emerges from `maintenances`.
 *
 * Same "config is the tuning surface, no migration to re-tune" pattern as garage_routing.php. Every
 * weight/threshold the scorer reads lives here, so the ranking can be adjusted without touching code.
 * The scoring mirrors the validated prototype: experience (volume) + concentration (share of the
 * garage's own work) + lift, all damped by a credibility ramp so a 2-job shop at "100%" never
 * masquerades as the expert.
 *
 * See [[garage-recommendation-engine]] and the standalone BI prototype it was validated against.
 */

return [

    // How long the derived history dataset is cached (seconds). The engine reads live `maintenances`
    // once, aggregates in memory, and reuses it. Bumped implicitly whenever the cache key vN changes.
    'cache_ttl' => 600,

    /*
    |--------------------------------------------------------------------------
    | Scoring weights & thresholds (the tuning knobs)
    |--------------------------------------------------------------------------
    | A garage's fit for a {model, brand, fault} query is a weighted sum of four terms, each in 0..1:
    |   - model : experience + concentration in that model
    |   - brand : experience + concentration in that brand (selected, or inferred from the model)
    |   - fault : experience + concentration×lift in that fault category
    |   - combo : jobs matching ALL selected criteria at once (the strongest "proven on this" signal)
    | Each term is only added when its dimension is part of the query. `combo` applies once ≥2
    | dimensions are selected. Higher total = stronger recommendation.
    */
    'scoring' => [
        'w_model' => 1.0,
        'w_brand' => 0.9,
        'w_fault' => 1.1,
        'w_combo' => 2.0,

        // Credibility ramp: concentration only earns full credit once the garage has this many matching
        // jobs (score factor = min(1, sqrt(jobs)/sqrt(this))). Stops tiny shops winning on "100%".
        'credibility_jobs' => 8,

        // The PRIMARY (exact-match) list requires at least this many combo-matched jobs; if fewer than two
        // garages clear it, the bar drops to 1 so a thin-but-real answer still shows.
        'min_primary_combo' => 3,
        'primary_limit'     => 4,

        // Lift cap so a rare fault at a small garage can't explode the score.
        'lift_cap' => 3.0,

        // Reason-chip thresholds (when each explanation is allowed to appear).
        'model_focus_min_share'     => 0.5,
        'model_focus_min_jobs'      => 5,
        'brand_conc_min_share'      => 0.6,
        'brand_conc_min_jobs'       => 5,
        'fault_specialist_min_share'=> 0.4,
        'fault_specialist_min_jobs' => 6,
        'fault_specialist_min_lift' => 1.5,
        'proven_combo_min'          => 5,   // "Proven on X + Y" needs this many exact-combo jobs
        'high_experience_min'       => 8,   // "High X experience" needs this many jobs

        // "Also worth considering" — a single-factor specialist must have at least this many jobs to be
        // offered, so the alternative is a real destination, not a fluke.
        'also_consider_min_jobs' => 5,

        // Confidence level shown to the user = how much EVIDENCE backs the recommendation (matching jobs).
        // High = strong history; Medium = some; Low = limited data (trust it less). Tune to taste.
        'confidence_high_matches' => 12,
        'confidence_med_matches'  => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Quality penalty (re-inspection track record) — OFF-by-reliability
    |--------------------------------------------------------------------------
    | A SOFT penalty (never a hard block, matching the Smart Routing strategy) from this garage's failed
    | re-inspections on the SAME fault category, read from maintenance_task_assignments. Per the brief it
    | only kicks in when the data is RELIABLE: the garage must have at least `min_attempts` concluded
    | attempts for that category before any points are docked, so we never punish on one bad data point.
    */
    'quality_penalty' => [
        'enabled'      => true,
        'min_attempts' => 3,      // reliability gate: need ≥ this concluded attempts before penalising
        'penalty_each' => 0.15,   // score subtracted per failed re-inspection (score units)
        'penalty_max'  => 0.60,   // ceiling on the penalty
        'warn_rate'    => 0.40,   // failure-rate above this raises a ⚠️ warning chip (still selectable)
    ],
];
