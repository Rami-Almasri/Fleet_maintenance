<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supervisor Video-Review gate
    |--------------------------------------------------------------------------
    |
    | The maintenance workflow has an optional "video review" stage
    | (Maintenance::WF_REPAIR_REVIEW) between the garage finishing the repair
    | (markReady) and the final re-inspection: a supervisor watches the garage's
    | video and either approves it for re-inspection or requests a re-fix.
    |
    | While this is `false` the gate is PARKED — the code stays in place but the
    | workflow never routes a car into repair_review. "Mark Ready" promotes the
    | car straight to the single-shot re-inspection, exactly as approveRepair
    | would have. The front-end mirror is SHOW_VIDEO_REVIEW in
    | frontend/src/config/features.js.
    |
    | Flip this to `true` (FEATURE_VIDEO_REVIEW=true) to re-enable the gate — the
    | approveRepair / requestRefix / video-upload logic is all still wired up.
    |
    */
    'video_review' => env('FEATURE_VIDEO_REVIEW', false),

    /*
    |--------------------------------------------------------------------------
    | Email notification channel
    |--------------------------------------------------------------------------
    |
    | When `true`, every FleetAlert is ALSO delivered by email (on top of the
    | durable in-app `database` channel) to any recipient who has an email
    | address. The mail body reuses the alert's title/body and links back to the
    | in-app deep-link (config('app.frontend_url')).
    |
    | PARKED OFF by default: the code path is fully wired (FleetAlert::via /
    | toMail), but nothing is sent until SMTP is configured in .env AND this flag
    | is flipped (NOTIFY_MAIL_ENABLED=true). This keeps the pipeline from trying
    | to send mail through an unconfigured mailer.
    |
    */
    'mail_notifications' => env('NOTIFY_MAIL_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Invoice-overdue bell alerts
    |--------------------------------------------------------------------------
    |
    | The NotificationScanner can raise an `invoice_overdue` alert per concluded
    | rental that still owes a balance. In practice that is a large receivables
    | backlog (hundreds of accounts), so firing one bell per debtor is noise, not
    | signal — it's an accounts-receivable report, not a per-car notification.
    |
    | PARKED OFF by default: the detector is fully wired but returns nothing until
    | a policy is chosen (a materiality threshold, a single digest alert, and/or
    | waiting for the financial reconciliation). The homepage "Proactive Flags"
    | panel still surfaces the top debtors + the true count/total (gated by the
    | frontend SHOW_FINANCIALS flag). Flip to true (NOTIFY_INVOICE_OVERDUE=true)
    | to arm the per-account bell.
    |
    */
    'invoice_overdue_alerts' => env('NOTIFY_INVOICE_OVERDUE', false),

    /*
    |--------------------------------------------------------------------------
    | Demo / Simulation Mode
    |--------------------------------------------------------------------------
    |
    | Arms the admin-only Simulation Panel (SimulationController / the frontend
    | /simulation page). While `true`, the panel's trigger buttons may FORCE a
    | live scenario on a real vehicle — a "Service Due" oil-change condition, or
    | a "Fault Discovery" complaint ticket — so the team can watch the system
    | react end-to-end in real time. Every change is journaled in
    | `simulation_events` and can be rolled back exactly with the panel's Reset.
    |
    | PARKED OFF by default: while `false`, every mutating simulation endpoint
    | hard-refuses with 403 (abort_unless), so the panel can never touch data in
    | a normal/production run even if the page is opened. The frontend mirror is
    | DEMO_MODE in frontend/src/config/features.js (hides the nav entry).
    |
    | Flip to `true` (FEATURE_DEMO_MODE=true) only for a demo, then flip back.
    |
    */
    'demo_mode' => env('FEATURE_DEMO_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Diagnostic Monitor
    |--------------------------------------------------------------------------
    |
    | The condition brain behind the Proactive Diagnostic Monitor
    | (DiagnosticGateService + the inspections:generate-tasks command): it decides
    | when a car is due for a routine/check-up and raises a system "Needs Test
    | Drive" request + Inspector notification.
    |
    |   enabled        — master switch. While `false`, DiagnosticGateService
    |                    reports no due conditions, so the monitor raises nothing.
    |   downtime_days  — a car idle (in-fleet but not rented / in the shop) for at
    |                    least this many days triggers a Post-Downtime Safety Check.
    |
    */
    'diagnostic_gate' => [
        'enabled'       => env('FEATURE_DIAGNOSTIC_GATE', true),
        'downtime_days' => (int) env('DIAGNOSTIC_GATE_DOWNTIME_DAYS', 15),
    ],

];
