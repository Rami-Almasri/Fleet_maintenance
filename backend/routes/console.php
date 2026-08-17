<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily: flag vehicles whose registration/insurance has expired (stop renting + sale/maintenance prep).
Schedule::command('fleet:check-expiry')->dailyAt('02:00');

// Every 10 min: pre-warm the Trip Dashboard cache (Delivery Command / Orders board) so the
// ~3s Google Sheets read happens off the request path and page loads stay instant.
Schedule::command('trips:warm')->everyTenMinutes()->withoutOverlapping();

// Nightly: refresh the N-Maintenance & Repair Google Sheet (the SOLE source of garage
// stage / IN-OUT / notes for maintenance contracts). Without this the /maintenance board
// and Return Check page go stale even though om:sync keeps contracts current. Runs before
// om:sync, then re-categorises any new rows against the reason vocabulary.
Schedule::command('import:maintenance-sheet')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('maintenance:link-reasons')->dailyAt('02:50');

// "Which car is at which garage" — the N-Location tab, the Controllers' hand-kept list and the ONLY
// written record of the garage for a car OfficeManager sent out on a maintenance contract. Every run
// REPLACES the mirror, so a car whose row was deleted (it came back) stops being shown at a garage.
// Hourly, not nightly: the tab is edited during the working day, and the In the Garage board is read
// during the working day. ⚠ The scheduler is unverified on the server ([[scheduler-audit]]) — the
// board shows its own `imported_at`, so a mirror that stopped refreshing is visible on the page
// itself rather than quietly going stale.
Schedule::command('import:garage-locations')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// OfficeManager API is the source of truth. The sync is split by dataset so each refreshes at
// the cadence it actually changes at (idempotent updateOrCreate by external_id "OM:{serial}",
// so every run refreshes + adds new). All use --skip-backup: routine delta syncs never dump the
// DB (that would be far too often for the hourly one — schedule a separate db:backup for that).

// Cars — once a day. --vehicles imports new cars from the API; --link refreshes each car's STATUS
// (API StatusNo) + car_serial. Cars change rarely, so daily is plenty.
Schedule::command('om:sync --vehicles --link --skip-backup')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

// Right after the cars sync re-imports each car's status from OfficeManager, overlay the fleet
// "Status" sheet on top: OM still lists Sold/Exported/Personal cars under our owner number, so this
// is what actually pulls them out of the active pool. Active cars keep their live om:sync status.
Schedule::command('import:vehicle-status')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->runInBackground();

// Insurance + Mulkiya, from the "F Insurance" tab — the source of truth for insurance_expiry
// since 2026-07-23 (OfficeManagerSync::upsertMortgage writes only is_mortgaged now). This phase
// lived ONLY inside `fleet:refresh`, which nothing schedules, so on any machine where nobody
// typed it by hand the insurance dates stayed frozen at their old API-era values while om:sync
// kept rewriting the same rows nightly — the registration looked fresh and the insurance was
// months stale. That is what put 57 cars on production behind "insurance expired" alerts when
// only 13 were genuinely lapsed. Runs after import:vehicle-status so it sees the settled fleet.
Schedule::command('sync:insurance')
    ->dailyAt('03:20')
    ->withoutOverlapping()
    ->runInBackground();

// The rest of the sheet phases, for the same reason. Each of these lived only inside
// `fleet:refresh`; the API syncs above had their own entries and the sheet imports did not, so
// production ran for months on whatever sheet data its database happened to be seeded with while
// om:sync kept the same rows' updated_at looking current. Ordered to mirror fleet:refresh —
// cars first, then the registration/fines overlay, then the sheet-sourced maintenance history.
Schedule::command('sync:vehicles')       // "Faster" tab — make/model/colour + purchase price
    ->dailyAt('03:25')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('sync:registrations')  // "F RTA" tab — fines count/amount + status text
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('import:customer-cases')
    ->dailyAt('03:35')
    ->withoutOverlapping()
    ->runInBackground();

// Oil change LAST of the sheet block but before mileage:scan (03:45) and service:sync-reminders
// (04:00) — those two read last_service_odometer / service_interval_km, the two columns this
// import owns. Run it after them and the reminders are computed from yesterday's baselines.
Schedule::command('sync:oil-change')
    ->dailyAt('03:40')
    ->withoutOverlapping()
    ->runInBackground();

// Contracts — hourly. Keeps fleet availability (Available/Rented/Maintenance) fresh all day,
// not just after the nightly run. Light: reads open contracts + close-detection.
Schedule::command('om:sync --contracts --skip-backup')
    ->hourlyAt(5)
    ->withoutOverlapping()
    ->runInBackground();

// Invoices — twice a day (03:20 & 15:20). Rolls invoice debit into contracts.
Schedule::command('om:sync --invoices --skip-backup')
    ->twiceDailyAt(3, 15, 20)
    ->withoutOverlapping()
    ->runInBackground();

// Customers — twice a day (03:30 & 15:30). Only fills MISSING names/details, so it's light.
Schedule::command('om:sync --customers --skip-backup')
    ->twiceDailyAt(3, 15, 30)
    ->withoutOverlapping()
    ->runInBackground();

// Every 10 minutes: turn current fleet conditions (overdue rentals, maintenance overruns,
// expiring documents, service-due cars, pending approvals) into in-app notifications. The
// scan is idempotent — it only raises genuinely new conditions — so a tight cadence keeps the
// bell near-realtime without ever spamming. Runs once more right after the nightly sync so the
// feed reflects freshly imported data immediately.
Schedule::command('notifications:scan')
    ->everyTenMinutes()
    ->withoutOverlapping();

Schedule::command('notifications:scan')->dailyAt('03:10')->withoutOverlapping();

// Start-of-day sweep at 08:00 so the Supervisor opens their morning to a fresh feed — in particular
// the preventive "Service Due Soon" alerts (cars approaching the service interval by km or by projected
// date). Idempotent + once-a-week dedup key, so this can't spam even alongside the 10-minute cadence.
Schedule::command('notifications:scan')->dailyAt('08:00')->withoutOverlapping();

// After the nightly contract sync, re-anchor every car's Global Mileage Baseline and heal its
// odometer from contract history (out/in mileage). Runs late enough that om:sync (03:00, in the
// background) has imported the day's fresh handovers, so the baseline + correction reflect them.
// Mileage drops / implausible jumps it finds surface on the /anomalies dashboard.
Schedule::command('mileage:scan --apply')
    ->dailyAt('03:45')
    ->withoutOverlapping();

// Missing-Invoice SLA: flag every ticket back in service whose invoice has been outstanding beyond the
// 3-day window, alerting the Supervisor + controllers. Runs each morning so the reminder lands before work.
Schedule::command('invoices:scan-overdue')
    ->dailyAt('08:05')
    ->withoutOverlapping();

// Maintenance Checkpoint Scan — the DAILY chase. From a day before the promised completion the responsible
// follow-up owners (Waleed/Abdullah) are asked every day whether the car is still coming back on that date,
// and keep being asked until they answer or the car leaves the shop. The morning run is the day's ask; the
// evening run catches a car that only crossed into due/overdue during the day (and, because the reminder
// receipt is keyed on the DAY, re-pushes nothing that already went out). Answering settles today only —
// pushing the date back moves the window, so the chase goes quiet until a day before the NEW date.
Schedule::command('checkpoints:scan')
    ->twiceDailyAt(8, 20, 10)
    ->withoutOverlapping();

// Inspection Review "remind me later" — fire the reminders a Controller set on a request in the review
// queue ("this car is out on hire, ask me again in 2 hours").
//
// EVERY MINUTE, and the cadence is the whole point: a reminder is a PROMISE ABOUT A TIME. Someone who
// asks for 30 minutes and is pinged at 39 stops trusting the feature and goes back to remembering things
// themselves, which is the problem this was built to remove. A ten-minute wheel makes every preset late
// by up to ten minutes; a one-minute wheel makes it late by up to one. The cost of that accuracy is one
// indexed read of (status, remind_at) that returns nothing almost every time it runs.
//
// Rows leave the working set the instant they are marked sent, so this is safe to run by hand and safe
// to run twice — which matters, because the scheduler is unverified on the server ([[scheduler-audit]]).
Schedule::command('review-reminders:dispatch')
    ->everyMinute()
    ->withoutOverlapping();

// Daily: keep the auto-derived oil-change Service Reminders in step with the Oil Change sheet data on
// each car (last_service_odometer + service_interval_km). Creates a reminder for newly-matched cars and
// refreshes 'auto' anchors; a reminder a human has edited (source='manual') is left untouched. Runs after
// om:sync (03:00) + mileage:scan (03:45) so it reads the freshest odometer/interval data.
// NOTE: the overdue / due-soon STATUS is computed live on every page load from the car's current
// odometer, so it's always current between runs — this command only keeps the reminder ROWS in sync
// with the source sheet (new cars, changed intervals).
Schedule::command('service:sync-reminders')
    ->dailyAt('04:00')
    ->withoutOverlapping();

// Proactive Diagnostic Monitor: each morning, raise a system "Needs Test Drive" request (+ notify the
// Inspector) for every active-fleet car due for a routine/check-up — oil, tyres, battery, or too long
// idle (DiagnosticGateService::conditionsDue). Runs after the reminder sync (04:00) so it reads the
// freshest anchors; the openWorkflow dedup means a car already in the pipeline is never re-requested,
// so this is safe to run daily. The agenda on each request tells the Inspector exactly what to check.
Schedule::command('inspections:generate-tasks')
    ->dailyAt('07:30')
    ->withoutOverlapping();

// Hourly: raise the oil-change ticket for rentals that have come back owing one — a controller's
// mid-rental "do it on return" / "recall now" call, or simply a car that returned past its oil
// limit. Hourly rather than daily because the trigger is a physical return: the car is standing in
// the yard and the crew needs the ticket while it is still there, not tomorrow morning. Idempotent
// (one settled decision row per rental), so a run that overlaps a UI-driven close is harmless.
Schedule::command('oil:settle-returns')
    ->hourly()
    ->withoutOverlapping();

// EVERY MINUTE: chase the cars whose oil change is finished but which are still standing in our
// yard. The command itself only rings a car whose last reminder is older than its window (5 min),
// so "every minute" costs one cheap indexed query and the noise is governed by the service, not by
// the schedule. Every other sweep in this file can wait until tomorrow; this one is measured in
// hours of a customer's paid rental, so it cannot.
Schedule::command('oil:chase-returns')
    ->everyMinute()
    ->withoutOverlapping();

// Every 10 min: push newly-logged vehicle timeline events (the maintenance-workflow audit trail)
// to the "Vehicle Timeline" Google Sheet. Incremental via a high-water mark, so each run appends
// only what's new — cheap and idempotent. Runs in the background so the Google write never blocks
// the scheduler; withoutOverlapping guards a slow write from stacking with the next tick.
Schedule::command('events:sync-sheet')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Nightly: rebuild the repair-signature projection — the corpus EVERY intelligence capability reads
// from. It was previously only ever run by hand, which is a failure with no symptom: the platform
// keeps answering, confidently, out of a history that stopped at whenever someone last remembered.
// Cheap (~4s over 26k tickets) and idempotent by construction — the projection is derived data with
// no authority of its own, so rebuilding is always the safe operation.
//
// Placed after the sheet import (02:30) and reason linking (02:50) so it reads the day's new rows,
// and before record-outcomes (03:15), which judges recommendations against it.
Schedule::command('intelligence:rebuild-signatures')
    ->dailyAt('03:05')
    ->withoutOverlapping();

// Nightly: judge past recommendations against what actually happened — the step that turns a
// recommendation engine into a learning one. It runs ninety days after the fact, long after anyone
// has stopped thinking about the case, which is precisely why it must be automatic: nobody is ever
// going to come back and record this by hand. Idempotent (already-judged rows are skipped) and
// append-only, so a re-run can never rewrite a verdict.
Schedule::command('intelligence:record-outcomes')
    ->dailyAt('03:15')
    ->withoutOverlapping();

// Weekly: run the proxy→measured promotion gate. Promotion is EVIDENCE-DRIVEN, NOT CALENDAR-DRIVEN —
// this schedule only decides how often the question is ASKED; the answer comes from a backtest that
// compares the measured outcome against the proxy it would replace, and refuses if it predicts worse.
// Every decision, including every refusal, is recorded append-only.
// `--alert` notifies the maintenance managers when QC verdict capture stalls. That is the one
// failure this platform cannot see from the outside: the intelligence layer does not break when
// verdicts stop arriving, it keeps producing cards from proxy evidence and looks exactly as healthy
// as before, while the dataset that would let it improve quietly stops growing.
Schedule::command('intelligence:evidence-health --promote --alert')
    ->weeklyOn(1, '04:00')
    ->withoutOverlapping();

// ── Fleet Intelligence materialised tables ──────────────────────────────────────────────────────
//
// Two derived tables carry the whole intelligence platform: `repair_visits` collapses maintenance
// EVENTS into real garage VISITS (only 23% of same-day groups are a single row, so counting raw
// rows as repairs overstates volume by ~2×), and `fault_recurrence_pairs` answers "when did this
// fault next come back?" — the number behind every garage quality judgement.
//
// ORDER MATTERS: recurrence reads the same corpus visits does and is sequenced after it so a
// half-built night never mixes one table's view of the data with the other's. Both are placed after
// om:sync (03:00), import:vehicle-status (03:15) and rebuild-signatures (03:05), so they see the
// day's rows and the day's fault labels.
//
// Both are FULL rebuilds into a staging table, validated before an atomic swap: if validation fails
// the previous known-good table is still serving. A stale number is recoverable; a silently wrong
// one is not.
//
// ⚠ The scheduler is dead on dev machines and unverified on the server. Both commands are designed
// to be run by hand, and Data Health surfaces `built_at` so a scheduler that stops firing shows up
// as ageing data rather than as numbers that quietly drift.
Schedule::command('intelligence:rebuild-visits')
    ->dailyAt('04:20')
    ->withoutOverlapping();

Schedule::command('intelligence:rebuild-recurrence')
    ->dailyAt('04:35')
    ->withoutOverlapping();

// ── Freshness watchdog ──────────────────────────────────────────────────────────────────────────
//
// ⚠ A WATCHDOG INSIDE THE THING IT WATCHES IS ONLY HALF A WATCHDOG. If the scheduler itself stops,
// this entry stops with it and the silence is indistinguishable from health. It is registered here
// anyway because it catches the FAR more likely failure — a rebuild that runs and fails, or a source
// import that stalls — and because it costs nothing.
//
// The other half belongs OUTSIDE Laravel: an OS-level task (Windows Task Scheduler / cron) running
// the same command. Only that survives the scheduler dying. See
// docs/Intelligence-Rebuild-Operations.md §3.
//
// 06:00 — ninety minutes after the rebuild window, so a failed or skipped night is caught before
// anyone opens a dashboard. --alert notifies the maintenance managers AND exits non-zero, so it
// works whether a human or a monitor is watching.
Schedule::command('intelligence:rebuild-health --alert')
    ->dailyAt('06:00')
    ->withoutOverlapping();
