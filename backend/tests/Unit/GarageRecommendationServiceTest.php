<?php

namespace Tests\Unit;

use App\Services\GarageRecommendationService;
use PHPUnit\Framework\TestCase;

/**
 * DB-free tests for the pure scoring core (GarageRecommendationService::scoreRows). We feed it a
 * hand-built history (rows of garage/model/brand/categories) + the config knobs and assert the ranking,
 * exactly like ContractEligibilityServiceTest feeds a facts struct. No DB, no config() — everything the
 * core needs is injected, so these lock the behaviour the BI prototype was validated against.
 *
 * Scenarios locked here:
 *   • Nissan Patrol + Engine — the focused specialist outranks the high-volume generalist (combo fit),
 *     the volume leader is still surfaced, and a no-Patrol engine shop appears only in "also consider".
 *   • Credibility ramp — a 2-job shop never beats a real specialist / never headlines.
 *   • Thin data — a barely-seen combo still returns a recommendation (fallback).
 *   • No history — empty dataset degrades gracefully (has_history=false, empty primary).
 */
class GarageRecommendationServiceTest extends TestCase
{
    private GarageRecommendationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new GarageRecommendationService();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────

    /** Scoring config — mirrors config/garage_recommendation.php defaults. */
    private function cfg(): array
    {
        return [
            'w_model' => 1.0, 'w_brand' => 0.9, 'w_fault' => 1.1, 'w_combo' => 2.0,
            'credibility_jobs' => 8, 'min_primary_combo' => 3, 'primary_limit' => 4, 'lift_cap' => 3.0,
            'model_focus_min_share' => 0.5, 'model_focus_min_jobs' => 5,
            'brand_conc_min_share' => 0.6, 'brand_conc_min_jobs' => 5,
            'fault_specialist_min_share' => 0.4, 'fault_specialist_min_jobs' => 6, 'fault_specialist_min_lift' => 1.5,
            'proven_combo_min' => 5, 'high_experience_min' => 8, 'also_consider_min_jobs' => 5,
            'confidence_high_matches' => 12, 'confidence_med_matches' => 5,
        ];
    }

    private function qcfg(): array
    {
        return ['enabled' => true, 'min_attempts' => 3, 'penalty_each' => 0.15, 'penalty_max' => 0.6, 'warn_rate' => 0.40];
    }

    private function labels(): array
    {
        return ['engine' => 'Engine', 'bodywork' => 'Bodywork', 'electrical' => 'Electrical'];
    }

    /** One history row. */
    private function row(int $vid, string $garage, string $brand, string $model, array $cats): array
    {
        return [
            'vendor_id' => $vid, 'garage' => $garage,
            'brand' => $brand, 'brand_l' => mb_strtolower($brand),
            'model' => $model, 'model_l' => mb_strtolower($model),
            'categories' => $cats,
        ];
    }

    /** N identical rows. */
    private function rows(int $n, int $vid, string $garage, string $brand, string $model, array $cats): array
    {
        return array_fill(0, $n, $this->row($vid, $garage, $brand, $model, $cats));
    }

    /** The Patrol/Engine world used by most tests. */
    private function fleet(): array
    {
        return array_merge(
            // Nissan Service — a pure Patrol-engine shop (focused specialist, moderate volume).
            $this->rows(20, 1, 'Nissan Service', 'Nissan', 'Patrol', ['engine']),
            // Deals On Wheels — the volume leader: most Patrols overall, but mostly bodywork; a big generalist.
            $this->rows(100, 2, 'Deals On Wheels', 'Nissan', 'Patrol', ['bodywork']),
            $this->rows(15, 2, 'Deals On Wheels', 'Nissan', 'Patrol', ['engine']),
            $this->rows(300, 2, 'Deals On Wheels', 'Chevrolet', 'Camaro', ['bodywork']),
            // GPT — a decent Patrol-engine shop.
            $this->rows(13, 3, 'GPT Garage', 'Nissan', 'Patrol', ['engine']),
            // Alresala — an ENGINE specialist, but on Fords: never touched a Patrol.
            $this->rows(50, 4, 'Alresala', 'Ford', 'Mustang', ['engine']),
            // Tiny — a 2-job Patrol-engine shop: below the credibility / primary threshold.
            $this->rows(2, 5, 'Tiny Shop', 'Nissan', 'Patrol', ['engine']),
        );
    }

    private function rec(array $rows, array $criteria): array
    {
        return $this->svc->scoreRows($rows, $criteria, $this->cfg(), $this->qcfg(), $this->labels(), null);
    }

    // ── tests ────────────────────────────────────────────────────────────────────────

    public function test_patrol_engine_ranks_focused_specialist_first(): void
    {
        $r = $this->rec($this->fleet(), ['model' => 'Patrol', 'faults' => ['engine']]);

        $this->assertTrue($r['has_history']);
        $names = array_column($r['primary'], 'garage');

        // The focused all-Patrol-engine shop wins over the high-volume generalist.
        $this->assertSame('Nissan Service', $r['primary'][0]['garage']);
        $this->assertTrue($r['primary'][0]['is_top']);
        $this->assertContains('Deals On Wheels', $names);
        $this->assertContains('GPT Garage', $names);

        // Nissan Service should be beaten by nobody — its score is the max.
        $this->assertSame(max(array_column($r['primary'], 'score')), $r['primary'][0]['score']);
    }

    public function test_volume_leader_is_surfaced_with_volume_reason(): void
    {
        $r = $this->rec($this->fleet(), ['model' => 'Patrol', 'faults' => ['engine']]);
        $deals = collect($r['primary'])->firstWhere('garage', 'Deals On Wheels');
        $this->assertNotNull($deals);
        $reasons = implode(' | ', array_column($deals['reasons'], 't'));
        // It has the most Patrol jobs overall → its headline should be the volume reason.
        $this->assertStringContainsString('Highest Patrol volume', $reasons);
    }

    public function test_top_pick_reasons_explain_why_in_importance_order(): void
    {
        $r = $this->rec($this->fleet(), ['model' => 'Patrol', 'faults' => ['engine']]);
        $texts = array_column($r['primary'][0]['reasons'], 't');
        // Most important evidence leads: the exact vehicle+fault history.
        $this->assertSame('Proven on Patrol + Engine', $texts[0]);
        $joined = implode(' | ', $texts);
        $this->assertStringContainsString('previous Patrol repairs', $joined); // historical matches
        $this->assertStringContainsString('specialist', $joined);              // specialization
    }

    public function test_engine_specialist_without_the_model_only_appears_in_also_consider(): void
    {
        $r = $this->rec($this->fleet(), ['model' => 'Patrol', 'faults' => ['engine']]);

        // Alresala never worked a Patrol → not a primary (exact-match) recommendation.
        $this->assertNotContains('Alresala', array_column($r['primary'], 'garage'));

        // …but it is the fleet's engine specialist → shown as an alternative.
        $faultAlso = collect($r['also_consider'])->firstWhere('dimension', 'fault');
        $this->assertNotNull($faultAlso);
        $this->assertSame('Alresala', $faultAlso['garage']);
    }

    public function test_credibility_ramp_keeps_tiny_shop_out_of_primary(): void
    {
        $r = $this->rec($this->fleet(), ['model' => 'Patrol', 'faults' => ['engine']]);
        // Tiny Shop has only 2 combo jobs (< min_primary_combo 3) and must not headline.
        $this->assertNotContains('Tiny Shop', array_column($r['primary'], 'garage'));
        $this->assertNotSame('Tiny Shop', $r['primary'][0]['garage']);
    }

    public function test_thin_data_still_returns_a_recommendation_via_fallback(): void
    {
        // Only one garage, only 2 matching jobs — below the primary threshold, so the fallback (>=1) applies.
        $rows = $this->rows(2, 9, 'Lone Garage', 'Jaguar', 'F-Pace', ['engine']);
        $r = $this->rec($rows, ['model' => 'F-Pace', 'faults' => ['engine']]);

        $this->assertTrue($r['has_history']);
        $this->assertNotEmpty($r['primary']);
        $this->assertSame('Lone Garage', $r['primary'][0]['garage']);
    }

    public function test_no_history_degrades_gracefully(): void
    {
        $r = $this->rec([], ['model' => 'Patrol', 'faults' => ['engine']]);
        $this->assertFalse($r['has_history']);
        $this->assertSame(0, $r['total_history']);
        $this->assertEmpty($r['primary']);
        $this->assertEmpty($r['also_consider']);
    }

    public function test_empty_criteria_returns_nothing_asked(): void
    {
        $r = $this->rec($this->fleet(), []);
        $this->assertTrue($r['has_history']);   // data exists…
        $this->assertEmpty($r['primary']);      // …but nothing was asked
    }

    public function test_confidence_and_concentration_reflect_evidence(): void
    {
        $r = $this->rec($this->fleet(), ['model' => 'Patrol', 'faults' => ['engine']]);
        $ns = collect($r['primary'])->firstWhere('garage', 'Nissan Service');
        // 20 matching jobs, all of them → high confidence, 100% specialization (over categorised work).
        $this->assertSame('high', $ns['confidence']);
        $this->assertSame(100, $ns['concentration']);
        // A 0–100 headline match score is present and in range (strong here → high).
        $this->assertArrayHasKey('match_score', $ns);
        $this->assertGreaterThanOrEqual(80, $ns['match_score']);
        $this->assertLessThanOrEqual(100, $ns['match_score']);

        // A thin, single-garage combo → low confidence.
        $thin = $this->rec($this->rows(3, 9, 'Lone', 'Jaguar', 'F-Pace', ['engine']), ['model' => 'F-Pace', 'faults' => ['engine']]);
        $this->assertSame('low', $thin['primary'][0]['confidence']); // 3 matches < med threshold (5)
    }

    public function test_per_fault_coverage_reports_at_garage_and_same_model(): void
    {
        $r = $this->rec($this->fleet(), ['model' => 'Patrol', 'faults' => ['engine', 'bodywork']]);
        $top = $r['primary'][0];

        $this->assertArrayHasKey('fault_coverage', $top);
        $this->assertArrayHasKey('coverage', $top);
        $this->assertSame(2, $top['coverage']['total']);
        foreach ($top['fault_coverage'] as $fc) {
            $this->assertArrayHasKey('at_garage', $fc);
            $this->assertArrayHasKey('same_model', $fc);
            // same-model count can never exceed the garage-wide count for that category.
            $this->assertLessThanOrEqual($fc['at_garage'], $fc['same_model']);
        }
        // the coverage summary is surfaced in the score explanation
        $texts = array_column($top['reasons'], 't');
        $this->assertNotEmpty(array_filter($texts, fn ($x) => str_contains($x, 'Current fault coverage')));
    }

    public function test_fault_only_query_ranks_the_specialist(): void
    {
        // No model — pure "who is best for Engine?". Alresala (50 engine) and Nissan Service (20) lead.
        $r = $this->rec($this->fleet(), ['faults' => ['engine']]);
        $this->assertTrue($r['has_history']);
        $top2 = array_slice(array_column($r['primary'], 'garage'), 0, 2);
        $this->assertContains('Alresala', $top2);
    }
}
