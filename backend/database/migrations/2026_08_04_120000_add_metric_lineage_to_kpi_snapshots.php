<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot lineage — a baseline that cannot say which definition produced it is unusable.
 *
 * ── WHY ──────────────────────────────────────────────────────────────────────────────────────────
 * The point of freezing a baseline is to argue from it later: "first-time fix was 59.4% before the
 * capture rollout, it is X now". That argument silently breaks the moment the METRIC changes rather
 * than the fleet — and on 2026-08-04 it did, when recurrence moved from counting label rows to
 * counting repair events. The stored 59.37% and the new 53.49% are not two measurements of the same
 * thing, and nothing in the row said so.
 *
 * So every snapshot now records the contract version that produced it, why it was taken, and which
 * snapshot it supersedes. A reader comparing two rows can see instantly whether they are comparable.
 *
 * ── THE OLD ROW IS KEPT ──────────────────────────────────────────────────────────────────────────
 * It is backfilled as version 1.0.0 rather than deleted or rewritten. A baseline you delete is a
 * baseline you cannot argue from, and rewriting history to make it agree with the present is the
 * opposite of traceability.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kpi_snapshots')) {
            return;
        }

        Schema::table('kpi_snapshots', function (Blueprint $table) {
            if (! Schema::hasColumn('kpi_snapshots', 'metric_version')) {
                $table->string('metric_version', 20)->nullable()->after('label');
            }
            if (! Schema::hasColumn('kpi_snapshots', 'reason')) {
                $table->text('reason')->nullable()->after('metric_version');
            }
            if (! Schema::hasColumn('kpi_snapshots', 'supersedes_snapshot_id')) {
                $table->unsignedBigInteger('supersedes_snapshot_id')->nullable()->after('reason');
            }
            if (! Schema::hasColumn('kpi_snapshots', 'commit_ref')) {
                $table->string('commit_ref', 60)->nullable()->after('supersedes_snapshot_id');
            }
        });

        // Backfill: everything already stored was taken under the retired raw-signature definition.
        //
        // ⚠ `captured_at` and `updated_at` are ASSIGNED EXPLICITLY, and that is the whole point.
        // `captured_at` is declared `ON UPDATE CURRENT_TIMESTAMP`, so any UPDATE that does not name
        // it silently rewrites the moment the baseline was actually taken — which this migration
        // exists to protect. MySQL skips the auto-update for a column the statement assigns, even
        // when the assigned value is the column's own. Caught in review after the first run moved a
        // 2026-07-30 capture to 2026-08-04; the restore below repairs that case.
        DB::table('kpi_snapshots')->whereNull('metric_version')->update([
            'metric_version' => '1.0.0',
            'reason'         => 'Taken under the retired raw-signature recurrence definition '
                . '(label rows counted as repairs, no observation horizon). '
                . 'Retained for history — NOT comparable with 2.0.0 figures. '
                . 'See docs/Metric-Specification-Recurrence.md §10.',
            'captured_at'    => DB::raw('captured_at'),
            'updated_at'     => DB::raw('updated_at'),
        ]);

        // Repair any row whose capture time was already clobbered by an earlier run of this
        // migration. `created_at` is a plain timestamp with no auto-update, so it still holds the
        // true moment. Restoring history is in scope here precisely because destroying it was ours.
        DB::statement('
            UPDATE kpi_snapshots
               SET captured_at = created_at,
                   updated_at  = updated_at
             WHERE created_at IS NOT NULL
               AND captured_at > created_at
               AND metric_version = ?', ['1.0.0']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('kpi_snapshots')) {
            return;
        }

        Schema::table('kpi_snapshots', function (Blueprint $table) {
            foreach (['metric_version', 'reason', 'supersedes_snapshot_id', 'commit_ref'] as $col) {
                if (Schema::hasColumn('kpi_snapshots', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
