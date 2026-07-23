<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A component TYPE — the dictionary entry that says what kind of thing a part is and how it is
 * tracked. Never a physical part (that's {@see VehicleComponent}).
 *
 * `tracking_mode` is the rulebook selector:
 *   serialized  — individual identity: serial_no required, quantity always 1, transferable.
 *   batch       — quantity/position tracked, serial optional (DOT code for tyres when known).
 *   consumable  — NEVER instantiates a VehicleComponent; the work is a ServiceRecord only.
 *
 * Entries are retired with is_active=false, never deleted (instances hold restrictOnDelete FKs).
 * Seeded from config/component_catalog.php by ComponentCatalogSeeder (idempotent upsert by slug).
 */
class ComponentCatalog extends Model
{
    /** Explicit: Laravel would guess 'component_catalogs'. */
    protected $table = 'component_catalog';

    public const TRACKING_SERIALIZED = 'serialized';
    public const TRACKING_BATCH      = 'batch';
    public const TRACKING_CONSUMABLE = 'consumable';
    public const TRACKING_MODES = [
        self::TRACKING_SERIALIZED,
        self::TRACKING_BATCH,
        self::TRACKING_CONSUMABLE,
    ];

    // Position vocabularies an instance's `position` is validated against.
    public const SCHEME_AXLE_CORNER = 'axle_corner';
    public const SCHEME_AXLE        = 'axle';
    public const POSITION_SCHEMES   = [self::SCHEME_AXLE_CORNER, self::SCHEME_AXLE];

    public const POSITIONS_BY_SCHEME = [
        self::SCHEME_AXLE_CORNER => ['front_left', 'front_right', 'rear_left', 'rear_right'],
        self::SCHEME_AXLE        => ['front', 'rear'],
    ];

    protected $fillable = [
        'slug', 'name', 'category_key', 'tracking_mode',
        'default_part_number', 'default_warranty_months',
        'expected_life_km', 'expected_life_months',
        'position_scheme', 'is_active', 'notes',
    ];

    protected $casts = [
        'default_warranty_months' => 'integer',
        'expected_life_km'        => 'integer',
        'expected_life_months'    => 'integer',
        'is_active'               => 'boolean',
    ];

    public function components(): HasMany
    {
        return $this->hasMany(VehicleComponent::class, 'component_catalog_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isConsumable(): bool
    {
        return $this->tracking_mode === self::TRACKING_CONSUMABLE;
    }

    public function isSerialized(): bool
    {
        return $this->tracking_mode === self::TRACKING_SERIALIZED;
    }

    /** Valid `position` values for instances of this type ([] = positionless). */
    public function positionsFor(): array
    {
        return self::POSITIONS_BY_SCHEME[$this->position_scheme] ?? [];
    }
}
