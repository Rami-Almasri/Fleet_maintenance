<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `valid_days` was a stored copy of "registration days left". It's now calculated
 * on the fly from expiry_date (VehicleRegistration::getValidDaysAttribute), so the
 * stored column is redundant and can drift — drop it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_registrations', function (Blueprint $table) {
            $table->dropColumn('valid_days');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_registrations', function (Blueprint $table) {
            $table->integer('valid_days')->nullable()->after('expiry_date');
        });
    }
};
