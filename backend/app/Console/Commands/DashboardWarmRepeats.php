<?php

namespace App\Console\Commands;

use App\Services\DashboardService;
use Illuminate\Console\Command;

/**
 * Pre-compute the dashboard's fleet-wide repeat-fault sweep so no human ever pays for it.
 *
 * "What Keeps Coming Back" now reads the OBSERVED repeat faults — every car's merged workshop-log +
 * ticket history, run through VehicleFaultRecurrenceService. That is the honest source (the review table
 * it used to read had produced 0 rows from real workflow activity), but it walks the whole fleet and
 * costs ~40–50 seconds cold. Cached for 30 minutes, that is one unlucky pageview every half hour.
 *
 * This command is that unlucky pageview, moved off the dashboard and onto the scheduler — the same
 * pattern `trips:warm` already uses. Safe to run at any time; it only writes a cache entry.
 *
 * NOTE for production: [[scheduler-audit]] records that the scheduler is not reliably running on prod.
 * If it is not, the card still works — the first reader after each expiry simply waits for the sweep.
 */
class DashboardWarmRepeats extends Command
{
    protected $signature = 'dashboard:warm-repeats';

    protected $description = 'Pre-compute the fleet-wide repeat-fault sweep behind the What Keeps Coming Back card';

    public function handle(DashboardService $dashboard): int
    {
        $started = microtime(true);

        // Asking for the faults section is what populates the sweep's own cache entry; requesting the
        // other two tabs as well would warm ledgers that are already fast and cheap.
        $section = $dashboard->repeats(DashboardService::REPEAT_WINDOW_DAYS, 6, ['faults'])['sections']['faults'] ?? [];

        $sources = $section['sources'] ?? [];
        $this->info(sprintf(
            'Warmed in %.1fs — %d returns across %d cars (%d sheet / %d system / %d both).',
            microtime(true) - $started,
            $section['total'] ?? 0,
            $section['cars'] ?? 0,
            $sources['sheet'] ?? 0,
            $sources['system'] ?? 0,
            $sources['both'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
