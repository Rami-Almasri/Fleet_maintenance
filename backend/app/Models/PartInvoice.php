<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A SUPPLIER's bill for parts we bought ourselves — the document behind a {@see PartPurchase}'s price.
 *
 * The ticket's cost has two independent sources, and this model is the first of them:
 *
 *     part_invoice         →  what the SUPPLIER charged for the part      (this model)
 *     maintenance_invoice  →  what the GARAGE charged to fit it (labour)  {@see MaintenanceInvoice}
 *
 * They are separate financial events with separate paper, separate vendors and separate dates, and the
 * ticket shows both without either being able to hide the other.
 *
 * SUPPLIER-ONLY by design. A part the repairing garage supplies is already a line on that garage's
 * maintenance invoice — recording it here too would count it twice on the ticket. PartInvoiceService
 * refuses to attach a garage-sourced purchase, so the rule cannot be broken by a client.
 *
 * One invoice, many purchases: a single supplier trip buys several parts on one document. `subtotal` is
 * therefore DERIVED from the attached purchases and never keyed by hand; `stated_total` is what the paper
 * says, and a gap over a cent needs an explanation.
 */
class PartInvoice extends Model
{
    use \App\Models\Concerns\IsFinancialDocument;

    protected $table = 'part_invoices';

    /** Receipt vs attached parts agree within a cent (mirrors MaintenanceInvoiceService). */
    public const VARIANCE_TOLERANCE = 0.01;

    protected $fillable = [
        'vendor_id', 'supplier_name',
        'invoice_no', 'invoice_date', 'currency',
        'subtotal', 'tax_amount', 'discount_amount', 'total_amount',
        'stated_total', 'variance_explanation',
        'photo_disk', 'photo_key',
        'notes',
        'recorded_by', 'recorded_by_name', 'recorded_at',
        // Lifecycle — see IsFinancialDocument + FinancialDocumentStatus.
        'status', 'due_date', 'terms_days',
        'approved_by', 'approved_by_name', 'approved_at',
        'paid_amount', 'paid_at', 'payment_reference', 'paid_by',
        'cancelled_at', 'cancellation_reason',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'subtotal'     => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'stated_total' => 'decimal:2',
        'recorded_at'  => 'datetime',
        'paid_amount'  => 'decimal:2',
        'due_date'     => 'date',
        'approved_at'  => 'datetime',
        'paid_at'      => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────────────────────────

    /** The supplier that issued this invoice (a Vendor row; null for a one-off named in supplier_name). */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /** The parts billed on this document — the invoice's line detail. */
    public function purchases(): HasMany
    {
        return $this->hasMany(PartPurchase::class, 'part_invoice_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    // ── Derived ───────────────────────────────────────────────────────────────────────────────────

    /** Who billed us, however they were recorded. */
    public function supplierLabel(): string
    {
        return $this->vendor?->name ?: ($this->supplier_name ?: 'Unnamed supplier');
    }

    /**
     * Re-derive the money from the attached purchases: subtotal = Σ(price × qty), total = subtotal + tax.
     * Called after every attach/detach so the header can never drift from its lines.
     */
    public function recalcTotals(): void
    {
        $purchases = $this->relationLoaded('purchases') ? $this->purchases : $this->purchases()->get();

        $subtotal = round((float) $purchases->sum(
            fn (PartPurchase $p) => (float) $p->purchase_price * (float) ($p->quantity ?: 1)
        ), 2);

        // Discount is held POSITIVE here (it is printed as a deduction on the supplier's paper, and the
        // form mirrors the paper) and subtracted once, right here, so no caller can forget the sign.
        $this->subtotal     = $subtotal;
        $this->total_amount = round($subtotal + (float) $this->tax_amount - (float) $this->discount_amount, 2);
        $this->saveQuietly();
    }

    // ── Financial document (IsFinancialDocument) ──────────────────────────────────────────────────

    /** What settling this supplier bill in full would cost. */
    public function documentTotal(): float
    {
        return round((float) $this->total_amount, 2);
    }

    /**
     * How much of this invoice has come back as credit — the refunded value of its parts. This is what
     * turns the document's status into PARTIALLY_REFUNDED / REFUNDED without anyone having to set it.
     */
    public function documentRefunded(): float
    {
        $purchases = $this->relationLoaded('purchases') ? $this->purchases : $this->purchases()->with('returns')->get();

        return round((float) $purchases->sum(fn (PartPurchase $p) => $p->refundedTotal()), 2);
    }

    /** The supplier's own invoice date when we have it, else when we recorded the bill. */
    public function documentDate(): ?string
    {
        $date = $this->invoice_date ?: $this->recorded_at ?: $this->created_at;

        return $date ? Carbon::parse($date)->toDateString() : null;
    }

    /** The signed gap between the attached parts (+ tax) and the printed total; null when none was keyed. */
    public function variance(): ?float
    {
        return $this->stated_total === null
            ? null
            : round((float) $this->total_amount - (float) $this->stated_total, 2);
    }

    /** A temporary URL to the invoice photo, or null when no photo was attached. */
    public function photoUrl(): ?string
    {
        if (! $this->photo_disk || ! $this->photo_key) {
            return null;
        }

        try {
            $disk = Storage::disk($this->photo_disk);

            return $this->photo_disk === 's3'
                ? $disk->temporaryUrl($this->photo_key, now()->addMinutes(30))
                : $disk->url($this->photo_key);
        } catch (\Throwable) {
            return null;
        }
    }
}
