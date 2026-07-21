<?php

namespace App\Services;

use App\Models\Vehicle;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Straight-line vehicle depreciation (Phase 1 — managerial).
 *
 * Pure, config-driven valuation from purchase_price + purchase_date. The core
 * math (compute()) takes primitives and an injectable policy, so it is unit-
 * testable with no DB / app boot; forVehicle()/forVehicles() are thin adapters
 * that read the model(s) and delegate.
 *
 * Policy comes from config/depreciation.php by default, or can be passed to the
 * constructor (tests, or a caller that wants to value the fleet under a
 * what-if policy). OfficeManager's own asset/depreciation feed is ignored on
 * purpose — it is ~98% empty, so FleetView owns this number.
 *
 * IMPORTANT: a car with no purchase_price or no purchase_date returns has_data
 * = false and NULL money fields — never 0. A missing input must read as
 * "unknown", not "free", so it can be surfaced/excluded rather than silently
 * dragging averages to zero.
 */
class DepreciationService
{
    /** Resolved policy: method, useful_life_years, residual_pct, categories[]. */
    private array $policy;

    public function __construct(?array $policy = null)
    {
        $this->policy = $policy ?? (array) config('depreciation', []);
    }

    /**
     * Depreciation snapshot for one vehicle as of a given date (default: today).
     *
     * @return array<string,mixed>
     */
    public function forVehicle(Vehicle $vehicle, CarbonInterface|string|null $asOf = null): array
    {
        return $this->compute(
            $vehicle->purchase_price !== null ? (float) $vehicle->purchase_price : null,
            $vehicle->purchase_date,
            $vehicle->category,
            $asOf,
        );
    }

    /**
     * Depreciation snapshots keyed by vehicle id. Pass null for the whole fleet.
     * One lean query (id + the three inputs) — safe to call for a fleet rollup.
     *
     * @param  array<int>|null  $vehicleIds
     * @return array<int,array<string,mixed>>
     */
    public function forVehicles(?array $vehicleIds = null, CarbonInterface|string|null $asOf = null): array
    {
        $query = Vehicle::query()->select('id', 'purchase_price', 'purchase_date', 'category');
        if ($vehicleIds !== null) {
            $query->whereIn('id', $vehicleIds);
        }

        $out = [];
        foreach ($query->get() as $vehicle) {
            $out[(int) $vehicle->id] = $this->forVehicle($vehicle, $asOf);
        }

        return $out;
    }

    /**
     * The pure engine. Straight-line: the asset loses (cost − residual) evenly
     * over its useful life; book value floors at the residual value and
     * accumulated depreciation caps at the depreciable base (never over-depreciates).
     *
     * @return array<string,mixed>
     */
    public function compute(
        ?float $purchasePrice,
        CarbonInterface|string|null $purchaseDate,
        ?string $category = null,
        CarbonInterface|string|null $asOf = null,
    ): array {
        $policy      = $this->resolvePolicy($category);
        $usefulLife  = max(1, (int) $policy['useful_life_years']);
        $residualPct = min(1.0, max(0.0, (float) $policy['residual_pct']));
        $method      = (string) $policy['method'];

        $asOf     = $this->toDate($asOf) ?? Carbon::now();
        $purchase = $this->toDate($purchaseDate);

        // Baseline row (also the "no data" shape). Money fields stay NULL until
        // we have both a positive price and a purchase date.
        $result = [
            'has_data'                 => false,
            'purchase_price'           => $purchasePrice !== null ? round($purchasePrice, 2) : null,
            'purchase_date'            => $purchase?->toDateString(),
            'as_of'                    => $asOf->toDateString(),
            'method'                   => $method,
            'useful_life_years'        => $usefulLife,
            'residual_pct'             => $residualPct,
            'residual_value'           => null,
            'age_years'                => null,
            'annual_depreciation'      => null,
            'monthly_depreciation'     => null,
            'daily_depreciation'       => null,
            'accumulated_depreciation' => null,
            'book_value'               => null,
            'percent_depreciated'      => null,
            'fully_depreciated'        => false,
        ];

        if ($purchasePrice === null || $purchasePrice <= 0 || $purchase === null) {
            return $result;
        }

        $residualValue   = round($purchasePrice * $residualPct, 2);
        $depreciableBase = round($purchasePrice - $residualValue, 2);
        $annual          = round($depreciableBase / $usefulLife, 2);

        // Fractional age in years; a future purchase date reads as age 0.
        $ageYears  = $purchase->greaterThan($asOf) ? 0.0 : (float) $purchase->diffInYears($asOf, true);
        $cappedAge = min($ageYears, (float) $usefulLife);

        $accumulated = round(($depreciableBase / $usefulLife) * $cappedAge, 2);
        $bookValue   = round($purchasePrice - $accumulated, 2);
        if ($bookValue < $residualValue) {          // belt-and-suspenders: never below salvage
            $bookValue   = $residualValue;
            $accumulated = round($purchasePrice - $residualValue, 2);
        }

        $percent = $depreciableBase > 0 ? min(1.0, round($accumulated / $depreciableBase, 4)) : 0.0;

        return array_merge($result, [
            'has_data'                 => true,
            'residual_value'           => $residualValue,
            'age_years'                => round($ageYears, 2),
            'annual_depreciation'      => $annual,
            'monthly_depreciation'     => round($annual / 12, 2),
            'daily_depreciation'       => round($annual / 365, 2),
            'accumulated_depreciation' => $accumulated,
            'book_value'               => $bookValue,
            'percent_depreciated'      => $percent,
            'fully_depreciated'        => $ageYears >= $usefulLife,
        ]);
    }

    /**
     * Effective policy for a vehicle category — category overrides win over the
     * top-level defaults; anything the override omits falls back to the default.
     *
     * @return array{method:string, useful_life_years:int, residual_pct:float}
     */
    public function resolvePolicy(?string $category): array
    {
        $defaults = [
            'method'            => $this->policy['method'] ?? 'straight_line',
            'useful_life_years' => $this->policy['useful_life_years'] ?? 5,
            'residual_pct'      => $this->policy['residual_pct'] ?? 0.20,
        ];

        $overrides = [];
        if ($category !== null && ! empty($this->policy['categories'][$category])) {
            $overrides = (array) $this->policy['categories'][$category];
        }

        return array_merge($defaults, $overrides);
    }

    /** Coerce a Carbon / date-string / null into a Carbon date (or null). */
    private function toDate(CarbonInterface|string|null $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value);
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
