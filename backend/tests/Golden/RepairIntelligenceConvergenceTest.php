<?php

namespace Tests\Golden;

use App\Intelligence\Recurrence\RecurrenceRepository;
use App\Intelligence\Recurrence\RecurrenceWindow;
use App\Services\RepairIntelligence\Query\ProjectionRepairHistoryQuery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * C5 repointed — Repair Intelligence now answers from the canonical dataset.
 *
 * ── THE BUG THIS MIGRATION FIXED, WHICH WAS NOT A ROUNDING PROBLEM ───────────────────────────────
 * C1 and C2 corrected RATES. C5 was worse: it counted EPISODES, and it counted them from raw label
 * rows. On the live corpus one episode reaches 54 rows — vehicle 1743, ENGINE_MECH, 2025-04-10 — so
 * a Repair Intelligence card reported fifty-four prior episodes for a fault that happened once, and
 * outcome learning could judge a single comeback as many.
 *
 * That is not an imprecise number. It is a false sentence, shown to the person deciding what to do
 * about the car.
 */
#[Group('golden')]
class RepairIntelligenceConvergenceTest extends GoldenTestCase
{
    private static bool $rebuilt = false;

    private ProjectionRepairHistoryQuery $query;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$rebuilt) {
            Artisan::call('intelligence:rebuild-recurrence');
            self::$rebuilt = true;
        }

        $this->query = app(ProjectionRepairHistoryQuery::class);
    }

    // ── Rates equal the repository, exactly ─────────────────────────────────────────────────────

    public function test_every_signature_return_rate_equals_the_repository(): void
    {
        $repo = (new RecurrenceRepository())->bySignatureAll(RecurrenceWindow::fromContract());
        $checked = 0;

        foreach ($repo as $signature => $stats) {
            if ($stats->n === 0) {
                continue;
            }

            $answer = $this->query->signatureReturnRate($signature);

            $this->assertSame($stats->n, $answer->sampleSize, "{$signature}: sample differs");
            $this->assertSame(
                $stats->returned,
                $answer->value['returned'],
                "{$signature}: returned count differs from the canonical repository",
            );
            // Compared against the raw proportion, not against rate()/100. rate() rounds to two
            // decimals for display; this interface publishes an unrounded proportion. Both are
            // derived from the SAME two integers, which the assertions above have already proven
            // identical — so comparing the derivations is the exact test, and comparing a rounded
            // value with an unrounded one would only be testing the rounding.
            $this->assertSame(
                $stats->returned / $stats->n,
                $answer->value['rate'],
                "{$signature}: rate differs from the canonical repository",
            );

            $checked++;
        }

        $this->assertGreaterThan(15, $checked, 'the comparison must cover the fault vocabulary');
    }

    // ── Episodes are episodes, not labels ───────────────────────────────────────────────────────

    /**
     * The regression that matters most in this commit.
     *
     * Vehicle 1743 carries 54 ENGINE_MECH label rows on 2025-04-10. It is ONE episode.
     */
    public function test_a_heavily_labelled_episode_counts_once(): void
    {
        $labelRows = DB::table('maintenance_signatures')
            ->where('vehicle_id', 1743)
            ->where('signature', 'ENGINE_MECH')
            ->where('occurred_at', '2025-04-10')
            ->count();

        $this->assertGolden('C5.label_rows_for_one_episode', 54, $labelRows);

        $answer = $this->query->findPreviousEpisodes(1743, ['ENGINE_MECH'], null, '2025-06-01', 90);

        $this->assertSame(1, $answer->sampleSize, 'fifty-four labels describe one episode');
    }

    public function test_comeback_detection_counts_episodes_not_labels(): void
    {
        // occurrencesBetween feeds outcome learning: its answer decides whether a recommendation is
        // recorded as having succeeded.
        $answer = $this->query->occurrencesBetween(1743, ['ENGINE_MECH'], '2025-01-01', '2025-06-01');

        $this->assertSame(1, $answer->sampleSize);
    }

    /**
     * No episode lookup may ever return two rows for the same (vehicle, signature, date).
     *
     * Checked across a spread of real vehicles rather than the one known case, so a future change
     * that reintroduces label-level reads is caught wherever it lands.
     */
    public function test_no_episode_lookup_returns_a_duplicate_day(): void
    {
        $vehicles = DB::table('fault_recurrence_pairs')
            ->select('vehicle_id')
            ->groupBy('vehicle_id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(15)
            ->pluck('vehicle_id');

        $signatures = ['ENGINE_MECH', 'ELECTRICAL', 'BRAKES', 'AC', 'SUSPENSION'];

        foreach ($vehicles as $vehicleId) {
            $rows = $this->query
                ->findPreviousEpisodes((int) $vehicleId, $signatures, null, '2026-07-29', 3650)
                ->value;

            foreach ($rows as $signature => $episodes) {
                $dates = collect($episodes)->pluck('occurred_at')->all();

                $this->assertSame(
                    count($dates),
                    count(array_unique($dates)),
                    "Vehicle {$vehicleId} / {$signature}: the same day appeared twice — label rows leaked in.",
                );
            }
        }
    }

    public function test_episodes_carry_the_collapse_audit_trail(): void
    {
        $rows = $this->query
            ->findPreviousEpisodes(1743, ['ENGINE_MECH'], null, '2025-06-01', 90)
            ->value['ENGINE_MECH'];

        $this->assertSame(54, (int) $rows[0]->source_row_count, 'how many labels collapsed must stay visible');
    }

    // ── Provenance ──────────────────────────────────────────────────────────────────────────────

    /**
     * The version identifies the SEMANTICS of the answers, and they changed.
     *
     * Stored answers stamped v1 were computed over a different population; comparing them with a v2
     * answer would be comparing episodes with labels.
     */
    public function test_the_query_layer_version_records_the_semantic_change(): void
    {
        $this->assertSame('v2', $this->query->version());
    }

    /**
     * The cache key carries both versions.
     *
     * Without them a deploy keeps serving day-old answers computed under the retired definition, and
     * the platform disagrees with itself for exactly as long as the TTL — the hardest divergence to
     * diagnose, because it heals before anyone investigates.
     */
    public function test_the_cache_cannot_serve_a_retired_definition(): void
    {
        $source = file_get_contents(app_path('Services/RepairIntelligence/Query/ProjectionRepairHistoryQuery.php'));

        $this->assertStringContainsString('self::VERSION', $source);
        $this->assertStringContainsString("config('metrics.recurrence.version'", $source);
    }

    /** No recurrence calculation may remain in this class. */
    public function test_the_query_no_longer_computes_recurrence_itself(): void
    {
        $source = file_get_contents(app_path('Services/RepairIntelligence/Query/ProjectionRepairHistoryQuery.php'));

        $this->assertStringNotContainsString('FROM maintenance_signatures a', $source);
        $this->assertStringNotContainsString('DATE_ADD(a.occurred_at', $source);
        $this->assertStringContainsString('RecurrenceRepository', $source);
    }

    /**
     * What deliberately still reads raw signatures, and why that is correct.
     *
     * `vehicleHistory()` returns a car's whole timeline INCLUDING exposure damage, because
     * chronic-vehicle and repair-vs-replace questions legitimately care about accident history. The
     * canonical dataset excludes exposure by contract, so it cannot answer that question — and this
     * is a retrieval, not a recurrence measurement. Asserted so the distinction is deliberate rather
     * than an oversight the guardrail later flags.
     */
    public function test_exposure_inclusive_history_is_still_a_raw_read_by_design(): void
    {
        $answer = $this->query->vehicleHistory(1743, '2026-07-29', 100);

        $this->assertGreaterThan(0, $answer->sampleSize);

        $hasExposure = collect($answer->value)->contains(fn ($r) => (bool) $r->is_exposure);
        $this->assertTrue(
            $hasExposure,
            'the full history must still surface damage — it is not a quality measure and must not be filtered',
        );
    }
}
