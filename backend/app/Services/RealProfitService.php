<?php

namespace App\Services;

use App\Contracts\VehicleExpenseProvider;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Real Net Profit — the ground-truth profitability engine that replaces OfficeManager's opaque
 * `contract_income`. (OM income was reverse-engineered as Rent + KM + Extra-driver + Fuel + Cardoo,
 * billed, with NO costs and NO discount removed — i.e. accrued revenue, not profit.)
 *
 * Per-contract Real Net Profit (agreed definition):
 *   = (Rent billed − Discount)                              core revenue, accrued basis
 *   + (Realized usage = COLLECTED km/fuel/cardoo/…)         surcharges count only once captured
 *   − (Operating cost = sales commissions + co-driver cost) per-contract direct costs
 * VAT, deposit, damages and breaches (wash entries) are excluded entirely.
 *
 * Per-vehicle this powers two views off ONE shared aggregation (vehicleAggregates):
 *   • vehicleBridge() — the LIFETIME gross→net Profit Bridge (gross revenue − operating − maintenance
 *     = net profit) shown on the car profile and the fleet profitability table.
 *   • vehicleYield()  — the trailing-12-month "Negative Yield" flag: a car whose Real Net Profit over
 *     the window is LESS than what it cost to maintain/repair in the same window.
 * Maintenance is kept strictly at the vehicle level (never per contract) so it is never double-counted.
 */
class RealProfitService
{
    /**
     * Usage / surcharge lines — counted only when COLLECTED (credit side).
     * Public so the traceability/drill-down layer can reconcile against the EXACT same field list
     * (single source of truth — the explanation layer never hard-codes its own copy).
     */
    public const USAGE_CREDIT = [
        'km_credit', 'fuel_credit', 'cardoo_credit', 'extra_driver_credit',
        'cdw_credit', 'gps_credit', 'co_driver_credit',
    ];

    /** Per-contract direct operating costs (subtracted from profit). Public for the same reason. */
    public const OPERATING_COST = [
        'salesman_commission_value1', 'salesman_commission_value2', 'co_driver_cost',
    ];

    /** Trailing window (months) for vehicle-level yield. */
    public const YIELD_MONTHS = 12;

    /**
     * Vehicle EXPENSE is read exclusively through this pluggable provider (Excel today, Odoo later) —
     * never from the maintenance tables, OfficeManager, vouchers or GL accounts. The rest of this engine
     * (revenue from contracts, operating cost from contracts) is unchanged.
     */
    public function __construct(private VehicleExpenseProvider $expenses) {}

    /**
     * Per-contract Real Net Profit breakdown. Accepts a contract model or a raw DB row.
     *
     * @return array<string,mixed>
     */
    public function contractProfit(object $c): array
    {
        $num = fn ($k) => (float) ($c->$k ?? 0);

        $coreRevenue   = round($num('rents_debit') - $num('contract_discount'), 2);
        $realizedUsage = round(array_sum(array_map($num, self::USAGE_CREDIT)), 2);
        $operatingCost = round(array_sum(array_map($num, self::OPERATING_COST)), 2);
        $net           = round($coreRevenue + $realizedUsage - $operatingCost, 2);

        return [
            'core_revenue'    => $coreRevenue,
            'realized_usage'  => $realizedUsage,
            'operating_cost'  => $operatingCost,
            'real_net_profit' => $net,
            'om_income'       => $num('contract_income') ?: null,
        ];
    }

    /**
     * Shared per-vehicle aggregation behind BOTH the lifetime Profit Bridge and the trailing-window
     * Negative-Yield flag — the single source so every "what this car earned vs. cost" number on the
     * platform reconciles. Keyed by vehicle_id, it returns the gross→net building blocks:
     *   rent_billed / discount / realized_usage   →  gross_revenue = (rent − discount) + usage
     *   operating_cost = Σ sales commissions + co-driver cost    (per-contract direct costs)
     *   maintenance    = Σ workshop-log cost (sheet + manual)     (vehicle level, never doubled)
     *   contracts      = rental count
     * Rentals only (type 'C'). $months bounds the OUT-date window; null = whole life (lifetime).
     * Two grouped queries, fleet-safe.
     *
     * @param  array<int>|null  $vehicleIds  limit to these vehicles (null = all)
     * @return array<int,array<string,float|int>>
     */
    private function vehicleAggregates(?array $vehicleIds, ?int $months): array
    {
        $cutoff = $months !== null ? Carbon::today()->subMonths($months)->toDateString() : null;

        $usageSql = implode(' + ', array_map(fn ($k) => "COALESCE($k,0)", self::USAGE_CREDIT));
        $costSql  = implode(' + ', array_map(fn ($k) => "COALESCE($k,0)", self::OPERATING_COST));

        // --- Rental revenue & operating cost per vehicle (rentals that went OUT in the window) ---
        $rentals = DB::table('contracts')
            ->where('contract_type', 'C')
            ->whereNotNull('vehicle_id')
            ->when($cutoff, fn ($q) => $q->whereDate('out_date', '>=', $cutoff))
            ->when($vehicleIds, fn ($q) => $q->whereIn('vehicle_id', $vehicleIds))
            ->groupBy('vehicle_id')
            ->select(
                'vehicle_id',
                DB::raw('COUNT(*) as contracts'),
                // Component sums so callers can show "Rent − Discount + Usage − Costs = profit".
                DB::raw('SUM(COALESCE(rents_debit,0)) as rent_billed'),
                DB::raw('SUM(COALESCE(contract_discount,0)) as discount'),
                DB::raw("SUM($usageSql) as realized_usage"),
                DB::raw("SUM($costSql) as operating_cost"),
            )
            ->get()
            ->keyBy('vehicle_id');

        // --- Expense per vehicle within the same window — from the pluggable expense provider (Excel
        //     today, Odoo later), the SOLE source of vehicle expense. It carries the `maintenance` slot
        //     of the Profit Bridge verbatim; no maintenance table / OM / voucher data is read here. ---
        $expense = $this->expenses->totalsByVehicle($vehicleIds, $cutoff, null);

        $ids = array_unique(array_merge($rentals->keys()->all(), array_keys($expense)));

        $out = [];
        foreach ($ids as $vid) {
            $r = $rentals[$vid] ?? null;
            $rentBilled    = round((float) ($r->rent_billed ?? 0), 2);
            $discount      = round((float) ($r->discount ?? 0), 2);
            $realizedUsage = round((float) ($r->realized_usage ?? 0), 2);
            $operatingCost = round((float) ($r->operating_cost ?? 0), 2);

            $out[(int) $vid] = [
                'rent_billed'    => $rentBilled,
                'discount'       => $discount,
                'realized_usage' => $realizedUsage,
                'gross_revenue'  => round($rentBilled - $discount + $realizedUsage, 2),
                'operating_cost' => $operatingCost,
                'maintenance'    => round((float) ($expense[(int) $vid] ?? 0), 2),
                'contracts'      => (int) ($r->contracts ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Per-vehicle LIFETIME Profit Bridge keyed by vehicle_id — the gross→net reconciliation shown on
     * the car profile and the fleet profitability table. "What the car generated on paper vs. what
     * was actually pocketed":
     *   net_profit = gross_revenue − operating_cost − maintenance
     * Same engine as vehicleYield()/Negative-Yield, taken over the whole life of the car (months=null).
     *
     * @param  array<int>|null  $vehicleIds  limit to these vehicles (null = all)
     * @return array<int,array<string,float|int>>
     */
    public function vehicleBridge(?array $vehicleIds = null, ?int $months = null): array
    {
        $out = [];
        foreach ($this->vehicleAggregates($vehicleIds, $months) as $vid => $a) {
            $out[$vid] = $a + [
                'net_profit' => round($a['gross_revenue'] - $a['operating_cost'] - $a['maintenance'], 2),
            ];
        }

        return $out;
    }

    /**
     * Per-vehicle trailing-window performance keyed by vehicle_id: real net profit, maintenance
     * spend, net yield and the Negative-Yield flag. Built on the same vehicleAggregates() engine as
     * the lifetime Profit Bridge, so the two can never diverge.
     *
     * @param  array<int>|null  $vehicleIds  limit to these vehicles (null = all)
     * @return array<int,array<string,mixed>>
     */
    public function vehicleYield(?array $vehicleIds = null, int $months = self::YIELD_MONTHS): array
    {
        $out = [];
        foreach ($this->vehicleAggregates($vehicleIds, $months) as $vid => $a) {
            // Real net profit here is BEFORE maintenance (revenue − operating); maintenance is the
            // separate spend the yield compares it against.
            $p = round($a['gross_revenue'] - $a['operating_cost'], 2);
            $s = $a['maintenance'];
            $out[$vid] = [
                'real_net_profit'   => $p,
                'maintenance_spend' => $s,
                'net_yield'         => round($p - $s, 2),
                'contracts'         => $a['contracts'],
                // Components behind real_net_profit, so the card can show the working.
                'rent_billed'       => $a['rent_billed'],
                'discount'          => $a['discount'],
                'realized_usage'    => $a['realized_usage'],
                'operating_cost'    => $a['operating_cost'],
                // Money pit: it cost real money to maintain AND earned less than that cost.
                'negative_yield'    => $s > 0 && $p < $s,
                'window_months'     => $months,
            ];
        }

        return $out;
    }

    /**
     * Vehicles currently flagged Negative Yield (earning less than they cost to maintain), worst
     * (most negative) first — for the dashboard. Disposed/sold cars are dropped.
     *
     * @return array<int,array<string,mixed>>
     */
    public function negativeYieldVehicles(int $months = self::YIELD_MONTHS): array
    {
        $flagged = array_filter($this->vehicleYield(null, $months), fn ($y) => $y['negative_yield']);
        if (empty($flagged)) {
            return [];
        }

        $vehicles = DB::table('vehicles')
            ->whereIn('id', array_keys($flagged))
            ->whereNotIn('status', ['disposed', 'sold'])
            ->get(['id', 'code', 'plate_no', 'make', 'model', 'year', 'odometer'])
            ->keyBy('id');

        $rows = [];
        foreach ($flagged as $vid => $y) {
            $v = $vehicles[$vid] ?? null;
            if (! $v) {
                continue;   // disposed / sold — not actionable
            }
            $rows[] = array_merge($y, [
                'vehicle_id' => (int) $vid,
                'code'       => $v->code,
                'plate'      => $v->plate_no,
                'car'        => trim($v->make . ' ' . $v->model) ?: null,
                'year'       => $v->year,
                'odometer'   => $v->odometer !== null ? (int) $v->odometer : null,
            ]);
        }

        usort($rows, fn ($a, $b) => $a['net_yield'] <=> $b['net_yield']);   // most negative first
        return $rows;
    }
}
