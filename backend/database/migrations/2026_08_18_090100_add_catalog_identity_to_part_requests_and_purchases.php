<?php

use App\Services\PartCatalogMatcher;
use App\Services\PartIdentityService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a part request and a part purchase an IDENTITY, instead of only a string someone typed.
 *
 * Both tables recorded `part_name` as free text and nothing else, so "which part is this?" could only
 * ever be answered by comparing characters. That is why buying an "Alternator" for a car that already
 * received a "دينامو" raised no warning, and why "Shock Absorber — KYB" and "Shock Absorber — Monroe"
 * were two unrelated parts to a duplicate check — on this database that shape covers 3,542 of 3,552
 * purchase rows, so the repeat-buy alert was effectively switched off while appearing to work.
 *
 * Two columns fix it, and they answer different questions on purpose:
 *
 *   component_catalog_id — WHICH PART THIS IS, when a human said so by picking it from the catalog.
 *                          Nullable, because a request may still be captured before the part is
 *                          identified, and because history predates the picker. restrictOnDelete: a
 *                          purchase whose part type was deleted could no longer say what was bought,
 *                          and every repeat-buy answer resting on it would quietly change. The catalog
 *                          page counts these references and refuses the delete with a sentence
 *                          (PartsCatalogController::referenceCounts) rather than letting MySQL throw.
 *
 *   part_name_key        — the row's OWN WORDING, normalised: brand suffix cut, parenthetical
 *                          dropped, punctuation flattened. "Shock Absorber — KYB" → "shock absorber".
 *                          This is what lets a legacy free-text row still be recognised, and it is
 *                          stored rather than computed in SQL because the normalisation is
 *                          unicode-aware PHP (it folds "A/C" to "ac" and leaves Arabic alone); a
 *                          MySQL expression could not reproduce it, and two normalisations that
 *                          disagree are worse than none.
 *
 * The key describes wording that never changes, so it never goes stale: the SET of wordings a catalog
 * part answers to is resolved live at query time by PartIdentityService. Curating a new other-name
 * ('dynamo') therefore takes effect on the existing history immediately, with no re-index step to
 * forget.
 *
 * BACKFILL. Every existing row is keyed, and linked to a catalog row where its wording resolves
 * strictly (PartCatalogMatcher — exact / core / whole-phrase, never a guess). Rows whose wording
 * resolves to nothing keep a NULL catalog id: they are still keyed, so they still match themselves,
 * and they are visibly unidentified rather than plausibly mislabelled. Work is batched by DISTINCT
 * wording — 254 distinct names across 3,552 purchase rows — so this is a few hundred updates, not
 * thousands.
 */
return new class extends Migration
{
    /**
     * part_requests and part_purchases carry the same two columns, for the same reasons.
     * Value is the index-name prefix — spelled out rather than derived, because both table names
     * start with "part" and a derived prefix would give the two tables identically-named indexes.
     */
    private const TABLES = ['part_requests' => 'preq', 'part_purchases' => 'ppur'];

    public function up(): void
    {
        foreach (self::TABLES as $table => $prefix) {
            Schema::table($table, function (Blueprint $t) use ($prefix) {
                $t->foreignId('component_catalog_id')->nullable()->after('part_name')
                    ->constrained('component_catalog')->restrictOnDelete();

                // How that link was established — 'picked' when a human chose it, else the matcher's
                // verdict (exact / core / phrase). Kept because "the app decided this" and "a person
                // decided this" are different grades of evidence and the UI has to be able to say so.
                $t->string('catalog_matched_by', 12)->nullable()->after('component_catalog_id');

                $t->string('part_name_key', 191)->nullable()->after('catalog_matched_by');

                // The two lookups the repeat-buy check actually makes, both per-vehicle.
                $t->index(['vehicle_id', 'component_catalog_id'], $prefix.'_veh_catalog_idx');
                $t->index(['vehicle_id', 'part_name_key'], $prefix.'_veh_namekey_idx');
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $prefix) {
            Schema::table($table, function (Blueprint $t) use ($prefix) {
                $t->dropIndex($prefix.'_veh_catalog_idx');
                $t->dropIndex($prefix.'_veh_namekey_idx');
                $t->dropConstrainedForeignId('component_catalog_id');
                $t->dropColumn(['catalog_matched_by', 'part_name_key']);
            });
        }
    }

    /**
     * Key and link every existing row, one DISTINCT wording at a time.
     *
     * The services are resolved fresh rather than injected so this runs identically under `migrate`
     * and under a test's schema build, where the container may hold a matcher whose index was cached
     * before the catalog existed.
     */
    private function backfill(): void
    {
        $matcher  = new PartCatalogMatcher();
        $identity = new PartIdentityService($matcher);

        foreach (array_keys(self::TABLES) as $table) {
            $names = DB::table($table)->select('part_name')->distinct()->pluck('part_name');

            foreach ($names as $name) {
                $key = $identity->nameKey($name);
                $hit = $matcher->resolve($name);

                DB::table($table)
                    ->where('part_name', $name)
                    ->update([
                        'part_name_key'        => $key,
                        'component_catalog_id' => $hit['catalog_id'],
                        // Null rather than 'none', so "never resolved" reads as an absence in the
                        // column too and cannot be mistaken for a recorded verdict of no-match.
                        'catalog_matched_by'   => $hit['catalog_id'] ? $hit['matched_by'] : null,
                    ]);
            }
        }
    }
};
