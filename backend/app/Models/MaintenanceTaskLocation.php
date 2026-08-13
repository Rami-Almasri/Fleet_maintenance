<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This fault is HERE" — one link between an event and one place on the car.
 *
 * A fault may have none (we do not know / the type has no place), one, or several. `sort_order` keeps
 * the inspector's own order so the rendered sentence reads back the way he said it: "rims and body",
 * not "body and rims". Duplicates are impossible — a unique index on (task, location) means tapping
 * the same place twice is one place, enforced in the schema rather than trusted to every writer.
 *
 * Written only through {@see \App\Services\FaultLocationService::sync()}, so validation, the
 * type's location policy and the ordering all happen in one place.
 */
class MaintenanceTaskLocation extends Model
{
    protected $table = 'maintenance_task_locations';

    protected $fillable = ['maintenance_task_id', 'vehicle_location_id', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(VehicleLocation::class, 'vehicle_location_id');
    }
}
