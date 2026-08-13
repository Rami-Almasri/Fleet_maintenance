<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A PLACE on a car — one entry in the shared "where is it?" vocabulary.
 *
 * The third axis of an event, beside WHAT (the kind catalogs) and HOW BAD (severity). It is type-
 * agnostic on purpose: a scratch, a dent, a crack, a leak, a broken light and whatever fault type
 * gets added next all point at these same rows. There is no ScratchLocation and there must never be
 * one — see config/vehicle_locations.php for the reasoning.
 *
 * `inspection_zone` is the join back to the inspection hotspot diagram (VehicleDiagram.js →
 * `inspection_records.body_part`) and `area_key` the join back to `damage_catalog.area_key`, so this
 * unifies the vocabularies that already existed rather than adding a competing one.
 *
 * Retired with is_active=false, never deleted — `maintenance_task_locations` holds restrictOnDelete
 * references, and deleting a place would erase where a historical fault was.
 *
 * Seeded from config/vehicle_locations.php by VehicleLocationSeeder (idempotent upsert by slug).
 */
class VehicleLocation extends Model
{
    /** How SPECIFIC an answer this location is. */
    public const PRECISION_PANEL     = 'panel';     // one body panel (front bumper, rear-left door)
    public const PRECISION_CORNER    = 'corner';    // one of the four wheel corners
    public const PRECISION_ZONE      = 'zone';      // an area (engine bay, underbody, dashboard)
    public const PRECISION_COMPONENT = 'component'; // a named part (mirror, wipers, exhaust)
    public const PRECISION_WHOLE     = 'whole';     // deliberately unspecific ("body", "rims", "cabin")
    public const PRECISIONS = [
        self::PRECISION_PANEL, self::PRECISION_CORNER, self::PRECISION_ZONE,
        self::PRECISION_COMPONENT, self::PRECISION_WHOLE,
    ];

    protected $fillable = [
        'slug', 'name', 'name_ar', 'group_key', 'precision',
        'inspection_zone', 'area_key', 'aliases', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'aliases'    => 'array',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Every event recorded at this place — the "what happens here" query the child table exists for. */
    public function taskLinks(): HasMany
    {
        return $this->hasMany(MaintenanceTaskLocation::class, 'vehicle_location_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeInGroup(Builder $query, string $key): Builder
    {
        return $query->where('group_key', $key);
    }

    /**
     * The picker's search: one box, either language, the name or the word someone uses instead of it.
     *
     * `aliases` is JSON matched with a plain LIKE rather than JSON_CONTAINS — the same deliberate
     * choice ComponentCatalog::scopeSearch documents: JSON_CONTAINS needs an exact element match so
     * it cannot do the substring matching a search box needs, and the JSON functions differ between
     * the local MariaDB and production MySQL 8. LIKE behaves identically on both.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';

        return $query->where(function (Builder $q) use ($needle) {
            $q->where('name', 'like', $needle)
                ->orWhere('name_ar', 'like', $needle)
                ->orWhere('slug', 'like', $needle)
                ->orWhere('aliases', 'like', $needle);
        });
    }

    /** Display name in the caller's language, falling back to English when no Arabic term is set. */
    public function displayName(string $locale = 'en'): string
    {
        return $locale === 'ar' && $this->name_ar ? $this->name_ar : $this->name;
    }
}
