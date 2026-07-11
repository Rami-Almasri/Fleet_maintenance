<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track A — surface live rental-invoice payment status.
 *
 * The OfficeManager /invoices records already carry the settlement state, but our
 * import discarded it. These columns persist it so the dashboard can show
 * paid / partial / not_paid (a "Pending" flag) straight from synced data — no manual
 * Excel sheet. Derived at import time from the OM row:
 *   balance_value = OM BalanceValue (amount still outstanding on the invoice; 0 = settled)
 *   paid_amount   = CashPaid + ChequePaid + VisaPaid (actual money received)
 *   status_no     = OM StatusNo (raw posting/confirmation code, kept verbatim)
 *   payment_status= 'paid' | 'partial' | 'not_paid' (derived — the actionable axis)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedSmallInteger('status_no')->nullable()->after('total_after_vat');
            $table->decimal('balance_value', 14, 2)->nullable()->after('status_no');
            $table->decimal('paid_amount', 14, 2)->nullable()->after('balance_value');
            $table->string('payment_status', 12)->nullable()->index()->after('paid_amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['payment_status']);
            $table->dropColumn(['status_no', 'balance_value', 'paid_amount', 'payment_status']);
        });
    }
};
