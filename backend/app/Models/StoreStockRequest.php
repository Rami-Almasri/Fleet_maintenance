<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request to put a part ON THE SHELF — procurement with no car attached.
 *
 * See the migration for why this is not a {@see PartRequest}: that entity is the intent to fit a
 * part to a VEHICLE and requires one, and everything hanging off it (the per-car duplicate engine,
 * the install step, the ticket cost roll-up) describes a car. Stocking the storehouse involves no
 * car until the part is issued, which is a later and separate event.
 *
 * Only {@see STATUS_RECEIVED} moves stock. Approving is permission to spend; receiving is the part
 * physically arriving, and that is the moment StoreService writes the `receipt` movement.
 */
class StoreStockRequest extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_ORDERED   = 'ordered';
    public const STATUS_RECEIVED  = 'received';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_APPROVED, self::STATUS_ORDERED,
        self::STATUS_RECEIVED, self::STATUS_REJECTED, self::STATUS_CANCELLED,
    ];

    /** Nothing further happens to these — the shelf has it, or it is never coming. */
    public const TERMINAL = [self::STATUS_RECEIVED, self::STATUS_REJECTED, self::STATUS_CANCELLED];

    /** The statuses from which the part may be booked in. */
    public const RECEIVABLE = [self::STATUS_APPROVED, self::STATUS_ORDERED];

    protected $fillable = [
        'status', 'store_item_id',
        'component_catalog_id', 'part_name', 'part_name_key', 'part_number', 'category_key',
        'quantity', 'estimated_price', 'currency', 'reason', 'notes',
        'supplier_vendor_id', 'supplier_name', 'unit_cost', 'received_quantity',
        'requested_by', 'requested_by_name', 'requested_at',
        'approved_by', 'approved_by_name', 'approved_at',
        'rejected_by', 'rejected_by_name', 'rejected_at', 'rejection_reason',
        'received_by', 'received_by_name', 'received_at',
    ];

    protected $casts = [
        'quantity'          => 'decimal:2',
        'estimated_price'   => 'decimal:2',
        'unit_cost'         => 'decimal:2',
        'received_quantity' => 'decimal:2',
        'requested_at'      => 'datetime',
        'approved_at'       => 'datetime',
        'rejected_at'       => 'datetime',
        'received_at'       => 'datetime',
    ];

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    /** Still owed to the shelf — asked for, agreed or ordered, but not yet on it. */
    public function scopeOutstanding(Builder $q): Builder
    {
        return $q->whereNotIn('status', self::TERMINAL);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class, 'store_item_id');
    }

    public function catalogPart(): BelongsTo
    {
        return $this->belongsTo(ComponentCatalog::class, 'component_catalog_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'supplier_vendor_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StoreMovement::class, 'store_stock_request_id');
    }
}
