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
    |                    That rule only fires once the car has been RENTED again since
    |                    its last test (it must have gone back into service).
    |   inactive_days  — the counterpart: a car that has NOT been rented at all since
    |                    its last test still gets an automatic check-up once this many
    |                    calendar days pass, so a parked-and-forgotten car is kept
    |                    road-ready even though the downtime rule holds off. Should be
    |                    >= downtime_days (a longer grace window before we test an
    |                    unused car). Defaults to 30.
    |
    */
    'diagnostic_gate' => [
        'enabled'       => env('FEATURE_DIAGNOSTIC_GATE', true),
        'downtime_days' => (int) env('DIAGNOSTIC_GATE_DOWNTIME_DAYS', 15),
        'inactive_days' => (int) env('DIAGNOSTIC_GATE_INACTIVE_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Asset Layer (Vehicle Component Inventory)
    |--------------------------------------------------------------------------
    |
    | Governs every workflow-coupled write of the Asset Layer (vehicle_components /
    | component_events / service_records). Three modes:
    |
    |   'off'      — Phase 1 default. The tables/models/permissions exist but NO
    |                integration call site touches them; the maintenance workflow
    |                behaves byte-identically to before the layer existed.
    |   'shadow'   — Phase 2 dark-launch: installPurchase / confirmRoutineServices
    |                also write asset rows, but failures only log (never roll back
    |                or block the billing/workflow write). Reads stay dark.
    |   'enforced' — asset writes are atomic with the workflow writes; disposition
    |                prompts and serial validation become blocking; UI reads live.
    |
    | Rollback at any point = set ASSET_LAYER_MODE=off (config flip, no deploy).
    |
    */
    'asset_layer' => env('ASSET_LAYER_MODE', 'off'),

    /*
    |--------------------------------------------------------------------------
    | Maintenance Event Type (Fault / Service / Inspection)
    |--------------------------------------------------------------------------
    |
    | Governs the type-driven domain separation: every maintenance_task carries a
    | first-class `kind` (fault | service | inspection) sourced from a catalog, so
    | routine servicing (oil, filters, tyres) is never counted, ranked, or scored
    | as a fault. See docs/Service-vs-Fault-Domain-Separation.md. Three modes:
    |
    |   'off'      — Phase 0 default. The catalogs + `kind` column exist and NEW
    |                tasks are stamped with a kind, but NOTHING reads it: every
    |                dashboard / analytics / health / recurrence figure is computed
    |                exactly as before. Byte-identical behaviour.
    |   'shadow'   — `kind` is written on new tasks AND legacy rows are backfilled,
    |                but readers still ignore it. Lets the classification accumulate
    |                and be validated before any number moves.
    |   'enforced' — read paths use the `kind` scopes: fault analytics/health/
    |                recurrence exclude services; services render in their own
    |                section; the UI shows 🔴/🔵/🟨 per event.
    |
    | Rollback at any point = set EVENT_KIND_MODE=off (config flip, no deploy). The
    | column and catalogs are additive; no existing column is dropped or repurposed.
    |
    */
    'event_kind' => env('EVENT_KIND_MODE', 'off'),

    /*
    |--------------------------------------------------------------------------
    | Maintenance Intelligence — Decision Cards
    |--------------------------------------------------------------------------
    |
    | Each capability on the intelligence pipeline is flagged INDEPENDENTLY, so the
    | workflow can be run with and without a given recommendation and the two
    | compared on real operations. That comparison is the point: a recommendation
    | earns the right to become part of the default workflow by demonstrably
    | improving outcomes, not by being built.
    |
    | Everything defaults to FALSE. A card that has never been observed against a
    | measured baseline should not be steering a supervisor's decision.
    |
    |   comeback_detection — "this fault already happened on this car recently."
    |     Reads the `maintenance_signatures` projection only; needs no cost
    |     reconstruction. Baseline it moves: mechanical first-time-fix 53.3%,
    |     second-comeback rate 56.9%. See docs/Maintenance-Intelligence-Success-Framework.md.
    |
    | Rollback is a config flip, not a deploy.
    |
    */
    'intelligence' => [
        'comeback_detection' => env('FEATURE_INTEL_COMEBACK', false),
    ],

];
