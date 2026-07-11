<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service Log: anchor an invoice to the car the work was done on (denormalised from the contract
 * for fast per-vehicle history) and the garage that did it. Both nullable — legacy/rental invoices
 * carry neither. The financial columns are untouched (kept in the DB, just hidden in the UI).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('vehicle_id')->nullable()->after('contract_id')->index();
            $table->unsignedBigInteger('vendor_id')->nullable()->after('vehicle_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['vehicle_id', 'vendor_id']);
        });
    }
};
