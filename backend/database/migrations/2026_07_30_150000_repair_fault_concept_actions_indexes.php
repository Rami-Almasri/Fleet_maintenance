<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrective migration — reconcile `fault_concept_actions` with its intended shape.
 *
 * The table exists on the live database with real data but was created outside the migration that
 * defines it, and arrived with only its PRIMARY KEY: none of the three indexes the create migration
 * specifies were ever applied. That is not cosmetic. The missing UNIQUE(finding_keyword_id,
 * action_catalog_id) is the only thing stopping the same fault→action link being inserted twice, which
 * would quietly double-count a suggested repair in the picker and in any analytics that joins through
 * this table.
 *
 * Written as a corrective migration rather than by editing the create migration or hand-inserting a
 * `migrations` row, because those fix one database and leave every other environment (laravel_test,
 * staging, production) silently different. This runs everywhere, checks before it acts, and is a no-op
 * on any database that already has the indexes — including a fresh deploy, where the create migration
 * has just added them.
 *
 * Verified before writing: 0 duplicate (finding_keyword_id, action_catalog_id) pairs and 0 orphaned FKs
 * across the 405 live rows, so the unique index applies without data loss. If that ever stops being
 * true the migration fails loudly rather than dropping rows — deduplicating production data is a
 * decision for a human, not a side effect of a deploy.
 *
 * See [[migrations-cannot-run-from-empty]] and [[mariadb-local-mysql8-prod]].
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fault_concept_actions')) {
            return;   // nothing to repair
        }

        // Fail loudly rather than let a unique index silently reject rows on a database we have not seen.
        $dupes = DB::table('fault_concept_actions')
            ->select('finding_keyword_id', 'action_catalog_id')
            ->groupBy('finding_keyword_id', 'action_catalog_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($dupes > 0 && ! $this->hasIndex('fca_keyword_action_unique')) {
            throw new RuntimeException(
                "Cannot add UNIQUE(finding_keyword_id, action_catalog_id) to fault_concept_actions: {$dupes} "
                . 'duplicate pair(s) present. Resolve the duplicates deliberately, then re-run this migration.'
            );
        }

        // Names are given explicitly and must match the create migration exactly — the generated name for
        // the unique pair exceeds the 64-character identifier limit, which is the original defect here.
        Schema::table('fault_concept_actions', function (Blueprint $table) {
            if (! $this->hasIndex('fca_keyword_action_unique')) {
                $table->unique(['finding_keyword_id', 'action_catalog_id'], 'fca_keyword_action_unique');
            }
            if (! $this->hasIndex('fca_keyword_relevance_index')) {
                $table->index(['finding_keyword_id', 'relevance'], 'fca_keyword_relevance_index');
            }
            if (! $this->hasIndex('fca_action_index')) {
                $table->index('action_catalog_id', 'fca_action_index');
            }
        });
    }

    public function down(): void
    {
        // Intentionally not dropping the indexes: they are the table's intended shape, and rolling this
        // migration back should not reintroduce the defect it exists to fix.
    }

    /** Index presence via information_schema — works on both MariaDB (local) and MySQL 8 (production). */
    private function hasIndex(string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'fault_concept_actions')
            ->where('index_name', $index)
            ->exists();
    }
};
