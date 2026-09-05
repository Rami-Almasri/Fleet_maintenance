<?php

namespace Tests\Unit;

use App\Services\VehicleFaultHistoryService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE RULES THE DRY RUN RESTS ON.
 *
 * `recurrence:dry-run` asks what the recurring-fault detector WOULD open if it could see the sheet, and
 * the answer decides whether we point a writing detector at 21,928 rows of history. Every rule below
 * either removes a false case or preserves a real one, and each is here because the naive version is
 * wrong on this fleet's data:
 *
 *   • collapse — the log writes one repair as several rows (OUT / follow-up / IN, plus a row per garage
 *     the car moved between). Counted raw, one job reports a recurrence at a gap of zero days.
 *   • closed   — a fault that never left the workshop has not come back; it never went away. 8 of the
 *     dry run's 48 rejections are exactly this.
 *   • window   — a fault returning 164 days later is a different story from one returning in 8.
 *
 * These exercise the collapse/pair logic through its public surface with a synthetic timeline, so they
 * need no database and fail on the pull request rather than on the machine that has the data.
 */
class RecurrenceCandidateTest extends TestCase
{
    /**
     * The collapse + pair rules, driven directly. `pairsFor` and `collapse` are private because the
     * timeline they consume is the service's own; this reaches them the way the detector does, through
     * a reflected call on a hand-built timeline, so the RULES are asserted rather than the plumbing.
     *
     * @param  array<int,array{at:string, closed?:bool}>  $events
     * @return array<int,array<string,mixed>>
     */
    private function pairs(array $events, int $windowDays = 90): array
    {
        $service = app(VehicleFaultHistoryService::class);

        $timeline = array_map(fn ($e) => [
            'source' => 'sheet', 'key' => 'brake_worn_pads', 'catalog_slug' => 'brake_worn_pads',
            'category_key' => 'brakes', 'label' => 'Brake Pad Wear', 'grain' => 'specific',
            'at' => $e['at'], 'garage' => 'Test Garage', 'ref_ids' => [1], 'ticket_id' => null,
            'closed' => $e['closed'] ?? true, 'closed_at' => $e['at'],
        ], $events);

        $collapse = new \ReflectionMethod($service, 'collapse');
        $pairsFor = new \ReflectionMethod($service, 'pairsFor');

        return $pairsFor->invoke($service, $collapse->invoke($service, $timeline), $windowDays);
    }

    #[Test]
    public function one_repair_written_as_several_rows_is_not_a_recurrence(): void
    {
        // OUT Monday, follow-up Wednesday, back Friday — one job, three rows.
        $pairs = $this->pairs([
            ['at' => '2026-03-02'],
            ['at' => '2026-03-04'],
            ['at' => '2026-03-06'],
        ]);

        $this->assertSame([], $pairs, 'a car shuffling through one repair has not come back');
    }

    #[Test]
    public function the_same_fault_after_a_real_gap_is_a_recurrence(): void
    {
        $pairs = $this->pairs([
            ['at' => '2026-03-02'],
            ['at' => '2026-05-10'],
        ]);

        $this->assertCount(1, $pairs);
        $this->assertTrue($pairs[0]['qualifies']);
        $this->assertSame(69, $pairs[0]['gap_days']);
        $this->assertSame(2, $pairs[0]['occurrence'], 'the second time this fault was recorded');
    }

    /**
     * The rule that stops the detector counting a car that is STILL IN the workshop as one that came
     * back out of it.
     */
    #[Test]
    public function a_previous_visit_that_never_closed_cannot_have_come_back(): void
    {
        $pairs = $this->pairs([
            ['at' => '2026-03-02', 'closed' => false],
            ['at' => '2026-05-10'],
        ]);

        $this->assertFalse($pairs[0]['qualifies']);
        $this->assertContains('PREVIOUS_NEVER_CLOSED', $pairs[0]['rejected_by']);
    }

    #[Test]
    public function a_return_outside_the_window_is_reported_but_does_not_qualify(): void
    {
        $pairs = $this->pairs([
            ['at' => '2026-01-05'],
            ['at' => '2026-08-20'],
        ], 90);

        $this->assertFalse($pairs[0]['qualifies']);
        $this->assertContains('OUTSIDE_WINDOW', $pairs[0]['rejected_by']);
        // Reported, not silently dropped — a dry run that only shows what passed cannot be checked.
        $this->assertNotEmpty($pairs);
    }

    /** Three separate returns are three cases, each numbered, not one lumped case. */
    #[Test]
    public function every_separate_return_is_its_own_numbered_case(): void
    {
        $pairs = $this->pairs([
            ['at' => '2026-02-01'],
            ['at' => '2026-03-15'],
            ['at' => '2026-05-01'],
        ]);

        $this->assertCount(2, $pairs);
        $this->assertSame([2, 3], array_column($pairs, 'occurrence'));
        $this->assertTrue($pairs[0]['qualifies'] && $pairs[1]['qualifies']);
    }

    /**
     * Provenance survives into the candidate: a case built on imported history must be distinguishable
     * from one this system witnessed, because only one of them can be checked against a ticket.
     */
    #[Test]
    public function each_side_of_a_case_keeps_the_ledger_it_came_from(): void
    {
        $pairs = $this->pairs([
            ['at' => '2026-03-02'],
            ['at' => '2026-05-10'],
        ]);

        $this->assertSame(['sheet'], $pairs[0]['previous']['sources']);
        $this->assertSame(['sheet'], $pairs[0]['latest']['sources']);
        $this->assertTrue($pairs[0]['previous']['closed']);
    }
}
