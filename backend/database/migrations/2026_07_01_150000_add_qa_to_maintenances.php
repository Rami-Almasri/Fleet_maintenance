<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quality-Assurance gate for complex repairs (Engine / Electrical / Mechanical).
 *
 * When the garage marks such a repair "ready", the ticket is routed to a PENDING_QA stage instead of
 * straight to re-inspection: Abu Maroof (the inspector) logs one or more QA Test Rounds — each a road/
 * bench test with an odometer photo + a pass/fail note — and only a FINAL APPROVE returns the car to
 * service. A REJECT sends it back for re-dispatch to the garage. See [[reinspection-qc-layer]].
 *
 *   qa_rounds      — append-only JSON log of the test rounds (mirrors follow_ups): each
 *                    {round, label, result, odometer, note, photo_id, by, by_id, at}.
 *   qa_started_at  — when the ticket entered PENDING_QA (the "Days in QA" clock starts).
 *   qa_completed_at— when Abu Maroof signed off / rejected (the clock stops).
 *   requires_qa    — locked at markReady: this repair needed the QA gate (so the routing decision is
 *                    auditable and the UI can badge it even after close).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->boolean('requires_qa')->default(false)->after('odometer_flags');
            $table->json('qa_rounds')->nullable()->after('requires_qa');
            $table->timestamp('qa_started_at')->nullable()->after('qa_rounds');
            $table->timestamp('qa_completed_at')->nullable()->after('qa_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn(['requires_qa', 'qa_rounds', 'qa_started_at', 'qa_completed_at']);
        });
    }
};
