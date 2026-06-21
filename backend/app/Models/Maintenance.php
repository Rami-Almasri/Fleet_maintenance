<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row in `maintenances` plays one of two roles, told apart by `origin`:
 *
 *  - the maintenance "header" for a type-'U' contract (origin = 'contract'): garage,
 *    issue tags, who approved it, expected return — one per contract (contract_id 1:1).
 *  - a standalone WORKSHOP EVENT (origin = 'sheet' | 'manual' | 'customer-sheet'):
 *    one row per event (OUT / IN / Follow up / …) anchored to a VEHICLE, not a contract.
 *    'sheet'/'customer-sheet' come from the Google-Sheet sync; 'manual' is entered by hand
 *    in the dashboard. The two event sources are interchangeable everywhere the board reads
 *    the live garage log — manual events are the dashboard becoming the source of truth.
 */
class Maintenance extends Model
{
    use HasFactory;

    protected $table = 'maintenances';

    /** Origins that represent a standalone, vehicle-anchored workshop EVENT (not a contract header). */
    public const EVENT_ORIGINS = ['sheet', 'manual', 'customer-sheet'];

    /** Origin used for workshop events created/edited by hand in the dashboard. */
    public const ORIGIN_MANUAL = 'manual';

    /** Origins owned by the Google-Sheet sync — the ONLY rows a sheet reload may wipe. */
    public const SHEET_ORIGINS = ['sheet', 'customer-sheet'];

    /** The live garage/workshop log the maintenance board reads: imported sheet events + hand-entered ones. */
    public const WORKSHOP_LOG_ORIGINS = ['sheet', 'manual'];

    /** Workshop stages (event_status) the dashboard manages; 'IN' means the car came back. */
    public const STAGES = ['OUT', 'IN', 'Follow up', 'Change', 'Delay', 'Test', 'Under Test'];

    protected $fillable = [
        'contract_id',
        'vehicle_id',
        'vendor_id',
        'maintenance_reason_id',
        'approval_status',
        'approved_amount',
        'approved_at',
        'maintenance_tags',
        'responsible',
        'approved_by',
        'expected_return_date',
        'maintenance_notes',
        // sheet maintenance log (origin = 'sheet')
        'origin',
        'row_hash',
        'car_label',
        'plate',
        'event_status',
        'out_date',
        'follow_date',
        'actual_in_date',
        'base_on',
        'driver',
        'liable_party',
        'charge_to',
        'garage',
        'maintenance_type',
        'service_main',
        'service_sup',
        'damage_location',
        'severity',
        'spare_part',
        'invoice_no',
        'cost',
        'cost_notes',
        // customer-cases sheet log (origin = 'customer-sheet')
        'bill_receive',
    ];

    protected $casts = [
        'maintenance_tags'     => 'array',
        'expected_return_date' => 'date',
        'out_date'             => 'date',
        'follow_date'          => 'date',
        'actual_in_date'       => 'date',
        'approved_amount'      => 'decimal:2',
        'cost'                 => 'decimal:2',
        'approved_at'          => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** Standalone workshop events (sheet log + hand-entered), not contract headers. */
    public function scopeWorkshopEvents(Builder $q): Builder
    {
        return $q->whereIn('origin', self::EVENT_ORIGINS);
    }

    /** True for a hand-entered event — the rows the sheet sync must never overwrite. */
    public function isManual(): bool
    {
        return $this->origin === self::ORIGIN_MANUAL;
    }

    /** The car this maintenance event is for (sheet log rows link straight to the vehicle). */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** The garage / workshop. */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** The classified situation (links to the controlled reason->status vocabulary). */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(MaintenanceReason::class, 'maintenance_reason_id');
    }
}
