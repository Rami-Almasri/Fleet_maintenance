<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHEN WORK ACTUALLY STARTED ON THIS FAULT — the per-attempt work clock.
 *
 * Why this column has to exist: a stint's `assigned_at` is stamped at DISPATCH, which is one shared
 * moment for every fault on the ticket. Measuring a fault from `assigned_at` therefore hands all of a
 * car's faults the same number — the vehicle's workshop time replicated per fault, which is precisely
 * what the per-fault requirement forbids.
 *
 * `work_started_at` is stamped by genuinely PER-FAULT events instead:
 *   • the workshop CONFIRMATION verdict (MaintenanceTaskService::confirmFault) — the technician looks
 *     at this specific fault and rules it real. Different faults get confirmed at different times.
 *   • an explicit move to in_progress (setStatus) — for flows where confirmation is skipped.
 * Write-once per stint (fill-if-null): the first real work signal of the attempt wins, so re-confirming
 * or bouncing a fault through in_progress never restarts its clock.
 *
 * A fault that never received either signal keeps a NULL here, and the read layer reports its time on
 * the `custody` basis (assigned_at → released_at) while SAYING SO — an honest, clearly-labelled
 * fallback rather than a shared number dressed up as per-fault measurement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_task_assignments', function (Blueprint $table) {
            // dateTime, NOT timestamp — MariaDB silently attaches ON UPDATE CURRENT_TIMESTAMP to
            // timestamp columns, which would rewrite this stored moment on every later row touch.
            $table->dateTime('work_started_at')->nullable()->after('assigned_at');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_task_assignments', function (Blueprint $table) {
            $table->dropColumn('work_started_at');
        });
    }
};
