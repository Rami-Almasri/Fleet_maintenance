<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\PartInvoice;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\User;
use App\Support\FinancialDocumentStatus as Status;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * THE STATE MACHINE IS ENFORCED, not merely displayed.
 *
 * The companion to Tests\Unit\InvoiceActionMachineTest, which pins which actions a row OFFERS. This one
 * pins the half that actually protects the money: that the API refuses the moves the row leaves out.
 *
 * That distinction was not academic. The ledger has always hidden Edit and Delete on an approved invoice
 * — {@see \App\Models\Concerns\IsFinancialDocument::isEditable()} said so, and the page honoured it — but
 * nothing on the server did. Anyone holding parts.purchase could re-key or delete an approved bill by
 * calling the endpoint, including one with payments already allocated against it, which would leave the
 * money pointing at a document that no longer existed. A hidden button is a suggestion.
 *
 * Three things are asserted here, for every state that matters:
 *
 *   1. A legal move succeeds and lands in the state the machine declares.
 *   2. An illegal move is refused with an error that names where the document actually stands.
 *   3. The refusals are about the DOCUMENT, not about who is looking at it — the permission gate is a
 *      separate layer on the routes, and both have to hold.
 */
class InvoiceActionAuthorizationTest extends CrudTestCase
{
    private function ticket(): Maintenance
    {
        return Maintenance::create([
            'vehicle_id'     => $this->makeVehicle(),
            'type'           => 'Breakdown',
            'status'         => 'Open',
            'actual_in_date' => Carbon::now()->toDateString(),
        ]);
    }

    private function purchase(Maintenance $ticket): PartPurchase
    {
        $request = PartRequest::create([
            'source'         => PartRequest::SOURCE_GARAGE,
            'status'         => PartRequest::STATUS_APPROVED,
            'vehicle_id'     => $ticket->vehicle_id,
            'maintenance_id' => $ticket->id,
            'part_name'      => 'Brake Pad Set',
            'quantity'       => 1,
            'reason'         => 'Worn',
            'requested_at'   => Carbon::now(),
        ]);

        return PartPurchase::create([
            'part_request_id' => $request->id,
            'vehicle_id'      => $ticket->vehicle_id,
            'maintenance_id'  => $ticket->id,
            'part_name'       => 'Brake Pad Set',
            'purchase_source' => PartPurchase::SOURCE_SUPPLIER,
            'source_name'     => 'ABC Auto Parts',
            'purchase_price'  => 400,
            'currency'        => 'AED',
            'quantity'        => 1,
            'purchased_at'    => Carbon::now(),
        ]);
    }

    /** A freshly keyed draft invoice, created through the real endpoint. */
    private function draft(string $no = 'INV-STATE-1'): PartInvoice
    {
        $ticket = $this->ticket();
        $res = $this->postJson('/api/part-invoices', [
            'supplier_name' => 'ABC Auto Parts',
            'invoice_no'    => $no,
            'purchase_ids'  => [$this->purchase($ticket)->id],
        ]);
        $res->assertSuccessful();

        return PartInvoice::findOrFail($this->idOf($res));
    }

    private function move(PartInvoice $inv, string $verb, array $body = [])
    {
        return $this->postJson("/api/financial-documents/supplier-invoice/{$inv->id}/{$verb}", $body);
    }

    /** The action keys the API says this invoice offers, for the currently authenticated user. */
    private function offered(PartInvoice $inv): array
    {
        $res = $this->getJson("/api/part-invoices/{$inv->id}");
        $res->assertSuccessful();

        return array_column(data_get($res->json(), 'data.actions', []), 'key');
    }

    // ── 1. Draft ─────────────────────────────────────────────────────────────────────────────────────

    /** The row the whole change was reported against: invoice 33333, a draft, nobody's second eyes yet. */
    public function test_a_draft_offers_submit_edit_and_delete_over_the_wire(): void
    {
        $inv = $this->draft();

        $this->assertSame(Status::DRAFT, $inv->status);
        $this->assertSame(
            [Status::ACTION_SUBMIT, Status::ACTION_EDIT, Status::ACTION_DELETE, Status::ACTION_CANCEL],
            $this->offered($inv),
        );
    }

    /** Submit is the move a draft is waiting for, and it lands in pending. */
    public function test_a_draft_can_be_submitted_for_review(): void
    {
        $inv = $this->draft();

        $this->move($inv, 'submit')->assertSuccessful();
        $this->assertSame(Status::PENDING, $inv->fresh()->status);
    }

    /** Paying a draft was never legal and still is not — the machine refuses before any money is written. */
    public function test_a_draft_cannot_be_paid(): void
    {
        $inv = $this->draft();

        $this->move($inv, 'pay', ['amount' => 100])->assertStatus(422);
        $this->assertSame(Status::DRAFT, $inv->fresh()->status);
        $this->assertSame(0.0, round((float) $inv->fresh()->paid_amount, 2));
    }

    /** A draft is still paper: it can be re-keyed and removed. */
    public function test_a_draft_can_be_edited_and_deleted(): void
    {
        $inv = $this->draft();
        $this->postJson("/api/part-invoices/{$inv->id}", ['invoice_no' => 'INV-EDITED'])->assertSuccessful();
        $this->assertSame('INV-EDITED', $inv->fresh()->invoice_no);

        $this->deleteJson("/api/part-invoices/{$inv->id}")->assertSuccessful();
        $this->assertNull(PartInvoice::find($inv->id));
    }

    // ── 2. Pending ───────────────────────────────────────────────────────────────────────────────────

    /**
     * Refusing a submitted bill without destroying it. PENDING → DRAFT was always legal in the machine
     * but nothing could perform it, so the only answer a reviewer could give a wrong bill was to cancel
     * it — which says the obligation does not exist, rather than that the paper is wrong.
     */
    public function test_a_pending_invoice_can_be_returned_for_correction(): void
    {
        $inv = $this->draft();
        $this->move($inv, 'submit')->assertSuccessful();

        $this->assertContains(Status::ACTION_RETURN, $this->offered($inv->fresh()));

        $this->move($inv, 'return', ['reason' => 'The printed total does not match the photo.'])->assertSuccessful();
        $this->assertSame(Status::DRAFT, $inv->fresh()->status);
    }

    /** Returning is a refusal, and a refusal with no reason cannot be acted on by whoever receives it. */
    public function test_returning_an_invoice_requires_a_reason(): void
    {
        $inv = $this->draft();
        $this->move($inv, 'submit')->assertSuccessful();

        $this->move($inv, 'return')->assertStatus(422);
        $this->assertSame(Status::PENDING, $inv->fresh()->status);
    }

    // ── 3. Approved — where the paper closes ─────────────────────────────────────────────────────────

    private function approved(): PartInvoice
    {
        $inv = $this->draft();
        $this->move($inv, 'submit')->assertSuccessful();
        $this->move($inv, 'approve')->assertSuccessful();

        return $inv->fresh();
    }

    public function test_approval_stamps_who_and_closes_editing(): void
    {
        $inv = $this->approved();

        $this->assertSame(Status::APPROVED, $inv->status);
        $this->assertSame($this->admin->name, $inv->approved_by_name);
        $this->assertNotNull($inv->approved_at);
        $this->assertFalse($inv->isEditable());
        $this->assertSame([Status::ACTION_PAY, Status::ACTION_UNAPPROVE, Status::ACTION_CANCEL], $this->offered($inv));
    }

    /**
     * THE HOLE THIS CHANGE CLOSED. The buttons were already hidden; the endpoint took the write anyway.
     */
    public function test_an_approved_invoice_cannot_be_edited_through_the_api(): void
    {
        $inv = $this->approved();
        $before = $inv->invoice_no;

        $res = $this->postJson("/api/part-invoices/{$inv->id}", ['invoice_no' => 'REWRITTEN']);

        $res->assertStatus(422);
        $this->assertSame($before, $inv->fresh()->invoice_no, 'An accepted obligation was rewritten in place.');
    }

    /** And the sharper case: deleting it would strand any payment allocated against it. */
    public function test_an_approved_invoice_cannot_be_deleted_through_the_api(): void
    {
        $inv = $this->approved();

        $this->deleteJson("/api/part-invoices/{$inv->id}")->assertStatus(422);
        $this->assertNotNull(PartInvoice::find($inv->id), 'An accepted obligation was deleted.');
    }

    /** An approval given in error is withdrawn, which reopens the paper rather than rewriting it. */
    public function test_withdrawing_an_approval_reopens_the_document(): void
    {
        $inv = $this->approved();

        $this->move($inv, 'unapprove')->assertSuccessful();
        $fresh = $inv->fresh();

        $this->assertSame(Status::PENDING, $fresh->status);
        $this->assertNull($fresh->approved_by_name);
        $this->assertTrue($fresh->isEditable(), 'Reopening a document is what makes correcting it possible again.');
    }

    /** Approving twice is not a legal move, and the refusal names where the document stands. */
    public function test_an_approved_invoice_cannot_be_approved_again(): void
    {
        $inv = $this->approved();

        $res = $this->move($inv, 'approve');
        $res->assertStatus(422);
        $this->assertStringContainsString('Approved', (string) data_get($res->json(), 'message'));
    }

    // ── 4. Paid and cancelled — the ends of the line ─────────────────────────────────────────────────

    public function test_a_paid_invoice_offers_only_cancellation(): void
    {
        $inv = $this->approved();
        $this->move($inv, 'pay')->assertSuccessful();

        $fresh = $inv->fresh();
        $this->assertSame(Status::PAID, $fresh->documentStatus());
        $this->assertSame([Status::ACTION_CANCEL], $this->offered($fresh));
    }

    /** Cancellation is the off-ramp, and it is the one move that always needs a reason. */
    public function test_cancelling_requires_a_reason_and_ends_the_document(): void
    {
        $inv = $this->draft();

        $this->move($inv, 'cancel')->assertStatus(422);

        $this->move($inv, 'cancel', ['reason' => 'Supplier reissued the bill.'])->assertSuccessful();
        $fresh = $inv->fresh();

        $this->assertSame(Status::CANCELLED, $fresh->status);
        $this->assertSame('Supplier reissued the bill.', $fresh->cancellation_reason);
        $this->assertSame([], $this->offered($fresh), 'A withdrawn document is read, not acted on.');
    }

    /** Nothing resurrects a cancelled document. */
    public function test_a_cancelled_invoice_refuses_every_move(): void
    {
        $inv = $this->draft();
        $this->move($inv, 'cancel', ['reason' => 'Keyed twice.'])->assertSuccessful();

        foreach (['submit', 'approve', 'unapprove'] as $verb) {
            $this->move($inv, $verb)->assertStatus(422);
        }
        $this->move($inv, 'pay', ['amount' => 10])->assertStatus(422);
        $this->assertSame(Status::CANCELLED, $inv->fresh()->status);
    }

    // ── 5. Who is looking ────────────────────────────────────────────────────────────────────────────

    /** A user holding exactly the named permissions, and nothing else. */
    private function actingAsUserWith(string ...$permissions): User
    {
        $user = User::create([
            'name'     => 'Scoped ' . uniqid(),
            'email'    => 'scoped.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user->fresh(), ['*']);

        return $user;
    }

    /**
     * Keying paper and committing money are two jobs with two permissions, and the ledger used to gate
     * every button on parts.purchase — so a buyer was shown Approve and Cancel, and got a 403 for
     * clicking them. Now the offer is computed per user and matches what the route will allow.
     */
    public function test_a_buyer_is_offered_only_the_paper_actions_and_refused_the_money_ones(): void
    {
        $inv = $this->draft();
        $this->actingAsUserWith('parts.view', 'parts.purchase');

        $this->assertSame([Status::ACTION_EDIT, Status::ACTION_DELETE], $this->offered($inv));
        $this->move($inv, 'submit')->assertStatus(403);
        $this->assertSame(Status::DRAFT, $inv->fresh()->status);
    }

    /** And the approver, who may move the money but must not re-key the paper. */
    public function test_an_approver_is_offered_the_money_actions_and_refused_the_paper_ones(): void
    {
        $inv = $this->draft();
        $this->actingAsUserWith('parts.view', 'maintenance.manage');

        $this->assertSame([Status::ACTION_SUBMIT, Status::ACTION_CANCEL], $this->offered($inv));
        $this->postJson("/api/part-invoices/{$inv->id}", ['invoice_no' => 'NOPE'])->assertStatus(403);
        $this->move($inv, 'submit')->assertSuccessful();
    }

    /** A reader sees the invoice and its state, and is offered nothing to do to it. */
    public function test_a_reader_is_offered_nothing(): void
    {
        $inv = $this->draft();
        $this->actingAsUserWith('parts.view');

        $this->assertSame([], $this->offered($inv));
        $this->move($inv, 'submit')->assertStatus(403);
        $this->deleteJson("/api/part-invoices/{$inv->id}")->assertStatus(403);
    }
}
