<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A SECTION of the location picker — Exterior, Wheels & Tyres, Glass & Mirrors, Lights, …
 *
 * Groups the places in {@see VehicleLocation} for display only: nothing about a fault depends on
 * which section its place happens to sit in, so a section can be renamed, reordered or retired
 * without touching a single stored fault. `key` is the exception — `vehicle_locations.group_key`
 * points at it, so it is stable forever and a rename means a new row.
 *
 * Seeded from config('vehicle_locations.groups') by VehicleLocationSeeder (idempotent upsert by key).
 */
class VehicleLocationGroup extends Model
{
    protected $fillable = ['key', 'label', 'label_ar', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    /** The places filed under this section. Not a real FK — `group_key` is a stable string key. */
    public function locations(): HasMany
    {
        return $this->hasMany(VehicleLocation::class, 'group_key', 'key');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }

    /** Section name in the caller's language, falling back to English when no Arabic label is set. */
    public function displayLabel(string $locale = 'en'): string
    {
        return $locale === 'ar' && $this->label_ar ? $this->label_ar : $this->label;
    }
}
