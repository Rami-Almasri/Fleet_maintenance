<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The paper behind a stock receipt.
 *
 * Putting a part on the shelf is a MONEY EVENT — someone bought it, from someone, for a price, on a
 * document. Until now the storehouse recorded the price and forgot the document, which made the
 * shelf's cost an assertion with nothing behind it: nobody could answer "who did we buy these ten
 * filters from, and where is the bill?" a month later.
 *
 * The document is a {@see \App\Models\PartInvoice} — the SAME supplier bill that already backs a part
 * bought for a car, not a second kind of paper. That is deliberate: one supplier trip commonly buys
 * some parts for a car in the workshop and some for the shelf, on ONE invoice, and the system must be
 * able to record that as one invoice. `PartInvoice::recalcTotals()` therefore sums both its purchases
 * and its store receipts.
 *
 * NULLABLE, because two legitimate in-movements have no invoice and never will: the OPENING COUNT
 * (stock that was already on the shelf the day this feature shipped — its paper, if it ever existed,
 * is not ours to invent) and a part coming back unused from a job (already paid for on the receipt
 * that first brought it in). A `receipt` without one is refused at the service, not by the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_movements', function (Blueprint $table) {
            $table->foreignId('part_invoice_id')->nullable()->after('supplier_vendor_id')
                ->constrained('part_invoices')->nullOnDelete();

            $table->index(['part_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::table('store_movements', function (Blueprint $table) {
            $table->dropForeign(['part_invoice_id']);
            $table->dropIndex(['part_invoice_id']);
            $table->dropColumn('part_invoice_id');
        });
    }
};
