<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A FAULT type — one entry in the failure/defect menu (leaks, noises, overheating, electrical faults).
 *
 * Every row is implicitly kind=fault: a maintenance_task referencing a FaultCatalog row is a real
 * failure that DOES count in Top Faults, recurrence, health/reliability/risk and fault KPIs. Evolves
 * the non-routine categories of config/maintenance_findings.php. `default_severity` is a prefill hint
 * only (the actual grade stays on the task/ticket). Seeded from config/fault_catalog.php by
 * FaultCatalogSeeder (idempotent upsert by slug). See docs/Service-vs-Fault-Domain-Separation.md.
 */
class FaultCatalog extends Model
{
    /** Explicit: Laravel would guess 'fault_catalogs'. */
    protected $table = 'fault_catalog';

    /** Every row of this catalog classifies its task as a Fault. */
    public const KIND = MaintenanceTask::KIND_FAULT;

    protected $fillable = [
        'slug', 'name', 'name_ar', 'category_key',
        // Does this fault type have a WHERE, and is it required? required | optional | none.
        // Seeded from config('vehicle_locations.policy'); resolved by FaultLocationService.
        'location_mode',
        'default_severity', 'on_site', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'on_site'    => 'boolean',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(MaintenanceTask::class, 'fault_catalog_id');
    }

    public function causes(): HasMany
    {
        return $this->hasMany(FaultCause::class, 'fault_catalog_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfCategory(Builder $query, string $key): Builder
    {
        return $query->where('category_key', $key);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
