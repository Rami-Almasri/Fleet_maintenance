<?php

namespace Database\Seeders;

use App\Models\ServiceCatalog;
use Illuminate\Database\Seeder;

/**
 * Event Type layer — seeds service_catalog from config/service_catalog.php.
 *
 * Idempotent + additive: upserts by `slug`. An entry edited in config updates its row; an entry
 * removed from config is left untouched (retire via is_active=false, never delete — tasks hold FKs).
 * is_active is NOT synced so a DB-side retirement survives re-seeding. Safe on every deploy.
 */
class ServiceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('service_catalog', []) as $entry) {
            if (empty($entry['slug'])) {
                continue;
            }

            ServiceCatalog::updateOrCreate(
                ['slug' => $entry['slug']],
                [
                    'name'                  => $entry['name'],
                    'name_ar'               => $entry['name_ar'] ?? null,
                    'category_key'          => $entry['category_key'],
                    'interval_km'           => $entry['interval_km'] ?? null,
                    'interval_months'       => $entry['interval_months'] ?? null,
                    'service_reminder_type' => $entry['service_reminder_type'] ?? null,
                    'component_slug'        => $entry['component_slug'] ?? null,
                    'default_labor_hours'   => $entry['default_labor_hours'] ?? null,
                    'sort_order'            => $entry['sort_order'] ?? 0,
                ]
            );
        }
    }
}
