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

// Maintenance Checkpoint Scan — chase workshop progress updates before a car goes overdue. Runs at 08:00
// and 20:00 (the 12-hour gap the request→reminder escalation needs) so the responsible follow-up owners
// (Waleed/Abdullah) are nudged a day before the promised completion, again 12h later, on the due day, and
// daily once overdue. Idempotent (level+date keys), so it never spams and clears when a checkpoint lands.
Schedule::command('checkpoints:scan')
    ->twiceDailyAt(8, 20, 10)
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

// Every 10 min: push newly-logged vehicle timeline events (the maintenance-workflow audit trail)
// to the "Vehicle Timeline" Google Sheet. Incremental via a high-water mark, so each run appends
// only what's new — cheap and idempotent. Runs in the background so the Google write never blocks
// the scheduler; withoutOverlapping guards a slow write from stacking with the next tick.
Schedule::command('events:sync-sheet')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();
