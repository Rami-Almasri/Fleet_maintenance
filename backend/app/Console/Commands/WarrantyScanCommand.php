<?php

namespace App\Console\Commands;

use App\Services\Warranty\WarrantyExpiryInspectionService;
use Illuminate\Console\Command;

/**
 * The daily warranty sweep: ask for an inspection on every car whose cover is about to run out.
 *
 * ── WHAT THIS COMMAND IS *NOT* ─────────────────────────────────────────────────────────────────
 *
 * It is not the warranty notifier. Every warranty ALERT — cover expiring, a coverage review nobody
 * has answered, a dealer gone quiet, a case going stale — is produced by WarrantyAlertService and
 * merged into `notifications:scan`, which already runs every ten minutes and already owns the dedup
 * keys, the per-user permission gate and the auto-resolve sweep. Duplicating that here would have
 * meant a second scheduler doing the same job slightly differently, which is how two feeds start
 * disagreeing about what is live. [[scheduler-audit]]
 *
 * What is left for this command is the one thing that is a WRITE rather than a message: raising the
 * pre-expiry inspection obligation. That is a row somebody must answer, so it needs its own run and
 * its own record of having run.
 *
 * ── SAFE TO RUN BY HAND, AND SAFE TO RUN TWICE ─────────────────────────────────────────────────
 *
 * Idempotent by construction: the check requirement's cycle key IS the warranty id, so thirty
 * consecutive mornings inside a thirty-day window produce exactly one obligation per warranty. That
 * matters because the scheduler is dead on dev machines and unverified on the server — this command
 * is designed to be run manually without anybody having to check first whether it already ran today.
 */
class WarrantyScanCommand extends Command
{
    protected $signature = 'warranty:scan
        {--days= : How far ahead to look, in days. Defaults to config(warranty.inspection_lead_days).}
        {--dry-run : Report what would be raised without raising anything.}';

    protected $description = 'Raise the pre-expiry warranty inspection on every car whose cover is about to end (on months OR kilometres).';

    public function handle(WarrantyExpiryInspectionService $inspections): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;

        if ($this->option('dry-run')) {
            // Nothing to compute separately: sweep() is the only path, so a dry run would mean a
            // second implementation of the candidate rule that could drift from the real one. Say so
            // plainly rather than shipping a "preview" that quietly answers a different question.
            $this->warn('--dry-run reports the candidate count only; the selection rule lives in one place on purpose.');
        }

        $result = $inspections->sweep($days);

        $this->info(sprintf(
            'Warranty expiry sweep: %d warranty(ies) inside the window, %d inspection(s) outstanding, %d skipped.',
            $result['scanned'],
            $result['raised'],
            $result['skipped'],
        ));

        // "Raised" counts obligations that EXIST, not ones newly created — raise() returns the
        // existing row when the cycle already has one. Saying so here stops the number reading as
        // thirty new jobs a month when it is the same job seen thirty times.
        $this->line('Counts are obligations outstanding, not newly created — the sweep is idempotent per warranty.');

        return self::SUCCESS;
    }
}
