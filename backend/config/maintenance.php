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

    /*
    |--------------------------------------------------------------------------
    | Long-running maintenance threshold (days)
    |--------------------------------------------------------------------------
    |
    | A car that has sat in the workshop for at least this many days is flagged
    | "Long-running" on the Maintenance Operations control center — a standing
    | operational signal independent of whether it is past its promised ETA (a
    | job can run long while its ETA keeps being pushed back, so this catches the
    | slow-burners overdue-detection alone would miss).
    |
    */

    'long_running_days' => (int) env('MAINT_LONG_RUNNING_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Maintenance Checkpoint (progress tracking)
    |--------------------------------------------------------------------------
    |
    | Follow-up owners a ticket falls back to when it has no explicit responsible
    | users assigned, plus the reminder lead time. Resolved by
    | MaintenanceCheckpointService::defaultRecipients(): explicit user ids first
    | (comma-separated MAINT_CHECKPOINT_USER_IDS), else every active user holding
    | the fallback permission (the Supervisors — Waleed & Abdullah).
    |
    */

    'checkpoint' => [
        // WHO gets chased, in strict order of preference:
        //   1. the ticket's explicitly assigned responsible users;
        //   2. this allow-list, if set — the safest production setting, because it names real people;
        //   3. holders of `fallback_permission` NARROWED to `fallback_roles` and never in `excluded_roles`.
        // If none of those yields anybody the reminder is NOT broadcast to whoever happens to hold a broad
        // permission — the ticket is reported as unassigned on the Checkpoint Compliance board instead.
        // A daily nag sent to everyone is how people learn to ignore it.
        'default_user_ids'    => array_values(array_filter(array_map(
            fn ($v) => (int) trim($v),
            explode(',', (string) env('MAINT_CHECKPOINT_USER_IDS', ''))
        ))),
        'fallback_permission' => env('MAINT_CHECKPOINT_FALLBACK_PERMISSION', 'maintenance.delegate'),
        // Only these OPERATIONAL roles may be auto-selected as fallback recipients. Defaults to the
        // Supervisors, who own workshop follow-up; `maintenance.delegate` alone is far too wide (on the
        // live fleet it also matches both super-admins and the QA accounts).
        'fallback_roles'      => array_values(array_filter(array_map(
            fn ($v) => trim($v),
            explode(',', (string) env('MAINT_CHECKPOINT_FALLBACK_ROLES', 'supervisor'))
        ))),
        // Never auto-select these roles, whatever else matches — admins are accountable for the chase,
        // not the target of it, and a daily operational nag to an admin account is pure noise.
        'excluded_roles'      => array_values(array_filter(array_map(
            fn ($v) => trim($v),
            explode(',', (string) env('MAINT_CHECKPOINT_EXCLUDED_ROLES', 'super-admin,admin'))
        ))),
        // Days before the expected completion date the reminder window OPENS. From that day the supervisor
        // is asked once a DAY ("is it still coming back on the promised date?") until they answer or the
        // car leaves. Pushing the date back moves this window with it.
        'reminder_lead_days'  => (int) env('MAINT_CHECKPOINT_LEAD_DAYS', 1),
        // How long a reminder may sit unanswered before the admin's Checkpoint Compliance board flags it
        // — "the supervisor was notified and gave no reason for a whole day".
        'unanswered_alert_days' => (int) env('MAINT_CHECKPOINT_UNANSWERED_DAYS', 1),
        // Days without a progress update before the Operations board flags a car as neglected
        // ("No checkpoint updates for N days"). Drives MaintenanceOpsCardService's stale alert.
        'stale_days'          => (int) env('MAINT_CHECKPOINT_STALE_DAYS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Oil-change projection (cars out on rental)
    |--------------------------------------------------------------------------
    | Drives OilChangeProjectionService. A car on a long rental can burn through
    | its oil interval days after it leaves, so each day we project where its
    | odometer has probably reached and chase ops for a real reading before the
    | limit is crossed. See the service docblock for the full model.
    */

    'oil_projection' => [
        // Prediction rate — a flat BUSINESS ASSUMPTION, not a per-customer model. Kept fixed so
        // the behaviour is deterministic, explainable and testable. Every reported reading simply
        // becomes the new anchor and we carry on projecting at this same rate.
        'rate_km_per_day'      => (int) env('OIL_PROJECTION_RATE_KM', 200),

        // How far past the service point a car may run before it actually has to be changed.
        // Fleet-wide (the interval itself is already per-car, from the Oil Change sheet).
        'grace_km'             => (int) env('OIL_PROJECTION_GRACE_KM', 500),

        // What a customer is ALLOWED to drive per day. Used only by the pre-handover release
        // decision, which is a worst-case call and so is made against the permitted maximum
        // rather than the prediction rate. A contract's own `miles_allowed_pd` overrides it.
        'allowance_km_per_day' => (int) env('OIL_PROJECTION_ALLOWANCE_KM', 250),

        // WHO gets chased for a customer mileage reading. An allow-list of real people (Leen and
        // Marwa) is the safest production setting. When it is empty we fall back to the permission
        // gate below — deliberately narrow, because a daily nag sent to everyone holding a broad
        // permission is how a fleet learns to ignore the bell.
        'recipient_user_ids'   => array_values(array_filter(array_map(
            fn ($v) => (int) trim($v),
            explode(',', (string) env('OIL_PROJECTION_USER_IDS', ''))
        ))),
    ],

];
