<?php

namespace App\Observers;

use App\Models\Payment;
use App\Services\AccountingService;

/**
 * Keeps a customer's cached balance / available wallet in step with the website-native
 * payments (P-) they record — the collection side OfficeManager never knew about. A new,
 * edited, or removed payment immediately re-syncs the customer so the wallet reflects what
 * was actually collected (see AccountingService for the combined-ledger rule).
 */
class PaymentObserver
{
    public function __construct(protected AccountingService $accounting)
    {
    }

    public function created(Payment $payment): void
    {
        $this->sync($payment->customer_id);
    }

    public function updated(Payment $payment): void
    {
        $this->sync($payment->customer_id);
        if ($payment->wasChanged('customer_id')) {
            $this->sync($payment->getOriginal('customer_id'));
        }
    }

    public function deleted(Payment $payment): void
    {
        $this->sync($payment->customer_id);
    }

    public function restored(Payment $payment): void
    {
        $this->sync($payment->customer_id);
    }

    protected function sync(?int $customerId): void
    {
        if (ContractObserver::$muted || ! $customerId) {
            return;
        }
        $this->accounting->syncCustomerBalance($customerId);
    }
}
