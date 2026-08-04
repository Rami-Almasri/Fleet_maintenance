<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `intelligence_rebuild_runs` — the health record of the derived-table rebuilds.
 *
 * ── WHY THIS TABLE EXISTS ────────────────────────────────────────────────────────────────────────
 * Convergence concentrates risk. Six recurrence implementations meant six things could be wrong
 * independently; ONE means every recurrence figure in the platform is wrong together if the nightly
 * rebuild stops. That is the correct trade — a consistently wrong number is detectable, six
 * inconsistent ones are not — but it turns rebuild health from an operational nicety into a
 * production requirement.
 *
 * The failure this guards against has NO SYMPTOM otherwise: the tables keep serving, the pages keep
 * rendering, every number keeps looking authoritative, and the whole platform quietly answers out of
 * a corpus that stopped growing on whatever night the scheduler died. The scheduler is already known
 * dead on dev machines and unverified on the server.
 *
 * So every run — success or failure — writes a row, and Data Health reads them. A rebuild that never
 * ran leaves a gap that is visible; a rebuild that failed leaves a row that says so.
 *
 * Append-only. Rows are never updated after completion: a run's record is what happened, and
 * rewriting it would destroy the one trail that explains a stale number.
 *
 * @see config/metrics/recurrence.php  freshness
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('intelligence_rebuild_runs')) {
            return;
        }

        Schema::create('intelligence_rebuild_runs', function (Blueprint $table) {
            $table->id();

            $table->string('command', 60);              // intelligence:rebuild-recurrence
            $table->string('target_table', 60);         // fault_recurrence_pairs
            $table->string('status', 16);               // running | success | failed | validation_failed

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->unsignedInteger('rows_read')->nullable();     // source rows consumed
            $table->unsignedInteger('rows_written')->nullable();  // rows in the rebuilt table

            // The corpus edge this build saw — what `as_of` on every downstream KPI resolves to.
            $table->date('corpus_max_date')->nullable();

            // Which contract produced these rows. A historical run must always say which definition
            // it implemented, or a stale table becomes unattributable.
            $table->string('metric_version', 20)->nullable();

            // Validation failures abort before the swap, so the previous good table is still serving.
            // The reason is kept so the failure is diagnosable without re-running.
            $table->text('failure_reason')->nullable();

            $table->json('stats')->nullable();          // the command's full report

            $table->timestamps();

            $table->index(['command', 'started_at']);
            $table->index(['target_table', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_rebuild_runs');
    }
};
