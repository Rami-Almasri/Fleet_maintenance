<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE ACTIVE-WORK LEDGER — the interval a technician was actually working on ONE fault.
 *
 * Why this table has to exist: until now a fault's work time was a single stamp
 * (`maintenance_task_assignments.work_started_at`) measured to `released_at`. That window is ELAPSED
 * SHOP TIME for the fault, not labor. The real shape of a repair is:
 *
 *      10:00 car arrives           (custody starts — assigned_at/arrived_at)
 *      11:00 technician starts     ← work session #1 opens
 *      12:00 out of parts          ← work #1 closes, blocked(parts) opens
 *      16:00 part arrives          ← blocked closes, work session #2 opens
 *      17:00 done                  ← work #2 closes, stint released
 *
 * Active work = 2h + 1h = 3h. Elapsed work window = 6h. Custody = 7h. Only the first is labor.
 * Without this ledger the system reports 6h and cannot say a word about WHY the other 4h passed.
 *
 * A row is one interval of one KIND:
 *   • work    — hands on this fault.
 *   • blocked — the fault could not progress, with the reason (parts / approval / customer /
 *               other_workshop / other). This is what makes "how long were we waiting for parts?"
 *               answerable per fault instead of guessed from the ticket.
 *
 * INVARIANT: at most ONE open session (ended_at IS NULL) per fault at any moment. MySQL cannot express
 * that as a partial unique index, so FaultWorkSessionService enforces it inside a transaction with
 * `lockForUpdate()` on the task's sessions — the lock IS the guard against a double-tap opening two.
 *
 * The session belongs to the STINT it happened in (`maintenance_task_assignment_id`) so per-garage
 * analytics stay possible after a transfer: garage A's active hours and garage B's are separate rows
 * inside the same repair attempt. Nullable only for an on-site fault, which has no stint at all.
 *
 * dateTime(), NOT timestamp() — MariaDB silently attaches ON UPDATE CURRENT_TIMESTAMP to the first
 * timestamp column of a table, which would rewrite `started_at` every time the row is closed. That is
 * exactly the bug 2026_06_30_150000 had to repair on the stint table; we do not repeat it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_task_work_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_task_id')->constrained('maintenance_tasks')->cascadeOnDelete();
            // The stint this interval happened in. Null = on-site fault (no garage stint exists).
            // The FK is named explicitly: Laravel's generated name for this column on this table is 69
            // characters, past MySQL's 64-char identifier limit.
            $table->unsignedBigInteger('maintenance_task_assignment_id')->nullable();
            $table->foreign('maintenance_task_assignment_id', 'mtws_stint_fk')
                ->references('id')->on('maintenance_task_assignments')->nullOnDelete();

            // 'work' = active labor on this fault. 'blocked' = it could not progress, and why.
            $table->string('kind', 10);
            $table->string('block_reason', 30)->nullable();

            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();   // null = the session running NOW

            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();

            // Provenance — a session synthesised by the backfill from a legacy work_started_at is NOT
            // the same evidence as one a technician actually clocked, and analytics must be able to
            // tell them apart rather than averaging measured and reconstructed time together.
            $table->string('source', 20)->default('live');  // live | backfill

            $table->timestamps();

            // Short explicit names — the defaults blow past MySQL's 64-char identifier limit.
            $table->index(['maintenance_task_id', 'started_at'], 'mtws_task_started_idx');
            $table->index(['maintenance_task_id', 'ended_at'], 'mtws_task_open_idx');   // the open-session lookup
            $table->index(['maintenance_task_assignment_id', 'kind'], 'mtws_stint_kind_idx');
        });

        Schema::table('maintenance_task_assignments', function (Blueprint $table) {
            // WHAT the recorded labor_hours was checked against, so a reader can tell a measured value
            // from one we could only take on trust:
            //   measured      — validated against this attempt's active work-session total.
            //   legacy_window — no sessions; validated against work_started_at → released_at.
            //   declared      — no work timeline at all; accepted but NOT evidence of measured labor.
            //   override      — deliberately exceeded the ceiling under maintenance.labor.override.
            $table->string('labor_basis', 20)->nullable()->after('labor_hours');
            // Set only for `override`: who authorised exceeding the ceiling and why. The audit event
            // carries the same facts; this column keeps them queryable next to the number itself.
            $table->text('labor_override_reason')->nullable()->after('labor_basis');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_task_assignments', function (Blueprint $table) {
            $table->dropColumn(['labor_basis', 'labor_override_reason']);
        });
        Schema::dropIfExists('maintenance_task_work_sessions');
    }
};
