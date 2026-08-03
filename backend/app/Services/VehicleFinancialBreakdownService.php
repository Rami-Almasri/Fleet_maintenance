<?php

namespace App\Services;

use App\Contracts\VehicleExpenseProvider;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\Vehicle;
use App\Services\Expenses\ExpenseCategoryClassifier;

/**
 * Financial traceability for ONE vehicle — the drill-down behind every number on the Fleet
 * Profitability table. It answers "where did this come from?" for gross revenue, operating cost,
 * maintenance, net profit and economic profit.
 *
 * EXACTNESS GUARANTEE (requirement: totals must match the profitability report exactly): the header
 * totals are taken verbatim from RealProfitService::vehicleBridge() — the SAME engine the report uses.
 * The per-source rows are pure decomposition (each contract via RealProfitService::contractProfit(),
 * the same maintenance rows the aggregate sums, DepreciationService for the asset side). No business
 * logic is duplicated or changed; this only reads existing data through existing relations.
 */
class VehicleFinancialBreakdownService
{
    public function __construct(
        private RealProfitService $profit,
        private DepreciationService $depreciation,
        private CostIntelligenceService $cost,
        private VehicleExpenseProvider $expenses,
    ) {}

    /** @return array<string,mixed> */
    public function forVehicle(Vehicle $vehicle): array
    {
        // ---- Authoritative totals — identical to the /Profitability row for this car. ------------
        $bridge = $this->profit->vehicleBridge([$vehicle->id]);
        $b = $bridge[$vehicle->id] ?? [
            'gross_revenue' => 0.0, 'operating_cost' => 0.0, 'maintenance' => 0.0,
            'net_profit' => 0.0, 'contracts' => 0,
        ];
        $gross     = round((float) $b['gross_revenue'], 2);
        $operating = round((float) $b['operating_cost'], 2);
        $maint     = round((float) $b['maintenance'], 2);
        $net       = round((float) $b['net_profit'], 2);

        // ---- Revenue + operating detail — each rental decomposed by the SAME per-contract engine. -
        $contracts = Contract::with('customer:id,name_en,customer_no')
            ->where('contract_type', 'C')
            ->where('vehicle_id', $vehicle->id)
            ->orderByDesc('out_date')
            ->get();

        $revenueRows   = [];
        $operatingRows = [];
        // Raw accumulators for reconciliation — summed from the SAME field lists the engine uses
        // (RealProfitService constants), over the SAME records shown as rows, so the sum ties back
        // to the headline exactly. If the drill-down ever listed a different set than the engine
        // counts, the difference would surface instead of being hidden.
        $sumRents = 0.0; $sumDisc = 0.0; $sumUsage = 0.0; $sumComm = 0.0; $sumDriver = 0.0;
        foreach ($contracts as $c) {
            $p = $this->profit->contractProfit($c);   // core_revenue, realized_usage, operating_cost

            $sumRents += (float) $c->rents_debit;
            $sumDisc  += (float) $c->contract_discount;
            foreach (RealProfitService::USAGE_CREDIT as $col) {
                $sumUsage += (float) ($c->$col ?? 0);
            }

            $revenueRows[] = [
                'contract_id'     => $c->id,
                'contract_no'     => $c->contract_no,
                'customer'        => optional($c->customer)->name_en,
                'customer_no'     => optional($c->customer)->customer_no,
                'out_date'        => optional($c->out_date)->toDateString(),
                'in_date'         => optional($c->in_date)->toDateString(),
                'days'            => $c->days !== null ? (int) $c->days : null,
                'day_price'       => round((float) $c->day_price, 2),
                'rents_billed'    => round((float) $c->rents_debit, 2),
                'discount'        => round((float) $c->contract_discount, 2),
                'usage_collected' => $p['realized_usage'],
                'collected'       => round((float) $c->contract_credit, 2),
                'contribution'    => round($p['core_revenue'] + $p['realized_usage'], 2),
                // Audit trail
                'source_module'   => 'Contracts (OfficeManager)',
                'created_at'      => optional($c->created_at)->toIso8601String(),
                'updated_at'      => optional($c->updated_at)->toIso8601String(),
            ];

            $comm1 = round((float) $c->salesman_commission_value1, 2);
            $comm2 = round((float) $c->salesman_commission_value2, 2);
            $coDrv = round((float) $c->co_driver_cost, 2);
            $sumComm   += (float) $c->salesman_commission_value1 + (float) $c->salesman_commission_value2;
            $sumDriver += (float) $c->co_driver_cost;
            if ($p['operating_cost'] != 0.0) {
                $operatingRows[] = [
                    'contract_id'     => $c->id,
                    'contract_no'     => $c->contract_no,
                    'out_date'        => optional($c->out_date)->toDateString(),
                    'commission_1'    => $comm1,
                    'commission_2'    => $comm2,
                    'co_driver_cost'  => $coDrv,
                    'subtotal'        => $p['operating_cost'],
                    'source_module'   => 'Contracts (OfficeManager)',
                ];
            }
        }

        // Payment records against those rentals (native P- payments), mapped to their contract no.
        $contractNoById = $contracts->pluck('contract_no', 'id');
        $payments = Payment::whereIn('contract_id', $contracts->pluck('id'))
            ->orderByDesc('paid_on')
            ->get(['payment_ref', 'contract_id', 'amount', 'paid_on', 'method', 'reference'])
            ->map(fn ($pay) => [
                'payment_ref' => $pay->payment_ref,
                'contract_no' => $contractNoById[$pay->contract_id] ?? null,
                'amount'      => round((float) $pay->amount, 2),
                'paid_on'     => optional($pay->paid_on)->toDateString(),
                'method'      => $pay->method,
                'reference'   => $pay->reference,
            ])->all();

        // ---- Expense detail — every line behind the expense total, straight from the pluggable
        //      provider (Excel today, Odoo later). These ARE the remarks / date / amount history the
        //      drawer renders; no maintenance table / OM / voucher data is read. -----------------------
        $expenseSource  = $this->expenses->source();
        $expenseHistory = $this->expenses->history($vehicle->id);
        $maintRows = [];
        foreach ($expenseHistory as $i => $ln) {
            $maintRows[] = [
                'id'           => 'exp-' . $i,
                'date'         => $ln['date'],
                'remarks'      => $ln['remarks'],
                'amount'       => round((float) $ln['amount'], 2),
                'account_type' => $ln['account_type'],
                // Operational bucket + the term that decided it, so the drawer can filter 262 lines
                // down to "insurance" or "tyres" and still show why each line landed there.
                'category'         => $ln['category'] ?? ExpenseCategoryClassifier::UNCATEGORISED,
                'category_label'   => $ln['category_label'] ?? 'Uncategorised',
                'category_matched' => $ln['category_matched'] ?? null,
                // history() returns EVERY line; the ones the total leaves out are flagged, never hidden.
                'excluded'         => (bool) ($ln['excluded'] ?? false),
                'source_module' => $expenseSource['label'] ?? 'Expenses sheet',
            ];
        }

        // ---- Asset side — depreciation → economic profit. ----------------------------------------
        $dep       = $this->depreciation->forVehicle($vehicle);
        $economic  = $dep['has_data'] ? round($net - $dep['accumulated_depreciation'], 2) : null;

        // ---- Cost Intelligence — maintenance ÷ km / day / rental. Denominators + ratios come from the
        //      SAME CostIntelligenceService the /cost-intelligence board uses, so every figure matches
        //      that page exactly. The numerator IS $maint (identical maintenance spend as above).
        $costRow = collect($this->cost->fleet()['vehicles'])->firstWhere('vehicle_id', $vehicle->id) ?: [];
        $costIntel = [
            'maintenance_cost' => $maint,
            'distance_km'      => $costRow['distance_km'] ?? null,
            'days_in_service'  => $costRow['days_in_service'] ?? null,
            'rentals'          => (int) $b['contracts'],
            'cost_per_km'      => $costRow['cost_per_km'] ?? null,
            'cost_per_day'     => $costRow['cost_per_day'] ?? null,
            'cost_per_rental'  => $costRow['cost_per_rental'] ?? null,
        ];

        // ---- Reconciliation & formula transparency (EXPLANATION ONLY — no profitability logic is
        //      recomputed here). Each `computed` sums the same records shown as rows, from the same
        //      field lists the engine uses, and must tie back to the engine's headline exactly.
        $grossComputed     = round($sumRents - $sumDisc + $sumUsage, 2);
        $operatingComputed = round($sumComm + $sumDriver, 2);
        // The maintenance total counts only the lines that ARE cost — excluded categories (sub-rental
        // recharges) sit in $maintRows so the drawer can show them, but must not enter the sum or the
        // reconciliation would fail against a total that never included them.
        $maintCostSum      = round(array_sum(array_map(
            fn ($r) => $r['excluded'] ? 0.0 : (float) $r['amount'],
            $maintRows,
        )), 2);
        $excludedRows      = array_values(array_filter($maintRows, fn ($r) => $r['excluded']));
        $excludedSum       = round(array_sum(array_map(fn ($r) => (float) $r['amount'], $excludedRows)), 2);
        $netComputed       = round($grossComputed - $operatingComputed - $maintCostSum, 2);

        $meta = [
            'engine'         => 'RealProfitService',
            'engine_version' => 'Financial Engine v1',
            'source'         => 'RealProfitService::vehicleBridge()',
            'data_window'    => 'Lifetime (all rental contracts + workshop-log maintenance)',
            'currency'       => 'AED',
            'generated_at'   => now()->utc()->toIso8601String(),
        ];

        // Financial lineage — which business records generated this number, top-down.
        $lineage = [
            'label' => 'Economic Profit', 'amount' => $economic, 'source' => 'Net Profit − Depreciation',
            'children' => [
                [
                    'label' => 'Net Profit', 'amount' => $net, 'source' => 'RealProfitService::vehicleBridge()',
                    'children' => [
                        ['label' => 'Gross Revenue', 'amount' => $gross, 'children' => [
                            ['label' => 'Rental contracts', 'count' => count($revenueRows)],
                            ['label' => 'Payments', 'count' => count($payments)],
                        ]],
                        ['label' => 'Operating Costs', 'amount' => $operating, 'children' => [
                            ['label' => 'Commissions', 'amount' => round($sumComm, 2)],
                            ['label' => 'Driver fees', 'amount' => round($sumDriver, 2)],
                        ]],
                        ['label' => 'Maintenance Costs', 'amount' => $maint, 'children' => [
                            ['label' => 'Expense entries', 'count' => count($maintRows)],
                        ]],
                    ],
                ],
                [
                    'label' => 'Depreciation', 'amount' => $dep['has_data'] ? $dep['accumulated_depreciation'] : null,
                    'children' => [
                        ['label' => 'Vehicle purchase', 'amount' => $dep['purchase_price']],
                        ['label' => 'Depreciation policy', 'note' => ($dep['method'] ?? 'straight_line') . ' · ' . ($dep['useful_life_years'] ?? '—') . 'y · residual ' . round(($dep['residual_pct'] ?? 0) * 100) . '%'],
                    ],
                ],
            ],
        ];

        return [
            'meta'    => $meta,
            'lineage' => $lineage,
            'vehicle' => [
                'id'             => $vehicle->id,
                'plate'          => $vehicle->plate_no,
                'car'            => trim((string) ($vehicle->make . ' ' . $vehicle->model)) ?: null,
                'status'         => $vehicle->status,
                'purchase_price' => $dep['purchase_price'],
                'purchase_date'  => $dep['purchase_date'],
            ],
            'totals' => [
                'gross_revenue'   => $gross,
                'operating_cost'  => $operating,
                'maintenance'     => $maint,
                'net_profit'      => $net,
                'economic_profit' => $economic,
                'rentals'         => (int) $b['contracts'],
            ],
            'revenue' => [
                'total'          => $gross,
                'rows'           => $revenueRows,
                'payments'       => $payments,
                'calculation'    => 'Σ (rent billed − discount + collected usage) over rental contracts (type C)',
                'formula'        => [
                    'rental_income' => round($sumRents, 2),
                    'discounts'     => round($sumDisc, 2),
                    'usage'         => round($sumUsage, 2),
                    'gross_revenue' => $gross,
                ],
                'reconciliation' => $this->reconcile($gross, $grossComputed),
            ],
            'operating' => [
                'total'          => $operating,
                'rows'           => $operatingRows,
                'calculation'    => 'Σ (sales commissions + co-driver fees) over rental contracts',
                'formula'        => [
                    'commissions'    => round($sumComm, 2),
                    'driver_fees'    => round($sumDriver, 2),
                    'operating_cost' => $operating,
                ],
                'reconciliation' => $this->reconcile($operating, $operatingComputed),
            ],
            'maintenance' => [
                'total'          => $maint,
                'rows'           => $maintRows,
                'calculation'    => 'Σ expense lines (' . ($expenseSource['label'] ?? 'expenses sheet') . ')'
                    . ($excludedRows ? ', excluding categories that are not spend on this vehicle' : ''),
                'formula'        => ['maintenance' => $maint],
                'reconciliation' => $this->reconcile($maint, $maintCostSum),
                'source'         => $expenseSource,
                // What the total deliberately leaves out — the policy, and what it cost this vehicle.
                'exclusions'       => $this->expenses->exclusions(),
                'excluded_lines'   => count($excludedRows),
                'excluded_amount'  => $excludedSum,
            ],
            // The explicit formula behind Net Profit, so the number is self-documenting.
            'net_formula' => [
                'gross_revenue'  => $gross,
                'operating_cost' => $operating,
                'maintenance'    => $maint,
                'net_profit'     => $net,
            ],
            'net' => [
                'total'          => $net,
                'calculation'    => 'Gross Revenue − Operating Costs − Maintenance',
                'reconciliation' => $this->reconcile($net, $netComputed),
            ],
            // Net → Economic, with the full depreciation working (price, method, life, residual, book value).
            'economic' => $dep + [
                'net_profit'      => $net,
                'economic_profit' => $economic,
                'calculation'     => 'Net Profit − Accumulated Depreciation',
                'reconciliation'  => $economic !== null
                    ? $this->reconcile($economic, round($net - $dep['accumulated_depreciation'], 2))
                    : null,
            ],
            // Running-cost ratios (maintenance ÷ km / day / rental) — the /cost-intelligence drill-down.
            'cost_intelligence' => $costIntel,
        ];
    }

    /**
     * Reconcile a displayed total against the sum of its contributing records. Difference must be
     * zero; a non-zero difference is EXPOSED (never hidden) so any data divergence is visible to finance.
     *
     * @return array{displayed: float, computed: float, difference: float, reconciled: bool}
     */
    private function reconcile(float $displayed, float $computed): array
    {
        $displayed = round($displayed, 2);
        $computed  = round($computed, 2);
        $diff      = round($displayed - $computed, 2);

        return [
            'displayed'  => $displayed,
            'computed'   => $computed,
            'difference' => $diff,
            'reconciled' => abs($diff) < 0.005,
        ];
    }
}
