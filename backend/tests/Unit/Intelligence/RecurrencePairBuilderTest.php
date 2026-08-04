<?php

namespace Tests\Unit\Intelligence;

use App\Intelligence\Support\RecurrencePairBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The deduplication guarantee, and the chain logic that sits on top of it.
 *
 * The first test in this file is the most important assertion in the PR: without it, one real
 * recurrence is counted once per duplicate label row, which inflated the first published garage
 * figures by 2.5–3× (33,026 raw fault rows collapse to 12,608 distinct events).
 */
class RecurrencePairBuilderTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'vehicle_id'     => 100,
            'signature'      => 'BRAKES',
            'occurred_at'    => '2026-01-10',
            'maintenance_id' => 1,
            'vendor_id'      => 200,
            'source'         => 'derived',
        ], $overrides);
    }

    /** @return array[] */
    private function build(array $rows): array
    {
        return iterator_to_array((new RecurrencePairBuilder())->build($rows), false);
    }

    /**
     * THE correctness test. Eight label rows for one fault on one day are ONE event, not eight —
     * and certainly not seven same-day "recurrences".
     */
    public function test_duplicate_label_rows_on_one_day_collapse_to_a_single_event(): void
    {
        $rows = [];
        for ($i = 1; $i <= 8; $i++) {
            $rows[] = $this->row(['maintenance_id' => $i]);
        }

        $events = $this->build($rows);

        $this->assertCount(1, $events, 'eight duplicate rows are one fault event');
        $this->assertSame(8, $events[0]['source_row_count'], 'the collapse is recorded, not hidden');
        $this->assertNull($events[0]['next_occurred_at'], 'a single event has no recurrence');
    }

    public function test_no_pair_can_ever_have_a_gap_below_one_day(): void
    {
        $events = $this->build([
            $this->row(['maintenance_id' => 1, 'occurred_at' => '2026-01-10']),
            $this->row(['maintenance_id' => 2, 'occurred_at' => '2026-01-10']),
            $this->row(['maintenance_id' => 3, 'occurred_at' => '2026-01-24']),
        ]);

        $this->assertCount(2, $events);
        $this->assertSame(14, $events[0]['days_to_return']);

        foreach ($events as $e) {
            if ($e['days_to_return'] !== null) {
                $this->assertGreaterThanOrEqual(1, $e['days_to_return']);
            }
        }
    }

    public function test_it_measures_the_gap_to_the_next_occurrence(): void
    {
        $events = $this->build([
            $this->row(['maintenance_id' => 1, 'occurred_at' => '2026-01-01']),
            $this->row(['maintenance_id' => 2, 'occurred_at' => '2026-01-21']),
            $this->row(['maintenance_id' => 3, 'occurred_at' => '2026-04-01']),
        ]);

        $this->assertSame(20, $events[0]['days_to_return']);
        $this->assertTrue($events[0]['returned_30']);

        $this->assertSame(70, $events[1]['days_to_return']);
        $this->assertFalse($events[1]['returned_30']);
        $this->assertFalse($events[1]['returned_60']);
        $this->assertTrue($events[1]['returned_90']);

        $this->assertNull($events[2]['days_to_return'], 'the last link in a chain is open');
    }

    /**
     * Open chains are the DENOMINATOR. A garage whose repairs never come back must be visible as
     * exactly that; dropping these rows makes every recurrence rate 100%.
     */
    public function test_faults_that_never_returned_are_kept(): void
    {
        $events = $this->build([$this->row()]);

        $this->assertCount(1, $events);
        $this->assertNull($events[0]['next_occurred_at']);
        $this->assertNull($events[0]['days_to_return']);
        $this->assertFalse($events[0]['returned_30']);
    }

    public function test_chains_are_scoped_to_one_vehicle_and_one_signature(): void
    {
        $events = $this->build([
            $this->row(['vehicle_id' => 100, 'signature' => 'BRAKES', 'occurred_at' => '2026-01-01']),
            $this->row(['vehicle_id' => 100, 'signature' => 'BRAKES', 'occurred_at' => '2026-02-01']),
            $this->row(['vehicle_id' => 100, 'signature' => 'ELECTRICAL', 'occurred_at' => '2026-01-05']),
            $this->row(['vehicle_id' => 101, 'signature' => 'BRAKES', 'occurred_at' => '2026-01-07']),
        ]);

        $this->assertCount(4, $events);
        $this->assertSame(31, $events[0]['days_to_return']);
        $this->assertNull($events[1]['days_to_return']);
        $this->assertNull($events[2]['days_to_return'], 'a different fault is a different chain');
        $this->assertNull($events[3]['days_to_return'], 'a different car is a different chain');
    }

    public function test_vendor_attribution_follows_the_lowest_maintenance_id(): void
    {
        $events = $this->build([
            $this->row(['maintenance_id' => 900, 'vendor_id' => 999]),
            $this->row(['maintenance_id' => 5, 'vendor_id' => 200]),
        ]);

        $this->assertCount(1, $events);
        $this->assertSame(5, $events[0]['first_maintenance_id']);
        $this->assertSame(200, $events[0]['first_vendor_id'], 'deterministic, not arrival-ordered');
        $this->assertTrue($events[0]['multi_vendor_day'], 'the ambiguity stays findable');
    }

    public function test_label_sources_merge_to_both_when_a_day_carries_derived_and_human(): void
    {
        $events = $this->build([
            $this->row(['maintenance_id' => 1, 'source' => 'derived']),
            $this->row(['maintenance_id' => 2, 'source' => 'human']),
        ]);

        $this->assertSame('both', $events[0]['label_source']);

        $single = $this->build([$this->row(['source' => 'human'])]);
        $this->assertSame('human', $single[0]['label_source']);
    }

    public function test_same_vendor_flags_whether_the_car_went_back_to_the_same_garage(): void
    {
        $back = $this->build([
            $this->row(['maintenance_id' => 1, 'occurred_at' => '2026-01-01', 'vendor_id' => 200]),
            $this->row(['maintenance_id' => 2, 'occurred_at' => '2026-02-01', 'vendor_id' => 200]),
        ]);
        $this->assertTrue($back[0]['same_vendor']);

        $elsewhere = $this->build([
            $this->row(['maintenance_id' => 1, 'occurred_at' => '2026-01-01', 'vendor_id' => 200]),
            $this->row(['maintenance_id' => 2, 'occurred_at' => '2026-02-01', 'vendor_id' => 300]),
        ]);
        $this->assertFalse($elsewhere[0]['same_vendor']);
    }

    public function test_chain_position_and_length_are_recorded(): void
    {
        $events = $this->build([
            $this->row(['maintenance_id' => 1, 'occurred_at' => '2026-01-01']),
            $this->row(['maintenance_id' => 2, 'occurred_at' => '2026-02-01']),
            $this->row(['maintenance_id' => 3, 'occurred_at' => '2026-03-01']),
        ]);

        $this->assertSame([1, 2, 3], array_column($events, 'chain_position'));
        $this->assertSame([3, 3, 3], array_column($events, 'chain_length'));
    }

    public function test_rows_that_cannot_be_placed_on_a_chain_are_skipped(): void
    {
        $events = $this->build([
            $this->row(['vehicle_id' => null]),
            $this->row(['occurred_at' => null]),
            $this->row(['occurred_at' => '0000-00-00']),
            $this->row(['signature' => null]),
            $this->row(['maintenance_id' => 9]),
        ]);

        $this->assertCount(1, $events);
        $this->assertSame(9, $events[0]['first_maintenance_id']);
    }

    public function test_events_with_no_garage_are_kept_but_unattributed(): void
    {
        $events = $this->build([$this->row(['vendor_id' => null])]);

        $this->assertCount(1, $events, 'the fault happened even if we cannot say where it was seen');
        $this->assertNull($events[0]['first_vendor_id']);
        $this->assertFalse($events[0]['multi_vendor_day']);
    }

    public function test_it_accepts_objects_as_well_as_arrays(): void
    {
        $events = $this->build([(object) $this->row()]);

        $this->assertCount(1, $events);
        $this->assertSame('BRAKES', $events[0]['signature']);
    }

    public function test_an_empty_stream_yields_nothing(): void
    {
        $this->assertSame([], $this->build([]));
    }

    // ── Right-censoring (days_observed) ─────────────────────────────────────────────────────────

    /**
     * A repair too recent to have failed must be distinguishable from one that held.
     *
     * Without this, an event recorded yesterday counts as a success, which flatters every garage —
     * and flatters the busiest ones most, because they have the most recent work.
     */
    public function test_events_are_stamped_with_how_long_they_have_been_watched(): void
    {
        $events = iterator_to_array((new RecurrencePairBuilder())->build([
            $this->row(['maintenance_id' => 1, 'occurred_at' => '2026-01-01']),
            $this->row(['maintenance_id' => 2, 'occurred_at' => '2026-06-30']),
        ], '2026-07-29'), false);

        $this->assertSame(209, $events[0]['days_observed'], 'Jan 1 → Jul 29');
        $this->assertSame(29, $events[1]['days_observed'], 'Jun 30 → Jul 29 — not yet judgeable at 90d');
    }

    public function test_the_horizon_is_the_corpus_edge_not_the_wall_clock(): void
    {
        // The corpus ends before today (signatures lag a sheet import). Anchoring on CURDATE()
        // silently discards fully-observed rows — one of the three horizons that had to be unified.
        $events = iterator_to_array((new RecurrencePairBuilder())->build([
            $this->row(['occurred_at' => '2026-07-29']),
        ], '2026-07-29'), false);

        $this->assertSame(0, $events[0]['days_observed'], 'an event on the corpus edge has been watched zero days');
    }

    public function test_days_observed_is_never_negative(): void
    {
        // A signature dated after the computed corpus max (clock skew, a late import) clamps to zero
        // rather than producing a negative that would pass a `>= window` filter as a huge number.
        $events = iterator_to_array((new RecurrencePairBuilder())->build([
            $this->row(['occurred_at' => '2026-08-15']),
        ], '2026-07-29'), false);

        $this->assertSame(0, $events[0]['days_observed']);
    }

    public function test_without_a_corpus_max_censoring_is_left_unstamped(): void
    {
        // The rebuild's validation rejects nulls, so this can never reach the table — but the builder
        // must not invent a horizon of its own when it was not given one.
        $events = $this->build([$this->row()]);

        $this->assertNull($events[0]['days_observed']);
    }
}
