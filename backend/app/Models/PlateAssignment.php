<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the plate-history timeline: which vehicle held a plate over which date window.
 *
 * Plates get reused after a sale (e.g. plate 19397 moved off a sold Camaro onto a new car),
 * so a single plate_key spans several vehicle rows. This table makes that history DISCOVERABLE
 * without ever moving records: each row just links a vehicle_id to a plate_key for a period.
 * Maintenance / inspection / cost data always stays on its original vehicle_id.
 *
 * Written by the `plate:build-history` backfill and (later) by OM sync when a plate moves.
 * Read by the Plate History UI (VehicleController@plateHistory / PlateHistoryService).
 */
class PlateAssignment extends Model
{
    protected $fillable = [
        'vehicle_id',
        'plate_key',
        'plate_raw',
        'from_date',
        'to_date',
        'is_current',
        'source',
        'confidence',
        'note',
    ];

    protected $casts = [
        'from_date'  => 'date',
        'to_date'    => 'date',
        'is_current' => 'boolean',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
