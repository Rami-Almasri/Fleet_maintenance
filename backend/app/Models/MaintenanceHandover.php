<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable custody-transfer EVENT on a maintenance ticket — the pause leg (car leaves) or the
 * resume leg (car comes back). Never updated after creation; the permanent evidence a
 * HandoverComparisonService diff is built from. See [[Pause & Return to Service — Enterprise Handover]].
 */
class MaintenanceHandover extends Model
{
    public const TYPE_PAUSE  = 'pause';
    public const TYPE_RESUME = 'resume';

    protected $fillable = [
        'maintenance_id',
        'vehicle_id',
        'type',
        'odometer_reading',
        'odometer_ocr_reading',
        'odometer_photo_inspection_record_id',
        'fuel_level',
        'exterior_condition',
        'interior_condition',
        'damage_findings',
        'missing_accessories',
        'notes',
        'signature_path',
        'signature_disk',
        'actor_id',
        'occurred_at',
        'workflow_status_snapshot',
        'reason',
    ];

    protected $casts = [
        'damage_findings'      => 'array',
        'missing_accessories'  => 'array',
        'occurred_at'          => 'datetime',
        'created_at'           => 'datetime',
    ];

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
