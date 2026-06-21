<?php

namespace App\Observers;

use App\Models\Contract;
use App\Services\AccountingService;

class ContractObserver
{
    /**
     * Set true to skip syncing during bulk imports (we recalc once at the end instead).
     */
    public static bool $muted = false;

    public function __construct(protected AccountingService $accounting)
    {
    }

    public function created(Contract $contract): void
    {
        $this->sync($contract->customer_id);
    }

    public function updated(Contract $contract): void
    {
        // recalc the current customer
        $this->sync($contract->customer_id);

        // if the contract was moved to a different customer, recalc the old one too
        if ($contract->wasChanged('customer_id')) {
            $this->sync($contract->getOriginal('customer_id'));
        }
    }

    public function deleted(Contract $contract): void
    {
        $this->sync($contract->customer_id);
    }

    public function restored(Contract $contract): void
    {
        $this->sync($contract->customer_id);
    }

    protected function sync(?int $customerId): void
    {
        if (self::$muted || ! $customerId) {
            return;
        }

        $this->accounting->syncCustomerBalance($customerId);
    }
}
