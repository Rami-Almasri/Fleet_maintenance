<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded garage-choice decision at dispatch — the durable answer to "why did we send this car here?".
 * Snapshots what GarageRecommendationService suggested vs. what the Supervisor chose, plus the evidence.
 *
 * See the create_garage_recommendation_decisions_table migration and [[garage-recommendation-engine]].
 */
class GarageRecommendationDecision extends Model
{
    protected $fillable = [
        'maintenance_id', 'vehicle_id', 'recommended_vendor_id', 'chosen_vendor_id',
        'accepted', 'followed', 'rank', 'score', 'match_score', 'confidence',
        'reasons', 'breakdown', 'strategy', 'expected_outcomes', 'fault_criticality', 'criteria', 'actor_id',
        'actual_outcomes', 'forecast_accuracy', 'scored_at',
        'engine_version', 'policy_version', 'config_fingerprint', 'data_snapshot',
        // The feedback loop: why the supervisor differed, and where their pick actually stood.
        'override_reason', 'override_note', 'chosen_rank', 'chosen_match_score', 'score_gap', 'chosen_advantages',
    ];

    protected $casts = [
        'accepted'    => 'boolean',
        'followed'    => 'boolean',
        'rank'        => 'integer',
        'score'       => 'float',
        'match_score' => 'integer',
        'reasons'     => 'array',
        'breakdown'   => 'array',
        'strategy'    => 'array',
        // The forecast the operator acted on, kept so predicted-vs-actual can be scored after closure.
        'expected_outcomes' => 'array',
        'fault_criticality' => 'array',
        // The verdict on the forecast — written by ForecastCalibration once the repair has concluded.
        'actual_outcomes'   => 'array',
        'forecast_accuracy' => 'array',
        'scored_at'         => 'datetime',
        'criteria'    => 'array',
        'chosen_rank'        => 'integer',
        'chosen_match_score' => 'integer',
        'score_gap'          => 'integer',
        'chosen_advantages'  => 'array',
    ];

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function recommendedVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'recommended_vendor_id');
    }

    public function chosenVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'chosen_vendor_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
