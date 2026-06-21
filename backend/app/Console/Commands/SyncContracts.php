<?php

namespace App\Console\Commands;

use App\Services\ContractImporter;
use Illuminate\Console\Command;

class SyncContracts extends Command
{
    protected $signature = 'sync:contracts';

    protected $description = 'Import rental contracts from the rich RA "Contracts" sheet (linked to customers by CustomerNo, cars by VIN).';

    public function handle(ContractImporter $importer): int
    {
        $this->info('Reading the "Contracts" tab and importing contracts...');

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
            foreach (array_slice($result['problems'], 0, 25) as $p) {
                $this->line('  - ' . $p);
            }
            if (count($result['problems']) > 25) {
                $this->line('  ... and ' . (count($result['problems']) - 25) . ' more');
            }
        }

        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
