<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default repair target (days)
    |--------------------------------------------------------------------------
    |
    | The fleet-wide fallback repair window used by the repair-ETA gauge
    | (App\Models\Maintenance::repairEta()) when a ticket has no explicit
    | `expected_return_date` promise. A car in the shop is then measured against
    | "start + this many days", so the dashboard always shows a live "day N of M"
    | counter instead of a dead "No ETA". A ready-by date set at dispatch always
    | overrides this. Change here (or via the MAINT_DEFAULT_REPAIR_DAYS env var)
    | to retune the whole fleet's default without touching code.
    |
    */

    'default_repair_days' => (int) env('MAINT_DEFAULT_REPAIR_DAYS', 4),

];
