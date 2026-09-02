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
    | MEASURED AGAINST THE LIVE FLEET — 178 cars, window ending 2026-09-02.
    | (Measured on the LIVE fleet deliberately: the vehicles table also holds 261
    | sold and 4 disposed cars whose windows are empty, and including them halves
    | every percentage below and makes ordinary behaviour look like a tail.)
    |
    |   mean 1.27 visits · median 1 · 90th percentile 3 · observed maximum 5
    |     >= 3 visits →  30 cars (16.9%)
    |     >= 4 visits →   8 cars (4.5%)
    |     >= 5 visits →   2 cars (1.1%)
    |     >= 6 visits →   0 cars
    |     >= 7 visits →   0 cars
    |
    | `warning` at 3 sits exactly on the 90th percentile and `high` at 5 on the
    | top 1% — both are real, defensible bands.
    |
    | `critical` at 7 is UNREACHABLE on this fleet: no car has reached even 6 in a
    | 30-day window, so the band exists as headroom and will never grade a car
    | today. Kept at the requested default rather than silently retuned. Set
    | GARAGE_INTEL_VISITS_CRITICAL=6 if a live critical band is wanted on this
    | signal — that is the lowest value that is still above the observed maximum,
    | so it stays a genuine outlier rather than re-labelling the existing `high`.
    |
    */
    'visits' => [
        'warning'  => (int) env('GARAGE_INTEL_VISITS_WARNING', 3),
        'high'     => (int) env('GARAGE_INTEL_VISITS_HIGH', 5),
        'critical' => (int) env('GARAGE_INTEL_VISITS_CRITICAL', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Downtime thresholds (true off-road shop DAYS inside the window)
    |--------------------------------------------------------------------------
    |
    | MEASURED AGAINST THE LIVE FLEET (same 178 cars, same window):
    |
    |   mean 1.56 days · median 0.45 · 90th percentile 4.5 · maximum 30.3
    |     >=  3 days → 24 cars (13.5%)
    |     >=  7 days →  8 cars (4.5%)
    |     >= 14 days →  2 cars (1.1%)
    |
    | 3 / 7 / 14 are well calibrated as shipped: each step is a real, shrinking
    | tail and — unlike the visit ladder — the critical band is genuinely
    | reachable. Left at the requested defaults, and no change is recommended.
    |
    | "Downtime" here is the canonical TRUE off-road figure: time under a
    | maintenance contract that is NOT also under a rental. Rental is King — a
    | day the customer had the car is rental time, never shop time.
    |
    */
    'downtime_days' => [
        'warning'  => (float) env('GARAGE_INTEL_DOWNTIME_WARNING', 3),
        'high'     => (float) env('GARAGE_INTEL_DOWNTIME_HIGH', 7),
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
