<?php

namespace App\Services\Accident;

use App\Models\AccidentCase;
use App\Models\AccidentFinancialEntry;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VehicleLogEvent;
use App\Services\AccountingService;
use App\Services\InvoiceService;
use App\Services\NotificationScanner;
use App\Services\VehicleLogService;
use App\Support\AccidentResponsibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BILLING A CUSTOMER FOR AN ACCIDENT — the one path from "the case says they owe it" to "it is on
 * their account".
 *
 * Evidence class: J (judgement) acting on F (facts) — the liability verdict and the confirmed
 * amount are read, never re-decided here. Produces: invoices (origin='manual'), vehicle_log_events.
 * Consumes: accident_cases, accident_financial_entries, contracts, customers.
 *
 * ── IT DOES NOT OWN A BALANCE, AND THAT IS THE WHOLE DESIGN ────────────────────────────────────
 *
 * There is no wallet table to debit and no deposit to draw down. A customer's balance is derived by
 * {@see AccountingService} from their contracts plus the website-native ledger, and the "available
 * wallet" is the magnitude of a negative balance. So charging is a single append: raise a manual
 * invoice on the accident's rental contract, and the wallet is consumed by arithmetic.
 *
 * That is why "handle insufficient deposit safely" needs no special case here. Nothing is
 * decremented, so nothing can go negative or half-apply: a charge larger than the wallet simply
 * leaves the balance positive, which is the correct and already-modelled meaning of "the customer
 * owes us the rest". The split is REPORTED (`covered_by_wallet` / `outstanding`) rather than
 * stored, because storing it would be a second copy of a number the ledger already implies.
 *
 * ── FOUR GATES, AND NONE OF THEM IS "ARE YOU SURE?" ────────────────────────────────────────────
 *
 *  1. LIABILITY MUST NAME THE CUSTOMER. `pending` is refused, and so is a verdict that puts the
 *     fault on the other party, the company or an employee — billing a customer we have formally
 *     decided was not at fault is the single worst thing this feature could do.
 *  2. THE AMOUNT MUST BE CONFIRMED, not estimated. Only `approved` and `actual` entries can be
 *     billed; an estimate is a quote, and a quote on somebody's account is a dispute waiting to
 *     happen. @see confirmedCustomerAmount()
 *  3. THE CASE MUST KNOW WHO AND WHERE. The frozen customer and rental contract are what the
 *     invoice hangs on; without them there is no account to charge.
 *  4. ONCE ONLY. Enforced by a unique index on `invoices.accident_case_id`; this service returns
 *     the existing charge rather than racing it.
 *
 * ── THE LEDGER IS WRITTEN THROUGH ITS OWN SERVICE ──────────────────────────────────────────────
 *
 * Every write goes through {@see InvoiceService}, which owns the M- sequence, the VAT arithmetic
 * and the contract denormalisation. This class never touches `Invoice::create` — one writer per
 * entity is the rule the financial layer is built on ([[ticket-cost-journey]]), and an accident is
 * not a reason to open a second door into customer billing.
 */
class AccidentChargeService
{
    /**
     * The verdicts under which a customer can be billed at all.
     *
     * `shared` is here because shared responsibility still means they bear a part — and the PART is
     * the confirmed customer-party amount on the ledger, not a percentage this service re-derives.
     * The percentage on the case is the DECISION; the money is whatever finance wrote down under
     * `party = customer`, and re-computing one from the other would let them disagree.
     */
    public const CHARGEABLE_LIABILITY = [
        AccidentCase::LIABILITY_CUSTOMER,
        AccidentCase::LIABILITY_SHARED,
    ];

    /**
     * Phases certain enough to put on somebody's account, most certain first.
     *
     * `actual` beats `approved`: once the repair is done and costed, that is what it came to. An
     * ESTIMATE is deliberately absent — see gate 2.
     */
    public const BILLABLE_PHASES = [
        AccidentFinancialEntry::PHASE_ACTUAL,
        AccidentFinancialEntry::PHASE_APPROVED,
    ];

    public function __construct(
        private InvoiceService $invoices,
        private AccountingService $accounting,
        private VehicleLogService $log,
        private NotificationScanner $notifier,
    ) {}

    // ══ READ ══════════════════════════════════════════════════════════════════════════════════

    /**
     * WHAT WOULD HAPPEN, AND WHY IT CAN OR CANNOT. The single answer the UI renders — the page never
     * re-derives "is this chargeable?" from raw fields, so the button and the endpoint can never
     * disagree about it.
     *
     * Safe to call on any case at any stage; it writes nothing.
     *
     * @return array<string,mixed>
     */
    public function state(AccidentCase $case): array
    {
        $charge   = $this->existingCharge($case);
        $amount   = $this->confirmedCustomerAmount($case);
        $customer = $case->customer_ref ? Customer::find($case->customer_ref) : null;
        $blockers = $this->blockers($case, $amount, $customer);

        // The account as it stands right now. After a charge this is the position it left behind,
        // which is what somebody chasing the money needs to see.
        $balance = $customer ? (float) $customer->balance : 0.0;
        $wallet  = $customer ? round(max(0, -$balance), 2) : 0.0;

        $charged = $charge ? (float) $charge->total_after_vat : 0.0;

        if ($charge) {
            // WHAT THE CHARGE ACTUALLY DID, read from the audit line written when it happened.
            //
            // It cannot be recomputed from today's wallet, and the arithmetic that looks like it
            // should work is the trap: the wallet CLAMPS AT ZERO, so a customer left owing money
            // reports a wallet of 0 whatever their credit was beforehand, and "wallet_after +
            // charged" silently reconstructs a credit they never had. Reading the stored figure is
            // also simply more correct — it is what was true at the moment the money moved, and no
            // later payment or charge can rewrite it.
            $audited      = $this->chargeAudit($case);
            $walletBefore = $audited['wallet_before']
                // Fallback for a charge that predates the audit line: reconstruct on the BALANCE,
                // which is unclamped and therefore invertible, and only then take the wallet of it.
                ?? round(max(0, -($balance - $charged)), 2);
            $covered      = $audited['covered_by_wallet'] ?? round(min($walletBefore, $charged), 2);
            $outstanding  = $audited['outstanding'] ?? round(max(0, $charged - $covered), 2);
        } else {
            // Nothing charged yet: what the credit WOULD absorb if it were.
            $walletBefore = $wallet;
            $covered      = round(min($wallet, $amount['amount']), 2);
            $outstanding  = round(max(0, $amount['amount'] - $covered), 2);
        }

        return [
            // pending | charged | not_chargeable | nothing_to_charge
            'status'        => $this->status($case, $charge, $amount, $blockers),
            'chargeable'    => $blockers === [] && $charge === null && $amount['amount'] > 0,
            'blockers'      => $blockers,
            'currency'      => $case->currency ?: 'AED',

            // The money the CASE says the customer bears, and how certain it is.
            'amount'        => $amount['amount'],
            'amount_phase'  => $amount['phase'],
            'amount_entry_ids' => $amount['entry_ids'],

            // The money as the LEDGER now sees it.
            'charged_amount'    => $charged,
            'covered_by_wallet' => $covered,
            'outstanding'       => $outstanding,
            'wallet_before'     => $walletBefore,
            'wallet_after'      => $wallet,
            'customer_balance'  => round($balance, 2),

            'customer' => $customer ? [
                'id'      => $customer->id,
                'name'    => $customer->name_en ?: $customer->name_ar,
                'balance' => round((float) $customer->balance, 2),
                'wallet'  => $wallet,
            ] : null,
            'contract' => [
                'id'          => $case->contract_id,
                'contract_no' => $case->contract_no_snapshot,
            ],
            'invoice' => $charge ? [
                'id'          => $charge->id,
                'invoice_ref' => $charge->invoice_ref,
                'amount'      => (float) $charge->total_after_vat,
                'date'        => optional($charge->invoice_date)->toDateString(),
                'notes'       => $charge->notes,
                'created_at'  => optional($charge->created_at)->toIso8601String(),
            ] : null,
        ];
    }

    /** The charge invoice for this case, if one has been raised. At most one — see the migration. */
    public function existingCharge(AccidentCase $case): ?Invoice
    {
        return Invoice::where('accident_case_id', $case->id)->first();
    }

    /**
     * What the newest charge audit line recorded — the authoritative account of what the charge did
     * when it happened, as opposed to what today's balance implies.
     *
     * Returns nulls rather than zeros when no line exists, so the caller can tell "the charge
     * absorbed nothing" apart from "we have no record of what it absorbed" and fall back rather than
     * publishing a confident zero.
     *
     * @return array{wallet_before:?float, covered_by_wallet:?float, outstanding:?float}
     */
    private function chargeAudit(AccidentCase $case): array
    {
        $meta = VehicleLogEvent::where('accident_case_id', $case->id)
            ->where('event_type', VehicleLogEvent::EVENT_ACCIDENT_CUSTOMER_CHARGED)
            ->orderByDesc('id')
            ->value('meta');

        $meta = is_array($meta) ? $meta : (json_decode((string) $meta, true) ?: []);
        $num  = fn (string $k) => array_key_exists($k, $meta) && $meta[$k] !== null ? round((float) $meta[$k], 2) : null;

        return [
            'wallet_before'     => $num('wallet_before'),
            'covered_by_wallet' => $num('covered_by_wallet'),
            'outstanding'       => $num('outstanding'),
        ];
    }

    /**
     * THE CONFIRMED SUM THE CUSTOMER BEARS — live `party = customer` entries at the most certain
     * phase present, never mixing phases.
     *
     * Taking the best phase rather than summing across is the same rule the case's own breakdown
     * follows: an approval and an actual are two statements about the SAME money, and adding them
     * would bill the customer twice for one repair. If `actual` rows exist they are the answer and
     * `approved` is ignored.
     *
     * @return array{amount:float, phase:?string, entry_ids:array<int,int>}
     */
    public function confirmedCustomerAmount(AccidentCase $case): array
    {
        $rows = $case->liveFinancials()
            ->where('party', AccidentFinancialEntry::PARTY_CUSTOMER)
            ->whereIn('phase', self::BILLABLE_PHASES)
            ->get();

        foreach (self::BILLABLE_PHASES as $phase) {
            $atPhase = $rows->where('phase', $phase);
            if ($atPhase->isNotEmpty()) {
                return [
                    'amount'    => round((float) $atPhase->sum('amount'), 2),
                    'phase'     => $phase,
                    'entry_ids' => $atPhase->pluck('id')->map(fn ($i) => (int) $i)->all(),
                ];
            }
        }

        return ['amount' => 0.0, 'phase' => null, 'entry_ids' => []];
    }

    /**
     * Everything standing between this case and a charge, in the words the page shows. Empty = ready.
     *
     * @return array<int,array{key:string, message:string}>
     */
    private function blockers(AccidentCase $case, array $amount, ?Customer $customer): array
    {
        $out = [];

        if (! $case->liabilityDecided()) {
            $out[] = ['key' => 'liability_pending', 'message' => 'Liability has not been decided yet. Nobody can be billed for an accident nobody has ruled on.'];
        } elseif (! in_array($case->liability_status, self::CHARGEABLE_LIABILITY, true)) {
            $out[] = ['key' => 'liability_not_customer', 'message' => 'This accident was ruled ' . str_replace('_', ' ', (string) $case->liability_status) . ' — the customer is not the party that bears it.'];
        }

        if ($amount['amount'] <= 0) {
            $out[] = ['key' => 'amount_unconfirmed', 'message' => 'No confirmed customer amount. Record what the customer bears as an approved or actual figure — an estimate is not billable.'];
        }

        if (! $customer) {
            $out[] = ['key' => 'no_customer', 'message' => 'This accident has no customer on it, so there is no account to charge.'];
        }

        // The invoice hangs off the contract; without one there is nothing to raise it against.
        if (! $case->contract_id) {
            $out[] = ['key' => 'no_contract', 'message' => 'The rental contract behind this accident is no longer available, so a charge cannot be raised against it.'];
        } elseif ($customer) {
            // THE CONTRACT MUST STILL BELONG TO THE PERSON THE CASE FROZE. InvoiceService derives the
            // invoice's customer from the contract, so if the contract were ever re-pointed the
            // charge would silently land on somebody who was never in the car. Rare, and exactly the
            // kind of rare that must refuse rather than guess.
            $holder = Contract::whereKey($case->contract_id)->value('customer_id');
            if ($holder && (int) $holder !== (int) $customer->id) {
                $out[] = [
                    'key'     => 'customer_mismatch',
                    'message' => 'The contract behind this accident now belongs to a different customer than the one recorded at the time. Resolve that before billing anybody.',
                ];
            }
        }

        return $out;
    }

    /** The one word the UI leads with. */
    private function status(AccidentCase $case, ?Invoice $charge, array $amount, array $blockers): string
    {
        if ($charge) {
            return 'charged';
        }
        if ($blockers !== []) {
            // "Nothing to charge" is a legitimate resting state, not a fault: a case where the other
            // party was at fault has no customer charge and never will. Distinguished from a case
            // that is merely unfinished so the UI can stay quiet about the first and prompt on the
            // second.
            $keys = array_column($blockers, 'key');
            if (in_array('liability_not_customer', $keys, true)) {
                return 'nothing_to_charge';
            }
            return 'not_chargeable';
        }

        return $amount['amount'] > 0 ? 'pending' : 'nothing_to_charge';
    }

    // ══ WRITE ═════════════════════════════════════════════════════════════════════════════════

    /**
     * PUT THE ACCIDENT ON THE CUSTOMER'S ACCOUNT.
     *
     * IDEMPOTENT: a case that already carries a charge returns that charge untouched rather than
     * raising a second one or throwing. The caller cannot tell the difference between "I charged it"
     * and "it was already charged", which is the property that makes a retried request safe.
     *
     * VAT DEFAULTS TO ZERO, deliberately. `InvoiceService` applies 5% unless told otherwise, and
     * silently adding it here would bill an amount the accident case never decided — the ledger and
     * the case would then disagree by 5% forever, with neither of them wrong on its own terms. The
     * charge equals the confirmed figure unless somebody explicitly says the recharge is VAT-able.
     *
     * @param array{vat_percentage?:float|string, note?:?string, invoice_date?:?string} $data
     */
    public function charge(AccidentCase $case, User $actor, array $data = []): Invoice
    {
        if ($existing = $this->existingCharge($case)) {
            return $existing;
        }

        $amount   = $this->confirmedCustomerAmount($case);
        $customer = $case->customer_ref ? Customer::find($case->customer_ref) : null;
        $blockers = $this->blockers($case, $amount, $customer);

        if ($blockers !== []) {
            throw ValidationException::withMessages([
                'charge' => array_column($blockers, 'message'),
            ]);
        }

        return DB::transaction(function () use ($case, $actor, $data, $amount, $customer) {
            // The position BEFORE, captured inside the transaction so the audit line states what was
            // actually true at the moment the money moved rather than what a later read reports.
            $walletBefore  = $this->accounting->customerWallet($customer);
            $balanceBefore = $this->accounting->customerOutstandingBalance($customer);

            $invoice = $this->invoices->store([
                'contract_id'    => $case->contract_id,
                'total_value'    => $amount['amount'],
                'discount'       => 0,
                // @see the docblock — zero unless the caller states otherwise.
                'vat_percentage' => $data['vat_percentage'] ?? 0,
                'invoice_date'   => $data['invoice_date'] ?? Carbon::now()->toDateString(),
                'notes'          => $this->invoiceNote($case, $data['note'] ?? null),
            ]);

            // The link, and the idempotency key. Set after creation because InvoiceService owns the
            // create; a unique index on this column is what actually prevents a second charge.
            $invoice->forceFill(['accident_case_id' => $case->id])->save();

            // Re-read the customer THROUGH the accounting service rather than trusting the cached
            // column: the observer has just re-synced it, and a stale in-memory model would report
            // the pre-charge balance in the audit line.
            $balanceAfter = $this->accounting->customerOutstandingBalance($customer);
            $walletAfter  = $this->accounting->customerWallet($customer);
            $covered      = round(min($walletBefore, $amount['amount']), 2);
            $outstanding  = round(max(0, $amount['amount'] - $covered), 2);

            $this->audit($case, VehicleLogEvent::EVENT_ACCIDENT_CUSTOMER_CHARGED, $actor, sprintf(
                '%s %s charged to %s on invoice %s',
                $case->currency ?: 'AED',
                number_format($amount['amount'], 2),
                $customer->name_en ?: $customer->name_ar ?: ('customer #' . $customer->id),
                $invoice->invoice_ref,
            ), [
                // THE TRANSACTION RECORD. Everything needed to reconstruct the charge without
                // re-deriving anything: what was billed, on whose authority, against which decision,
                // and what it did to the account.
                'invoice_id'      => $invoice->id,
                'invoice_ref'     => $invoice->invoice_ref,
                'customer_id'     => $customer->id,
                'customer_name'   => $customer->name_en ?: $customer->name_ar,
                'contract_id'     => $case->contract_id,
                'contract_no'     => $case->contract_no_snapshot,
                'amount'          => $amount['amount'],
                'currency'        => $case->currency ?: 'AED',
                // The SOURCE of the figure — which ledger rows, at which certainty.
                'source_phase'    => $amount['phase'],
                'source_entry_ids' => $amount['entry_ids'],
                'liability'       => $case->liability_status,
                'liability_share_pct' => $case->liability_share_pct,
                'liability_source' => $case->liability_source,
                // What it did to the account, both sides of the move.
                'wallet_before'   => $walletBefore,
                'wallet_after'    => $walletAfter,
                'balance_before'  => $balanceBefore,
                'balance_after'   => $balanceAfter,
                'covered_by_wallet' => $covered,
                'outstanding'     => $outstanding,
                'charged_by'      => $actor->name ?: $actor->email,
            ]);

            $this->tell($case, $customer, $invoice, $covered, $outstanding, $actor);

            return $invoice->fresh();
        });
    }

    /**
     * WITHDRAW THE CHARGE — for a liability verdict that changed, or a figure billed in error.
     *
     * The invoice is removed rather than zeroed. It is a charge INSTRUMENT, not a historical record:
     * leaving a zero-value M- invoice on the customer's account would mean their statement shows a
     * charge that was never owed, which is worse for them than no line at all. What survives is the
     * accident timeline, which is append-only and carries both the charge and this reversal with
     * their amounts, their actors and the balance either side — so the audit is complete without the
     * customer's ledger carrying a ghost.
     *
     * Gated on `accidents.override` at the route: undoing money that has been billed is a step above
     * billing it, the same bar as waiving a police report or reopening a settled case.
     */
    public function reverseCharge(AccidentCase $case, string $reason, User $actor): AccidentCase
    {
        $charge = $this->existingCharge($case);
        if (! $charge) {
            throw ValidationException::withMessages([
                'charge' => 'There is no customer charge on this accident to withdraw.',
            ]);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Say why the charge is being withdrawn. Money taken off a customer’s account without a reason is indistinguishable from a mistake.',
            ]);
        }

        return DB::transaction(function () use ($case, $charge, $reason, $actor) {
            $customer      = $charge->customer_id ? Customer::find($charge->customer_id) : null;
            $balanceBefore = $customer ? $this->accounting->customerOutstandingBalance($customer) : null;
            $amount        = (float) $charge->total_after_vat;
            $ref           = $charge->invoice_ref;

            // Through the service that owns manual invoices — never a raw delete.
            $this->invoices->destroy($charge);

            $balanceAfter = $customer ? $this->accounting->customerOutstandingBalance($customer) : null;

            $this->audit($case, VehicleLogEvent::EVENT_ACCIDENT_CHARGE_REVERSED, $actor, sprintf(
                'Customer charge %s (%s %s) withdrawn by %s',
                $ref,
                $case->currency ?: 'AED',
                number_format($amount, 2),
                $actor->name ?: $actor->email,
            ), [
                'invoice_ref'    => $ref,
                'amount'         => round($amount, 2),
                'currency'       => $case->currency ?: 'AED',
                'customer_id'    => $customer?->id,
                'reason'         => $reason,
                'balance_before' => $balanceBefore === null ? null : round($balanceBefore, 2),
                'balance_after'  => $balanceAfter === null ? null : round($balanceAfter, 2),
                'withdrawn_by'   => $actor->name ?: $actor->email,
            ]);

            return $case->fresh();
        });
    }

    // ══ internals ═════════════════════════════════════════════════════════════════════════════

    /**
     * What the customer reads on their statement. It names the accident, because "M-0042, AED 3,400"
     * with no explanation is the line that generates the phone call.
     */
    private function invoiceNote(AccidentCase $case, ?string $extra): string
    {
        $note = sprintf(
            'Accident %s on %s — customer liability%s.',
            $case->reference,
            optional($case->occurred_at)->format('d M Y') ?: 'an unrecorded date',
            $case->liability_status === AccidentCase::LIABILITY_SHARED && $case->liability_share_pct !== null
                ? ' (shared, ' . $case->liability_share_pct . '%)'
                : '',
        );

        return trim($note . ($extra ? ' ' . trim($extra) : ''));
    }

    /** One timeline row per money movement. @see VehicleLogService::recordAccident() */
    private function audit(AccidentCase $case, string $event, ?User $actor, string $description, array $meta = []): void
    {
        $this->log->recordAccident($case, $event, $actor, [
            'description' => $description,
            'meta'        => array_merge([
                'accident_case_id' => $case->id,
                'reference'        => $case->reference,
                'stage'            => $case->stage,
            ], $meta),
        ]);
    }

    /**
     * Tell finance the money is on the account — and say whether any of it is actually outstanding,
     * because a charge the wallet swallowed whole needs nobody to chase it.
     */
    private function tell(AccidentCase $case, Customer $customer, Invoice $invoice, float $covered, float $outstanding, User $actor): void
    {
        try {
            $currency = $case->currency ?: 'AED';
            $body = $outstanding > 0
                ? sprintf('%s %s billed to %s on %s. %s %s was covered by their available credit; %s %s is outstanding.',
                    $currency, number_format((float) $invoice->total_after_vat, 2),
                    $customer->name_en ?: $customer->name_ar, $invoice->invoice_ref,
                    $currency, number_format($covered, 2), $currency, number_format($outstanding, 2))
                : sprintf('%s %s billed to %s on %s and fully covered by their available credit — nothing to collect.',
                    $currency, number_format((float) $invoice->total_after_vat, 2),
                    $customer->name_en ?: $customer->name_ar, $invoice->invoice_ref);

            $this->notifier->notifyByAnyPermission(AccidentResponsibility::financeAuthority(), [
                'type'     => 'accident_customer_charged',
                'category' => 'accident',
                'severity' => $outstanding > 0 ? 'warning' : 'info',
                'title'    => 'Accident charged to a customer — ' . $case->reference,
                'body'     => $body,
                'url'      => '/accidents/' . $case->id,
                'key'      => 'accident:' . $case->id . ':charged',
                'icon'     => 'alert',
                'meta'     => [
                    'accident_case_id' => $case->id,
                    'invoice_ref'      => $invoice->invoice_ref,
                    'customer_id'      => $customer->id,
                    'outstanding'      => $outstanding,
                ],
            ], $actor->id);
        } catch (\Throwable $e) {
            report($e);   // a bell must never sink a posted charge
        }
    }
}
