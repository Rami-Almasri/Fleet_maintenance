<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A part request — the INTENT to fit a part to a vehicle, tracked through its lifecycle.
 *
 * Two origins ({@see SOURCE_CUSTOMER} / {@see SOURCE_GARAGE}) and a status machine
 * (Requested → Under Review → Approved → Purchased → Installed → Completed, with Rejected/Cancelled
 * off-ramps). It holds no money — the buy lives on {@see PartPurchase}; installed cost still flows through
 * maintenance_line_items so nothing is double-counted.
 */
class PartRequest extends Model
{
    public const SOURCE_CUSTOMER = 'customer';
    public const SOURCE_GARAGE    = 'garage';
    public const SOURCES = [self::SOURCE_CUSTOMER, self::SOURCE_GARAGE];

    public const STATUS_REQUESTED    = 'requested';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED     = 'approved';
    public const STATUS_PURCHASED    = 'purchased';
    public const STATUS_INSTALLED    = 'installed';
    public const STATUS_COMPLETED    = 'completed';
    public const STATUS_REJECTED     = 'rejected';
    public const STATUS_CANCELLED    = 'cancelled';

    /** The forward lifecycle, in order (off-ramps handled separately). */
    public const LIFECYCLE = [
        self::STATUS_REQUESTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_PURCHASED,
        self::STATUS_INSTALLED,
        self::STATUS_COMPLETED,
    ];

    public const TERMINAL = [self::STATUS_COMPLETED, self::STATUS_REJECTED, self::STATUS_CANCELLED];

    public const LOCATION_GARAGE = 'garage';
    public const LOCATION_ONSITE = 'onsite';
    public const LOCATIONS = [self::LOCATION_GARAGE, self::LOCATION_ONSITE];

    public const CLASS_CONSUMABLE = 'consumable';
    public const CLASS_STANDARD   = 'standard';
    public const CLASS_MAJOR      = 'major';
    public const CLASSES = [self::CLASS_CONSUMABLE, self::CLASS_STANDARD, self::CLASS_MAJOR];

    protected $fillable = [
        'source', 'status',
        'vehicle_id', 'customer_id', 'maintenance_id', 'maintenance_task_id',
        'part_name', 'part_number', 'category_key', 'part_class', 'repair_location',
        'quantity', 'reason', 'estimated_price', 'currency', 'notes',
        'requested_by', 'requested_by_name', 'requested_at',
        'reviewed_by', 'reviewed_by_name', 'reviewed_at', 'review_notes',
        'approved_by', 'approved_by_name', 'approved_at',
        'rejected_by', 'rejected_by_name', 'rejected_at', 'rejection_reason',
    ];

    protected $casts = [
        'quantity'        => 'decimal:2',
        'estimated_price' => 'decimal:2',
        'requested_at'    => 'datetime',
        'reviewed_at'     => 'datetime',
        'approved_at'     => 'datetime',
        'rejected_at'     => 'datetime',
    ];

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    /** Has this request been paid for yet? (Guards the install step.) */
    public function hasPurchase(): bool
    {
        return $this->purchases()->exists();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(PartPurchase::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
