<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture the discount + real billing period carried on each OfficeManager invoice:
 *  - discount / total_after_discount: the missing piece behind the "VAT math" false alarms —
 *    TotalAfterVat = (TotalValue − Discount) + VAT, so an unrecorded discount made value+vat
 *    look like it didn't add up. The VAT-math check is now discount-aware.
 *  - period_from / period_to: the invoice's real billing window (InvoiceperiodFrom/ToDate),
 *    used by the overlap/double-billing check instead of invoice_date + rent_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('discount', 14, 2)->nullable()->after('total_after_vat');
            $table->decimal('total_after_discount', 14, 2)->nullable()->after('discount');
            $table->date('period_from')->nullable()->after('total_after_discount');
            $table->date('period_to')->nullable()->after('period_from');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['discount', 'total_after_discount', 'period_from', 'period_to']);
        });
    }
};
