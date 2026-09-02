<?php

namespace Tests\Unit;

use App\Support\FinancialDocumentStatus as Status;
use Tests\TestCase;

/**
 * WHICH ACTIONS A DOCUMENT OFFERS, pinned state by state.
 *
 * The ledger used to render one button per legal transition, which meant a draft invoice showed Submit,
 * Approve and Cancel at once, beside Edit and Delete — five actions with no order of importance, one of
 * which skipped the review stage another one existed to start. The fix is not a shorter button list: it
 * is that "what may be OFFERED" became its own answer, narrower than "what is LEGAL", and computed in
 * one place that every screen reads.
 *
 * These are decisions about a state, a permission and a flag — no database, no HTTP — so the whole
 * matrix can be asserted cheaply. The HTTP half (that the API actually REFUSES what this list leaves
 * out) is pinned in Tests\Crud\InvoiceActionAuthorizationTest; a UI that merely hides a button has
 * enforced nothing, and both halves have to hold for the rule to be real.
 */
class InvoiceActionMachineTest extends TestCase
{
    /** A user who can do everything. */
    private function superuser(): callable
    {
        return fn () => true;
    }

    /** A user holding exactly the named permissions and nothing else. */
    private function holding(string ...$permissions): callable
    {
        return fn (string $p) => in_array($p, $permissions, true);
    }

    /** @return array<int, string> the action keys offered, in the order they should be read. */
    private function keys(?string $shown, ?string $stored = null, bool $editable = false, ?callable $can = null): array
    {
        return array_column(
            Status::actionsFor($shown, $stored ?? $shown, $editable, $can ?: $this->superuser()),
            'key',
        );
    }

    private function primary(?string $shown, ?string $stored = null, bool $editable = false): ?string
    {
        foreach (Status::actionsFor($shown, $stored ?? $shown, $editable, $this->superuser()) as $a) {
            if ($a['primary']) {
                return $a['key'];
            }
        }

        return null;
    }

    // ── 1. The offer, state by state ─────────────────────────────────────────────────────────────────

    /**
     * The state the whole change was reported against. Submit leads because asking for a second look is
     * what a finished draft is waiting for; Edit and Delete are there because a draft is still paper.
     * Approve is NOT here — it stays legal (see the direct-approval test below) but offering it beside
     * Submit would make skipping review the same single click as asking for it.
     */
    public function test_a_draft_offers_submit_edit_delete_and_cancel_but_never_approve(): void
    {
        $keys = $this->keys(Status::DRAFT, editable: true);

        $this->assertSame([Status::ACTION_SUBMIT, Status::ACTION_EDIT, Status::ACTION_DELETE, Status::ACTION_CANCEL], $keys);
        $this->assertNotContains(Status::ACTION_APPROVE, $keys, 'Approving straight from a draft skips the review stage and must not be one click away.');
        $this->assertSame(Status::ACTION_SUBMIT, $this->primary(Status::DRAFT, editable: true));
    }

    /** A submitted bill is waiting for a decision, and refusing it must not mean destroying it. */
    public function test_a_pending_invoice_offers_approve_and_return_for_correction(): void
    {
        $keys = $this->keys(Status::PENDING, editable: true);

        $this->assertContains(Status::ACTION_APPROVE, $keys);
        $this->assertContains(Status::ACTION_RETURN, $keys);
        $this->assertSame(Status::ACTION_APPROVE, $this->primary(Status::PENDING, editable: true));
    }

    /** Approval closes the paper. What is left is money and the two ways to undo the decision. */
    public function test_an_approved_invoice_offers_payment_not_editing(): void
    {
        $keys = $this->keys(Status::APPROVED, editable: false);

        $this->assertSame([Status::ACTION_PAY, Status::ACTION_UNAPPROVE, Status::ACTION_CANCEL], $keys);
        $this->assertNotContains(Status::ACTION_EDIT, $keys);
        $this->assertNotContains(Status::ACTION_DELETE, $keys);
        $this->assertSame(Status::ACTION_PAY, $this->primary(Status::APPROVED));
    }

    /**
     * A part-paid bill is STORED as approved, so the raw machine would still offer to withdraw that
     * approval — on money somebody has already been paid. The derived state has its own, narrower
     * TRANSITIONS entry, and the offer follows it.
     */
    public function test_a_part_paid_invoice_cannot_have_its_approval_withdrawn(): void
    {
        $keys = $this->keys(Status::PARTIALLY_PAID, stored: Status::APPROVED);

        $this->assertSame([Status::ACTION_PAY, Status::ACTION_CANCEL], $keys);
        $this->assertNotContains(Status::ACTION_UNAPPROVE, $keys, 'Withdrawing approval on a bill already part-paid would strand the payment.');
    }

    /** Settled. Cancelling remains legal for a bill paid in error, but it is not what the row leads with. */
    public function test_a_paid_invoice_leads_with_nothing_and_keeps_cancel_behind_the_menu(): void
    {
        $this->assertSame([Status::ACTION_CANCEL], $this->keys(Status::PAID));
        $this->assertNull($this->primary(Status::PAID), 'A destructive move is never a row’s headline action.');
    }

    /** Withdrawn documents are read, not acted on. */
    public function test_a_cancelled_invoice_offers_nothing(): void
    {
        $this->assertSame([], $this->keys(Status::CANCELLED, editable: true));
        $this->assertNull($this->primary(Status::CANCELLED));
    }

    // ── 2. The invariant that makes the list safe to render ──────────────────────────────────────────

    /**
     * The offer may narrow the machine; it may never widen it. If this ever fails, a screen is showing a
     * button whose endpoint will refuse it — the exact drift that keeping two lists creates.
     */
    public function test_every_offered_move_is_a_legal_transition_from_the_stored_status(): void
    {
        foreach (Status::ALL as $stored) {
            foreach (Status::actionsFor($stored, $stored, true, $this->superuser()) as $action) {
                if ($action['to'] === null) {
                    continue;   // edit / delete move nothing
                }
                $this->assertTrue(
                    Status::canMove($stored, $action['to']),
                    "'{$action['key']}' is offered on a {$stored} document but {$stored} → {$action['to']} is not a legal move.",
                );
            }
        }
    }

    /** Every state in the vocabulary has an answer, so no document can render an undefined action list. */
    public function test_every_status_has_a_declared_offer(): void
    {
        foreach (Status::ALL as $status) {
            $this->assertArrayHasKey($status, Status::ACTIONS, "No action list is declared for '{$status}'.");
        }
    }

    /** Direct approval stays LEGAL — the API and its callers depend on it; it is only un-OFFERED. */
    public function test_approving_a_draft_remains_a_legal_transition(): void
    {
        $this->assertTrue(Status::canMove(Status::DRAFT, Status::APPROVED));
    }

    // ── 3. Permission ────────────────────────────────────────────────────────────────────────────────

    /**
     * The two permissions are genuinely different jobs — keying the paper is parts.purchase, committing
     * the money is maintenance.manage — and the page used to gate ALL of it on parts.purchase. A buyer
     * was therefore shown Approve and Cancel buttons that the API would refuse with a 403.
     */
    public function test_a_buyer_is_offered_only_the_paper_actions(): void
    {
        $this->assertSame(
            [Status::ACTION_EDIT, Status::ACTION_DELETE],
            $this->keys(Status::DRAFT, editable: true, can: $this->holding('parts.purchase')),
        );
    }

    /** And an approver who cannot key paper is offered the money moves, without Edit or Delete. */
    public function test_an_approver_is_offered_only_the_money_actions(): void
    {
        $this->assertSame(
            [Status::ACTION_SUBMIT, Status::ACTION_CANCEL],
            $this->keys(Status::DRAFT, editable: true, can: $this->holding('maintenance.manage')),
        );
    }

    /** A reader with neither permission gets a row that shows its state and offers nothing. */
    public function test_a_reader_is_offered_nothing(): void
    {
        $this->assertSame([], $this->keys(Status::DRAFT, editable: true, can: $this->holding('parts.view')));
    }

    /**
     * The headline is derived, not declared, so losing the permission for the leading action promotes
     * the next real one rather than leaving the row with an empty primary slot.
     */
    public function test_the_primary_action_falls_through_to_what_the_user_can_actually_do(): void
    {
        $actions = Status::actionsFor(Status::PENDING, Status::PENDING, true, $this->holding('parts.purchase'));

        $this->assertSame([Status::ACTION_EDIT, Status::ACTION_DELETE], array_column($actions, 'key'));
        $this->assertSame([], array_filter(array_column($actions, 'primary')), 'Edit is a correction, never the headline.');
    }

    /**
     * The two document kinds share this map but not their write gate: a supplier bill is keyed by whoever
     * buys parts, a garage bill by whoever runs maintenance. The map holds a placeholder and each document
     * substitutes its own answer, so a garage invoice never reports that editing it needs a parts
     * permission — which is neither what its route checks nor true.
     */
    public function test_the_paper_permission_is_the_documents_own(): void
    {
        $garage = Status::actionsFor(Status::DRAFT, Status::DRAFT, true, $this->superuser(), 'maintenance.manage');
        $edit   = array_values(array_filter($garage, fn ($a) => $a['key'] === Status::ACTION_EDIT))[0];

        $this->assertSame('maintenance.manage', $edit['permission']);
        $this->assertNotSame(Status::PERM_PAPER, $edit['permission'], 'The placeholder leaked into the payload.');

        // And the supplier bill's default is unchanged.
        $supplier = Status::actionsFor(Status::DRAFT, Status::DRAFT, true, $this->superuser());
        $this->assertSame('parts.purchase', array_values(array_filter($supplier, fn ($a) => $a['key'] === Status::ACTION_EDIT))[0]['permission']);
    }

    /** A garage-invoice reader who lacks maintenance.manage is offered nothing at all on a draft. */
    public function test_a_garage_invoice_respects_its_own_permission_when_filtering(): void
    {
        $this->assertSame(
            [],
            array_column(
                Status::actionsFor(Status::DRAFT, Status::DRAFT, true, $this->holding('parts.purchase'), 'maintenance.manage'),
                'key',
            ),
        );
    }

    // ── 4. Editability ───────────────────────────────────────────────────────────────────────────────

    /** Edit and Delete track the document's own editability, not merely its name. */
    public function test_edit_and_delete_disappear_once_the_document_is_not_editable(): void
    {
        $keys = $this->keys(Status::PENDING, editable: false);

        $this->assertNotContains(Status::ACTION_EDIT, $keys);
        $this->assertNotContains(Status::ACTION_DELETE, $keys);
        $this->assertContains(Status::ACTION_APPROVE, $keys, 'The lifecycle moves are unaffected by editability.');
    }

    /** Cancelling and returning require a written reason; approving and submitting do not. */
    public function test_the_destructive_moves_declare_that_they_need_a_reason(): void
    {
        $byKey = [];
        foreach (Status::actionsFor(Status::PENDING, Status::PENDING, true, $this->superuser()) as $a) {
            $byKey[$a['key']] = $a;
        }

        $this->assertTrue($byKey[Status::ACTION_CANCEL]['needs_reason']);
        $this->assertTrue($byKey[Status::ACTION_RETURN]['needs_reason']);
        $this->assertFalse($byKey[Status::ACTION_APPROVE]['needs_reason']);
        $this->assertTrue($byKey[Status::ACTION_CANCEL]['danger']);
    }
}
