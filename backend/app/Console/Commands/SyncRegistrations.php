<?php

namespace App\Console\Commands;

use App\Services\VehicleRegistrationImporter;
use Illuminate\Console\Command;

class SyncRegistrations extends Command
{
    protected $signature = 'sync:registrations';

    protected $description = 'Import vehicle RTA registration + fines from the "F RTA" tab (linked to cars by VIN).';

    public function handle(VehicleRegistrationImporter $importer): int
    {
        $this->info('Reading the "F RTA" tab and importing vehicle registrations...');

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
