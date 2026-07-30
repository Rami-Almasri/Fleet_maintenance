<?php

namespace Tests\Unit;

use App\Models\RepairInspection;
use Tests\TestCase;

/**
 * The fourth verdict: "the inspector attended and genuinely could not tell".
 *
 * With only fixed/still_exists on offer, an inspector who cannot verify has two bad options — guess
 * "fixed", which injects a false positive into the only trustworthy dataset the platform has, or
 * record nothing, which loses the ticket silently and looks identical to a skipped queue.
 *
 * The rule it must obey, and the reason every query was changed:
 *   COUNTS toward coverage      — the workflow step genuinely happened
 *   EXCLUDED from statistics    — it says nothing about whether the repair held
 *   RECORDS WHY                 — "no usable verdict" is not one problem
 */
class UnverifiableVerdictTest extends TestCase
{
    public function test_it_is_a_recognised_verdict(): void
    {
        $this->assertContains(RepairInspection::RESULT_UNABLE_TO_VERIFY, RepairInspection::RESULTS);
    }

    /**
     * THE LOAD-BEARING ASSERTION. Every statistic and every model starts from CONCLUSIVE_RESULTS;
     * if this verdict ever leaked in, it would either inflate repair quality or defame a garage.
     */
    public function test_it_is_never_conclusive(): void
    {
        $this->assertNotContains(RepairInspection::RESULT_UNABLE_TO_VERIFY, RepairInspection::CONCLUSIVE_RESULTS);

        foreach ([RepairInspection::RESULT_FIXED, RepairInspection::RESULT_STILL_EXISTS, RepairInspection::RESULT_NEW_ISSUE] as $conclusive) {
            $this->assertContains($conclusive, RepairInspection::CONCLUSIVE_RESULTS);
        }
    }

    /** It is not a failure: it must never blame a garage or raise a repair-failure alert. */
    public function test_it_is_not_counted_as_a_returned_repair(): void
    {
        $this->assertNotContains(RepairInspection::RESULT_UNABLE_TO_VERIFY, RepairInspection::RETURNED_RESULTS);
    }

    public function test_isConclusive_reflects_the_verdict(): void
    {
        $unverifiable = new RepairInspection(['result' => RepairInspection::RESULT_UNABLE_TO_VERIFY]);
        $fixed        = new RepairInspection(['result' => RepairInspection::RESULT_FIXED]);
        $failed       = new RepairInspection(['result' => RepairInspection::RESULT_STILL_EXISTS]);

        $this->assertFalse($unverifiable->isConclusive());
        $this->assertTrue($fixed->isConclusive());
        $this->assertTrue($failed->isConclusive(), 'A failure is still a usable measurement.');
    }

    /**
     * The reasons are separate vocabularies on purpose. A car the customer drove away in is a
     * scheduling failure; a fault that will not reproduce is a diagnostic one. They need different
     * fixes, and sharing a bucket would hide both.
     */
    public function test_unverifiable_reasons_do_not_overlap_with_failure_reasons(): void
    {
        $failure = [
            RepairInspection::REASON_WRONG_DIAGNOSIS, RepairInspection::REASON_PART_FAILED,
            RepairInspection::REASON_REPAIR_INCOMPLETE, RepairInspection::REASON_WRONG_PART,
            RepairInspection::REASON_CUSTOMER_COMPLAINT, RepairInspection::REASON_UNKNOWN,
        ];

        $this->assertSame([], array_intersect($failure, RepairInspection::UNVERIFIABLE_REASONS));
    }

    /** Both vocabularies must validate against the same column, so REASONS carries them all. */
    public function test_every_reason_is_accepted_by_the_shared_validator(): void
    {
        foreach (RepairInspection::UNVERIFIABLE_REASONS as $reason) {
            $this->assertContains($reason, RepairInspection::REASONS, "{$reason} would fail request validation.");
        }
    }

    /** A blank reason must fall back inside the unverifiable vocabulary, never to `unknown`. */
    public function test_the_fallback_reason_cannot_be_mistaken_for_a_failure(): void
    {
        $this->assertContains(RepairInspection::UNVERIFIABLE_OTHER, RepairInspection::UNVERIFIABLE_REASONS);
        $this->assertNotSame(RepairInspection::REASON_UNKNOWN, RepairInspection::UNVERIFIABLE_OTHER);
    }
}
