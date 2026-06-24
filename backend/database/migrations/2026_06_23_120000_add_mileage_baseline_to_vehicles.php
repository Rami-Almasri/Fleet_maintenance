<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global Mileage Baseline anchor on `vehicles`.
 *
 * The branch records the odometer at every handover (out_milage / in_milage on each
 * contract), so the EARLIEST valid out_milage a car ever had is its true "Start-Mileage".
 * We persist that anchor here — separate from the hand-edited `odometer` (which keeps
 * getting typo'd to 0/1/extra-digit) — so the car card can show where the car began and the
 * scanner has a stable reference. The live `odometer` is then healed FROM contract history
 * by MileageBaselineService; only this baseline column is new (additive, nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // Earliest valid out_milage ever recorded for the car (the "Start-Mileage" anchor).
            $table->unsignedInteger('baseline_odometer')->nullable()->after('service_synced_at');
            // When MileageBaselineService last (re)computed the baseline for this car.
            $table->timestamp('baseline_synced_at')->nullable()->after('baseline_odometer');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['baseline_odometer', 'baseline_synced_at']);
        });
    }
};
