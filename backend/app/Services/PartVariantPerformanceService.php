<?php

namespace App\Services;

use App\Models\ComponentCatalog;
use App\Models\VehicleComponent;
use App\Support\PartSpecs;
use Illuminate\Support\Collection;

/**
 * WHICH ONE IS ACTUALLY WORTH BUYING — the payoff for typing a spec in at the counter.
 *
 * Evidence class: DERIVED
 *   Consumes: vehicle_components (install/removal legs, purchase_cost, specs)
 *   Produces: E-variant (part variant performance — read-only, never persisted)
 *
 * ── The question ─────────────────────────────────────────────────────────────────────────────────
 *
 * "This battery cost 380 and lasted 6 months; that one cost 700 and lasted 14 months." Both are
 * facts the fleet already generated — 1,095 parts have been fitted and later removed, and every one
 * of them carries a price. What was missing was WHICH BATTERY each one was, so the two could not be
 * put in separate buckets. That is the only thing a spec adds, and it is the whole thing.
 *
 * The decisive column is COST PER MONTH OF SERVICE. A cheaper part that dies twice as fast is more
 * expensive, and that is invisible in a purchase ledger sorted by price — which is how a fleet ends
 * up buying the dear option repeatedly while believing it is economising.
 *
 * ── What is measured, and what is refused ────────────────────────────────────────────────────────
 *
 * A life is measured ONLY on a part that has both an install and a removal date. A part still fitted
 * has not finished its life and its age is a LOWER BOUND, so it is reported separately ("6 still
 * running, oldest 14 months") and never averaged into the lifespan. Mixing the two would drag every
 * average toward whatever the fleet happens to be running today.
 *
 * Nothing here is ranked, scored or recommended. It reports counts, medians and money, and the
 * reader decides — a five-part bucket is not evidence of anything and this service will not pretend
 * otherwise, which is why `observations` sits beside every figure.
 */
class PartVariantPerformanceService
{
    /** Below this, a bucket's averages are reported but flagged as too thin to act on. */
    public const THIN_EVIDENCE = 5;

    private const DAYS_PER_MONTH = 30.44;

    /**
     * Every variant of one part type, worst value first.
     *
     * @return array{part_type: ?array, variants: array<int,array>, totals: array}
     */
    public function forPartType(int $catalogId, string $locale = 'en'): array
    {
        $catalog = ComponentCatalog::find($catalogId);

        if (! $catalog) {
            return ['part_type' => null, 'variants' => [], 'totals' => $this->emptyTotals()];
        }

        $rows = VehicleComponent::query()
            ->trusted()
            ->where('component_catalog_id', $catalogId)
            ->get([
                'id', 'specs', 'purchase_cost', 'installed_at', 'removed_at',
                'installed_odometer', 'removed_odometer', 'removal_reason', 'brand', 'vehicle_id',
            ]);

        $variants = $rows
            ->groupBy(fn (VehicleComponent $c) => PartSpecs::variantKey($catalog, $c->specs))
            ->map(fn (Collection $group, string $key) => $this->summariseVariant($catalog, $key, $group, $locale))
            ->values()
            // Most-bought first. NOT "best first": ordering by cost-per-month would present a
            // two-observation bucket as the fleet's best option, which is the exact mistake this
            // service refuses to make on the reader's behalf.
            ->sortByDesc('fitted')
            ->values()
            ->all();

        return [
            'part_type' => [
                'id'          => $catalog->id,
                'slug'        => $catalog->slug,
                'name'        => $catalog->displayName($locale),
                'spec_fields' => array_keys($catalog->specFields()),
                'has_specs'   => $catalog->hasSpecs(),
            ],
            'variants' => $variants,
            'totals'   => $this->totals($rows),
        ];
    }

    /**
     * The part types worth opening — those with enough finished lives behind them to compare
     * anything, ordered by how much the fleet spends on them.
     *
     * @return array<int,array>
     */
    public function partTypeIndex(string $locale = 'en'): array
    {
        $rows = VehicleComponent::query()
            ->trusted()
            ->whereNotNull('component_catalog_id')
            ->get(['component_catalog_id', 'specs', 'purchase_cost', 'installed_at', 'removed_at']);

        $catalogs = ComponentCatalog::whereIn('id', $rows->pluck('component_catalog_id')->unique())
            ->get()
            ->keyBy('id');

        return $rows
            ->groupBy('component_catalog_id')
            ->map(function (Collection $group, $catalogId) use ($catalogs, $locale) {
                $catalog = $catalogs->get($catalogId);

                if (! $catalog) {
                    return null;
                }

                $completed = $group->filter(fn ($c) => $c->installed_at && $c->removed_at);
                $spend     = $group->sum(fn ($c) => (float) ($c->purchase_cost ?? 0));

                // How many DIFFERENT things we have been buying under this one name. More than one
                // means there is a comparison to make; one means there is nothing to choose between
                // yet, either because the fleet standardised or because nobody has typed a spec.
                $variantCount = $group
                    ->map(fn ($c) => PartSpecs::variantKey($catalog, $c->specs))
                    ->unique()
                    ->count();

                $specced = $group->filter(fn ($c) => ! empty($c->specs))->count();

                return [
                    'id'              => $catalog->id,
                    'slug'            => $catalog->slug,
                    'name'            => $catalog->displayName($locale),
                    'has_specs'       => $catalog->hasSpecs(),
                    'fitted'          => $group->count(),
                    'completed_lives' => $completed->count(),
                    'variants'        => $variantCount,
                    'total_spend'     => round($spend, 2),
                    // The honest headline on a thin row: you cannot compare what nobody described.
                    'specced'         => $specced,
                    'spec_coverage'   => $group->count() ? round($specced / $group->count() * 100) : 0,
                ];
            })
            ->filter()
            ->sortByDesc('total_spend')
            ->values()
            ->all();
    }

    // ── Internals ───────────────────────────────────────────────────────────────────────────────

    /**
     * One bucket: what this exact variant cost us and how long it stayed on the car.
     *
     * @param  Collection<int,VehicleComponent>  $group
     */
    private function summariseVariant(ComponentCatalog $catalog, string $key, Collection $group, string $locale): array
    {
        // A specimen's specs stand for the bucket — every member shares the summary fields by
        // construction, which is what the variant key means.
        $specimen = $group->first(fn ($c) => ! empty($c->specs)) ?? $group->first();

        $finished = $group->filter(fn ($c) => $c->installed_at && $c->removed_at);
        $running  = $group->filter(fn ($c) => $c->installed_at && ! $c->removed_at);

        $lifeDays = $finished
            ->map(fn ($c) => (int) $c->installed_at->diffInDays($c->removed_at))
            ->filter(fn ($d) => $d > 0)
            ->values();

        $lifeKm = $finished
            ->filter(fn ($c) => $c->removed_odometer !== null && $c->installed_odometer !== null)
            ->map(fn ($c) => max(0, (int) $c->removed_odometer - (int) $c->installed_odometer))
            ->filter(fn ($km) => $km > 0)
            ->values();

        $costs = $group
            ->map(fn ($c) => $c->purchase_cost === null ? null : (float) $c->purchase_cost)
            ->filter(fn ($v) => $v !== null && $v > 0)
            ->values();

        // MEDIAN, not mean. One battery that died in a week after an alternator fault would drag a
        // mean down far enough to condemn a variant that is otherwise fine; the median describes
        // what usually happens, which is what a buying decision needs.
        $medianDays = $this->median($lifeDays);
        $avgCost    = $costs->isNotEmpty() ? round($costs->avg(), 2) : null;

        // THE DECIDING NUMBER. Null unless BOTH legs are known — a cost-per-month computed from a
        // guessed price or an unfinished life would be the most confident-looking wrong figure on
        // the page.
        $costPerMonth = ($avgCost !== null && $medianDays !== null && $medianDays > 0)
            ? round($avgCost / ($medianDays / self::DAYS_PER_MONTH), 2)
            : null;

        return [
            'key'           => $key,
            'label'         => PartSpecs::variantLabel($catalog, $specimen?->specs, $locale),
            'specs'         => $specimen?->specs,
            'is_unspecced'  => $key === '',

            'fitted'        => $group->count(),
            'observations'  => $lifeDays->count(),   // how much this row's averages actually rest on
            'thin_evidence' => $lifeDays->count() < self::THIN_EVIDENCE,

            'median_life_days'   => $medianDays,
            'median_life_months' => $medianDays !== null ? round($medianDays / self::DAYS_PER_MONTH, 1) : null,
            'shortest_life_days' => $lifeDays->min(),
            'longest_life_days'  => $lifeDays->max(),
            'median_life_km'     => $this->median($lifeKm),

            'avg_cost'       => $avgCost,
            'cheapest'       => $costs->isNotEmpty() ? round($costs->min(), 2) : null,
            'dearest'        => $costs->isNotEmpty() ? round($costs->max(), 2) : null,
            'total_spend'    => round($costs->sum(), 2),
            'cost_per_month' => $costPerMonth,

            // A lower bound, kept apart from the measured lives on purpose.
            'still_running'      => $running->count(),
            'oldest_running_days' => $running->isNotEmpty()
                ? $running->map(fn ($c) => (int) $c->installed_at->diffInDays(now()))->max()
                : null,

            'brands' => $group->pluck('brand')->filter()->unique()->take(6)->values()->all(),

            // Why they came off — a variant whose lives end in 'failed' is a different story from
            // one that ends in 'worn_out', at the same number of months.
            'removal_reasons' => $finished
                ->pluck('removal_reason')
                ->filter()
                ->countBy()
                ->sortDesc()
                ->all(),
        ];
    }

    private function totals(Collection $rows): array
    {
        $finished = $rows->filter(fn ($c) => $c->installed_at && $c->removed_at);
        $specced  = $rows->filter(fn ($c) => ! empty($c->specs));

        return [
            'fitted'          => $rows->count(),
            'completed_lives' => $finished->count(),
            'specced'         => $specced->count(),
            'spec_coverage'   => $rows->count() ? round($specced->count() / $rows->count() * 100) : 0,
            'total_spend'     => round($rows->sum(fn ($c) => (float) ($c->purchase_cost ?? 0)), 2),
        ];
    }

    private function emptyTotals(): array
    {
        return ['fitted' => 0, 'completed_lives' => 0, 'specced' => 0, 'spec_coverage' => 0, 'total_spend' => 0.0];
    }

    /** @param Collection<int,int> $values */
    private function median(Collection $values): ?int
    {
        if ($values->isEmpty()) {
            return null;
        }

        $sorted = $values->sort()->values();
        $count  = $sorted->count();
        $middle = (int) floor($count / 2);

        return $count % 2
            ? (int) $sorted[$middle]
            : (int) round(($sorted[$middle - 1] + $sorted[$middle]) / 2);
    }
}
