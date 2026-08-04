<?php

namespace Tests\Golden;

use App\Intelligence\Evidence\EvidenceRegistry;
use App\Intelligence\Recurrence\RecurrenceRepository;
use App\Intelligence\Recurrence\RecurrenceWindow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;

/**
 * The evidence layer — the drill-down that makes a supplier grade defensible.
 *
 * The tests that matter here are the reconciliation ones: the drawer must not merely show *some*
 * repairs, it must show exactly the repairs the published figure was computed from. A drawer whose
 * rows do not add up to the claim is worse than no drawer, because it looks like proof.
 */
#[Group('golden')]
class EvidenceLayerTest extends GoldenTestCase
{
    private static bool $rebuilt = false;

    private EvidenceRegistry $registry;

    private int $vendorId;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$rebuilt) {
            Artisan::call('intelligence:rebuild-recurrence');
            self::$rebuilt = true;
        }

        $this->registry = app(EvidenceRegistry::class);

        $this->vendorId = (int) DB::table('fault_recurrence_pairs')
            ->whereNotNull('first_vendor_id')
            ->where('days_observed', '>=', 90)
            ->selectRaw('first_vendor_id, COUNT(*) AS c')
            ->groupBy('first_vendor_id')
            ->orderByDesc('c')
            ->value('first_vendor_id');
    }

    // ── The rows ARE the number ─────────────────────────────────────────────────────────────────

    /**
     * THE assertion this layer exists for.
     *
     * The drawer's row count must equal the sample the garage was scored on. If it does not, the
     * evidence is showing a different population than the claim — which is exactly the failure the
     * whole convergence was about, reappearing one layer down.
     */
    public function test_the_evidence_rows_reconcile_with_the_published_sample(): void
    {
        $stats = (new RecurrenceRepository())
            ->byGarage(RecurrenceWindow::fromContract(), [$this->vendorId])[$this->vendorId];

        $result = $this->registry->resolve("recurrence.garage:{$this->vendorId}")->rows(1, 1);

        $this->assertSame(
            $stats->n,
            $result['total'],
            'the drawer must show exactly the repairs the score was computed from',
        );
    }

    public function test_repairs_that_held_are_shown_not_filtered_out(): void
    {
        $evidence = $this->registry->resolve("recurrence.garage:{$this->vendorId}");
        $result   = $evidence->rows(1, 200);

        $held     = array_filter($result['rows'], fn ($r) => $r['outcome'] === 'held');
        $returned = array_filter($result['rows'], fn ($r) => $r['outcome'] !== 'held');

        // Showing only failures would make every garage look catastrophic and would misrepresent the
        // denominator — the rate is returns over opportunities, and both halves belong on screen.
        $this->assertNotEmpty($returned, 'the returns must be visible');
        $this->assertNotEmpty($held, 'the repairs that held must be visible too, or the rate is unreadable');
    }

    public function test_every_row_pairs_two_real_tickets(): void
    {
        $result = $this->registry->resolve("recurrence.garage:{$this->vendorId}")->rows(1, 25);

        foreach ($result['rows'] as $row) {
            $this->assertTrue(
                DB::table('maintenances')->where('id', $row['ticket_id'])->exists(),
                'every evidence row must resolve to a real ticket',
            );

            if ($row['came_back_on'] !== null) {
                $this->assertNotNull($row['return_ticket_id'], 'a return must name the ticket it came back on');
                $this->assertGreaterThanOrEqual(1, $row['days_between']);
            }
        }
    }

    /** The deduplication stays auditable — the reader can see how many labels became one repair. */
    public function test_rows_expose_how_many_labels_were_merged(): void
    {
        $result = $this->registry->resolve("recurrence.garage:{$this->vendorId}")->rows(1, 50);

        foreach ($result['rows'] as $row) {
            $this->assertGreaterThanOrEqual(1, $row['labels_merged']);
        }
    }

    // ── Claim and method ────────────────────────────────────────────────────────────────────────

    public function test_the_claim_restates_the_figures_so_the_drawer_stands_alone(): void
    {
        $stats = (new RecurrenceRepository())
            ->byGarage(RecurrenceWindow::fromContract(), [$this->vendorId])[$this->vendorId];

        $claim = $this->registry->resolve("recurrence.garage:{$this->vendorId}")->claim();

        $this->assertStringContainsString((string) $stats->rate(), $claim);
        $this->assertStringContainsString(number_format($stats->n), $claim);
    }

    public function test_the_method_is_operational_language_not_sql(): void
    {
        $method = $this->registry->resolve("recurrence.garage:{$this->vendorId}")->method();

        foreach (['SELECT', 'JOIN', 'fault_recurrence_pairs', 'is_exposure', 'days_observed'] as $engineWord) {
            $this->assertStringNotContainsString(
                $engineWord,
                $method,
                'the method must read as operational language — engine vocabulary belongs in the technical note',
            );
        }

        $this->assertStringContainsString('same fault', $method);
    }

    public function test_the_technical_note_carries_the_contract_version_and_the_exclusions(): void
    {
        $note = $this->registry->resolve("recurrence.garage:{$this->vendorId}")->technicalNote();

        $this->assertStringContainsString(config('metrics.recurrence.version'), $note);
        $this->assertStringContainsString('fault_recurrence_pairs', $note);
        $this->assertStringContainsString('counts once', $note);
    }

    // ── Domain cells ────────────────────────────────────────────────────────────────────────────

    public function test_a_matrix_cell_drills_to_only_that_domains_repairs(): void
    {
        $repo   = new RecurrenceRepository();
        $window = RecurrenceWindow::fromContract();

        $byDomain = $repo->byGarageAndDomain($window, [$this->vendorId])[$this->vendorId] ?? [];
        $this->assertNotEmpty($byDomain, 'the busiest garage must work in at least one domain');

        $domain = array_key_first($byDomain);
        $stats  = $byDomain[$domain];

        $result = $this->registry->resolve("recurrence.garage_domain:{$this->vendorId}:{$domain}")->rows(1, 1);

        $this->assertSame($stats->n, $result['total'], "cell {$domain} must show exactly its own repairs");
    }

    public function test_the_domain_method_explains_why_comparison_is_area_matched(): void
    {
        $byDomain = (new RecurrenceRepository())
            ->byGarageAndDomain(RecurrenceWindow::fromContract(), [$this->vendorId])[$this->vendorId];
        $domain = array_key_first($byDomain);

        $method = $this->registry->resolve("recurrence.garage_domain:{$this->vendorId}:{$domain}")->method();

        // The reading rule must travel with the cell: comparing tyre work against the all-fleet rate
        // would penalise every tyre shop for a fault type that recurs more everywhere.
        $this->assertStringContainsString('IN THIS AREA', $method);
    }

    // ── Addressing ──────────────────────────────────────────────────────────────────────────────

    public function test_an_unknown_evidence_id_is_refused_rather_than_answered_emptily(): void
    {
        // "No evidence" and "no such claim" look identical to a user and mean very different things.
        $this->expectException(InvalidArgumentException::class);
        $this->registry->resolve('recurrence.nonsense:1');
    }

    public function test_ids_round_trip_through_the_registry(): void
    {
        $id = "recurrence.garage:{$this->vendorId}";
        $this->assertSame($id, $this->registry->resolve($id)->id());
        $this->assertTrue($this->registry->knows($id));
        $this->assertFalse($this->registry->knows('made.up:9'));
    }

    /**
     * The ids the scorecard emits must all resolve.
     *
     * A card advertising a drill-down that 404s is worse than a card with no drill-down: the reader
     * asks for proof and is told the proof does not exist.
     */
    public function test_every_evidence_id_the_scorecard_emits_resolves(): void
    {
        $svc = app(\App\Services\Garage\GarageScorecardService::class);
        $svc->forget();
        $report = $svc->report();

        $checked = 0;
        foreach (array_slice($report['garages'], 0, 12) as $card) {
            $this->assertTrue($this->registry->knows($card['evidence_query_id']), "card id did not resolve: {$card['evidence_query_id']}");
            $checked++;

            foreach (array_slice($card['domains'], 0, 4) as $domain) {
                $this->assertTrue(
                    $this->registry->knows($domain['evidence_query_id']),
                    "cell id did not resolve: {$domain['evidence_query_id']}",
                );
                $checked++;
            }
        }

        $this->assertGreaterThan(20, $checked);
    }

    /**
     * Leaderboard rows carry the SAME evidence id as the matrix cell they came from.
     *
     * Rebuilding the id on the leaderboard would have been a second place that knows how evidence is
     * addressed — and the first time the scheme changed, one of the two would have silently rotted.
     */
    public function test_leaderboard_rows_reuse_the_cell_evidence_id(): void
    {
        $svc = app(\App\Services\Garage\GarageScorecardService::class);
        $svc->forget();
        $report = $svc->report();

        $cellIds = [];
        foreach ($report['garages'] as $card) {
            foreach ($card['domains'] as $d) {
                $cellIds["{$card['vendor_id']}:{$d['key']}"] = $d['evidence_query_id'];
            }
        }

        $checked = 0;
        foreach ($report['leaderboard'] as $domainKey => $board) {
            foreach (array_merge($board['best'], $board['worst']) as $row) {
                $this->assertNotEmpty($row['evidence_query_id'], 'every leaderboard row must be drillable');
                $this->assertSame(
                    $cellIds["{$row['vendor_id']}:{$domainKey}"] ?? null,
                    $row['evidence_query_id'],
                    'a leaderboard row must open the same evidence as its matrix cell',
                );
                $this->assertTrue($this->registry->knows($row['evidence_query_id']));
                $checked++;
            }
        }

        $this->assertGreaterThan(10, $checked);
    }
}