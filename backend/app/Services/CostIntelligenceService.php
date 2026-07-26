<?php

namespace App\Services;

use App\Contracts\VehicleExpenseProvider;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cost Intelligence — maintenance cost per km / per day / per rental, per vehicle, plus a rental-segment
 * ("category") rollup ranking which segment costs the fleet the most.
 *
 * Pure reuse, no parallel cost ledger:
 *   • numerator  — the SAME per-vehicle maintenance spend RealProfitService sums for the Profit Bridge
 *                  (via the one VehicleExpenseProvider seam — Excel today, Odoo later).
 *   • distance   — the SAME validated lifetime travel (last IN − first OUT) FuelMileageService computes.
 *   • days       — the SAME in-service days FleetUtilizationService computes.
 *   • rentals    — rental contract count (type 'C').
 *
 * A ratio is NULL (never 0 or Inf) when its denominator is missing/invalid — an unmeasured car reads
 * as "unknown", not "free" or "infinitely cheap". Fleet ratios are computed on TOTALS, not as an
 * average of per-car ratios, so a few tiny-distance cars can't distort the headline.
 *
 * DATE WINDOW: an optional [from, to] scopes the maintenance spend (numerator), the rentals count and
 * every total/rollup derived from them. Distance and in-service days are lifetime facts that cannot be
 * honestly windowed, so while a window is active cost_per_km and cost_per_day are NULL (the UI shows
 * "—" rather than divide a period cost by a lifetime denominator). No window = lifetime, matching the
 * Profit Bridge exactly.
 */
class CostIntelligenceService
{
    private const CACHE_TTL = 300;

    public function __construct(
        private RealProfitService $profit,
        private FuelMileageService $mileage,
        private FleetUtilizationService $utilization,
        private VehicleExpenseProvider $expenses,
    ) {}

    /**
     * @param  ?string  $from  inclusive lower bound (Y-m-d), or null for lifetime
     * @param  ?string  $to    inclusive upper bound (Y-m-d), or null for lifetime
     * @return array{vehicles: array<int,array<string,mixed>>, by_category: array<int,array<string,mixed>>, summary: array<string,mixed>}
     */
    public function fleet(?string $from = null, ?string $to = null): array
    {
        $from = $this->normalizeDate($from);
        $to   = $this->normalizeDate($to);
        $key  = 'intelligence:cost:v2:' . ($from ?? '∞') . ':' . ($to ?? '∞');

        return Cache::remember($key, self::CACHE_TTL, fn () => $this->build($from, $to));
    }

    private function build(?string $from, ?string $to): array
    {
        $windowed = $from !== null || $to !== null;

        // Numerator + rentals. Lifetime uses the shared Profit Bridge verbatim; a custom window reads
        // the windowed maintenance from the same expense seam and counts contracts that went OUT in it.
        if ($windowed) {
            $maintByVehicle = $this->expenses->totalsByVehicle(null, $from, $to);
            $rentalsByVehicle = $this->windowedRentals($from, $to);
        } else {
            $bridge = $this->profit->vehicleBridge();
            $maintByVehicle = [];
            $rentalsByVehicle = [];
            foreach ($bridge as $vid => $b) {
                $maintByVehicle[(int) $vid]   = (float) ($b['maintenance'] ?? 0);
                $rentalsByVehicle[(int) $vid] = (int) ($b['contracts'] ?? 0);
            }
        }

        // Distance denominator — validated lifetime travel, re-keyed by vehicle_id.
        $mileage = collect($this->mileage->fleet()['vehicles'])->keyBy('vehicle_id');

        // Days denominator — canonical in-service days per vehicle.
        $utilization = collect($this->utilization->report()['cars'])->keyBy('vehicle_id');

        $rows = Vehicle::query()
            ->select('id', 'plate_no', 'make', 'model', 'status', 'sheet_category')
            ->get()
            ->map(function ($v) use ($maintByVehicle, $rentalsByVehicle, $mileage, $utilization, $windowed) {
                $maintenance = round((float) ($maintByVehicle[$v->id] ?? 0), 2);
                $rentals     = (int) ($rentalsByVehicle[$v->id] ?? 0);

                // Lifetime denominators — held null while windowed so no ratio mixes scopes.
                $km   = $windowed ? null : $this->positiveIntOrNull(data_get($mileage->get($v->id), 'actual_mileage'));
                $days = $windowed ? null : $this->positiveIntOrNull(data_get($utilization->get($v->id), 'days_in_service'));

                return [
                    'vehicle_id'       => $v->id,
                    'plate'            => $v->plate_no,
                    'car'              => trim((string) ($v->make . ' ' . $v->model)) ?: null,
                    'status'           => $v->status,
                    'category'         => $v->sheet_category ?: null,
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
            'windowed'          => $windowed,
            'from'              => $from,
            'to'                => $to,
            'total_maintenance' => $totalMaint,
            'total_km'          => $windowed ? null : $totalKm,
            'total_days'        => $windowed ? null : $totalDays,
            'total_rentals'     => $totalRentals,
            'km_unknown'        => $rows->whereNull('distance_km')->count(),
        ] + $this->prefixed('fleet', $this->computeRatios(
            $totalMaint,
            $windowed ? null : ($totalKm ?: null),
            $windowed ? null : ($totalDays ?: null),
            $totalRentals,
        ));

        return [
            'vehicles'    => $rows->all(),
            'by_category' => $this->rollupByCategory($rows, $windowed),
            'summary'     => $summary,
        ];
    }

    /**
     * Rollup of expense by rental segment ("category") — the "which category costs us the most" view.
     * Ranked by total maintenance spend, descending. Cars with no category fall into an "Uncategorized"
     * bucket so the totals always reconcile with the fleet figure.
     *
     * @param  \Illuminate\Support\Collection<int,array<string,mixed>>  $rows
     * @return array<int,array<string,mixed>>
     */
    private function rollupByCategory($rows, bool $windowed): array
    {
        return $rows
            ->groupBy(fn ($r) => $r['category'] ?? '—')
            ->map(function ($group, $category) use ($windowed) {
                $maintenance = round($group->sum('maintenance_cost'), 2);
                $km          = (int) $group->sum('distance_km');
                $days        = (int) $group->sum('days_in_service');
                $rentals     = (int) $group->sum('rentals');
                $withCost    = $group->where('maintenance_cost', '>', 0)->count();

                return [
                    'category'         => $category === '—' ? null : $category,
                    'vehicles'         => $group->count(),
                    'vehicles_costing' => $withCost,
                    'maintenance_cost' => $maintenance,
                    'distance_km'      => $windowed ? null : $km,
                    'rentals'          => $rentals,
                ] + $this->computeRatios(
                    $maintenance,
                    $windowed ? null : ($km ?: null),
                    $windowed ? null : ($days ?: null),
                    $rentals,
                );
            })
            ->sortByDesc('maintenance_cost')
            ->values()
            ->all();
    }

    /**
     * Rental contract count per vehicle whose OUT date falls inside [from, to]. Same slice the Profit
     * Bridge counts (type 'C'), just bounded to the window instead of lifetime.
     *
     * @return array<int,int>  vehicle_id => rentals in window
     */
    private function windowedRentals(?string $from, ?string $to): array
    {
        return DB::table('contracts')
            ->where('contract_type', 'C')
            ->whereNotNull('vehicle_id')
            ->when($from, fn ($q) => $q->whereDate('out_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('out_date', '<=', $to))
            ->groupBy('vehicle_id')
            ->pluck(DB::raw('COUNT(*)'), 'vehicle_id')
            ->map(fn ($n) => (int) $n)
            ->all();
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

    /** Validate a Y-m-d string; anything unparseable becomes null (treated as "no bound"). */
    private function normalizeDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::createFromFormat('Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
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
