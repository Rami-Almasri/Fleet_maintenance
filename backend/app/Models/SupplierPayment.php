<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * One payment made to a supplier or a garage — the money actually leaving the account.
 *
 * A payment is an EVENT, not an obligation, which is why its life is simpler than an invoice's: it either
 * happened or it was voided. It carries no approval chain of its own because the obligation it settles was
 * already approved; approving the same money twice would be ceremony rather than control.
 *
 * What it does carry is the thing finance needs to reconcile against the bank: the date, the method, the
 * reference, and a photo of the transfer. And it is split across the bills it settles through
 * {@see allocations()} — one transfer covering three invoices is one record, not three.
 */
class SupplierPayment extends Model
{
    protected $table = 'supplier_payments';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';
    public const METHOD_CASH          = 'cash';
    public const METHOD_CHEQUE        = 'cheque';
    public const METHOD_CARD          = 'card';
    /** The supplier kept a credit note against what we owed instead of moving money. */
    public const METHOD_CREDIT_OFFSET = 'credit_offset';
    /** Only produced by the migration of the old payment columns, where the method was never captured. */
    public const METHOD_UNKNOWN       = 'unknown';

    public const METHODS = [
        self::METHOD_BANK_TRANSFER,
        self::METHOD_CASH,
        self::METHOD_CHEQUE,
        self::METHOD_CARD,
        self::METHOD_CREDIT_OFFSET,
    ];

    public const METHOD_LABELS = [
        self::METHOD_BANK_TRANSFER => 'Bank transfer',
        self::METHOD_CASH          => 'Cash',
        self::METHOD_CHEQUE        => 'Cheque',
        self::METHOD_CARD          => 'Card',
        self::METHOD_CREDIT_OFFSET => 'Settled against a credit note',
        self::METHOD_UNKNOWN       => 'Not recorded',
    ];

    public const STATUS_RECORDED  = 'recorded';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'vendor_id', 'payee_name',
        'payment_date', 'amount', 'currency',
        'method', 'reference', 'notes',
        'photo_disk', 'photo_key',
        'status', 'cancelled_at', 'cancellation_reason',
        'recorded_by', 'recorded_by_name', 'recorded_at',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount'       => 'decimal:2',
        'cancelled_at' => 'datetime',
        'recorded_at'  => 'datetime',
    ];

    public function methodLabel(): string
    {
        return self::METHOD_LABELS[$this->method] ?? 'Not recorded';
    }

    public function payeeLabel(): string
    {
        return $this->vendor?->name ?: ($this->payee_name ?: 'Unnamed payee');
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * How much of this payment has been pointed at a specific bill. A payment on account — money sent
     * ahead of the invoice — is legitimate, so this can be less than the amount.
     */
    public function allocatedTotal(): float
    {
        $rows = $this->relationLoaded('allocations') ? $this->allocations : $this->allocations()->get();

        return round((float) $rows->sum('amount'), 2);
    }

    /** Money paid but not yet pointed at a bill — a credit sitting with the supplier. */
    public function unallocatedTotal(): float
    {
        return round(max(0, (float) $this->amount - $this->allocatedTotal()), 2);
    }

    // ── Relationships ─────────────────────────────────────────────────────────────────────────────

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** A temporary URL to the transfer evidence, or null when none was attached. */
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
