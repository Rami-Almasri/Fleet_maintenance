<?php

namespace Database\Seeders;

use App\Models\DamageCatalog;
use App\Models\FaultCatalog;
use App\Models\VehicleLocation;
use App\Models\VehicleLocationGroup;
use App\Services\FaultLocationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the WHERE axis: the `vehicle_locations` vocabulary, plus each catalog row's `location_mode`.
 *
 * Idempotent + additive, exactly like DamageCatalogSeeder: upserts by `slug`, never deletes (tasks
 * hold restrictOnDelete FKs — retire with is_active=false), and does not sync `is_active`, so a
 * DB-side retirement survives re-seeding. Safe to run on every deploy.
 *
 * The second half stamps `fault_catalog.location_mode` / `damage_catalog.location_mode` from
 * config('vehicle_locations.policy'). That is a PREFILL of the authored answer, not a takeover: the
 * column exists so a curator can override one type in the app, and re-running this seeder re-asserts
 * the config answer for every row — which is the same trade the other catalog seeders make (config
 * is the authored source; the DB is what runs).
 */
class VehicleLocationSeeder extends Seeder
{
    /** Whether this database can even record an in-app edit (pre-migration deploys cannot). */
    private bool $editedInApp = false;

    public function run(): void
    {
        $this->editedInApp = Schema::hasTable('vehicle_locations')
            && Schema::hasColumn('vehicle_locations', 'edited_in_app');

        // Sections first — a place's `group_key` is meaningless until the section it names exists.
        // firstOrCreate, not updateOrCreate: a section's label, order and active flag are all editable
        // from the admin page, and re-asserting the config wording on every deploy would silently undo
        // a rename nobody asked to have undone.
        if (Schema::hasTable('vehicle_location_groups')) {
            foreach ((array) config('vehicle_locations.groups', []) as $group) {
                if (empty($group['key'])) {
                    continue;
                }

                VehicleLocationGroup::firstOrCreate(
                    ['key' => $group['key']],
                    [
                        'label'      => $group['label'] ?? $group['key'],
                        'label_ar'   => $group['label_ar'] ?? null,
                        'sort_order' => $group['sort_order'] ?? 0,
                    ]
                );
            }
        }

        foreach ((array) config('vehicle_locations.locations', []) as $entry) {
            if (empty($entry['slug'])) {
                continue;
            }

            // A place a curator has edited in the app is left exactly as they left it — the same
            // read-config/write-DB trade `component_catalog.edited_in_app` makes. Without this, the
            // next deploy would quietly revert every rename, re-grouping and alias someone added.
            if ($this->editedInApp && VehicleLocation::query()->where('slug', $entry['slug'])->value('edited_in_app')) {
                continue;
            }

            VehicleLocation::updateOrCreate(
                ['slug' => $entry['slug']],
                [
                    'name'            => $entry['name'],
                    'name_ar'         => $entry['name_ar'] ?? null,
                    'group_key'       => $entry['group_key'],
                    'precision'       => $entry['precision'] ?? VehicleLocation::PRECISION_PANEL,
                    'inspection_zone' => $entry['inspection_zone'] ?? null,
                    'area_key'        => $entry['area_key'] ?? null,
                    'aliases'         => $entry['aliases'] ?? [],
                    'sort_order'      => $entry['sort_order'] ?? 0,
                ]
            );
        }

        $this->stampPolicy();
    }

    /**
     * Write the authored location policy onto the type catalogs.
     *
     * Guarded on the column existing so this seeder is safe to run against a database that has the
     * catalogs but not yet the add_location_mode_to_catalogs migration — the ordering the deploy
     * scripts happen to use is not something a seeder should depend on.
     */
    private function stampPolicy(): void
    {
        $policy = app(FaultLocationService::class);

        foreach ([FaultCatalog::class, DamageCatalog::class] as $model) {
            /** @var \Illuminate\Database\Eloquent\Model $probe */
            $probe = new $model();
            if (! Schema::hasTable($probe->getTable()) || ! Schema::hasColumn($probe->getTable(), 'location_mode')) {
                continue;
            }

            foreach ($model::query()->get(['id', 'slug', 'category_key']) as $row) {
                // storedMode deliberately null: this call resolves the AUTHORED answer
                // (by_catalog_slug → by_category → default), which is exactly what is being stamped.
                $mode = $policy->policyFor($row->slug, $row->category_key, null);
                $model::query()->whereKey($row->id)->update(['location_mode' => $mode]);
            }
        }
    }
}
