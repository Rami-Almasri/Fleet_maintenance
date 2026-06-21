<?php

namespace App\Console\Commands;

use App\Services\OperationsService;
use Illuminate\Console\Command;

class ReconcileFleetStatus extends Command
{
    protected $signature = 'fleet:reconcile-status';

    protected $description = "Re-derive every car's operational_status (rented / in maintenance / available) from its currently-open contracts.";

    public function handle(OperationsService $ops): int
    {
        $this->info("Reconciling vehicle operational_status from open contracts…");
        $r = $ops->reconcileAllOperationalStatus();
        $this->line("  changed → rented: {$r['rented']} · maintenance: {$r['maintenance']} · available: {$r['available']}");

        return self::SUCCESS;
    }
}
