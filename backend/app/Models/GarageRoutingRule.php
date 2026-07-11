<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One editable Garage Preference Rule in the Smart Routing Engine matrix.
 *
 * A rule says: garage `vendor_id` is worth `weight` points on axis `dimension` for `match_key`
 * (e.g. dimension=fault_category, match_key=electrical, weight=20, is_specialist=true). The scoring
 * service sums the active rules that match a ticket's faults + the car's class. config/garage_routing.php
 * seeds defaults; this table is the runtime source of truth, edited from the admin dashboard —
 * same config-seeds-DB pattern as [[FindingKeyword]] and [[FaultCause]].
 *
 * See the create_garage_routing_rules_table migration for the full column design and rationale.
 */
class GarageRoutingRule extends Model
{
    /** Routing axes — the allowed `dimension` values (kept in sync with config('garage_routing.dimensions')). */
    public const DIM_FAULT_CATEGORY = 'fault_category';
    public const DIM_VEHICLE_CLASS  = 'vehicle_class';
    public const DIMENSIONS         = [self::DIM_FAULT_CATEGORY, self::DIM_VEHICLE_CLASS];

    protected $fillable = [
        'vendor_id',
        'dimension',
        'match_key',
        'weight',
        'is_specialist',
        'active',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'weight'        => 'integer',
        'is_specialist' => 'boolean',
        'active'        => 'boolean',
    ];

    /** The garage this rule prefers / penalises. */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    public function scopeForDimension(Builder $q, string $dimension): Builder
    {
        return $q->where('dimension', $dimension);
    }

    /** Rules on the given axis whose match_key is in the supplied set (the engine's core lookup). */
    public function scopeMatching(Builder $q, string $dimension, array $matchKeys): Builder
    {
        return $q->where('dimension', $dimension)->whereIn('match_key', $matchKeys);
    }
}
