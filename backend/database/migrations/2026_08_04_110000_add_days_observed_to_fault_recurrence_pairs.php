<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `days_observed` — how long each fault event has been watched for a recurrence.
 *
 * ── THE BIAS THIS CORRECTS ───────────────────────────────────────────────────────────────────────
 * A repair completed last week cannot have come back within 90 days yet. Counting it as a repair
 * that HELD is a free pass, and not an evenly distributed one: it flatters the busiest garages most,
 * because they have the most recent work. Right-censoring.
 *
 * The existing GarageScorecardService already corrected for this and OperationalKpiService did not,
 * which is one of the three reasons the platform published three different fleet comeback rates.
 * Promoting the correction into the canonical dataset is what lets every consumer inherit it.
 *
 * ── WHY A STORED COLUMN AND NOT A QUERY-TIME FILTER ──────────────────────────────────────────────
 * The horizon depends on MAX(occurred_at) across the whole corpus, which moves every rebuild.
 * Computing it per query would mean every caller re-deriving the corpus max — and the moment two
 * callers derive it differently (CURDATE() vs corpus max, exactly what happened before) the metric
 * forks again. Stamped at build time it is a snapshot fact: auditable, uniform, and cheap to filter.
 *
 * One column serves every window: `days_observed >= 30 | 60 | 90`.
 *
 * Nullable so the migration is safe on an existing table; the rebuild fills it, and a golden test
 * asserts zero nulls survive a rebuild.
 *
 * @see config/metrics/recurrence.php  observation_horizon
 * @see docs/Metric-Specification-Recurrence.md  §4
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fault_recurrence_pairs')) {
            return;
        }

        if (! Schema::hasColumn('fault_recurrence_pairs', 'days_observed')) {
            Schema::table('fault_recurrence_pairs', function (Blueprint $table) {
                $table->unsignedSmallInteger('days_observed')->nullable()->after('days_to_return');

                // Every governed read filters on this, so it earns an index of its own.
                $table->index('days_observed');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fault_recurrence_pairs', 'days_observed')) {
            Schema::table('fault_recurrence_pairs', function (Blueprint $table) {
                $table->dropIndex(['days_observed']);
                $table->dropColumn('days_observed');
            });
        }
    }
};
