<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A reasoned, approved correction to a ticket's cost — the fourth source document.
 *
 * Three documents cover money that came with paper: the supplier's invoice, the garage's invoice, and a
 * return's credit note. This covers everything else, and it exists so that "everything else" can never
 * again be an unexplained number: a labour refund the garage agreed on the phone, a goodwill discount, a
 * correction to a mis-keyed figure, a write-off.
 *
 * It is a DOCUMENT, not a free edit. It cannot exist without a reason code, a written explanation and a
 * named approver, and it may carry a photo of whatever backs it. That is the whole point — an adjustment
 * is auditable in a way that silently editing a total never was.
 *
 * {@see applies_to} is what protects the parts/labour split: a part return credits PARTS and is forbidden
 * from touching labour, so a labour refund must be recorded here, deliberately, by someone who signs for it.
 */
class CostAdjustment extends Model
{
    protected $table = 'cost_adjustments';

    /** Moves the parts band. */
    public const APPLIES_PARTS = 'parts';
    /** Moves the labour band — the ONLY way labour cost can come down (a part return never may). */
    public const APPLIES_LABOUR = 'labour';
    /** Neither — a ticket-level correction (VAT keyed wrong, rounding, goodwill on the whole bill). */
    public const APPLIES_OTHER = 'other';
    public const APPLIES_TO = [self::APPLIES_PARTS, self::APPLIES_LABOUR, self::APPLIES_OTHER];

    /** Takes money OFF the ticket. */
    public const DIRECTION_CREDIT = 'credit';
    /** Puts money ON the ticket. */
    public const DIRECTION_DEBIT = 'debit';
    public const DIRECTIONS = [self::DIRECTION_CREDIT, self::DIRECTION_DEBIT];

    public const REASON_LABOUR_REFUND    = 'labour_refund';
    public const REASON_GOODWILL         = 'goodwill_discount';
    public const REASON_OVERCHARGE       = 'overcharge_corrected';
    public const REASON_UNDERCHARGE      = 'undercharge_corrected';
    public const REASON_WARRANTY_REWORK  = 'warranty_rework';
    public const REASON_WRITE_OFF        = 'write_off';
    public const REASON_KEYING_ERROR     = 'keying_error';
    public const REASON_OTHER            = 'other';

    public const REASON_CODES = [
        self::REASON_LABOUR_REFUND,
        self::REASON_GOODWILL,
        self::REASON_OVERCHARGE,
        self::REASON_UNDERCHARGE,
        self::REASON_WARRANTY_REWORK,
        self::REASON_WRITE_OFF,
        self::REASON_KEYING_ERROR,
        self::REASON_OTHER,
    ];

    public const REASON_LABELS = [
        self::REASON_LABOUR_REFUND   => 'Labour refunded by the garage',
        self::REASON_GOODWILL        => 'Goodwill discount',
        self::REASON_OVERCHARGE      => 'Overcharge corrected',
        self::REASON_UNDERCHARGE     => 'Undercharge corrected',
        self::REASON_WARRANTY_REWORK => 'Redone under warranty — not charged again',
        self::REASON_WRITE_OFF       => 'Written off',
        self::REASON_KEYING_ERROR    => 'Keying error corrected',
        self::REASON_OTHER           => 'Other',
    ];

    protected $fillable = [
        'maintenance_id', 'maintenance_task_id', 'vehicle_id',
        'applies_to', 'direction', 'amount', 'currency',
        'reason_code', 'reason_note', 'reference',
        'photo_disk', 'photo_key',
        'vendor_id', 'line_item_id',
        'approved_by', 'approved_by_name', 'approved_at',
        'created_by',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    /** The signed money this adjustment puts on the ticket: a credit is negative, a debit positive. */
    public function signedAmount(): float
    {
        $amount = round(abs((float) $this->amount), 2);

        return $this->direction === self::DIRECTION_CREDIT ? -$amount : $amount;
    }

    public function reasonLabel(): string
    {
        return self::REASON_LABELS[$this->reason_code] ?? 'Other';
    }

    /** Which ledger band this belongs to — parts and labour keep their own totals honest. */
    public function isLabour(): bool
    {
        return $this->applies_to === self::APPLIES_LABOUR;
    }

    // ── Relationships ─────────────────────────────────────────────────────────────────────────────

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    /** The garage this concerns, when it concerns one (a labour refund is owed BY someone). */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** The ledger row this adjustment wrote — the money itself. */
    public function lineItem(): BelongsTo
    {
        return $this->belongsTo(MaintenanceLineItem::class, 'line_item_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** A temporary URL to whatever backs this adjustment, or null when nothing was attached. */
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
