<?php

namespace Tests\Unit;

use App\Services\Garage\RepairCostIndex;
use PHPUnit\Framework\TestCase;

/**
 * The cost ladder's job is not to produce a number — it is to refuse to produce a number that claims
 * more than the evidence supports. These tests are mostly about what it DECLINES to say.
 */
class RepairCostIndexTest extends TestCase
{
    private function idx(array $buckets, array $min = ['garage_fault_model' => 5, 'garage_fault' => 5, 'garage' => 5]): RepairCostIndex
    {
        return new RepairCostIndex($buckets, $min);
    }

    private function b(float $median, int $n): array
    {
        return ['median' => $median, 'p90' => $median * 2, 'n' => $n];
    }

    public function test_the_finest_grain_wins_when_it_has_the_samples(): void
    {
        $i = $this->idx([
            RepairCostIndex::key('fleet')                                    => $this->b(500, 9000),
            RepairCostIndex::key('garage', 7)                                => $this->b(600, 800),
            RepairCostIndex::key('garage_fault', 7, 'engine')                => $this->b(900, 40),
            RepairCostIndex::key('garage_fault_model', 7, 'engine', 'Yukon') => $this->b(1200, 8),
        ]);

        // This garage, this fault, this model — the only rung that answers the question actually asked.
        $r = $i->estimate(7, ['engine'], 'Yukon');
        $this->assertSame(1200.0, $r['value']);
        $this->assertSame('garage_fault_model', $r['basis']);
        $this->assertSame(8, $r['sample']);

        // A different model drops one rung, and the figure changes to match.
        $this->assertSame('garage_fault', $i->estimate(7, ['engine'], 'Patrol')['basis']);
        $this->assertSame(900.0, $i->estimate(7, ['engine'], 'Patrol')['value']);
    }

    public function test_a_thin_bucket_is_demoted_rather_than_believed(): void
    {
        $i = $this->idx([
            RepairCostIndex::key('fleet')                     => $this->b(500, 9000),
            RepairCostIndex::key('garage', 7)                 => $this->b(600, 800),
            // Two repairs is not a price, however specific it looks.
            RepairCostIndex::key('garage_fault', 7, 'engine') => $this->b(9999, 2),
        ]);

        $r = $i->estimate(7, ['engine'], 'Yukon');
        $this->assertSame(600.0, $r['value']);
        $this->assertSame('garage', $r['basis']);
        $this->assertStringContainsString('across all its work', $r['reason']);
        // The rung it could not use is still reported, so the demotion is visible rather than silent.
        $this->assertSame(800, $r['support']['garage']);
    }

    public function test_multiple_faults_are_summed_not_averaged(): void
    {
        // A car in for an engine knock AND a scraped door costs both. A median of the two would quote
        // the supervisor roughly half the job.
        $i = $this->idx([
            RepairCostIndex::key('fleet')                       => $this->b(500, 9000),
            RepairCostIndex::key('garage', 7)                   => $this->b(600, 800),
            RepairCostIndex::key('garage_fault', 7, 'engine')   => $this->b(900, 40),
            RepairCostIndex::key('garage_fault', 7, 'bodywork') => $this->b(450, 30),
        ]);

        $r = $i->estimate(7, ['engine', 'bodywork']);
        $this->assertSame(1350.0, $r['value']);
        $this->assertSame('garage_fault', $r['basis']);
        // Confidence follows the WEAKEST leg — a sum is only as sound as its thinnest component.
        $this->assertSame(30, $r['sample']);
    }

    public function test_a_partial_sum_is_refused_because_it_would_understate_the_job(): void
    {
        // Engine can be priced, interior cannot. Summing only what we can price produces a number that
        // looks like the job total and silently omits a fault — worse than falling back honestly.
        $i = $this->idx([
            RepairCostIndex::key('fleet')                     => $this->b(500, 9000),
            RepairCostIndex::key('garage', 7)                 => $this->b(600, 800),
            RepairCostIndex::key('garage_fault', 7, 'engine') => $this->b(900, 40),
        ]);

        $r = $i->estimate(7, ['engine', 'interior']);
        $this->assertSame(600.0, $r['value'], 'a partial sum was published as if it were the job total');
        $this->assertSame('garage', $r['basis']);

        // But the fault that CAN be priced still appears in the breakdown — refusing the total is not
        // a reason to hide the one real price we have.
        $breakdown = $i->breakdown(7, ['engine', 'interior']);
        $this->assertCount(1, $breakdown);
        $this->assertSame('engine', $breakdown[0]['fault']);
    }

    public function test_a_garage_with_no_priced_history_falls_to_the_fleet_and_says_so(): void
    {
        $i = $this->idx([RepairCostIndex::key('fleet') => $this->b(500, 9000)]);

        $r = $i->estimate(99, ['engine'], 'Yukon');
        $this->assertSame(500.0, $r['value']);
        $this->assertSame('fleet', $r['basis']);
        $this->assertStringContainsString('not specific to them', $r['reason']);
    }

    public function test_an_empty_ledger_yields_no_number_at_all(): void
    {
        // The failure that started this work was a fabricated-looking figure. With nothing behind it,
        // the honest output is null plus a reason — never a zero, never a fleet median that is not there.
        $r = $this->idx([])->estimate(7, ['engine']);

        $this->assertNull($r['value']);
        $this->assertSame('unavailable', $r['basis']);
        $this->assertNotEmpty($r['reason']);
    }

    public function test_a_category_price_never_borrows_from_the_garages_other_work(): void
    {
        // Phase 2's whole point: a category figure appears when it is earned and stays away when it is
        // not. Falling back to the garage median here would print "Interior: AED 600" off zero interior
        // repairs, which is exactly the magic number this engine exists to eliminate.
        $i = $this->idx([
            RepairCostIndex::key('garage', 7)                 => $this->b(600, 800),
            RepairCostIndex::key('garage_fault', 7, 'engine') => $this->b(900, 40),
        ]);

        $this->assertNull($i->forFault(7, 'interior')['value']);
        $this->assertSame([], $i->breakdown(7, ['interior']));
    }
}
