<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Maintenance Swap: a replacement vehicle attached to an original rental whose car went to the
 * workshop. See the migration for the full rationale. 'active' = replacement currently standing in;
 * 'released' = the swap has ended (original car back in service).
 */
class MaintenanceSwap extends Model
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'original_vehicle_id', 'original_plate', 'original_car',
        'original_contract_id', 'original_contract_no', 'tenant_name', 'reason',
        'replacement_vehicle_id', 'replacement_plate', 'replacement_car',
        'status', 'assigned_by', 'released_by', 'released_at', 'notes',
    ];

    protected $casts = [
        'released_at' => 'datetime',
    ];

    /** Only swaps still in effect (a replacement currently attached). */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }

    public function originalVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'original_vehicle_id');
    }

    public function replacementVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'replacement_vehicle_id');
    }
}
