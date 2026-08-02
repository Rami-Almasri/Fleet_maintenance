<?php

namespace Tests\Unit;

use App\Services\Knowledge\RepairIntelligencePresenter;
use PHPUnit\Framework\TestCase;

/**
 * Locks the FROZEN public contract + the server-side financial redaction. DB-free — feeds the presenter
 * a hand-built internal envelope and asserts the exact shape the frontend will bind to.
 */
class RepairIntelligencePresenterTest extends TestCase
{
    private function internal(): array
    {
        return [
            'query'       => ['vehicle_id' => 7, 'make' => 'Jetour', 'model' => 'T2', 'category_key' => 'engine', 'symptom' => 'Engine Noise', 'matched_on' => 'category_key'],
            'has_history' => true,
            'sample_size' => 6,
            'statistics'  => ['sample_size' => 6, 'success_rate' => 0.92, 'average_duration' => 5.8, 'average_cost' => 782.5, 'recurrence_rate' => 0.0],
            'confidence'  => ['score' => 72, 'band' => 'high', 'reasons' => ['Includes the same vehicle']],
            'recommendation' => [
                'likely_cause'      => ['value' => 'Timing chain wear', 'share' => 1.0],
                'suggested_garage'  => ['vendor_id' => 12, 'name' => 'APEX', 'jobs' => 4, 'success_rate' => 1.0, 'avg_cost' => 782.5, 'avg_duration_days' => 5.8, 'recurrences' => 0],
                'expected_parts'    => [['part_number' => 'TC-1', 'name' => 'Timing chain', 'freq' => 6]],
                'expected_cost'     => ['p25' => 745, 'median' => 770, 'p75' => 795, 'n' => 6, 'currency' => 'AED'],
                'expected_duration' => ['p25' => 5.25, 'median' => 6, 'p75' => 6.75, 'n' => 6],
                'recurrence_risk'   => 'low',
                'garage_options'    => [],
            ],
            'similar_repairs' => ['tiers' => [
                'vehicle' => [['make' => 'Jetour', 'model' => 'T2', 'plate' => 'J-7', 'symptom' => 'Engine Noise', 'garage' => 'APEX', 'duration_days' => 6, 'total_cost' => 850.0, 'parts' => [['part_number' => 'TC-1', 'name' => 'Timing chain']], 'outcome' => 'verified_fixed', 'resolved_at' => '2026-01-14', 'tier' => 1, 'tier_label' => 'vehicle']],
                'model'   => [['make' => 'Jetour', 'model' => 'T2', 'plate' => 'J-9', 'symptom' => 'Engine Noise', 'garage' => 'BUDGET', 'duration_days' => 4, 'total_cost' => 700.0, 'parts' => [], 'outcome' => 'fixed', 'resolved_at' => '2025-12-01', 'tier' => 2, 'tier_label' => 'model']],
                'make'    => [],
                'fleet'   => [],
            ]],
            'evidence' => [
                ['maintenance_task_id' => 903, 'vehicle' => 'Jetour T2', 'plate' => 'J-7', 'garage' => 'APEX', 'total_cost' => 850.0, 'duration_days' => 6, 'outcome' => 'verified_fixed', 'recurred' => false, 'resolved_at' => '2026-01-14', 'tier' => 'vehicle'],
            ],
            'why' => ['6 comparable repairs on this vehicle', 'No recurrence detected after these repairs'],
        ];
    }

    public function test_contract_shape_with_financials(): void
    {
        $out = RepairIntelligencePresenter::present($this->internal(), true);

        // `fault_knowledge` sits between the repair cohort and the explanation on purpose. It answers a
        // different question from everything around it — what this fault IS, rather than what happened
        // the last time we repaired it — and it is the one section that stays populated when the fleet
        // has no history at all.
        $this->assertSame(['state', 'message', 'recommendation', 'statistics', 'similar_repairs', 'fault_knowledge', 'explanation', 'financials_visible'], array_keys($out));
        $this->assertSame('ready', $out['state']);
        $this->assertTrue($out['financials_visible']);

        $this->assertSame(['action', 'summary', 'confidence', 'likely_cause', 'suggested_garage', 'expected_parts', 'expected_cost', 'expected_duration', 'recurrence_risk'], array_keys($out['recommendation']));
        $this->assertStringContainsString('APEX', $out['recommendation']['action']);
        $this->assertStringContainsString('Timing chain wear', $out['recommendation']['summary']);
        $this->assertSame(770, $out['recommendation']['expected_cost']['median']);

        // similar_repairs is a FLAT, tier-ordered array
        $this->assertCount(2, $out['similar_repairs']);
        $this->assertSame('vehicle', $out['similar_repairs'][0]['tier']);
        $this->assertSame('model', $out['similar_repairs'][1]['tier']);
        $this->assertSame(850.0, $out['similar_repairs'][0]['cost']);
        $this->assertSame('Engine Noise', $out['similar_repairs'][0]['fault']);

        $this->assertSame(782.5, $out['statistics']['average_cost']);
        $this->assertSame(0.92, $out['statistics']['success_rate']);
        $this->assertNotEmpty($out['explanation']['why']);
        $this->assertSame(850.0, $out['explanation']['evidence'][0]['cost']);
    }

    public function test_cost_is_redacted_without_financials(): void
    {
        $out = RepairIntelligencePresenter::present($this->internal(), false);

        $this->assertFalse($out['financials_visible']);
        $this->assertNull($out['recommendation']['expected_cost']);
        $this->assertNull($out['statistics']['average_cost']);
        $this->assertNull($out['similar_repairs'][0]['cost']);
        $this->assertNull($out['explanation']['evidence'][0]['cost']);
        $this->assertArrayNotHasKey('avg_cost', $out['recommendation']['suggested_garage']);
        // Non-cost intelligence still flows.
        $this->assertSame(6, $out['statistics']['sample_size']);
        $this->assertSame(5.8, $out['statistics']['average_duration']);
        $this->assertStringNotContainsString('AED', (string) $out['recommendation']['action']);
    }

    public function test_no_history_state_keeps_shape(): void
    {
        $empty = [
            'query' => ['matched_on' => 'category_key'], 'has_history' => false, 'sample_size' => 0,
            'confidence' => ['score' => 0, 'band' => 'low', 'reasons' => ['No comparable repairs yet']],
            'recommendation' => null,
            'similar_repairs' => ['tiers' => ['vehicle' => [], 'model' => [], 'make' => [], 'fleet' => []]],
            'evidence' => [], 'why' => ['No comparable repairs in fleet history yet'],
        ];
        $out = RepairIntelligencePresenter::present($empty, true);

        $this->assertSame('no_history', $out['state']);
        $this->assertNotEmpty($out['message']);
        $this->assertNull($out['recommendation']['action']);
        $this->assertNull($out['recommendation']['suggested_garage']);
        $this->assertSame(0, $out['statistics']['sample_size']);
        $this->assertSame([], $out['similar_repairs']);
        // shape is invariant even when empty
        $this->assertSame(['action', 'summary', 'confidence', 'likely_cause', 'suggested_garage', 'expected_parts', 'expected_cost', 'expected_duration', 'recurrence_risk'], array_keys($out['recommendation']));
    }

    public function test_low_confidence_state_message(): void
    {
        $d = $this->internal();
        $d['sample_size'] = 2;
        $d['statistics']['sample_size'] = 2;
        $d['confidence'] = ['score' => 30, 'band' => 'low', 'reasons' => ['Only 2 comparable repairs — treat as indicative']];
        $out = RepairIntelligencePresenter::present($d, true);

        $this->assertSame('low_confidence', $out['state']);
        $this->assertStringContainsString('indicative', $out['message']);
    }
}
