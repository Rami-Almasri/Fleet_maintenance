<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A part sent back to where it came from — recorded as an event, never as an erasure.
 *
 * The purchase stays exactly as it was; this row sits next to it and credits the money back, so the
 * ticket keeps the whole story instead of quietly showing a smaller number:
 *
 *     purchase   +400.00
 *     return     -400.00
 *     net           0.00
 *
 * The credit reaches the ticket through the same path the purchase did — a NEGATIVE maintenance_line_items
 * row against the same fault ({@see credit_line_item_id}) — so every existing cost roll-up (fault → ticket
 * → vehicle TCO → spend reports) picks it up with no special-casing.
 *
 * refund_amount and restocking_fee are separate on purpose: a restocking fee is money that does NOT come
 * back, so it must remain in the ticket cost. Only the refund is credited.
 */
class PartReturn extends Model
{
    protected $table = 'part_returns';

    /** The supplier/garage sent the wrong item, or we ordered the wrong one. */
    public const REASON_WRONG_PART = 'wrong_part';
    /** The item was defective / dead on arrival / failed immediately. */
    public const REASON_FAULTY_PART = 'faulty_part';
    /** The repair no longer needs it — including a fault later judged incorrect. */
    public const REASON_NOT_NEEDED = 'not_needed';
    /** We bought more than the job used. */
    public const REASON_OVER_ORDERED = 'over_ordered';
    /** The part didn't fit this vehicle. */
    public const REASON_WRONG_FITMENT = 'wrong_fitment';
    public const REASON_OTHER = 'other';

    public const REASON_CODES = [
        self::REASON_WRONG_PART,
        self::REASON_FAULTY_PART,
        self::REASON_NOT_NEEDED,
        self::REASON_OVER_ORDERED,
        self::REASON_WRONG_FITMENT,
        self::REASON_OTHER,
    ];

    /** Logged; the part hasn't physically gone back yet. */
    public const STATUS_REQUESTED = 'requested';
    /** Handed back to the supplier; the money hasn't come yet. */
    public const STATUS_SENT = 'sent';
    /** Money received — this is the state that writes the credit line. */
    public const STATUS_REFUNDED = 'refunded';
    /** The supplier refused it; the part and its cost stay with us. */
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_SENT,
        self::STATUS_REFUNDED,
        self::STATUS_REJECTED,
    ];

    /** States where the money is settled one way or the other. */
    public const TERMINAL = [self::STATUS_REFUNDED, self::STATUS_REJECTED];

    protected $fillable = [
        'part_purchase_id',
        'vehicle_id', 'maintenance_id', 'maintenance_task_id',
        'quantity',
        'reason_code', 'reason_note',
        'refund_amount', 'restocking_fee', 'currency',
        'status', 'rejection_reason',
        'credit_line_item_id',
        'returned_by', 'returned_by_name', 'returned_at',
        'settled_by', 'settled_by_name', 'settled_at',
    ];

    protected $casts = [
        'quantity'       => 'decimal:2',
        'refund_amount'  => 'decimal:2',
        'restocking_fee' => 'decimal:2',
        'returned_at'    => 'datetime',
        'settled_at'     => 'datetime',
    ];

    /** Human wording for a reason code — used in timeline entries and the ticket's cost journey. */
    public const REASON_LABELS = [
        self::REASON_WRONG_PART    => 'Wrong part',
        self::REASON_FAULTY_PART   => 'Faulty part',
        self::REASON_NOT_NEEDED    => 'No longer needed',
        self::REASON_OVER_ORDERED  => 'Over-ordered',
        self::REASON_WRONG_FITMENT => "Didn't fit this vehicle",
        self::REASON_OTHER         => 'Other',
    ];

    public function reasonLabel(): string
    {
        return self::REASON_LABELS[$this->reason_code] ?? 'Other';
    }

    /** Refunded — i.e. money actually came back and a credit line exists. */
    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }

    /** Still moving: logged or sent, but the money question isn't answered yet. */
    public function isPending(): bool
    {
        return ! in_array($this->status, self::TERMINAL, true);
    }

    // ── Relationships ─────────────────────────────────────────────────────────────────────────────

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(PartPurchase::class, 'part_purchase_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    /** The negative part line this return wrote onto the ticket (null until refunded). */
    public function creditLine(): BelongsTo
    {
        return $this->belongsTo(MaintenanceLineItem::class, 'credit_line_item_id');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }
}
