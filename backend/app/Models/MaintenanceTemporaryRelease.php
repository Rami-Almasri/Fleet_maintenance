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

    // ── The release's own mini-lifecycle (the `stage` column) ───────────────────────────────────────
    //
    // A release is a ROUND TRIP, and it walks the same lanes a garage run does — because operationally
    // it IS the same work: a car has to be driven somewhere, by someone, and confirmed to have arrived.
    //
    //   out_dispatch     the controller let the car go — a supervisor still has to say WHERE it goes
    //   out_assigned     destination + (optionally) a driver picked — awaiting the physical pickup
    //   out_transit      the driver has the car and is driving it to the destination
    //   at_destination   parked at the destination; nothing happens until someone asks for it back
    //   return_dispatch  someone asked for it back — a supervisor confirms/changes the garage
    //   return_assigned  garage + (optionally) a driver picked — awaiting the pickup FROM the destination
    //   return_transit   the driver has the car and is driving it back to the garage
    //
    // The trip ends by CLOSING the row (returned_at set, the ticket's pointer cleared) — there is no
    // "done" stage, so `isOpen()` stays the single answer to "is the car out right now?".
    public const STAGE_OUT_DISPATCH    = 'out_dispatch';
    public const STAGE_OUT_ASSIGNED    = 'out_assigned';
    public const STAGE_OUT_TRANSIT     = 'out_transit';
    public const STAGE_AT_DESTINATION  = 'at_destination';
    public const STAGE_RETURN_DISPATCH = 'return_dispatch';
    public const STAGE_RETURN_ASSIGNED = 'return_assigned';
    public const STAGE_RETURN_TRANSIT  = 'return_transit';

    public const STAGES = [
        self::STAGE_OUT_DISPATCH,
        self::STAGE_OUT_ASSIGNED,
        self::STAGE_OUT_TRANSIT,
        self::STAGE_AT_DESTINATION,
        self::STAGE_RETURN_DISPATCH,
        self::STAGE_RETURN_ASSIGNED,
        self::STAGE_RETURN_TRANSIT,
    ];

    /** Operator-facing stage names — the contract with the frontend release banner. */
    public const STAGE_LABELS = [
        self::STAGE_OUT_DISPATCH    => 'Release — needs dispatch',
        self::STAGE_OUT_ASSIGNED    => 'Release — awaiting pickup',
        self::STAGE_OUT_TRANSIT     => 'Release — on the way out',
        self::STAGE_AT_DESTINATION  => 'Released — waiting to come back',
        self::STAGE_RETURN_DISPATCH => 'Coming back — needs dispatch',
        self::STAGE_RETURN_ASSIGNED => 'Coming back — awaiting pickup',
        self::STAGE_RETURN_TRANSIT  => 'Coming back — on the way',
    ];

    /**
     * Which board lane a ticket carrying this release shows in. The ticket's own workflow_status is
     * FROZEN while the car is out (it is still "In Workshop" as far as the repair is concerned), so the
     * board reads the lane from here instead — that is what puts a released car in Needs Dispatch, then
     * Awaiting Pickup, then En Route, then Returned — Resume Due, then round again for the way back.
     * CONTRACT with frontend/src/config/maintenanceLanes.js lane keys.
     */
    public const STAGE_LANES = [
        self::STAGE_OUT_DISPATCH    => 'pending',
        self::STAGE_OUT_ASSIGNED    => 'awaiting_pickup',
        self::STAGE_OUT_TRANSIT     => 'in_transit',
        self::STAGE_AT_DESTINATION  => 'returned_waiting_resume',
        self::STAGE_RETURN_DISPATCH => 'pending',
        self::STAGE_RETURN_ASSIGNED => 'awaiting_pickup',
        self::STAGE_RETURN_TRANSIT  => 'in_transit',
    ];

    protected $fillable = [
        'maintenance_id',
        'vehicle_id',
        'reason',
        'reason_note',
        'stage',
        'destination',
        'taken_by',
        'released_by',
        'released_at',
        'odometer_out',
        'workflow_status_snapshot',
        'vendor_id_snapshot',
        'garage_snapshot',
        'return_vendor_id',
        'return_garage',
        'out_driver_id',
        'return_driver_id',
        'out_assigned_at',
        'out_started_at',
        'arrived_at',
        'return_requested_at',
        'return_assigned_at',
        'return_started_at',
        'returned_at',
        'returned_by',
        'odometer_in',
        'distance_km',
        'return_note',
    ];

    protected $casts = [
        'released_at'         => 'datetime',
        'out_assigned_at'     => 'datetime',
        'out_started_at'      => 'datetime',
        'arrived_at'          => 'datetime',
        'return_requested_at' => 'datetime',
        'return_assigned_at'  => 'datetime',
        'return_started_at'   => 'datetime',
        'returned_at'         => 'datetime',
        'odometer_out'        => 'integer',
        'odometer_in'         => 'integer',
        'distance_km'         => 'integer',
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

    /**
     * Human label for where the release currently stands. A CLOSED row keeps whatever stage it was at
     * when it ended (the trip's last leg, or where it was called off), so the label has to answer from
     * `returned_at` first — a finished trip that still read "on the way back" in the history would be
     * describing a car that came home days ago.
     */
    public function stageLabel(): string
    {
        if (! $this->isOpen()) {
            // A trip that ended with no return reading never happened: it was cancelled on paper.
            return $this->odometer_in === null ? 'Cancelled' : 'Completed';
        }
        return self::STAGE_LABELS[$this->stage] ?? str_replace('_', ' ', (string) $this->stage);
    }

    /** The board lane a ticket carrying this release belongs in — null once the trip is over. */
    public function laneKey(): ?string
    {
        return $this->isOpen() ? (self::STAGE_LANES[$this->stage] ?? null) : null;
    }

    /** The car is physically away from the workshop (in someone's hands or parked at the destination). */
    public function isAway(): bool
    {
        return in_array($this->stage, [
            self::STAGE_OUT_TRANSIT,
            self::STAGE_AT_DESTINATION,
            self::STAGE_RETURN_DISPATCH,
            self::STAGE_RETURN_ASSIGNED,
            self::STAGE_RETURN_TRANSIT,
        ], true);
    }

    /** The garage the return leg is heading to — the chosen one, else the garage the car left. */
    public function returnGarageLabel(): ?string
    {
        return $this->return_garage ?: $this->garage_snapshot;
    }

    /** Vendor id the return leg is heading to — the chosen one, else the garage the car left. */
    public function returnVendorId(): ?int
    {
        return $this->return_vendor_id ? (int) $this->return_vendor_id : ($this->vendor_id_snapshot ? (int) $this->vendor_id_snapshot : null);
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

    /** The driver carrying the out leg (null = the leg was left open to the pool). */
    public function outDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'out_driver_id');
    }

    /** The driver carrying the return leg (null = the leg was left open to the pool). */
    public function returnDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_driver_id');
    }
}
