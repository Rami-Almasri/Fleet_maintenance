<?php

use App\Services\PartCatalogMatcher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Says WHICH PART a billed part line is — not just what somebody typed on the invoice form.
 *
 * A part line already carried `description` ("Front brake pads") and an optional `part_number`, both
 * free text. That is the same failure the catalog was built to end everywhere else: three people
 * bill the same pad set as "Brake pads", "Front brake pads (set)" and "brake pad front", and the
 * three cost the fleet money under three unrelated names. Nothing joins — not "what did brake pads
 * cost us this year", not "how long did the last set last", not "we bought this part twice".
 *
 * Worse, the part's CATEGORY was derived from the FAULT the line is attributed to. A fault and a
 * part are different things: "engine noise" is a symptom and the part fitted for it may be a mount,
 * a belt or a pulley. Deriving one from the other files spend under the wrong heading whenever the
 * repair is not the obvious one. With a catalog reference the line states its own category, taken
 * from the part itself, and the fault link goes back to being what it is — attribution, not identity.
 *
 *   component_catalog_id — the part, when a human picked it from the catalog. Nullable, because
 *                          history predates the picker and the public garage portal still submits
 *                          typed lines. restrictOnDelete for the same reason purchases use it: a
 *                          cost line whose part type vanished can no longer say what was fitted.
 *                          Counted by PartsCatalogController::referenceCounts() so the catalog page
 *                          refuses the delete with a sentence instead of a SQL error.
 *
 *   catalog_matched_by   — how the link was established: 'picked' when a person chose it, else the
 *                          matcher's verdict. "The app decided this" and "a person decided this"
 *                          are different grades of evidence and the UI has to be able to say which.
 *
 * Deliberately NOT added: `part_name_key`. part_requests / part_purchases store it because the
 * repeat-buy check reads it; no consumer reads a per-line wording key here, and a stored field with
 * no reader is a field that silently goes wrong. Wording still resolves live through
 * PartIdentityService when something needs it.
 *
 * BACKFILL — existing PART lines are linked where their wording resolves strictly through
 * PartCatalogMatcher (exact / core / whole-phrase, never a guess). Wording that resolves to nothing
 * keeps a NULL id: visibly unidentified beats plausibly mislabelled. Batched by DISTINCT description,
 * so this is a few hundred updates rather than one per line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_line_items', function (Blueprint $t) {
            $t->foreignId('component_catalog_id')->nullable()->after('part_number')
                ->constrained('component_catalog')->restrictOnDelete();
            $t->string('catalog_matched_by', 12)->nullable()->after('component_catalog_id');

            // "What has this car spent on THIS part?" — the per-vehicle lookup the durability and
            // spend reads make, now that the part is an id rather than a spelling.
            $t->index(['vehicle_id', 'component_catalog_id'], 'mli_veh_catalog_idx');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('maintenance_line_items', function (Blueprint $t) {
            $t->dropIndex('mli_veh_catalog_idx');
            $t->dropConstrainedForeignId('component_catalog_id');
            $t->dropColumn('catalog_matched_by');
        });
    }

    /**
     * The matcher is constructed fresh rather than resolved from the container: under a test's schema
     * build the container may hold one whose index was cached before the catalog rows existed.
     */
    private function backfill(): void
    {
        $matcher = new PartCatalogMatcher();

        $descriptions = DB::table('maintenance_line_items')
            ->where('kind', 'part')
            ->select('description')
            ->distinct()
            ->pluck('description');

        foreach ($descriptions as $description) {
            $hit = $matcher->resolve($description);

            if (! $hit['catalog_id']) {
                continue;
            }

            DB::table('maintenance_line_items')
                ->where('kind', 'part')
                ->where('description', $description)
                ->update([
                    'component_catalog_id' => $hit['catalog_id'],
                    'catalog_matched_by'   => $hit['matched_by'],
                ]);
        }
    }
};
