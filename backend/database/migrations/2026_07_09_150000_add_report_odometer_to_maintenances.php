<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The odometer reading the inspector captures at the Decide step — the reading when the TEST DRIVE ENDS
 * (distinct from `test_odometer`, the start-of-drive anchor). Optional. Its own column so it lands as a
 * distinct "End of test drive" row in the vehicle mileage timeline, alongside the other capture points.
 * Additive + nullable — every legacy/other-path ticket is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->unsignedInteger('report_odometer')->nullable()->after('test_odometer');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('report_odometer');
        });
    }
};
