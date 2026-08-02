<?php

namespace Tests\Unit;

use App\Services\Garage\Reason;
use App\Services\Garage\RepairOutlook;
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

        // The expected-work panel is the one part of the response that reads the fault ontology out of
        // the database. These tests are about the RANKING, run with no DB booted, so it is stubbed away —
        // what a repair typically involves is knowledge about the fault and cannot change which garage
        // wins. RepairOutlook's own behaviour belongs in a DB-backed test.
        $this->svc = new GarageRecommendationService(null, null, new class extends RepairOutlook {
            // Constructor overridden as a no-op: the real one resolves MatchExplanationService out of
            // the container, and this stub answers without collaborators.
            public function __construct()
            {
            }

            public function for(array $faultsDetail, array $scopeChain = []): array
            {
                return [];
            }
        });
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

    /**
     * The explain / strategy tuning, read from the REAL config file rather than re-declared here. It is a
     * plain array with no facade calls, so a unit test can require it — and these tests then police the
     * shipped defaults instead of a copy that silently drifts from them.
     */
    private function fileCfg(string $key): array
    {
        static $cfg = null;
        $cfg ??= require __DIR__ . '/../../config/garage_recommendation.php';
        return $cfg[$key];
    }

    private function labels(): array
    {
        return ['engine' => 'Engine', 'bodywork' => 'Bodywork', 'electrical' => 'Electrical', 'interior' => 'Interior', 'brakes' => 'Brakes'];
    }

    /** One history row. */
    private function row(int $vid, string $garage, string $brand, string $model, array $cats, string $class = 'SUV'): array
    {
        return [
            'vendor_id' => $vid, 'garage' => $garage,
            'brand' => $brand, 'brand_l' => mb_strtolower($brand),
            'model' => $model, 'model_l' => mb_strtolower($model),
            'class' => $class, 'class_l' => mb_strtolower($class),
            'categories' => $cats,
        ];
    }

    /** N identical rows. */
    private function rows(int $n, int $vid, string $garage, string $brand, string $model, array $cats, string $class = 'SUV'): array
    {
        return array_fill(0, $n, $this->row($vid, $garage, $brand, $model, $cats, $class));
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

    /**
     * @param  array<int, array<string, mixed>>|null  $outcomes  per-vendor forecast rows, keyed by vendor id
     */
    private function rec(array $rows, array $criteria, ?array $outcomes = null): array
    {
        return $this->svc->scoreRows(
            $rows, $criteria, $this->cfg(), $this->qcfg(), $this->labels(), null,
            $this->fileCfg('explain'), $this->fileCfg('strategy'),
            [
                'criticality' => $this->fileCfg('criticality'),
                'business'    => $this->fileCfg('business'),
                'outcomes'    => ['vendors' => $outcomes ?? [], 'fleet' => []],
                // Built from the REAL config values, so a threshold quoted in an explanation cannot
                // drift away from the threshold the engine actually applies.
                'metrics'     => (new \App\Services\Garage\MetricDictionary(
                    (int) $this->fileCfg('outcomes')['comeback_window_days'],
                    (float) $this->fileCfg('explain')['specialization']['full_share'],
                ))->all(),
                'faults_detail' => $criteria['faults_detail'] ?? [],
            ],
        );
    }

    /** A forecast row shaped like GarageOutcomeForecaster's output. */
    private function outcome(float $cost, float $days, int $queue = 0, string $basis = 'garage'): array
    {
        return [
            'cost_by_fault' => [],
            'duration_days' => ['value' => $days, 'basis' => $basis, 'sample' => 40],
            'comeback_pct'  => ['value' => 20.0, 'basis' => $basis, 'sample' => 40],
            'success_pct'   => ['value' => 80.0, 'basis' => $basis, 'sample' => 40],
            'cost_aed'      => ['value' => $cost, 'basis' => $basis, 'sample' => 40],
            'queue_open'    => ['value' => $queue, 'basis' => 'live', 'sample' => $queue],
            'busy'          => $queue >= 4,
            'start_in_days' => $queue * 0.5,
            'complete_in_days' => $queue * 0.5 + $days,
            'transport'     => ['value' => null, 'basis' => 'unavailable', 'sample' => 0],
        ];
    }

    /** @return array<string, array{awarded:float, max:int, applicable:bool}> component key => row */
    private function components(array $garage): array
    {
        $out = [];
        foreach ($garage['breakdown']['components'] as $c) {
            $out[$c['key']] = $c;
        }
        return $out;
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

    // ── The explainable 100-point breakdown ──────────────────────────────────────────

    public function test_breakdown_components_add_up_to_the_headline_score(): void
    {
        $r = $this->rec($this->fleet(), ['model' => 'Patrol', 'class' => 'SUV', 'faults' => ['engine']]);

        foreach ($r['primary'] as $p) {
            $sum = array_sum(array_column($p['breakdown']['components'], 'awarded'));
            // The whole point of the rewrite: what the card shows must literally add up to the number.
            $this->assertSame($p['match_score'], (int) round($sum), "{$p['garage']} components must sum to its score");
            $this->assertSame($p['match_score'], $p['breakdown']['total']);
            // …and the maxima must sum to exactly 100, so "x/100" is never a lie.
            $this->assertSame(100, array_sum(array_column($p['breakdown']['components'], 'max')));
            $this->assertLessThanOrEqual(100, $p['match_score']);
        }
    }

    public function test_breakdown_carries_the_five_briefed_factors_at_their_briefed_weights(): void
    {
        $r = $this->rec($this->fleet(), ['model' => 'Patrol', 'class' => 'SUV', 'faults' => ['engine']]);
        $c = $this->components($r['primary'][0]);

        $this->assertSame(
            ['fault_matching', 'vehicle_similarity', 'historical_success', 'specialization', 'confidence'],
            array_keys($c)
        );
        $this->assertSame(40, $c['fault_matching']['max']);
        $this->assertSame(20, $c['vehicle_similarity']['max']);
        $this->assertSame(20, $c['historical_success']['max']);
        $this->assertSame(10, $c['specialization']['max']);
        $this->assertSame(10, $c['confidence']['max']);
        // Every component must be able to answer "what did you measure?"
        foreach ($c as $row) {
            $this->assertNotEmpty($row['detail']);
            $this->assertNotEmpty($row['question']);
        }
    }

    public function test_fault_matching_states_the_repair_VOLUME_that_earned_the_points(): void
    {
        // THE DEFECT THIS LOCKS. The detail line used to report only which TIER each fault landed in —
        // "2 of 3 faults with same-model history" — while the score ramps linearly on the NUMBER of
        // matching repairs inside that tier. Two garages therefore printed word-for-word identical
        // evidence and sat nine points apart, on the component the card names as decisive. A breakdown
        // whose top row cannot be checked against a count is the black box it was built to replace.
        $r = $this->rec($this->fleet(), ['model' => 'Patrol', 'class' => 'SUV', 'faults' => ['engine']]);
        $byName = [];
        foreach ($r['primary'] as $p) {
            $byName[$p['garage']] = $this->components($p)['fault_matching']['detail'];
        }

        // Same tier (both have Patrol engine history), very different volumes — 20 against 15.
        $this->assertStringContainsString('Engine 20× on this model', $byName['Nissan Service']);
        $this->assertStringContainsString('Engine 15× on this model', $byName['Deals On Wheels']);
        $this->assertNotSame($byName['Nissan Service'], $byName['Deals On Wheels']);

        // A garage with the fault but never on this model must say which volume it is claiming, and
        // say WHICH volume it is — borrowing the same-model wording for other-model history is the
        // same lie one rung down the evidence ladder.
        // No model on the query, so nothing can reach the same-model rung and every fault lands on the
        // other-models one.
        $other = $this->rec(
            $this->rows(12, 9, 'Ford Only', 'Ford', 'Mustang', ['engine']),
            ['faults' => ['engine']],
        );
        $this->assertStringContainsString(
            'Engine 12× on other models',
            $this->components($other['primary'][0])['fault_matching']['detail'],
        );
    }

    public function test_a_long_fault_list_truncates_the_volumes_out_loud(): void
    {
        // Silent truncation reads as "that was all of them", which is the one thing it must not.
        $rows = array_merge(
            $this->rows(6, 1, 'Wide Shop', 'Nissan', 'Patrol', ['engine']),
            $this->rows(4, 1, 'Wide Shop', 'Nissan', 'Patrol', ['brakes']),
            $this->rows(3, 1, 'Wide Shop', 'Nissan', 'Patrol', ['bodywork']),
            $this->rows(2, 1, 'Wide Shop', 'Nissan', 'Patrol', ['electrical']),
            $this->rows(9, 2, 'Other Shop', 'Nissan', 'Patrol', ['engine']),
        );
        $r = $this->rec($rows, [
            'model' => 'Patrol', 'class' => 'SUV',
            'faults' => ['engine', 'brakes', 'bodywork', 'electrical'],
        ]);
        $detail = $this->components($r['primary'][0])['fault_matching']['detail'];

        $this->assertStringContainsString('+1 more', $detail);
    }

    public function test_unmeasurable_factors_are_redistributed_not_silently_zeroed(): void
    {
        // No fault selected → Fault Matching and Specialization cannot be measured at all.
        $r = $this->rec($this->fleet(), ['model' => 'Patrol', 'class' => 'SUV']);
        $c = $this->components($r['primary'][0]);

        $this->assertFalse($c['fault_matching']['applicable']);
        $this->assertFalse($c['specialization']['applicable']);
        $this->assertSame(0, $c['fault_matching']['max']);
        // Their 50 points are redistributed over the three measurable factors — still out of 100.
        $this->assertSame(100, array_sum(array_column($r['primary'][0]['breakdown']['components'], 'max')));
        $this->assertTrue($r['primary'][0]['breakdown']['redistributed']);
        $this->assertNotEmpty($r['primary'][0]['breakdown']['note']);
    }

    public function test_fault_evidence_is_tiered_exact_over_domain_over_general(): void
    {
        // A Yukon in for an engine fault AND an interior fault. All three garages have Yukon engine work
        // (so all three are real candidates); they differ only in their INTERIOR evidence.
        $rows = array_merge(
            // Exact — has done interior work on this very model.
            $this->rows(10, 1, 'Exact Garage', 'GMC', 'Yukon', ['interior']),
            $this->rows(10, 1, 'Exact Garage', 'GMC', 'Yukon', ['engine']),
            // Domain — 200 interior repairs, but never on a Yukon.
            $this->rows(200, 2, 'Domain Garage', 'Nissan', 'Patrol', ['interior'], 'SUV'),
            $this->rows(10, 2, 'Domain Garage', 'GMC', 'Yukon', ['engine']),
            // General — plenty of work, none of it interior on any vehicle.
            $this->rows(60, 3, 'General Garage', 'GMC', 'Yukon', ['engine']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['interior', 'engine']]);

        $pct = [];
        foreach ($r['primary'] as $p) {
            $interior = collect($p['fault_coverage'])->firstWhere('category_key', 'interior');
            $pct[$p['garage']] = ['tier' => $interior['tier'], 'pct' => $interior['pct']];
        }
        $this->assertSame('exact', $pct['Exact Garage']['tier']);
        $this->assertSame('domain', $pct['Domain Garage']['tier']);
        $this->assertSame('general', $pct['General Garage']['tier']);
        // The brief's ordering: exact beats domain beats general, regardless of raw volume — 200 repairs
        // on other models must not outrank 10 on THIS model.
        $this->assertGreaterThan($pct['Domain Garage']['pct'], $pct['Exact Garage']['pct']);
        $this->assertGreaterThan($pct['General Garage']['pct'], $pct['Domain Garage']['pct']);
    }

    public function test_a_single_matching_job_never_reads_as_proven_coverage(): void
    {
        $rows = array_merge(
            $this->rows(1, 1, 'One Job', 'GMC', 'Yukon', ['engine']),
            $this->rows(40, 2, 'Real Specialist', 'GMC', 'Yukon', ['engine']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine']]);
        $one = collect($r['primary'])->firstWhere('garage', 'One Job');

        // One repair is evidence, but it is not proof — it must stay well under a "covered" reading.
        $this->assertLessThan(50, $one['fault_coverage'][0]['pct']);
        $this->assertSame('Real Specialist', $r['primary'][0]['garage']);
        // …and the Confidence factor docks it a second time for the thin sample.
        $this->assertLessThan(5, $this->components($one)['confidence']['awarded']);
    }

    public function test_runner_ups_explain_why_they_lost_and_what_they_are_better_at(): void
    {
        $r = $this->rec($this->fleet(), ['model' => 'Patrol', 'class' => 'SUV', 'faults' => ['engine']]);
        $this->assertNull($r['primary'][0]['why_not'], 'the top pick has nobody to lose to');

        $runner = $r['primary'][1];
        $this->assertNotNull($runner['why_not']);
        $this->assertSame($r['primary'][0]['match_score'] - $runner['match_score'], $runner['why_not']['gap']);
        $this->assertNotEmpty($runner['why_not']['summary']);
        $this->assertStringContainsString($r['primary'][0]['garage'], $runner['why_not']['summary']);
        // Every deficit names the component it was lost on.
        foreach ($runner['why_not']['losses'] as $loss) {
            $this->assertNotEmpty($loss['component']);
            $this->assertLessThan(0, $loss['points']);
        }
    }

    // ── Multi-fault strategy: one garage, or split? ──────────────────────────────────

    /**
     * Garage A owns the engine work, Garage B owns the electrical work. Both faults clear the
     * criticality bar, so a split is genuinely on the table — unlike engine + paint, where the second
     * leg would be cosmetic and never worth a second vehicle move.
     */
    private function splitFleet(): array
    {
        return array_merge(
            $this->rows(30, 1, 'Deals On Wheels', 'GMC', 'Yukon', ['engine']),
            $this->rows(2, 1, 'Deals On Wheels', 'GMC', 'Yukon', ['electrical']),
            $this->rows(28, 2, 'GPT Garage', 'GMC', 'Yukon', ['electrical']),
            $this->rows(1, 2, 'GPT Garage', 'GMC', 'Yukon', ['engine']),
        );
    }

    public function test_split_is_recommended_when_no_garage_covers_both_domains(): void
    {
        $r = $this->rec($this->splitFleet(), ['model' => 'Yukon', 'faults' => ['engine', 'electrical']]);
        $s = $r['strategy'];

        $this->assertSame('split', $s['mode']);
        $this->assertCount(2, $s['legs']);

        $byGarage = [];
        foreach ($s['legs'] as $leg) {
            $byGarage[$leg['garage']] = array_column($leg['faults'], 'category_key');
        }
        $this->assertSame(['engine'], $byGarage['Deals On Wheels']);
        $this->assertSame(['electrical'], $byGarage['GPT Garage']);

        // It must show its work: the split has to beat the single plan by a stated margin — and the
        // reported gain is NET of the downtime the extra vehicle move costs.
        $this->assertGreaterThan($s['confidence_single'], $s['confidence']);
        $this->assertSame($s['confidence'] - $s['confidence_single'], $s['confidence_gain_raw']);
        $this->assertSame($s['confidence_gain_raw'] - $s['downtime_penalty'], $s['confidence_gain']);
        $this->assertGreaterThan(0, $s['downtime_penalty'], 'a second vehicle move is never free');
        $this->assertStringContainsString('Electrical', $s['reason']);
        $this->assertNotEmpty($s['tradeoff']);   // splitting is never free — say what it costs
        $this->assertNotEmpty($s['why_not']);
    }

    public function test_one_capable_garage_is_not_split_for_the_sake_of_it(): void
    {
        // A rival IS marginally better on electrical, but our pick already covers it well — the gap does
        // not justify a second vehicle move.
        $rows = array_merge(
            $this->rows(30, 1, 'All Rounder', 'GMC', 'Yukon', ['engine']),
            // 4 electrical jobs = solid but not saturated (~68%), so the 40-job shop is strictly better
            // on that fault — a real split candidate that the gap threshold then rejects.
            $this->rows(4, 1, 'All Rounder', 'GMC', 'Yukon', ['electrical']),
            $this->rows(40, 2, 'Electric Shop', 'GMC', 'Yukon', ['electrical']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine', 'electrical']]);

        $this->assertSame('single', $r['strategy']['mode']);
        $this->assertSame('All Rounder', $r['strategy']['legs'][0]['garage']);
        // Coverage is strong on both, so the reason must be "good enough", not "nobody else is better".
        $this->assertSame('single_covers_all', $r['strategy']['rejected_reason']);
        $this->assertNotEmpty($r['strategy']['reason']);
    }

    public function test_no_rival_specialist_is_reported_distinctly_from_good_enough_coverage(): void
    {
        // One garage is strongest on BOTH faults. Saying "its record is good enough" would be a
        // different — and possibly false — claim from "nobody else is better", so they are separate.
        $rows = array_merge(
            $this->rows(30, 1, 'Only Real Option', 'GMC', 'Yukon', ['engine']),
            // Only 2 electrical jobs (~51%) — genuinely weak, and nobody else does electrical at all.
            $this->rows(2, 1, 'Only Real Option', 'GMC', 'Yukon', ['electrical']),
            $this->rows(2, 2, 'Weaker Shop', 'GMC', 'Yukon', ['engine']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine', 'electrical']]);

        $this->assertSame('single', $r['strategy']['mode']);
        $this->assertSame('no_better_specialist', $r['strategy']['rejected_reason']);
        $this->assertStringContainsString('No other garage has a stronger record', $r['strategy']['reason']);
        // Weak coverage must still be called out rather than hidden behind the verdict.
        $this->assertStringContainsString('Watch that fault at re-inspection', $r['strategy']['reason']);
    }

    public function test_faults_in_the_same_trade_are_never_split(): void
    {
        // Engine + brakes are both mechanical — one workshop, one visit, however the numbers fall.
        $rows = array_merge(
            $this->rows(30, 1, 'Engine Shop', 'GMC', 'Yukon', ['engine']),
            $this->rows(30, 2, 'Brake Shop', 'GMC', 'Yukon', ['brakes']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine', 'brakes']]);

        $this->assertSame('single', $r['strategy']['mode']);
        $this->assertSame('same_domain', $r['strategy']['rejected_reason']);
    }

    public function test_a_split_leg_needs_real_evidence_not_a_single_job(): void
    {
        // The "electrical specialist" has exactly one electrical job — not worth a second vehicle move.
        $rows = array_merge(
            $this->rows(30, 1, 'Deals On Wheels', 'GMC', 'Yukon', ['engine']),
            $this->rows(1, 2, 'Tiny Electric Shop', 'GMC', 'Yukon', ['electrical']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine', 'electrical']]);

        $this->assertSame('single', $r['strategy']['mode']);
        $this->assertSame('thin_evidence', $r['strategy']['rejected_reason']);
    }

    public function test_severe_body_damage_can_justify_a_split_that_routine_paint_cannot(): void
    {
        // The same two garages and the same two faults. The ONLY difference is that the inspector
        // flagged the body damage as severe — an accident, not a scratch — which escalates it out of
        // the cosmetic tier and makes the second leg worth the vehicle move.
        $rows = array_merge(
            $this->rows(40, 1, 'Engine Shop', 'GMC', 'Yukon', ['engine']),
            $this->rows(40, 2, 'Body Shop', 'GMC', 'Yukon', ['bodywork']),
        );
        $criteria = ['model' => 'Yukon', 'faults' => ['engine', 'bodywork']];

        $cosmetic = $this->rec($rows, $criteria);
        $this->assertSame('single', $cosmetic['strategy']['mode']);
        $this->assertSame('cosmetic_leg', $cosmetic['strategy']['rejected_reason']);

        $severe = $this->rec($rows, $criteria + ['fault_severities' => ['bodywork' => 'high']]);
        $this->assertSame('split', $severe['strategy']['mode']);
        $this->assertCount(2, $severe['strategy']['legs']);
    }

    public function test_single_fault_tickets_have_nothing_to_split(): void
    {
        $s = $this->rec($this->fleet(), ['model' => 'Patrol', 'faults' => ['engine']])['strategy'];
        // Either nothing to say at all, or a business-only verdict — never a split.
        $this->assertTrue($s === null || $s['rejected_reason'] === 'single_fault');
    }

    // ── Fault criticality ────────────────────────────────────────────────────────────

    public function test_a_safety_critical_fault_outweighs_a_cosmetic_one(): void
    {
        // Brake specialist with no paint history vs. paint specialist with no brake history. Brakes are
        // safety critical (×1.5), bodywork is cosmetic (×0.6) — the brake shop must win.
        $rows = array_merge(
            $this->rows(40, 1, 'Brake Shop', 'GMC', 'Yukon', ['brakes']),
            $this->rows(40, 2, 'Paint Shop', 'GMC', 'Yukon', ['bodywork']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['brakes', 'bodywork']]);

        $this->assertSame('Brake Shop', $r['primary'][0]['garage']);
        $this->assertGreaterThan(
            collect($r['primary'])->firstWhere('garage', 'Paint Shop')['match_score'],
            $r['primary'][0]['match_score'],
        );
    }

    public function test_criticality_tiers_are_published_with_the_criteria(): void
    {
        $r = $this->rec($this->fleet(), ['model' => 'Patrol', 'faults' => ['brakes', 'engine', 'bodywork']]);
        $tiers = collect($r['criteria']['fault_criticality'])->keyBy('category_key');

        $this->assertSame('safety_critical', $tiers['brakes']['tier']);
        $this->assertSame('major_mechanical', $tiers['engine']['tier']);
        $this->assertSame('cosmetic', $tiers['bodywork']['tier']);
        // The weights the brief specified, so the UI can show why one fault outvoted another.
        $this->assertSame(1.5, $tiers['brakes']['weight']);
        $this->assertSame(0.6, $tiers['bodywork']['weight']);
    }

    public function test_inspector_severity_escalates_a_fault_but_never_demotes_it(): void
    {
        $rows = $this->rows(20, 1, 'Shop', 'GMC', 'Yukon', ['electrical']);

        $plain = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['electrical']]);
        $severe = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['electrical'], 'fault_severities' => ['electrical' => 'high']]);
        $this->assertSame('operational', $plain['criteria']['fault_criticality'][0]['tier']);
        $this->assertSame('major_mechanical', $severe['criteria']['fault_criticality'][0]['tier']);
        $this->assertTrue($severe['criteria']['fault_criticality'][0]['escalated']);

        // A brake fault logged as "routine" is still safety critical — severity may only escalate.
        $brakes = $this->rec(
            $this->rows(20, 1, 'Shop', 'GMC', 'Yukon', ['brakes']),
            ['model' => 'Yukon', 'faults' => ['brakes'], 'fault_severities' => ['brakes' => 'routine']],
        );
        $this->assertSame('safety_critical', $brakes['criteria']['fault_criticality'][0]['tier']);
    }

    public function test_a_cosmetic_only_leg_never_justifies_moving_the_car(): void
    {
        // The engine shop is weak on paint and a paint specialist exists — but paint alone is not worth
        // a second vehicle move, so the ticket stays in one place.
        $rows = array_merge(
            $this->rows(40, 1, 'Engine Shop', 'GMC', 'Yukon', ['engine']),
            $this->rows(40, 2, 'Paint Shop', 'GMC', 'Yukon', ['bodywork']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine', 'bodywork']]);

        $this->assertSame('single', $r['strategy']['mode']);
        $this->assertSame('cosmetic_leg', $r['strategy']['rejected_reason']);
        $this->assertStringContainsString('cosmetic', $r['strategy']['reason']);
    }

    // ── Business factors & expected outcomes ─────────────────────────────────────────

    public function test_business_axis_scores_cost_speed_and_availability(): void
    {
        $rows = array_merge(
            $this->rows(40, 1, 'Premium Shop', 'GMC', 'Yukon', ['engine']),
            $this->rows(38, 2, 'Value Shop', 'GMC', 'Yukon', ['engine']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine']], [
            1 => $this->outcome(cost: 1200, days: 6, queue: 6),
            2 => $this->outcome(cost: 400, days: 2, queue: 0),
        ]);

        $byName = collect($r['primary'])->keyBy('garage');
        // Cheaper + faster + free now must score higher on the BUSINESS axis…
        $this->assertGreaterThan($byName['Premium Shop']['business']['score'], $byName['Value Shop']['business']['score']);
        // …and earn the badges that justify the claim.
        $this->assertContains('cheapest', $byName['Value Shop']['business']['badges']);
        $this->assertContains('fastest', $byName['Value Shop']['business']['badges']);
        $this->assertContains('no_queue', $byName['Value Shop']['business']['badges']);
        // The technical score stays clean of money — the two axes never merge.
        $this->assertSame(100, array_sum(array_column($byName['Value Shop']['breakdown']['components'], 'max')));
    }

    public function test_a_cheaper_faster_near_equal_garage_is_surfaced_as_a_tradeoff_not_auto_picked(): void
    {
        $rows = array_merge(
            $this->rows(40, 1, 'Top Technical', 'GMC', 'Yukon', ['engine']),
            $this->rows(37, 2, 'Cheaper Faster', 'GMC', 'Yukon', ['engine']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine']], [
            1 => $this->outcome(cost: 1500, days: 7, queue: 8),
            2 => $this->outcome(cost: 400, days: 2, queue: 0),
        ]);

        $this->assertSame('Top Technical', $r['primary'][0]['garage'], 'the technical pick still leads');
        $tradeoff = $r['strategy']['business_tradeoff'];
        $this->assertNotNull($tradeoff);
        $this->assertSame('Cheaper Faster', $tradeoff['candidate']);
        $this->assertNotEmpty($tradeoff['advantages']);
        // The decision is EXPLAINED, never silently switched — the supervisor makes the call.
        $this->assertFalse($tradeoff['auto_switched']);
        $this->assertStringContainsString('points lower technically', $tradeoff['summary']);
    }

    public function test_fleet_fallback_outcomes_never_win_a_business_badge(): void
    {
        // Both garages report the same FLEET median cost — that is not a difference, and claiming one is
        // "cheapest" on a number every garage shares would be a fabricated advantage.
        $rows = array_merge(
            $this->rows(40, 1, 'Shop A', 'GMC', 'Yukon', ['engine']),
            $this->rows(38, 2, 'Shop B', 'GMC', 'Yukon', ['engine']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine']], [
            1 => $this->outcome(cost: 391, days: 1, queue: 0, basis: 'fleet'),
            2 => $this->outcome(cost: 391, days: 1, queue: 0, basis: 'fleet'),
        ]);

        foreach ($r['primary'] as $p) {
            $this->assertNotContains('cheapest', $p['business']['badges']);
            $this->assertNotContains('fastest', $p['business']['badges']);
            $this->assertFalse($p['business']['factors']['cost']['measured']);
        }
    }

    public function test_why_not_states_concrete_evidence_gaps_and_real_strengths(): void
    {
        $rows = array_merge(
            $this->rows(30, 1, 'Yukon Engine Shop', 'GMC', 'Yukon', ['engine']),
            // The runner-up has engine history, but never on a Yukon.
            $this->rows(25, 2, 'Other Model Shop', 'Nissan', 'Patrol', ['engine']),
            $this->rows(3, 2, 'Other Model Shop', 'GMC', 'Yukon', ['engine']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine']], [
            1 => $this->outcome(cost: 1400, days: 8, queue: 9),
            2 => $this->outcome(cost: 350, days: 1, queue: 0),
        ]);

        $runner = $r['primary'][1];
        $this->assertNotEmpty($runner['why_not']['lost_because']);
        // Facts, not adjectives — the sentence names the fault and the counts.
        $this->assertStringContainsString('Engine', implode(' | ', $runner['why_not']['lost_because']));
        // …and the honest counterweight: it is cheaper and faster.
        $kinds = array_column($runner['why_not']['strengths'], 'kind');
        $this->assertContains('cheapest', $kinds);
        $this->assertContains('fastest', $kinds);
    }

    // ── Fault-first recommendations ──────────────────────────────────────────────────

    public function test_every_fault_gets_its_own_winner_not_one_overall_answer(): void
    {
        // The engine shop and the electrical shop each own one fault. A single ticket-wide answer would
        // hide that; the supervisor needs to see both.
        $rows = array_merge(
            $this->rows(30, 1, 'Engine Shop', 'GMC', 'Yukon', ['engine']),
            $this->rows(30, 2, 'Electric Shop', 'GMC', 'Yukon', ['electrical']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine', 'electrical']]);

        $this->assertCount(2, $r['per_fault']);
        $byFault = collect($r['per_fault'])->keyBy('category_key');
        $this->assertSame('Engine Shop', $byFault['engine']['winner']['garage']);
        $this->assertSame('Electric Shop', $byFault['electrical']['winner']['garage']);
        // Each carries the evidence that justifies it, not just a name.
        $this->assertSame(100, $byFault['engine']['winner']['coverage_pct']);
        $this->assertStringContainsString('Engine', $byFault['engine']['reason']['text']);
    }

    public function test_a_fault_recommendation_carries_its_criticality_and_the_operators_own_wording(): void
    {
        $r = $this->rec(
            $this->rows(30, 1, 'Brake Shop', 'GMC', 'Yukon', ['brakes']),
            [
                'model' => 'Yukon',
                'faults' => ['brakes'],
                // The inspector wrote a symptom; the card should echo THAT, not the category name.
                'faults_detail' => [['symptom' => 'Grinding when braking', 'category_key' => 'brakes', 'label' => 'Brakes']],
            ],
        );
        $f = $r['per_fault'][0];

        $this->assertSame('Grinding when braking', $f['symptom']);
        $this->assertSame('safety_critical', $f['criticality']);
        $this->assertSame(1.5, $f['weight']);
    }

    public function test_the_alternative_explains_what_it_trades_away(): void
    {
        // A faster, cheaper shop with a thinner record on this model — exactly the case a supervisor
        // has to weigh, and "lower score" would tell them nothing.
        $rows = array_merge(
            $this->rows(30, 1, 'Proven Shop', 'GMC', 'Yukon', ['engine']),
            $this->rows(4, 2, 'Quick Shop', 'GMC', 'Yukon', ['engine']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine']], [
            1 => $this->outcome(cost: 1200, days: 6, queue: 5),
            2 => $this->outcome(cost: 400, days: 1, queue: 0),
        ]);
        $f = $r['per_fault'][0];

        $this->assertSame('Proven Shop', $f['winner']['garage']);
        $this->assertSame('Quick Shop', $f['alternative']['garage']);
        // The trade-off names BOTH sides: what it gains and what it gives up.
        $this->assertMatchesRegularExpression('/faster|cheaper/i', $f['tradeoff']['text']);
        $this->assertStringContainsString("Less Engine experience", $f["tradeoff"]["text"]);
        // And it carries the two sides as reason CODES, so a non-English UI can rebuild the sentence
        // rather than reprinting this one.
        $this->assertNotEmpty($f['tradeoff']['parts']['pros']);
        $this->assertNotEmpty($f['tradeoff']['parts']['cons']);
    }

    public function test_a_garage_with_no_usable_history_for_a_fault_is_not_offered_as_an_option(): void
    {
        // Naming a garage that has never touched this fault is not an alternative, it is filler — and
        // filler in a decision UI invites a worse decision.
        $rows = array_merge(
            $this->rows(30, 1, 'Engine Shop', 'GMC', 'Yukon', ['engine']),
            $this->rows(60, 2, 'Tyre Only Shop', 'GMC', 'Yukon', ['tyres']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine', 'tyres']]);
        $engine = collect($r['per_fault'])->firstWhere('category_key', 'engine');

        $this->assertSame('Engine Shop', $engine['winner']['garage']);
        $this->assertNotSame('Tyre Only Shop', $engine['alternative']['garage'] ?? null);
    }

    public function test_a_fault_percentage_travels_with_the_repair_counts_it_came_from(): void
    {
        // "84%" invites "84% of what?" — and the answer has to be beside the number, not behind a click.
        $rows = array_merge(
            $this->rows(6, 1, 'Proven Shop', 'GMC', 'Yukon', ['engine']),
            $this->rows(9, 1, 'Proven Shop', 'GMC', 'Tahoe', ['engine']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine']]);
        $ev = $r['per_fault'][0]['winner']['evidence'];

        $this->assertNotEmpty($ev, 'a coverage percentage with no stated basis is a magic number');
        // The model-specific record first — that is what earned the top tier.
        $this->assertStringContainsString('6 previous Yukon Engine repairs here', $ev[0]['text']);
        // The remainder is reported as the OTHER models it came from, never double-counted.
        $this->assertStringContainsString('9 similar Engine repairs here on other models', implode(' ', Reason::texts($ev)));
        // The counts travel as params too — the Arabic UI needs the numbers, not the English sentence.
        $this->assertSame('evidence_same_model', $ev[0]['code']);
        $this->assertSame(6, $ev[0]['params']['n']);
    }

    public function test_the_reasoning_is_available_as_scannable_points_not_only_prose(): void
    {
        $rows = array_merge(
            $this->rows(30, 1, 'Proven Shop', 'GMC', 'Yukon', ['engine']),
            $this->rows(4, 2, 'Quick Shop', 'GMC', 'Yukon', ['engine']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine']], [
            1 => $this->outcome(cost: 1200, days: 6, queue: 5),
            2 => $this->outcome(cost: 400, days: 1, queue: 0),
        ]);
        $f = $r['per_fault'][0];

        $this->assertNotEmpty($f['winner_points']);
        // Every tick is a CHECKABLE fact. "Highest score" explains nothing and must never appear here.
        foreach ($f['winner_points'] as $p) {
            $this->assertStringNotContainsStringIgnoringCase('score', $p['text']);
            // …and every one is renderable in another language, not just printable in this one.
            $this->assertNotEmpty($p['code']);
        }
        $this->assertStringContainsString('Yukon', $f['winner_points'][0]['text']);

        // The alternative is split into what it gains and what it gives up, each side standing alone.
        $this->assertNotEmpty($f['alt_cons']);
        $this->assertNotEmpty($f['alt_pros']);
        $this->assertStringContainsString('Less Engine experience', implode(' ', Reason::texts($f['alt_cons'])));
        $this->assertMatchesRegularExpression('/faster|cheaper|sooner/i', implode(' ', Reason::texts($f['alt_pros'])));
    }

    public function test_two_prices_are_only_subtracted_at_a_grain_both_garages_share(): void
    {
        // The seductive error: the winner has an EARNED engine price (700) and the alternative only a
        // garage-wide average (300). Subtracting those reads as a AED 400 saving; it is a category
        // price measured against an all-work average, and the two answer different questions.
        $rows = array_merge(
            $this->rows(30, 1, 'Proven Shop', 'GMC', 'Yukon', ['engine']),
            $this->rows(20, 2, 'Cheap Shop', 'GMC', 'Yukon', ['engine']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine']], [
            1 => ['cost_by_fault' => [['fault' => 'engine', 'value' => 700.0, 'basis' => 'garage_fault', 'sample' => 63]]] + $this->outcome(cost: 550, days: 2),
            2 => $this->outcome(cost: 300, days: 2),
        ]);
        $cc = $r['per_fault'][0]['cost_compare'];

        // Each side still SHOWS its most specific figure — that is the number worth knowing.
        $this->assertSame(700.0, $cc['winner']['value']);
        $this->assertSame('garage_fault', $cc['winner']['basis']);
        $this->assertSame(300.0, $cc['alternative']['value']);
        // ...but the difference drops to the shared rung, and the mismatch is flagged for the UI.
        $this->assertFalse($cc['same_grain']);
        $this->assertSame('garage', $cc['comparison']['grain']);
        $this->assertSame(550.0, $cc['comparison']['winner']['value']);
        $this->assertEqualsWithDelta(-250, $cc['comparison']['delta'], 0.01);
    }

    public function test_a_fleet_fallback_price_can_never_ground_a_saving(): void
    {
        // A fleet median is the same number for every garage, so a "saving" against it is an artefact
        // of the fallback rather than a difference between two workshops.
        $rows = array_merge(
            $this->rows(30, 1, 'Proven Shop', 'GMC', 'Yukon', ['engine']),
            $this->rows(20, 2, 'Unknown Shop', 'GMC', 'Yukon', ['engine']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine']], [
            1 => $this->outcome(cost: 550, days: 2),
            2 => ['cost_aed' => ['value' => 391.0, 'basis' => 'fleet', 'sample' => 13867, 'confidence' => 'low', 'reason' => null, 'support' => []]]
                 + $this->outcome(cost: 391, days: 2),
        ]);
        $cc = $r['per_fault'][0]['cost_compare'];

        $this->assertNull($cc['comparison'], 'a fleet median was used as one side of a price comparison');
        $this->assertStringNotContainsStringIgnoringCase('cheaper', (string) $r['per_fault'][0]['verdict']['text']);
    }

    public function test_the_verdict_names_both_garages_and_never_resolves_to_pick_the_cheapest(): void
    {
        $rows = array_merge(
            $this->rows(30, 1, 'Proven Shop', 'GMC', 'Yukon', ['engine']),
            $this->rows(6, 2, 'Cheap Shop', 'GMC', 'Yukon', ['engine']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine']], [
            1 => $this->outcome(cost: 900, days: 1, queue: 0),
            2 => $this->outcome(cost: 400, days: 4, queue: 6),
        ]);
        $v = $r['per_fault'][0]['verdict'];

        // Both sides of the exchange, both named — this is the sentence the supervisor decides from.
        $this->assertStringContainsString('Cheap Shop', $v['text']);
        $this->assertStringContainsString('Proven Shop', $v['text']);
        $this->assertStringContainsString('cheaper', $v['text']);
        // The engine states the trade; it never spends the money for them.
        foreach (['choose', 'should', 'recommend the cheaper', 'pick the'] as $imperative) {
            $this->assertStringNotContainsStringIgnoringCase($imperative, $v['text']);
        }
        // The garage NAMES are params and the two sides are reason lists, so another language rebuilds
        // this sentence with its own grammar instead of receiving an English one it can only reprint.
        $this->assertSame('Proven Shop', $v['params']['winner']);
        $this->assertSame('Cheap Shop', $v['params']['alternative']);
        // …and the clause lists live in their OWN slots, never overwriting the garage names above.
        $this->assertNotEmpty($v['parts']['alternative_side']);
    }

    public function test_decision_factors_state_both_garages_as_strengths(): void
    {
        // A comparison where only the winner earns ticks is advocacy. The supervisor may overrule the
        // engine, and they need to see what they would be CHOOSING, not only what they would give up.
        $rows = array_merge(
            $this->rows(30, 1, 'Proven Shop', 'GMC', 'Yukon', ['engine']),
            $this->rows(6, 2, 'Cheap Shop', 'GMC', 'Yukon', ['engine']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine']], [
            1 => $this->outcome(cost: 900, days: 4, queue: 0),
            2 => $this->outcome(cost: 400, days: 1, queue: 0),
        ]);
        $f = $r['per_fault'][0];

        $this->assertNotEmpty($f['factors']['winner']);
        $this->assertNotEmpty($f['factors']['alternative']);
        // Each side's list contains only ITS OWN advantages — never the other's failings restated.
        $this->assertStringContainsString('more experienced', implode(' ', Reason::texts($f['factors']['winner'])));
        $this->assertStringContainsString('cheaper', implode(' ', Reason::texts($f['factors']['alternative'])));
    }

    public function test_the_scan_reason_stays_short_enough_for_a_table_row(): void
    {
        $rows = array_merge(
            $this->rows(30, 1, 'Proven Shop', 'GMC', 'Yukon', ['engine']),
            $this->rows(6, 2, 'Cheap Shop', 'GMC', 'Yukon', ['engine']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine']]);

        $reason = $r['per_fault'][0]['short_reason'];
        $this->assertNotEmpty($reason);
        // At most two clauses — the row has to stay scannable however the UI joins them.
        $this->assertLessThanOrEqual(2, count($reason));
        $rendered = implode(' + ', Reason::texts($reason));
        // A sentence here defeats the scan table's whole purpose.
        $this->assertLessThanOrEqual(60, mb_strlen($rendered));
        $this->assertStringNotContainsString('.', $rendered);
    }

    public function test_the_price_comparison_declares_how_much_it_can_be_leaned_on(): void
    {
        // Compared on garage averages, off thin samples, "AED 500 cheaper" is indicative at best — and
        // it reads identically to a comparison grounded in 60 fault-specific repairs.
        $rows = array_merge(
            $this->rows(30, 1, 'Proven Shop', 'GMC', 'Yukon', ['engine']),
            $this->rows(6, 2, 'Thin Shop', 'GMC', 'Yukon', ['engine']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine']], [
            1 => ['cost_aed' => ['value' => 900.0, 'basis' => 'garage', 'sample' => 8, 'confidence' => 'low', 'reason' => null, 'support' => []]] + $this->outcome(cost: 900, days: 2),
            2 => ['cost_aed' => ['value' => 400.0, 'basis' => 'garage', 'sample' => 6, 'confidence' => 'low', 'reason' => null, 'support' => []]] + $this->outcome(cost: 400, days: 2),
        ]);
        $cc = $r['per_fault'][0]['cost_confidence'];

        $this->assertSame('low', $cc['level']);
        $this->assertStringContainsString('indicative', $cc['reason']['text']);
        $this->assertSame('cost_garage_thin', $cc['reason']['code']);

        // And where one side has no price of its own, the comparison is refused outright.
        $r2 = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine']], [
            1 => $this->outcome(cost: 900, days: 2),
            2 => ['cost_aed' => ['value' => 391.0, 'basis' => 'fleet', 'sample' => 13867, 'confidence' => 'low', 'reason' => null, 'support' => []]] + $this->outcome(cost: 391, days: 2),
        ]);
        $this->assertSame('none', $r2['per_fault'][0]['cost_confidence']['level']);
    }

    // ── Metric self-explanation ──────────────────────────────────────────────────────

    public function test_first_time_resolution_and_repeat_repair_are_named_as_one_measure_seen_twice(): void
    {
        // Read as independent figures, "success 55%" beside "comeback 44%" says a garage fixes barely
        // half of what it touches. They are complements. Both definitions must say so, or the pair
        // misleads every operator who does not already know the formula.
        $m = $this->rec($this->fleet(), ['model' => 'Patrol', 'faults' => ['engine']])['metrics'];

        $this->assertSame('Predicted first-time resolution', $m['success_pct']['label']);
        $this->assertSame('Repeat repair probability (90 days)', $m['comeback_pct']['label']);
        foreach (['success_pct', 'comeback_pct'] as $id) {
            $this->assertStringContainsString('100%', $m[$id]['means'], "`{$id}` does not tell the operator the two figures are complements");
        }
        // And the operator-facing label must not be engine vocabulary.
        $this->assertSame('Fault experience', $m['coverage_pct']['label']);
    }

    public function test_every_metric_states_its_meaning_without_being_clicked(): void
    {
        $m = $this->rec($this->fleet(), ['model' => 'Patrol', 'faults' => ['engine']])['metrics'];

        foreach ($m as $id => $def) {
            $this->assertNotEmpty($def['short'], "metric `{$id}` has no one-line definition");
            // One line means one line — anything longer is a paragraph nobody reads under a number.
            $this->assertLessThanOrEqual(110, mb_strlen($def['short']), "metric `{$id}` short definition is too long to sit under a figure");
        }
    }


    public function test_every_displayed_metric_ships_its_own_definition(): void
    {
        $r = $this->rec($this->fleet(), ['model' => 'Patrol', 'faults' => ['engine']]);
        $metrics = $r['metrics'];

        // The figures the UI actually renders must all be explainable — no magic numbers.
        foreach (['match_score', 'criticality_weight', 'coverage_pct', 'specialization_pct',
                  'success_pct', 'comeback_pct', 'duration_days', 'cost_aed', 'confidence'] as $id) {
            $this->assertArrayHasKey($id, $metrics, "metric `{$id}` has no definition");
            foreach (['label', 'kind', 'short', 'means', 'method', 'source'] as $field) {
                $this->assertNotEmpty($metrics[$id][$field], "metric `{$id}` is missing `{$field}`");
            }
        }
    }

    public function test_a_policy_multiplier_is_not_presented_as_evidence(): void
    {
        $metrics = $this->rec($this->fleet(), ['model' => 'Patrol', 'faults' => ['engine']])['metrics'];

        // The ×1.3 on a fault is a business rule someone chose — labelling it as measured history would
        // be a lie about where the number came from.
        $this->assertSame('policy', $metrics['criticality_weight']['kind']);
        $this->assertNotNull($metrics['criticality_weight']['caveat']);

        // Counted history vs prediction must also be distinguishable.
        $this->assertSame('measured', $metrics['coverage_pct']['kind']);
        $this->assertSame('forecast', $metrics['success_pct']['kind']);
        $this->assertSame('derived', $metrics['match_score']['kind']);
    }

    public function test_metrics_whose_definition_admits_a_limitation_say_so(): void
    {
        $metrics = $this->rec($this->fleet(), ['model' => 'Patrol', 'faults' => ['engine']])['metrics'];

        // Success is a "did not come back" proxy, and the specialization denominator excludes
        // unclassified work. Both are the kind of thing that quietly misleads if left unstated.
        $this->assertStringContainsString('proxy', strtolower($metrics['success_pct']['caveat']));
        $this->assertNotNull($metrics['specialization_pct']['caveat']);
        $this->assertNotNull($metrics['cost_aed']['caveat']);
    }

    // ── The executive decision summary ───────────────────────────────────────────────

    public function test_decision_summary_separates_the_technical_and_business_answers(): void
    {
        $rows = array_merge(
            $this->rows(40, 1, 'Top Technical', 'GMC', 'Yukon', ['engine']),
            $this->rows(37, 2, 'Cheaper Faster', 'GMC', 'Yukon', ['engine']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine']], [
            1 => $this->outcome(cost: 1500, days: 7, queue: 8),
            2 => $this->outcome(cost: 400, days: 2, queue: 0),
        ]);
        $s = $r['strategy']['summary'];

        $this->assertSame('Top Technical', $s['technical']['garage']);
        $this->assertSame('Cheaper Faster', $s['business']['garage']);
        // The final call is the technical one, and the reason names what settled it.
        $this->assertSame('Top Technical', $s['final']);
        $this->assertStringContainsString('outweighs the potential saving', $s['reason']);
        $this->assertFalse($s['axes_agree'], 'the two axes disagreed and the summary must say so');
    }

    public function test_summary_reports_agreement_when_there_is_no_trade_to_make(): void
    {
        $rows = array_merge(
            $this->rows(40, 1, 'Best At Everything', 'GMC', 'Yukon', ['engine']),
            $this->rows(30, 2, 'Runner Up', 'GMC', 'Yukon', ['engine']),
        );
        // The technical leader is ALSO the cheaper/faster one — nothing to trade away.
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['engine']], [
            1 => $this->outcome(cost: 300, days: 1, queue: 0),
            2 => $this->outcome(cost: 1500, days: 8, queue: 9),
        ]);
        $s = $r['strategy']['summary'];

        $this->assertSame('Best At Everything', $s['final']);
        $this->assertNull($s['business']);
        $this->assertTrue($s['axes_agree']);
    }

    public function test_summary_names_the_critical_fault_that_drove_the_call(): void
    {
        // Brakes (×1.5) alongside cosmetic bodywork — the summary must credit the brake fault, not paint.
        $rows = array_merge(
            $this->rows(40, 1, 'Brake Expert', 'GMC', 'Yukon', ['brakes', 'bodywork']),
            $this->rows(36, 2, 'Budget Shop', 'GMC', 'Yukon', ['brakes', 'bodywork']),
        );
        $r = $this->rec($rows, ['model' => 'Yukon', 'faults' => ['brakes', 'bodywork']], [
            1 => $this->outcome(cost: 1500, days: 7, queue: 8),
            2 => $this->outcome(cost: 350, days: 1, queue: 0),
        ]);

        $this->assertStringContainsString('Brakes', $r['strategy']['summary']['reason']);
        $this->assertStringContainsString('Safety critical', $r['strategy']['summary']['reason']);
    }

    public function test_expected_outcomes_ride_along_with_each_recommendation(): void
    {
        $r = $this->rec(
            $this->rows(40, 1, 'Shop', 'GMC', 'Yukon', ['engine']),
            ['model' => 'Yukon', 'faults' => ['engine']],
            [1 => $this->outcome(cost: 420, days: 4.2, queue: 2)],
        );
        $o = $r['primary'][0]['outcomes'];

        $this->assertSame(4.2, $o['duration_days']['value']);
        $this->assertSame(80.0, $o['success_pct']['value']);
        $this->assertSame(20.0, $o['comeback_pct']['value']);
        $this->assertSame(420.0, $o['cost_aed']['value']);
        // Start/finish come from the live queue, not a guess.
        $this->assertSame(1.0, $o['start_in_days']);
        $this->assertSame(5.2, $o['complete_in_days']);
        // Transport has no data source at all and must say so rather than report 0.
        $this->assertNull($o['transport']['value']);
        $this->assertSame('unavailable', $o['transport']['basis']);
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
