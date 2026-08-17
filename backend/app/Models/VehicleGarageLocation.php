<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the maintenance sheet's N-Location tab: a car and the garage it is at.
 *
 * A MIRROR of the tab as of `imported_at`, replaced wholesale on every import — see the migration.
 * Never treat it as history: yesterday's rows are gone, because the tab itself no longer has them.
 */
class VehicleGarageLocation extends Model
{
    /** @use HasFactory<\Database\Factories\VehicleGarageLocationFactory> */
    use HasFactory;

    protected $fillable = [
        'vehicle_id',
        'car_label',
        'plate_text',
        'garage_name',
        'vendor_id',
        'sheet_row',
        'imported_at',
    ];

    protected $casts = [
        'imported_at' => 'datetime',
        'sheet_row'   => 'integer',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** The garage as a vendor, when its name matched one we already know. */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
