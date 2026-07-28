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
        'default_user_ids'    => array_values(array_filter(array_map(
            fn ($v) => (int) trim($v),
            explode(',', (string) env('MAINT_CHECKPOINT_USER_IDS', ''))
        ))),
        'fallback_permission' => env('MAINT_CHECKPOINT_FALLBACK_PERMISSION', 'maintenance.delegate'),
        // Days before the expected completion date the first "checkpoint required" reminder fires.
        'reminder_lead_days'  => (int) env('MAINT_CHECKPOINT_LEAD_DAYS', 1),
    ],

];
