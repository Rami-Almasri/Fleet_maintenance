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
    | DEFAULT IS NOW 'shadow'. Vehicle Installed Components (the per-vehicle configuration tab and the
    | fleet Component Intelligence board) READ these tables, so leaving writes off would ship a feature
    | whose surfaces are permanently empty. 'shadow' is the safe way to have it live: every workflow
    | install populates the asset ledger, but a component-write failure is reported and swallowed, so
    | it can never block or roll back a part install or its billing line. Reads ignore the mode.
    |
    | Promote to 'enforced' once `php artisan components:verify` has run clean over real installs for
    | a while. Enforced makes the asset write atomic with the workflow write, and turns the disposition
    | prompt ("what happened to the old part?") and serial validation into blocking requirements.
    |
    */
    'asset_layer' => env('ASSET_LAYER_MODE', 'shadow'),

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
    /*
    |--------------------------------------------------------------------------
    | Maintenance workflow rules
    |--------------------------------------------------------------------------
    */

    'maintenance' => [
        /*
         | Require a QC verdict before a repaired car closes.
         |
         | ON by default, unlike everything below it, because this one is not a recommendation — it is
         | an evidence-capture rule, and the evidence is unrecoverable. A routine-graded ticket used to
         | auto-close from in_our_park and skip the re-inspection gate entirely; measured, that meant
         | 12 of 16 closed tickets had no verdict, all of them with a garage and real faults.
         |
         | The cost is real: jobs that previously closed themselves now wait for an inspector. Turn it
         | off if that queue becomes the bottleneck — but know that every ticket closing without a
         | verdict is a repair outcome nobody will ever be able to reconstruct.
         */
        'require_qc_verdict' => env('MAINT_REQUIRE_QC_VERDICT', true),
    ],

    'intelligence' => [
        'comeback_detection' => env('FEATURE_INTEL_COMEBACK', false),

        /*
         | Comeback card OPERATING POINT — set by backtest, not by taste.
         |
         | Measured over 26,705 outcome-verified opportunities (every (ticket, signature) pair whose
         | forward 90-day window is fully observed). Base rate: a repair comes back 39.5% of the
         | time. That is the number the card has to beat to be worth an interruption.
         |
         |   90d / ≥1 (the original)  219 cards/mo  precision 47.6%  lift 1.20×
         |   THIS SETTING              52 cards/mo  precision 55.5%  lift 1.40×  recall 12.4%
         |
         | The trade is deliberate and precision-first. We miss roughly seven comebacks in eight.
         | A card firing seven times a day is ignored inside a fortnight, and an ignored card
         | catches none of them. Two visits for the same fault within a fortnight is also a pattern
         | nobody needs persuading is abnormal — the card's first job is to be obviously right when
         | it fires.
         |
         | Re-derive rather than adjust by feel: the backtest re-runs in about a minute.
         */
        'comeback' => [
            'window_days' => env('INTEL_COMEBACK_WINDOW_DAYS', 14),
            'min_priors'  => env('INTEL_COMEBACK_MIN_PRIORS', 1),

            /*
             | Signatures the card stays silent on, each for a measured reason. "Lift" is precision
             | divided by that signature's own recurrence rate; below 1.00 the card is worse than
             | knowing nothing.
             |
             |   OIL_SERVICE   1.05×  recurrence is a service interval, not a failed repair
             |   BATTERY       0.91×  consumable
             |   CHECK_ENGINE  1.04×  a symptom, not a fault
             |   LEAK_OTHER    0.52×  the classifier's fallback bucket — semantically incoherent
             |   TRANSMISSION  0.89×  measured anti-predictive
             |   ACCESSORY     0.80×  measured anti-predictive
             |   KEY           0.94×  measured anti-predictive
             |   FUEL_SYS      0.47×  measured anti-predictive
             |
             | BODY and RIM are absent because they never reach here at all — exposure damage is
             | excluded in the projection itself (customers damage cars; repairs did not fail).
             */
            'excluded_signatures' => [
                'OIL_SERVICE', 'BATTERY', 'CHECK_ENGINE', 'LEAK_OTHER',
                'TRANSMISSION', 'ACCESSORY', 'KEY', 'FUEL_SYS',
            ],

            /*
             | Verified verdicts needed before the card stops calling itself a proxy.
             |
             | `repair_inspections` records a real per-fault QC verdict (fixed | still_exists) at the
             | re-inspection gate. Once enough have accumulated, the card can say "the last repair
             | was signed off as fixed and the fault is back" — a MEASURED repair failure rather
             | than a return rate standing in for one. Below this floor it keeps the proxy caveat and
             | stays capped at moderate confidence. It flips itself; nobody has to remember to.
             */
            'verdict_floor' => env('INTEL_COMEBACK_VERDICT_FLOOR', 30),
        ],
    ],

];
