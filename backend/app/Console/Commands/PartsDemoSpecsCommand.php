<?php

namespace App\Console\Commands;

use App\Models\ComponentCatalog;
use App\Models\VehicleComponent;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * A DEMONSTRATION fill for the "What to buy" page — so the screen can be understood before anyone
 * has typed a single spec at the counter.
 *
 * ── READ THIS BEFORE RUNNING IT ──────────────────────────────────────────────────────────────────
 *
 * This writes specs that NOBODY RECORDED. They are inferred from what each part cost, by splitting
 * every part type into three price bands and labelling the bands with the sizes that plausibly
 * correspond. A part in the dearest third of batteries is labelled 100Ah. Nobody checked that
 * battery. It may have been a 60Ah bought at a bad price.
 *
 * So the SPEC LABELS are fiction. What is NOT fiction is everything they are attached to: the
 * prices are real, the install and removal dates are real, and therefore the lifespans and the
 * cost-per-month are real. Which makes the output answer a genuine question — "in this fleet, do
 * the parts we pay more for actually last longer?" — while being useless for its nominal one,
 * "which capacity should we buy". Only real recorded specs can answer that.
 *
 * WHY THIS IS SAFE TO RUN AND SAFE TO UNDO: it touches exactly one column, `vehicle_components.
 * specs`, which was added by the part-specifications migration and is currently NULL on every row
 * in the fleet. `--clear` sets it back to NULL, which restores the database to precisely its
 * present state. Nothing else is written, no row is created, and no other column is read from or
 * assigned to. (The demo-seeder incident that polluted the live database wrote real entities; this
 * writes one nullable JSON column on rows that already exist.)
 *
 * It refuses to overwrite a spec a person actually entered — see the query filter below. Once real
 * specs start arriving, this command stops being able to touch them.
 */
class PartsDemoSpecsCommand extends Command
{
    protected $signature = 'parts:demo-specs
                            {--clear : Wipe every demo spec and return the column to empty}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Fill part specs from price bands so the "What to buy" page can be seen working (demo only — reversible with --clear)';

    /**
     * Price bands → the spec labels they are dressed in, per part type.
     *
     * Three bands (cheapest / middle / dearest third) because that is the coarsest split that still
     * produces a comparison, and a coarse split keeps each bucket big enough for its median to mean
     * something. The labels are ordered cheapest-first and must be valid options in
     * config/part_specs.php — anything else is dropped by PartSpecs::validate and the row ends up
     * blank, which would silently thin the demo.
     */
    private const BANDS = [
        'battery-12v' => [
            ['voltage' => '12v', 'capacity_ah' => '60',  'battery_chemistry' => 'lead_acid'],
            ['voltage' => '12v', 'capacity_ah' => '70',  'battery_chemistry' => 'lead_acid'],
            ['voltage' => '12v', 'capacity_ah' => '100', 'battery_chemistry' => 'agm'],
        ],
        'tyre' => [
            ['tyre_size' => '205/65R16', 'tyre_construction' => 'standard'],
            ['tyre_size' => '225/65R17', 'tyre_construction' => 'standard'],
            ['tyre_size' => '265/60R18', 'tyre_construction' => 'all_terrain'],
        ],
        'brake-pads' => [
            ['pad_material' => 'organic'],
            ['pad_material' => 'semi_metallic'],
            ['pad_material' => 'ceramic'],
        ],
        'brake-discs' => [
            ['disc_diameter_mm' => 280],
            ['disc_diameter_mm' => 300],
            ['disc_diameter_mm' => 330],
        ],
        'cabin-filter' => [
            ['filter_media' => 'paper'],
            ['filter_media' => 'carbon'],
            ['filter_media' => 'hepa'],
        ],
    ];

    public function handle(): int
    {
        return $this->option('clear') ? $this->clear() : $this->fill();
    }

    private function clear(): int
    {
        $affected = VehicleComponent::whereNotNull('specs')->update(['specs' => null]);

        $this->info("Cleared specs on {$affected} components. The column is empty again.");
        $this->line('The "What to buy" page will go back to one "No spec recorded" row per part.');

        return self::SUCCESS;
    }

    private function fill(): int
    {
        $this->warn('This writes INVENTED spec labels onto real components, derived from price bands.');
        $this->line('The prices and lifespans stay real; the sizes are a stand-in so the page can be read.');
        $this->line('Undo at any time with:  php artisan parts:demo-specs --clear');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Fill demo specs now?', true)) {
            return self::SUCCESS;
        }

        $filled = 0;

        foreach (self::BANDS as $slug => $bands) {
            $catalog = ComponentCatalog::where('slug', $slug)->first();

            if (! $catalog) {
                $this->line("  skipped {$slug} — not in the catalog");
                continue;
            }

            // Only untouched rows. A spec someone actually typed is a fact and this command has no
            // business rewriting it with a guess derived from a price.
            $rows = VehicleComponent::where('component_catalog_id', $catalog->id)
                ->whereNull('specs')
                ->whereNotNull('purchase_cost')
                ->orderBy('purchase_cost')
                ->get(['id', 'purchase_cost']);

            if ($rows->count() < 3) {
                $this->line("  skipped {$catalog->name} — too few priced parts to band");
                continue;
            }

            $count = $this->fillBands($rows, $bands);
            $filled += $count;

            $this->line("  {$catalog->name}: {$count} parts labelled across " . count($bands) . ' bands');
        }

        $this->newLine();
        $this->info("Filled {$filled} components.");
        $this->line('Open  Parts → What to buy  to see it. Undo with:  php artisan parts:demo-specs --clear');

        return self::SUCCESS;
    }

    /**
     * Split the rows into equal thirds BY PRICE and stamp each third with its band's label.
     *
     * Split by rank rather than by price value on purpose: a fleet whose batteries all cost within
     * 50 of each other would put every row in one band if the split were by amount, and the demo
     * would show the same single bucket it is meant to break up.
     *
     * @param  Collection<int,VehicleComponent>  $rows  ordered cheapest-first
     */
    private function fillBands(Collection $rows, array $bands): int
    {
        $perBand = (int) ceil($rows->count() / count($bands));
        $filled  = 0;

        foreach ($rows->chunk($perBand)->values() as $index => $chunk) {
            $specs = $bands[min($index, count($bands) - 1)];

            foreach ($chunk as $component) {
                // saveQuietly: this is a backfill of a descriptive column, not a lifecycle event —
                // it must not fire model events that would write timeline entries for parts that
                // were fitted years ago.
                $component->specs = $specs;
                $component->saveQuietly();
                $filled++;
            }
        }

        return $filled;
    }
}
