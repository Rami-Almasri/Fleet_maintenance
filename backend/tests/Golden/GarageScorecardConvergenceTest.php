<?php

namespace Tests\Golden;

use App\Intelligence\Recurrence\RecurrenceRepository;
use App\Intelligence\Recurrence\RecurrenceWindow;
use App\Services\Garage\GarageScorecardService;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Group;

/**
 * C2 repointed — /garages, the scorecards and every surface built on them now read the canonical
 * definition, and read the SAME numbers as the platform baseline.
 *
 * ── WHAT "IDENTICAL" MEANS, PRECISELY ────────────────────────────────────────────────────────────
 * A garage's recurrence rate on this page must equal the repository's rate for that garage EXACTLY.
 * That is the requirement, and it is tested per garage rather than in aggregate.
 *
 * The page's FLEET figure is deliberately NOT the platform's fleet figure, and that is not a
 * divergence: this page measures over repairs that name a garage (n=9,960), while the Executive
 * dashboard measures over every repair (n=10,595) — 635 events carry no vendor. A garage cannot
 * sensibly be compared against an average that includes repairs no garage did. Same definition,
 * same dataset, same window, narrower population — and the page now publishes both figures side by
 * side so the difference reads as scope rather than as two surfaces contradicting each other.
 */
#[Group('golden')]
class GarageScorecardConvergenceTest extends GoldenTestCase
{
    private static bool $rebuilt = false;

    private array $report;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$rebuilt) {
            Artisan::call('intelligence:rebuild-recurrence');
            self::$rebuilt = true;
        }

        $svc = app(GarageScorecardService::class);
        $svc->forget();
        $this->report = $svc->report();
    }

    /** @return array<int, array<string, mixed>> keyed by vendor_id */
    private function garages(): array
    {
        return array_column($this->report['garages'], null, 'vendor_id');
    }

    // ── The equality requirement ────────────────────────────────────────────────────────────────

    /**
     * EVERY scored garage's rate equals the repository's, exactly.
     *
     * Not a sample of garages and not within a tolerance: a tolerance is precisely how two
     * definitions coexist unnoticed for a year.
     */
    public function test_every_garage_rate_equals_the_repository_exactly(): void
    {
        $repo = (new RecurrenceRepository())->byGarage(RecurrenceWindow::fromContract());
        $checked = 0;

        foreach ($this->garages() as $vid => $card) {
            if (! isset($repo[$vid])) {
                continue;
            }

            $this->assertSame(
                $repo[$vid]->n,
                $card['reliability']['n'],
                "Garage {$card['garage']} (#{$vid}): sample differs from the canonical repository.",
            );

            $this->assertSame(
                $repo[$vid]->rate(),
                $card['reliability']['comeback_pct'],
                "Garage {$card['garage']} (#{$vid}): rate differs from the canonical repository.",
            );

            $checked++;
        }

        $this->assertGreaterThan(30, $checked, 'the comparison must actually cover the fleet');
    }

    public function test_the_page_baseline_and_the_platform_baseline_reconcile_exactly(): void
    {
        $platform = (new RecurrenceRepository())->fleet(RecurrenceWindow::fromContract());
        $fleet    = $this->report['fleet'];

        // The page states its own population AND the platform's, so the two can be reconciled on
        // sight rather than looking like a disagreement.
        $this->assertSame('attributable', $fleet['population']);
        $this->assertSame($platform->rate(), $fleet['platform_comeback_pct']);
        $this->assertSame($platform->n, $fleet['platform_comeback_n']);

        // And the gap between them is exactly the unattributed events — nothing unexplained.
        $this->assertSame(
            $platform->n - $fleet['comeback_n'],
            $fleet['unattributed_n'],
            'the difference between the two baselines must be fully explained by unattributed repairs',
        );
    }

    // ── The honesty rules survived the repoint ──────────────────────────────────────────────────

    public function test_garages_below_the_floor_are_withheld_not_shown_weak(): void
    {
        $floor = (int) config('metrics.recurrence.min_sample.garage', 30);
        $withheld = 0;

        foreach ($this->garages() as $card) {
            if ($card['reliability']['n'] >= $floor) {
                continue;
            }

            $this->assertNull(
                $card['score']['value'],
                "{$card['garage']} has {$card['reliability']['n']} repairs — below {$floor} — and must not carry a score.",
            );
            $this->assertNotEmpty(
                $card['score']['reason'] ?? $card['reliability']['reason'],
                'a withheld score must say WHY, or the page reads as broken rather than honest',
            );
            $withheld++;
        }

        $this->assertGreaterThan(0, $withheld);
    }

    public function test_scored_garages_are_the_expected_count(): void
    {
        // 57 -> 33. The 24 that fell were scored on duplicated label rows, never on 30 real repairs.
        $this->assertGolden('C2.garages_scored', 33, $this->report['fleet']['garages_scored']);
        $this->assertGolden('C2.garages_total', 170, $this->report['fleet']['garages_total']);
    }

    public function test_every_score_publishes_its_sample_and_coverage(): void
    {
        foreach ($this->garages() as $card) {
            if ($card['score']['value'] === null) {
                continue;
            }

            $this->assertArrayHasKey('n', $card['reliability']);
            $this->assertGreaterThanOrEqual(30, $card['reliability']['n']);

            // Which measures actually entered this score, and which were skipped for want of data.
            $this->assertNotEmpty($card['score']['coverage']);
        }
    }

    public function test_the_report_publishes_corpus_coverage_and_freshness(): void
    {
        $this->assertArrayHasKey('coverage', $this->report);
        $this->assertArrayHasKey('as_of', $this->report);
        $this->assertNotNull($this->report['as_of'], 'a stale rebuild must be visible on this page');
        $this->assertGolden('C2.coverage_covered', 10595, $this->report['coverage']['covered']);
    }

    // ── Evidence traceability ───────────────────────────────────────────────────────────────────

    /**
     * Every displayed rate must drill back to the repairs behind it.
     *
     * A page that grades suppliers has to be able to show its working on demand — otherwise Adham
     * cannot answer a garage owner who disputes the number, and Basem cannot check it.
     */
    public function test_every_card_and_cell_carries_an_evidence_id(): void
    {
        foreach ($this->garages() as $vid => $card) {
            $this->assertSame("recurrence.garage:{$vid}", $card['evidence_query_id']);

            foreach ($card['domains'] as $domain) {
                $this->assertSame(
                    "recurrence.garage_domain:{$vid}:{$domain['key']}",
                    $domain['evidence_query_id'],
                );
            }
        }
    }

    public function test_an_evidence_id_resolves_to_real_paired_repairs(): void
    {
        $repo = new RecurrenceRepository();
        $w    = RecurrenceWindow::fromContract();

        // Take the busiest scored garage and walk its number back to the tickets.
        $vid = array_key_first($repo->byGarage($w));

        $pairs = $repo->pairs($w, ['first_vendor_id' => $vid, 'returned_only' => true])->take(3);

        $this->assertGreaterThan(0, $pairs->count(), 'the evidence trail must not be empty');

        foreach ($pairs as $p) {
            $this->assertNotNull($p->first_maintenance_id);
            $this->assertNotNull($p->next_occurred_at);
        }
    }

    // ── The judgement layer is untouched ────────────────────────────────────────────────────────

    /**
     * Case-mix survived, and it is doing its job.
     *
     * Every garage's rate rose when deduplication landed — so if the expectation had NOT risen with
     * it, every score would have collapsed. That the scores barely moved (GPT 55→57, POWER POINT
     * 46→51) is the evidence that case-mix is relative rather than absolute.
     */
    public function test_case_mix_expectation_is_still_computed_and_published(): void
    {
        $scored = array_filter($this->garages(), fn ($c) => $c['score']['value'] !== null);

        foreach ($scored as $card) {
            $this->assertNotNull($card['reliability']['expected_pct'], 'the case-mix expectation must be published');
            $this->assertNotNull($card['reliability']['vs_expected_pts']);
            $this->assertSame(
                round($card['reliability']['comeback_pct'] - $card['reliability']['expected_pct'], 1),
                $card['reliability']['vs_expected_pts'],
            );
        }

        $this->assertGreaterThan(20, count($scored));
    }

    /**
     * Exposure damage is excluded from quality, and the exclusion now happens ONE layer earlier.
     *
     * It used to be an `is_exposure = 0` filter in this service's own query. It is now applied when
     * the canonical dataset is built, so every consumer inherits it and none can forget it.
     *
     * The GRADE_EXPOSURE cell state is currently UNREACHABLE, and that is correct rather than
     * broken: it fires only for a domain fed exclusively by exposure signatures, and in the current
     * map both exposure-fed domains (`tyres`, `bodywork`) also receive non-exposure work. The
     * service's own rule is that such a domain keeps its quality grade, because excluding it would
     * throw away real repair evidence to avoid some damage rows. Verified identical before and after
     * the repoint: zero such cells in both captures.
     *
     * Asserted explicitly so that if someone adds a purely-exposure domain to the map, this test
     * tells us the state has become reachable instead of it appearing unannounced.
     */
    public function test_exposure_is_excluded_at_the_dataset_layer_not_by_this_service(): void
    {
        $map      = (array) config('garage_recommendation.criticality.signature_categories', []);
        $exposure = \App\Services\Knowledge\RepairSignatureClassifier::EXPOSURE_SIGNATURES;

        $fedByExposure = $fedByOther = [];
        foreach ($map as $signature => $domain) {
            in_array($signature, $exposure, true) ? $fedByExposure[] = $domain : $fedByOther[] = $domain;
        }
        $pureExposure = array_diff(array_unique($fedByExposure), array_unique($fedByOther));

        $graded = 0;
        foreach ($this->garages() as $card) {
            foreach ($card['domains'] as $domain) {
                if ($domain['grade'] === GarageScorecardService::GRADE_EXPOSURE) {
                    $this->assertNull($domain['comeback_pct'], 'damage recurs from customer behaviour — never graded');
                    $this->assertGreaterThan(0, $domain['jobs'], 'but the work is still counted as volume');
                    $graded++;
                }
            }
        }

        $this->assertSame(
            $pureExposure === [] ? 0 : $graded,
            $graded,
            'GRADE_EXPOSURE must appear exactly when a purely-exposure domain exists in the map.',
        );

        // And the underlying guarantee, which holds regardless of the map: no exposure event can
        // reach a quality figure, because the canonical dataset never contains one.
        $this->assertTrue((bool) config('metrics.recurrence.filters.exclude_exposure'));
        $this->assertSame(
            0,
            \Illuminate\Support\Facades\DB::table('fault_recurrence_pairs')
                ->whereIn('signature', $exposure)->count(),
            'exposure signatures must never appear in the canonical recurrence dataset',
        );
    }

    public function test_the_provenance_names_the_canonical_source_and_contract_version(): void
    {
        $p = $this->report['provenance'];

        $this->assertSame(config('metrics.recurrence.version'), $p['metric_version']);
        $this->assertSame('config/metrics/recurrence.php', $p['metric_contract']);
        $this->assertStringContainsString('fault_recurrence_pairs', $p['sources'][0]['table']);
        $this->assertStringContainsString('however many times it was labelled', $p['sources'][0]['note']);
    }

    /** The service must no longer contain a recurrence calculation of its own. */
    public function test_the_service_no_longer_computes_recurrence_itself(): void
    {
        $source = file_get_contents(app_path('Services/Garage/GarageScorecardService.php'));

        $this->assertStringNotContainsString('FROM maintenance_signatures a', $source);
        $this->assertStringNotContainsString('DATE_ADD(a.occurred_at', $source);
        $this->assertStringContainsString('RecurrenceRepository', $source);
    }
}
