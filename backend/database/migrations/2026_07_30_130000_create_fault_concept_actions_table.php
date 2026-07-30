<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which normalized repair actions typically resolve a fault concept.
 *
 * `keyword_profiles.repair_actions` already holds a JSON list, but it is free text — and free-text
 * repair actions are exactly what the Action Catalog exists to replace. "Replace brake pads",
 * "replace pads" and "brake pad replacement" are one action described three ways, and no analytics
 * can group them. This table carries the FK instead, so a fault's suggested repairs and a technician's
 * recorded repairs are drawn from the same vocabulary and can be compared directly.
 *
 * That comparison is the whole point: once both sides speak the same vocabulary, "which repairs
 * actually solve this complaint?" becomes a join rather than a research project.
 *
 * RELEVANCE, NOT CERTAINTY. `typical` is what usually fixes it; `possible` is what sometimes does.
 * Ranked rather than binary because the picker should open on the two or three actions a technician
 * probably needs, without hiding the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        // DRIFT GUARD — deliberately narrow, not a house style.
        //
        // On the live database this table already exists with 405 rows but was never recorded in
        // `migrations`, and it was created WITHOUT this migration's indexes, so it cannot have come from
        // here (most likely hand-built or a partially-applied run — MySQL DDL is non-transactional, so a
        // migration that dies mid-way leaves its tables behind with no ledger entry). Without this guard
        // `php artisan migrate` aborts on "table already exists" and blocks every later migration.
        //
        // The guard makes this a no-op where the table is present and a normal create where it is not, so
        // a fresh deploy and the drifted database converge. The missing indexes are then reconciled by
        // 2026_07_30_150000_repair_fault_concept_actions_indexes, which is where that fix belongs — this
        // migration stays the single canonical definition of the table's shape.
        //
        // Do NOT copy this pattern into new migrations. See [[migrations-cannot-run-from-empty]].
        if (Schema::hasTable('fault_concept_actions')) {
            return;
        }

        Schema::create('fault_concept_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('finding_keyword_id');
            $table->unsignedBigInteger('action_catalog_id');

            $table->string('relevance', 16)->default('typical');   // typical | possible
            $table->unsignedInteger('sort_order')->default(0);

            // Where the link came from. Seeded links are curated knowledge; `fleet` links would be
            // learned from what actually resolved this fault, and must be distinguishable from what
            // a person asserted — the same provenance rule as the rest of the platform.
            $table->string('source', 16)->default('seed');         // seed | human | fleet | ai
            $table->timestamps();

            // EXPLICIT index names. Laravel's generated name for the unique pair would be
            // `fault_concept_actions_finding_keyword_id_action_catalog_id_unique` — 65 characters, one
            // over MySQL/MariaDB's 64-char identifier limit, so this migration would fail on a FRESH
            // database too, not only a drifted one. That is why the live table exists without indexes.
            $table->unique(['finding_keyword_id', 'action_catalog_id'], 'fca_keyword_action_unique');
            $table->index(['finding_keyword_id', 'relevance'], 'fca_keyword_relevance_index');
            $table->index('action_catalog_id', 'fca_action_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fault_concept_actions');
    }
};
