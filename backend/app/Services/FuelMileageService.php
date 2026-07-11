<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * Fuel & Mileage Reconciliation — the live version of the "fuel_data_plate_summary" report.
 *
 * Every car movement in the fleet is a contract that stamps an odometer OUT and an odometer IN
 * (plus any fuel not refilled = fuel_debit). This service reconciles, per car:
 *
 *   Actual Mileage      = last IN reading − first OUT reading      (real odometer travel)
 *   Contract Mileage    = Σ (IN − OUT) over every contract         (km explained by a movement)
 *   Out-of-Contract km  = Actual − Contract                        (km the car moved with NO contract
 *                                                                    → office use / transport / leakage)
 *   Total Fuel Debit    = Σ fuel_debit                             (fuel charged back for not refilling)
 *
 * All numbers come straight from the synced contracts — no inference, no confidence scores. Data
 * errors (odometer that runs backwards, giant unexplained jumps) are surfaced as flags rather than
 * hidden, because catching them is the whole point of the report.
 */
class FuelMileageService
{
    /** An odometer reading of 0 (or null) means "not recorded" — never a real start-of-life reading. */
    private const NO_READING = 0;

    /**
     * A car is flagged for leakage when the unexplained (out-of-contract) travel is both material in
     * absolute terms and a meaningful share of its total travel — filters out rounding-size noise.
     */
    private const LEAKAGE_MIN_KM = 100;
    private const LEAKAGE_MIN_SHARE = 0.05; // 5%

    /**
     * One reconciliation row per vehicle, plus a fleet-wide summary.
     *
     * Reconciliation is period-based (like the source report): only contracts whose OUT date falls in
     * [$from, $to] are considered, so "actual travel" and "out-of-contract km" describe that window.
     * Pass both null for an all-time view (noisy for cars whose odometer was reset over the years).
     *
     * @return array{vehicles: array<int, array>, summary: array, window: array}
     */
    public function fleet(?string $from = null, ?string $to = null): array
    {
        // Pull every contract that carries an odometer reading, cheapest possible column set, ordered
        // so each vehicle's contracts arrive in chronological (movement) order.
        $byVehicle = $this->windowed(
            Contract::query()
                ->whereNotNull('vehicle_id')
                ->select('vehicle_id', 'contract_no', 'contract_type', 'out_date', 'in_date', 'out_milage', 'in_milage', 'fuel_debit'),
            $from,
            $to
        )
            ->orderBy('vehicle_id')
            ->orderByRaw('out_date is null, out_date')   // real dates first, chronological
            ->orderBy('out_milage')
            ->get()
            ->groupBy('vehicle_id');

        // Names/plates for the cars we have contracts for.
        $vehicles = Vehicle::query()
            ->whereIn('id', $byVehicle->keys())
            ->select('id', 'plate_no', 'make', 'model', 'status')
            ->get()
            ->keyBy('id');

        $rows = collect();
        foreach ($byVehicle as $vehicleId => $contracts) {
            $v = $vehicles[$vehicleId] ?? null;
            $rows->push($this->reconcileVehicle($vehicleId, $v, $contracts));
        }

        $rows = $rows->sortByDesc('out_of_contract')->values();

        $summary = [
            'vehicles'             => $rows->count(),
            'contracts'            => (int) $rows->sum('contracts'),
            'total_actual_km'      => (int) $rows->sum('actual_mileage'),
            'total_contract_km'    => (int) $rows->sum('contract_mileage'),
            'total_out_of_contract' => (int) $rows->sum('out_of_contract'),
            'total_fuel_debit'     => round((float) $rows->sum('total_fuel_debit'), 2),
            'cars_with_leakage'    => $rows->where('leakage_flag', true)->count(),
            'cars_with_rollback'   => $rows->where('rollback_flag', true)->count(),
        ];

        return [
            'vehicles' => $rows->all(),
            'summary'  => $summary,
            'window'   => ['from' => $from, 'to' => $to],
        ];
    }

    /**
     * The per-contract ledger for ONE car — the drill-down behind a summary row. Each leg carries the
     * gap driven BEFORE it (this contract's OUT minus the previous contract's IN) so the exact place a
     * car picked up unexplained kilometres is visible on the timeline.
     *
     * @return array{vehicle: array|null, contracts: array<int, array>, totals: array}
     */
    public function vehicle(int $vehicleId, ?string $from = null, ?string $to = null): array
    {
        $contracts = $this->windowed(
            Contract::query()
                ->where('vehicle_id', $vehicleId)
                ->select('id', 'contract_no', 'contract_type', 'state', 'out_date', 'in_date', 'out_milage', 'in_milage', 'fuel_debit'),
            $from,
            $to
        )
            ->orderByRaw('out_date is null, out_date')
            ->orderBy('out_milage')
            ->get();

        $v = Vehicle::query()->select('id', 'plate_no', 'make', 'model', 'status')->find($vehicleId);

        $prevIn = null;
        $legs = $contracts->map(function ($c) use (&$prevIn) {
            $out = $this->reading($c->out_milage);
            $in = $this->reading($c->in_milage);
            $cm = ($out !== null && $in !== null) ? $in - $out : null;

            // Unexplained travel between the last return and this pickup.
            $gap = ($out !== null && $prevIn !== null) ? $out - $prevIn : null;
            if ($in !== null) {
                $prevIn = $in;
            }

            return [
                'id'               => $c->id,
                'contract_no'      => $c->contract_no,
                'contract_type'    => $c->contract_type,
                'state'            => $c->state,
                'out_date'         => optional($c->out_date)->toDateString(),
                'in_date'          => optional($c->in_date)->toDateString(),
                'out_milage'       => $out,
                'in_milage'        => $in,
                'contract_mileage' => $cm,
                'gap_before'       => $gap,
                'fuel_debit'       => round((float) $c->fuel_debit, 2),
                'open'             => $in === null,
                'negative'         => $cm !== null && $cm < 0,
            ];
        });

        return [
            'vehicle'   => $v ? $this->vehicleCard($vehicleId, $v) : null,
            'contracts' => $legs->all(),
            'totals'    => $this->reconcileVehicle($vehicleId, $v, $contracts),
            'window'    => ['from' => $from, 'to' => $to],
        ];
    }

    /**
     * Restrict a contract query to an OUT-date window. Either bound is optional; a bound of null just
     * leaves that side open. Dates outside YYYY-MM-DD are ignored (treated as "no bound").
     */
    private function windowed($query, ?string $from, ?string $to)
    {
        if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $query->whereDate('out_date', '>=', $from);
        }
        if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $query->whereDate('out_date', '<=', $to);
        }

        return $query;
    }

    /**
     * Collapse one car's contracts into a single reconciliation row.
     */
    private function reconcileVehicle(int $vehicleId, ?Vehicle $v, Collection $contracts): array
    {
        $firstOut = null;   // first genuine OUT reading, chronologically
        $lastIn = null;     // last genuine IN reading, chronologically
        $contractMileage = 0;
        $fuel = 0.0;
        $fuelContracts = 0;
        $openNow = 0;
        $negativeLegs = 0;
        $types = [];

        foreach ($contracts as $c) {
            $out = $this->reading($c->out_milage);
            $in = $this->reading($c->in_milage);

            if ($firstOut === null && $out !== null) {
                $firstOut = $out;
            }
            if ($in !== null) {
                $lastIn = $in;   // contracts are ordered, so the last one wins
            }
            if ($out !== null && $in !== null) {
                $leg = $in - $out;
                $contractMileage += $leg;
                if ($leg < 0) {
                    $negativeLegs++;
                }
            }
            if ($in === null) {
                $openNow++;
            }

            $fuel += (float) $c->fuel_debit;
            if ((float) $c->fuel_debit > 0) {
                $fuelContracts++;
            }

            $t = $c->contract_type ?: '—';
            $types[$t] = ($types[$t] ?? 0) + 1;
        }

        $actual = ($firstOut !== null && $lastIn !== null) ? $lastIn - $firstOut : null;
        $outOfContract = $actual !== null ? $actual - $contractMileage : null;

        $rollback = $actual !== null && $actual < 0;
        $leakage = $actual !== null && $actual > 0
            && $outOfContract > self::LEAKAGE_MIN_KM
            && $outOfContract > $actual * self::LEAKAGE_MIN_SHARE;

        $card = $v ? $this->vehicleCard($vehicleId, $v) : [
            'vehicle_id' => $vehicleId, 'plate' => null, 'car' => null, 'status' => null,
        ];

        return $card + [
            'contracts'        => $contracts->count(),
            'open_now'         => $openNow,
            'first_out'        => $firstOut,
            'last_in'          => $lastIn,
            'actual_mileage'   => $actual,
            'contract_mileage' => $contractMileage,
            'out_of_contract'  => $outOfContract,
            'total_fuel_debit' => round($fuel, 2),
            'fuel_contracts'   => $fuelContracts,
            'type_mix'         => $types,
            'rollback_flag'    => $rollback,
            'leakage_flag'     => $leakage,
            'negative_legs'    => $negativeLegs,
        ];
    }

    private function vehicleCard(int $vehicleId, Vehicle $v): array
    {
        return [
            'vehicle_id' => $vehicleId,
            'plate'      => $v->plate_no,
            'car'        => trim((string) ($v->make . ' ' . $v->model)) ?: null,
            'status'     => $v->status,
        ];
    }

    /** Normalise an odometer field: null or 0 both mean "no reading". */
    private function reading($value): ?int
    {
        if ($value === null) {
            return null;
        }
        $v = (int) $value;

        return $v <= self::NO_READING ? null : $v;
    }
}
