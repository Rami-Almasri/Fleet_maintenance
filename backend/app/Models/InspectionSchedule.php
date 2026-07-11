<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recurring SAFETY / OPERATIONS inspection plan for a vehicle. See the
 * inspection_schedules migration + InspectionScheduleController. Distinct from
 * ServiceReminder (technical maintenance).
 */
class InspectionSchedule extends Model
{
    /** How the cadence is measured. */
    public const INTERVAL_TYPES = ['time', 'meter', 'both'];

    /** The inspection pillars (safety & operations). */
    public const PILLARS = ['safety', 'operations', 'compliance', 'cleanliness'];

    /** Consider a schedule "due soon" within this many days / km of its next-due point. */
    public const DUE_SOON_DAYS = 3;
    public const DUE_SOON_KM   = 500;

    protected $fillable = [
        'vehicle_id', 'name', 'description', 'pillar',
        'interval_type', 'interval_days', 'interval_km',
        'last_inspected_at', 'last_inspected_odometer',
        'next_due_at', 'next_due_odometer',
        'assigned_to', 'active', 'notes',
    ];

    protected $casts = [
        'interval_days'           => 'integer',
        'interval_km'             => 'integer',
        'last_inspected_odometer' => 'integer',
        'next_due_odometer'       => 'integer',
        'last_inspected_at'       => 'datetime',
        'next_due_at'             => 'datetime',
        'active'                  => 'boolean',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Recompute next_due_at / next_due_odometer from the last-inspected anchors and the
     * cadence. Call before save and after logging a completed inspection. A time anchor
     * falls back to the row's created moment; a meter anchor needs last_inspected_odometer.
     */
    public function recomputeNextDue(): void
    {
        if (in_array($this->interval_type, ['time', 'both'], true) && $this->interval_days) {
            $anchor = $this->last_inspected_at ?: $this->created_at ?: Carbon::now();
            $this->next_due_at = Carbon::parse($anchor)->addDays($this->interval_days);
        } elseif ($this->interval_type === 'meter') {
            $this->next_due_at = null;
        }

        if (in_array($this->interval_type, ['meter', 'both'], true)
            && $this->interval_km && $this->last_inspected_odometer !== null) {
            $this->next_due_odometer = $this->last_inspected_odometer + $this->interval_km;
        } elseif ($this->interval_type === 'time') {
            $this->next_due_odometer = null;
        }
    }

    /**
     * Live status: overdue | due_soon | ok | no_data. Meter checks use the car's current
     * odometer; whichever axis (time or meter) is worse wins.
     *
     * @return array{status:string,label:string,days_remaining:?int,km_remaining:?int}
     */
    public function statusInfo(): array
    {
        $now       = Carbon::now();
        $odometer  = $this->vehicle?->odometer;
        $states    = [];   // collected verdicts to reduce to the worst

        $daysRemaining = null;
        if ($this->next_due_at) {
            $daysRemaining = (int) round($now->diffInDays($this->next_due_at, false));
            $states[] = $daysRemaining < 0 ? 'overdue'
                : ($daysRemaining <= self::DUE_SOON_DAYS ? 'due_soon' : 'ok');
        }

        $kmRemaining = null;
        if ($this->next_due_odometer !== null && $odometer !== null) {
            $kmRemaining = $this->next_due_odometer - $odometer;
            $states[] = $kmRemaining < 0 ? 'overdue'
                : ($kmRemaining <= self::DUE_SOON_KM ? 'due_soon' : 'ok');
        }

        if (empty($states)) {
            return ['status' => 'no_data', 'label' => 'No due point', 'days_remaining' => null, 'km_remaining' => null];
        }

        $status = in_array('overdue', $states, true) ? 'overdue'
            : (in_array('due_soon', $states, true) ? 'due_soon' : 'ok');

        return [
            'status'         => $status,
            'label'          => ['overdue' => 'Overdue', 'due_soon' => 'Due soon', 'ok' => 'On track'][$status],
            'days_remaining' => $daysRemaining,
            'km_remaining'   => $kmRemaining,
        ];
    }
}
