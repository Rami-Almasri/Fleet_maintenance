<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vehicle's last computed garage-behaviour reading and the severity each signal was last
 * announced at. Written only by App\Services\Garage\GarageIntelligenceService — nothing else
 * should grade a car or decide what has been said about it.
 *
 * @see \App\Services\Garage\GarageIntelligenceService
 */
class VehicleGarageAlertState extends Model
{
    protected $fillable = [
        'vehicle_id', 'window_days',
        'visits', 'downtime_seconds', 'downtime_pct',
        'visit_severity', 'downtime_severity', 'severity',
        'notified_visit_severity', 'notified_downtime_severity', 'last_notified_at',
        'currently_in_garage', 'last_entry_at', 'reasons', 'evaluated_at',
    ];

    protected $casts = [
        'window_days'         => 'integer',
        'visits'              => 'integer',
        'downtime_seconds'    => 'integer',
        'downtime_pct'        => 'float',
        'currently_in_garage' => 'boolean',
        'last_entry_at'       => 'datetime',
        'evaluated_at'        => 'datetime',
        'last_notified_at'    => 'datetime',
        'reasons'             => 'array',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** Cars currently asking for attention on either signal. */
    public function scopeNeedingAttention($query)
    {
        return $query->where('severity', '!=', \App\Support\GarageSeverity::NORMAL);
    }
}
