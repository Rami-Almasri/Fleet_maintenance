<?php

namespace App\Console\Commands;

use App\Services\VehicleImporter;
use Illuminate\Console\Command;

class SyncVehicles extends Command
{
    protected $signature = 'sync:vehicles
        {--overwrite : Force-refresh price/color/category for ALL sheet cars (default: fill only if empty)}
        {--overwrite-vins= : Comma-separated VINs to force-refresh price/color/category}';

    protected $description = 'Import/sync vehicles from the Google Sheet "Faster" master tab (enriched with FASTER Asset prices).';

    public function handle(VehicleImporter $importer): int
    {
        $this->info('Reading the "Faster" master tab and importing vehicles...');

        $overwrite = (bool) $this->option('overwrite');
        $vins = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('overwrite-vins')))));

        $result = $importer->import($overwrite, $vins);

        $this->newLine();
        $this->info("Enriched (matched an API car) : {$result['updated']}");
        $this->info("  of which matched by PLATE + VIN backfilled : " . ($result['backfilled'] ?? 0));
        $this->info("Skipped (no VIN) : {$result['skipped']}");
        $this->info("Protected (added on website) : {$result['protected']}");
        $this->info("Enrichment preserved (kept local price/color/category) : {$result['preserved']}");
        $this->warn("Unmatched (VIN not in our API fleet — NOT created) : " . ($result['unmatched'] ?? 0));
        foreach (array_slice($result['unmatched_samples'] ?? [], 0, 20) as $s) {
            $this->line('  - ' . $s);
        }

        if (! empty($result['problems'])) {
            $this->newLine();
            $this->warn('Problems (' . count($result['problems']) . '):');
            foreach (array_slice($result['problems'], 0, 30) as $p) {
                $this->line('  - ' . $p);
            }
            if (count($result['problems']) > 30) {
                $this->line('  ... and ' . (count($result['problems']) - 30) . ' more');
            }
        }

        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
