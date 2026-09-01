<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One outstanding NEED: this car has to have a spare key, and somebody must see it through.
 *
 * The three-way distinction this model exists to hold — the same shape as the check-requirement
 * contract, applied to procurement:
 *
 *   SpareKeyRequirement — the NEED. Has a status, and stays visible until a key exists.
 *   [[PartRequest]]     — the BUY. Approved, priced, rejected — the existing procurement machine.
 *   [[VehicleComponent]] — the KEY. A physical asset belonging to the car, forever.
 *
 * `status` here is a PROJECTION of the purchase requests and purchases hanging off it, recomputed by
 * {@see \App\Services\SpareKeyProjection}. It is never the source of truth for whether a key was
 * approved or bought — part_requests and part_purchases are — it exists so the operations board can
 * group ten thousand rows without walking three joins per row.
 */
class SpareKeyRequirement extends Model
{
    // ── Lifecycle ──────────────────────────────────────────────────────────────────────────────
    /** Raised. Somebody said the car needs a key; nothing has been asked for yet. */
    public const STATUS_REQUIRED = 'required';
    /** A purchase request exists and is waiting on the approver. */
    public const STATUS_PURCHASE_REQUESTED = 'purchase_requested';
    /** The purchase request was approved — the buy is authorised but has not happened. */
    public const STATUS_APPROVED = 'approved';
    /** A purchase was recorded: the key is on order / paid for, not yet in our hands. */
    public const STATUS_ORDERED = 'ordered';
    /** At least one physical key arrived and became a component — but not the whole quantity. */
    public const STATUS_RECEIVED = 'received';
    /** Every key asked for is now a component on the car. The need is met. */
    public const STATUS_COMPLETED = 'completed';
    /**
     * The purchase request was rejected. DELIBERATELY NOT a closed state: the car still has no spare
     * key, so the requirement stays open, stays on the board, and can be re-requested. A rejection
     * ends a buy, never a need.
     */
    public const STATUS_REJECTED = 'rejected';
    /** Withdrawn by a human — the key turned up, the car left the fleet, it was raised in error. */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Still owed. This is the set the board counts as outstanding and the set the unique open-lock
     * covers — `rejected` is in it on purpose (see STATUS_REJECTED).
     */
    public const OPEN_STATUSES = [
        self::STATUS_REQUIRED,
        self::STATUS_PURCHASE_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_ORDERED,
        self::STATUS_RECEIVED,
        self::STATUS_REJECTED,
    ];

    /** Nothing further is owed. */
    public const CLOSED_STATUSES = [self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    /** Board column order — the sequence a requirement actually travels. */
    public const BOARD_ORDER = [
        self::STATUS_REQUIRED,
        self::STATUS_PURCHASE_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_ORDERED,
        self::STATUS_RECEIVED,
        self::STATUS_REJECTED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    // ── Why a key is needed ────────────────────────────────────────────────────────────────────
    // Codes, not English: the reason is read by the board's filters and shown in two languages, and
    // a free-typed sentence can do neither. The sentence still has somewhere to go — `notes`.
    public const REASON_MISSING     = 'missing';      // the car came to us with only one key
    public const REASON_ADDITIONAL  = 'additional';   // a second key wanted for operational reasons
    public const REASON_LOST        = 'lost';         // a key that existed is gone
    public const REASON_REPLACEMENT = 'replacement';  // a key exists but is broken/worn
    public const REASON_OTHER       = 'other';
    public const REASONS = [
        self::REASON_MISSING, self::REASON_ADDITIONAL, self::REASON_LOST,
        self::REASON_REPLACEMENT, self::REASON_OTHER,
    ];

    // ── Provenance ─────────────────────────────────────────────────────────────────────────────
    public const SOURCE_APP          = 'app';
    public const SOURCE_SHEET_IMPORT = 'sheet_import';

    /** The catalog slug of the part type a spare key IS. The one place this string is written. */
    public const CATALOG_SLUG = 'spare-key';

    /**
     * `status`, `open_vehicle_id` and `received_quantity` are deliberately absent: they are the
     * projection, and SpareKeyProjection sets them by explicit assignment. A mass-assigned status
     * would let a request body claim a requirement was completed with no key behind it.
     */
    protected $fillable = [
        'vehicle_id', 'quantity', 'reason_code', 'notes',
        'source', 'external_ref', 'started_on', 'finished_on',
        'requested_by', 'requested_by_name', 'requested_at',
    ];

    protected $casts = [
        'quantity'          => 'integer',
        'received_quantity' => 'integer',
        'started_on'        => 'date',
        'finished_on'       => 'date',
        'requested_at'      => 'datetime',
        'completed_at'      => 'datetime',
        'cancelled_at'      => 'datetime',
    ];

    // ── Relations ──────────────────────────────────────────────────────────────────────────────

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Every purchase request raised to satisfy this need — usually one, more than one when the first
     * was rejected and somebody tried again. Ordered oldest-first so the history reads forwards.
     */
    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PartRequest::class, 'spare_key_requirement_id')->orderBy('id');
    }

    // ── Derived reads ──────────────────────────────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** How many keys are still owed on this requirement. Never negative. */
    public function outstandingQuantity(): int
    {
        return max(0, (int) $this->quantity - (int) $this->received_quantity);
    }

    /**
     * The purchase request currently carrying this need — the newest one that has not been rejected
     * or cancelled. Null when nothing has been asked for yet, or when the last attempt was refused
     * (which is exactly when the UI must offer "create a purchase request" again).
     */
    public function livePurchaseRequest(): ?PartRequest
    {
        $requests = $this->relationLoaded('purchaseRequests')
            ? $this->purchaseRequests
            : $this->purchaseRequests()->with('purchases')->get();

        return $requests
            ->reject(fn (PartRequest $r) => in_array($r->status, [PartRequest::STATUS_REJECTED, PartRequest::STATUS_CANCELLED], true))
            ->sortByDesc('id')
            ->first();
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────────────────────

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeForVehicle(Builder $q, int $vehicleId): Builder
    {
        return $q->where('vehicle_id', $vehicleId);
    }
}
