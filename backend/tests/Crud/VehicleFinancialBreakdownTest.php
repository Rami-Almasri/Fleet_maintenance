<?php

namespace Tests\Crud;

use App\Models\Contract;
use App\Models\Vehicle;
use App\Models\VehicleExpense;

/**
 * Financial traceability drill-down — the header totals MUST match the /Profitability row for the
 * same vehicle exactly (requirement: calculations match the existing report), and every section must
 * carry its source rows. Expense comes ONLY from the imported sheet (vehicle_expenses).
 */
class VehicleFinancialBreakdownTest extends CrudTestCase
{
    /** Seed expense lines for a vehicle (the sole source of expense). */
    private function seedExpense(int $vid, array $lines): void
    {
        foreach ($lines as $i => [$remarks, $amount]) {
            VehicleExpense::create([
                'car_serial'   => 900000 + $vid,
                'vehicle_id'   => $vid,
                'entry_date'   => now()->subDays(20 - $i)->toDateString(),
                'account_type' => 'Expence',
                'remarks'      => $remarks,
                'debit'        => $amount,
                'credit'       => 0,
                'amount'       => $amount,
                'source'       => 'excel',
                'imported_at'  => now(),
            ]);
        }
    }

    public function test_breakdown_totals_match_profitability_row_and_expose_sources(): void
    {
        $vid = $this->makeVehicle();
        Vehicle::whereKey($vid)->update([
            'purchase_price' => 100000,
            'purchase_date'  => now()->subYears(2)->toDateString(),
        ]);

        // One rental (type-C) with revenue + operating fields.
        Contract::create([
            'vehicle_id'                 => $vid,
            'contract_no'                => 'C-' . strtoupper(uniqid()),
            'contract_type'              => 'C',
            'state'                      => 'closed',
            'out_date'                   => now()->subDays(30)->toDateString(),
            'in_date'                    => now()->subDays(10)->toDateString(),
            'days'                       => 20,
            'day_price'                  => 500,
            'rents_debit'                => 10000,
            'contract_discount'          => 500,
            'km_credit'                  => 300,
            'salesman_commission_value1' => 200,
            'co_driver_cost'             => 100,
            'contract_credit'            => 9800,
        ]);

        // Expense lines (the sole source of expense) — total 1500 = 1000 + 500.
        $this->seedExpense($vid, [['CHANGE OIL', 1000], ['AL SAFI TYRES', 500]]);

        // The authoritative row from the existing report.
        $prof = $this->getJson('/api/Profitability');
        $prof->assertSuccessful();
        $row = collect(data_get($prof->json(), 'data.vehicles'))->firstWhere('vehicle_id', $vid);
        $this->assertNotNull($row);

        // The drill-down.
        $res = $this->getJson("/api/intelligence/vehicle/{$vid}/financial-breakdown");
        $res->assertSuccessful();
        $d = data_get($res->json(), 'data');
        $t = $d['totals'];

        // EXACT match on the money-only totals (no time component).
        $this->assertSame($row['gross_revenue'], $t['gross_revenue'], 'gross must match the report exactly');
        $this->assertSame($row['operating_cost'], $t['operating_cost'], 'operating must match');
        $this->assertSame($row['maintenance'], $t['maintenance'], 'maintenance must match');
        $this->assertSame($row['net'], $t['net_profit'], 'net must match');
        // Economic carries depreciation (time-based) — match within a cent.
        $this->assertEqualsWithDelta($row['economic_profit'], $t['economic_profit'], 0.5);

        // Sanity on the actual numbers.
        $this->assertEqualsWithDelta(9800.0, $t['gross_revenue'], 0.01);  // 10000 − 500 + 300
        $this->assertEqualsWithDelta(300.0, $t['operating_cost'], 0.01);  // 200 + 100
        $this->assertEqualsWithDelta(1500.0, $t['maintenance'], 0.01);
        $this->assertEqualsWithDelta(8000.0, $t['net_profit'], 0.01);     // 9800 − 300 − 1500

        // Every section exposes its source rows.
        $this->assertNotEmpty($d['revenue']['rows']);
        $rev = $d['revenue']['rows'][0];
        $this->assertArrayHasKey('contract_no', $rev);
        $this->assertEqualsWithDelta(9800.0, $rev['contribution'], 0.01);

        $this->assertNotEmpty($d['operating']['rows']);
        $this->assertNotEmpty($d['maintenance']['rows']);
        // Expense rows are the sheet lines verbatim: remarks + date + amount (oldest first).
        $mnt = $d['maintenance']['rows'][0];
        $this->assertEqualsWithDelta(1000.0, $mnt['amount'], 0.01);
        $this->assertSame('CHANGE OIL', $mnt['remarks']);

        // Net formula + economic working present.
        foreach (['gross_revenue', 'operating_cost', 'maintenance', 'net_profit'] as $k) {
            $this->assertArrayHasKey($k, $d['net_formula']);
        }
        $this->assertTrue($d['economic']['has_data']);
        $this->assertEqualsWithDelta(100000.0, $d['economic']['purchase_price'], 0.01);
        $this->assertArrayHasKey('book_value', $d['economic']);
        $this->assertArrayHasKey('method', $d['economic']);
        $this->assertArrayHasKey('useful_life_years', $d['economic']);
    }

    public function test_every_section_reconciles_and_exposes_formula_metadata_and_lineage(): void
    {
        $vid = $this->makeVehicle();
        Vehicle::whereKey($vid)->update([
            'purchase_price' => 100000,
            'purchase_date'  => now()->subYears(2)->toDateString(),
        ]);
        Contract::create([
            'vehicle_id' => $vid, 'contract_no' => 'C-' . strtoupper(uniqid()), 'contract_type' => 'C', 'state' => 'closed',
            'out_date' => now()->subDays(30)->toDateString(), 'in_date' => now()->subDays(10)->toDateString(),
            'rents_debit' => 10000, 'contract_discount' => 500, 'km_credit' => 300,
            'salesman_commission_value1' => 200, 'co_driver_cost' => 100,
        ]);
        $this->seedExpense($vid, [['CHANGE OIL', 900], ['WAFA AUTO', 400], ['ENOC (PETROL)', 200]]);

        $d = data_get($this->getJson("/api/intelligence/vehicle/{$vid}/financial-breakdown")->assertSuccessful()->json(), 'data');

        // (1) Every section reconciles to ZERO — displayed == sum of contributing records.
        foreach (['revenue' => 'gross_revenue', 'operating' => 'operating_cost', 'maintenance' => 'maintenance', 'net' => 'net_profit'] as $sec => $totalKey) {
            $rec = $d[$sec]['reconciliation'];
            $this->assertEqualsWithDelta(0.0, $rec['difference'], 0.001, "$sec must reconcile to zero");
            $this->assertTrue($rec['reconciled'], "$sec must be reconciled");
            $this->assertEqualsWithDelta($d['totals'][$totalKey], $rec['displayed'], 0.01, "$sec displayed == report total");
        }
        $this->assertTrue($d['economic']['reconciliation']['reconciled']);

        // (4) Formula components reconstruct each total (no mental math needed).
        $rf = $d['revenue']['formula'];
        $this->assertEqualsWithDelta($rf['rental_income'] - $rf['discounts'] + $rf['usage'], $rf['gross_revenue'], 0.01);
        $of = $d['operating']['formula'];
        $this->assertEqualsWithDelta($of['commissions'] + $of['driver_fees'], $of['operating_cost'], 0.01);
        $mf = $d['maintenance']['formula'];
        $this->assertEqualsWithDelta(1500.0, $mf['maintenance'], 0.01);   // Σ expense lines: 900 + 400 + 200

        // (3) Explainability metadata.
        $this->assertSame('Financial Engine v1', $d['meta']['engine_version']);
        $this->assertSame('RealProfitService::vehicleBridge()', $d['meta']['source']);
        $this->assertArrayHasKey('generated_at', $d['meta']);
        $this->assertSame('AED', $d['meta']['currency']);

        // (2) Lineage tree.
        $this->assertSame('Economic Profit', $d['lineage']['label']);
        $this->assertSame('Net Profit', $d['lineage']['children'][0]['label']);

        // (6) Audit fields on the underlying records.
        $this->assertArrayHasKey('source_module', $d['revenue']['rows'][0]);
        $this->assertArrayHasKey('created_at', $d['revenue']['rows'][0]);
        $this->assertArrayHasKey('contract_id', $d['revenue']['rows'][0]);
        $this->assertArrayHasKey('source_module', $d['maintenance']['rows'][0]);
        $this->assertArrayHasKey('amount', $d['maintenance']['rows'][0]);
        $this->assertArrayHasKey('remarks', $d['maintenance']['rows'][0]);
    }

    /**
     * A sub-rental recharge (a car hired IN from another company, billed through the same ledger) is a
     * rental transaction, not spend on this asset. It must stay OUT of the maintenance total — and it
     * must stay VISIBLE, with the reason, or the total is just quietly smaller than the ledger.
     */
    public function test_sub_rental_recharges_are_excluded_from_cost_but_still_shown(): void
    {
        $vid = $this->makeVehicle();
        $this->seedExpense($vid, [
            ['CHANGE OIL', 900],
            ['AL SAFI TYRES', 600],
            ['INV56400/CAR63393/RENT 6 DAYS/RENT 2000/SALIK 30/VAT104', 2000],  // hired-in car
        ]);

        $d = data_get($this->getJson("/api/intelligence/vehicle/{$vid}/financial-breakdown")->assertSuccessful()->json(), 'data');

        // The total counts the two real repair lines and NOT the recharge.
        $this->assertEqualsWithDelta(1500.0, $d['totals']['maintenance'], 0.01, 'sub-rental must not reach the total');
        $this->assertTrue($d['maintenance']['reconciliation']['reconciled'], 'the reduced total must still tie out');
        $this->assertEqualsWithDelta(0.0, $d['maintenance']['reconciliation']['difference'], 0.001);

        // …and it is still returned, flagged, with the policy stated.
        $this->assertCount(3, $d['maintenance']['rows'], 'every line stays visible, including the excluded one');
        $this->assertSame(1, $d['maintenance']['excluded_lines']);
        $this->assertEqualsWithDelta(2000.0, $d['maintenance']['excluded_amount'], 0.01);
        $this->assertSame('sub_rental', collect($d['maintenance']['exclusions'])->pluck('key')->first());
        $this->assertNotEmpty($d['maintenance']['exclusions'][0]['reason'], 'an exclusion without a reason is a black box');

        $excluded = collect($d['maintenance']['rows'])->firstWhere('excluded', true);
        $this->assertSame('sub_rental', $excluded['category']);
        $this->assertEqualsWithDelta(2000.0, $excluded['amount'], 0.01);
    }

    /** The explanation tree must show the excluded lines as their own drillable list, not drop them. */
    public function test_explain_tree_carries_the_excluded_lines_and_their_reason(): void
    {
        $vid = $this->makeVehicle();
        $this->seedExpense($vid, [
            ['CHANGE OIL', 900],
            ['15548_ RENT 6 DAYS FOR CAR', 340],
        ]);

        $d = data_get($this->getJson("/api/intelligence/vehicle/{$vid}/explain")->assertSuccessful()->json(), 'data');
        $maint = $d['nodes'][$d['roots']['maintenance']];

        $this->assertEqualsWithDelta(900.0, $maint['value'], 0.01);
        $this->assertCount(1, $maint['children'], 'only counted lines are children of the total');
        $this->assertCount(1, $maint['excluded_children']);
        $this->assertStringContainsString('340', $maint['excluded_children_label']);
        $this->assertNotEmpty($maint['excluded_note']);
        $this->assertTrue($maint['reconciliation']['ok']);

        $node = $d['nodes'][$maint['excluded_children'][0]];
        $this->assertTrue($node['excluded']);
        $this->assertNotEmpty($node['note'], 'an excluded record must say why it is out');
        $this->assertSame('No — excluded', $node['record']['Counted as cost']);
    }

    /** Every line is filed under a type, and the type facets tie back to the total exactly. */
    public function test_expense_entries_are_typed_and_the_facets_tie_out(): void
    {
        $vid = $this->makeVehicle();
        $this->seedExpense($vid, [
            ['CHANGE OIL', 900],
            ['ENGINE OIL + FILTER', 300],
            ['AL SAFI TYRES', 600],
            ['ADAMJEE INSURANCE ANNUAL PREMIUM', 1200],
        ]);

        $d = data_get($this->getJson("/api/intelligence/vehicle/{$vid}/explain")->assertSuccessful()->json(), 'data');
        $maint = $d['nodes'][$d['roots']['maintenance']];

        $facets = collect($maint['children_facets']);
        $this->assertSame(4, $facets->sum('count'), 'every line belongs to exactly one facet');
        $this->assertEqualsWithDelta($maint['value'], $facets->sum('total'), 0.01, 'facet subtotals must sum to the total');
        $this->assertEqualsWithDelta(1200.0, $facets->firstWhere('key', 'oil_fluids')['total'], 0.01);
        $this->assertEqualsWithDelta(1200.0, $facets->firstWhere('key', 'insurance')['total'], 0.01);

        // Each record names the term that filed it — the bucket has to be defensible line by line.
        $first = $d['nodes'][$maint['children'][0]];
        $this->assertArrayHasKey('Type', $first['record']);
        $this->assertStringContainsString('in the remark', $first['record']['Type matched on']);
    }
}
