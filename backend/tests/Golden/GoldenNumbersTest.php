<?php

namespace Tests\Golden;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * The regression net for Fleet Intelligence.
 *
 * Every assertion here is a number that was independently measured against the live database during
 * the data study, before any of this code existed. If the implementation reproduces them, the
 * foundation is not merely written — it is verified, and every metric built on top inherits that
 * verification.
 *
 * ── BASELINE ────────────────────────────────────────────────────────────────────────────────────
 * Measured 2026-08-03 against `laravel` (26,942 tickets, 49,487 fault labels, 438 vehicles).
 *
 * ── WHEN ONE FAILS ──────────────────────────────────────────────────────────────────────────────
 * See GoldenTestCase::assertGolden(). Two legitimate outcomes, and "update the number until it
 * passes" is not one of them.
 */
#[Group('golden')]
class GoldenNumbersTest extends GoldenTestCase
{
    /** Rebuild both derived tables once for the whole class, then assert against them. */
    private static bool $rebuilt = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$rebuilt) {
            Artisan::call('intelligence:rebuild-visits');
            Artisan::call('intelligence:rebuild-recurrence');
            self::$rebuilt = true;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // G01–G05 · The corpus itself
    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /** G01 — HISTORICAL count. Guards against a scope or SoftDelete regression. */
    public function test_g01_maintenance_corpus_size(): void
    {
        $this->assertGolden('G01', 26942, DB::table('maintenances')->count());
    }

    /** G02 — 93.5% garage attribution is what makes Garage Intelligence possible at all. */
    public function test_g02_garage_attribution_coverage(): void
    {
        $total  = DB::table('maintenances')->count();
        $withId = DB::table('maintenances')->whereNotNull('vendor_id')->count();

        $this->assertGolden('G02', 93.5, round($withId / $total * 100, 1), 0.5);
    }

    /** G03 — 78.4% of tickets carry at least one fault label. */
    public function test_g03_signature_ticket_coverage(): void
    {
        $labelled = DB::table('maintenance_signatures')->distinct()->count('maintenance_id');

        $this->assertGolden('G03', 21125, $labelled);
    }

    /**
     * G04 — the fault vocabulary is exactly 20 signatures.
     *
     * A change here means the classifier moved, which invalidates every recurrence comparison drawn
     * across the boundary. It should never change silently.
     */
    public function test_g04_fault_vocabulary_is_twenty_signatures(): void
    {
        $count = DB::table('maintenance_signatures')->where('is_exposure', 0)->distinct()->count('signature');

        $this->assertGolden('G04', 20, $count);
    }

    /** G05 — ELECTRICAL is the fleet's most common fault system, and it is fleet-wide. */
    public function test_g05_electrical_is_the_leading_fault(): void
    {
        $row = DB::table('maintenance_signatures')
            ->selectRaw('COUNT(*) AS occurrences, COUNT(DISTINCT vehicle_id) AS vehicles')
            ->where('is_exposure', 0)
            ->where('signature', 'ELECTRICAL')
            ->first();

        $this->assertGolden('G05.occurrences', 4124, (int) $row->occurrences);
        $this->assertGolden('G05.vehicles', 206, (int) $row->vehicles);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // G06–G08 · Garage recurrence — the flagship metric
    //
    // These figures are DEDUPLICATED. The pre-correction values (Deals On Wheels 72.0d over 2,430
    // pairs, GPT 131.6d over 2,483) counted duplicate label rows and were inflated ~2.5-3x.
    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /** @return object{pairs:int, avg_days:float} */
    private function garageRecurrence(string $name): object
    {
        return DB::table('fault_recurrence_pairs as p')
            ->join('vendors as v', 'v.id', '=', 'p.first_vendor_id')
            ->selectRaw('COUNT(*) AS pairs, AVG(p.days_to_return) AS avg_days')
            ->whereNotNull('p.next_occurred_at')
            ->where('v.name', $name)
            ->first();
    }

    /** G06 — the defensible headline: worst garage that clears the minimum-sample gate. */
    public function test_g06_deals_on_wheels_recurrence(): void
    {
        $r = $this->garageRecurrence('Deals On Wheels auto');

        $this->assertGolden('G06.pairs', 473, (int) $r->pairs);
        $this->assertGolden('G06.avg_days', 55.0, round((float) $r->avg_days, 1), 0.1);
    }

    /** G07 — a strong performer at meaningful volume. */
    public function test_g07_gpt_garage_recurrence(): void
    {
        $r = $this->garageRecurrence('GPT GARRAGE');

        $this->assertGolden('G07.pairs', 719, (int) $r->pairs);
        $this->assertGolden('G07.avg_days', 131.0, round((float) $r->avg_days, 1), 0.1);
    }

    /**
     * G08 — the gate itself.
     *
     * Alresala shows the worst interval in the fleet (19.6 days) on 29 measured repairs. Below the
     * n>=30 minimum, so the platform must show "not enough data" rather than the worst score on the
     * leaderboard. This test exists to prove the most tempting number in the dataset stays suppressed.
     */
    public function test_g08_the_worst_looking_garage_is_below_the_sample_gate(): void
    {
        $r = $this->garageRecurrence('Alresala al zahabia');

        $this->assertGolden('G08.pairs', 29, (int) $r->pairs);
        $this->assertGolden('G08.avg_days', 19.6, round((float) $r->avg_days, 1), 0.1);

        $this->assertLessThan(
            \App\Intelligence\MetricContext::MIN_SAMPLE,
            (int) $r->pairs,
            'G08: this garage must remain below the minimum-sample gate — it is the case that proves the gate works.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // G09–G10 · Visit collapse — Correction B
    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /** G09 — only ~23% of attributed visits are a single event. Collapsing is doing real work. */
    public function test_g09_single_row_visit_share(): void
    {
        $row = DB::table('repair_visits')
            ->selectRaw('COUNT(*) AS visits, SUM(event_row_count = 1) AS single_row')
            ->whereNotNull('vehicle_id')
            ->whereNotNull('vendor_id')
            ->first();

        $this->assertGolden('G09.single_row', 1970, (int) $row->single_row);
        $this->assertGolden('G09.pct', 22.9, round($row->single_row / $row->visits * 100, 1), 0.5);
    }

    /**
     * G10 — the window is same-day.
     *
     * The gap distribution is flat after day 0 (day 0 = 15,045 pairs; days 1-10 ~120 each), so a
     * wider window would merge genuinely separate visits on no evidence. Asserting the recorded
     * window means a change to the rule cannot slip in unnoticed.
     */
    public function test_g10_collapse_window_is_same_day_only(): void
    {
        $windows = DB::table('repair_visits')->distinct()->pluck('grouping_window_days')->all();

        $this->assertSame([0], array_map('intval', $windows), 'G10: the collapse window must be 0 (same-day).');

        // And the consequence: consecutive one-day-apart events stayed as separate visits.
        $sameVehicleVendorConsecutive = DB::selectOne('
            SELECT COUNT(*) AS n FROM (
                SELECT DATEDIFF(started_at, LAG(started_at) OVER (PARTITION BY vehicle_id, vendor_id ORDER BY started_at)) AS gap
                FROM repair_visits WHERE vehicle_id IS NOT NULL AND vendor_id IS NOT NULL
            ) x WHERE gap = 1
        ');

        $this->assertGreaterThan(
            0,
            (int) $sameVehicleVendorConsecutive->n,
            'G10: one-day-apart visits must survive as distinct rows.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // G17–G20 · Structural guarantees
    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * G17 — the synthetic demo rows are still present and still identifiable.
     *
     * VehicleComponentDemoSeeder wrote 3,475 part purchases and 3,475 components on 2026-08-02. They
     * are UI fixtures and must not be deleted; they must also never reach an analytical surface. This
     * asserts they remain taggable, so SyntheticDataFilter has something to filter on.
     */
    public function test_g17_synthetic_rows_remain_identifiable(): void
    {
        $seededComponents = DB::table('vehicle_components')
            ->where('write_mode', \App\Intelligence\Support\SyntheticDataFilter::SEED_WRITE_MODE)
            ->count();

        $this->assertGolden('G17.components', 3475, $seededComponents);

        $realComponents = DB::table('vehicle_components')
            ->where(fn ($q) => $q->where('write_mode', '<>', 'seed')->orWhereNull('write_mode'))
            ->count();

        $this->assertLessThan(
            50,
            $realComponents,
            'G17: real component capture is still near zero — Parts Intelligence must stay unbuilt.'
        );
    }

    /**
     * G18 — the approval process does not exist in the data.
     *
     * `approval_status` is `not_required` on every one of the 26,942 rows. A column with zero
     * variance cannot support a KPI, which is why "Waiting for Approval" was killed. If this ever
     * returns more than one value, an approval workflow started being captured and the tile becomes
     * buildable — a finding worth catching.
     */
    public function test_g18_approval_status_has_no_variance(): void
    {
        $distinct = DB::table('maintenances')->distinct()->count('approval_status');

        $this->assertGolden('G18', 1, $distinct);
    }

    /**
     * G19 — the deduplication guarantee, asserted at the storage layer.
     *
     * A gap below one day means same-day duplicate label rows were paired with each other. This is
     * the single assertion that would have caught the original inflated figures.
     */
    public function test_g19_no_recurrence_pair_has_a_sub_day_gap(): void
    {
        $bad = DB::table('fault_recurrence_pairs')->where('days_to_return', '<', 1)->count();

        $this->assertGolden('G19', 0, $bad);

        // And the collapse itself: 33,026 raw fault rows became 12,608 distinct events.
        $events   = DB::table('fault_recurrence_pairs')->count();
        $rawRows  = (int) DB::table('fault_recurrence_pairs')->sum('source_row_count');

        $this->assertGolden('G19.events', 12608, $events);
        $this->assertGolden('G19.raw_rows', 33026, $rawRows);
    }

    /**
     * G20 — a second rebuild produces byte-identical rows.
     *
     * Idempotence is what makes these tables safe to rebuild nightly and safe to reason about: the
     * table is a pure function of its source, so a stale table is the only failure mode, never a
     * subtly different one.
     */
    public function test_g20_rebuilds_are_idempotent(): void
    {
        $checksum = fn () => [
            'visits' => DB::selectOne("
                SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS('|',
                    IFNULL(vehicle_id,-1), IFNULL(vendor_id,-1), started_at, IFNULL(ended_at,'-'),
                    IFNULL(duration_days,-1), event_row_count, primary_maintenance_id))),0) AS c
                FROM repair_visits"),
            'pairs' => DB::selectOne("
                SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS('|',
                    vehicle_id, signature, occurred_at, IFNULL(days_to_return,-1),
                    IFNULL(first_vendor_id,-1), source_row_count))),0) AS c
                FROM fault_recurrence_pairs"),
        ];

        $before = $checksum();

        Artisan::call('intelligence:rebuild-visits');
        Artisan::call('intelligence:rebuild-recurrence');

        $after = $checksum();

        $this->assertEquals($before['visits']->n, $after['visits']->n, 'G20: repair_visits row count changed on rebuild.');
        $this->assertEquals($before['visits']->c, $after['visits']->c, 'G20: repair_visits content changed on rebuild.');
        $this->assertEquals($before['pairs']->n, $after['pairs']->n, 'G20: fault_recurrence_pairs row count changed on rebuild.');
        $this->assertEquals($before['pairs']->c, $after['pairs']->c, 'G20: fault_recurrence_pairs content changed on rebuild.');
    }

    /** Event conservation: every dated source row is accounted for by exactly one visit. */
    public function test_visit_rebuild_conserves_every_dated_source_row(): void
    {
        $dated     = DB::table('maintenances')->whereNotNull('out_date')->count();
        $collapsed = (int) DB::table('repair_visits')->sum('event_row_count');

        $this->assertGolden('conservation.dated_rows', 25557, $dated);
        $this->assertGolden('conservation.collapsed', $dated, $collapsed);
    }
}
