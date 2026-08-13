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

    /**
     * Where the row came from — and, crucially, whether the reading has ALREADY been applied:
     *
     *  • MANUAL_EDIT     — someone typed a new odometer on the Vehicles form. The reading is HELD; the car
     *                      still shows its old value until an admin approves. Review decides what happens.
     *  • WORKFLOW_STAGE  — a maintenance-ticket stage capture that ran more than TOLERANCE_KM above the
     *                      previous at-our-park reading. The reading was ACCEPTED at capture (blocking it
     *                      just taught drivers to re-type the old number) and is already on the ticket +
     *                      the car. Review here is an after-the-fact audit: approve = the movement was
     *                      real, reject = it was a mis-read, put the car back on its previous reading.
     */
    public const SOURCE_MANUAL_EDIT    = 'manual_edit';
    public const SOURCE_WORKFLOW_STAGE = 'workflow_stage';

    protected $fillable = [
        'vehicle_id',
        'source',
        'maintenance_id',
        'stage_key',
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

    /** The maintenance ticket a WORKFLOW_STAGE deviation was captured on (null for a manual edit). */
    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    /**
     * Was the reading already written to the car when this row was raised? True for a stage capture (the
     * workflow accepted it and healed the odometer forward); false for a manual edit (held pending).
     * The review actions branch on this — approving an already-applied reading must not re-write it.
     */
    public function isAlreadyApplied(): bool
    {
        return $this->source === self::SOURCE_WORKFLOW_STAGE;
    }

    /**
     * Did the captured reading actually reach the car?
     *
     * A stage capture only ever heals the odometer FORWARD, so a BACKWARD reading — which "Needs Test
     * Drive" now accepts instead of refusing (see OdometerContinuityService::REVIEW_NOT_BLOCK_STAGES) —
     * is filed for review while the car still sits at its old, higher number. For those rows approving
     * has to do something: the inspector's whole claim is that our stored mileage is too high. This
     * distinguishes "recorded and already on the car" (confirm only) from "recorded, car untouched"
     * (approving writes it).
     *
     * Deliberately compared against `previous_odometer`, not merely "!= requested": if a later stage has
     * since moved the car on, the dispute is stale and the newer reading must not be overwritten — the
     * same guard reject() applies when winding back.
     */
    public function awaitsApplyToVehicle(): bool
    {
        if (! $this->isAlreadyApplied()) {
            return true; // a manual edit is held pending by definition
        }

        $current = $this->vehicle?->odometer;

        return $current !== null
            && $this->previous_odometer !== null
            && (int) $current === (int) $this->previous_odometer
            && (int) $current !== (int) $this->requested_odometer;
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
