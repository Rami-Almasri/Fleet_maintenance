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
        'make',
        'model',
        'year',
        'color',
        'category',
        'status',
        'status_no',
        'car_serial',
        'for_sale',
        'operational_status',
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
}
