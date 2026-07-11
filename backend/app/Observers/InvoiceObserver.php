<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\AccountingService;

/**
 * Keeps a customer's cached balance / available wallet in step with the website-native
 * invoices (M-) raised against their contracts. ONLY manual invoices matter here — the
 * OM-synced ('api') invoices already mirror contract_debit, so they are ignored to avoid
 * double-billing (see AccountingService for the combined-ledger rule).
 */
class InvoiceObserver
{
    public function __construct(protected AccountingService $accounting)
    {
    }

    public function created(Invoice $invoice): void
    {
        $this->sync($invoice);
    }

    public function updated(Invoice $invoice): void
    {
        $this->sync($invoice);
        if ($invoice->wasChanged('customer_id')) {
            $this->sync($invoice, $invoice->getOriginal('customer_id'));
        }
    }

    public function deleted(Invoice $invoice): void
    {
        $this->sync($invoice);
    }

    public function restored(Invoice $invoice): void
    {
        $this->sync($invoice);
    }

    protected function sync(Invoice $invoice, ?int $customerId = null): void
    {
        $customerId ??= $invoice->customer_id;
        // Only the website-native ledger feeds the customer wallet; skip OM-synced invoices.
        if (ContractObserver::$muted || $invoice->origin !== 'manual' || ! $customerId) {
            return;
        }
        $this->accounting->syncCustomerBalance($customerId);
    }
}
