<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A SERVICE type — one entry in the planned/preventive-work menu (oil, filters, tyres, fluids).
 *
 * Every row is implicitly kind=service: a maintenance_task referencing a ServiceCatalog row is a
 * scheduled job, never a fault, so it is excluded from all fault analytics but still counted in cost /
 * history / profitability. `slug` is the stable machine key. Seeded from config/service_catalog.php by
 * ServiceCatalogSeeder (idempotent upsert by slug). See docs/Service-vs-Fault-Domain-Separation.md.
 */
class ServiceCatalog extends Model
{
    /** Explicit: Laravel would guess 'service_catalogs'. */
    protected $table = 'service_catalog';

    /** Every row of this catalog classifies its task as a Service. */
    public const KIND = MaintenanceTask::KIND_SERVICE;

    protected $fillable = [
        'slug', 'name', 'name_ar', 'category_key',
        'interval_km', 'interval_months',
        'service_reminder_type', 'component_slug',
        'default_labor_hours', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'interval_km'         => 'integer',
        'interval_months'     => 'integer',
        'default_labor_hours' => 'decimal:2',
        'is_active'           => 'boolean',
        'sort_order'          => 'integer',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(MaintenanceTask::class, 'service_catalog_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /** Does this service carry a preventive cadence (km or time)? */
    public function isScheduled(): bool
    {
        return $this->interval_km !== null || $this->interval_months !== null;
    }
}
