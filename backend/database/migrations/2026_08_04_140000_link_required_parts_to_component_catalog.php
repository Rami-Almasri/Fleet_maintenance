<?php

use App\Services\PartCatalogMatcher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * maintenance_required_parts gains a real reference to the parts catalog.
 *
 * Until now `part_name` was free text, so "Brake pads", "Front brake pads (set)" and "break" were
 * three unrelated strings describing one part. Nothing could be counted, no spend could be grouped,
 * and the catalog the fleet maintains had no bearing on what the inspector actually asked for.
 *
 * WHAT THIS MIGRATION DOES NOT DO: it does not delete `part_name`. The inspector's own wording is
 * evidence and stays verbatim, exactly as the original table docblock promised. The catalog id is
 * ADDED ALONGSIDE it — the structured answer next to the human one. Going forward the API requires
 * the id on new lines; the text becomes a label, not the identity.
 *
 * THE SAFE PATH FOR EXISTING DATA. The column is NULLABLE, and it has to be: some historical
 * wording genuinely does not identify a part ("break" is a typo for either brakes or brake pads,
 * and no amount of cleverness can tell which). Backfill therefore uses PartCatalogMatcher, which
 * only ever matches EXACTLY on a normalised string and refuses ties. Rows it cannot resolve are
 * left NULL and listed by `parts:link-required` so a human can finish the job. Nothing is guessed:
 * a required part pointing at the WRONG catalog entry would order the wrong part and quietly
 * corrupt every figure built on the catalog afterwards, which is far worse than a null.
 *
 * `catalog_matched_by` records HOW each link was made (exact / core / phrase / manual), so a
 * reviewer can see at a glance which links were mechanical and which a person chose. Per the
 * traceability rule, a derived value must be able to explain itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_required_parts', function (Blueprint $table) {
            // restrictOnDelete, matching every other reference to the catalog: a part type that
            // something still points at is RETIRED (is_active=false), never deleted.
            $table->foreignId('component_catalog_id')->nullable()->after('finding_text')
                ->constrained('component_catalog')->restrictOnDelete();

            // exact | core | phrase | manual | null. How the link above was established.
            $table->string('catalog_matched_by', 12)->nullable()->after('component_catalog_id');

            // "What has this car needed, by part type" without touching the text column.
            $table->index(['component_catalog_id', 'status'], 'mrp_catalog_status_idx');
        });

        $this->backfill();
    }

    /**
     * Link what can be linked with certainty; leave the rest for a human.
     *
     * Runs in chunks and touches only rows that are still unlinked, so it is safe to re-run and
     * safe on a large table. Uses the same matcher the API and the artisan command use — one
     * definition of "which part is this", not three that drift apart.
     */
    private function backfill(): void
    {
        if (! Schema::hasTable('maintenance_required_parts')) {
            return;
        }

        // The matcher reads component_catalog. On a brand-new install this migration runs BEFORE
        // any seeder, so there is nothing to match against and there are no rows to match anyway.
        if (DB::table('component_catalog')->count() === 0) {
            return;
        }

        $matcher = new PartCatalogMatcher();

        DB::table('maintenance_required_parts')
            ->select('id', 'part_name')
            ->whereNull('component_catalog_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($matcher) {
                foreach ($rows as $row) {
                    $hit = $matcher->resolve($row->part_name);

                    if ($hit['catalog_id'] === null) {
                        continue; // unresolved on purpose — reported by parts:link-required
                    }

                    DB::table('maintenance_required_parts')
                        ->where('id', $row->id)
                        ->update([
                            'component_catalog_id' => $hit['catalog_id'],
                            'catalog_matched_by'   => $hit['matched_by'],
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('maintenance_required_parts', function (Blueprint $table) {
            $table->dropIndex('mrp_catalog_status_idx');
            $table->dropConstrainedForeignId('component_catalog_id');
            $table->dropColumn('catalog_matched_by');
        });
    }
};
