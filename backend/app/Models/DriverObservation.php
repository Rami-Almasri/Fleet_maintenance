<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Driver Handover Observation — the lightweight internal-note path recorded when a driver receives a car
 * back and notices (or is casually told) something. NOT a customer complaint: no contact, no escalation.
 * It optionally spawns an inspection request (`inspection_request_id`). See [[driver-observation-entity]].
 */
class DriverObservation extends Model
{
    protected $table = 'driver_observations';

    public const STATUS_OPEN                 = 'open';
    public const STATUS_INSPECTION_REQUESTED = 'inspection_requested';
    public const STATUS_DISMISSED            = 'dismissed';
    public const STATUSES = [self::STATUS_OPEN, self::STATUS_INSPECTION_REQUESTED, self::STATUS_DISMISSED];

    protected $fillable = [
        'vehicle_id', 'driver_id', 'contract_id', 'note', 'photo', 'status', 'inspection_request_id',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /** The inspection request (maintenance ticket in pending_review) this observation spawned, if any. */
    public function inspectionRequest(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class, 'inspection_request_id');
    }
}
