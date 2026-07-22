<?php

namespace Tests\Crud;

use App\Models\Contract;
use App\Models\Vehicle;
use App\Models\VehicleExpense;

/**
 * Recursive explanation tree — every figure must (a) be reachable from a metric root, (b) prove
 * itself with a reconciliation that ties out, and (c) drill down to the ORIGINAL record (contract →
 * payment, maintenance → line item, economic → purchase). Values come from the same engines as
 * /Profitability, so the tree is a pure explanation layer, never an independent calculation.
 */
class FinancialExplanationTest extends CrudTestCase
{
    public function test_explanation_tree_reconciles_and_drills_to_source_records(): void
    {
        $vid = $this->makeVehicle();
        Vehicle::whereKey($vid)->update([
            'purchase_price' => 100000,
            'purchase_date'  => now()->subYears(2)->toDateString(),
        ]);

        Contract::create([
            'vehicle_id'                 => $vid,
            'contract_no'                => 'C-EXPLAIN-1',
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

        // Expense (sole source) — two sheet lines totalling 1500.
        foreach ([['CHANGE OIL', 1000], ['AL SAFI TYRES', 500]] as $i => [$remarks, $amount]) {
            VehicleExpense::create([
                'car_serial' => 900000 + $vid, 'vehicle_id' => $vid,
                'entry_date' => now()->subDays(20 - $i)->toDateString(), 'account_type' => 'Expence',
                'remarks' => $remarks, 'debit' => $amount, 'credit' => 0, 'amount' => $amount,
                'source' => 'excel', 'imported_at' => now(),
            ]);
        }

        $res = $this->getJson("/api/intelligence/vehicle/{$vid}/financial-explain");
        $res->assertSuccessful();
        $d = data_get($res->json(), 'data');

        $nodes = $d['nodes'];
        $roots = $d['roots'];

        // Every metric root resolves to a real node.
        foreach (['gross_revenue', 'operating_cost', 'maintenance', 'net', 'economic', 'cost_per_km'] as $metric) {
            $this->assertArrayHasKey($roots[$metric], $nodes, "root {$metric} must resolve");
        }

        // Net Profit reconciles: 9800 − 300 − 1500 = 8000, difference 0.
        $net = $nodes[$roots['net']];
        $this->assertEqualsWithDelta(8000.0, $net['value'], 0.01);
        $this->assertTrue($net['reconciliation']['ok']);
        $this->assertEqualsWithDelta(0.0, $net['reconciliation']['difference'], 0.01);

        // No node with a reconciliation is allowed to silently drift, and no reference may dangle.
        foreach ($nodes as $id => $n) {
            if (! empty($n['reconciliation'])) {
                $this->assertTrue($n['reconciliation']['ok'], "node {$id} must tie out (diff {$n['reconciliation']['difference']})");
            }
            foreach ($n['children'] ?? [] as $child) {
                $this->assertArrayHasKey($child, $nodes, "child ref {$child} of {$id} must exist");
            }
            foreach ($n['formula']['terms'] ?? [] as $t) {
                if (! empty($t['ref'])) {
                    $this->assertArrayHasKey($t['ref'], $nodes, "term ref {$t['ref']} of {$id} must exist");
                }
            }
        }

        // Drill path: Gross Revenue → Rental Income → a contract record (the original transaction).
        $gross = $nodes[$roots['gross_revenue']];
        $rentTerm = collect($gross['formula']['terms'])->firstWhere('label', 'Rental Income');
        $this->assertNotNull($rentTerm['ref']);
        $rent = $nodes[$rentTerm['ref']];
        $this->assertNotEmpty($rent['children']);
        // Rental Income → per-contract contribution (rc:) → the original contract record.
        $rcNode = $nodes[$rent['children'][0]];
        $this->assertStringStartsWith('rc:', $rent['children'][0]);
        $contractRef = $rcNode['children'][0];
        $this->assertStringStartsWith('contract:', $contractRef);
        $contractNode = $nodes[$contractRef];
        $this->assertArrayHasKey('record', $contractNode);       // reaches the raw contract fields
        $this->assertSame('C-EXPLAIN-1', $contractNode['record']['Contract no']);

        // Economic Profit drills to the Vehicle Purchase record.
        $eco = $nodes[$roots['economic']];
        $depRef = collect($eco['formula']['terms'])->firstWhere('label', 'Accumulated Depreciation')['ref'];
        $this->assertTrue($nodes[$depRef]['reconciliation']['ok']);
        $this->assertArrayHasKey('purchase', $nodes);
        $this->assertEqualsWithDelta(100000.0, $nodes['purchase']['value'], 0.01);
    }
}
