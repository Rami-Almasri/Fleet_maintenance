<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the catalog's synonym list in two, because ONE list was being asked to do two jobs that
 * need opposite amounts of caution.
 *
 * `aliases` was authored as a search index and deliberately mixes two kinds of wording: other names
 * for the part ('dynamo', 'سلف') and the SYMPTOM someone types instead of a name ('ac not cooling',
 * 'brake noise'). That mix is exactly right for a dropdown, where a human reads the results and
 * picks. It is unusable for deciding whether two records are THE SAME PART — 'brake noise' is as
 * true of the discs and the caliper as of the pads — which is why PartCatalogMatcher had to ignore
 * aliases wholesale, and why buying "dynamo" for a car that already received an "Alternator" raised
 * no repeat warning at all. The information was in the catalog; nothing was allowed to use it.
 *
 * So: `identity_aliases` holds ONLY other names for the same part, and CAN be trusted to prove
 * identity. `aliases` keeps the symptom wording and the names too ambiguous to decide between two
 * rows ('fan motor' — radiator fan or A/C blower?), and stays search-only. Both are searched by the
 * picker; only one is evidence. Generous where a human decides, strict where nobody does.
 *
 * The column is nullable and backfilled from config for every row the config still owns. A row
 * already edited in the app is left empty rather than guessed at — someone owns that row, its
 * alternate names are theirs to state, and the Parts Catalog page now has a field for it. An empty
 * identity list costs nothing: identity then rests on the part's own names, which is where it rested
 * before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('component_catalog', function (Blueprint $table) {
            $table->json('identity_aliases')->nullable()->after('aliases');
        });

        $this->backfillFromConfig();
    }

    public function down(): void
    {
        Schema::table('component_catalog', function (Blueprint $table) {
            $table->dropColumn('identity_aliases');
        });
    }

    /**
     * Seed identity_aliases (and re-seed the now symptom-only aliases) from config, by slug.
     *
     * Deliberately skips `edited_in_app` rows — the same guard ComponentCatalogSeeder applies. A
     * deploy does not get to overwrite a name someone corrected in the app, and it especially does
     * not get to do so while splitting a field, where the merge is a judgement call.
     */
    private function backfillFromConfig(): void
    {
        foreach (config('component_catalog', []) as $entry) {
            if (empty($entry['slug']) || ! array_key_exists('identity_aliases', $entry)) {
                continue;
            }

            DB::table('component_catalog')
                ->where('slug', $entry['slug'])
                ->where('edited_in_app', false)
                ->update([
                    'identity_aliases' => json_encode(array_values($entry['identity_aliases']), JSON_UNESCAPED_UNICODE),
                    'aliases'          => json_encode(array_values($entry['aliases'] ?? []), JSON_UNESCAPED_UNICODE),
                ]);
        }
    }
};
