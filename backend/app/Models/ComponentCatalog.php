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
        'slug', 'name', 'name_ar', 'aliases', 'identity_aliases', 'category_key', 'action_target', 'tracking_mode',
        'default_part_number', 'default_warranty_months', 'default_warranty_km',
        'expected_life_km', 'expected_life_months',
        'position_scheme', 'is_active', 'notes',
        'edited_in_app', 'edited_at', 'edited_by', 'edited_by_name',
    ];

    protected $casts = [
        'aliases'                 => 'array',
        'identity_aliases'        => 'array',
        'default_warranty_months' => 'integer',
        'default_warranty_km'     => 'integer',
        'expected_life_km'        => 'integer',
        'expected_life_months'    => 'integer',
        'is_active'               => 'boolean',
        'edited_in_app'           => 'boolean',
        'edited_at'               => 'datetime',
    ];

    public function components(): HasMany
    {
        return $this->hasMany(VehicleComponent::class, 'component_catalog_id');
    }

    /**
     * Everything else that points at this part type with a restrictOnDelete foreign key.
     *
     * These exist so the app can REFUSE a delete with a sentence naming what is in the way, instead
     * of letting MySQL reject it with an integrity-constraint error the user cannot act on. Any new
     * table that references component_catalog must be added here AND to
     * PartsCatalogController::referenceCounts(), or deleting a part will start throwing SQL again.
     */
    public function warranties(): HasMany
    {
        return $this->hasMany(Warranty::class, 'component_catalog_id');
    }

    public function requiredParts(): HasMany
    {
        return $this->hasMany(MaintenanceRequiredPart::class, 'component_catalog_id');
    }

    /** The intents to buy this part type — what someone asked for, before any money moved. */
    public function partRequests(): HasMany
    {
        return $this->hasMany(PartRequest::class, 'component_catalog_id');
    }

    /** The money actually spent on this part type. The record the repeat-buy warning reads. */
    public function partPurchases(): HasMany
    {
        return $this->hasMany(PartPurchase::class, 'component_catalog_id');
    }

    /** The billed repair lines that fitted this part type — what it has cost across the fleet. */
    public function lineItems(): HasMany
    {
        return $this->hasMany(MaintenanceLineItem::class, 'component_catalog_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The picker's search: one box, any language, part name OR the words someone uses instead of it.
     *
     * Four columns are searched because a technician looking for the alternator may type "alternator",
     * "دينمو", "dynamo" or "battery not charging" — and all four have to land on the same row, or he
     * gives up and types free text again, which is the problem this catalog exists to end.
     *
     * BOTH synonym lists are searched. `identity_aliases` holds the other NAMES of the part and is
     * also trusted to prove two records are the same part; `aliases` holds symptom wording and the
     * names too ambiguous to decide between two rows, and is search-only. The distinction matters
     * everywhere else in the system and matters not at all here: a person is about to read the
     * results and choose, so the box should find the row by any word that could lead to it.
     *
     * They are JSON columns matched with a plain LIKE against their raw text rather than with
     * JSON_CONTAINS. Deliberate, for two reasons: JSON_CONTAINS needs an exact element match, so it
     * cannot do the substring matching a search box needs ("not cooling" would miss "ac not cooling");
     * and the JSON functions differ between the local MariaDB and the production MySQL 8, which is a
     * trap this codebase has been caught by before. LIKE behaves identically on both.
     *
     * The cost of LIKE-on-JSON is that a search could in principle match the JSON syntax itself — but
     * the needle is a word a human typed, and no human searches for '","'. Worst case it surfaces one
     * extra row in a dropdown.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        // Escape the LIKE wildcards so a part number containing '%' or '_' searches literally.
        $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';

        return $query->where(function (Builder $q) use ($needle) {
            $q->where('name', 'like', $needle)
                ->orWhere('name_ar', 'like', $needle)
                ->orWhere('aliases', 'like', $needle)
                ->orWhere('identity_aliases', 'like', $needle)
                ->orWhere('slug', 'like', $needle)
                ->orWhere('default_part_number', 'like', $needle);
        });
    }

    /**
     * Every wording that NAMES this part — the surfaces that may be trusted to prove two records
     * refer to the same thing.
     *
     * Its own names, its slug, and the curated other-names list. NOT `aliases`, which carries symptom
     * wording and ambiguous trade names; see {@see \App\Services\PartIdentityService} for why that
     * asymmetry exists and what enforces it.
     *
     * @return array<int,string>
     */
    public function identitySurfaces(): array
    {
        return array_values(array_filter(array_merge(
            [$this->name, $this->name_ar, $this->slug],
            $this->identity_aliases ?? []
        ), fn ($s) => trim((string) $s) !== ''));
    }

    /** Display name in the caller's language, falling back to English when no Arabic term is set. */
    public function displayName(string $locale = 'en'): string
    {
        return $locale === 'ar' && $this->name_ar ? $this->name_ar : $this->name;
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

    /**
     * The component type a repair action's `target` refers to, or null when that target is not an
     * asset at all (`brake_system`, `warning_light`, `wiring` — bled, reset and repaired, never
     * fitted).
     *
     * Deliberately an exact lookup against the mapped column, never a slug transform: the two
     * vocabularies were authored independently and disagree on six of the first twenty-eight
     * targets (`battery` → `battery-12v`, `cooling_fan` → `radiator-fan`, `link_rod` →
     * `stabilizer-link`, …). See the add_action_target_to_component_catalog migration.
     *
     * Consumables ARE mapped and ARE returned — the caller skips them by tracking_mode, so that
     * "oil is not an asset" reads as a deliberate rule rather than as an unmapped gap.
     */
    public static function forActionTarget(?string $target): ?self
    {
        if (! $target) {
            return null;
        }

        return static::query()->active()->where('action_target', $target)->first();
    }
}
