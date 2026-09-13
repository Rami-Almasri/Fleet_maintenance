<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE CUSTOMER CHARGE FOR AN ACCIDENT — one column, and no new ledger.
 *
 * ── WHY THERE IS NO `accident_customer_charges` TABLE ──────────────────────────────────────────
 *
 * Because the money a customer owes already has exactly one home, and adding a second would make
 * "what does this customer owe?" a question with two answers. A customer's balance is DERIVED:
 *
 *     balance = Σ contract_debit + Σ manual-invoice total_after_vat
 *             − Σ contract_credit − Σ manual-payment amount          (@see AccountingService)
 *
 * and the "available wallet" is simply `max(0, −balance)` — there is no wallet table, no deposit
 * table, and no stored credit anywhere. That is deliberate ([[customer-wallet-feature]]: any
 * overpayment becomes credit against the next thing they owe, with no deposit-vs-overpayment split).
 *
 * So charging a customer for an accident is not a new KIND of transaction. It is a charge on their
 * account, and this system already has one instrument for that: a website-native invoice
 * (`origin = 'manual'`, ref `M-0001`). Raising one debits the account, `InvoiceObserver` re-syncs
 * the cached balance, and the wallet is consumed by arithmetic rather than by a transfer. Nothing
 * can go negative, because nothing is decremented — a derived figure simply moves.
 *
 * ── THE UNIQUE INDEX IS THE IDEMPOTENCY GUARANTEE ──────────────────────────────────────────────
 *
 * `accident_case_id` is UNIQUE, so one accident can back at most one charge invoice, enforced by
 * the database rather than by a service remembering to check. MySQL permits many NULLs in a unique
 * index, so every ordinary rental invoice is unaffected. A service-level check exists too and
 * returns the existing charge rather than erroring — but the index is what makes a double-charge
 * impossible under a race, which is the only condition where double-billing actually happens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('accident_case_id')->nullable()->after('contract_id')
                ->constrained('accident_cases')->nullOnDelete();
            // ONE charge per accident. @see the docblock — this is the real guard, not the service.
            $table->unique('accident_case_id', 'invoices_accident_case_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_accident_case_unique');
            $table->dropForeign(['accident_case_id']);
            $table->dropColumn('accident_case_id');
        });
    }
};
