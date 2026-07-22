<?php

namespace App\Services;

use App\Models\Vehicle;

/**
 * Financial EXPLANATION tree — the recursive "explain every number down to the original record" layer
 * behind the Profitability and Cost-Intelligence tables. It answers, for ANY figure, the four questions:
 *   • What is this number?          — label + value + unit
 *   • Where did it come from?       — source_module + drillable children (contracts → payments,
 *                                     maintenance → line items, depreciation → purchase, …)
 *   • How was it calculated?        — an explicit formula (terms + operator) on every derived node
 *   • Why is it exactly this value? — a reconciliation (displayed vs Σ sources, difference, ✓/✗)
 *
 * PURE EXPLANATION LAYER — NO business logic is duplicated. Every value is taken verbatim from
 * VehicleFinancialBreakdownService (which itself wraps the authoritative engines: RealProfitService,
 * DepreciationService, CostIntelligenceService) plus FuelMileageService for the odometer readings.
 * This class only STRUCTURES those numbers into a node graph; it never re-computes a business figure.
 *
 * Output is a flat node registry + a roots map (metric → node id). Children/formula terms reference
 * other nodes by id, so shared sub-trees (Net reuses Gross, Economic reuses Net, Cost reuses
 * Maintenance) are stored once and the frontend can drill recursively without a duplicated payload.
 */
class FinancialExplanationService
{
    /** @var array<string,array<string,mixed>> id → node */
    private array $nodes = [];

    /** The vehicle being explained — used to deep-link record nodes to existing detail pages. */
    private ?int $vehicleId = null;

    public function __construct(
        private VehicleFinancialBreakdownService $breakdown,
        private FuelMileageService $mileage,
    ) {}

    /** @return array<string,mixed> */
    public function forVehicle(Vehicle $vehicle): array
    {
        $this->nodes = [];
        $this->vehicleId = $vehicle->id;
        $d = $this->breakdown->forVehicle($vehicle);

        // Build the revenue + operating sub-trees (also creates the shared contract/payment records).
        $grossId     = $this->buildRevenue($d);
        $operatingId = $this->buildOperating($d);
        $maintId     = $this->buildMaintenance($d);
        $netId       = $this->buildNet($d, $grossId, $operatingId, $maintId);
        $economicId  = $this->buildEconomic($d, $netId);

        // Cost Intelligence — reuses the SAME maintenance sub-tree as its numerator.
        $cost = $this->buildCost($d, $vehicle, $maintId);

        $roots = [
            'revenue'          => $grossId,
            'gross_revenue'    => $grossId,
            'operating'        => $operatingId,
            'operating_cost'   => $operatingId,
            'maintenance'      => $maintId,
            'maintenance_cost' => $maintId,
            'net'              => $netId,
            'net_profit'       => $netId,
            'economic'         => $economicId,
            'economic_profit'  => $economicId,
            'cost'             => $cost['overview'],
            'distance'         => $cost['distance'],
            'distance_km'      => $cost['distance'],
            'cost_per_km'      => $cost['per_km'],
            'cost_per_day'     => $cost['per_day'],
            'cost_per_rental'  => $cost['per_rental'],
            'rentals'          => $cost['rentals'],
        ];

        return [
            'vehicle' => $d['vehicle'],
            'totals'  => $d['totals'],
            'roots'   => $roots,
            'default' => $grossId,
            'nodes'   => $this->nodes,
        ];
    }

    // ---------------------------------------------------------------- Revenue -----------------------

    private function buildRevenue(array $d): string
    {
        $rows     = $d['revenue']['rows'];
        $payments = $d['revenue']['payments'];
        $gross    = (float) $d['totals']['gross_revenue'];

        // Payments grouped by their contract number, so each contract can carry its own receipts.
        $paymentsByContract = [];
        foreach ($payments as $pay) {
            $ref = $pay['payment_ref'] ?: ('PAY-' . count($paymentsByContract));
            $pid = $this->put([
                'id'     => 'payment:' . $ref,
                'label'  => 'Receipt ' . ($pay['payment_ref'] ?: '—'),
                'kind'   => 'record',
                'value'  => $pay['amount'],
                'unit'   => 'AED',
                'record' => [
                    'Payment ref' => $pay['payment_ref'],
                    'Contract'    => $pay['contract_no'],
                    'Amount'      => $pay['amount'],
                    'Paid on'     => $pay['paid_on'],
                    'Method'      => $pay['method'],
                    'Reference'   => $pay['reference'],
                ],
                'source_module' => 'Payments (native P-)',
            ]);
            $paymentsByContract[$pay['contract_no']][] = $pid;
        }

        $rentChildren = $discChildren = $usageChildren = $rentalChildren = [];
        $sumRent = $sumDisc = $sumUsage = 0.0;

        foreach ($rows as $r) {
            $sumRent  += (float) $r['rents_billed'];
            $sumDisc  += (float) $r['discount'];
            $sumUsage += (float) $r['usage_collected'];

            $payIds = $paymentsByContract[$r['contract_no']] ?? [];
            $label  = 'Contract ' . ($r['contract_no'] ?: '—');
            $sub    = trim(($r['customer'] ?: 'No customer') . ' · ' . ($r['out_date'] ?: '—') . ' → ' . ($r['in_date'] ?: '…'));

            // The full contract RECORD — the original business transaction. Drills into its receipts.
            $contractId = $this->put([
                'id'       => 'contract:' . $r['contract_id'],
                'label'    => $label,
                'subtitle' => $sub,
                'kind'     => 'record',
                'value'    => $r['contribution'],
                'unit'     => 'AED',
                'formula'  => [
                    'result_label' => 'Contribution to gross revenue',
                    'result'       => $r['contribution'],
                    'result_unit'  => 'AED',
                    'terms'        => [
                        ['op' => '',  'label' => 'Rent billed',     'value' => $r['rents_billed'],    'unit' => 'AED'],
                        ['op' => '−', 'label' => 'Discount',        'value' => $r['discount'],        'unit' => 'AED'],
                        ['op' => '+', 'label' => 'Collected usage', 'value' => $r['usage_collected'], 'unit' => 'AED'],
                    ],
                ],
                'reconciliation' => $this->recon(
                    $r['contribution'],
                    (float) $r['rents_billed'] - (float) $r['discount'] + (float) $r['usage_collected'],
                    '(rent − discount + usage)',
                ),
                'record' => [
                    'Contract no'      => $r['contract_no'],
                    'Customer'         => $r['customer'],
                    'Customer no'      => $r['customer_no'],
                    'Out date'         => $r['out_date'],
                    'In date'          => $r['in_date'],
                    'Days'             => $r['days'],
                    'Rate / day'       => $r['day_price'],
                    'Rent billed'      => $r['rents_billed'],
                    'Discount'         => $r['discount'],
                    'Collected usage'  => $r['usage_collected'],
                    'Total collected'  => $r['collected'],
                ],
                'children'       => $payIds,
                'children_label' => $payIds ? 'Payments / receipts (' . count($payIds) . ')' : null,
                'link'           => ['to' => '/contracts/' . $r['contract_id'], 'label' => 'Open contract'],
                'source_module'  => $r['source_module'] ?? 'Contracts (OfficeManager)',
            ]);

            $rentalChildren[] = $contractId;

            // Per-contract contribution nodes to each revenue COMPONENT — each drills to the record.
            $rentChildren[] = $this->put([
                'id'       => 'rc:' . $r['contract_id'],
                'label'    => $label,
                'subtitle' => $sub,
                'kind'     => 'record',
                'value'    => $r['rents_billed'],
                'unit'     => 'AED',
                'children' => [$contractId],
                'children_label' => 'Full contract',
                'source_module'  => 'Contracts (OfficeManager)',
            ]);
            if ((float) $r['discount'] != 0.0) {
                $discChildren[] = $this->put([
                    'id' => 'dc:' . $r['contract_id'], 'label' => $label, 'subtitle' => $sub, 'kind' => 'record',
                    'value' => $r['discount'], 'unit' => 'AED', 'children' => [$contractId],
                    'children_label' => 'Full contract', 'source_module' => 'Contracts (OfficeManager)',
                ]);
            }
            if ((float) $r['usage_collected'] != 0.0) {
                $usageChildren[] = $this->put([
                    'id' => 'uc:' . $r['contract_id'], 'label' => $label, 'subtitle' => $sub, 'kind' => 'record',
                    'value' => $r['usage_collected'], 'unit' => 'AED', 'children' => [$contractId],
                    'children_label' => 'Full contract', 'source_module' => 'Contracts (OfficeManager)',
                ]);
            }
        }

        $rentId = $this->put([
            'id' => 'rent_income', 'label' => 'Rental Income', 'kind' => 'component',
            'value' => round($sumRent, 2), 'unit' => 'AED',
            'children' => $rentChildren, 'children_label' => 'Rental contracts (' . count($rentChildren) . ')',
            'reconciliation' => $this->recon(round($sumRent, 2), $this->sumValues($rentChildren), 'Σ rent billed over all contracts'),
            'source_module'  => 'Contracts (OfficeManager)',
        ]);
        $discId = $this->put([
            'id' => 'discounts', 'label' => 'Discounts', 'kind' => 'component',
            'value' => round($sumDisc, 2), 'unit' => 'AED',
            'children' => $discChildren, 'children_label' => 'Discounted contracts (' . count($discChildren) . ')',
            'reconciliation' => $this->recon(round($sumDisc, 2), $this->sumValues($discChildren), 'Σ discount over all contracts'),
            'source_module'  => 'Contracts (OfficeManager)',
        ]);
        $usageId = $this->put([
            'id' => 'usage', 'label' => 'Collected Usage', 'kind' => 'component',
            'value' => round($sumUsage, 2), 'unit' => 'AED',
            'children' => $usageChildren, 'children_label' => 'Contracts with usage (' . count($usageChildren) . ')',
            'reconciliation' => $this->recon(round($sumUsage, 2), $this->sumValues($usageChildren), 'Σ collected km/fuel/cardoo/… (RealProfitService::USAGE_CREDIT)'),
            'source_module'  => 'Contracts (OfficeManager)',
        ]);

        return $this->put([
            'id' => 'gross', 'label' => 'Gross Revenue', 'kind' => 'metric',
            'value' => round($gross, 2), 'unit' => 'AED',
            'formula' => [
                'result_label' => 'Gross Revenue', 'result' => round($gross, 2), 'result_unit' => 'AED',
                'terms' => [
                    ['op' => '',  'label' => 'Rental Income',    'value' => round($sumRent, 2),  'unit' => 'AED', 'ref' => $rentId],
                    ['op' => '−', 'label' => 'Discounts',        'value' => round($sumDisc, 2),  'unit' => 'AED', 'ref' => $discId],
                    ['op' => '+', 'label' => 'Collected Usage',  'value' => round($sumUsage, 2), 'unit' => 'AED', 'ref' => $usageId],
                ],
            ],
            'reconciliation' => $this->recon(round($gross, 2), round($sumRent - $sumDisc + $sumUsage, 2), 'Rental Income − Discounts + Collected Usage'),
            'source_module'  => 'RealProfitService (Profit Bridge)',
        ]);
    }

    // -------------------------------------------------------------- Operating -----------------------

    private function buildOperating(array $d): string
    {
        $operating = (float) $d['totals']['operating_cost'];
        $c1 = $c2 = $cd = [];
        $sc1 = $sc2 = $scd = 0.0;

        foreach ($d['operating']['rows'] as $r) {
            $sc1 += (float) $r['commission_1'];
            $sc2 += (float) $r['commission_2'];
            $scd += (float) $r['co_driver_cost'];
            $label = 'Contract ' . ($r['contract_no'] ?: '—');
            $ref   = 'contract:' . $r['contract_id'];
            if ((float) $r['commission_1'] != 0.0) {
                $c1[] = $this->put(['id' => 'oc1:' . $r['contract_id'], 'label' => $label, 'kind' => 'record',
                    'value' => $r['commission_1'], 'unit' => 'AED', 'children' => [$ref], 'children_label' => 'Full contract',
                    'source_module' => 'Contracts (OfficeManager)']);
            }
            if ((float) $r['commission_2'] != 0.0) {
                $c2[] = $this->put(['id' => 'oc2:' . $r['contract_id'], 'label' => $label, 'kind' => 'record',
                    'value' => $r['commission_2'], 'unit' => 'AED', 'children' => [$ref], 'children_label' => 'Full contract',
                    'source_module' => 'Contracts (OfficeManager)']);
            }
            if ((float) $r['co_driver_cost'] != 0.0) {
                $cd[] = $this->put(['id' => 'ocd:' . $r['contract_id'], 'label' => $label, 'kind' => 'record',
                    'value' => $r['co_driver_cost'], 'unit' => 'AED', 'children' => [$ref], 'children_label' => 'Full contract',
                    'source_module' => 'Contracts (OfficeManager)']);
            }
        }

        $c1Id = $this->put(['id' => 'op_comm1', 'label' => 'Salesman commission 1', 'kind' => 'component',
            'value' => round($sc1, 2), 'unit' => 'AED', 'children' => $c1, 'children_label' => 'Contracts (' . count($c1) . ')',
            'reconciliation' => $this->recon(round($sc1, 2), $this->sumValues($c1), 'Σ salesman_commission_value1'), 'source_module' => 'Contracts (OfficeManager)']);
        $c2Id = $this->put(['id' => 'op_comm2', 'label' => 'Salesman commission 2', 'kind' => 'component',
            'value' => round($sc2, 2), 'unit' => 'AED', 'children' => $c2, 'children_label' => 'Contracts (' . count($c2) . ')',
            'reconciliation' => $this->recon(round($sc2, 2), $this->sumValues($c2), 'Σ salesman_commission_value2'), 'source_module' => 'Contracts (OfficeManager)']);
        $cdId = $this->put(['id' => 'op_codriver', 'label' => 'Co-driver cost', 'kind' => 'component',
            'value' => round($scd, 2), 'unit' => 'AED', 'children' => $cd, 'children_label' => 'Contracts (' . count($cd) . ')',
            'reconciliation' => $this->recon(round($scd, 2), $this->sumValues($cd), 'Σ co_driver_cost'), 'source_module' => 'Contracts (OfficeManager)']);

        return $this->put([
            'id' => 'operating', 'label' => 'Operating Costs', 'kind' => 'metric',
            'value' => round($operating, 2), 'unit' => 'AED',
            'formula' => [
                'result_label' => 'Operating Costs', 'result' => round($operating, 2), 'result_unit' => 'AED',
                'terms' => [
                    ['op' => '',  'label' => 'Salesman commission 1', 'value' => round($sc1, 2), 'unit' => 'AED', 'ref' => $c1Id],
                    ['op' => '+', 'label' => 'Salesman commission 2', 'value' => round($sc2, 2), 'unit' => 'AED', 'ref' => $c2Id],
                    ['op' => '+', 'label' => 'Co-driver cost',        'value' => round($scd, 2), 'unit' => 'AED', 'ref' => $cdId],
                ],
            ],
            'reconciliation' => $this->recon(round($operating, 2), round($sc1 + $sc2 + $scd, 2), 'Commission 1 + Commission 2 + Co-driver'),
            'source_module'  => 'RealProfitService (Profit Bridge)',
        ]);
    }

    // ------------------------------------------------------------ Maintenance -----------------------

    private function buildMaintenance(array $d): string
    {
        $maint  = (float) $d['totals']['maintenance'];
        $source = $d['maintenance']['source']['label'] ?? 'Expenses sheet';
        $entries = [];

        // Every expense line straight from the provider — its own remarks / date / amount, verbatim.
        foreach ($d['maintenance']['rows'] as $ln) {
            $entries[] = $this->put([
                'id'       => 'exp:' . $ln['id'],
                'label'    => $ln['remarks'] ?: 'Expense',
                'subtitle' => trim((string) ($ln['date'] ?: '') . ($ln['account_type'] ? ' · ' . $ln['account_type'] : '')) ?: null,
                'kind'     => 'record',
                'value'    => $ln['amount'], 'unit' => 'AED',
                'record'   => [
                    'Date'         => $ln['date'],
                    'Remarks'      => $ln['remarks'],
                    'Account type' => $ln['account_type'],
                    'Amount'       => $ln['amount'],
                ],
                'source_module' => $source,
            ]);
        }

        return $this->put([
            'id' => 'maint', 'label' => 'Maintenance Cost', 'kind' => 'metric',
            'value' => round($maint, 2), 'unit' => 'AED',
            'children' => $entries, 'children_label' => 'Expense entries (' . count($entries) . ')',
            'reconciliation' => $this->recon(round($maint, 2), $this->sumValues($entries), 'Σ expense lines (' . $source . ')'),
            'source_module'  => $source,
        ]);
    }

    // -------------------------------------------------------------------- Net -----------------------

    private function buildNet(array $d, string $grossId, string $operatingId, string $maintId): string
    {
        $t = $d['totals'];
        return $this->put([
            'id' => 'net', 'label' => 'Net Profit', 'kind' => 'metric',
            'value' => round((float) $t['net_profit'], 2), 'unit' => 'AED',
            'formula' => [
                'result_label' => 'Net Profit', 'result' => round((float) $t['net_profit'], 2), 'result_unit' => 'AED',
                'terms' => [
                    ['op' => '',  'label' => 'Gross Revenue',   'value' => round((float) $t['gross_revenue'], 2),  'unit' => 'AED', 'ref' => $grossId],
                    ['op' => '−', 'label' => 'Operating Costs',  'value' => round((float) $t['operating_cost'], 2), 'unit' => 'AED', 'ref' => $operatingId],
                    ['op' => '−', 'label' => 'Maintenance Costs', 'value' => round((float) $t['maintenance'], 2),   'unit' => 'AED', 'ref' => $maintId],
                ],
            ],
            'reconciliation' => $this->recon(
                round((float) $t['net_profit'], 2),
                round((float) $t['gross_revenue'] - (float) $t['operating_cost'] - (float) $t['maintenance'], 2),
                'Gross Revenue − Operating Costs − Maintenance Costs',
            ),
            'source_module' => 'RealProfitService (Profit Bridge)',
        ]);
    }

    // -------------------------------------------------------------- Economic ------------------------

    private function buildEconomic(array $d, string $netId): string
    {
        $e = $d['economic'];
        $net = round((float) $d['totals']['net_profit'], 2);

        if (empty($e['has_data'])) {
            return $this->put([
                'id' => 'economic', 'label' => 'Economic Profit', 'kind' => 'metric',
                'value' => null, 'unit' => 'AED',
                'note' => 'No purchase price / date on file — depreciation, and therefore economic profit, cannot be computed for this car.',
                'source_module' => 'DepreciationService',
            ]);
        }

        // Purchase → residual → depreciable base → annual → accumulated depreciation.
        $purchaseId = $this->put([
            'id' => 'purchase', 'label' => 'Vehicle Purchase', 'kind' => 'record',
            'value' => $e['purchase_price'], 'unit' => 'AED',
            'record' => [
                'Purchase price' => $e['purchase_price'], 'Purchase date' => $e['purchase_date'],
                'Method' => $e['method'], 'Useful life (yrs)' => $e['useful_life_years'],
                'Residual %' => $e['residual_pct'],
            ],
            'link' => $this->vehicleId ? ['to' => '/vehicles/' . $this->vehicleId, 'label' => 'Open vehicle'] : null,
            'source_module' => 'FASTER Asset sheet / Vehicle record',
        ]);
        $residualId = $this->put([
            'id' => 'residual', 'label' => 'Residual Value', 'kind' => 'component',
            'value' => $e['residual_value'], 'unit' => 'AED',
            'formula' => [
                'result_label' => 'Residual Value', 'result' => $e['residual_value'], 'result_unit' => 'AED',
                'terms' => [
                    ['op' => '',  'label' => 'Purchase price', 'value' => $e['purchase_price'], 'unit' => 'AED', 'ref' => $purchaseId],
                    ['op' => '×', 'label' => 'Residual %',     'value' => $e['residual_pct'],   'unit' => ''],
                ],
            ],
            'source_module' => 'DepreciationService policy',
        ]);
        $baseId = $this->put([
            'id' => 'dep_base', 'label' => 'Depreciable Base', 'kind' => 'component',
            'value' => round((float) $e['purchase_price'] - (float) $e['residual_value'], 2), 'unit' => 'AED',
            'formula' => [
                'result_label' => 'Depreciable Base', 'result' => round((float) $e['purchase_price'] - (float) $e['residual_value'], 2), 'result_unit' => 'AED',
                'terms' => [
                    ['op' => '',  'label' => 'Purchase price', 'value' => $e['purchase_price'], 'unit' => 'AED', 'ref' => $purchaseId],
                    ['op' => '−', 'label' => 'Residual value', 'value' => $e['residual_value'], 'unit' => 'AED', 'ref' => $residualId],
                ],
            ],
            'source_module' => 'DepreciationService',
        ]);
        $annualId = $this->put([
            'id' => 'dep_annual', 'label' => 'Annual Depreciation', 'kind' => 'component',
            'value' => $e['annual_depreciation'], 'unit' => 'AED',
            'formula' => [
                'result_label' => 'Annual Depreciation', 'result' => $e['annual_depreciation'], 'result_unit' => 'AED',
                'terms' => [
                    ['op' => '',  'label' => 'Depreciable base', 'value' => round((float) $e['purchase_price'] - (float) $e['residual_value'], 2), 'unit' => 'AED', 'ref' => $baseId],
                    ['op' => '÷', 'label' => 'Useful life',      'value' => $e['useful_life_years'], 'unit' => 'years'],
                ],
            ],
            'source_module' => 'DepreciationService (straight-line)',
        ]);
        // Accumulated depreciation. When the car is past its useful life the straight-line schedule
        // CAPS accumulated at the depreciable base (book value floors at salvage) — so we present the
        // capped identity, and the reconciliation re-derives it the SAME way the engine does, so a
        // fully-depreciated car ties out cleanly instead of raising a false "does not tie out" alarm.
        $base = round((float) $e['purchase_price'] - (float) $e['residual_value'], 2);
        $fully = ! empty($e['fully_depreciated']);
        $depFormula = $fully
            ? [
                'result_label' => 'Accumulated Depreciation', 'result' => $e['accumulated_depreciation'], 'result_unit' => 'AED',
                'terms' => [
                    ['op' => '', 'label' => 'Depreciable base (fully depreciated)', 'value' => $base, 'unit' => 'AED', 'ref' => $baseId],
                ],
            ]
            : [
                'result_label' => 'Accumulated Depreciation', 'result' => $e['accumulated_depreciation'], 'result_unit' => 'AED',
                'terms' => [
                    ['op' => '',  'label' => 'Annual depreciation', 'value' => $e['annual_depreciation'], 'unit' => 'AED', 'ref' => $annualId],
                    ['op' => '×', 'label' => 'Age',                 'value' => $e['age_years'], 'unit' => 'years'],
                ],
            ];
        // Reconcile via the EXACT book-value identity — accumulated depreciation is, by definition,
        // what the asset has lost: purchase price − current book value. Both come straight from
        // DepreciationService, so this ties out to the cent in every case (including the cap), unlike
        // re-multiplying annual × age (which would drift on the rounded age). The formula card above
        // still shows the pedagogical straight-line working.
        $depId = $this->put([
            'id' => 'depreciation', 'label' => 'Accumulated Depreciation', 'kind' => 'component',
            'value' => $e['accumulated_depreciation'], 'unit' => 'AED',
            'formula' => $depFormula,
            'reconciliation' => $this->recon(
                (float) $e['accumulated_depreciation'],
                round((float) $e['purchase_price'] - (float) $e['book_value'], 2),
                'Purchase price − current book value' . ($fully ? ' (fully depreciated — capped at depreciable base)' : ''),
            ),
            'record' => [
                'Age (yrs)' => $e['age_years'], 'Book value' => $e['book_value'],
                '% depreciated' => $e['percent_depreciated'], 'As of' => $e['as_of'] ?? null,
                'Fully depreciated' => $fully ? 'yes' : 'no',
            ],
            'source_module' => 'DepreciationService',
        ]);

        return $this->put([
            'id' => 'economic', 'label' => 'Economic Profit', 'kind' => 'metric',
            'value' => round((float) $e['economic_profit'], 2), 'unit' => 'AED',
            'formula' => [
                'result_label' => 'Economic Profit', 'result' => round((float) $e['economic_profit'], 2), 'result_unit' => 'AED',
                'terms' => [
                    ['op' => '',  'label' => 'Net Profit',                'value' => $net, 'unit' => 'AED', 'ref' => $netId],
                    ['op' => '−', 'label' => 'Accumulated Depreciation', 'value' => $e['accumulated_depreciation'], 'unit' => 'AED', 'ref' => $depId],
                ],
            ],
            'reconciliation' => $this->recon(
                round((float) $e['economic_profit'], 2),
                round($net - (float) $e['accumulated_depreciation'], 2),
                'Net Profit − Accumulated Depreciation',
            ),
            'source_module' => 'DepreciationService + Profit Bridge',
        ]);
    }

    // ---------------------------------------------------------- Cost Intelligence -------------------

    /** @return array<string,string> keys: overview, per_km, per_day, per_rental, distance, rentals */
    private function buildCost(array $d, Vehicle $vehicle, string $maintId): array
    {
        $ci    = $d['cost_intelligence'];
        $maint = round((float) $ci['maintenance_cost'], 2);

        // Distance — last odometer IN − first odometer OUT (the SAME readings FuelMileageService uses).
        $mt       = $this->mileage->vehicle($vehicle->id)['totals'] ?? [];
        $firstOut = $mt['first_out'] ?? null;
        $lastIn   = $mt['last_in'] ?? null;
        $distId = $this->put([
            'id' => 'distance', 'label' => 'Validated Distance', 'kind' => 'component',
            'value' => $ci['distance_km'], 'unit' => 'km',
            'formula' => ($firstOut !== null && $lastIn !== null) ? [
                'result_label' => 'Validated Distance', 'result' => $ci['distance_km'], 'result_unit' => 'km',
                'terms' => [
                    ['op' => '',  'label' => 'Last odometer IN',  'value' => $lastIn,   'unit' => 'km'],
                    ['op' => '−', 'label' => 'First odometer OUT', 'value' => $firstOut, 'unit' => 'km'],
                ],
            ] : null,
            'note' => $ci['distance_km'] === null ? 'No reliable odometer pair on file — distance is unknown, so cost / km is not measured.' : null,
            'source_module' => 'FuelMileageService (contract odometer readings)',
        ]);

        // Days in service — first rental → today. Anchor date = earliest contract OUT date.
        $inService = null;
        foreach ($d['revenue']['rows'] as $r) {
            if ($r['out_date'] && ($inService === null || $r['out_date'] < $inService)) {
                $inService = $r['out_date'];
            }
        }
        $daysId = $this->put([
            'id' => 'days', 'label' => 'In-service Days', 'kind' => 'component',
            'value' => $ci['days_in_service'], 'unit' => 'days',
            'note' => 'Days from the car\'s first rental (in-service date' . ($inService ? ' ' . $inService : '') . ') until today.',
            'source_module' => 'FleetUtilizationService (in-service window)',
        ]);

        // Rentals — the completed-rental count; drills to the contract list.
        $rentalRefs = [];
        foreach ($d['revenue']['rows'] as $r) {
            $rentalRefs[] = 'contract:' . $r['contract_id'];
        }
        $rentalsId = $this->put([
            'id' => 'rentals_count', 'label' => 'Rentals', 'kind' => 'component',
            'value' => (int) $ci['rentals'], 'unit' => 'rentals',
            'children' => $rentalRefs, 'children_label' => 'Rental contracts (' . count($rentalRefs) . ')',
            'source_module' => 'RealProfitService (rental count)',
        ]);

        $perKm = $this->put([
            'id' => 'cost_per_km', 'label' => 'Cost / km', 'kind' => 'ratio',
            'value' => $ci['cost_per_km'], 'unit' => 'AED/km',
            'formula' => [
                'result_label' => 'Cost / km', 'result' => $ci['cost_per_km'], 'result_unit' => 'AED/km',
                'terms' => [
                    ['op' => '',  'label' => 'Maintenance Cost',   'value' => $maint,             'unit' => 'AED', 'ref' => $maintId],
                    ['op' => '÷', 'label' => 'Validated Distance', 'value' => $ci['distance_km'], 'unit' => 'km',  'ref' => $distId],
                ],
            ],
            'reconciliation' => $ci['cost_per_km'] !== null && $ci['distance_km']
                ? $this->recon((float) $ci['cost_per_km'], $maint / (float) $ci['distance_km'], 'Maintenance ÷ Distance', 0.0001, 4)
                : null,
            'note' => $ci['cost_per_km'] === null ? 'Not measured — the car has no validated distance.' : null,
            'source_module' => 'CostIntelligenceService',
        ]);
        $perDay = $this->put([
            'id' => 'cost_per_day', 'label' => 'Cost / day', 'kind' => 'ratio',
            'value' => $ci['cost_per_day'], 'unit' => 'AED/day',
            'formula' => [
                'result_label' => 'Cost / day', 'result' => $ci['cost_per_day'], 'result_unit' => 'AED/day',
                'terms' => [
                    ['op' => '',  'label' => 'Maintenance Cost', 'value' => $maint,                'unit' => 'AED',  'ref' => $maintId],
                    ['op' => '÷', 'label' => 'In-service Days',  'value' => $ci['days_in_service'], 'unit' => 'days', 'ref' => $daysId],
                ],
            ],
            'reconciliation' => $ci['cost_per_day'] !== null && $ci['days_in_service']
                ? $this->recon((float) $ci['cost_per_day'], $maint / (float) $ci['days_in_service'], 'Maintenance ÷ In-service Days')
                : null,
            'note' => $ci['cost_per_day'] === null ? 'Not measured — the car has no in-service days.' : null,
            'source_module' => 'CostIntelligenceService',
        ]);
        $perRental = $this->put([
            'id' => 'cost_per_rental', 'label' => 'Cost / rental', 'kind' => 'ratio',
            'value' => $ci['cost_per_rental'], 'unit' => 'AED/rental',
            'formula' => [
                'result_label' => 'Cost / rental', 'result' => $ci['cost_per_rental'], 'result_unit' => 'AED/rental',
                'terms' => [
                    ['op' => '',  'label' => 'Maintenance Cost', 'value' => $maint,           'unit' => 'AED',     'ref' => $maintId],
                    ['op' => '÷', 'label' => 'Rentals',          'value' => (int) $ci['rentals'], 'unit' => 'rentals', 'ref' => $rentalsId],
                ],
            ],
            'reconciliation' => $ci['cost_per_rental'] !== null && (int) $ci['rentals'] > 0
                ? $this->recon((float) $ci['cost_per_rental'], $maint / (int) $ci['rentals'], 'Maintenance ÷ Rentals')
                : null,
            'note' => $ci['cost_per_rental'] === null ? 'Not measured — the car has no completed rentals.' : null,
            'source_module' => 'CostIntelligenceService',
        ]);

        $overview = $this->put([
            'id' => 'cost', 'label' => 'Running Cost', 'kind' => 'group',
            'value' => null,
            'children' => [$perKm, $perDay, $perRental],
            'children_label' => 'Running-cost ratios',
            'note' => 'Every ratio is the same logged Maintenance Cost divided by a validated usage figure.',
            'source_module' => 'CostIntelligenceService',
        ]);

        return [
            'overview' => $overview, 'per_km' => $perKm, 'per_day' => $perDay, 'per_rental' => $perRental,
            'distance' => $distId, 'rentals' => $rentalsId,
        ];
    }

    // ------------------------------------------------------------------- Helpers --------------------

    /** Register a node and return its id. */
    private function put(array $node): string
    {
        $this->nodes[$node['id']] = $node;

        return $node['id'];
    }

    /** Sum the `value` of a list of already-registered child node ids (the reconciliation basis). */
    private function sumValues(array $ids): float
    {
        $sum = 0.0;
        foreach ($ids as $id) {
            $sum += (float) ($this->nodes[$id]['value'] ?? 0);
        }

        return round($sum, 2);
    }

    /**
     * Prove a value: displayed vs the sum/re-derivation of its sources. Difference surfaced (never
     * hidden); `ok` within tolerance. $decimals controls the rounding of the compared numbers.
     */
    private function recon(float $displayed, float $sourceSum, string $basis, float $tol = 0.01, int $decimals = 2): array
    {
        $displayed = round($displayed, $decimals);
        $sourceSum = round($sourceSum, $decimals);
        $diff      = round($displayed - $sourceSum, $decimals);

        return [
            'displayed'  => $displayed,
            'source_sum' => $sourceSum,
            'difference' => $diff,
            'ok'         => abs($diff) <= $tol,
            'basis'      => $basis,
        ];
    }
}
