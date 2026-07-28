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
        'accepted', 'followed', 'rank', 'score', 'confidence', 'reasons', 'criteria', 'actor_id',
    ];

    protected $casts = [
        'accepted' => 'boolean',
        'followed' => 'boolean',
        'rank'     => 'integer',
        'score'    => 'float',
        'reasons'  => 'array',
        'criteria' => 'array',
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
