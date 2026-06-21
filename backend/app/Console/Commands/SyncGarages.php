<?php

namespace App\Console\Commands;

use App\Services\GarageImporter;
use Illuminate\Console\Command;
use Throwable;

class SyncGarages extends Command
{
    protected $signature = 'garages:sync
        {sheet=1VzK8l1Vyj5y-Zpdn7_LFtLgIUPxwwoYTr-0DewZrqQE : Spreadsheet ID}
        {gid=1494017154 : Tab gid}';

    protected $description = 'Import garages & used-parts shops from the garages sheet into vendors';

    public function handle(GarageImporter $importer): int
    {
        try {
            $this->info('Importing garages…');
            $result = $importer->import($this->argument('sheet'), (int) $this->argument('gid'));
            $this->info("Done. Garages/shops: {$result['garages']}, used-parts shops: {$result['parts']}.");
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
