<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture the "Cardoo" charge the OfficeManager /contracts row carries but our import
 * was dropping. Symptom: ContractDebit didn't reconcile with the mapped debit buckets —
 * e.g. contract #88510 had ContractDebit 1,204.70 while the buckets summed to 1,036.70.
 * The 168.00 gap is exactly CardooDebit / CardooPriceAmount, an itemised charge OM sends
 * (CardooDebit/CardooCredit, plus a CardooDepositAmount hold) that had no column here.
 *
 * Purely ADDITIVE and nullable. Existing rows backfill on the next om:sync. Naming mirrors
 * the existing *_debit / *_credit charge buckets so the debit/credit totals reconcile:
 *   contract_debit = sum(*_debit incl. cardoo_debit); same for credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->decimal('cardoo_debit', 12, 2)->nullable()->after('deposit_credit');
            $table->decimal('cardoo_credit', 12, 2)->nullable()->after('cardoo_debit');
            // A separate deposit/authorisation hold OM tracks under Cardoo (e.g. 7,000),
            // distinct from contract_deposit. Kept for completeness of the financial picture.
            $table->decimal('cardoo_deposit', 12, 2)->nullable()->after('cardoo_credit');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['cardoo_debit', 'cardoo_credit', 'cardoo_deposit']);
        });
    }
};
