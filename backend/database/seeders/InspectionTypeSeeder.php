<?php

namespace Database\Seeders;

use App\Models\InspectionType;
use Illuminate\Database\Seeder;

/**
 * Event Type layer — seeds inspection_types from config/inspection_types.php.
 *
 * Idempotent + additive: upserts by `slug`; retire via is_active=false, never delete. Safe on every deploy.
 */
class InspectionTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('inspection_types', []) as $entry) {
            if (empty($entry['slug'])) {
                continue;
            }

            InspectionType::updateOrCreate(
                ['slug' => $entry['slug']],
                [
                    'name'                 => $entry['name'],
                    'name_ar'              => $entry['name_ar'] ?? null,
                    'checklist_key'        => $entry['checklist_key'] ?? null,
                    'expects_measurements' => $entry['expects_measurements'] ?? false,
                    'may_spawn_fault'      => $entry['may_spawn_fault'] ?? true,
                    'sort_order'           => $entry['sort_order'] ?? 0,
                ]
            );
        }
    }
}
