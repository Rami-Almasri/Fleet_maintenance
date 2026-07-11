<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Readiness-checklist fields for the Pre-Delivery Readiness Gate.
 *
 * The gate (VehicleReadinessService) already reads condition_grade, the open workflow ticket,
 * registration/insurance expiry and flagged-but-unreviewed damage from tables that exist. These
 * two columns close the last two checklist items the gate needs its own home for:
 *
 *   - cleaning_status  : the manual "is the car clean enough to hand over" flag
 *                        (clean | dirty | pending; null = not yet assessed → a soft warning).
 *   - gps_last_seen_at : when the car's tracker last reported. There is NO live telematics feed
 *                        today, so this stays a SOFT (non-blocking) check — it surfaces "GPS not
 *                        reporting" without ever grounding a car, and is ready to harden the day a
 *                        feed lands. See the Rev.11B GPS dependency in the lifecycle plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('cleaning_status', 20)->nullable()->after('condition_graded_by');
            $table->timestamp('gps_last_seen_at')->nullable()->after('cleaning_status');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['cleaning_status', 'gps_last_seen_at']);
        });
    }
};
