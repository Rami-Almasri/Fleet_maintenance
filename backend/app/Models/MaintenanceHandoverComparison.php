<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Handover Comparison Report — a permanent, generated-once-per-resume diff between the pause
 * handover and the resume handover (see HandoverComparisonService::compare()). Persisted unconditionally
 * (clean or not) so it stays attached to the ticket's history even when nothing breached.
 */
class MaintenanceHandoverComparison extends Model
{
    protected $fillable = [
        'maintenance_id',
        'pause_handover_id',
        'resume_handover_id',
        'mileage_delta',
        'fuel_delta',
        'new_damages',
        'missing_accessories',
        'condition_changes',
        'exceeds_threshold',
        'threshold_breaches',
        'generated_at',
    ];

    protected $casts = [
        'new_damages'          => 'array',
        'missing_accessories'  => 'array',
        'condition_changes'    => 'array',
        'threshold_breaches'   => 'array',
        'exceeds_threshold'    => 'boolean',
        'generated_at'         => 'datetime',
    ];

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function pauseHandover(): BelongsTo
    {
        return $this->belongsTo(MaintenanceHandover::class, 'pause_handover_id');
    }

    public function resumeHandover(): BelongsTo
    {
        return $this->belongsTo(MaintenanceHandover::class, 'resume_handover_id');
    }
}
