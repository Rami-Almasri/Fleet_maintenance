<?php

namespace App\Console\Commands;

use App\Exceptions\OdooException;
use App\Services\Odoo\OdooMasterDataService;
use Illuminate\Console\Command;

/**
 * Pull Odoo's accounts, analytic accounts, products and partners into the local picker cache (§26).
 *
 * Read-only against Odoo. Nothing is created there — see OdooMasterDataService for why auto-creating
 * products would be the wrong kind of helpful.
 *
 * This is the command an administrator runs FIRST, before anybody can map anything: with an empty cache
 * every mapping picker is empty, which looks like a bug and is actually just an un-run import.
 */
class OdooPullMasterData extends Command
{
    protected $signature = 'odoo:pull-master-data {--model= : pull only one Odoo model}';

    protected $description = 'Pull Odoo master data (accounts, analytic accounts, products, partners) into the local mapping cache';

    public function handle(OdooMasterDataService $master): int
    {
        try {
            $models = $this->option('model')
                ? [$this->option('model')]
                : array_keys(OdooMasterDataService::MODELS);

            foreach ($models as $model) {
                $this->line("Pulling {$model} …");
                $count = $master->pull($model);
                $this->info("  {$count} record(s)");
            }

            $this->newLine();
            $this->info('Master data pull complete.');

            return self::SUCCESS;
        } catch (OdooException $e) {
            // The two failures worth telling apart on a console: "you never configured this" and
            // "it is configured and Odoo said no".
            $this->error('[' . $e->errorCode . '] ' . $e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
