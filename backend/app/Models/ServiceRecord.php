<?php

namespace App\Models;

use App\Support\ServiceTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A performed ACTION (labor) — oil change, alignment, inspection, repair labor. An immutable
 * FACT, not a workflow: the requested/approved/in-progress pre-life lives on the maintenance
 * ticket. A failed service's retry is a new ticket, never a state change here.
 *
 * Consumables (the oil itself, coolant) carry their cost here via materials_cost — they NEVER
 * become VehicleComponent rows. Component = physical thing · Service = performed action.
 */
class ServiceRecord extends Model
{
    public const RESULT_COMPLETED = 'completed';
    public const RESULT_PARTIAL   = 'partial';
    public const RESULT_FAILED    = 'failed';
    public const RESULTS = [self::RESULT_COMPLETED, self::RESULT_PARTIAL, self::RESULT_FAILED];

    public const SOURCE_WORKFLOW_CLOSE  = 'workflow_close';
    public const SOURCE_INVOICE_IMPORT  = 'invoice_import';
    public const SOURCE_MANUAL          = 'manual';
    public const SOURCE_LEGACY_BACKFILL = 'legacy_backfill';
    public const SOURCES = [
        self::SOURCE_WORKFLOW_CLOSE, self::SOURCE_INVOICE_IMPORT,
        self::SOURCE_MANUAL, self::SOURCE_LEGACY_BACKFILL,
    ];

    /** Canonical action vocabulary — single source: App\Support\ServiceTypes. */
    public const SERVICE_TYPES = ServiceTypes::ALL;

    protected $fillable = [
        'vehicle_id', 'maintenance_id', 'maintenance_task_id',
        'service_type', 'description', 'performed_at', 'odometer',
        'workshop_vendor_id', 'technician_name',
        'labor_cost', 'materials_cost', 'duration_hours',
        'result', 'related_component_id',
        'source', 'source_line_item_id', 'source_invoice_item_id',
        'performed_by', 'performed_by_name',
    ];

    protected $casts = [
        'performed_at'   => 'date',
        'odometer'       => 'integer',
        'labor_cost'     => 'decimal:2',
        'materials_cost' => 'decimal:2',
        'duration_hours' => 'decimal:2',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'workshop_vendor_id');
    }

    public function relatedComponent(): BelongsTo
    {
        return $this->belongsTo(VehicleComponent::class, 'related_component_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function scopeForVehicle(Builder $query, int $vehicleId): Builder
    {
        return $query->where('vehicle_id', $vehicleId);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('service_type', $type);
    }

    /**
     * Latest record per service_type for one vehicle — the "last done: <date> at <garage>" map
     * (successor of serviceHistory's last_by_category).
     */
    public static function lastPerType(int $vehicleId): array
    {
        return static::forVehicle($vehicleId)
            ->orderByDesc('performed_at')
            ->orderByDesc('id')
            ->get()
            ->unique('service_type')
            ->keyBy('service_type')
            ->all();
    }
}
