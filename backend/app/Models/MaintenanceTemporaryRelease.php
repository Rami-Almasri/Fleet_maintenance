<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One out→in round trip where the car physically left the workshop MID-REPAIR (road test, customer
 * test/delivery, external inspection, storage, …) while the maintenance ticket stayed open at the same
 * stage. The immutable-ish log of the temporary release: it's created OPEN on the way out (odometer_out)
 * and completed on the way back (returned_at + odometer_in + distance_km). See the migration for how this
 * differs from a Pause & Return to Service. See [[Temporary Vehicle Release]].
 */
class MaintenanceTemporaryRelease extends Model
{
    // Why the car left the shop (the `reason` column).
    public const REASON_ROAD_TEST           = 'road_test';
    public const REASON_CUSTOMER_TEST        = 'customer_test';
    public const REASON_EXTERNAL_INSPECTION  = 'external_inspection';
    public const REASON_OTHER                = 'other';

    public const REASONS = [
        self::REASON_ROAD_TEST,
        self::REASON_CUSTOMER_TEST,
        self::REASON_EXTERNAL_INSPECTION,
        self::REASON_OTHER,
    ];

    /** Human labels — the contract with the frontend reason picker + the audit copy. */
    public const REASON_LABELS = [
        self::REASON_ROAD_TEST          => 'Road Test',
        self::REASON_CUSTOMER_TEST      => 'Customer Test / Delivery',
        self::REASON_EXTERNAL_INSPECTION => 'External Inspection',
        self::REASON_OTHER              => 'Other',
    ];

    protected $fillable = [
        'maintenance_id',
        'vehicle_id',
        'reason',
        'reason_note',
        'taken_by',
        'released_by',
        'released_at',
        'odometer_out',
        'workflow_status_snapshot',
        'returned_at',
        'returned_by',
        'odometer_in',
        'distance_km',
        'return_note',
    ];

    protected $casts = [
        'released_at'  => 'datetime',
        'returned_at'  => 'datetime',
        'odometer_out' => 'integer',
        'odometer_in'  => 'integer',
        'distance_km'  => 'integer',
    ];

    /** Still out — the return leg hasn't been recorded yet. */
    public function isOpen(): bool
    {
        return $this->returned_at === null;
    }

    /** Human label for this release's reason, falling back to the raw key. */
    public function reasonLabel(): string
    {
        return self::REASON_LABELS[$this->reason] ?? $this->reason;
    }

    /** Only the releases still out (car hasn't returned). */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('returned_at');
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }
}
