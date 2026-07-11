<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single REJECTED odometer entry — an attempt that violated the continuity rules hard enough to be
 * blocked by the workflow, captured for audit even though the transition itself was thrown away. See the
 * create_odometer_block_events migration for the why; surfaced on /oversight/mileage next to the recorded
 * odometer_flags so a supervisor sees who tried to force an unauthorised value, and what that value was.
 */
class OdometerBlockEvent extends Model
{
    protected $fillable = [
        'maintenance_id',
        'vehicle_id',
        'stage_key',
        'status',
        'previous',
        'reading',
        'delta',
        'note',
        'actor_id',
    ];

    protected $casts = [
        'previous' => 'integer',
        'reading'  => 'integer',
        'delta'    => 'integer',
    ];

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
