<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vehicle depreciation policy (Phase 1 — managerial, not statutory)
    |--------------------------------------------------------------------------
    |
    | FleetView computes book value / depreciation from vehicles.purchase_price
    | and vehicles.purchase_date (the FASTER Asset sheet is their source of
    | truth). OfficeManager's own asset/depreciation fields are ~98% empty, so
    | we do NOT source depreciation from OM — we compute it here from a policy.
    |
    | This is a business-rule config, not data: change the numbers to re-value
    | the whole fleet on the next read. Straight-line is the P1 method; declining
    | balance / units-of-production are future work (kept out on purpose).
    |
    |   method             — only 'straight_line' is implemented in P1.
    |   useful_life_years  — years from purchase to fully depreciated.
    |   residual_pct       — salvage floor as a fraction of purchase price
    |                        (0.20 = book value never drops below 20% of cost).
    |
    | Per-category overrides (keyed by vehicles.category, exact match) let a
    | class of car use a different life/residual; empty = every car uses the
    | defaults. Example:
    |   'categories' => ['Luxury' => ['useful_life_years' => 6, 'residual_pct' => 0.30]],
    |
    */

    'method'            => env('DEPRECIATION_METHOD', 'straight_line'),
    'useful_life_years' => (int) env('DEPRECIATION_USEFUL_LIFE_YEARS', 5),
    'residual_pct'      => (float) env('DEPRECIATION_RESIDUAL_PCT', 0.20),

    'categories' => [
        // 'Luxury' => ['useful_life_years' => 6, 'residual_pct' => 0.30],
    ],

];
