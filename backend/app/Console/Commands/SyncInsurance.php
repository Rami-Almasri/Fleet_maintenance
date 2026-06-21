<?php

namespace App\Console\Commands;

use App\Services\InsuranceImporter;
use Illuminate\Console\Command;

class SyncInsurance extends Command
{
    protected $signature = 'sync:insurance';

    protected $description = 'Import insurance expiry dates from the "F Insurance" tab into vehicle_registrations.';

    public function handle(InsuranceImporter $importer): int
    {
        $this->info('Reading the "F Insurance" tab and updating insurance expiry...');

        $result = $importer->import();

        if (isset($result['error'])) {
            $this->error($result['error']);
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Created : {$result['created']}");
        $this->info("Updated : {$result['updated']}");
        $this->info("Skipped : {$result['skipped']}");
        $this->info("Protected (web) : {$result['protected']}");

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
