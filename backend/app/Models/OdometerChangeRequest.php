<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending / reviewed request to change a vehicle's odometer by a SIGNIFICANT amount.
 *
 * See the migration for the full story. In short: an odometer edit whose gap from the car's current
 * reading exceeds SIGNIFICANT_DELTA_KM (either direction) is held here — with a mandatory reason note —
 * until an admin approves (writes it onto the vehicle) or rejects it (leaves the odometer as-is).
 */
class OdometerChangeRequest extends Model
{
    /**
     * How big an odometer change (km, either direction) is "significant" enough to demand a note and go
     * through approval instead of applying silently. Mirrored in the frontend (VehicleForm) — keep in step.
     */
    public const SIGNIFICANT_DELTA_KM = 10;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'vehicle_id',
        'previous_odometer',
        'requested_odometer',
        'delta',
        'note',
        'workflow_stage',
        'status',
        'requested_by_id',
        'requested_by',
        'reviewed_by_id',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'previous_odometer'  => 'integer',
        'requested_odometer' => 'integer',
        'delta'              => 'integer',
        'reviewed_at'        => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** Is a change of this magnitude big enough to require the note + approval flow? */
    public static function isSignificant(?int $previous, int $requested): bool
    {
        // No prior reading → this is the anchor, nothing to compare against; treat as insignificant.
        if ($previous === null) {
            return false;
        }

        return abs($requested - $previous) > self::SIGNIFICANT_DELTA_KM;
    }
}
