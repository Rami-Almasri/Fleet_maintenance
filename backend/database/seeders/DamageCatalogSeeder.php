<?php

namespace Database\Seeders;

use App\Models\DamageCatalog;
use Illuminate\Database\Seeder;

/**
 * Seeds damage_catalog from config/damage_catalog.php.
 *
 * Idempotent + additive, exactly like ServiceCatalogSeeder: upserts by `slug`, never deletes (tasks
 * hold FKs — retire with is_active=false), and does not sync `is_active` so a DB-side retirement
 * survives re-seeding. Safe to run on every deploy.
 */
class DamageCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('damage_catalog', []) as $entry) {
            if (empty($entry['slug'])) {
                continue;
            }

            DamageCatalog::updateOrCreate(
                ['slug' => $entry['slug']],
                [
                    'name'                   => $entry['name'],
                    'name_ar'                => $entry['name_ar'] ?? null,
                    'category_key'           => $entry['category_key'],
                    'area_key'               => $entry['area_key'] ?? null,
                    'damage_type'            => $entry['damage_type'] ?? DamageCatalog::TYPE_UNKNOWN,
                    'is_chargeable'          => $entry['is_chargeable'] ?? true,
                    'is_insurable'           => $entry['is_insurable'] ?? false,
                    'affects_roadworthiness' => $entry['affects_roadworthiness'] ?? false,
                    'sort_order'             => $entry['sort_order'] ?? 0,
                ]
            );
        }
    }
}
