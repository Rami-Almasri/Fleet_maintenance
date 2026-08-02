<?php

namespace Database\Seeders;

use App\Models\ComponentCatalog;
use Illuminate\Database\Seeder;

/**
 * Asset Layer — seeds component_catalog from config/component_catalog.php.
 *
 * Idempotent + additive-only: upserts by `slug` (an entry edited in config updates its row; an
 * entry removed from config is left untouched — retire via is_active=false, never delete, because
 * instances hold restrictOnDelete FKs). Safe to run on every deploy.
 */
class ComponentCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('component_catalog', []) as $entry) {
            if (empty($entry['slug'])) {
                continue; // malformed config line — never write a slugless row
            }

            ComponentCatalog::updateOrCreate(
                ['slug' => $entry['slug']],
                [
                    'name'                    => $entry['name'],
                    'category_key'            => $entry['category_key'],
                    // The action-vocabulary join (see the add_action_target migration). Synced from
                    // config like every other descriptive field, so the mapping lives beside the
                    // component type it belongs to rather than in a second table someone must
                    // remember to update.
                    'action_target'           => $entry['action_target'] ?? null,
                    'tracking_mode'           => $entry['tracking_mode'],
                    'default_part_number'     => $entry['default_part_number'] ?? null,
                    'default_warranty_months' => $entry['default_warranty_months'] ?? null,
                    'expected_life_km'        => $entry['expected_life_km'] ?? null,
                    'expected_life_months'    => $entry['expected_life_months'] ?? null,
                    'position_scheme'         => $entry['position_scheme'] ?? null,
                    'notes'                   => $entry['notes'] ?? null,
                    // is_active is deliberately NOT synced from config: a DB-side retirement
                    // (is_active=false) must survive re-seeding.
                ]
            );
        }
    }
}
