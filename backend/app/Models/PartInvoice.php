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

    /**
     * The two outcomes of the second-eyes check.
     *
     * DISPUTED exists because a check that can only ever pass is not a check. A bill whose photo
     * does not say what was keyed has to be able to end in disagreement and stay visible, rather
     * than force the checker to either lie or leave it forever unchecked.
     */
    public const MATCH_MATCHES  = 'matches';
    public const MATCH_DISPUTED = 'disputed';
    public const MATCH_RESULTS  = [self::MATCH_MATCHES, self::MATCH_DISPUTED];

    protected $fillable = [
        'vendor_id', 'supplier_name',
        'invoice_no', 'invoice_date', 'currency',
        'subtotal', 'tax_amount', 'discount_amount', 'total_amount',
        'stated_total', 'variance_explanation',
        'photo_disk', 'photo_key',
        'notes',
        'recorded_by', 'recorded_by_name', 'recorded_at',
        // The second pair of eyes — see the matching migration for why this is not a boolean.
        'matched_at', 'matched_by', 'matched_by_name', 'match_result', 'match_note',
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
        'matched_at'   => 'datetime',
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

    /** The parts billed on this document that were bought FOR A CAR — half the invoice's line detail. */
    public function purchases(): HasMany
    {
        return $this->hasMany(PartPurchase::class, 'part_invoice_id');
    }

    /**
     * The parts on this document that went ON THE SHELF — the other half of the line detail.
     *
     * One supplier trip routinely buys some parts for a car in the workshop and some for the
     * storehouse, on ONE invoice. Both are money this supplier billed us, so both count towards the
     * total; what differs is only where the part went. Restricted to receipts because those are the
     * only in-movements that represent a purchase (an opening count and a return carry no new spend).
     */
    public function storeReceipts(): HasMany
    {
        return $this->hasMany(StoreMovement::class, 'part_invoice_id')
            ->where('reason', StoreMovement::REASON_RECEIPT);
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
        $receipts  = $this->relationLoaded('storeReceipts') ? $this->storeReceipts : $this->storeReceipts()->get();

        // Both halves of the bill: parts bought for a car, and parts bought for the shelf. Leaving the
        // shelf lines out would make the invoice's own total disagree with its printed one for every
        // mixed supplier trip, and the variance gate would then demand an explanation for money that
        // was recorded perfectly.
        $subtotal = round(
            (float) $purchases->sum(fn (PartPurchase $p) => (float) $p->purchase_price * (float) ($p->quantity ?: 1))
            + (float) $receipts->sum(fn (StoreMovement $m) => $m->lineTotal()),
            2
        );

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

    /** Keying a supplier's bill is part of buying parts — the same gate its own routes carry. */
    public function paperPermission(): string
    {
        return 'parts.purchase';
    }

    /** The supplier's own invoice date when we have it, else when we recorded the bill. */
    public function documentDate(): ?string
    {
        $date = $this->invoice_date ?: $this->recorded_at ?: $this->created_at;

        return $date ? Carbon::parse($date)->toDateString() : null;
    }

    /** The signed gap between the attached parts (+ tax) and the printed total; null when none was keyed. */
    /** Has a second person looked at the photo against the figures yet? */
    public function isMatched(): bool
    {
        return $this->matched_at !== null;
    }

    /** Bills still waiting for their second pair of eyes, longest-waiting first. */
    public function scopeAwaitingMatch($q)
    {
        return $q->whereNull('matched_at');
    }

    /** Bills a checker looked at and disagreed with — the ones that need somebody to act. */
    public function scopeDisputed($q)
    {
        return $q->where('match_result', self::MATCH_DISPUTED);
    }

    /**
     * Why this person may not be the one to check this bill — or null when they may.
     *
     * The rule lives on the model rather than inline in the controller because it IS the stage: two
     * conditions decide whether a check means anything, and a rule worth enforcing is worth being
     * able to test without standing up an HTTP request and a database.
     *
     *   NO PHOTO      There is nothing to read the figures against. Calling that "checked" would
     *                 mean "read the same numbers back", which is the exact failure this stage was
     *                 built to stop.
     *   SELF-CHECK    The whole value is that a SECOND person looked. Letting the person who keyed
     *                 the figures confirm them records something false — that two people agreed,
     *                 when only one ever did.
     *
     * A bill with no recorder (imported by a job, not keyed by a human) has nobody to be different
     * from, so only the photo rule applies to it.
     */
    public function whyCannotBeCheckedBy(?int $userId): ?string
    {
        if (! $this->photoUrl()) {
            return 'This bill has no photo, so there is nothing to check the figures against. Attach the paper first.';
        }

        if ($this->recorded_by !== null && $userId !== null && (int) $this->recorded_by === $userId) {
            return 'You keyed this bill, so you cannot be the one who checks it. It needs a second pair of eyes.';
        }

        return null;
    }

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
