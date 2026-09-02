<?php

namespace Tests\Unit;

use App\Services\FleetUtilizationService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic underneath "how many garage visits" and "how much garage downtime" — the two
 * questions the Garage Intelligence alerts are raised from.
 *
 * These exercise FleetUtilizationService's interval engine directly, DB-free, because that engine is
 * the single definition of both numbers ([[maintenance-days-single-source]]) and every awkward case
 * the fleet's real data contains is an arithmetic case, not a wiring one: a stay that started before
 * the window, one that has not ended, two records describing the same stay, and a return recorded
 * before the departure.
 *
 * Intervals are [start, end|null] datetime strings; end = null means "still in the garage".
 */
class GarageVisitAndDowntimeMathTest extends TestCase
{
    private const DAY = 86400;

    /** Unix stamp for a 'Y-m-d H:i:s' string. */
    private function ts(string $s): int
    {
        return Carbon::parse($s)->timestamp;
    }

    private function dayNum(string $date): int
    {
        return FleetUtilizationService::dayNum(Carbon::parse($date));
    }

    /** Downtime seconds over a window, expressed in days for readability. */
    private function days(array $intervals, string $from, string $to, ?string $now = null): float
    {
        $nowTs = $this->ts($now ?? $to);

        return round(FleetUtilizationService::mergeSeconds(
            $intervals,
            $this->ts($from),
            $this->ts($to),
            $nowTs
        ) / self::DAY, 4);
    }

    private function visits(array $intervals, string $from, string $to, ?string $today = null): int
    {
        return FleetUtilizationService::countVisits(
            $intervals,
            $this->dayNum($from),
            $this->dayNum($to),
            $this->dayNum($today ?? $to)
        );
    }

    // ── VISITS ─────────────────────────────────────────────────────────────────────────────────

    public function test_one_stay_is_one_visit(): void
    {
        $stays = [['2026-08-10 08:00:00', '2026-08-12 17:00:00']];

        $this->assertSame(1, $this->visits($stays, '2026-08-01', '2026-08-31'));
    }

    /**
     * THE CENTRAL RULE. A car that goes in ONCE and has an inspection, an oil change, a brake repair
     * and an electrical repair done during that stay is ONE visit, not four. The engine counts STAYS
     * — one type-'U' maintenance contract per stay — so the four maintenance records and four
     * workflow tasks that hang off it never inflate the count. Modelled here as the single interval
     * those four jobs share.
     */
    public function test_four_jobs_during_one_stay_are_still_one_visit(): void
    {
        $oneStay = [['2026-08-10 08:00:00', '2026-08-14 17:00:00']];

        $this->assertSame(1, $this->visits($oneStay, '2026-08-01', '2026-08-31'));
        $this->assertSame(4.375, $this->days($oneStay, '2026-08-01 00:00:00', '2026-08-31 00:00:00'));
    }

    public function test_separate_stays_are_separate_visits(): void
    {
        $stays = [
            ['2026-08-02 09:00:00', '2026-08-03 09:00:00'],
            ['2026-08-11 09:00:00', '2026-08-12 09:00:00'],
            ['2026-08-20 09:00:00', '2026-08-21 09:00:00'],
        ];

        $this->assertSame(3, $this->visits($stays, '2026-08-01', '2026-08-31'));
    }

    public function test_a_stay_entirely_before_the_window_is_not_counted(): void
    {
        $stays = [['2026-06-01 09:00:00', '2026-06-04 09:00:00']];

        $this->assertSame(0, $this->visits($stays, '2026-08-01', '2026-08-31'));
    }

    /** A stay that began before the window but is still running is a visit inside it. */
    public function test_a_stay_that_started_before_the_window_and_reaches_into_it_counts(): void
    {
        $stays = [['2026-07-25 09:00:00', '2026-08-05 09:00:00']];

        $this->assertSame(1, $this->visits($stays, '2026-08-01', '2026-08-31'));
    }

    /** An open stay runs to today, so it is inside any window that has not closed before it began. */
    public function test_a_car_still_in_the_garage_counts_as_a_visit(): void
    {
        $stays = [['2026-08-28 09:00:00', null]];

        $this->assertSame(1, $this->visits($stays, '2026-08-01', '2026-08-31', '2026-08-31'));
    }

    /** The window's last day is inside it; the day after is not. */
    public function test_the_window_boundaries_are_exact(): void
    {
        $onLastDay  = [['2026-08-31 09:00:00', '2026-08-31 18:00:00']];
        $dayAfter   = [['2026-09-01 09:00:00', '2026-09-01 18:00:00']];

        $this->assertSame(1, $this->visits($onLastDay, '2026-08-01', '2026-08-31'));
        $this->assertSame(0, $this->visits($dayAfter, '2026-08-01', '2026-08-31'));
    }

    /**
     * A stay that merely ENDS on the day the window opens contributed no time inside it and belongs
     * to the period before — counting it would report a visit worth zero days.
     */
    public function test_a_stay_that_only_ends_on_the_opening_day_is_not_a_visit_in_this_window(): void
    {
        $stays = [['2026-07-28 09:00:00', '2026-08-01 09:00:00']];

        $this->assertSame(0, $this->visits($stays, '2026-08-01', '2026-08-31'));
    }

    /** A return recorded BEFORE the departure is not a visit — it is a typo, and is dropped. */
    public function test_a_return_before_the_departure_is_not_counted(): void
    {
        $stays = [['2026-08-20 09:00:00', '2026-08-14 09:00:00']];

        $this->assertSame(0, $this->visits($stays, '2026-08-01', '2026-08-31'));
    }

    // ── DOWNTIME ───────────────────────────────────────────────────────────────────────────────

    public function test_a_completed_stay_measures_its_real_elapsed_time(): void
    {
        // 10 Aug 08:00 → 12 Aug 20:00 = 2 days 12 hours, not "three calendar days".
        $stays = [['2026-08-10 08:00:00', '2026-08-12 20:00:00']];

        $this->assertSame(2.5, $this->days($stays, '2026-08-01 00:00:00', '2026-08-31 00:00:00'));
    }

    public function test_an_open_stay_runs_to_now_not_to_the_end_of_the_window(): void
    {
        $stays = [['2026-08-20 00:00:00', null]];

        // "Now" is 25 Aug midday; the window nominally runs to the 31st. Five and a half days, not eleven.
        $this->assertSame(5.5, $this->days($stays, '2026-08-01 00:00:00', '2026-08-31 00:00:00', '2026-08-25 12:00:00'));
    }

    public function test_several_stays_add_up(): void
    {
        $stays = [
            ['2026-08-02 00:00:00', '2026-08-04 00:00:00'],   // 2 days
            ['2026-08-10 00:00:00', '2026-08-11 12:00:00'],   // 1.5 days
        ];

        $this->assertSame(3.5, $this->days($stays, '2026-08-01 00:00:00', '2026-08-31 00:00:00'));
    }

    /**
     * OVERLAP IS NEVER DOUBLE-COUNTED. Two records describing the same physical stay — a very common
     * shape in this fleet, where the same trip is written more than once — must total the union, not
     * the sum. Summing these two would report six days for a four-day stay.
     */
    public function test_overlapping_records_are_merged_not_summed(): void
    {
        $overlapping = [
            ['2026-08-10 00:00:00', '2026-08-14 00:00:00'],   // 4 days
            ['2026-08-12 00:00:00', '2026-08-14 00:00:00'],   // 2 days, entirely inside the first
        ];

        $this->assertSame(4.0, $this->days($overlapping, '2026-08-01 00:00:00', '2026-08-31 00:00:00'));
    }

    public function test_partially_overlapping_records_total_the_union(): void
    {
        $stays = [
            ['2026-08-10 00:00:00', '2026-08-13 00:00:00'],
            ['2026-08-12 00:00:00', '2026-08-16 00:00:00'],
        ];

        $this->assertSame(6.0, $this->days($stays, '2026-08-01 00:00:00', '2026-08-31 00:00:00'));
    }

    /** Two stays that meet exactly end-to-start are one continuous span, counted once. */
    public function test_back_to_back_stays_are_one_continuous_span(): void
    {
        $stays = [
            ['2026-08-10 00:00:00', '2026-08-12 00:00:00'],
            ['2026-08-12 00:00:00', '2026-08-14 00:00:00'],
        ];

        $this->assertSame(4.0, $this->days($stays, '2026-08-01 00:00:00', '2026-08-31 00:00:00'));
    }

    /** A stay that began before the window contributes only the part inside it. */
    public function test_a_stay_crossing_the_window_opening_is_clipped_not_counted_whole(): void
    {
        $stays = [['2026-07-25 00:00:00', '2026-08-04 00:00:00']];   // 10 days, 3 of them in window

        $this->assertSame(3.0, $this->days($stays, '2026-08-01 00:00:00', '2026-08-31 00:00:00'));
    }

    /** …and one that runs past the window's end is clipped at the end, not dropped. */
    public function test_a_stay_crossing_the_window_close_is_clipped_at_the_end(): void
    {
        $stays = [['2026-08-29 00:00:00', '2026-09-10 00:00:00']];

        $this->assertSame(2.0, $this->days($stays, '2026-08-01 00:00:00', '2026-08-31 00:00:00'));
    }

    /** A stay entirely outside the window contributes nothing at all. */
    public function test_a_stay_outside_the_window_contributes_nothing(): void
    {
        $this->assertSame(0.0, $this->days([['2026-06-01 00:00:00', '2026-06-09 00:00:00']], '2026-08-01 00:00:00', '2026-08-31 00:00:00'));
    }

    /**
     * A return recorded before the departure is skipped rather than counted as negative time —
     * silently subtracting from a car's downtime would be worse than ignoring the row.
     */
    public function test_invalid_timestamp_ordering_is_skipped_not_counted_negative(): void
    {
        $stays = [
            ['2026-08-10 00:00:00', '2026-08-12 00:00:00'],   // good: 2 days
            ['2026-08-20 00:00:00', '2026-08-14 00:00:00'],   // return before departure
        ];

        $this->assertSame(2.0, $this->days($stays, '2026-08-01 00:00:00', '2026-08-31 00:00:00'));
    }

    public function test_no_records_at_all_is_zero_not_an_error(): void
    {
        $this->assertSame(0.0, $this->days([], '2026-08-01 00:00:00', '2026-08-31 00:00:00'));
        $this->assertSame(0, $this->visits([], '2026-08-01', '2026-08-31'));
    }

    /** A stay whose start and end are the same instant is zero time, not a division-by-zero. */
    public function test_a_zero_length_stay_contributes_no_downtime(): void
    {
        $this->assertSame(0.0, $this->days([['2026-08-10 09:00:00', '2026-08-10 09:00:00']], '2026-08-01 00:00:00', '2026-08-31 00:00:00'));
    }
}
