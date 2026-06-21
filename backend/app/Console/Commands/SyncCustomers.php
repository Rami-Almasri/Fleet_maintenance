<?php

namespace App\Console\Commands;

use App\Services\CustomerImporter;
use Illuminate\Console\Command;

class SyncCustomers extends Command
{
    protected $signature = 'sync:customers';

    protected $description = 'Import customer details from the Google Sheet "New OM" tab (matched by CustomerNo).';

    public function handle(CustomerImporter $importer): int
    {
        $this->info('Reading the "New OM" tab and importing customers...');

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
