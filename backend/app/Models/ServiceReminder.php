<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recurring TECHNICAL maintenance due point for a vehicle (oil, filters, brakes, …).
 * See the service_reminders migration + ServiceReminderController + the auto-seed
 * command. Distinct from InspectionSchedule (safety/ops).
 *
 * The "oil_change" reminder is auto-seeded from the Oil Change sheet data on the car
 * (source = 'auto') and mirrors Vehicle::serviceStatus(); any user edit flips it to
 * 'manual' and the seeder then leaves it alone.
 */
class ServiceReminder extends Model
{
    /** Where the anchors came from: the Oil Change sheet, or a human. Manual always wins. */
    public const SOURCES = ['auto', 'manual'];

    /** Common service types (free-form is allowed; these drive the picker + labels). */
    public const TYPE_LABELS = [
        'oil_change'      => 'Oil Change',
        'air_filter'      => 'Air Filter',
        'oil_filter'      => 'Oil Filter',
        'brake_pads'      => 'Brake Pads',
        'tire_rotation'   => 'Tire Rotation',
        'tire_change'     => 'Tire Change',
        'battery'         => 'Battery',
        'ac_service'      => 'A/C Service',
        'transmission'    => 'Transmission Service',
        'general'         => 'General Service',
    ];

    /** "Due soon" window ahead of the next-due point. */
    public const DUE_SOON_KM   = 500;
    public const DUE_SOON_DAYS = 7;

    protected $fillable = [
        'vehicle_id', 'service_type', 'name',
        'interval_km', 'interval_days',
        'last_service_odometer', 'last_service_at',
        'next_due_odometer', 'next_due_at',
        'source', 'is_muted', 'active', 'notes',
        'last_notified_at', 'notified_by', 'notified_count',
    ];

    protected $casts = [
        'interval_km'           => 'integer',
        'interval_days'         => 'integer',
        'last_service_odometer' => 'integer',
        'next_due_odometer'     => 'integer',
        'last_service_at'       => 'date',
        'next_due_at'           => 'date',
        'is_muted'              => 'boolean',
        'active'                => 'boolean',
        'last_notified_at'      => 'datetime',
        'notified_count'        => 'integer',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** Who fired the last "notify the team" alert for this reminder (null if none yet). */
    public function notifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notified_by');
    }

    /** Has an alert already been sent out for this reminder? */
    public function isNotified(): bool
    {
        return $this->last_notified_at !== null;
    }

    /** A readable label for the service type, falling back to a title-cased slug. */
    public function displayName(): string
    {
        return $this->name
            ?: (self::TYPE_LABELS[$this->service_type] ?? ucwords(str_replace('_', ' ', (string) $this->service_type)));
    }

    /** Recompute the stored next-due point from the anchors + cadence (call before save). */
    public function recomputeNextDue(): void
    {
        $this->next_due_odometer = ($this->interval_km && $this->last_service_odometer !== null)
            ? $this->last_service_odometer + $this->interval_km
            : null;

        $this->next_due_at = ($this->interval_days && $this->last_service_at)
            ? Carbon::parse($this->last_service_at)->addDays($this->interval_days)
            : null;
    }

    /**
     * Live status: overdue | due_soon | ok | no_data. km axis uses the car's current
     * odometer (same maths as Vehicle::serviceStatus); worst axis wins. A muted reminder
     * is still computed but flagged so the UI can grey it out.
     *
     * @return array{status:string,label:string,km_remaining:?int,days_remaining:?int}
     */
    public function statusInfo(): array
    {
        $odometer = $this->vehicle?->odometer;
        $states   = [];

        $kmRemaining = null;
        if ($this->interval_km && $this->last_service_odometer !== null && $odometer !== null) {
            $kmRemaining = ($this->last_service_odometer + $this->interval_km) - $odometer;
            $states[] = $kmRemaining < 0 ? 'overdue'
                : ($kmRemaining <= self::DUE_SOON_KM ? 'due_soon' : 'ok');
        }

        $daysRemaining = null;
        if ($this->next_due_at) {
            $daysRemaining = (int) round(Carbon::now()->diffInDays($this->next_due_at, false));
            $states[] = $daysRemaining < 0 ? 'overdue'
                : ($daysRemaining <= self::DUE_SOON_DAYS ? 'due_soon' : 'ok');
        }

        if (empty($states)) {
            return ['status' => 'no_data', 'label' => 'No Data', 'km_remaining' => null, 'days_remaining' => null];
        }

        $status = in_array('overdue', $states, true) ? 'overdue'
            : (in_array('due_soon', $states, true) ? 'due_soon' : 'ok');

        return [
            'status'         => $status,
            'label'          => ['overdue' => 'Overdue', 'due_soon' => 'Due soon', 'ok' => 'OK'][$status],
            'km_remaining'   => $kmRemaining,
            'days_remaining' => $daysRemaining,
        ];
    }
}
