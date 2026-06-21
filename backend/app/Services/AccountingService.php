<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Financial calculations (customer balances, contract totals).
 * All figures are computed with single aggregate SQL queries for performance —
 * we never load a customer's contracts into PHP just to sum them.
 */
class AccountingService
{
    /**
     * Recompute and store ONE customer's cached debit/credit/balance from their contracts.
     * Uses a mass update so it does NOT fire Customer model events (no loops).
     */
    public function syncCustomerBalance(int $customerId): void
    {
        $row = $this->aggregate($customerId);
        $debit  = (float) $row->total_debit;
        $credit = (float) $row->total_credit;

        Customer::whereKey($customerId)->update([
            'debit'   => round($debit, 2),
            'credit'  => round($credit, 2),
            'balance' => round($debit - $credit, 2),
        ]);
    }

    /**
     * Reconcile EVERY customer's cached balance from contract sums in a single SQL statement.
     * Run after bulk imports, or on a schedule, to guarantee no drift.
     */
    public function recalcAllCustomers(): void
    {
        DB::statement('
            UPDATE customers c
            LEFT JOIN (
                SELECT customer_id,
                       COALESCE(SUM(contract_debit), 0)  AS d,
                       COALESCE(SUM(contract_credit), 0) AS cr
                FROM contracts
                WHERE deleted_at IS NULL
                GROUP BY customer_id
            ) t ON t.customer_id = c.id
            SET c.debit   = COALESCE(t.d, 0),
                c.credit  = COALESCE(t.cr, 0),
                c.balance = COALESCE(t.d, 0) - COALESCE(t.cr, 0)
            WHERE c.deleted_at IS NULL
        ');
    }

    /**
     * Outstanding balance for a customer = SUM(contract_debit) - SUM(contract_credit)
     * across ALL of the customer's contracts (handles the multi-contract case).
     */
    public function customerOutstandingBalance(Customer|int $customer): float
    {
        $row = $this->aggregate($this->customerId($customer));

        return round((float) $row->total_debit - (float) $row->total_credit, 2);
    }

    /**
     * Available wallet = money the customer has paid beyond what they owe.
     * It's the magnitude of a negative balance (overpayment / unused credit);
     * zero when the customer owes money or is settled. Carries to the next contract.
     */
    public function customerWallet(Customer|int $customer): float
    {
        return round(max(0, -$this->customerOutstandingBalance($customer)), 2);
    }

    /**
     * Full financial summary for a customer (one query).
     *
     * @return array{contracts:int, open_contracts:int, total_debit:float, total_credit:float, outstanding_balance:float}
     */
    public function customerSummary(Customer|int $customer): array
    {
        $row = $this->aggregate($this->customerId($customer));

        $debit  = (float) $row->total_debit;
        $credit = (float) $row->total_credit;

        return [
            'contracts'           => (int) $row->contracts,
            'open_contracts'      => (int) $row->open_contracts,
            'total_debit'         => round($debit, 2),
            'total_credit'        => round($credit, 2),
            'outstanding_balance' => round($debit - $credit, 2),
        ];
    }

    /** The single aggregate query that powers both methods above. */
    protected function aggregate(int $customerId): object
    {
        return Contract::where('customer_id', $customerId)
            ->selectRaw('
                COUNT(*) as contracts,
                SUM(state = \'open\') as open_contracts,
                COALESCE(SUM(contract_debit), 0) as total_debit,
                COALESCE(SUM(contract_credit), 0) as total_credit
            ')
            ->first();
    }

    protected function customerId(Customer|int $customer): int
    {
        return $customer instanceof Customer ? $customer->id : (int) $customer;
    }
}
