<?php

namespace Tests\Crud;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Models\VehicleExpense;

/**
 * Explainability platform — the shared multi-module graph. Asserts the platform contract:
 *  • one shared DAG across modules (finance + service), no cycles;
 *  • values shared, not duplicated (Net references the single Gross node);
 *  • reverse lineage ("what depends on this") reaches the downstream KPIs/surfaces;
 *  • every reconcilable node ties out; every node carries a confidence grade;
 *  • the service module explains a business RULE (not a transaction) with evidence;
 *  • audit + snapshot context is present.
 */
class ExplainabilityPlatformTest extends CrudTestCase
{
    public function test_platform_graph_is_shared_reversible_and_reconciled(): void
    {
        $vid = $this->makeVehicle();
        Vehicle::whereKey($vid)->update([
            'purchase_price'       => 100000,
            'purchase_date'        => now()->subYears(2)->toDateString(),
            'odometer'             => 60000,
            // Service-due inputs so the SERVICE module evaluates the rule (with evidence) rather than
            // returning "no data": 60000 − 50000 = 10000 km since service ≥ 10000 interval ⇒ due.
            'last_service_odometer' => 50000,
            'service_interval_km'   => 10000,
        ]);

        Contract::create([
            'vehicle_id' => $vid, 'contract_no' => 'C-PLAT-1', 'contract_type' => 'C', 'state' => 'closed',
            'out_date' => now()->subDays(30)->toDateString(), 'in_date' => now()->subDays(10)->toDateString(),
            'days' => 20, 'day_price' => 500, 'rents_debit' => 10000, 'contract_discount' => 500,
            'km_credit' => 300, 'salesman_commission_value1' => 200, 'co_driver_cost' => 100, 'contract_credit' => 9800,
        ]);
        // Maintenance record drives the SERVICE-due module (last-service history, not cost).
        Maintenance::create([
            'vehicle_id' => $vid, 'origin' => 'manual', 'cost' => 1500, 'garage' => 'Test Garage',
            'out_date' => now()->subDays(20)->toDateString(),
        ]);
        // Expense (the sole source of the finance module's expense) — a sheet line of 1500.
        VehicleExpense::create([
            'car_serial' => 900000 + $vid, 'vehicle_id' => $vid, 'entry_date' => now()->subDays(20)->toDateString(),
            'account_type' => 'Expence', 'remarks' => 'CHANGE OIL', 'debit' => 1500, 'credit' => 0,
            'amount' => 1500, 'source' => 'excel', 'imported_at' => now(),
        ]);

        $res = $this->getJson("/api/intelligence/vehicle/{$vid}/explain");
        $res->assertSuccessful();
        $d = data_get($res->json(), 'data');

        $nodes = $d['nodes'];
        $roots = $d['roots'];

        // Two modules present, no cycle warnings, audit context present.
        $this->assertEqualsCanonicalizing(['finance', 'service'], array_column($d['modules'], 'key'));
        $this->assertEmpty($d['warnings']);
        $this->assertSame('1.0.0', $d['context']['engine_version']);
        $this->assertSame('Live', $d['context']['snapshot']);

        // SHARED, not duplicated: Net's Gross operand points at the SAME node id the gross root resolves to.
        $net = $nodes[$roots['net']];
        $grossRef = collect($net['formula']['terms'])->firstWhere('label', 'Gross Revenue')['ref'];
        $this->assertSame($roots['gross_revenue'], $grossRef);

        // Dependencies + reverse dependencies are both derived.
        $this->assertContains($grossRef, $net['dependencies']);
        $this->assertContains($roots['net'], $nodes[$grossRef]['reverse_dependencies']); // gross ← net

        // Reverse lineage climbs to a downstream consumer surface (Executive Report).
        $this->assertArrayHasKey('kpi:fleet_net', $nodes);
        $this->assertContains('kpi:fleet_net', $nodes[$roots['net']]['reverse_dependencies']);
        $this->assertContains('surface:exec', $nodes['kpi:fleet_net']['reverse_dependencies']);

        // Every reconcilable node ties out; every non-consumer node has a confidence grade.
        foreach ($nodes as $id => $n) {
            if (! empty($n['reconciliation'])) {
                $this->assertTrue($n['reconciliation']['ok'], "node {$id} must reconcile");
            }
            if (($n['type'] ?? null) !== 'consumer') {
                $this->assertNotEmpty($n['confidence'] ?? null, "node {$id} must carry confidence");
            }
        }

        // Service module explains a business RULE with evidence + reconciliation.
        $rule = $nodes[$roots['service.service_due']];
        $this->assertSame('rule', $rule['type']);
        $this->assertNotEmpty($rule['business_rule']['statement']);
        $this->assertNotEmpty($rule['evidence']);
        $this->assertTrue($rule['reconciliation']['ok']);
    }
}
