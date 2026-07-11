<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet Maintenance Workflow — stage-timing anchors.
 *
 * The lifecycle already stamps WHO advanced each handoff and WHEN (inspected_at, dispatched_at,
 * ready_at, …). These two columns are the dedicated time anchors the downtime model reads, named
 * for the moment they mark so the duration maths is self-documenting:
 *
 *   test_started_at  — the inspector begins the test drive / diagnostic   (Stage 1 / Stage 0→1)
 *   dispatched_at    — the car leaves for the garage   (ALREADY exists from the workflow migration)
 *   returned_at      — the car comes back from the garage, ready for re-inspection   (UC-5)
 *
 * Stage durations are then pure subtraction:
 *   Test Drive   = dispatched_at − test_started_at
 *   At Garage    = returned_at   − dispatched_at
 *   Total downtime = returned_at − test_started_at
 *
 * Every column is nullable + additive; the service writes them atomically inside the same
 * transaction as the status change, so the timestamp can never drift from the stage it records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            // dispatched_at already exists (workflow migration); only the two endpoints are new.
            $table->timestamp('test_started_at')->nullable()->after('inspected_at');
            $table->timestamp('returned_at')->nullable()->after('ready_at');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn(['test_started_at', 'returned_at']);
        });
    }
};
