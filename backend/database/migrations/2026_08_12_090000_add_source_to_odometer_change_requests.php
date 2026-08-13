<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen the odometer approval queue so it also holds STAGE deviations, not just manual edits.
 *
 * Until now a maintenance-ticket reading that ran more than TOLERANCE_KM above the previous stage at an
 * at-our-park spot-check (the inspector's start-of-drive anchor, the final QA sign-off) was HARD-BLOCKED:
 * the driver was told "the car shouldn't have moved — re-check the dial" and could not proceed. But the
 * car sometimes really did move those 12 km (a yard shuffle, a quick fuel run, someone else moved it), and
 * re-reading the dial cannot make a true reading go away. Blocking it only taught people to type the
 * previous number.
 *
 * So the reading is now ACCEPTED with a mandatory note, and filed here for a supervisor to review after
 * the fact — the same board that already reviews significant manual odometer edits. These rows carry
 * `source = workflow_stage` plus the ticket + stage they came from, so the reviewer can tell a
 * before-the-fact request (manual edit, reading NOT yet applied) from an after-the-fact audit
 * (stage capture, reading ALREADY recorded on the ticket).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odometer_change_requests', function (Blueprint $table) {
            // Where this row came from: 'manual_edit' (the original Vehicles-form flow, reading held
            // pending) | 'workflow_stage' (a maintenance-ticket capture, reading already recorded).
            $table->string('source', 32)->default('manual_edit')->after('vehicle_id')->index();

            // The ticket + stage a workflow_stage row was captured at (null for a manual edit).
            $table->foreignId('maintenance_id')->nullable()->after('source')->constrained('maintenances')->nullOnDelete();
            $table->string('stage_key', 40)->nullable()->after('maintenance_id');
        });
    }

    public function down(): void
    {
        Schema::table('odometer_change_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('maintenance_id');
            $table->dropColumn(['source', 'stage_key']);
        });
    }
};
