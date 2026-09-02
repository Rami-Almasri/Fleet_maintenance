<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of stock in or out of the storehouse — the evidence behind every shelf level.
 *
 * Append-only. A movement is never edited and never deleted; a mistake is corrected by an opposing
 * `adjustment` carrying its own note, so the correction is visible history rather than a hole in it.
 */
class StoreMovement extends Model
{
    public const IN  = 'in';
    public const OUT = 'out';

    /** Stock arriving. */
    public const REASON_OPENING             = 'opening';              // first count of an existing shelf
    public const REASON_RECEIPT             = 'receipt';              // a stock buy landed
    public const REASON_RETURN_FROM_VEHICLE = 'return_from_vehicle';  // issued, not used, brought back

    /** Stock leaving. */
    public const REASON_ISSUE     = 'issue';      // handed to a job — the only path onto a car
    public const REASON_WRITE_OFF = 'write_off';  // damaged / lost / expired

    /** Either direction: a count correction, which moves no money. */
    public const REASON_ADJUSTMENT = 'adjustment';

    public const IN_REASONS  = [self::REASON_OPENING, self::REASON_RECEIPT, self::REASON_RETURN_FROM_VEHICLE, self::REASON_ADJUSTMENT];
    public const OUT_REASONS = [self::REASON_ISSUE, self::REASON_WRITE_OFF, self::REASON_ADJUSTMENT];

    protected $fillable = [
        'store_item_id', 'direction', 'reason', 'quantity', 'qty_after', 'unit_cost', 'currency',
        'vehicle_id', 'maintenance_id', 'part_request_id', 'part_purchase_id',
        'store_stock_request_id', 'supplier_vendor_id', 'part_invoice_id',
        'note', 'price_variance_note', 'actor_id', 'actor_name', 'occurred_at',
    ];

    protected $casts = [
        'quantity'    => 'decimal:2',
        'qty_after'   => 'decimal:2',
        'unit_cost'   => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    /** The signed effect on the shelf — the only place the direction becomes a sign. */
    public function signedQuantity(): float
    {
        return ($this->direction === self::IN ? 1 : -1) * (float) $this->quantity;
    }

    /** What this movement was worth, when it carried a price at all. */
    public function value(): ?float
    {
        return $this->unit_cost === null ? null : round((float) $this->unit_cost * (float) $this->quantity, 2);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class, 'store_item_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function partRequest(): BelongsTo
    {
        return $this->belongsTo(PartRequest::class, 'part_request_id');
    }

    public function partPurchase(): BelongsTo
    {
        return $this->belongsTo(PartPurchase::class, 'part_purchase_id');
    }

    public function stockRequest(): BelongsTo
    {
        return $this->belongsTo(StoreStockRequest::class, 'store_stock_request_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'supplier_vendor_id');
    }

    /**
     * The supplier bill this receipt was booked against — the paper behind the price.
     *
     * Present on every `receipt`; absent by design on an opening count (no document exists) and on a
     * part returned unused (it was already paid for on the receipt that first brought it in).
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PartInvoice::class, 'part_invoice_id');
    }

    /** What this movement cost in total — the line it contributes to its invoice. */
    public function lineTotal(): float
    {
        return round((float) ($this->unit_cost ?? 0) * (float) $this->quantity, 2);
    }

    public function scopeIncoming(Builder $q): Builder
    {
        return $q->where('direction', self::IN);
    }

    public function scopeOutgoing(Builder $q): Builder
    {
        return $q->where('direction', self::OUT);
    }
}
