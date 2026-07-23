<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Vehicle extends Model
{
    /** @use HasFactory<\Database\Factories\VehicleFactory> */
    use HasFactory, SoftDeletes;

    /** Cars are replaced/sold 4 years after purchase. */
    public const REPLACEMENT_YEARS = 4;

    /** OfficeManager AssetStatusNo -> our status slug (the API is the source of truth). */
    public const OM_STATUS = [
        1 => 'office_use',
        2 => 'ready',
        3 => 'rented',
        4 => 'out_of_order',
        5 => 'under_maintenance',
        6 => 'suspended',
        7 => 'disposed',
        8 => 'sold',
        9 => 'returned',
    ];

    /**
     * Operational status = the car's CURRENT movement, derived from its open contract
     * (not from the API). Maintained live by OperationsService and re-derived in bulk by
     * OperationsService::reconcileAllOperationalStatus after each sync.
     */
    public const OPERATIONAL_LABELS = [
        'available'   => 'Available',
        'rented'      => 'Rented',
        'maintenance' => 'In Maintenance',
        'test'        => 'Test Drive',
        'transfer'    => 'Transfer',
        'sale_prep'   => 'Sale Prep',
        // Out on a Logistics Dispatch — being driven to a destination (transit_destination holds where).
        'in_transit'  => 'In Transit',
    ];

    /**
     * "Active" fleet = cars that can actually earn right now: Ready (OM status 2) or
     * Rented (3). Single source of truth for views that should ignore sold / disposed /
     * under_maintenance / out_of_order / suspended / returned cars (Maintenance Foresight
     * report + its NotificationScanner alerts).
     */
    public const ACTIVE_STATUSES = ['ready', 'rented'];

    /**
     * Visual Condition Grade (Abu Marouf) — a manual cosmetic/condition assessment kept
     * SEPARATE from the OM lifecycle `status` and the live `operational_status`:
     *   green  = Perfect (fully available, no issues)
     *   orange = Cosmetic / serviceable (minor scratches — still rentable, warn the
     *            customer at handover)
     *   yellow = Maintenance needed (showing symptoms — NOT rentable, must be routed to
     *            the garage; blocked from the rental interface and hidden from Available)
     *   red    = Critical / grounded (unsafe or a major fault — blocked from the rental
     *            interface and hidden from the Available counts)
     * Only green & orange stay rentable; yellow & red are both pulled from the pool and
     * routed to maintenance.
     */
    public const CONDITION_GRADES = ['green', 'orange', 'yellow', 'red'];

    public const CONDITION_LABELS = [
        'green'  => 'Perfect',
        'orange' => 'Cosmetic issues',
        'yellow' => 'Maintenance needed',
        'red'    => 'Critical — grounded',
    ];

    /** Human labels for the status slugs. */
    public const STATUS_LABELS = [
        'office_use'        => 'Office Use',
        'ready'             => 'Ready',
        'rented'            => 'Rented',
        'out_of_order'      => 'Out of Order',
        'under_maintenance' => 'Under Maintenance',
        'suspended'         => 'Suspended',
        'disposed'          => 'Disposed',
        'sold'              => 'Sold',
        'returned'          => 'Returned',
    ];

    protected $fillable = [
        'code',
        'vin',
        'engine_no',
        'driver_no',
        'plate_no',
        'plate_key',   // canonical plate digits (leading zeros stripped) — the plate-history key
        'make',
        'model',
        'year',
        'color',
        'category',
        'vehicle_class',
        'status',
        'status_no',
        'car_serial',
        'for_sale',
        'operational_status',
        'transit_destination',
        // --- Visual Condition Grade (Abu Marouf) ---
        'condition_grade',
        'condition_note',
        'condition_graded_at',
        'condition_graded_by',
        // --- Deferred Maintenance (car pulled out of the shop early for a customer) ---
        'is_deferred_maintenance',
        'deferred_maintenance_reason',
        'deferred_maintenance_flagged_at',
        'deferred_maintenance_flagged_by',
        // --- Pre-Delivery Readiness checklist (see VehicleReadinessService) ---
        'cleaning_status',
        'gps_last_seen_at',
        'odometer',
        'engine_hours',
        'source',
        // --- specs & rental defaults from the OfficeManager API car card ---
        'keys_number',
        'auto_gear',
        'cylinders',
        'horse_power',
        'doors',
        'seats',
        'passengers',
        'wheel_drive',
        'location',
        'salik_tag_no',
        'hour_rent_value',
        'day_rent_value',
        'week_rent_value',
        'month_rent_value',
        'year_rent_value',
        'miles_allowed_pd',
        'miles_allowed_pm',
        'extra_mile_charge',
        'full_fuel_cost',
        'purchase_price',
        'purchase_date',
        'warranty_end_date',
        'warranty_end_km',
        'service_due_date',
        'service_due_km',
        'battery_last_changed',
        // --- service interval + baseline from the "Oil Change" sheet (NOT the API) ---
        'last_service_odometer',
        'service_interval_km',
        'service_synced_at',
        // --- Global Mileage Baseline (anchored to the earliest contract reading) ---
        'baseline_odometer',
        'baseline_synced_at',
        'replacement_due_date',
        'notes',
        'external_id',
        'synced_at',
        'origin',
    ];

    protected $casts = [
        'for_sale' => 'boolean',
        'auto_gear' => 'boolean',
        'status_no' => 'integer',
        'keys_number' => 'integer',
        'cylinders' => 'integer',
        'horse_power' => 'integer',
        'doors' => 'integer',
        'seats' => 'integer',
        'passengers' => 'integer',
        'wheel_drive' => 'integer',
        'miles_allowed_pd' => 'integer',
        'miles_allowed_pm' => 'integer',
        'service_due_km' => 'integer',
        'last_service_odometer' => 'integer',
        'service_interval_km' => 'integer',
        'service_synced_at' => 'datetime',
        'baseline_odometer' => 'integer',
        'baseline_synced_at' => 'datetime',
        'hour_rent_value' => 'decimal:2',
        'day_rent_value' => 'decimal:2',
        'week_rent_value' => 'decimal:2',
        'month_rent_value' => 'decimal:2',
        'year_rent_value' => 'decimal:2',
        'extra_mile_charge' => 'decimal:2',
        'full_fuel_cost' => 'decimal:2',
        'synced_at' => 'datetime',
        'purchase_date' => 'date',
        'warranty_end_date' => 'date',
        'service_due_date' => 'date',
        'battery_last_changed' => 'date',
        'replacement_due_date' => 'date',
        'condition_graded_at' => 'datetime',
        'gps_last_seen_at' => 'datetime',
        'is_deferred_maintenance' => 'boolean',
        'deferred_maintenance_flagged_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Auto-fill the replacement/sell date from purchase_date (+4 years) when not provided.
        static::saving(function (Vehicle $vehicle) {
            if ($vehicle->purchase_date && empty($vehicle->replacement_due_date)) {
                $vehicle->replacement_due_date = Carbon::parse($vehicle->purchase_date)
                    ->addYears(self::REPLACEMENT_YEARS)
                    ->toDateString();
            }
        });
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * Every maintenance row anchored to this car — sheet/manual workshop events AND workflow tickets.
     * Broad on purpose; callers scope it (e.g. the Vehicles list counts only open on-site tickets to
     * flag "Pending Maintenance" while the car stays available). See VehicleService::index().
     */
    public function maintenances(): HasMany
    {
        return $this->hasMany(Maintenance::class);
    }

    /** Recurring technical service due-points (oil, filters, brakes, …) for this car. */
    public function serviceReminders(): HasMany
    {
        return $this->hasMany(ServiceReminder::class);
    }

    /**
     * Record a completed OIL CHANGE and roll every dependent surface forward in one shot — the
     * closed-loop "Log oil change" action. This is the single writer that keeps the three oil
     * surfaces in agreement, so completing a service actually clears the Service-Due alert:
     *
     *   1. the vehicle-level anchor (last_service_odometer / service_synced_at) that
     *      serviceStatus() — and therefore the serviceDue / serviceDueSoon notifications — read;
     *   2. the recurring "oil_change" ServiceReminder row (find-or-create, rolled to the new
     *      anchor and flipped to source='manual' so the auto-seeder leaves it alone).
     *
     * @param  int          $odometer  the reading at which the oil was changed
     * @param  string|null  $date      service date (Y-m-d); defaults to today
     */
    public function recordOilService(int $odometer, ?string $date = null): ServiceReminder
    {
        $date = $date ?: now()->toDateString();

        // 1) Vehicle anchor — what serviceStatus() and the oil alert read. The reading also
        //    advances the car's live odometer (monotonic), so serviceStatus() recomputes
        //    "km left" against a current mileage rather than a stale API value.
        $this->advanceOdometer($odometer);
        $this->last_service_odometer = $odometer;
        $this->service_synced_at = now();
        $this->save();

        // 2) The matching recurring reminder (find-or-create), rolled forward.
        $reminder = $this->serviceReminders()->firstOrNew(['service_type' => 'oil_change']);
        if (! $reminder->exists) {
            $reminder->name       = ServiceReminder::TYPE_LABELS['oil_change'];
            $reminder->interval_km = $this->service_interval_km;
            $reminder->active     = true;
        }
        $reminder->last_service_at       = $date;
        $reminder->last_service_odometer = $odometer;
        $reminder->source                = 'manual';
        $reminder->recomputeNextDue();
        $reminder->save();

        return $reminder;
    }

    /**
     * Fallback cadence for routine services that have no sheet-derived interval (unlike oil, which reads
     * its per-car interval from `service_interval_km`). Only used when the reminder is FIRST created by a
     * completed routine fault; an existing reminder keeps whatever cadence it already has. km + days axes
     * are both optional — a battery is age-driven, filters are mileage-driven. Tune freely.
     *
     * @var array<string, array{interval_km:?int, interval_days:?int}>
     */
    public const ROUTINE_SERVICE_DEFAULTS = [
        'battery'    => ['interval_km' => null,  'interval_days' => 730],   // ~2-year battery life
        'oil_filter' => ['interval_km' => 10000, 'interval_days' => null],
        'air_filter' => ['interval_km' => 20000, 'interval_days' => null],
    ];

    /**
     * Record a completed ROUTINE service of any type and roll its recurring Service Reminder forward, so
     * the next reminder fires on schedule. This is the generic sibling of recordOilService(): oil_change
     * delegates to it (because oil ALSO re-anchors the car's serviceStatus() baseline); every other type
     * just find-or-creates its `service_type` reminder, re-anchors it to this odometer/date, flips it to
     * source='manual' (so the auto-seeder leaves it), and recomputes the next-due point. A brand-new
     * reminder seeds its cadence from ROUTINE_SERVICE_DEFAULTS.
     *
     * @param  string       $serviceType  a ServiceReminder service_type slug (oil_change, battery, …)
     * @param  int          $odometer     the reading at which the service was performed
     * @param  string|null  $date         service date (Y-m-d); defaults to today
     */
    public function recordServiceDone(string $serviceType, int $odometer, ?string $date = null): ServiceReminder
    {
        if ($serviceType === 'oil_change') {
            return $this->recordOilService($odometer, $date);
        }

        $date = $date ?: now()->toDateString();

        // Sync the vehicle master record's dynamic fields from this service reading:
        //  - a battery service stamps `battery_last_changed` (which drives the computed Next
        //    Battery Change), mirroring how oil re-anchors last_service_odometer;
        //  - every routine service advances the live odometer (monotonic) so serviceStatus()
        //    recalculates against current mileage.
        if ($serviceType === 'battery') {
            $this->battery_last_changed = $date;
        }
        $this->advanceOdometer($odometer);
        if ($this->isDirty()) {
            $this->save();
        }

        $reminder = $this->serviceReminders()->firstOrNew(['service_type' => $serviceType]);
        if (! $reminder->exists) {
            $defaults = self::ROUTINE_SERVICE_DEFAULTS[$serviceType] ?? ['interval_km' => null, 'interval_days' => null];
            $reminder->name          = ServiceReminder::TYPE_LABELS[$serviceType] ?? ucwords(str_replace('_', ' ', $serviceType));
            $reminder->interval_km   = $defaults['interval_km'];
            $reminder->interval_days = $defaults['interval_days'];
            $reminder->active        = true;
        }
        $reminder->last_service_at       = $date;
        $reminder->last_service_odometer = $odometer;
        $reminder->source                = 'manual';
        $reminder->recomputeNextDue();
        $reminder->save();

        return $reminder;
    }

    /**
     * Move the car's live odometer forward to a freshly-observed reading (e.g. captured while
     * logging a service). Odometer is monotonic by policy — the self-healing global baseline
     * never rolls back — so a LOWER reading at service time is ignored rather than trusted; only
     * a higher one becomes the new current mileage that serviceStatus() reads. Does not save on
     * its own: the caller persists as part of its own write.
     */
    protected function advanceOdometer(int $reading): void
    {
        if ($reading > 0 && ($this->odometer === null || $reading > $this->odometer)) {
            $this->odometer = $reading;
        }
    }

    /** The car's Maintenance-Workflow audit trail (append-only), newest event first. */
    public function logEvents(): HasMany
    {
        return $this->hasMany(VehicleLogEvent::class)->latest('occurred_at');
    }

    /** Asset Layer: every component row currently or last associated with this car. */
    public function components(): HasMany
    {
        return $this->hasMany(VehicleComponent::class);
    }

    /** Asset Layer: the physical truth — what is installed on this car RIGHT NOW. */
    public function activeComponents(): HasMany
    {
        return $this->hasMany(VehicleComponent::class)->where('status', VehicleComponent::STATUS_ACTIVE);
    }

    /** Asset Layer: performed actions (oil changes, inspections, repair labor), newest first. */
    public function serviceRecords(): HasMany
    {
        return $this->hasMany(ServiceRecord::class)->latest('performed_at');
    }

    /**
     * This car's own row in the plate-history timeline (one per vehicle+plate). Carries whether
     * this car is the plate's CURRENT holder and the from/to window it held the plate. The full
     * timeline of OTHER cars that shared the plate is fetched by PlateHistoryService via plate_key
     * — this relation just exposes the flag/window for the resource without an extra query.
     */
    public function plateAssignment(): HasOne
    {
        return $this->hasOne(PlateAssignment::class);
    }

    /** True when this car is the live holder of its plate (per the plate-history timeline). */
    public function isCurrentPlateHolder(): bool
    {
        return (bool) optional($this->plateAssignment)->is_current;
    }

    /** The car's registration / insurance record (latest). */
    public function registration(): HasOne
    {
        return $this->hasOne(VehicleRegistration::class)->latestOfMany();
    }

    /**
     * The single currently-open contract (the car's current movement), if any.
     * Uses ofMany so the "latest" is the latest AMONG open contracts — otherwise a
     * later-imported CLOSED contract (higher id) would hide a genuinely open one.
     * A contract with an in_date counts as RETURNED (see Contract::scopeCurrentlyOpen),
     * so a returned-but-not-closed contract never shows the car as still out.
     */
    public function openContract(): HasOne
    {
        return $this->hasOne(Contract::class)->ofMany(['id' => 'max'], fn ($q) => $q->where('state', 'open')->whereNull('in_date'));
    }

    /**
     * Service-due status, computed STRICTLY in km from a single set of sources:
     *   - current mileage     = odometer               (API)
     *   - last service km     = last_service_odometer  ("Oil Change" sheet)
     *   - interval            = service_interval_km     ("Oil Change" sheet)
     *
     * No heuristics, no date-based guessing, and the API's service_due_* fields are not used.
     * If the car has no Oil Change sheet match (baseline or interval missing) the status is
     * 'no_data' — we never guess a due point.
     *
     *   distance = odometer - last_service_odometer
     *   due when distance >= service_interval_km
     *
     * @return array{status:string,label:string,current:?int,baseline:?int,interval:?int,distance:?int,remaining:?int,overdue_km:?int}
     */
    public function serviceStatus(): array
    {
        $current  = $this->odometer;
        $baseline = $this->last_service_odometer;
        $interval = $this->service_interval_km;

        $base = [
            'current'    => $current,
            'baseline'   => $baseline,
            'interval'   => $interval,
            'distance'   => null,
            'remaining'  => null,
            'overdue_km' => null,
        ];

        // No sheet match (or no current mileage) -> No Data. Don't guess a due point.
        if ($baseline === null || $interval === null || $current === null) {
            return ['status' => 'no_data', 'label' => 'No Data'] + $base;
        }

        $distance  = $current - $baseline;
        $remaining = $interval - $distance;
        $isDue     = $distance >= $interval;

        return [
            'status'     => $isDue ? 'service_due' : 'ok',
            'label'      => $isDue ? 'Service Due' : 'OK',
            'distance'   => $distance,
            'remaining'  => $remaining,
            'overdue_km' => $isDue ? -$remaining : null,
        ] + $base;
    }

    /**
     * A car graded Red (critical / grounded) OR Yellow (maintenance needed) must never leave
     * on a customer handover: it is blocked from the booking/rental interface and hidden from
     * the Available counts, and should be routed to the garage. Only green & orange stay
     * rentable (orange with a documented acknowledgment).
     */
    public function rentBlockedByCondition(): bool
    {
        return in_array($this->condition_grade, ['red', 'yellow'], true);
    }

    /**
     * Orange = serviceable with minor cosmetic issues: still rentable, but ops must
     * inform the customer at handover (a mandatory acknowledgment prompt).
     */
    public function hasCosmeticAlert(): bool
    {
        return $this->condition_grade === 'orange';
    }

    /**
     * Yellow = the car is showing symptoms and needs scheduled maintenance. It is pulled from
     * the rental pool (see rentBlockedByCondition) and must be routed to the garage — it is
     * also surfaced in the Maintenance Forecast.
     */
    public function needsScheduledMaintenance(): bool
    {
        return $this->condition_grade === 'yellow';
    }

    /**
     * Only Orange (cosmetic / serviceable) cars stay bookable but require an acknowledgment: a
     * customer handover on one requires the sales agent to confirm the customer was told about
     * the condition first — that acknowledgment is recorded on the contract. Green is clean;
     * Yellow & Red are hard blocks (see rentBlockedByCondition), so neither is rentable.
     */
    public function requiresConditionAcknowledgement(): bool
    {
        return $this->condition_grade === 'orange';
    }

    /**
     * Deferred Maintenance: the car was pulled out of the workshop early to satisfy a customer,
     * so it still "owes" the garage a visit. Set when it's rented out of maintenance, surfaced as
     * a standing 🛠️↩️ flag on every fleet surface, and cleared only when it's checked back into
     * the workshop (a new maintenance visit) or a supervisor dismisses it. See OperationsService.
     */
    public function owesMaintenance(): bool
    {
        return (bool) $this->is_deferred_maintenance;
    }
}
