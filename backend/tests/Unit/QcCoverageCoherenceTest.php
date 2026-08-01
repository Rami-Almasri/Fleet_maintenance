<?php

namespace Tests\Unit;

use App\Services\Intelligence\Readiness\EvidenceLedger;
use RuntimeException;
use Tests\TestCase;

/**
 * QC coverage must be a real ratio, not two unrelated counts divided by each other.
 *
 * This guard exists because of a shipped defect that read perfectly sensibly: the numerator counted
 * every repair inspection ever recorded, while the denominator counted only closed, verdict-eligible
 * tickets. Inspections on tickets that were still open therefore inflated coverage — the platform
 * reported 47% capture when the true figure for finished repairs was 23%, overstating its own
 * evidence position by roughly two-fold.
 *
 * Nothing about the code looked wrong. The only thing that gave it away was an arithmetic
 * impossibility further down the same array: more evidence lost in the last fortnight than in all of
 * history. These four invariants make that class of mistake loud instead of plausible.
 */
class QcCoverageCoherenceTest extends TestCase
{
    private function coverage(array $overrides = []): array
    {
        return $overrides + [
            'closed' => 13, 'with_verdict' => 3, 'unverifiable' => 0,
            'coverage' => 3 / 13, 'lost' => 10, 'lost_recently' => 6, 'leaking' => true,
        ];
    }

    public function test_a_coherent_reading_passes_through_untouched(): void
    {
        $qc = $this->coverage();

        $this->assertSame($qc, EvidenceLedger::assertCoherent($qc));
    }

    /**
     * THE ORIGINAL DEFECT. Verdicts counted from a wider population than the denominator — which is
     * exactly how coverage can exceed 100% while every individual query looks correct.
     */
    public function test_more_verdicts_than_eligible_tickets_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/numerator and denominator describe different tickets/');

        EvidenceLedger::assertCoherent($this->coverage(['with_verdict' => 20, 'lost' => 0]));
    }

    /** The symptom that actually exposed it: a fortnight losing more than all of history. */
    public function test_losing_more_recently_than_in_total_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/recent loss exceeds lifetime loss/');

        EvidenceLedger::assertCoherent($this->coverage(['lost' => 2, 'lost_recently' => 6]));
    }

    /** `unable_to_verify` is a subset of the verdicts recorded, never a superset. */
    public function test_more_unverifiable_than_verdicts_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unverifiable exceeds with_verdict/');

        EvidenceLedger::assertCoherent($this->coverage(['unverifiable' => 9]));
    }

    /** Loss must be the arithmetic complement of coverage, or one of the two is measuring elsewhere. */
    public function test_a_loss_that_does_not_reconcile_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/lost does not reconcile/');

        EvidenceLedger::assertCoherent($this->coverage(['lost' => 4]));
    }

    /** An empty fleet is coherent, not an error — every count is zero and the ratio is defined as 0. */
    public function test_an_empty_fleet_is_coherent(): void
    {
        $empty = [
            'closed' => 0, 'with_verdict' => 0, 'unverifiable' => 0,
            'coverage' => 0.0, 'lost' => 0, 'lost_recently' => 0, 'leaking' => false,
        ];

        $this->assertSame($empty, EvidenceLedger::assertCoherent($empty));
    }
}
