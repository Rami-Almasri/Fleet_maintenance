<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture the contract window + rental terms carried on each OfficeManager invoice:
 *  - contract_out_date / contract_in_date: the visit dates AS BILLED — compared against
 *    contracts.out_date / in_date by the date-anomaly check (spot billing vs in/out drift).
 *  - rent_days / net_rate: RentDays + RentDayRate, for the contract Financials view.
 *  - car_serial: RaCarSerialNo — a reliable car link (CarNo is often null in the payload).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->date('contract_out_date')->nullable()->after('car_no');
            $table->date('contract_in_date')->nullable()->after('contract_out_date');
            // Signed: the API occasionally returns a NEGATIVE RentDays (its own in/out is
            // backwards) — stored raw rather than discarded, so the data stays faithful.
            $table->integer('rent_days')->nullable()->after('contract_in_date');
            $table->decimal('net_rate', 14, 2)->nullable()->after('rent_days'); // RentDayRate
            $table->unsignedBigInteger('car_serial')->nullable()->index()->after('net_rate');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['contract_out_date', 'contract_in_date', 'rent_days', 'net_rate', 'car_serial']);
        });
    }
};
