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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paying suppliers and garages — and keeping every invoice's settlement derived from those payments
 * rather than hand-kept beside them.
 *
 * The rules it enforces:
 *
 *  1. A PAYMENT IS A RECORD. One row per payment, with its date, method, reference and evidence. The
 *     second payment on an invoice no longer overwrites the first.
 *  2. NEVER PAY MORE THAN IS OWED. Allocation is checked per bill against what that bill still owes
 *     (total − already allocated − refunded), so a bill cannot be over-settled through two payments made
 *     by two people who could not see each other's work.
 *  3. ONLY APPROVED OBLIGATIONS. A draft or cancelled invoice is not payable; paying one would settle
 *     something nobody accepted.
 *  4. paid_amount IS A SUM. After any change, each touched invoice re-derives its settlement from its
 *     allocations, exactly as cost is re-derived from line items.
 *
 * Voiding a payment reverses its allocations and restores what is owed. The record survives — a bounced
 * transfer is part of the history, not something to erase.
 */
class SupplierPaymentService
{
    public function __construct(private VehicleLogService $log) {}

    /**
     * Record a payment and point it at the bills it settles.
     *
     * @param array{vendor_id?:?int, payee_name?:?string, payment_date?:?string, amount:float,
     *              method:string, reference?:?string, notes?:?string,
     *              allocations?:array<int,array{document_type:string, document_id:int, amount?:float}>} $data
     */
    public function record(array $data, User $actor, ?UploadedFile $photo = null): SupplierPayment
    {
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw new WorkflowTransitionException('A payment needs an amount.', ['field' => 'amount']);
        }

        return DB::transaction(function () use ($data, $actor, $photo, $amount) {
            $payment = SupplierPayment::create([
                'vendor_id'        => $data['vendor_id'] ?? null,
                'payee_name'       => $this->clean($data['payee_name'] ?? null),
                'payment_date'     => $data['payment_date'] ?? Carbon::now()->toDateString(),
                'amount'           => $amount,
                'currency'         => 'AED',
                'method'           => $data['method'],
                'reference'        => $this->clean($data['reference'] ?? null),
                'notes'            => $this->clean($data['notes'] ?? null),
                'status'           => SupplierPayment::STATUS_RECORDED,
                'recorded_by'      => $actor->id,
                'recorded_by_name' => $actor->name ?: $actor->email,
                'recorded_at'      => Carbon::now(),
            ]);

            if ($photo) {
                [$disk, $key] = $this->storePhoto($payment, $photo);
                $payment->forceFill(['photo_disk' => $disk, 'photo_key' => $key])->save();
            }

            $this->syncAllocations($payment, $data['allocations'] ?? [], $actor);

            return $payment->fresh(['allocations', 'vendor']);
        });
    }

    /**
     * Replace a payment's allocations — used when money paid on account is later pointed at the bills it
     * turned out to cover.
     *
     * @param array<int,array{document_type:string, document_id:int, amount?:float}> $allocations
     */
    public function allocate(SupplierPayment $payment, array $allocations, User $actor): SupplierPayment
    {
        if ($payment->isCancelled()) {
            throw new WorkflowTransitionException('A cancelled payment cannot be allocated.', ['field' => 'status']);
        }

        return DB::transaction(function () use ($payment, $allocations, $actor) {
            $this->syncAllocations($payment, $allocations, $actor);

            return $payment->fresh(['allocations', 'vendor']);
        });
    }

    /**
     * Void a payment — the transfer bounced, or it was keyed against the wrong supplier. The record
     * stays and its allocations are released, so every bill it touched goes back to being owed.
     */
    public function cancel(SupplierPayment $payment, User $actor, string $reason): SupplierPayment
    {
        if ($payment->isCancelled()) {
            abort(409, 'This payment is already cancelled.');
        }

        return DB::transaction(function () use ($payment, $actor, $reason) {
            $touched = $payment->allocations()->get();

            $payment->allocations()->delete();
            $payment->forceFill([
                'status'              => SupplierPayment::STATUS_CANCELLED,
                'cancelled_at'        => Carbon::now(),
                'cancellation_reason' => trim($reason),
            ])->save();

            // Every bill it used to settle now owes that money again.
            foreach ($touched as $allocation) {
                if ($doc = $allocation->document()) {
                    $this->recalcDocument($doc);
                }
            }

            $this->logPayment($payment, $actor, 'cancelled — ' . trim($reason));

            return $payment->fresh(['allocations']);
        });
    }

    /**
     * Re-derive a bill's settlement from its allocations, and move its status when that settles it.
     *
     * This is the single writer of `paid_amount` — the column is a roll-up, never an input, which is why
     * two people paying the same invoice at the same time cannot produce a figure that disagrees with the
     * payments underneath it.
     */
    public function recalcDocument(Model $document): void
    {
        $type = PaymentAllocation::typeFor($document);

        // Cancelled payments contribute nothing, which is what makes voiding one restore the debt.
        $paid = round((float) PaymentAllocation::where('document_type', $type)
            ->where('document_id', $document->id)
            ->whereHas('payment', fn ($q) => $q->where('status', SupplierPayment::STATUS_RECORDED))
            ->sum('amount'), 2);

        $latest = PaymentAllocation::where('document_type', $type)
            ->where('document_id', $document->id)
            ->whereHas('payment', fn ($q) => $q->where('status', SupplierPayment::STATUS_RECORDED))
            ->with('payment')
            ->get()
            ->sortByDesc(fn ($a) => $a->payment->payment_date)
            ->first();

        $settled = $paid + $document->documentRefunded() + 0.01 >= $document->documentTotal();

        $document->forceFill([
            'paid_amount'       => $paid,
            'paid_at'           => $latest?->payment?->payment_date,
            'payment_reference' => $latest?->payment?->reference,
            // Only a document that was APPROVED can become PAID; one still in draft keeps its own state.
            'status' => $settled && Status::isCommitted($document->documentStatus())
                ? Status::PAID
                : ($document->status === Status::PAID && ! $settled ? Status::APPROVED : $document->status),
        ])->save();
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Write a payment's allocations after checking every one of them, so a rejected slice leaves the
     * payment exactly as it was.
     *
     * @param array<int,array{document_type:string, document_id:int, amount?:float}> $rows
     */
    private function syncAllocations(SupplierPayment $payment, array $rows, User $actor): void
    {
        $previous = $payment->allocations()->get();
        $planned = [];
        $total = 0.0;

        foreach ($rows as $row) {
            $document = $this->resolveDocument($row['document_type'] ?? '', (int) ($row['document_id'] ?? 0));

            $this->assertPayable($document);

            // Default to clearing the bill — the common case, and it cannot overshoot.
            $owed = $this->outstandingFor($document, $payment->id);
            $amount = array_key_exists('amount', $row) && $row['amount'] !== null
                ? round((float) $row['amount'], 2)
                : $owed;

            if ($amount <= 0) {
                continue;
            }
            if ($amount > $owed + 0.01) {
                throw new WorkflowTransitionException(
                    'That would pay AED ' . number_format($amount, 2) . ' against a bill that only owes AED '
                    . number_format($owed, 2) . '.',
                    ['field' => 'allocations', 'document_id' => $document->id, 'outstanding' => $owed],
                );
            }

            $planned[] = ['document' => $document, 'amount' => $amount, 'type' => $row['document_type']];
            $total += $amount;
        }

        if (round($total, 2) > (float) $payment->amount + 0.01) {
            throw new WorkflowTransitionException(
                'The allocations (AED ' . number_format($total, 2) . ') come to more than the payment itself (AED '
                . number_format((float) $payment->amount, 2) . ').',
                ['field' => 'allocations'],
            );
        }

        $payment->allocations()->delete();
        foreach ($planned as $p) {
            $payment->allocations()->create([
                'document_type' => $p['type'],
                'document_id'   => $p['document']->id,
                'amount'        => $p['amount'],
            ]);
        }

        // Re-derive every bill this payment touches NOW, plus any it used to touch and no longer does.
        $documents = collect($planned)->pluck('document')
            ->concat($previous->map(fn ($a) => $a->document())->filter())
            ->unique(fn ($d) => PaymentAllocation::typeFor($d) . ':' . $d->id);

        foreach ($documents as $document) {
            $this->recalcDocument($document);
        }

        $this->logPayment($payment->fresh('allocations'), $actor, 'recorded');
    }

    /** What this bill still owes, ignoring anything this same payment has already put against it. */
    private function outstandingFor(Model $document, int $ignorePaymentId): float
    {
        $type = PaymentAllocation::typeFor($document);

        $allocated = round((float) PaymentAllocation::where('document_type', $type)
            ->where('document_id', $document->id)
            ->where('supplier_payment_id', '!=', $ignorePaymentId)
            ->whereHas('payment', fn ($q) => $q->where('status', SupplierPayment::STATUS_RECORDED))
            ->sum('amount'), 2);

        return round(max(0, $document->documentTotal() - $allocated - $document->documentRefunded()), 2);
    }

    /** Only an accepted obligation may be settled. */
    private function assertPayable(Model $document): void
    {
        $status = $document->documentStatus();

        if (in_array($status, [Status::DRAFT, Status::PENDING], true)) {
            throw new WorkflowTransitionException(
                'That bill is still ' . strtolower(Status::label($status))
                . ' — approve it before paying it.',
                ['field' => 'allocations', 'document_id' => $document->id],
            );
        }
        if ($status === Status::CANCELLED) {
            throw new WorkflowTransitionException(
                'That bill was cancelled and cannot be paid.',
                ['field' => 'allocations', 'document_id' => $document->id],
            );
        }
    }

    private function resolveDocument(string $type, int $id): Model
    {
        $document = match ($type) {
            PaymentAllocation::DOC_SUPPLIER_INVOICE => PartInvoice::find($id),
            PaymentAllocation::DOC_GARAGE_INVOICE   => MaintenanceInvoice::find($id),
            default => null,
        };

        if (! $document) {
            throw new WorkflowTransitionException(
                'That bill no longer exists.',
                ['field' => 'allocations', 'document_type' => $type, 'document_id' => $id],
            );
        }

        return $document;
    }

    /**
     * Put the payment on the timeline of every vehicle whose repair it settles, so a car's history shows
     * the money leaving as well as arriving.
     */
    private function logPayment(SupplierPayment $payment, User $actor, string $verb): void
    {
        $opts = [
            'source_tag'  => 'parts',
            'description' => 'Payment ' . $verb . ' · ' . $payment->payeeLabel() . ' · AED '
                . number_format((float) $payment->amount, 2) . ' · ' . $payment->methodLabel()
                . ($payment->reference ? ' · ' . $payment->reference : '') . ' (by ' . $actor->name . ')',
            'meta' => [
                'supplier_payment_id' => $payment->id,
                'amount'              => (float) $payment->amount,
                'method'              => $payment->method,
                'reference'           => $payment->reference,
            ],
        ];

        foreach ($this->ticketsTouched($payment) as $ticketId) {
            if ($ticket = Maintenance::find($ticketId)) {
                $this->log->record($ticket, VehicleLogEvent::EVENT_COST_RECORDED, $actor, $opts);
            }
        }
    }

    /** Every ticket reached by this payment, through the bills it settles. */
    private function ticketsTouched(SupplierPayment $payment): array
    {
        $ids = [];

        foreach ($payment->allocations as $allocation) {
            $document = $allocation->document();
            if ($document instanceof MaintenanceInvoice) {
                $ids[] = $document->maintenance_id;
            } elseif ($document instanceof PartInvoice) {
                $ids = array_merge($ids, $document->purchases()->pluck('maintenance_id')->filter()->all());
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function storePhoto(SupplierPayment $payment, UploadedFile $file): array
    {
        $disk = config('filesystems.disks.s3.bucket') ? 's3' : 'public';
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $key  = $file->storeAs("supplier-payments/{$payment->id}", (string) Str::uuid() . '.' . $ext, $disk);

        return $key ? [$disk, $key] : [null, null];
    }

    private function clean($value): ?string
    {
        $v = is_string($value) ? trim($value) : '';

        return $v === '' ? null : $v;
    }
}
