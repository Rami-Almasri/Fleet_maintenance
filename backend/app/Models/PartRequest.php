<?php

namespace App\Models;

use App\Models\Concerns\HasPartIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
    /** Fills component_catalog_id / part_name_key on every save — see the trait for why the caller
     *  is not trusted with it. */
    use HasPartIdentity;

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

    /**
     * Statuses where the part is no longer owed — fitted (installed/completed) or dropped
     * (rejected/cancelled). Wider than TERMINAL, which excludes `installed` because an installed request
     * is still open bookkeeping-wise even though the car has the part. Used by isOutstanding().
     */
    public const SETTLED = [
        self::STATUS_INSTALLED,
        self::STATUS_COMPLETED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

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
        // WHY this buy exists, when the answer is "a car needed a spare key". @see SpareKeyRequirement
        'spare_key_requirement_id',
        'part_name', 'part_number', 'category_key', 'part_class', 'repair_location',
        // WHICH SIZE is being asked for — "get a 12V 60Ah battery" instead of "get a battery",
        // which is what saves the buyer a guess at the counter. @see \App\Support\PartSpecs
        'specs',
        // WHICH part this is, as opposed to what it was called. See PartIdentityService.
        'component_catalog_id', 'catalog_matched_by', 'part_name_key',
        'quantity', 'reason', 'estimated_price', 'currency', 'notes',
        'requested_by', 'requested_by_name', 'requested_at',
        'reviewed_by', 'reviewed_by_name', 'reviewed_at', 'review_notes',
        'approved_by', 'approved_by_name', 'approved_at',
        'rejected_by', 'rejected_by_name', 'rejected_at', 'rejection_reason',
    ];

    protected $casts = [
        'specs'           => 'array',
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

    /**
     * True once the part is physically on site — a purchase against it was marked delivered, or it was
     * already fitted. Delivery is NOT a request status (the request stays `purchased` after
     * PartWorkflowService::markDelivered); it lives only on part_purchases.delivered_at, so this is the
     * only honest way to ask "has it arrived?".
     *
     * Reads the loaded `purchases` collection — eager-load it on any hot path (board/state loaders).
     */
    public function isOnSite(): bool
    {
        return $this->purchases->contains(
            fn (PartPurchase $purchase) => $purchase->delivered_at !== null || $purchase->installed_at !== null
        );
    }

    /**
     * Is the car still WAITING on this part? The wait is for DELIVERY, not for the fitting: a request
     * stops being outstanding the moment the part lands (delivered) or its status settles
     * (installed/completed = fitted, rejected/cancelled = never coming).
     *
     * This is the single definition of "waiting on parts" — WorkflowStateResolver's repair-blocked state
     * and the workflow board's "Parts Requested" badge both read it, so they can never drift apart.
     */
    public function isOutstanding(): bool
    {
        return ! in_array($this->status, self::SETTLED, true) && ! $this->isOnSite();
    }

    /**
     * The SQL twin of isOutstanding(), for the surfaces that count/filter in the database rather than
     * over a loaded collection (dashboard widgets, KPI counts). Kept beside it deliberately: if one
     * changes the other must, or the widgets start disagreeing with the cards again.
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query
            ->whereNotIn('status', self::SETTLED)
            ->whereDoesntHave('purchases', fn (Builder $p) => $p
                ->whereNotNull('delivered_at')
                ->orWhereNotNull('installed_at'));
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

    /** The part TYPE being requested, when it is known. Null on history written before the picker. */
    public function catalogPart(): BelongsTo
    {
        return $this->belongsTo(ComponentCatalog::class, 'component_catalog_id');
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

    /**
     * The inspector's technical requirement(s) this request was raised from — the traceability link back to
     * the inspection. Many-to-many: a coordinator can merge the same part asked for by two faults into one
     * request, and can split one requirement across several requests. Empty for ad-hoc/customer requests.
     */
    public function requiredParts(): BelongsToMany
    {
        return $this->belongsToMany(MaintenanceRequiredPart::class, 'part_request_required_part', 'part_request_id', 'maintenance_required_part_id')
            ->withTimestamps();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * The NEED this buy was raised to satisfy, when it was a spare key. Null for every other part —
     * a fault-driven request answers "why" through its task, which is a different question with a
     * different answer, so the two links are separate columns rather than one polymorphic one.
     */
    public function spareKeyRequirement(): BelongsTo
    {
        return $this->belongsTo(SpareKeyRequirement::class, 'spare_key_requirement_id');
    }
}
