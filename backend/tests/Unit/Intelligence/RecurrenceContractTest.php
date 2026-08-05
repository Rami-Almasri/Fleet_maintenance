<?php

namespace Tests\Unit\Intelligence;

use App\Intelligence\Coverage;
use App\Intelligence\Recurrence\RecurrenceStats;
use App\Intelligence\Recurrence\RecurrenceWindow;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The governed contract, asserted.
 *
 * A metric contract that nothing checks is a comment. These tests are what stop
 * config/metrics/recurrence.php drifting away from the code that claims to implement it — the exact
 * failure that let six recurrence implementations coexist for months, each individually documented
 * and collectively contradictory.
 */
class RecurrenceContractTest extends TestCase
{
    // ── The contract exists and is complete ─────────────────────────────────────────────────────

    public function test_the_contract_declares_every_governed_field(): void
    {
        $c = config('metrics.recurrence');

        $this->assertIsArray($c, 'config/metrics/recurrence.php must load as metrics.recurrence');

        foreach ([
            'version', 'effective_date', 'owner', 'business_definition', 'canonical_dataset',
            'grain', 'deduplication', 'observation_horizon', 'window_days', 'filters',
            'numerator', 'denominator', 'min_sample', 'coverage', 'case_mix',
            'domain_weighting', 'freshness', 'change_history',
        ] as $field) {
            $this->assertArrayHasKey($field, $c, "The contract must declare [{$field}].");
        }
    }

    public function test_the_canonical_dataset_is_the_deduplicated_table(): void
    {
        $this->assertSame('fault_recurrence_pairs', config('metrics.recurrence.canonical_dataset'));
        $this->assertNotSame('maintenance_signatures', config('metrics.recurrence.canonical_dataset'));
    }

    public function test_deduplication_is_on_and_keyed_on_one_fault_day(): void
    {
        $this->assertTrue(config('metrics.recurrence.deduplication.enabled'));
        $this->assertSame(
            ['vehicle_id', 'signature', 'occurred_at'],
            config('metrics.recurrence.deduplication.key'),
        );
    }

    public function test_the_observation_horizon_is_anchored_on_the_corpus_not_the_clock(): void
    {
        // CURDATE() silently discards fully-observed rows, because the corpus ends before today.
        $this->assertSame('corpus_max', config('metrics.recurrence.observation_horizon.mode'));
    }

    public function test_exposure_is_excluded_and_derived_from_the_classifier(): void
    {
        $this->assertTrue(config('metrics.recurrence.filters.exclude_exposure'));
        $this->assertStringContainsString(
            'EXPOSURE_SIGNATURES',
            config('metrics.recurrence.filters.exposure_source'),
            'The exposure list must be DERIVED from the classifier, never re-declared in the contract.',
        );
    }

    /**
     * v2.1.0 — scheduled work is excluded from every rate, and the list is DERIVED like exposure's.
     *
     * Re-declaring the service vocabulary in the contract would let it drift from the classifier that
     * stamps `kind` at rebuild, and the first symptom would be a rate quietly measured over a
     * different population than the one the drawer shows.
     */
    public function test_services_are_excluded_and_derived_from_the_classifier(): void
    {
        $this->assertTrue(config('metrics.recurrence.filters.exclude_services'));
        $this->assertStringContainsString(
            'SERVICE_SIGNATURES',
            config('metrics.recurrence.filters.service_source'),
            'The service list must be DERIVED from the classifier, never re-declared in the contract.',
        );
    }

    /**
     * The exclusion is a SCOPE, not a deletion — the rows stay and stay readable.
     *
     * If this ever flips to a delete, "we removed 1,073 services from the rate" stops being checkable
     * and the evidence drawer's Services tab has nothing to read.
     */
    public function test_the_service_exclusion_is_expressed_as_a_kind_column(): void
    {
        $this->assertSame('kind', config('metrics.recurrence.filters.kind_column'));
    }

    public function test_the_service_separation_is_recorded_in_the_change_history(): void
    {
        $history = config('metrics.recurrence.change_history');
        $latest  = end($history);

        $this->assertSame('2.1.0', $latest['version']);
        $this->assertSame('2.1.0', config('metrics.recurrence.version'), 'the contract must publish its own latest version');
        $this->assertSame(46.38, $latest['fleet_comeback']);
        $this->assertSame(9522, $latest['fleet_n']);

        // The limitation travels WITH the version. Only OIL_SERVICE is typed; TYRE genuinely mixes
        // punctures with rotations and the corpus cannot separate them. A reader who does not know
        // that will over-trust the separation.
        $this->assertNotEmpty($latest['known_limit']);
    }

    public function test_the_ticket_scope_is_historical(): void
    {
        // A retired ticket is a repair that really happened. Matches OperationalKpiService.
        $this->assertSame('historical', config('metrics.recurrence.filters.ticket_scope'));
    }

    /**
     * The floors are stated in REAL repairs.
     *
     * Before deduplication a garage floor of 30 enforced roughly 6. Lowering it now to restore the
     * old count of scored garages would re-import the bug as a setting.
     */
    public function test_the_garage_floor_is_thirty_real_repairs(): void
    {
        $this->assertSame(30, config('metrics.recurrence.min_sample.garage'));
        $this->assertSame(20, config('metrics.recurrence.min_sample.garage_domain'));
    }

    public function test_case_mix_and_weighting_are_declared_as_judgement_not_measurement(): void
    {
        // Both are governed HERE but applied in the domain service — the repository never scores.
        $this->assertSame(
            \App\Services\Garage\GarageScorecardService::class,
            config('metrics.recurrence.case_mix.applied_in'),
        );
        $this->assertSame(
            \App\Services\Garage\GarageScorecardService::class,
            config('metrics.recurrence.domain_weighting.applied_in'),
        );
        $this->assertSame('indirect_standardisation', config('metrics.recurrence.case_mix.method'));
    }

    public function test_the_case_mix_limitation_is_documented(): void
    {
        // Rigorous garage-vs-fleet; only approximate garage-vs-garage. If this stops being stated,
        // the comparison page starts making a claim the statistics do not support.
        $this->assertNotEmpty(config('metrics.recurrence.case_mix.approximate_for'));
    }

    public function test_change_history_is_append_only_and_explains_the_supersession(): void
    {
        $history = config('metrics.recurrence.change_history');

        $this->assertGreaterThanOrEqual(2, count($history));
        $this->assertSame('1.0.0', $history[0]['version']);
        $this->assertArrayHasKey('retired_because', $history[0]);
        $this->assertSame(config('metrics.recurrence.version'), end($history)['version']);
    }

    // ── RecurrenceWindow reads the contract, never hardcodes ────────────────────────────────────

    public function test_the_governed_window_comes_from_the_contract(): void
    {
        $w = RecurrenceWindow::fromContract();

        $this->assertSame(config('metrics.recurrence.window_days'), $w->windowDays);
        $this->assertSame(config('metrics.recurrence.observation_horizon.mode'), $w->horizonMode);
        $this->assertSame(config('metrics.recurrence.filters.exclude_exposure'), $w->excludeExposure);
        $this->assertSame(config('metrics.recurrence.version'), $w->version);
        $this->assertTrue($w->appliesHorizon());
    }

    public function test_a_reported_sub_window_keeps_the_governed_horizon(): void
    {
        $w = RecurrenceWindow::reported(30);

        $this->assertSame(30, $w->windowDays);
        $this->assertTrue($w->appliesHorizon(), 'narrowing the window must not drop the censoring rule');
    }

    public function test_an_ungoverned_window_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RecurrenceWindow::reported(45);   // not in the contract's reported windows
    }

    public function test_a_zero_or_negative_window_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RecurrenceWindow(0);
    }

    public function test_an_unknown_horizon_mode_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RecurrenceWindow(90, 'yesterday');
    }

    public function test_two_definitions_cannot_share_a_cache_entry(): void
    {
        $governed = RecurrenceWindow::fromContract();
        $legacy   = RecurrenceWindow::legacy(90, RecurrenceWindow::HORIZON_NONE);

        $this->assertNotSame($governed->fingerprint(), $legacy->fingerprint());
    }

    // ── RecurrenceStats measures, and refuses to judge ──────────────────────────────────────────

    public function test_an_empty_sample_reports_null_not_zero(): void
    {
        // "0% comeback" over no repairs is the most flattering possible lie about a garage.
        $stats = RecurrenceStats::empty(RecurrenceWindow::fromContract(), new Coverage(0, 0));

        $this->assertNull($stats->rate());
        $this->assertNull($stats->heldRate());
        $this->assertSame(0, $stats->n);
    }

    public function test_the_rate_is_returned_over_fully_observed_events(): void
    {
        $stats = $this->stats(n: 100, returned: 46, held: 54, back30: 20, back90: 26, meanGap: 41.0);

        $this->assertSame(46.0, $stats->rate());
        $this->assertSame(54.0, $stats->heldRate());
    }

    /**
     * `held` is the SCORING complement and contains faults that returned after the window. Only
     * neverReturned() may be described as a repair that lasted — the UI once printed "{n} never came
     * back" over the full `held` bucket, which fleet-wide covered 3,705 repairs whose fault did
     * return. The rate must not move when that display is corrected.
     */
    public function test_never_returned_excludes_the_faults_that_came_back_after_the_window(): void
    {
        $stats = $this->stats(n: 100, returned: 46, held: 54, back30: 20, back90: 26, backLater: 14);

        $this->assertSame(40, $stats->neverReturned(), 'held minus the late returns');
        $this->assertSame(54.0, $stats->heldRate(), 'the scored complement is untouched by the split');
        $this->assertSame(46.0, $stats->rate(), 'and so is the comeback rate');
    }

    public function test_merging_sums_the_late_returns_too(): void
    {
        $merged = $this->stats(n: 60, held: 30, backLater: 8)->merge($this->stats(n: 40, held: 30, backLater: 5));

        $this->assertSame(13, $merged->backLater);
        $this->assertSame(47, $merged->neverReturned());
    }

    public function test_it_carries_no_score_grade_or_expectation(): void
    {
        // The measurement layer must not leak judgement. If any of these appear, case-mix has
        // migrated out of the domain service and every consumer silently inherits it.
        foreach (['score', 'grade', 'expected', 'expected_pct', 'verdict', 'band'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $this->stats()->toArray());
        }
    }

    public function test_the_floor_check_is_the_callers_to_make(): void
    {
        $this->assertFalse($this->stats(n: 29)->meetsFloor(30));
        $this->assertTrue($this->stats(n: 30)->meetsFloor(30));
    }

    public function test_merging_sums_the_counts_and_drops_the_median(): void
    {
        $a = $this->stats(n: 60, returned: 30, held: 30, back30: 10, back90: 20, meanGap: 40.0);
        $b = $this->stats(n: 40, returned: 10, held: 30, back30: 4, back90: 6, meanGap: 70.0);

        $merged = $a->merge($b);

        $this->assertSame(100, $merged->n);
        $this->assertSame(40, $merged->returned);
        $this->assertSame(60, $merged->held);
        $this->assertSame(14, $merged->back30);
        $this->assertNull($merged->meanGapDays, 'gap averages do not sum — report none rather than a wrong one');
    }

    private function stats(
        int $n = 10, int $returned = 5, int $held = 5,
        int $back30 = 2, int $back90 = 3, ?float $meanGap = 30.0,
        int $backLater = 0,
    ): RecurrenceStats {
        return new RecurrenceStats(
            $n, $returned, $held, $back30, $back90, $backLater, $meanGap,
            new Coverage($n, $n), null, RecurrenceWindow::fromContract(),
        );
    }
}
