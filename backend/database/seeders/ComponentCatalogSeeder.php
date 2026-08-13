<?php

namespace Database\Seeders;

use App\Models\ComponentCatalog;
use Illuminate\Database\Seeder;

/**
 * Seeds the parts vocabulary from config/component_catalog.php.
 *
 * The catalog is USER-OWNED (see the make_component_catalog_editable migration): the app is where
 * part names, Arabic terms and warranty defaults get corrected, and this seeder must never undo
 * that work. It therefore does three different things depending on what it finds:
 *
 *   row absent          → INSERT it. This is how a new part type ships with a release.
 *   row present, clean  → UPDATE it. Nobody has touched it in the app, so config still owns it and
 *                         a fixed typo or a better Arabic term reaches every install.
 *   row present, edited → SKIP it entirely. `edited_in_app` means a human made a decision here and
 *                         a deploy does not get to overrule it.
 *
 * Still idempotent and still additive-only: never deletes, never renames a slug (that would orphan
 * the restrictOnDelete FKs held by every component instance). Retirement is is_active=false, set in
 * the app. `is_active` is likewise never synced from config, so a retirement survives re-seeding.
 *
 * Safe to run on every deploy.
 */
class ComponentCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach (config('component_catalog', []) as $entry) {
            if (empty($entry['slug'])) {
                continue; // malformed config line — never write a slugless row
            }

            $existing = ComponentCatalog::where('slug', $entry['slug'])->first();

            // The guard. A row someone corrected in the app is theirs, not the config's.
            if ($existing && $existing->edited_in_app) {
                $skipped++;
                continue;
            }

            $attributes = [
                'name'         => $entry['name'],
                'name_ar'      => $entry['name_ar'] ?? null,
                // The two synonym lists, which are NOT interchangeable. `identity_aliases` holds only
                // other names for this exact part and is trusted to prove two records are the same
                // part; `aliases` holds symptom wording and names too ambiguous to pick a row with,
                // and is search-only. Never a fault vocabulary either way; see the config header.
                'aliases'          => $entry['aliases'] ?? null,
                'identity_aliases' => $entry['identity_aliases'] ?? null,
                'category_key' => $entry['category_key'],
                // The action-vocabulary join (see the add_action_target migration). Synced from
                // config like every other descriptive field, so the mapping lives beside the
                // component type it belongs to rather than in a second table someone must
                // remember to update.
                'action_target'           => $entry['action_target'] ?? null,
                'tracking_mode'           => $entry['tracking_mode'],
                'default_part_number'     => $entry['default_part_number'] ?? null,
                'default_warranty_months' => $entry['default_warranty_months'] ?? null,
                'default_warranty_km'     => $entry['default_warranty_km'] ?? null,
                'expected_life_km'        => $entry['expected_life_km'] ?? null,
                'expected_life_months'    => $entry['expected_life_months'] ?? null,
                'position_scheme'         => $entry['position_scheme'] ?? null,
                'notes'                   => $entry['notes'] ?? null,
                // is_active is deliberately NOT synced from config: a DB-side retirement
                // (is_active=false) must survive re-seeding.
            ];

            if ($existing) {
                $existing->fill($attributes)->save();
                $updated++;
            } else {
                ComponentCatalog::create($attributes + ['slug' => $entry['slug']]);
                $inserted++;
            }
        }

        $this->command?->info(
            "Component catalog: {$inserted} added, {$updated} synced, {$skipped} left alone (edited in app)."
        );
    }
}
