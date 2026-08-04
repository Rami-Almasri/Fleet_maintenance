<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A checkpoint now records the supervisor's ANSWER to the daily question, not just the resulting date:
 *
 *   confirmed   — "yes, the car still comes back on the date we promised"  (date unchanged)
 *   rescheduled — "no, it moved" (+ the mandatory delay_reason)
 *
 * Before this, "confirmed" was only inferable by comparing previous_expected_date to next_expected_date,
 * which made the reason history unreadable ("how many times did this car slip, and why each time?").
 * Storing the answer explicitly makes every response countable — the point of the daily chase.
 *
 * Backfilled from the dates already on file, so historic rows read the same way.
 * See [[maintenance-checkpoint-feature]].
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('maintenance_checkpoints', 'response')) {
            Schema::table('maintenance_checkpoints', function (Blueprint $table) {
                $table->string('response', 16)->nullable()->after('summary');
                $table->index('response');
            });
        }

        // Backfill: the date moved → rescheduled; it held → confirmed.
        DB::table('maintenance_checkpoints')->whereNull('response')->update([
            'response' => DB::raw(
                "CASE WHEN previous_expected_date IS NOT NULL"
                . " AND next_expected_date IS NOT NULL"
                . " AND previous_expected_date <> next_expected_date"
                . " THEN 'rescheduled' ELSE 'confirmed' END"
            ),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('maintenance_checkpoints', 'response')) {
            Schema::table('maintenance_checkpoints', function (Blueprint $table) {
                $table->dropIndex(['response']);
                $table->dropColumn('response');
            });
        }
    }
};
