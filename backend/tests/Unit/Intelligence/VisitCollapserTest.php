<?php

namespace Tests\Unit\Intelligence;

use App\Intelligence\Support\VisitCollapser;
use PHPUnit\Framework\TestCase;

/**
 * The collapsing rule, exercised against the edge cases actually present in the corpus.
 *
 * Every case here maps to a measured volume in docs/Fleet-Intelligence-Execution-Plan.md §1.2:
 * 15,045 same-day pairs, 2,325 multi-vendor days, 20,018 open tickets, 1,385 undated rows,
 * 1,738 without a garage, 646 without a vehicle, 31 with a negative duration.
 */
class VisitCollapserTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id'              => 1,
            'vehicle_id'      => 100,
            'vendor_id'       => 200,
            'out_date'        => '2026-01-10',
            'actual_in_date'  => null,
            'origin'          => 'sheet',
            'workflow_status' => null,
        ], $overrides);
    }

    /** @return array[] */
    private function collapse(array $rows, array $multiVendorDays = []): array
    {
        return iterator_to_array((new VisitCollapser())->collapse($rows, $multiVendorDays), false);
    }

    public function test_same_day_events_collapse_into_one_visit(): void
    {
        $visits = $this->collapse([
            $this->row(['id' => 1, 'origin' => 'sheet']),
            $this->row(['id' => 2, 'origin' => 'sheet']),
            $this->row(['id' => 3, 'origin' => 'manual', 'actual_in_date' => '2026-01-12']),
        ]);

        $this->assertCount(1, $visits);
        $this->assertSame(3, $visits[0]['event_row_count']);
        $this->assertSame([1, 2, 3], $visits[0]['maintenance_ids']);
        $this->assertSame(1, $visits[0]['primary_maintenance_id']);
        $this->assertSame('manual+sheet', $visits[0]['origin_mix']);
        $this->assertSame('2026-01-12', $visits[0]['ended_at']);
        $this->assertSame(2, $visits[0]['duration_days']);
    }

    /**
     * The correction that matters most: a one-day gap is a SEPARATE visit.
     *
     * The gap distribution is flat after day 0 (day 0 = 15,045 pairs; days 1–10 ≈ 120 each), so
     * anything beyond same-day is the fleet's ordinary revisit rate, not the tail of one visit.
     * A 3-day window would have merged ~300 genuinely distinct visits.
     */
    public function test_a_one_day_gap_does_not_collapse(): void
    {
        $visits = $this->collapse([
            $this->row(['id' => 1, 'out_date' => '2026-01-10']),
            $this->row(['id' => 2, 'out_date' => '2026-01-11']),
        ]);

        $this->assertCount(2, $visits);
        $this->assertSame(0, $visits[0]['grouping_window_days']);
    }

    public function test_a_three_day_gap_does_not_collapse_either(): void
    {
        $visits = $this->collapse([
            $this->row(['id' => 1, 'out_date' => '2026-01-10']),
            $this->row(['id' => 2, 'out_date' => '2026-01-13']),
        ]);

        $this->assertCount(2, $visits);
    }

    public function test_a_wider_window_is_available_but_not_the_default(): void
    {
        $collapser = new VisitCollapser(windowDays: 3);

        $visits = iterator_to_array($collapser->collapse([
            $this->row(['id' => 1, 'out_date' => '2026-01-10']),
            $this->row(['id' => 2, 'out_date' => '2026-01-12']),
        ]), false);

        $this->assertCount(1, $visits);
        $this->assertSame(3, $visits[0]['grouping_window_days'], 'the window is recorded on the row');
    }

    public function test_different_garages_on_the_same_day_stay_separate_and_are_flagged(): void
    {
        $visits = $this->collapse(
            [
                $this->row(['id' => 1, 'vendor_id' => 200]),
                $this->row(['id' => 2, 'vendor_id' => 300]),
            ],
            ['100|2026-01-10' => true],
        );

        $this->assertCount(2, $visits, 'a car at two garages on one day is two visits, never merged');
        $this->assertTrue($visits[0]['multi_vendor_day']);
        $this->assertTrue($visits[1]['multi_vendor_day']);
    }

    public function test_different_vehicles_never_group_together(): void
    {
        $visits = $this->collapse([
            $this->row(['id' => 1, 'vehicle_id' => 100]),
            $this->row(['id' => 2, 'vehicle_id' => 101]),
        ]);

        $this->assertCount(2, $visits);
    }

    public function test_open_tickets_become_open_visits_and_carry_no_duration(): void
    {
        $visits = $this->collapse([$this->row(['actual_in_date' => null])]);

        $this->assertTrue($visits[0]['is_open']);
        $this->assertNull($visits[0]['ended_at']);
        $this->assertNull($visits[0]['duration_days']);
        $this->assertFalse($visits[0]['has_close_date']);
    }

    public function test_rows_without_an_out_date_are_excluded(): void
    {
        $visits = $this->collapse([
            $this->row(['id' => 1, 'out_date' => null]),
            $this->row(['id' => 2, 'out_date' => '']),
            $this->row(['id' => 3, 'out_date' => '0000-00-00']),
            $this->row(['id' => 4, 'out_date' => '2026-01-10']),
        ]);

        $this->assertCount(1, $visits);
        $this->assertSame(4, $visits[0]['primary_maintenance_id']);
    }

    /**
     * 31 tickets close before they open. The visit is real; only its duration is unusable.
     * Discarding the row would throw away evidence of a repair that happened.
     */
    public function test_a_negative_duration_is_nulled_but_the_visit_is_kept(): void
    {
        $visits = $this->collapse([
            $this->row(['out_date' => '2026-01-10', 'actual_in_date' => '2026-01-05']),
        ]);

        $this->assertCount(1, $visits);
        $this->assertNull($visits[0]['duration_days']);
        $this->assertTrue($visits[0]['has_close_date'], 'it did close — the dates are just inconsistent');
        $this->assertFalse($visits[0]['is_open']);
    }

    public function test_unattributed_rows_group_among_themselves(): void
    {
        $visits = $this->collapse([
            $this->row(['id' => 1, 'vendor_id' => null]),
            $this->row(['id' => 2, 'vendor_id' => null]),
            $this->row(['id' => 3, 'vendor_id' => 200]),
        ]);

        $this->assertCount(2, $visits);
        $this->assertNull($visits[0]['vendor_id']);
        $this->assertSame(2, $visits[0]['event_row_count']);
        $this->assertSame(200, $visits[1]['vendor_id']);
    }

    public function test_a_visit_ends_when_its_last_event_ends(): void
    {
        $visits = $this->collapse([
            $this->row(['id' => 1, 'actual_in_date' => '2026-01-12']),
            $this->row(['id' => 2, 'actual_in_date' => '2026-01-15']),
            $this->row(['id' => 3, 'actual_in_date' => null]),
        ]);

        $this->assertCount(1, $visits);
        $this->assertSame('2026-01-15', $visits[0]['ended_at']);
        $this->assertSame(5, $visits[0]['duration_days']);
    }

    public function test_a_visit_is_cancelled_only_when_every_event_in_it_was(): void
    {
        $allCancelled = $this->collapse([
            $this->row(['id' => 1, 'workflow_status' => 'review_rejected']),
            $this->row(['id' => 2, 'workflow_status' => 'cancelled']),
        ]);
        $this->assertTrue($allCancelled[0]['is_cancelled']);

        $mixed = $this->collapse([
            $this->row(['id' => 1, 'workflow_status' => 'review_rejected']),
            $this->row(['id' => 2, 'workflow_status' => 'under_repair']),
        ]);
        $this->assertFalse($mixed[0]['is_cancelled'], 'real work happened, so the visit stands');
    }

    public function test_it_accepts_objects_as_well_as_arrays(): void
    {
        $visits = $this->collapse([(object) $this->row()]);

        $this->assertCount(1, $visits);
        $this->assertSame(100, $visits[0]['vehicle_id']);
    }

    public function test_an_empty_stream_yields_nothing(): void
    {
        $this->assertSame([], $this->collapse([]));
    }
}
