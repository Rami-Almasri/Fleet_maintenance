<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An INSPECTION type — one entry in the checks menu (pre-rental, post-repair QC, periodic, dormancy).
 *
 * Every row is implicitly kind=inspection: a maintenance_task referencing an InspectionType is a check,
 * not a fault or service. An inspection that finds a problem spawns a SEPARATE kind=fault task (linked
 * via derived_from_task_id); the inspection row itself never becomes a fault. Seeded from
 * config/inspection_types.php by InspectionTypeSeeder. See docs/Service-vs-Fault-Domain-Separation.md.
 */
class InspectionType extends Model
{
    protected $table = 'inspection_types';

    /** Every row of this catalog classifies its task as an Inspection. */
    public const KIND = MaintenanceTask::KIND_INSPECTION;

    protected $fillable = [
        'slug', 'name', 'name_ar', 'checklist_key',
        'expects_measurements', 'may_spawn_fault', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'expects_measurements' => 'boolean',
        'may_spawn_fault'      => 'boolean',
        'is_active'            => 'boolean',
        'sort_order'           => 'integer',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(MaintenanceTask::class, 'inspection_type_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
