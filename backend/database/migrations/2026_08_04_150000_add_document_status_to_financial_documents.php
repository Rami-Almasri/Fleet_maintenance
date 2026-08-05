<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every financial document gets a STATUS, and the stamps that make each status true.
 *
 * Until now a document's state was implicit — a supplier invoice existed, and that was all anyone could
 * say about it. As procurement grows that is not enough: the same invoice is a draft, then awaiting
 * approval, then approved, then paid, and "what have we actually committed to, and what do we still owe"
 * is unanswerable without saying which. So the two invoice tables gain the shared vocabulary defined in
 * {@see \App\Support\FinancialDocumentStatus}, plus the evidence behind each transition:
 *
 *     approved_by / approved_at            — who let it through
 *     paid_amount / paid_at / payment_ref  — what was actually settled, and against what reference
 *     cancelled_at / cancellation_reason   — why it stopped
 *
 * `paid_amount` is a running figure, not a flag, because part-payments are normal in supplier terms: the
 * PARTIALLY_PAID status is DERIVED from it rather than stored, so the status can never disagree with the
 * money. The same is true of PARTIALLY_REFUNDED, which is derived from part_returns.
 *
 * Backfill is deliberate and conservative: every existing row becomes APPROVED, not PAID. An invoice that
 * was recorded before this feature existed was certainly accepted as real, but nothing in the data says it
 * was ever paid, and inventing a payment would be exactly the unauditable figure this platform is trying
 * to eliminate.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['part_invoices', 'maintenance_invoices'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('status', 24)->default('draft')->index();

                $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $t->string('approved_by_name')->nullable();
                $t->timestamp('approved_at')->nullable();

                // What has actually been settled. Kept as an amount so a part-payment is representable;
                // PAID vs PARTIALLY_PAID is then derived and can never drift from it.
                $t->decimal('paid_amount', 12, 2)->default(0);
                $t->timestamp('paid_at')->nullable();
                $t->string('payment_reference', 120)->nullable();
                $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();

                $t->timestamp('cancelled_at')->nullable();
                $t->text('cancellation_reason')->nullable();
            });
        }

        // Existing documents were accepted as real when they were recorded — but were never PAID as far as
        // the data knows. APPROVED is the honest landing state.
        foreach (['part_invoices', 'maintenance_invoices'] as $table) {
            \Illuminate\Support\Facades\DB::table($table)->update(['status' => 'approved']);
        }

        Schema::table('cost_adjustments', function (Blueprint $t) {
            // An adjustment is approved at the moment it is created (the API demands an approver), so it
            // starts APPROVED and only ever moves to CANCELLED when reversed.
            $t->string('status', 24)->default('approved')->index();
        });
    }

    public function down(): void
    {
        foreach (['part_invoices', 'maintenance_invoices'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['approved_by']);
                $t->dropForeign(['paid_by']);
                $t->dropColumn([
                    'status', 'approved_by', 'approved_by_name', 'approved_at',
                    'paid_amount', 'paid_at', 'payment_reference', 'paid_by',
                    'cancelled_at', 'cancellation_reason',
                ]);
            });
        }

        Schema::table('cost_adjustments', function (Blueprint $t) {
            $t->dropColumn('status');
        });
    }
};
