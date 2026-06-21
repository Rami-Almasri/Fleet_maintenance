<?php

namespace App\Console\Commands;

use App\Services\VendorInsuranceImporter;
use Illuminate\Console\Command;

class SyncVendorsInsurance extends Command
{
    protected $signature = 'sync:vendors-insurance';

    protected $description = 'Add the distinct insurance companies from the "F Insurance" tab into the vendors table (type=insurance).';

    public function handle(VendorInsuranceImporter $importer): int
    {
        $this->info('Reading insurance companies from the "F Insurance" tab...');

        $result = $importer->import();

        if (isset($result['error'])) {
            $this->error($result['error']);
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Distinct companies : {$result['distinct_companies']}");
        $this->info("Created            : {$result['created']}");
        $this->info("Already existed    : {$result['already_existed']}");

        return self::SUCCESS;
    }
}
