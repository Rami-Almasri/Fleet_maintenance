<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily: flag vehicles whose registration/insurance has expired (stop renting + sale/maintenance prep).
Schedule::command('fleet:check-expiry')->dailyAt('02:00');

// Nightly: refresh the N-Maintenance & Repair Google Sheet (the SOLE source of garage
// stage / IN-OUT / notes for maintenance contracts). Without this the /maintenance board
// and Return Check page go stale even though om:sync keeps contracts current. Runs before
// om:sync, then re-categorises any new rows against the reason vocabulary.
Schedule::command('import:maintenance-sheet')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('maintenance:link-reasons')->dailyAt('02:50');

// OfficeManager API is the source of truth. Nightly: --link refreshes every car's STATUS
// (from the API StatusNo) + car_serial, then contracts/invoices/customers are pulled.
// Idempotent (updateOrCreate by external_id "OM:{serial}"), so it refreshes + adds new.
// --skip-backup: this routine delta sync skips the pre-flight dump (manual re-imports back
// up automatically). Drop --skip-backup here if you want a nightly DB backup before the sync.
Schedule::command('om:sync --link --contracts --invoices --customers --skip-backup')
    ->dailyAt('03:00')
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

Schedule::command('notifications:scan')->dailyAt('03:10');
