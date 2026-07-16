<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Handover Comparison that breached its configured thresholds — must be ACKNOWLEDGED before the
 * gated resume can actually finalize. No silent continuation past a discrepancy. See
 * MaintenanceWorkflowService::resumeMaintenance()/acknowledgeIncident().
 */
class MaintenanceIncident extends Model
{
    public const STATUS_OPEN         = 'open';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const TYPE_HANDOVER_DISCREPANCY = 'handover_discrepancy';

    protected $fillable = [
        'maintenance_id',
        'comparison_id',
        'type',
        'severity',
        'description',
        'status',
        'acknowledged_by',
        'acknowledged_at',
        'acknowledgement_note',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function comparison(): BelongsTo
    {
        return $this->belongsTo(MaintenanceHandoverComparison::class, 'comparison_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
