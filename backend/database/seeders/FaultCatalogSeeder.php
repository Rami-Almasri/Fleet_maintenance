<?php

namespace Database\Seeders;

use App\Models\FaultCatalog;
use Illuminate\Database\Seeder;

/**
 * Event Type layer — seeds fault_catalog from config/fault_catalog.php.
 *
 * Idempotent + additive: upserts by `slug`; retire via is_active=false, never delete. is_active is not
 * synced so a DB-side retirement survives re-seeding. Safe on every deploy.
 */
class FaultCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('fault_catalog', []) as $entry) {
            if (empty($entry['slug'])) {
                continue;
            }

            FaultCatalog::updateOrCreate(
                ['slug' => $entry['slug']],
                [
                    'name'             => $entry['name'],
                    'name_ar'          => $entry['name_ar'] ?? null,
                    'category_key'     => $entry['category_key'],
                    'default_severity' => $entry['default_severity'] ?? null,
                    'on_site'          => $entry['on_site'] ?? false,
                    'sort_order'       => $entry['sort_order'] ?? 0,
                ]
            );
        }
    }
}
