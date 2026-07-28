<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manager's "not now" on a service-due vehicle (Maintenance Operations Center). An operational
 * dismissal only — it hides the row from the actionable board until snoozed_until passes; it never
 * alters the km service rule. See the migration and [[maintenance-workflow-engine]].
 */
class ServiceDueSnooze extends Model
{
    protected $fillable = [
        'vehicle_id', 'service_type', 'snoozed_until', 'reason',
        'active', 'snoozed_by', 'snoozed_by_name',
    ];

    protected $casts = [
        'snoozed_until' => 'date',
        'active'        => 'boolean',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function snoozedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'snoozed_by');
    }

    /**
     * Currently-effective snoozes: active and either open-ended or not yet elapsed. The board reads
     * this to decide which vehicles to hide.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('active', true)
            ->where(function ($q) {
                $q->whereNull('snoozed_until')->orWhereDate('snoozed_until', '>=', today());
            });
    }

    /** Map of vehicle_id => live snooze (latest wins) for the whole fleet, one query. */
    public static function liveByVehicle(): \Illuminate\Support\Collection
    {
        return static::live()->orderByDesc('id')->get()->keyBy('vehicle_id');
    }
}
