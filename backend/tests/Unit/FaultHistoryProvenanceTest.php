<?php

namespace Tests\Unit;

use App\Services\VehicleFaultHistoryService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE PROVENANCE CONTRACT for "has this car had this before?".
 *
 * Both surfaces that ask the question — the test-drive bench and the dashboard's What Keeps Coming Back
 * — now read both ledgers: the imported N-Maintenance workshop log, and this system's own fault records.
 * Merging them is the easy half. The half that has to be locked down is that the merge is never
 * ANONYMOUS: a technician told "seen 3 times" must be able to see whether that rests on the old log, on
 * records this system produced, or on both, because those three answers carry different weight and only
 * one of them is checkable against a ticket.
 *
 * See [[traceability-visibility-requirement]] — every page shows its Data Origin.
 */
class FaultHistoryProvenanceTest extends TestCase
{
    #[Test]
    public function history_from_one_ledger_names_that_ledger(): void
    {
        $this->assertSame('SHEET', VehicleFaultHistoryService::sourceCode(['sheet']));
        $this->assertSame('SYSTEM', VehicleFaultHistoryService::sourceCode(['system']));
    }

    /**
     * The case the requirement calls out by name: a fault found in both places must SAY so. It is
     * stronger evidence than either ledger alone, and hiding the overlap would throw that away.
     */
    #[Test]
    public function a_fault_evidenced_in_both_ledgers_says_both(): void
    {
        $this->assertSame('BOTH', VehicleFaultHistoryService::sourceCode(['sheet', 'system']));
    }

    /**
     * "We checked and found nothing" is a different answer from "no data", and the bench needs to be
     * able to tell them apart — a clean car is reassuring, an unanswered question is not.
     */
    #[Test]
    public function no_history_is_its_own_answer_rather_than_an_empty_source(): void
    {
        $this->assertSame('NONE', VehicleFaultHistoryService::sourceCode([]));
    }

    // ── Identity ────────────────────────────────────────────────────────────────────────────────

    /**
     * The two ledgers must key one fault identically or they can never match. This is the same
     * resolution the sheet reader applies, asserted from the lookup side so the pair cannot drift.
     */
    #[Test]
    public function sheet_wording_and_ticket_wording_identify_as_one_fault(): void
    {
        $service = app(VehicleFaultHistoryService::class);

        $sheet  = $service->identify('Battery Weak or Dead');
        $ticket = $service->identify("Battery / won't start");

        $this->assertSame($sheet['key'], $ticket['key']);
        $this->assertSame('elec_battery_failure', $sheet['key']);
        $this->assertSame('electrical', $sheet['category_key']);
    }

    /**
     * A system word identifies as its category and nothing finer, so it can never be mistaken for a
     * specific fault in that system. This is the guard against "every Engine visit is a recurrence of
     * every engine issue", asserted at the identity layer rather than only inside the sheet reader.
     */
    #[Test]
    public function a_system_word_never_identifies_as_a_specific_fault(): void
    {
        $service = app(VehicleFaultHistoryService::class);

        $broad    = $service->identify('Engine');
        $specific = $service->identify('Engine Oil leak');

        $this->assertNull($broad['catalog_slug'], 'a system word names no catalog fault');
        $this->assertNotSame($broad['key'], $specific['key']);
        $this->assertSame('fluid_oil_leak', $specific['catalog_slug']);
    }
}
