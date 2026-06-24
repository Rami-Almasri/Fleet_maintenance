<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hybrid-to-Native billing: let the WEBSITE be the source of truth going forward
 * while the OfficeManager sync keeps the legacy history intact.
 *
 *  - invoices.invoice_no becomes NULLABLE: OfficeManager owns the integer InvoiceNo
 *    sequence, so a manual invoice created on the site must NOT borrow one. Manual
 *    invoices carry no invoice_no and are identified by a human ref instead.
 *  - invoices.invoice_ref: the website's own invoice number, e.g. "M-0001" (M = manual),
 *    unique and obviously FleetView-originated, so it can never collide with OM's ids.
 *  - invoices.notes: free-text context for a hand-entered invoice.
 *  - payments: the credit/collection side the platform never had — what was actually
 *    received against a contract (and optionally a specific invoice). Contract balance
 *    going forward = sum(invoices.total_after_vat) − sum(payments.amount).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // OM no longer owns every row — manual invoices leave this null.
            $table->unsignedBigInteger('invoice_no')->nullable()->change();
            // Website-issued number for manual invoices (e.g. M-0001). Unique so it can
            // never clash with another manual ref; api rows leave it null.
            $table->string('invoice_ref')->nullable()->unique()->after('invoice_no');
            $table->text('notes')->nullable()->after('origin');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // Website-issued receipt number, e.g. P-0001.
            $table->string('payment_ref')->nullable()->unique();
            // Every payment is anchored to a contract; the invoice link is optional
            // (a payment may settle one invoice, or just sit against the contract).
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->decimal('amount', 14, 2)->default(0);
            $table->date('paid_on')->nullable();
            $table->string('method')->nullable();      // cash | card | bank_transfer | cheque | online | other
            $table->string('reference')->nullable();   // external txn id / cheque no
            $table->text('notes')->nullable();
            $table->string('recorded_by')->nullable(); // the user who entered it
            $table->string('origin')->default('manual');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['invoice_ref']);
            $table->dropColumn(['invoice_ref', 'notes']);
            // Best-effort revert; only valid if no NULL invoice_no rows remain.
            $table->unsignedBigInteger('invoice_no')->nullable(false)->change();
        });
    }
};
