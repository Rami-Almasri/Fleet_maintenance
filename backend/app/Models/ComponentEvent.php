<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a component's biography — APPEND-ONLY. No service exposes an update or delete on
 * this table; a wrong entry is corrected by a compensating entry, never by editing history.
 *
 * `at` is business time (backfill writes historical dates); created_at is the insert moment.
 * A cross-vehicle transfer is ONE `transferred` event carrying BOTH from_vehicle_id and
 * to_vehicle_id. Every event is mirrored into vehicle_log_events (EVENT_COMPONENT_*) by the
 * writing service so the Vehicle Timeline shows it — this table stays the asset source of truth.
 */
class ComponentEvent extends Model
{
    public const EVENT_PURCHASED         = 'purchased';
    public const EVENT_STORED            = 'stored';
    public const EVENT_INSTALLED         = 'installed';
    public const EVENT_REMOVED           = 'removed';
    public const EVENT_TRANSFERRED       = 'transferred';
    public const EVENT_DISPOSED          = 'disposed';
    public const EVENT_RETURNED_SUPPLIER = 'returned_supplier';
    public const EVENT_WARRANTY_CLAIMED  = 'warranty_claimed';
    public const EVENT_SOLD              = 'sold';
    public const EVENTS = [
        self::EVENT_PURCHASED, self::EVENT_STORED, self::EVENT_INSTALLED,
        self::EVENT_REMOVED, self::EVENT_TRANSFERRED, self::EVENT_DISPOSED,
        self::EVENT_RETURNED_SUPPLIER, self::EVENT_WARRANTY_CLAIMED, self::EVENT_SOLD,
    ];

    protected $fillable = [
        'vehicle_component_id', 'event',
        'from_vehicle_id', 'to_vehicle_id', 'odometer',
        'maintenance_id', 'maintenance_task_id',
        'actor_id', 'actor_name',
        'at', 'note', 'meta',
    ];

    protected $casts = [
        'at'       => 'datetime',
        'odometer' => 'integer',
        'meta'     => 'array',
    ];

    public function component(): BelongsTo
    {
        return $this->belongsTo(VehicleComponent::class, 'vehicle_component_id');
    }

    public function fromVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'from_vehicle_id');
    }

    public function toVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'to_vehicle_id');
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(MaintenanceMedia::class, 'component_event_id');
    }
}
