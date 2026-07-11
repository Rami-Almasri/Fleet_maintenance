<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Odometer Continuity Rules — store the per-stage continuity verdict on each workflow ticket.
 *
 * Keyed by capture stage (test_drive | dispatch | receive | return), each value is the flag produced by
 * OdometerContinuityService::evaluate() — {status, previous, reading, delta, tolerance}. The board/drawer
 * read this to surface a "Discrepancy" badge so a Supervisor spots a bad reading at a glance. Nullable:
 * legacy tickets and un-reached stages simply carry no verdict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->json('odometer_flags')->nullable()->after('return_odometer');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('odometer_flags');
        });
    }
};
