<?php

namespace Database\Seeders;

use App\Models\FaultCatalog;
use Illuminate\Database\Seeder;

/**
 * Event Type layer — seeds fault_catalog from config/fault_catalog.php.
 *
 * Idempotent + additive: upserts by `slug`; retire via is_active=false, never delete. is_active is not
 * synced so a DB-side retirement survives re-seeding. Safe on every deploy.
 *
 * EDITED ROWS ARE LEFT ALONE. This runs on every container start, and it used to rewrite name,
 * category, severity, on_site and sort_order unconditionally — so a fault renamed on the admin page
 * was reverted by the next deploy, with nothing to show the curator why their change had vanished.
 * A row stamped `edited_in_app` is now the curator's, and the authored file stops asserting over it.
 * Same trade `vehicle_locations` and `component_catalog` already make.
 */
class FaultCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('fault_catalog', []) as $entry) {
            if (empty($entry['slug'])) {
                continue;
            }

            $existing = FaultCatalog::where('slug', $entry['slug'])->first();

            // Hands off — a human has since said something different about this row in the app.
            // Still not `continue`-on-missing: a row the curator deleted should come back, because
            // the config is where it is authored.
            if ($existing && $existing->edited_in_app) {
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
