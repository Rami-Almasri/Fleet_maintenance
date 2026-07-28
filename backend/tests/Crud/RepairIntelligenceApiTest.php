<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\MaintenanceTask;
use App\Models\RepairInspection;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * P0 acceptance — the Fleet Knowledge Engine's two READ endpoints back ALL THREE UI entry points
 * (TicketDetailDrawer, CheckpointModal, the fault-creation preview), so exercising them here is the
 * feature test for every entry point. Covers: the frozen contract shape, both endpoints (existing fault +
 * pre-task preview), the empty state, the permission gate, server-side financial redaction, and a bounded
 * query count (no N+1). Boots the real app + RBAC on the isolated laravel_test schema (CrudTestCase).
 */
class RepairIntelligenceApiTest extends CrudTestCase
{
    /** Seed one COMPLETED engine repair on a vehicle (its own ticket), with parts + a QC verdict. */
    private function seedRepair(Vehicle $veh, Vendor $garage, float $cost, int $days, string $result = 'fixed'): MaintenanceTask
    {
        $m = Maintenance::create(['vehicle_id' => $veh->id, 'origin' => 'manual']);
        $resolved = now()->subDays(15);

        $t = new MaintenanceTask([
            'maintenance_id' => $m->id, 'vehicle_id' => $veh->id, 'kind' => 'fault',
            'symptom' => 'Engine Noise', 'category_key' => 'engine', 'status' => 'completed',
            'current_vendor_id' => $garage->id, 'root_cause' => 'Timing chain wear',
            'parts_cost' => $cost, 'labor_cost' => 150,
            'started_at' => now()->subDays(15 + $days), 'resolved_at' => $resolved,
        ]);
        $t->save();

        MaintenanceLineItem::create([
            'maintenance_id' => $m->id, 'vehicle_id' => $veh->id, 'maintenance_task_id' => $t->id,
            'kind' => 'part', 'description' => 'Timing chain', 'part_number' => 'TC-1',
            'quantity' => 1, 'unit_price' => $cost, 'line_total' => $cost,
        ]);

        RepairInspection::create([
            'maintenance_id' => $m->id, 'vehicle_id' => $veh->id, 'fault_id' => $t->id,
            'result' => $result, 'inspection_date' => $resolved,
        ]);

        return $t;
    }

    /** An OPEN fault (the one an operator is looking at) to query intelligence for. */
    private function seedOpenFault(Vehicle $veh): MaintenanceTask
    {
        $m = Maintenance::create(['vehicle_id' => $veh->id, 'origin' => 'manual']);
        $t = new MaintenanceTask([
            'maintenance_id' => $m->id, 'vehicle_id' => $veh->id, 'kind' => 'fault',
            'symptom' => 'Engine Noise', 'category_key' => 'engine', 'status' => 'pending',
        ]);
        $t->save();

        return $t;
    }

    private function vehicle(string $make = 'Jetour', string $model = 'T2'): Vehicle
    {
        return Vehicle::create(['plate_no' => 'T-' . random_int(10000, 99999), 'vin' => 'VIN' . uniqid(), 'make' => $make, 'model' => $model]);
    }

    /** A history of engine repairs across same-model siblings + the subject's own past repairs. */
    private function seedHistory(Vehicle $subject): void
    {
        $g1 = Vendor::create(['name' => 'APEX', 'type' => 'garage']);
        $g2 = Vendor::create(['name' => 'BUDGET', 'type' => 'garage']);
        $this->seedRepair($subject, $g1, 800, 5);
        $this->seedRepair($subject, $g1, 850, 6);
        for ($i = 1; $i <= 4; $i++) {
            $sib = $this->vehicle('Jetour', 'T2');
            $this->seedRepair($sib, $i % 2 ? $g1 : $g2, 700 + $i * 20, 4 + $i);
        }
    }

    private const CONTRACT = [
        'state', 'message',
        'recommendation' => ['action', 'summary', 'confidence' => ['score', 'band', 'reasons'], 'likely_cause', 'suggested_garage', 'expected_parts', 'expected_cost', 'expected_duration', 'recurrence_risk'],
        'statistics' => ['sample_size', 'success_rate', 'average_duration', 'average_cost', 'recurrence_rate'],
        'similar_repairs', 'explanation' => ['why', 'evidence'], 'financials_visible',
    ];

    /** A user with exactly the given permissions, authenticated. */
    private function actingWith(array $perms): User
    {
        $u = User::create(['name' => 'U ' . uniqid(), 'email' => 'u.' . uniqid() . '@fleet.test', 'password' => Hash::make('x'), 'status' => 'active']);
        foreach ($perms as $p) {
            $u->givePermissionTo($p);
        }
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    // ── Entry point A/C: existing fault (drawer + checkpoint use GET …/{task}/repair-intelligence) ──

    public function test_task_endpoint_returns_the_frozen_contract_with_history(): void
    {
        $subject = $this->vehicle();
        $this->seedHistory($subject);
        $fault = $this->seedOpenFault($subject);

        $res = $this->getJson("/api/maintenance-tasks/{$fault->id}/repair-intelligence");

        $res->assertOk()->assertJsonStructure(['data' => self::CONTRACT]);
        $this->assertContains($res->json('data.state'), ['ready', 'low_confidence']);
        $this->assertGreaterThan(0, $res->json('data.sample_size') ?? $res->json('data.statistics.sample_size'));
        $this->assertNotNull($res->json('data.recommendation.suggested_garage.name'));
        $this->assertNotEmpty($res->json('data.explanation.why'));
        $this->assertNotEmpty($res->json('data.similar_repairs'));
    }

    // ── Entry point B: fault-creation PREVIEW (before a task exists) ──

    public function test_preview_endpoint_works_before_a_task_exists(): void
    {
        $subject = $this->vehicle();
        $this->seedHistory($subject);

        $res = $this->postJson('/api/repair-intelligence/preview', ['vehicle_id' => $subject->id, 'category_key' => 'engine']);

        $res->assertOk()->assertJsonStructure(['data' => self::CONTRACT]);
        $this->assertContains($res->json('data.state'), ['ready', 'low_confidence']);
        $this->assertNotEmpty($res->json('data.similar_repairs'));
    }

    public function test_empty_history_returns_no_history_state_not_an_error(): void
    {
        $fresh = $this->vehicle('Rare', 'Unicorn');

        $res = $this->postJson('/api/repair-intelligence/preview', ['vehicle_id' => $fresh->id, 'category_key' => 'engine']);

        $res->assertOk();
        $this->assertSame('no_history', $res->json('data.state'));
        $this->assertSame([], $res->json('data.similar_repairs'));
        $this->assertNotEmpty($res->json('data.message'));
        // shape stays invariant even when empty
        $res->assertJsonStructure(['data' => self::CONTRACT]);
    }

    // ── Permissions ──

    public function test_requires_maintenance_view_permission(): void
    {
        $subject = $this->vehicle();
        $fault = $this->seedOpenFault($subject);
        $this->actingWith([]); // authenticated, but no maintenance.view

        $this->getJson("/api/maintenance-tasks/{$fault->id}/repair-intelligence")->assertForbidden();
        $this->postJson('/api/repair-intelligence/preview', ['vehicle_id' => $subject->id, 'category_key' => 'engine'])->assertForbidden();
    }

    // ── Financial visibility (server-side redaction) ──

    public function test_cost_is_redacted_for_a_user_without_billing_view(): void
    {
        $subject = $this->vehicle();
        $this->seedHistory($subject);
        $this->actingWith(['maintenance.view']); // NO billing.view

        $res = $this->postJson('/api/repair-intelligence/preview', ['vehicle_id' => $subject->id, 'category_key' => 'engine']);

        $res->assertOk();
        $this->assertFalse($res->json('data.financials_visible'));
        $this->assertNull($res->json('data.recommendation.expected_cost'));
        $this->assertNull($res->json('data.statistics.average_cost'));
        $this->assertNull($res->json('data.similar_repairs.0.cost'));
        // Non-cost intelligence still flows.
        $this->assertNotNull($res->json('data.statistics.average_duration'));
        $this->assertNotEmpty($res->json('data.recommendation.expected_parts'));
    }

    public function test_cost_is_visible_with_billing_view(): void
    {
        $subject = $this->vehicle();
        $this->seedHistory($subject);
        $this->actingWith(['maintenance.view', 'billing.view']);

        $res = $this->postJson('/api/repair-intelligence/preview', ['vehicle_id' => $subject->id, 'category_key' => 'engine']);

        $res->assertOk();
        $this->assertTrue($res->json('data.financials_visible'));
        $this->assertNotNull($res->json('data.recommendation.expected_cost.median'));
        $this->assertNotNull($res->json('data.statistics.average_cost'));
    }

    // ── Performance — the retrieval + aggregation must be a bounded scan, not N+1 ──

    public function test_endpoint_query_count_is_bounded(): void
    {
        $subject = $this->vehicle();
        // A deliberately larger cohort so an N+1 would blow the budget.
        $g = Vendor::create(['name' => 'APEX', 'type' => 'garage']);
        for ($i = 0; $i < 20; $i++) {
            $sib = $this->vehicle('Jetour', 'T2');
            $this->seedRepair($sib, $g, 700 + $i, 5);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->postJson('/api/repair-intelligence/preview', ['vehicle_id' => $subject->id, 'category_key' => 'engine'])->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Retrieval (1) + inspections batch (1) + recurrence batch (1) + a handful of framework/auth reads.
        // Independent of cohort SIZE — that is the point (no per-repair query).
        $this->assertLessThan(25, $count, "Repair intelligence used {$count} queries — looks like an N+1.");
    }
}
