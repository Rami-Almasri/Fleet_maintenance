<?php

namespace Tests\Golden;

use App\Intelligence\Recurrence\RecurrenceRepository;
use App\Intelligence\Recurrence\RecurrenceWindow;
use App\Kpi\Kpi;
use App\Kpi\OperationalKpiService;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Group;

/**
 * C1 repointed — the fleet baseline now comes exclusively from the canonical repository.
 *
 * This is the first consumer to move, and the first commit in the convergence where a user-visible
 * number changes. These tests are the proof that it moved for the stated reason and no other: the
 * published figures must be byte-identical to what the repository returns, not merely close to it.
 *
 * "Close enough" is how two definitions coexist for a year without anybody noticing.
 */
#[Group('golden')]
class OperationalKpiConvergenceTest extends GoldenTestCase
{
    private static bool $rebuilt = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$rebuilt) {
            Artisan::call('intelligence:rebuild-recurrence');
            self::$rebuilt = true;
        }
    }

    /** @return array<string, Kpi> */
    private function kpis(): array
    {
        $out = [];
        foreach (app(OperationalKpiService::class)->all() as $kpi) {
            $out[$kpi->key] = $kpi;
        }

        return $out;
    }

    // ── The published figures ARE the repository's figures ──────────────────────────────────────

    public function test_the_comeback_rate_is_exactly_the_repository_value(): void
    {
        $repo = (new RecurrenceRepository())->fleet(RecurrenceWindow::fromContract());
        $kpi  = $this->kpis()['comeback_rate'];

        // Exact, not approximate. A tolerance here would let a second definition drift back in.
        $this->assertSame(round($repo->rate(), 2), $kpi->value);
        $this->assertSame($repo->n, $kpi->sampleSize);
    }

    public function test_the_first_time_fix_rate_is_exactly_the_repository_complement(): void
    {
        $repo = (new RecurrenceRepository())->fleet(RecurrenceWindow::fromContract());
        $kpi  = $this->kpis()['first_time_fix_rate'];

        $this->assertSame(round($repo->heldRate(), 2), $kpi->value);
        $this->assertSame($repo->n, $kpi->sampleSize);
    }

    /**
     * The two rates must be complements of one another to the decimal.
     *
     * They were computed from the same query before too, but nothing enforced it. Now they come from
     * one RecurrenceStats, so a divergence would mean the object itself is inconsistent.
     */
    public function test_the_two_rates_are_exact_complements(): void
    {
        $k = $this->kpis();

        $this->assertSame(
            100.0,
            round($k['comeback_rate']->value + $k['first_time_fix_rate']->value, 2),
        );
        $this->assertSame($k['comeback_rate']->sampleSize, $k['first_time_fix_rate']->sampleSize);
    }

    /**
     * RE-BASELINED for metric contract v2.1.0 (2026-08-05): scheduled services left the denominator.
     *
     * 46.51 → 46.38 over n 10,595 → 9,522. The 1,073 excluded events are oil services returning at
     * 47.72%; a recurring oil change is the service working, not the repair failing, so it never
     * belonged in a first-time-fix figure. Same movement as repo.fleet.* by construction — these
     * KPIs read the one RecurrenceStats, which is the point of the convergence.
     */
    public function test_the_baseline_matches_the_governed_contract_figures(): void
    {
        $k = $this->kpis();

        $this->assertGolden('C1.comeback', 46.38, $k['comeback_rate']->value, 0.05);
        $this->assertGolden('C1.first_time_fix', 53.62, $k['first_time_fix_rate']->value, 0.05);
        $this->assertGolden('C1.n', 9522, $k['comeback_rate']->sampleSize);
    }

    // ── Provenance travels with the number ──────────────────────────────────────────────────────

    public function test_every_recurrence_kpi_states_which_definition_produced_it(): void
    {
        foreach (['comeback_rate', 'first_time_fix_rate'] as $key) {
            $kpi = $this->kpis()[$key];

            $this->assertSame(
                config('metrics.recurrence.version'),
                $kpi->context['metric_version'] ?? null,
                "{$key} must state its metric version — an unversioned baseline cannot be compared with a later one.",
            );

            $this->assertSame('fault_recurrence_pairs (deduplicated repair events)', $kpi->context['source'] ?? null);
            $this->assertSame('corpus_max', $kpi->context['observed_horizon'] ?? null);
        }
    }

    public function test_every_recurrence_kpi_publishes_its_coverage_and_freshness(): void
    {
        foreach (['comeback_rate', 'first_time_fix_rate'] as $key) {
            $kpi = $this->kpis()[$key];

            $this->assertNotNull($kpi->coverage, "{$key} must publish the censored tail, not hide it");
            $this->assertNotNull($kpi->asOf, "{$key} must publish how fresh the corpus was");
            $this->assertGolden("C1.{$key}.censored", 2013, $kpi->coverage->missing());

            // Coverage is 84%, so the confidence must be downgraded automatically — nobody hand-set it.
            $this->assertSame(Kpi::CONFIDENCE_PARTIAL, $kpi->confidence);
        }
    }

    // ── Nothing else moved ──────────────────────────────────────────────────────────────────────

    /**
     * The other thirteen KPIs are untouched by this repoint.
     *
     * Turnaround in particular reads maintenances directly and has nothing to do with recurrence; if
     * it moved, the repoint reached further than intended.
     */
    public function test_the_non_recurrence_kpis_are_unaffected(): void
    {
        $k = $this->kpis();

        $this->assertGolden('C1.turnaround_days', 2.7, $k['workshop_turnaround_days']->value, 0.05);
        $this->assertGolden('C1.garage_attribution', 93.5, $k['garage_attribution']->value, 0.1);

        // Still correctly reported as not measurable — the repoint must not have accidentally
        // supplied it with a number it has not earned.
        $this->assertFalse($k['repeat_failure_rate']->available);
    }

    public function test_the_full_scoreboard_still_reports_every_kpi(): void
    {
        $all = app(OperationalKpiService::class)->all();

        $this->assertCount(15, $all, 'the scoreboard must not gain or lose a KPI during a repoint');
    }

    /**
     * The service must no longer contain a recurrence calculation of its own.
     *
     * A source-level assertion, because the behavioural tests above would still pass if someone
     * left the old query in place unused — and an unused duplicate is how the next divergence starts.
     */
    public function test_the_service_no_longer_computes_recurrence_itself(): void
    {
        $source = file_get_contents(app_path('Kpi/OperationalKpiService.php'));

        $this->assertStringNotContainsString(
            'FROM maintenance_signatures a',
            $source,
            'OperationalKpiService must not query raw signatures — recurrence comes from the repository.',
        );

        $this->assertStringContainsString('RecurrenceRepository', $source);
        $this->assertStringContainsString('RecurrenceWindow::fromContract()', $source);
    }
}
