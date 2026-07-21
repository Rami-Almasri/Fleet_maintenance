<?php

namespace App\Services;

use App\Models\Vehicle;
use Illuminate\Support\Facades\Cache;

/**
 * Cost Intelligence — maintenance cost per km / per day / per rental, per vehicle.
 *
 * Pure reuse, no parallel cost ledger:
 *   • numerator  — the SAME per-vehicle maintenance spend RealProfitService sums for the Profit Bridge.
 *   • distance   — the SAME validated lifetime travel (last IN − first OUT) FuelMileageService computes.
 *   • days       — the SAME in-service days FleetUtilizationService computes.
 *   • rentals    — the SAME contract count RealProfitService carries.
 *
 * A ratio is NULL (never 0 or Inf) when its denominator is missing/invalid — an unmeasured car reads
 * as "unknown", not "free" or "infinitely cheap". Fleet ratios are computed on TOTALS, not as an
 * average of per-car ratios, so a few tiny-distance cars can't distort the headline.
 */
class CostIntelligenceService
{
    private const CACHE_TTL = 300;

    public function __construct(
        private RealProfitService $profit,
        private FuelMileageService $mileage,
        private FleetUtilizationService $utilization,
    ) {}

    /**
     * @return array{vehicles: array<int,array<string,mixed>>, summary: array<string,mixed>}
     */
    public function fleet(): array
    {
        return Cache::remember('intelligence:cost:v1', self::CACHE_TTL, fn () => $this->build());
    }

    private function build(): array
    {
        // Numerator + rentals — the shared profit engine (lifetime, rentals only), keyed by vehicle_id.
        $bridge = $this->profit->vehicleBridge();

        // Distance denominator — validated lifetime travel, re-keyed by vehicle_id.
        $mileage = collect($this->mileage->fleet()['vehicles'])->keyBy('vehicle_id');

        // Days denominator — canonical in-service days per vehicle.
        $utilization = collect($this->utilization->report()['cars'])->keyBy('vehicle_id');

        $rows = Vehicle::query()
            ->select('id', 'plate_no', 'make', 'model', 'status')
            ->get()
            ->map(function ($v) use ($bridge, $mileage, $utilization) {
                $b           = $bridge[$v->id] ?? ['maintenance' => 0.0, 'contracts' => 0];
                $maintenance = round((float) ($b['maintenance'] ?? 0), 2);
                $rentals     = (int) ($b['contracts'] ?? 0);

                $km   = $this->positiveIntOrNull(data_get($mileage->get($v->id), 'actual_mileage'));
                $days = $this->positiveIntOrNull(data_get($utilization->get($v->id), 'days_in_service'));

                return [
                    'vehicle_id'       => $v->id,
                    'plate'            => $v->plate_no,
                    'car'              => trim((string) ($v->make . ' ' . $v->model)) ?: null,
                    'status'           => $v->status,
                    'maintenance_cost' => $maintenance,
                    'distance_km'      => $km,
                    'days_in_service'  => $days,
                    'rentals'          => $rentals,
                ] + $this->computeRatios($maintenance, $km, $days, $rentals);
            })
            ->sortByDesc('maintenance_cost')
            ->values();

        $totalMaint   = round($rows->sum('maintenance_cost'), 2);
        $totalKm      = (int) $rows->sum('distance_km');
        $totalDays    = (int) $rows->sum('days_in_service');
        $totalRentals = (int) $rows->sum('rentals');

        $summary = [
            'vehicles'          => $rows->count(),
            'total_maintenance' => $totalMaint,
            'total_km'          => $totalKm,
            'total_days'        => $totalDays,
            'total_rentals'     => $totalRentals,
            'km_unknown'        => $rows->whereNull('distance_km')->count(),
        ] + $this->prefixed('fleet', $this->computeRatios($totalMaint, $totalKm ?: null, $totalDays ?: null, $totalRentals));

        return ['vehicles' => $rows->all(), 'summary' => $summary];
    }

    /**
     * Pure ratio math — divide-by-zero / missing denominator → NULL, never 0 or Inf.
     *
     * @return array{cost_per_km: ?float, cost_per_day: ?float, cost_per_rental: ?float}
     */
    public function computeRatios(float $maintenance, ?int $distanceKm, ?int $daysInService, int $rentals): array
    {
        return [
            'cost_per_km'     => ($distanceKm !== null && $distanceKm > 0) ? round($maintenance / $distanceKm, 4) : null,
            'cost_per_day'    => ($daysInService !== null && $daysInService > 0) ? round($maintenance / $daysInService, 2) : null,
            'cost_per_rental' => ($rentals > 0) ? round($maintenance / $rentals, 2) : null,
        ];
    }

    /** null / ≤0 → null (an unrecorded or invalid denominator is "unknown", not a real value). */
    private function positiveIntOrNull($value): ?int
    {
        if ($value === null) {
            return null;
        }
        $v = (int) $value;

        return $v > 0 ? $v : null;
    }

    /** Prefix ratio keys for the fleet summary (cost_per_km → fleet_cost_per_km, …). */
    private function prefixed(string $prefix, array $ratios): array
    {
        $out = [];
        foreach ($ratios as $key => $value) {
            $out["{$prefix}_{$key}"] = $value;
        }

        return $out;
    }
}
