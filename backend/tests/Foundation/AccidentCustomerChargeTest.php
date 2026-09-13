<?php

namespace Tests\Foundation;

use App\Models\AccidentCase;
use App\Models\AccidentFinancialEntry;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\VehicleLogEvent;
use App\Services\AccountingService;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * BILLING A CUSTOMER FOR AN ACCIDENT.
 *
 * The tests are written around the ways this could quietly go wrong with real money on a real
 * person's account: billing somebody a verdict cleared, billing an estimate, billing twice, and
 * leaving a balance that says something different from the ledger it was derived from.
 *
 * THE LOAD-BEARING ASSERTION IN MOST OF THESE is that `customers.balance` moves by exactly the
 * charge and by nothing else. There is no wallet table to check — the wallet IS the balance read
 * backwards — so "the wallet was consumed correctly" and "the balance is right" are the same
 * statement, and any drift between them would mean a parallel ledger had crept in.
 */
class AccidentCustomerChargeTest extends FoundationTestCase
{
    /** A car on hire, a real customer, an open type-C contract, and an accident already reported. */
    private function caseOnHire(array $contractOverrides = []): array
    {
        $vehicle = $this->makeVehicle();

        $customer = Customer::create([
            'customer_no' => 'C' . random_int(100000, 999999),
            'name_en'     => 'Charge Test Customer',
            'mobile1'     => '0509876543',
        ]);

        $contract = Contract::create(array_merge([
            'contract_no'   => 'RC' . random_int(100000, 999999),
            'contract_type' => 'C',
            'state'         => 'open',
            'vehicle_id'    => $vehicle->id,
            'customer_id'   => $customer->id,
            'out_date'      => now()->subDays(4)->toDateString(),
        ], $contractOverrides));

        $id = $this->idOf($this->postJson('/api/accidents', [
            'vehicle_id'  => $vehicle->id,
            'occurred_at' => now()->subHours(3)->toIso8601String(),
            'description' => 'Kerbed the offside wheels.',
        ])->assertCreated());

        return [$id, $customer, $contract, $vehicle];
    }

    /** Walk a case to the point where a customer charge is legitimate. */
    private function makeChargeable(int $id, float $amount = 3000, string $phase = AccidentFinancialEntry::PHASE_APPROVED): void
    {
        $this->postJson("/api/accidents/$id/police/bypass", ['reason' => 'Kerb damage — no police report issued.'])->assertOk();
        $this->postJson("/api/accidents/$id/liability", [
            'liability_status' => AccidentCase::LIABILITY_CUSTOMER,
            'liability_source' => 'internal',
        ])->assertOk();
        $this->postJson("/api/accidents/$id/financials", [
            'phase' => $phase, 'party' => AccidentFinancialEntry::PARTY_CUSTOMER, 'amount' => $amount,
        ])->assertCreated();
    }

    /** The customer's live balance, straight from the accounting authority (not the cached column). */
    private function balance(Customer $c): float
    {
        return app(AccountingService::class)->customerOutstandingBalance($c);
    }

    private function wallet(Customer $c): float
    {
        return app(AccountingService::class)->customerWallet($c);
    }

    // ── 1 · the gates ─────────────────────────────────────────────────────────────────────────

    /** Liability undecided: nobody can be billed for an accident nobody has ruled on. */
    public function test_a_case_with_undecided_liability_cannot_be_charged(): void
    {
        [$id] = $this->caseOnHire();

        $state = $this->getJson("/api/accidents/$id/charge")->assertOk()->json('data');
        $this->assertFalse($state['chargeable']);
        $this->assertSame('not_chargeable', $state['status']);
        $this->assertContains('liability_pending', array_column($state['blockers'], 'key'));

        $this->postJson("/api/accidents/$id/charge")->assertStatus(422)->assertJsonValidationErrors('charge');
        $this->assertSame(0, Invoice::where('accident_case_id', $id)->count());
    }

    /**
     * THE MOST IMPORTANT REFUSAL IN THE FEATURE. A verdict that cleared the customer must make the
     * charge impossible, not merely discouraged — billing somebody we formally decided was not at
     * fault is the worst outcome this code can produce.
     */
    public function test_a_customer_cleared_by_the_verdict_can_never_be_charged(): void
    {
        [$id, $customer] = $this->caseOnHire();
        $this->postJson("/api/accidents/$id/police/bypass", ['reason' => 'No report.'])->assertOk();
        $this->postJson("/api/accidents/$id/liability", [
            'liability_status' => AccidentCase::LIABILITY_OTHER_PARTY, 'liability_source' => 'police_report',
        ])->assertOk();
        // Money on the case, sitting against the customer party by mistake — the verdict still wins.
        $this->postJson("/api/accidents/$id/financials", [
            'phase' => AccidentFinancialEntry::PHASE_APPROVED,
            'party' => AccidentFinancialEntry::PARTY_CUSTOMER, 'amount' => 5000,
        ])->assertCreated();

        $before = $this->balance($customer);

        $state = $this->getJson("/api/accidents/$id/charge")->assertOk()->json('data');
        $this->assertSame('nothing_to_charge', $state['status'], 'a cleared customer is a resting state, not an unfinished one');
        $this->assertContains('liability_not_customer', array_column($state['blockers'], 'key'));

        $this->postJson("/api/accidents/$id/charge")->assertStatus(422);
        $this->assertSame($before, $this->balance($customer->fresh()), 'nothing touched the account');
    }

    /** An ESTIMATE is a quote. A quote on somebody's account is a dispute waiting to happen. */
    public function test_an_estimate_is_not_billable(): void
    {
        [$id] = $this->caseOnHire();
        $this->postJson("/api/accidents/$id/police/bypass", ['reason' => 'No report.'])->assertOk();
        $this->postJson("/api/accidents/$id/liability", [
            'liability_status' => AccidentCase::LIABILITY_CUSTOMER, 'liability_source' => 'internal',
        ])->assertOk();
        $this->postJson("/api/accidents/$id/financials", [
            'phase' => AccidentFinancialEntry::PHASE_ESTIMATE,
            'party' => AccidentFinancialEntry::PARTY_CUSTOMER, 'amount' => 4000,
        ])->assertCreated();

        $state = $this->getJson("/api/accidents/$id/charge")->assertOk()->json('data');
        $this->assertFalse($state['chargeable']);
        $this->assertContains('amount_unconfirmed', array_column($state['blockers'], 'key'));
        $this->assertEquals(0, $state['amount'], 'an estimate contributes nothing to the billable figure');

        $this->postJson("/api/accidents/$id/charge")->assertStatus(422);
    }

    /** Approving it afterwards makes it billable, and the figure is the approved one. */
    public function test_approving_the_amount_makes_it_billable(): void
    {
        [$id] = $this->caseOnHire();
        $this->makeChargeable($id, 3000);

        $state = $this->getJson("/api/accidents/$id/charge")->assertOk()->json('data');
        $this->assertTrue($state['chargeable']);
        $this->assertSame('pending', $state['status']);
        $this->assertEquals(3000, $state['amount']);
        $this->assertSame(AccidentFinancialEntry::PHASE_APPROVED, $state['amount_phase']);
        $this->assertSame([], $state['blockers']);
    }

    /**
     * An approval and an actual are two statements about the SAME money. Billing their sum would
     * charge the customer twice for one repair, so the most certain phase wins outright.
     */
    public function test_an_actual_supersedes_an_approval_rather_than_adding_to_it(): void
    {
        [$id] = $this->caseOnHire();
        $this->makeChargeable($id, 3000, AccidentFinancialEntry::PHASE_APPROVED);
        $this->postJson("/api/accidents/$id/financials", [
            'phase' => AccidentFinancialEntry::PHASE_ACTUAL,
            'party' => AccidentFinancialEntry::PARTY_CUSTOMER, 'amount' => 2750,
        ])->assertCreated();

        $state = $this->getJson("/api/accidents/$id/charge")->assertOk()->json('data');
        $this->assertEquals(2750, $state['amount'], 'the actual, not 5,750');
        $this->assertSame(AccidentFinancialEntry::PHASE_ACTUAL, $state['amount_phase']);
    }

    // ── 2 · the charge itself, and the ledger it lands in ─────────────────────────────────────

    /**
     * THE CORE PATH. A charge is a manual M- invoice on the accident's rental contract, and the
     * customer's balance moves by exactly that and nothing else.
     */
    public function test_charging_raises_a_manual_invoice_and_moves_the_balance_by_exactly_that(): void
    {
        [$id, $customer, $contract] = $this->caseOnHire();
        $this->makeChargeable($id, 3000);

        $before = $this->balance($customer);

        $res = $this->postJson("/api/accidents/$id/charge")->assertCreated();

        $invoice = Invoice::where('accident_case_id', $id)->firstOrFail();
        $this->assertSame('manual', $invoice->origin, 'the website-native ledger, not an OM-synced row');
        $this->assertNull($invoice->invoice_no, 'OM owns the integer sequence — never borrow one');
        $this->assertMatchesRegularExpression('/^M-\d{4}$/', $invoice->invoice_ref);
        $this->assertSame($contract->id, $invoice->contract_id);
        $this->assertSame($customer->id, $invoice->customer_id);
        $this->assertEquals(3000, (float) $invoice->total_after_vat);
        // VAT is not silently added — the charge must equal what the case decided.
        $this->assertEquals(0, (float) $invoice->vat_value);
        $this->assertStringContainsString('Accident ACC-', $invoice->notes);

        $this->assertEquals(round($before + 3000, 2), $this->balance($customer->fresh()),
            'the balance moved by the charge and by nothing else');
        $this->assertSame('charged', $res->json('data.charge.status'));
    }

    /**
     * NO PARALLEL LEDGER. The charge must be visible through the SAME aggregate the Customers page
     * reads, not through a private accident total — so the recalculation that rebuilds every
     * customer from scratch has to arrive at the same number.
     */
    public function test_the_charge_survives_a_full_ledger_rebuild(): void
    {
        [$id, $customer] = $this->caseOnHire();
        $this->makeChargeable($id, 1500);
        $this->postJson("/api/accidents/$id/charge")->assertCreated();

        $afterCharge = $this->balance($customer->fresh());

        // The bulk reconciler — one SQL statement that rebuilds every cached balance from the raw
        // ledgers. If the accident charge lived anywhere else, this would silently erase it.
        app(AccountingService::class)->recalcAllCustomers();

        $this->assertEquals($afterCharge, (float) $customer->fresh()->balance,
            'the cached balance and the rebuilt one agree — one ledger, not two');
    }

    // ── 3 · the wallet ────────────────────────────────────────────────────────────────────────

    /**
     * A customer in credit has the charge absorbed by that credit. There is nothing to decrement:
     * the wallet is the balance read backwards, so the absorption is arithmetic and cannot half-apply.
     */
    public function test_available_credit_absorbs_the_charge_and_is_reported_as_covered(): void
    {
        [$id, $customer, $contract] = $this->caseOnHire();

        // Put the customer in credit: a recorded payment with nothing owed against it.
        Payment::create([
            'payment_ref' => 'P-TEST' . random_int(1000, 9999),
            'contract_id' => $contract->id, 'customer_id' => $customer->id,
            'amount' => 5000, 'paid_on' => now()->toDateString(), 'origin' => 'manual',
        ]);
        $this->assertEquals(5000, $this->wallet($customer->fresh()), 'pre-paid credit is the wallet');

        $this->makeChargeable($id, 3000);
        $state = $this->getJson("/api/accidents/$id/charge")->assertOk()->json('data');
        $this->assertEquals(3000, $state['covered_by_wallet'], 'the credit would absorb all of it');
        $this->assertEquals(0, $state['outstanding']);

        $this->postJson("/api/accidents/$id/charge")->assertCreated();

        $after = $this->getJson("/api/accidents/$id/charge")->assertOk()->json('data');
        $this->assertEquals(3000, $after['covered_by_wallet']);
        $this->assertEquals(0, $after['outstanding'], 'nothing left to collect');
        $this->assertEquals(2000, $this->wallet($customer->fresh()), '5,000 credit − 3,000 charge');
        $this->assertEquals(-2000, $this->balance($customer->fresh()), 'still in credit, never negative-wallet');
    }

    /**
     * INSUFFICIENT CREDIT IS NOT AN ERROR — it is the ordinary case, and the remainder is simply
     * owed. What must NOT happen is a partial application, a negative wallet, or a refusal that
     * leaves the accident unbilled.
     */
    public function test_a_charge_larger_than_the_credit_leaves_the_remainder_outstanding(): void
    {
        [$id, $customer, $contract] = $this->caseOnHire();

        Payment::create([
            'payment_ref' => 'P-TEST' . random_int(1000, 9999),
            'contract_id' => $contract->id, 'customer_id' => $customer->id,
            'amount' => 800, 'paid_on' => now()->toDateString(), 'origin' => 'manual',
        ]);
        $this->assertEquals(800, $this->wallet($customer->fresh()));

        $this->makeChargeable($id, 3000);
        $this->postJson("/api/accidents/$id/charge")->assertCreated();

        $state = $this->getJson("/api/accidents/$id/charge")->assertOk()->json('data');
        $this->assertEquals(800, $state['covered_by_wallet']);
        $this->assertEquals(2200, $state['outstanding'], '3,000 billed − 800 credit');
        $this->assertEquals(0, $this->wallet($customer->fresh()), 'the wallet is spent, never negative');
        $this->assertEquals(2200, $this->balance($customer->fresh()), 'and the rest is owed');
    }

    /** A customer with no credit at all owes the whole charge. No special case, no error. */
    public function test_a_customer_with_no_credit_simply_owes_the_whole_charge(): void
    {
        [$id, $customer] = $this->caseOnHire();
        $before = $this->balance($customer);
        $this->makeChargeable($id, 3000);
        $this->postJson("/api/accidents/$id/charge")->assertCreated();

        $state = $this->getJson("/api/accidents/$id/charge")->assertOk()->json('data');
        $this->assertEquals(0, $state['covered_by_wallet']);
        $this->assertEquals(3000, $state['outstanding']);
        $this->assertEquals(round($before + 3000, 2), $this->balance($customer->fresh()));
        $this->assertEquals(0, $this->wallet($customer->fresh()), 'wallet floors at zero, never goes below');
    }

    // ── 4 · idempotency ───────────────────────────────────────────────────────────────────────

    /**
     * THE SAME ACCIDENT CANNOT BE CHARGED TWICE. The second call returns the first charge rather
     * than raising another or erroring — the property that makes a retried or double-clicked
     * request safe.
     */
    public function test_charging_twice_returns_the_same_invoice_and_bills_once(): void
    {
        [$id, $customer] = $this->caseOnHire();
        $this->makeChargeable($id, 3000);

        $first = $this->postJson("/api/accidents/$id/charge")->assertCreated()->json('data.invoice');
        $balanceAfterFirst = $this->balance($customer->fresh());

        $second = $this->postJson("/api/accidents/$id/charge")->assertCreated()->json('data.invoice');

        $this->assertSame($first['id'], $second['id'], 'the same invoice came back');
        $this->assertSame($first['invoice_ref'], $second['invoice_ref']);
        $this->assertSame(1, Invoice::where('accident_case_id', $id)->count(), 'exactly one charge exists');
        $this->assertEquals($balanceAfterFirst, $this->balance($customer->fresh()), 'the account moved once');
    }

    /** The database is the real guard, not the service's check. A second row is rejected outright. */
    public function test_the_database_refuses_a_second_charge_row_for_one_accident(): void
    {
        [$id] = $this->caseOnHire();
        $this->makeChargeable($id, 3000);
        $this->postJson("/api/accidents/$id/charge")->assertCreated();

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Bypassing the service entirely — the unique index must still hold.
        Invoice::create([
            'invoice_ref' => 'M-DUPE' . random_int(100, 999), 'origin' => 'manual',
            'customer_id' => null, 'contract_id' => null, 'total_value' => 1, 'total_after_vat' => 1,
        ])->forceFill(['accident_case_id' => $id])->save();
    }

    // ── 5 · the audit trail ───────────────────────────────────────────────────────────────────

    /**
     * The charge is the one accident action that lands on a real person's account, so the log line
     * has to carry the whole transaction — what, to whom, on whose authority, from which ledger
     * rows, and what it did to the balance on both sides.
     */
    public function test_the_charge_writes_a_full_transaction_record(): void
    {
        [$id, $customer, $contract] = $this->caseOnHire();
        $this->makeChargeable($id, 3000);
        $this->postJson("/api/accidents/$id/charge")->assertCreated();

        $event = VehicleLogEvent::where('accident_case_id', $id)
            ->where('event_type', VehicleLogEvent::EVENT_ACCIDENT_CUSTOMER_CHARGED)
            ->firstOrFail();
        $meta = $event->meta;

        $this->assertSame($customer->id, $meta['customer_id']);
        $this->assertSame('Charge Test Customer', $meta['customer_name']);
        $this->assertSame($contract->id, $meta['contract_id']);
        $this->assertSame($contract->contract_no, $meta['contract_no']);
        $this->assertEquals(3000, $meta['amount']);
        $this->assertSame('AED', $meta['currency']);
        // The SOURCE of the figure, not just the figure.
        $this->assertSame(AccidentFinancialEntry::PHASE_APPROVED, $meta['source_phase']);
        $this->assertNotEmpty($meta['source_entry_ids']);
        $this->assertSame(AccidentCase::LIABILITY_CUSTOMER, $meta['liability']);
        $this->assertSame('internal', $meta['liability_source']);
        // The resulting balance, both sides of the move.
        $this->assertArrayHasKey('balance_before', $meta);
        $this->assertEquals(round($meta['balance_before'] + 3000, 2), round($meta['balance_after'], 2));
        $this->assertArrayHasKey('wallet_before', $meta);
        $this->assertArrayHasKey('wallet_after', $meta);
        $this->assertNotEmpty($meta['invoice_ref']);
        $this->assertNotEmpty($meta['charged_by']);
    }

    /** And it reaches the car's own history, linked, like every other accident event. */
    public function test_the_charge_appears_on_the_case_timeline_with_a_link(): void
    {
        [$id] = $this->caseOnHire();
        $this->makeChargeable($id, 3000);
        $this->postJson("/api/accidents/$id/charge")->assertCreated();

        $events = $this->getJson("/api/accidents/$id/timeline")->assertOk()->json('data.events');
        $charged = collect($events)->firstWhere('event_type', VehicleLogEvent::EVENT_ACCIDENT_CUSTOMER_CHARGED);

        $this->assertNotNull($charged);
        $this->assertStringContainsString('charged to Charge Test Customer', $charged['description']);
    }

    // ── 6 · withdrawing it ────────────────────────────────────────────────────────────────────

    /** Taking the charge back restores the balance exactly, and says why in the log. */
    public function test_withdrawing_the_charge_restores_the_balance_and_records_the_reason(): void
    {
        [$id, $customer] = $this->caseOnHire();
        $this->makeChargeable($id, 3000);

        $before = $this->balance($customer);
        $this->postJson("/api/accidents/$id/charge")->assertCreated();
        $this->assertEquals(round($before + 3000, 2), $this->balance($customer->fresh()));

        $this->postJson("/api/accidents/$id/charge/reverse", ['reason' => ''])->assertStatus(422);

        $res = $this->postJson("/api/accidents/$id/charge/reverse", [
            'reason' => 'Liability was revised to the other party after the police report arrived.',
        ])->assertOk();

        $this->assertEquals($before, $this->balance($customer->fresh()), 'the account is exactly as it was');
        $this->assertSame(0, Invoice::where('accident_case_id', $id)->count());
        $this->assertSame('pending', $res->json('data.status'), 'billable again, not stuck');

        $event = VehicleLogEvent::where('accident_case_id', $id)
            ->where('event_type', VehicleLogEvent::EVENT_ACCIDENT_CHARGE_REVERSED)->firstOrFail();
        $this->assertStringContainsString('Liability was revised', $event->meta['reason']);
        $this->assertEquals(3000, $event->meta['amount']);
        $this->assertArrayHasKey('balance_before', $event->meta);
        $this->assertArrayHasKey('balance_after', $event->meta);

        // The history survives the invoice — both halves are still readable.
        $this->assertSame(1, VehicleLogEvent::where('accident_case_id', $id)
            ->where('event_type', VehicleLogEvent::EVENT_ACCIDENT_CUSTOMER_CHARGED)->count());
    }

    /** Withdrawing when nothing was charged is refused rather than silently succeeding. */
    public function test_withdrawing_a_charge_that_does_not_exist_is_refused(): void
    {
        [$id] = $this->caseOnHire();
        $this->makeChargeable($id, 3000);

        $this->postJson("/api/accidents/$id/charge/reverse", ['reason' => 'Nothing to withdraw here.'])
            ->assertStatus(422)->assertJsonValidationErrors('charge');
    }

    // ── 7 · permissions ───────────────────────────────────────────────────────────────────────

    /**
     * Three different levels of trust: seeing the position, putting money on an account, and taking
     * it back off. The operations desk works accidents but must not be able to bill or unbill.
     */
    public function test_the_charge_permission_split_holds(): void
    {
        [$id] = $this->caseOnHire();
        $this->makeChargeable($id, 3000);

        $desk = User::create([
            'name' => 'Ops Desk', 'email' => 'ops.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'), 'status' => 'active',
        ]);
        $desk->assignRole('operations');
        Sanctum::actingAs($desk, ['*']);

        // May SEE whether the renter has been billed…
        $this->getJson("/api/accidents/$id/charge")->assertOk();
        // …but not bill them.
        $this->postJson("/api/accidents/$id/charge")->assertForbidden();
        $this->postJson("/api/accidents/$id/charge/reverse", ['reason' => 'because I said so'])->assertForbidden();

        // Finance may bill, but withdrawing is a step above — that is the override bar.
        $finance = User::create([
            'name' => 'Finance', 'email' => 'fin.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'), 'status' => 'active',
        ]);
        $finance->assignRole('finance');
        Sanctum::actingAs($finance, ['*']);

        $this->postJson("/api/accidents/$id/charge")->assertCreated();
        $this->postJson("/api/accidents/$id/charge/reverse", ['reason' => 'Finance should not hold this.'])
            ->assertForbidden();
    }

    // ── 8 · it does not disturb what already worked ───────────────────────────────────────────

    /**
     * The charge must not touch the rental contract, the repair ticket, or the accident's own
     * financial ledger. It is a billing act, and everything else about the case stays as it was.
     */
    public function test_charging_leaves_the_contract_and_the_case_ledger_untouched(): void
    {
        [$id, , $contract] = $this->caseOnHire();
        $this->makeChargeable($id, 3000);

        $entriesBefore = $this->getJson("/api/accidents/$id")->assertOk()->json('data.financials');

        $this->postJson("/api/accidents/$id/charge")->assertCreated();

        $contract->refresh();
        $this->assertSame('open', $contract->state, 'billing an accident does not end the hire');
        $this->assertNull($contract->in_date);

        $entriesAfter = $this->getJson("/api/accidents/$id")->assertOk()->json('data.financials');
        $this->assertSame($entriesBefore, $entriesAfter,
            'the case ledger records what is OWED; the invoice records that it was BILLED — two facts, one copy each');
    }

    /** An ordinary rental invoice is unaffected by the new column and its unique index. */
    public function test_ordinary_invoices_are_unaffected(): void
    {
        [, , $contract] = $this->caseOnHire();

        // Several ordinary manual invoices on the same contract — all carry a NULL accident_case_id,
        // which the unique index must permit.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/Invoice', [
                'contract_id' => $contract->id, 'total_value' => 100 + $i,
                'invoice_date' => now()->toDateString(),
            ])->assertSuccessful();
        }

        $this->assertSame(3, Invoice::where('contract_id', $contract->id)
            ->whereNull('accident_case_id')->where('origin', 'manual')->count());
    }
}
