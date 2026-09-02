<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Maintenance;
use App\Models\MaintenanceInvoice;
use App\Models\PartInvoice;
use App\Models\PaymentAllocation;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Support\FinancialDocumentStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moving a financial document through its life: draft → pending → approved → paid, with cancel as the
 * off-ramp. One service for both invoice kinds, because the decisions are the same decisions.
 *
 * Three rules it enforces, none of which a client can bypass:
 *
 *  1. THE MACHINE IS REAL. Every move is checked against {@see Status::TRANSITIONS}. You cannot pay a
 *     draft, approve a cancelled document, or un-cancel anything.
 *  2. APPROVAL IS NOT FREE. Approving records who did it and when. That name is what makes an obligation
 *     answerable later, and it is taken from the authenticated user rather than the request body.
 *  3. PAYMENT IS AN AMOUNT, NOT A FLAG. Paying records how much and against what reference, so a
 *     part-payment is representable and PARTIALLY_PAID derives itself. Over-payment is refused.
 *
 * Every transition writes to the vehicle timeline, so a document's life is readable from the car's history
 * and not only from the document.
 */
class FinancialDocumentService
{
    public function __construct(private VehicleLogService $log) {}

    /** Submit a draft for approval. */
    public function submit(Model $doc, User $actor): Model
    {
        return $this->transition($doc, Status::PENDING, $actor, fn () => []);
    }

    /**
     * Approve it — from here it is a real obligation and can no longer be edited in place.
     *
     * Approval is also when the bill becomes payable, so it is where the agreed payment terms are
     * STAMPED onto it. A snapshot, not a live lookup: renegotiating a supplier's terms next year must
     * never silently rewrite whether last year's bill was paid late.
     */
    public function approve(Model $doc, User $actor): Model
    {
        [$dueDate, $termsDays] = $this->resolveTerms($doc);

        return $this->transition($doc, Status::APPROVED, $actor, fn () => [
            'approved_by'      => $actor->id,
            'approved_by_name' => $actor->name ?: $actor->email,
            'approved_at'      => Carbon::now(),
            'due_date'         => $dueDate,
            'terms_days'       => $termsDays,
        ]);
    }

    /**
     * The payee's agreed terms, applied to this bill's own date.
     *
     * No agreed terms means due on receipt — the honest default, and identical to how payables aged
     * before terms existed. A bill with no date at all gets no due date rather than a guessed one.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function resolveTerms(Model $doc): array
    {
        $basis = $doc->documentDate();
        if (! $basis) {
            return [null, null];
        }

        $terms = $doc->vendor?->payment_terms_days;

        return [
            Carbon::parse($basis)->addDays((int) ($terms ?? 0))->toDateString(),
            $terms !== null ? (int) $terms : null,
        ];
    }

    /**
     * Refuse a submitted document and send it back to its author to be corrected.
     *
     * The move itself is not new — PENDING → DRAFT has always been in {@see Status::TRANSITIONS} — but
     * nothing could perform it, so the only answer a reviewer could give a wrong bill was to cancel it.
     * Cancelling says "this obligation does not exist"; returning says "this one does, and the paper is
     * wrong". Conflating them destroys the bill rather than fixing it.
     *
     * The reason is required and recorded, because the person receiving it back has to know what to fix.
     */
    public function returnToDraft(Model $doc, User $actor, string $reason): Model
    {
        $reason = trim($reason);

        return $this->transition(
            $doc,
            Status::DRAFT,
            $actor,
            fn () => ['approved_by' => null, 'approved_by_name' => null, 'approved_at' => null],
            verb: 'returned for correction — ' . $reason,
        );
    }

    /** Send an approved document back for another look. Clears the approval that is being withdrawn. */
    public function unapprove(Model $doc, User $actor): Model
    {
        return $this->transition($doc, Status::PENDING, $actor, fn () => [
            'approved_by'      => null,
            'approved_by_name' => null,
            'approved_at'      => null,
        ]);
    }

    /**
     * Record a payment. `amount` is what was paid NOW; it accumulates, so paying twice on the same
     * document leaves it PAID only once the total is covered.
     *
     * @param array{amount?:?float, reference?:?string, paid_at?:?string} $data
     */
    public function pay(Model $doc, array $data, User $actor): Model
    {
        $total     = $doc->documentTotal();
        $alreadyPaid = round((float) $doc->paid_amount, 2);
        $refunded  = $doc->documentRefunded();

        // Default to settling whatever is still owed — the common case, and it can't overshoot.
        $owed   = round(max(0, $total - $alreadyPaid - $refunded), 2);
        $amount = array_key_exists('amount', $data) && $data['amount'] !== null
            ? round((float) $data['amount'], 2)
            : $owed;

        if ($amount <= 0) {
            throw new WorkflowTransitionException('A payment needs an amount.', ['field' => 'amount']);
        }
        if ($amount > $owed + 0.01) {
            throw new WorkflowTransitionException(
                'That is more than the ' . number_format($owed, 2) . ' still owed on this document.',
                ['field' => 'amount', 'outstanding' => $owed],
            );
        }

        // A payment is a RECORD, not an increment on a column. Even this convenience path (pay this one
        // bill in full) goes through SupplierPaymentService, so there is exactly one way money is
        // settled and `paid_amount` always derives from real payments — see SupplierPaymentService.
        $payments = app(SupplierPaymentService::class);

        $payments->record([
            'vendor_id'    => $doc instanceof PartInvoice ? $doc->vendor_id : $doc->vendor_id,
            'payee_name'   => $doc instanceof PartInvoice ? $doc->supplier_name : null,
            'payment_date' => $data['paid_at'] ?? Carbon::now()->toDateString(),
            'amount'       => $amount,
            'method'       => $data['method'] ?? SupplierPayment::METHOD_BANK_TRANSFER,
            'reference'    => $this->clean($data['reference'] ?? null),
            'allocations'  => [[
                'document_type' => PaymentAllocation::typeFor($doc),
                'document_id'   => $doc->id,
                'amount'        => $amount,
            ]],
        ], $actor);

        // recalcDocument() inside the payment service already moved paid_amount + status; re-read it.
        $fresh = $doc->fresh();
        $this->logToTimeline($fresh, $actor, 'payment of AED ' . number_format($amount, 2) . ' recorded');

        return $fresh;
    }

    /** Withdraw a document. The record stays; it simply stops being an obligation. */
    public function cancel(Model $doc, User $actor, string $reason): Model
    {
        return $this->transition($doc, Status::CANCELLED, $actor, fn () => [
            'cancelled_at'        => Carbon::now(),
            'cancellation_reason' => trim($reason),
        ], verb: 'cancelled — ' . trim($reason));
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Apply a move after checking it is legal, then log it.
     *
     * `allowSameStatus` exists for a part-payment: money moved, but the stored decision does not change
     * (it stays APPROVED, and the derived status becomes PARTIALLY_PAID on its own).
     */
    private function transition(
        Model $doc,
        string $to,
        User $actor,
        callable $fields,
        bool $allowSameStatus = false,
        ?string $verb = null,
    ): Model {
        $from = $doc->status ?: Status::DRAFT;

        if (! ($allowSameStatus && $to === $from) && ! Status::canMove($from, $to)) {
            throw new WorkflowTransitionException(
                'A ' . Status::label($from) . ' document cannot become ' . Status::label($to) . '.',
                ['from' => $from, 'to' => $to, 'allowed' => Status::TRANSITIONS[$from] ?? []],
            );
        }

        return DB::transaction(function () use ($doc, $to, $actor, $fields, $verb, $from) {
            $doc->forceFill(['status' => $to] + $fields())->save();

            $this->logToTimeline($doc, $actor, $verb ?: (Status::label($to) . ' (was ' . Status::label($from) . ')'));

            return $doc->fresh();
        });
    }

    /**
     * Record the move on the timeline of every vehicle the document touches. A supplier invoice can span
     * several cars, so each affected vehicle gets its own entry — a car's history has to be complete when
     * read on its own.
     */
    private function logToTimeline(Model $doc, User $actor, string $verb): void
    {
        [$label, $ticketIds, $vehicleIds] = $this->reach($doc);

        $opts = [
            'source_tag'  => 'parts',
            'description' => $label . ' · ' . $verb . ' (by ' . $actor->name . ')',
            'meta'        => [
                'document_type' => $doc instanceof PartInvoice ? 'supplier_invoice' : 'garage_invoice',
                'document_id'   => $doc->id,
                'status'        => $doc->status,
                'paid_amount'   => round((float) $doc->paid_amount, 2),
            ],
        ];

        foreach ($ticketIds as $ticketId) {
            if ($ticket = Maintenance::find($ticketId)) {
                $this->log->record($ticket, VehicleLogEvent::EVENT_COST_RECORDED, $actor, $opts);
            }
        }
        if (! $ticketIds) {
            foreach ($vehicleIds as $vehicleId) {
                if ($vehicle = Vehicle::find($vehicleId)) {
                    $this->log->recordVehicle($vehicle, VehicleLogEvent::EVENT_COST_RECORDED, $actor, $opts);
                }
            }
        }
    }

    /** What this document is called, and which tickets / vehicles it reaches. */
    private function reach(Model $doc): array
    {
        if ($doc instanceof PartInvoice) {
            $purchases = $doc->purchases()->get(['maintenance_id', 'vehicle_id']);

            return [
                'Supplier invoice ' . ($doc->invoice_no ?: '#' . $doc->id) . ' · ' . $doc->supplierLabel(),
                $purchases->pluck('maintenance_id')->filter()->unique()->values()->all(),
                $purchases->pluck('vehicle_id')->filter()->unique()->values()->all(),
            ];
        }

        /** @var MaintenanceInvoice $doc */
        $garage = $doc->is_internal ? 'In-House' : ($doc->vendor?->name ?: 'garage');

        return [
            'Garage invoice ' . ($doc->invoice_no ?: '#' . $doc->id) . ' · ' . $garage,
            array_filter([$doc->maintenance_id]),
            [],
        ];
    }

    private function clean($value): ?string
    {
        $v = is_string($value) ? trim($value) : '';

        return $v === '' ? null : $v;
    }
}
