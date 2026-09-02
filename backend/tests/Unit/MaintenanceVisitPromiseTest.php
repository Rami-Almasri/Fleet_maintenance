<?php

namespace Tests\Unit;

use App\Services\FleetUtilizationService;
use PHPUnit\Framework\TestCase;

/**
 * The three DB-free rulings the Maintenance History day view rests on. Each one exists because the
 * obvious implementation is wrong on this fleet's real data, so each is locked here rather than left
 * to be "simplified" back into the bug it replaced.
 *
 *   expectedPromise()   — a ready-by date that equals the logged return is not a promise.
 *   mergeWorkshopRows() — the nearest log row wins each FIELD, not the whole record.
 *   visitSource()       — an origin the code cannot read is 'unknown', never folded into a side.
 */
class MaintenanceVisitPromiseTest extends TestCase
{
    // ── expectedPromise ─────────────────────────────────────────────────────────────────────────

    public function test_a_forward_looking_date_is_a_promise(): void
    {
        $p = FleetUtilizationService::expectedPromise('2026-08-01', '2026-08-05', null);

        $this->assertSame('2026-08-05', $p['expected_on']);
        $this->assertFalse($p['recorded_on_return']);
    }

    /**
     * ~89% of the corpus. The Controller writes the real return into the expected column when the car
     * comes back, so the date is a record of what happened, not of what was agreed. Reporting it as a
     * kept promise would make every garage look punctual — the exact reason GarageScorecardService
     * refuses to score on this column.
     */
    public function test_a_date_equal_to_the_logged_return_is_flagged_as_written_after_the_fact(): void
    {
        $p = FleetUtilizationService::expectedPromise('2026-08-01', '2026-08-05', '2026-08-05');

        $this->assertSame('2026-08-05', $p['expected_on'], 'the date is still shown — it is just not evidence');
        $this->assertTrue($p['recorded_on_return']);
    }

    public function test_a_return_on_a_different_day_leaves_the_promise_intact(): void
    {
        $p = FleetUtilizationService::expectedPromise('2026-08-01', '2026-08-05', '2026-08-09');

        $this->assertSame('2026-08-05', $p['expected_on']);
        $this->assertFalse($p['recorded_on_return'], 'came back four days late — the promise still stands as one');
    }

    public function test_a_date_before_the_out_date_is_no_promise_at_all(): void
    {
        $p = FleetUtilizationService::expectedPromise('2026-08-01', '2026-07-28', null);

        $this->assertNull($p['expected_on'], 'promised back before it left — a typo, not a same-day target');
    }

    public function test_a_same_day_target_is_a_real_promise(): void
    {
        $p = FleetUtilizationService::expectedPromise('2026-08-01', '2026-08-01', null);

        $this->assertSame('2026-08-01', $p['expected_on']);
    }

    // ── mergeWorkshopRows ───────────────────────────────────────────────────────────────────────

    /**
     * THE BUG THIS REPLACED. The log holds several rows per trip and the first one is routinely blank,
     * so "one row wins the record" handed back an empty record and the page printed "—" for a garage
     * the log plainly knew. The preferred row wins each field it FILLS, not the whole record.
     */
    public function test_a_preferred_but_emptier_row_does_not_blank_out_a_field_a_later_row_fills(): void
    {
        $merged = FleetUtilizationService::mergeWorkshopRows([
            ['date' => '2026-08-02', 'garage' => null, 'issue' => null, 'notes' => null, 'cost' => null, 'expected' => null, 'actual_in' => null],
            ['date' => '2026-08-01', 'garage' => 'Al Hoorani', 'issue' => 'Engine', 'notes' => null, 'cost' => null, 'expected' => '2026-08-05', 'actual_in' => null],
        ]);

        $this->assertSame('Al Hoorani', $merged['garage']);
        $this->assertSame('Engine', $merged['issue']);
        $this->assertSame('2026-08-05', $merged['expected']);
    }

    public function test_the_first_row_wins_any_field_it_actually_fills(): void
    {
        $merged = FleetUtilizationService::mergeWorkshopRows([
            ['date' => '2026-08-31', 'garage' => 'Algourab', 'issue' => null, 'notes' => null, 'cost' => null, 'expected' => null, 'actual_in' => null],
            ['date' => '2026-07-11', 'garage' => 'Al hezam al abyad', 'issue' => 'Tires', 'notes' => null, 'cost' => null, 'expected' => null, 'actual_in' => null],
        ]);

        $this->assertSame('Algourab', $merged['garage'], 'where the car is now, not where it started');
        $this->assertSame('Tires', $merged['issue'], 'a field the first row left empty is still filled');
    }

    /**
     * The caller decides the order, and the two orders answer different questions. Newest-first is what
     * stops a car that left in July from being reported at the garage it started at seven weeks ago.
     */
    public function test_the_caller_order_is_honoured_rather_than_re_sorted(): void
    {
        $rows = [
            ['date' => '2026-07-11', 'garage' => 'Opening', 'issue' => null, 'notes' => null, 'cost' => null, 'expected' => null, 'actual_in' => null],
            ['date' => '2026-08-31', 'garage' => 'Latest', 'issue' => null, 'notes' => null, 'cost' => null, 'expected' => null, 'actual_in' => null],
        ];

        $this->assertSame('Opening', FleetUtilizationService::mergeWorkshopRows($rows)['garage']);
        $this->assertSame('Latest', FleetUtilizationService::mergeWorkshopRows(array_reverse($rows))['garage']);
    }

    public function test_only_the_requested_fields_are_merged(): void
    {
        $merged = FleetUtilizationService::mergeWorkshopRows(
            [['date' => '2026-08-31', 'garage' => 'Algourab', 'expected' => '2026-09-02']],
            ['garage'],
        );

        $this->assertSame('Algourab', $merged['garage']);
        $this->assertArrayNotHasKey('expected', $merged, 'the promise is merged in its own pass, in its own order');
    }

    public function test_the_day_of_the_first_row_is_carried_so_the_page_can_say_as_of_when(): void
    {
        $merged = FleetUtilizationService::mergeWorkshopRows([
            ['date' => '2026-08-31', 'garage' => 'Algourab'],
        ], ['garage']);

        $this->assertSame('2026-08-31', $merged['as_of']);
    }

    public function test_an_empty_string_counts_as_unfilled(): void
    {
        $merged = FleetUtilizationService::mergeWorkshopRows([
            ['date' => '2026-08-02', 'garage' => '', 'issue' => null, 'notes' => null, 'cost' => null, 'expected' => null, 'actual_in' => null],
            ['date' => '2026-08-01', 'garage' => '7 CYLINDER', 'issue' => null, 'notes' => null, 'cost' => null, 'expected' => null, 'actual_in' => null],
        ]);

        $this->assertSame('7 CYLINDER', $merged['garage']);
    }

    // ── workLabel ───────────────────────────────────────────────────────────────────────────────

    /**
     * The sheet splits one job across two columns and reading only the first drops the thing that was
     * actually done: "Interior" alone is the area, "Interior · Deep Cleaning" is the work.
     */
    public function test_the_area_and_the_job_are_both_reported(): void
    {
        $row = (object) ['service_main' => 'Interior', 'service_sup' => 'Deep Cleaning'];

        $this->assertSame('Interior · Deep Cleaning', FleetUtilizationService::workLabel($row));
    }

    public function test_a_repeated_half_is_not_printed_twice(): void
    {
        $row = (object) ['service_main' => 'Tires', 'service_sup' => 'Tires'];

        $this->assertSame('Tires', FleetUtilizationService::workLabel($row));
    }

    public function test_either_half_alone_still_reads(): void
    {
        $this->assertSame('Tires', FleetUtilizationService::workLabel((object) ['service_main' => 'Tires', 'service_sup' => '']));
        $this->assertSame('Deep Cleaning', FleetUtilizationService::workLabel((object) ['service_main' => '', 'service_sup' => 'Deep Cleaning']));
    }

    public function test_older_rows_fall_back_to_the_columns_they_do_have(): void
    {
        $row = (object) ['service_main' => '', 'service_sup' => '', 'maintenance_type' => 'Routine', 'damage_location' => 'Front bumper'];

        $this->assertSame('Routine', FleetUtilizationService::workLabel($row));
    }

    public function test_a_row_that_says_nothing_reports_nothing_rather_than_an_empty_string(): void
    {
        $this->assertNull(FleetUtilizationService::workLabel((object) ['service_main' => '', 'service_sup' => '']));
    }

    public function test_no_candidate_rows_means_no_record_rather_than_an_empty_one(): void
    {
        $this->assertNull(FleetUtilizationService::mergeWorkshopRows([]));
    }

    // ── visitSource ─────────────────────────────────────────────────────────────────────────────

    public function test_an_officemanager_contract_is_named_as_one(): void
    {
        $this->assertSame('officemanager', FleetUtilizationService::visitSource('api', '1'));
    }

    public function test_a_visit_opened_in_this_system_is_recognised_by_either_marker(): void
    {
        $this->assertSame('system', FleetUtilizationService::visitSource('web', 'workflow'));
        $this->assertSame('system', FleetUtilizationService::visitSource('web', null));
        $this->assertSame('system', FleetUtilizationService::visitSource(null, 'workflow'));
    }

    /**
     * A row the code cannot read must not be quietly assigned to either side: the two filtered counts
     * are shown next to each other, and they have to keep summing to the total.
     */
    public function test_an_unreadable_origin_is_reported_as_unknown(): void
    {
        $this->assertSame('unknown', FleetUtilizationService::visitSource(null, null));
        $this->assertSame('unknown', FleetUtilizationService::visitSource('something-new', null));
    }
}
