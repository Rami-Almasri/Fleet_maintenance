<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One damaged area on an accident case — countable, costable, and joinable to the repair that fixed
 * it (or did not).
 *
 * `unknown` severity is a legitimate and common answer: most items are entered at the roadside by
 * somebody who can see a dent and cannot see what is behind it. Forcing a grade there would produce
 * a number that reads as an assessment and is actually a guess.
 */
class AccidentDamageItem extends Model
{
    public const SEVERITY_MINOR    = 'minor';
    public const SEVERITY_MODERATE = 'moderate';
    public const SEVERITY_SEVERE   = 'severe';
    public const SEVERITY_UNKNOWN  = 'unknown';
    public const SEVERITIES = [self::SEVERITY_MINOR, self::SEVERITY_MODERATE, self::SEVERITY_SEVERE, self::SEVERITY_UNKNOWN];

    protected $fillable = [
        'accident_case_id', 'damage_catalog_id', 'vehicle_location_id', 'area_label',
        'severity', 'description', 'requires_replacement', 'estimated_cost',
        'maintenance_task_id', 'created_by', 'created_by_name',
    ];

    protected $casts = [
        'requires_replacement' => 'boolean',
        'estimated_cost'       => 'decimal:2',
    ];

    public function accidentCase(): BelongsTo
    {
        return $this->belongsTo(AccidentCase::class);
    }

    /** The damage VOCABULARY row, when the reporter could name one. */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(DamageCatalog::class, 'damage_catalog_id');
    }

    /** WHERE on the car — the curated location axis, shared with faults. */
    public function location(): BelongsTo
    {
        return $this->belongsTo(VehicleLocation::class, 'vehicle_location_id');
    }

    /** The repair task raised for this item, once the car reaches a garage. */
    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }
}
