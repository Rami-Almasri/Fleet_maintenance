<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Garage Intelligence — the two abnormal-behaviour signals
    |--------------------------------------------------------------------------
    |
    | Two questions, asked of one car over one rolling window:
    |
    |   1. Is it going into the garage TOO OFTEN?      (visit frequency)
    |   2. Is it SPENDING TOO LONG in the garage?      (downtime)
    |
    | Both are read from the canonical fleet-utilization engine
    | (App\Services\FleetUtilizationService) — the single definition of a
    | workshop visit and of maintenance time that the Dashboard, the Fleet
    | Utilization page and the car profile already agree on. Nothing here
    | re-counts maintenance rows; see [[maintenance-days-single-source]].
    |
    | Every number below is configurable and read in exactly one place
    | (App\Services\Garage\GarageIntelligenceService) — no threshold is
    | duplicated anywhere else in the codebase.
    |
    */

    // Master switch. Off = the reading is never computed and no alert is raised;
    // nothing else in the maintenance workflow changes.
    'enabled' => (bool) env('GARAGE_INTEL_ENABLED', true),

    /*
    | The rolling monitoring window, in days. Both signals are measured over the
    | SAME window so the two numbers on a car's card are comparable, and so the
    | downtime percentage has an honest denominator.
    */
    'window_days' => (int) env('GARAGE_INTEL_WINDOW_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Visit-frequency thresholds (visits inside the window)
    |--------------------------------------------------------------------------
    |
    | RECALIBRATED 2026-09-02, when the engine started reading the Google Sheet
    | workshop log and the website's tickets alongside the OM contracts. THE
    | NUMBERS BELOW ARE NOT THE ORIGINALLY REQUESTED 3 / 5 / 7, AND THIS IS WHY:
    |
    | 3 / 5 / 7 was calibrated against contracts alone, which saw 108 of the 126
    | cars that had garage activity and — because one contract can cover a dozen
    | separate trips — under-counted the departures of the cars it did see. On the
    | full picture, three garage visits in a month is the 41st percentile: it is
    | what a perfectly ordinary car does. Shipping the old numbers on the new
    | basis would have graded 73 of 178 cars (41%) as needing attention, which is
    | not a warning, it is noise.
    |
    | So the BANDS WERE MOVED TO THE POSITIONS THE ORIGINAL ONES OCCUPIED —
    | roughly the 90th percentile, the top ~5%, and the top ~1%:
    |
    |   MEASURED, live fleet, 178 cars, all three sources, window to 2026-09-02:
    |   mean 2.42 visits · median 2 · 90th percentile 6 · observed maximum 12
    |     >=  4 visits →  50 cars (28.1%)
    |     >=  5 visits →  35 cars (19.7%)
    |     >=  6 visits →  19 cars (10.7%)   ← warning  (the 90th percentile)
    |     >=  8 visits →   8 cars (4.5%)    ← high
    |     >= 10 visits →   2 cars (1.1%)    ← critical (and now REACHABLE, which
    |                                          7 never was on the old basis)
    |
    | To go back to the literal original numbers, set GARAGE_INTEL_VISITS_WARNING=3
    | GARAGE_INTEL_VISITS_HIGH=5 GARAGE_INTEL_VISITS_CRITICAL=7 — no code changes
    | needed. Expect roughly four in ten cars to sit at warning if you do.
    |
    */
    'visits' => [
        'warning'  => (int) env('GARAGE_INTEL_VISITS_WARNING', 6),
        'high'     => (int) env('GARAGE_INTEL_VISITS_HIGH', 8),
        'critical' => (int) env('GARAGE_INTEL_VISITS_CRITICAL', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Downtime thresholds (true off-road shop DAYS inside the window)
    |--------------------------------------------------------------------------
    |
    | RECALIBRATED for the same reason as the visit ladder: the log contributes
    | shop time the contracts never recorded, so every car's figure rose.
    |
    |   MEASURED, live fleet, 178 cars, all three sources, window to 2026-09-02:
    |   mean 2.24 days · median 1.10 · 90th percentile 6.2 · maximum 30.3
    |     >=  3 days → 48 cars (27.0%)   ← the old warning; far too wide now
    |     >=  5 days → 22 cars (12.4%)   ← warning
    |     >= 10 days →  6 cars (3.4%)    ← high
    |     >= 14 days →  2 cars (1.1%)    ← critical (unchanged — it was already
    |                                       in the right place)
    |
    | Only a DURATION with both ends recorded is counted. 9.8% of recent log trips
    | have no logged return; those still count as a visit (the car certainly went)
    | but contribute no time, because running an unreturned row to today is how a
    | one-day oil change once invented 93 days of downtime.
    |
    | Revert with GARAGE_INTEL_DOWNTIME_WARNING=3 GARAGE_INTEL_DOWNTIME_HIGH=7.
    |
    | "Downtime" here is the canonical TRUE off-road figure: time under a
    | maintenance contract that is NOT also under a rental. Rental is King — a
    | day the customer had the car is rental time, never shop time.
    |
    */
    'downtime_days' => [
        'warning'  => (float) env('GARAGE_INTEL_DOWNTIME_WARNING', 5),
        'high'     => (float) env('GARAGE_INTEL_DOWNTIME_HIGH', 10),
        'critical' => (float) env('GARAGE_INTEL_DOWNTIME_CRITICAL', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | WHO is told
    |--------------------------------------------------------------------------
    |
    | Same doctrine as every other recipient list in this application: a named
    | allow-list first, and when it is empty a fallback narrowed by ROLE, never a
    | bare permission. A standing operational alert broadcast to everyone holding
    | a broad permission is how a fleet learns to ignore the bell.
    | See [[checkpoint-reminder-recipient-rules]].
    |
    | This signal is deliberately an ADMIN/management one — it is a question
    | about a car's pattern over a month, not a task for today's shift — so
    | unlike the checkpoint chase, admins are the intended audience here and are
    | NOT excluded.
    |
    */
    'recipients' => [
        'user_ids' => array_values(array_filter(array_map(
            fn ($v) => (int) trim($v),
            explode(',', (string) env('GARAGE_INTEL_USER_IDS', ''))
        ))),
        'fallback_permission' => env('GARAGE_INTEL_PERMISSION', 'maintenance.manage'),
        'fallback_roles'      => array_values(array_filter(array_map(
            fn ($v) => trim($v),
            explode(',', (string) env('GARAGE_INTEL_ROLES', 'super-admin,admin,manager'))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Real-time evaluation
    |--------------------------------------------------------------------------
    |
    | When true, a car is re-evaluated the moment its garage occupancy changes —
    | hooked onto OperationsService::reconcileVehicleOperationalStatus, the one
    | choke point every maintenance-workflow transition, contract open/close and
    | dispatch already funnels through. Scoped to the ONE affected car.
    |
    | Turning this off leaves the daily `garage:intelligence-sweep` safety net as
    | the only evaluator — accurate, but a day late.
    |
    */
    'realtime' => (bool) env('GARAGE_INTEL_REALTIME', true),

];
