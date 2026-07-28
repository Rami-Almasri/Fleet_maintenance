<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable entry in a Logistics Dispatch's audit trail — "George claimed it 14:02", "Picked up
 * 14:25", "Returned / Arrived at base 16:40 (25.21°, 55.27°)". Written by LogisticsDispatchService on
 * every status change; never edited. The GPS columns are populated only on the "returned" event.
 */
class LogisticsTaskEvent extends Model
{
    // Lifecycle verbs (what the trail row records).
    public const EVENT_DISPATCHED     = 'dispatched';     // coordinator raised the request
    public const EVENT_CLAIMED        = 'claimed';        // a driver took ownership
    public const EVENT_PICKED_UP      = 'picked_up';      // vehicle is with the driver
    public const EVENT_DELIVERED       = 'delivered';     // vehicle at the destination
    public const EVENT_RETURNED       = 'returned';       // vehicle back at base (GPS-stamped)
    public const EVENT_CANCELLED      = 'cancelled';      // move called off
    public const EVENT_STATUS_UPDATE  = 'status_update';  // free-text "where is it?" reply
    public const EVENT_REASSIGNED     = 'reassigned';     // handed to a different driver

    protected $fillable = [
        'logistics_task_id', 'vehicle_id',
        'event', 'from_status', 'to_status',
        'actor_id', 'actor_name',
        'lat', 'lng', 'accuracy',
        'note', 'occurred_at',
    ];

    protected $casts = [
        'lat'         => 'float',
        'lng'         => 'float',
        'accuracy'    => 'float',
        'occurred_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(LogisticsTask::class, 'logistics_task_id');
    }

    /** The vehicle this movement is about — used by ActivityFeedService when it unions the timeline. */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
