<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VAT and discount, on both kinds of paper.
 *
 * A ticket must separate parts, labour, VAT, discounts, refunds and the net total — and every one of those
 * bands has to come off a real document. So VAT and discount are stored where the document is:
 *
 *     maintenance_invoices : vat_total / discount_total   (the garage's bill)
 *     part_invoices        : discount_amount              (the supplier's bill; VAT already = tax_amount)
 *
 * These header columns are DERIVED, not keyed twice. A garage invoice's VAT and discount are written as
 * maintenance_line_items rows (kind = vat / discount) exactly like its parts and labour, so the ticket
 * total remains the sum of ONE ledger and every band traces to the same invoice. The columns here are the
 * per-invoice roll-up of those lines, mirroring how parts_total / labor_total already work.
 *
 * `amount` therefore becomes parts + labor + vat + discount (discount being negative). For every existing
 * invoice both new columns are 0, so `amount` is unchanged — this migration moves no money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_invoices', function (Blueprint $table) {
            // Roll-ups of this invoice's vat / discount lines. Discount is stored NEGATIVE, so that
            // amount = parts_total + labor_total + vat_total + discount_total is a plain sum everywhere.
            $table->decimal('vat_total', 12, 2)->default(0)->after('labor_total');
            $table->decimal('discount_total', 12, 2)->default(0)->after('vat_total');
        });

        Schema::table('part_invoices', function (Blueprint $table) {
            // Supplier discount, stored POSITIVE and subtracted in the total (the supplier states it as a
            // deduction on the paper, so the form mirrors the paper). VAT is the existing tax_amount.
            $table->decimal('discount_amount', 12, 2)->default(0)->after('tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_invoices', function (Blueprint $table) {
            $table->dropColumn(['vat_total', 'discount_total']);
        });

        Schema::table('part_invoices', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });
    }
};
