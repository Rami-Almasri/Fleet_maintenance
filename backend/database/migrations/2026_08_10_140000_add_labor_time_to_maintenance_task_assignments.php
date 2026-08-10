<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-ATTEMPT manual labor time lives on the STINT, not on the fault row.
 *
 * A repair attempt is the run of consecutive stints ending in a terminal outcome (resolved /
 * failed_reinspection / cancelled); the stint that ENDS an attempt carries that attempt's human-entered
 * "actual mechanic time" (labor_hours). Closed stints are never rewritten, so attempt history is
 * immutable by construction — a second Make Ready after a failed re-inspection lands on the NEW
 * attempt's stint and can never overwrite attempt #1 (the bug the findings-JSON path had).
 *
 * Distinct metrics, kept separate on purpose:
 *   • elapsed  — wall-clock assigned_at → released_at (garage custody incl. transit) — DERIVED, no column.
 *   • labor    — what the mechanic actually spent — a human FACT, this column, write-once per attempt.
 * `maintenance_tasks.repair_hours` becomes a derived CACHE of Σ(stint labor) — see MaintenanceTask.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_task_assignments', function (Blueprint $table) {
            $table->decimal('labor_hours', 6, 2)->nullable()->after('reason');
            $table->foreignId('labor_recorded_by')->nullable()->after('labor_hours')
                ->constrained('users')->nullOnDelete();
            // dateTime, NOT timestamp — MariaDB silently gives timestamp columns ON UPDATE
            // CURRENT_TIMESTAMP, which would rewrite this stored moment on every row touch.
            $table->dateTime('labor_recorded_at')->nullable()->after('labor_recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_task_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('labor_recorded_by');
            $table->dropColumn(['labor_hours', 'labor_recorded_at']);
        });
    }
};
