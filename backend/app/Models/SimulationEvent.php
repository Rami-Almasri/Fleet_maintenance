<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rolled-back-able change made by the admin Demo/Simulation Panel.
 * See the create_simulation_events_table migration for the full contract.
 */
class SimulationEvent extends Model
{
    public const SCENARIO_OIL   = 'oil_alert';
    public const SCENARIO_FAULT = 'fault_discovery';

    protected $fillable = [
        'scenario',
        'vehicle_id',
        'maintenance_id',
        'snapshot',
        'label',
        'created_by',
    ];

    protected $casts = [
        'snapshot' => 'array',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
