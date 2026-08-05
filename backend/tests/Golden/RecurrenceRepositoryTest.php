<?php

namespace Tests\Golden;

use App\Intelligence\Recurrence\RecurrenceRepository;
use App\Intelligence\Recurrence\RecurrenceWindow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * The single reader, verified against the real corpus.
 *
 * ── WHAT "PARITY" HONESTLY MEANS HERE ────────────────────────────────────────────────────────────
 * These tests do NOT assert that the repository reproduces the legacy VALUES. It cannot, and it must
 * not: the legacy implementations counted duplicate label rows and applied no consistent horizon, so
 * reproducing their numbers would mean reproducing the bug. The fleet rate is supposed to move from
 * 40.62% to 46.51%.
 *
 * What parity means is that the repository can express every legacy PARAMETERISATION — each retired
 * implementation was a (window, horizon) pair, and the repository can be asked for any of them. That
 * is what proves convergence removes duplicate implementations without removing capability. The
 * value difference is quantified separately by `intelligence:recurrence-compare`, which is the gate
 * that must be reviewed before any consumer is repointed.
 *
 * The internal-consistency tests are the more important half: they prove a garage's rate and the
 * fleet rate it is compared against are literally the same measurement, which is the property six
 * separate implementations could never have.
 */
#[Group('golden')]
class RecurrenceRepositoryTest extends GoldenTestCase
{
    private static bool $rebuilt = false;

    private RecurrenceRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$rebuilt) {
            Artisan::call('intelligence:rebuild-recurrence');
            self::$rebuilt = true;
        }

        $this->repo = new RecurrenceRepository();
    }

    // ── The governed measurement ────────────────────────────────────────────────────────────────

    /**
     * RE-BASELINED for metric contract v2.1.0 (2026-08-05): scheduled services left the denominator.
     *
     * n 10,595 → 9,522 and the rate 46.51 → 46.38. The 1,073 removed events are oil services, which
     * returned at 47.72% — ABOVE the fault rate — so excluding them LOWERED the fleet figure. A
     * recurring oil change is the service working, not a repair failing, and it was being charged to
     * garages as a comeback. Not a regression: the population changed on purpose.
     */
    public function test_the_governed_fleet_rate_is_the_contract_figure(): void
    {
        $stats = $this->repo->fleet(RecurrenceWindow::fromContract());

        $this->assertGolden('repo.fleet.n', 9522, $stats->n);
        $this->assertGolden('repo.fleet.rate', 46.38, $stats->rate(), 0.05);
        $this->assertGolden('repo.fleet.held_rate', 53.62, $stats->heldRate(), 0.05);
    }

    /**
     * The excluded services are still THERE — the correction is auditable, not a deletion.
     *
     * If this ever returns zero, somebody "cleaned up" the corpus and the evidence drawer's Services
     * tab silently became an empty page nobody would think to check.
     */
    public function test_the_excluded_services_remain_in_the_corpus(): void
    {
        $services = DB::table('fault_recurrence_pairs')
            ->where('kind', RecurrenceRepository::KIND_SERVICE)
            ->where('days_observed', '>=', RecurrenceWindow::fromContract()->windowDays)
            ->count();

        $this->assertGolden('repo.services.excluded_n', 1073, $services);
        $this->assertSame(
            0,
            $this->repo->fleet(RecurrenceWindow::fromContract())->n
                - DB::table('fault_recurrence_pairs')
                    ->where('kind', RecurrenceRepository::KIND_FAULT)
                    ->where('days_observed', '>=', 90)->count(),
            'the governed fleet must be exactly the fault-kind population',
        );
    }

    public function test_returned_and_held_partition_the_sample_exactly(): void
    {
        $s = $this->repo->fleet(RecurrenceWindow::fromContract());

        $this->assertSame($s->n, $s->returned + $s->held, 'every event either came back inside the window or did not');
    }

    public function test_the_thirty_and_ninety_day_buckets_partition_the_returns(): void
    {
        $s = $this->repo->fleet(RecurrenceWindow::fromContract());

        $this->assertSame($s->returned, $s->back30 + $s->back90);
    }

    // ── Parameterisation: the repository can express every retired definition ───────────────────

    /**
     * C1/C3/C5 shape — 90 days, no observation horizon.
     *
     * This is the uncensored figure. It is 45.84% rather than the legacy 40.62% because the corpus
     * is deduplicated; the remaining gap to 46.51% is the censoring the legacy code omitted.
     */
    public function test_it_can_express_the_uncensored_legacy_shape(): void
    {
        $stats = $this->repo->fleet(RecurrenceWindow::legacy(90, RecurrenceWindow::HORIZON_NONE));

        $this->assertGolden('repo.legacy_nohorizon.n', 12608, $stats->n);
        $this->assertGolden('repo.legacy_nohorizon.rate', 45.84, $stats->rate(), 0.05);
    }

    /** C2 shape — 90 days, corpus-max horizon. The scorecard's definition, now the governed one. */
    public function test_it_can_express_the_censored_legacy_shape(): void
    {
        $stats = $this->repo->fleet(RecurrenceWindow::legacy(90, RecurrenceWindow::HORIZON_CORPUS_MAX));

        $this->assertGolden('repo.legacy_horizon.n', 10595, $stats->n);
        $this->assertGolden('repo.legacy_horizon.rate', 46.51, $stats->rate(), 0.05);
    }

    /**
     * A narrower window censors less, so it observes MORE events — and fewer of them have come back.
     *
     * Both directions matter. If the 30-day sample were the smaller one, the censoring rule would be
     * anchored on a fixed date rather than on the window, which is the bug that let three different
     * horizons coexist.
     */
    public function test_narrowing_the_window_keeps_the_censoring_rule(): void
    {
        $thirty = $this->repo->fleet(RecurrenceWindow::reported(30));
        $ninety = $this->repo->fleet(RecurrenceWindow::fromContract());

        $this->assertGreaterThan(
            $ninety->n,
            $thirty->n,
            'a 30-day window needs only 30 days of observation, so it can judge more events than a 90-day one',
        );

        $this->assertLessThan(
            $ninety->rate(),
            $thirty->rate(),
            'fewer faults return within a month than within three',
        );
    }

    // ── Internal consistency: one measurement, every grain ──────────────────────────────────────

    /**
     * THE PROPERTY SIX IMPLEMENTATIONS COULD NEVER HAVE.
     *
     * Per-garage numbers must sum to the fleet number, minus only the events with no garage. Under
     * the old architecture the fleet baseline and the per-garage rates came from different queries
     * and there was nothing making them reconcile.
     */
    public function test_the_garages_sum_to_the_fleet(): void
    {
        $w = RecurrenceWindow::fromContract();

        $fleet    = $this->repo->fleet($w);
        $garages  = $this->repo->byGarage($w);

        $garageN        = array_sum(array_map(fn ($s) => $s->n, $garages));
        $garageReturned = array_sum(array_map(fn ($s) => $s->returned, $garages));

        // The kind scope belongs here too. This is the one place the test hand-rolls the repository's
        // scope instead of asking for it, so contract v2.1.0 (services out of every rate) had to be
        // mirrored by hand — otherwise the reconciliation compares a fault-only fleet against a
        // fault-plus-service remainder and fails by exactly the unattributed services.
        $unattributed = DB::table('fault_recurrence_pairs')
            ->whereNull('first_vendor_id')
            ->where('kind', RecurrenceRepository::KIND_FAULT)
            ->where('days_observed', '>=', $w->windowDays)
            ->count();

        $this->assertSame(
            $fleet->n,
            $garageN + $unattributed,
            'per-garage events plus unattributed events must equal the fleet exactly',
        );

        $this->assertLessThanOrEqual($fleet->returned, $garageReturned);
    }

    public function test_the_domains_sum_to_their_garage(): void
    {
        $w = RecurrenceWindow::fromContract();

        // Pick the busiest garage — the strictest case for a rollup.
        $garages = $this->repo->byGarage($w);
        arsort($garages);
        $vendorId = array_key_first(array_slice($garages, 0, 1, true));

        $byDomain = $this->repo->byGarageAndDomain($w, [$vendorId])[$vendorId] ?? [];
        $domainN  = array_sum(array_map(fn ($s) => $s->n, $byDomain));

        // Signatures the domain map does not know are legitimately dropped, so the domain rollup is
        // a subset of the garage total, never larger than it.
        $this->assertGreaterThan(0, $domainN);
        $this->assertLessThanOrEqual($garages[$vendorId]->n, $domainN);
    }

    public function test_the_signatures_sum_to_the_fleet(): void
    {
        $w = RecurrenceWindow::fromContract();

        $fleet = $this->repo->fleet($w);
        $bySig = $this->repo->bySignatureAll($w);

        $this->assertSame($fleet->n, array_sum(array_map(fn ($s) => $s->n, $bySig)));
        $this->assertSame($fleet->returned, array_sum(array_map(fn ($s) => $s->returned, $bySig)));
    }

    // ── Honesty rules ───────────────────────────────────────────────────────────────────────────

    public function test_an_unknown_garage_reports_empty_not_zero(): void
    {
        $stats = $this->repo->byVehicle(RecurrenceWindow::fromContract(), 999999999);

        $this->assertSame(0, $stats->n);
        $this->assertNull($stats->rate(), 'no data must never render as a 0% comeback rate');
    }

    public function test_every_measurement_carries_coverage_and_freshness(): void
    {
        $stats = $this->repo->fleet(RecurrenceWindow::fromContract());

        $this->assertNotNull($stats->asOf, 'as_of is what makes a stale rebuild visible');
        $this->assertGreaterThan(0, $stats->coverage->total);
        $this->assertLessThan(100.0, $stats->coverage->percent(), 'the censored tail must be published, not hidden');
    }

    public function test_coverage_reports_the_censored_tail(): void
    {
        $c = $this->repo->coverage(RecurrenceWindow::fromContract());

        $this->assertGolden('repo.coverage.covered', 10595, $c->covered);
        $this->assertGolden('repo.coverage.total', 12608, $c->total);
        $this->assertGolden('repo.coverage.missing', 2013, $c->missing());
    }

    public function test_the_median_is_computed_separately_from_the_mean(): void
    {
        $w = RecurrenceWindow::fromContract();

        $median = $this->repo->medianGap($w);
        $mean   = $this->repo->fleet($w)->meanGapDays;

        $this->assertNotNull($median);
        $this->assertNotNull($mean);
        // Right-skewed: the mean sits above the median. If they ever coincide, the distribution
        // changed and the "use the median" guidance needs revisiting.
        $this->assertLessThan($mean, $median, 'time-to-return is right-skewed — the median must sit below the mean');
    }

    public function test_evidence_pairs_are_walkable_back_to_real_tickets(): void
    {
        $w = RecurrenceWindow::fromContract();

        $vendorId = array_key_first($this->repo->byGarage($w));
        $pairs    = $this->repo->pairs($w, ['first_vendor_id' => $vendorId, 'returned_only' => true])->take(5);

        $this->assertGreaterThan(0, $pairs->count());

        foreach ($pairs as $p) {
            $this->assertNotNull($p->first_maintenance_id);
            $this->assertNotNull($p->next_occurred_at);
            $this->assertGreaterThanOrEqual(1, $p->days_to_return);
            $this->assertTrue(
                DB::table('maintenances')->where('id', $p->first_maintenance_id)->exists(),
                'every evidence row must resolve to a real ticket',
            );
        }
    }
}
