<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Type every recurrence event as a FAULT or a SERVICE — metric contract v2.1.0.
 *
 * A recurring oil change is the service working, not the repair failing. Under v2.0.0 the corpus made
 * no distinction, so 1,073 fully-observed scheduled services were counted as repair failures at
 * 47.72% — above the 46.38% real faults recur at — inflating every garage's comeback rate.
 *
 * The rows are TYPED, not deleted. Rates scope to kind='fault'; the evidence drawer's Services tab
 * reads kind='service'. An exclusion nobody can see is indistinguishable from data going missing.
 *
 * Default 'fault' is safe: the rebuild restamps every row, and until it runs the table behaves
 * exactly as it did under v2.0.0 rather than silently emptying every rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fault_recurrence_pairs', function (Blueprint $table) {
            $table->string('kind', 12)->default('fault')->after('signature');

            // Every scoped read is (kind, days_observed) — the horizon filter travels with the kind
            // scope in base(), so a composite index serves both rather than one filtering after.
            $table->index(['kind', 'days_observed'], 'frp_kind_observed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fault_recurrence_pairs', function (Blueprint $table) {
            $table->dropIndex('frp_kind_observed_idx');
            $table->dropColumn('kind');
        });
    }
};
