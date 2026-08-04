<?php

namespace Tests\Golden;

use App\Http\Controllers\Intelligence\GarageIntelligenceController;
use App\Intelligence\Evidence\EvidenceRegistry;
use App\Services\Garage\GarageScorecardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Group;

/**
 * The profile and comparison endpoints — and the one property that matters about them.
 *
 * ── NO SECOND CALCULATION PATH ───────────────────────────────────────────────────────────────────
 * Both read the SAME cached report the /garages list is built from. The tests below prove it by
 * comparing every figure against the list rather than against a hand-computed expectation: a test
 * that asserted "the profile says 46.5%" would pass even if the profile had grown its own query and
 * happened to agree today.
 *
 * The failure being guarded against is a profile page quietly disagreeing with the page it was
 * opened from — which is the convergence failure, one level up.
 */
#[Group('golden')]
class GarageIntelligenceEndpointsTest extends GoldenTestCase
{
    private static bool $rebuilt = false;

    private GarageIntelligenceController $controller;

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

        $this->controller = app(GarageIntelligenceController::class);
    }

    /**
     * The scored garages, in list order — round-tripped through JSON.
     *
     * The endpoints serialise, so a raw PHP float 40.0 arrives at the client as `40`. Comparing the
     * in-memory report against a decoded response would fail on that alone and prove nothing about
     * divergence. Both sides are put through the same serialisation the browser sees, so the test
     * compares what a user would actually receive.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scored(): array
    {
        $scored = array_values(array_filter($this->report['garages'], fn ($g) => $g['score']['value'] !== null));

        return json_decode(json_encode($scored), true);
    }

    private function payload($response): array
    {
        return json_decode($response->getContent(), true)['data'];
    }

    // ── Profile ─────────────────────────────────────────────────────────────────────────────────

    /**
     * Every figure on the profile is byte-identical to the same garage's row in the list.
     *
     * Checked across every scored garage, not a sample: an aggregate can agree while individuals
     * diverge, which is exactly how the platform ended up with six definitions.
     */
    public function test_the_profile_is_identical_to_the_list_for_every_scored_garage(): void
    {
        foreach ($this->scored() as $listCard) {
            $profile = $this->payload($this->controller->show($listCard['vendor_id']))['garage'];

            $this->assertSame($listCard['score']['value'], $profile['score']['value'], "{$listCard['garage']}: score differs");
            $this->assertSame($listCard['reliability']['comeback_pct'], $profile['reliability']['comeback_pct'], "{$listCard['garage']}: rate differs");
            $this->assertSame($listCard['reliability']['n'], $profile['reliability']['n'], "{$listCard['garage']}: sample differs");
            $this->assertSame($listCard['reliability']['expected_pct'], $profile['reliability']['expected_pct'], "{$listCard['garage']}: case-mix expectation differs");
            $this->assertSame(count($listCard['domains']), count($profile['domains']), "{$listCard['garage']}: domain count differs");
        }
    }

    public function test_the_profile_ranks_over_the_measured_population_not_every_vendor(): void
    {
        $scored  = $this->scored();
        $profile = $this->payload($this->controller->show($scored[0]['vendor_id']));

        // Being 1st of 33 measured garages is a fact. Being 1st of 170 mostly-unmeasured ones is not.
        $this->assertSame(1, $profile['rank']);
        $this->assertSame(count($scored), $profile['ranked_of']);
        $this->assertLessThan($this->report['fleet']['garages_total'], $profile['ranked_of']);
    }

    public function test_the_profile_ships_the_baselines_and_provenance_it_is_judged_against(): void
    {
        $profile = $this->payload($this->controller->show($this->scored()[0]['vendor_id']));

        $this->assertSame($this->report['fleet']['comeback_pct'], $profile['fleet']['comeback_pct']);
        $this->assertSame(config('metrics.recurrence.version'), $profile['provenance']['metric_version']);
        $this->assertNotNull($profile['as_of'], 'a stale corpus must be visible on the profile too');
    }

    public function test_an_unmeasured_garage_is_a_clean_404(): void
    {
        $response = $this->controller->show(999999);

        $this->assertSame(404, $response->getStatusCode());
    }

    // ── Comparison ──────────────────────────────────────────────────────────────────────────────

    private function compare(array $ids)
    {
        return $this->controller->compare(Request::create('/x', 'GET', ['ids' => implode(',', $ids)]));
    }

    public function test_comparison_figures_are_identical_to_the_list(): void
    {
        $scored = $this->scored();
        $ids    = array_column(array_slice($scored, 0, 3), 'vendor_id');

        $compared = collect($this->payload($this->compare($ids))['garages'])->keyBy('vendor_id');

        foreach (array_slice($scored, 0, 3) as $listCard) {
            $row = $compared[$listCard['vendor_id']];
            $this->assertSame($listCard['score']['value'], $row['score']['value']);
            $this->assertSame($listCard['reliability']['comeback_pct'], $row['reliability']['comeback_pct']);
            $this->assertSame($listCard['reliability']['n'], $row['reliability']['n']);
        }
    }

    /**
     * The work mix must travel with every comparison.
     *
     * Case-mix adjustment is indirect standardisation: rigorous garage-vs-fleet, only approximate
     * garage-vs-garage when the two do different work. Without the mix on screen, this page would
     * make a claim the statistics do not support.
     */
    public function test_every_compared_garage_publishes_its_work_mix(): void
    {
        $ids  = array_column(array_slice($this->scored(), 0, 2), 'vendor_id');
        $data = $this->payload($this->compare($ids));

        $this->assertSame('case_mix_approximate_between_garages', $data['caveat']);

        foreach ($data['garages'] as $g) {
            $this->assertNotEmpty($g['mix'], "{$g['garage']}: the work mix must be published");
            foreach ($g['mix'] as $m) {
                $this->assertArrayHasKey('share_pct', $m);
                $this->assertArrayHasKey('label', $m);
            }
        }
    }

    public function test_fewer_than_two_garages_is_refused(): void
    {
        $this->assertSame(422, $this->compare([$this->scored()[0]['vendor_id']])->getStatusCode());
        $this->assertSame(422, $this->compare([])->getStatusCode());
    }

    public function test_comparison_is_capped_at_four(): void
    {
        $ids  = array_column(array_slice($this->scored(), 0, 6), 'vendor_id');
        $data = $this->payload($this->compare($ids));

        $this->assertLessThanOrEqual(4, count($data['garages']));
    }

    // ── Evidence reaches both surfaces ──────────────────────────────────────────────────────────

    public function test_every_figure_on_both_endpoints_is_drillable(): void
    {
        $registry = app(EvidenceRegistry::class);
        $scored   = $this->scored();

        $profile = $this->payload($this->controller->show($scored[0]['vendor_id']))['garage'];
        $this->assertTrue($registry->knows($profile['evidence_query_id']));

        foreach ($profile['domains'] as $d) {
            $this->assertTrue($registry->knows($d['evidence_query_id']), "domain {$d['key']} is not drillable");
        }

        $ids = array_column(array_slice($scored, 0, 2), 'vendor_id');
        foreach ($this->payload($this->compare($ids))['garages'] as $g) {
            $this->assertTrue($registry->knows($g['evidence_query_id']));
        }
    }

    /**
     * The reconciliation requirement, applied to the new surfaces.
     *
     * A figure shown on the profile must be provable by the evidence its own id resolves to.
     */
    public function test_profile_figures_reconcile_with_their_evidence_rows(): void
    {
        $registry = app(EvidenceRegistry::class);

        foreach (array_slice($this->scored(), 0, 8) as $listCard) {
            $profile = $this->payload($this->controller->show($listCard['vendor_id']))['garage'];

            $rows = $registry->resolve($profile['evidence_query_id'])->rows(1, 1);

            $this->assertSame(
                $profile['reliability']['n'],
                $rows['total'],
                "{$profile['garage']}: the evidence does not add up to the figure shown",
            );
        }
    }
}
