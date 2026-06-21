<?php

namespace App\Console\Commands;

use App\Services\AccountingService;
use Illuminate\Console\Command;

class RecalcCustomerBalances extends Command
{
    protected $signature = 'customers:recalc';

    protected $description = 'Reconcile every customer\'s cached debit/credit/balance from their contracts.';

    public function handle(AccountingService $accounting): int
    {
        $this->info('Reconciling customer balances from contracts...');
        $accounting->recalcAllCustomers();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
