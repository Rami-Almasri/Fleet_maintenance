<?php

/*
|--------------------------------------------------------------------------
| Garage Scorecard
|--------------------------------------------------------------------------
| Tuning for App\Services\Garage\GarageScorecardService — the per-garage,
| per-repair-domain report behind /garages ("who is good at what, and where
| does this garage have a problem?").
|
| This is a REPORTING surface, not a scoring input. The recommendation engine
| (config/garage_recommendation.php) still decides where a car goes; nothing
| here feeds it. The two read the same corpus and the same comeback window on
| purpose, so a garage's number cannot mean one thing on this page and another
| on the assign step.
|
| ⚠️ Data reality at the time of writing — re-measure before changing a floor:
|   attributable repairs   ~37,000 non-exposure signature events with a vendor
|   graded (garage,domain) 296 pairs at n ≥ 20, across 44 garages
|   turnaround             out_date → actual_in_date; workflow timestamps still
|                          cover too few tickets to segment by garage
|   on-time                DELIBERATELY ABSENT from the score. Its only source
|                          (expected_return_date) equals actual_in_date in ~89%
|                          of rows, so an "on-time rate" built on it is ~94% by
|                          construction and grades nobody.
*/

return [

    // The whole report is one expensive pass (a correlated subquery over ~37k
    // signature rows). It changes on the timescale of repairs, not requests.
    'cache_ttl' => 900,

    /*
    | The window that defines a comeback. MUST stay equal to
    | App\Kpi\OperationalKpiService::COMEBACK_WINDOW_DAYS and to
    | garage_recommendation.outcomes.comeback_window_days — three surfaces
    | publishing three different "comeback rates" is the failure mode.
    */
    'comeback_window_days' => 90,

    // Spans beyond this are data-entry errors, not long repairs. Dropped rather
    // than winsorised: averaging them in moves the number without informing anyone.
    'duration_outlier_days' => 60,

    /*
    | Sample floors. Below these a figure is an anecdote, and this page says
    | "not enough repairs" instead of printing a percentage that grades a garage
    | on four jobs.
    */
    'min_n' => [
        'domain'   => 20,   // before a (garage, domain) cell is graded or ranked
        'garage'   => 30,   // before a garage receives an overall score
        'duration' => 10,   // before a garage's turnaround is compared to the fleet
    ],

    /*
    | How far from the fleet a garage must sit before we call it a strength or a
    | problem, in percentage points of comeback rate. Inside this band the honest
    | word is "on par" — naming a 2-point difference as a weakness trains people
    | to ignore the page.
    */
    'material_pts' => 8,

    // Composite weights. Only MEASURED axes are blended and the weights
    // renormalise over them, so the score strengthens as data accrues without a
    // rewrite — and a garage is never deflated by a measure we never took.
    'weights' => [
        'reliability' => 0.65,   // do its repairs hold (comeback, case-mix adjusted)
        'speed'       => 0.35,   // how fast the car comes back to the road
    ],

    // Sample-size bands behind the overall score.
    'confidence' => [
        'high_min'   => 200,
        'medium_min' => 60,
    ],

    // How many rows the per-domain leaderboards carry ("best garage for tyres").
    'leaderboard_limit' => 5,

    // Strength / weakness lists on a garage card.
    'highlight_limit' => 3,
];
