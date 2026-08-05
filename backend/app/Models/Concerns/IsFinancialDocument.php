<?php

namespace App\Models\Concerns;

use App\Support\FinancialDocumentStatus as Status;

/**
 * Shared lifecycle behaviour for a financial document — a supplier invoice or a garage invoice.
 *
 * Both carry the same stored columns (status, approval stamps, paid_amount, cancellation) and answer the
 * same questions, so the logic lives once here rather than being written twice and drifting.
 *
 * The important rule is that the STORED status only ever holds a decision somebody took (draft → pending →
 * approved → paid → cancelled). Anything that is a statement about money — partially paid, partially
 * refunded, refunded — is DERIVED at read time by {@see documentStatus()}. That way the status shown can
 * never contradict the figures it sits next to, which is the same principle the totals follow.
 *
 * The consuming model must provide {@see documentTotal()} and {@see documentRefunded()}.
 */
trait IsFinancialDocument
{
    /** The full value of this document — what would have to be paid to settle it. */
    abstract public function documentTotal(): float;

    /** How much of it has come back as credit (0 when the document has no return path). */
    abstract public function documentRefunded(): float;

    /** The date the bill is reckoned from — the supplier's invoice date, or when we recorded it. */
    abstract public function documentDate(): ?string;

    /**
     * The status to SHOW: the stored decision, refined by what the money actually says.
     *
     * Refunds are checked before payment because a refunded invoice is the more specific fact — an invoice
     * that was paid and then fully credited is REFUNDED, and calling it PAID would hide the reversal.
     */
    public function documentStatus(): string
    {
        $stored = $this->status ?: Status::DRAFT;

        // A cancelled or unapproved document is a decision, not a money state — report it as it stands.
        if (in_array($stored, [Status::CANCELLED, Status::DRAFT, Status::PENDING], true)) {
            return $stored;
        }

        $total    = round($this->documentTotal(), 2);
        $refunded = round($this->documentRefunded(), 2);

        if ($refunded > 0.01 && $total > 0.01) {
            return $refunded + 0.01 >= $total ? Status::REFUNDED : Status::PARTIALLY_REFUNDED;
        }

        $paid = round((float) $this->paid_amount, 2);
        if ($paid > 0.01) {
            return $paid + 0.01 >= $total ? Status::PAID : Status::PARTIALLY_PAID;
        }

        // Marked paid with no amount recorded (a settlement whose figure nobody keyed) — trust the flag.
        return $stored === Status::PAID ? Status::PAID : Status::APPROVED;
    }

    public function documentStatusLabel(): string
    {
        return Status::label($this->documentStatus());
    }

    /**
     * The date this bill falls due, stamped from the payee's agreed terms when it became payable.
     *
     * Falls back to the document's own date when no terms were agreed — "due on receipt" is the honest
     * default, and it reproduces exactly the behaviour that existed before terms were introduced.
     */
    public function dueDate(): ?\Illuminate\Support\Carbon
    {
        if ($this->due_date) {
            return \Illuminate\Support\Carbon::parse($this->due_date);
        }

        $basis = $this->documentDate();

        return $basis ? \Illuminate\Support\Carbon::parse($basis) : null;
    }

    /**
     * Days past due. Negative means it is not due yet, so a caller can say "due in 6 days" from the same
     * number — and null when there is no date to reckon from at all.
     */
    public function daysOverdue(): ?int
    {
        $due = $this->dueDate();
        if (! $due || $this->outstandingAmount() <= 0.01) {
            return null;
        }

        // Signed: floats forward AND backward, unlike diffInDays() which is absolute.
        return (int) $due->startOfDay()->diffInDays(\Illuminate\Support\Carbon::now()->startOfDay(), false);
    }

    /** Owed, and past the date we agreed to pay it. */
    public function isOverdue(): bool
    {
        return ($this->daysOverdue() ?? -1) > 0;
    }

    /** What is still owed on this document — never negative, and zero once it is not an obligation. */
    public function outstandingAmount(): float
    {
        if (! Status::isCommitted($this->documentStatus())) {
            return 0.0;
        }

        return round(max(0, $this->documentTotal() - (float) $this->paid_amount - $this->documentRefunded()), 2);
    }

    /** Has the business committed to this money (approved or beyond)? */
    public function isCommitted(): bool
    {
        return Status::isCommitted($this->documentStatus());
    }

    public function isCancelled(): bool
    {
        return $this->status === Status::CANCELLED;
    }

    /**
     * Can this document still be edited? Once approved it is an accepted obligation, and silently
     * rewriting it would break the audit trail — a correction becomes an adjustment instead.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [Status::DRAFT, Status::PENDING], true);
    }

    /** The lifecycle block every API response shares, so a document reads the same on every screen. */
    public function statusPayload(): array
    {
        $status = $this->documentStatus();

        return [
            'status'        => $status,
            'status_label'  => Status::label($status),
            'status_tone'   => Status::tone($status),
            'stored_status' => $this->status,
            'committed'     => Status::isCommitted($status),
            'editable'      => $this->isEditable(),
            'total'         => $this->documentTotal(),
            'paid_amount'   => round((float) $this->paid_amount, 2),
            'refunded'      => $this->documentRefunded(),
            'outstanding'   => $this->outstandingAmount(),
            'approved_by'   => $this->approved_by_name,
            'approved_at'   => optional($this->approved_at)->toIso8601String(),
            'paid_at'       => optional($this->paid_at)->toIso8601String(),
            'payment_reference'   => $this->payment_reference,
            'cancelled_at'        => optional($this->cancelled_at)->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            // What the UI may offer next — computed here so no screen invents its own rules.
            'allowed_transitions' => Status::TRANSITIONS[$this->status] ?? [],
        ];
    }
}
