<?php

namespace App\Console\Commands;

use App\Services\OilChangeImporter;
use Illuminate\Console\Command;

/**
 * Import the per-car service interval + last-service baseline from the "Oil Change" tab.
 *
 * This phase existed ONLY inside `fleet:refresh`, which nothing schedules — so on any machine
 * where nobody ran a full refresh by hand it simply never happened, and every car's
 * `last_service_odometer` / `service_interval_km` stayed at whatever the DB was seeded with.
 * Vehicle::serviceStatus() needs both numbers, so a car missing them reads "no data" for
 * service due while the same car on a refreshed machine reads a real km-overdue figure.
 * Giving the phase its own command is what makes it schedulable at all.
 *
 * Must run BEFORE service:sync-reminders (04:00), which derives the oil-change Service
 * Reminders from exactly these two columns.
 */
class SyncOilChange extends Command
{
    protected $signature = 'sync:oil-change';

    protected $description = 'Import last-service odometer + service interval from the "Oil Change" tab into vehicles.';

    public function handle(OilChangeImporter $importer): int
    {
        $this->info('Reading the "Oil Change" tab and updating service baselines...');

        $result = $importer->import();

        if (isset($result['error'])) {
            $this->error($result['error']);
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Updated   : {$result['updated']}");
        $this->info("Unmatched : {$result['unmatched']}");
        $this->info("Skipped   : {$result['skipped']}");

        if (! empty($result['problems'])) {
            $this->newLine();
            $this->warn('Problems (' . count($result['problems']) . '):');
            foreach (array_slice($result['problems'], 0, 20) as $p) {
                $this->line('  - ' . $p);
            }
        }

        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
