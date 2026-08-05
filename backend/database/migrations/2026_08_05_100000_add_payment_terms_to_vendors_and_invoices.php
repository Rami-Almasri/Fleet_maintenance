<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment terms — so "overdue" means late against what we AGREED, not merely old.
 *
 * Payables were aged from the invoice date, which treats every supplier identically: a 45-day-old bill
 * from a net-60 supplier looked worse than a 20-day-old bill from one demanding payment on receipt. That
 * is precisely backwards, and it is the figure people chase from.
 *
 * So terms live on the VENDOR (the agreement is with them, not with each bill), and each invoice stamps
 * the `due_date` it inherited at the moment it became payable. Stamping rather than always recomputing
 * matters: renegotiating terms next year must not silently rewrite whether last year's bill was paid late.
 *
 * `payment_terms_days = 0` is "due on receipt" and is a real answer, so the column is nullable to mean
 * "no terms agreed" — those bills fall back to the invoice date, exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            // Net days from the invoice date. Null = no agreed terms (fall back to due-on-receipt).
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('default_lead_time_days');
            $table->string('payment_terms_note', 191)->nullable()->after('payment_terms_days');
        });

        foreach (['part_invoices', 'maintenance_invoices'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                // Stamped from the vendor's terms when the bill becomes payable — a snapshot, so later
                // renegotiation cannot rewrite history.
                $t->date('due_date')->nullable()->after('status');
                $t->unsignedSmallInteger('terms_days')->nullable()->after('due_date');
                $t->index('due_date');
            });
        }
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['payment_terms_days', 'payment_terms_note']);
        });

        foreach (['part_invoices', 'maintenance_invoices'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['due_date']);
                $t->dropColumn(['due_date', 'terms_days']);
            });
        }
    }
};
