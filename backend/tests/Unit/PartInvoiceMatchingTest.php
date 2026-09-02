<?php

namespace Tests\Unit;

use App\Models\PartInvoice;
use Tests\TestCase;

/**
 * The second pair of eyes on a supplier bill.
 *
 * Keying a bill and checking one are two jobs for two people, and they used to be one: whoever
 * bought the part typed the figures, and the only comparison the system made was between two numbers
 * that same person had typed. A total misread off the paper agreed with itself and passed.
 *
 * Two conditions decide whether a check means anything, and both are refusals rather than warnings.
 * They are asserted here without a database because they are decisions about two attributes, not
 * queries — and a money rule that can only be exercised through an HTTP round-trip tends to stop
 * being exercised at all.
 */
class PartInvoiceMatchingTest extends TestCase
{
    private function invoice(?int $recordedBy, bool $withPhoto = true): PartInvoice
    {
        $invoice = new PartInvoice();
        $invoice->recorded_by = $recordedBy;

        // photoUrl() derives from the stored disk/key pair; setting them is what makes a bill have
        // paper behind it.
        if ($withPhoto) {
            $invoice->photo_disk = 'public';
            $invoice->photo_key  = 'part-invoices/bill.jpg';
        }

        return $invoice;
    }

    // ── A check with nothing to check against is not a check ──────────────────────────────────────

    public function test_a_bill_with_no_photo_cannot_be_checked(): void
    {
        $why = $this->invoice(recordedBy: 7, withPhoto: false)->whyCannotBeCheckedBy(9);

        $this->assertNotNull($why);
        $this->assertStringContainsString('no photo', $why);
    }

    public function test_the_photo_rule_applies_even_to_a_bill_nobody_keyed(): void
    {
        // An imported bill has no recorder to be different from, so the self-check rule cannot fire.
        // The photo rule still must, or an import would be checkable against nothing at all.
        $this->assertNotNull($this->invoice(recordedBy: null, withPhoto: false)->whyCannotBeCheckedBy(9));
    }

    // ── The whole value is that a SECOND person looked ────────────────────────────────────────────

    public function test_the_person_who_keyed_the_bill_cannot_check_it(): void
    {
        $why = $this->invoice(recordedBy: 7)->whyCannotBeCheckedBy(7);

        $this->assertNotNull($why);
        $this->assertStringContainsString('second pair of eyes', $why);
    }

    public function test_a_different_person_may_check_it(): void
    {
        $this->assertNull($this->invoice(recordedBy: 7)->whyCannotBeCheckedBy(9));
    }

    public function test_a_bill_with_no_recorder_may_be_checked_by_anyone(): void
    {
        // Nothing to be a self-check against, and the paper is there — so the check is meaningful.
        $this->assertNull($this->invoice(recordedBy: null)->whyCannotBeCheckedBy(9));
    }

    public function test_the_photo_rule_is_checked_before_the_self_check_rule(): void
    {
        // Both fail here. The photo answer is the more actionable one — "attach the paper" is
        // something the reader can do, "find someone else" is not, when there is nothing to look at.
        $why = $this->invoice(recordedBy: 7, withPhoto: false)->whyCannotBeCheckedBy(7);

        $this->assertStringContainsString('no photo', $why);
    }

    // ── Three states, never two ───────────────────────────────────────────────────────────────────

    public function test_an_unchecked_bill_is_not_matched(): void
    {
        $this->assertFalse($this->invoice(recordedBy: 7)->isMatched());
    }

    public function test_a_checked_bill_is_matched_whichever_way_it_went(): void
    {
        // "Somebody looked and disagreed" is still somebody having looked. Collapsing that into the
        // same false as "nobody has looked yet" is exactly what a boolean column would have done,
        // and why the schema stores a timestamp and a result instead.
        $disputed = $this->invoice(recordedBy: 7);
        $disputed->matched_at   = now();
        $disputed->match_result = PartInvoice::MATCH_DISPUTED;

        $this->assertTrue($disputed->isMatched());
    }

    public function test_there_are_exactly_two_outcomes(): void
    {
        // A check that can only ever pass is not a check.
        $this->assertSame(['matches', 'disputed'], PartInvoice::MATCH_RESULTS);
    }
}
