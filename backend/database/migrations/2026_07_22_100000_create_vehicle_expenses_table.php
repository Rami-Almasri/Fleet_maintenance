<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * vehicle_expenses — the imported expense ledger, the SOLE source of vehicle expense in FleetView.
 *
 * One row per source line (e.g. one Excel voucher-detail line: date, remarks, debit, credit). The
 * per-vehicle expense = Σ amount (amount = debit − credit). Storing lines (not just a per-car total)
 * lets the expense drawer render the exact remarks/date/amount history and lets windowed metrics
 * (trailing-window yield, cost-by-period) filter by entry_date — all from this one table.
 *
 * `source` tags where a row came from ('excel' today, 'odoo' later) so a re-import replaces only its
 * own source cleanly. Join key to FleetView vehicles is car_serial (resolved to vehicle_id at import).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('car_serial')->index();          // OM CarSerial from the sheet
            $table->unsignedBigInteger('vehicle_id')->nullable()->index(); // resolved FleetView vehicle (null = unmatched)
            $table->date('entry_date')->nullable()->index();            // the voucher / expense date
            $table->string('account_type')->nullable();                 // e.g. Expence / xExpence
            $table->text('remarks')->nullable();                        // the free-text line label (CHANGE OIL, ENOC (PETROL)…)
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);               // debit − credit (the signed expense)
            $table->string('source')->default('excel')->index();        // 'excel' now, 'odoo' later
            $table->timestamp('imported_at')->nullable();               // when this batch was loaded (source as-of)
            $table->timestamps();

            $table->index(['source', 'vehicle_id']);
            $table->index(['source', 'car_serial']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_expenses');
    }
};
