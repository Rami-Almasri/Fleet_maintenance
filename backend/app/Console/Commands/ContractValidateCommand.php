<?php

namespace App\Console\Commands;

use App\Services\ContractValidator;
use Illuminate\Console\Command;

class ContractValidateCommand extends Command
{
    protected $signature = 'contracts:validate
        {--zombie-days=90 : Days without an in_date before an open contract is a "zombie"}';

    protected $description = 'Read-only data-quality check: flag "zombie" contracts (open, no return after N days).';

    public function handle(ContractValidator $validator): int
    {
        $days = (int) $this->option('zombie-days') ?: 90;
        $z = $validator->zombies($days);

        $this->info("Zombie contracts (open, no in_date, out_date before {$z['cutoff']} — {$days}d): {$z['count']}");

        if ($z['samples']) {
            $this->table(
                ['Contract', 'Serial', 'Out date', 'Days out', 'Vehicle id'],
                array_map(fn ($s) => [
                    $s['contract_no'], $s['contract_serial'], $s['out_date'], $s['days_out'], $s['vehicle_id'],
                ], $z['samples'])
            );

            if ($z['count'] > count($z['samples'])) {
                $this->line('… and ' . ($z['count'] - count($z['samples'])) . ' more.');
            }
        }

        return self::SUCCESS;
    }
}
