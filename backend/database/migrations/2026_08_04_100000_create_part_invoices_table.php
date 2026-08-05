<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * part_invoices — the SUPPLIER's bill for parts we bought ourselves.
 *
 * This is the missing half of the cost journey. A ticket already knows what the GARAGE charged
 * (maintenance_invoices — labour, and any part the garage itself supplied). It had no way to record what a
 * PARTS SUPPLIER charged: part_purchases carried a bare price with no document, no number, no date and no
 * photo, so a purchase could not be proved against paper.
 *
 * Deliberately SUPPLIER-ONLY. A part the repairing garage provides is already a line on that garage's
 * maintenance invoice; giving it a second document here would double-count it on the ticket. The rule is
 * enforced in PartInvoiceService, not merely documented:
 *
 *     purchase_source = supplier  →  part_invoice (this table)  — a separate financial event
 *     purchase_source = garage    →  maintenance_invoice line   — no part invoice, ever
 *
 * One invoice covers MANY purchases (one supplier trip, several parts), which is why the money lives on a
 * header here and the line detail stays on part_purchases. `subtotal` is derived from the attached
 * purchases; `stated_total` is what the paper says; a gap over a cent must be explained — the same
 * variance gate maintenance_invoices already enforces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_invoices', function (Blueprint $table) {
            $table->id();

            // WHO billed us. A vendor row (type parts_supplier) when we have one; supplier_name is the
            // free-text fallback for a one-off shop we don't keep on file.
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('supplier_name')->nullable();

            // The document itself.
            $table->string('invoice_no', 120)->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('currency', 3)->default('AED');

            // Money. subtotal is DERIVED (sum of the attached purchases); tax + total are keyed.
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);

            // The printed grand total, and the mandatory note when it disagrees with the parts we attached.
            $table->decimal('stated_total', 12, 2)->nullable();
            $table->text('variance_explanation')->nullable();

            // The photo of the paper — the evidence the whole feature exists for.
            $table->string('photo_disk', 20)->nullable();
            $table->string('photo_key')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_by_name')->nullable();
            $table->timestamp('recorded_at')->nullable();

            $table->timestamps();

            $table->index(['vendor_id', 'invoice_date']);
            $table->index('invoice_no');
        });

        Schema::table('part_purchases', function (Blueprint $table) {
            // The supplier bill this purchase appears on. Null while the buy is logged but the paper hasn't
            // been keyed yet, and null forever for a garage-sourced part (which bills on the garage invoice).
            $table->foreignId('part_invoice_id')->nullable()->after('po_number')
                ->constrained('part_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('part_purchases', function (Blueprint $table) {
            $table->dropForeign(['part_invoice_id']);
            $table->dropColumn('part_invoice_id');
        });

        Schema::dropIfExists('part_invoices');
    }
};
