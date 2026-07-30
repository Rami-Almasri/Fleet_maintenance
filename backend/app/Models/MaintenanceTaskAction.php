<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that was physically done to fix a fault. See the create_maintenance_task_actions
 * migration for why this exists and why it is never backfilled.
 *
 * This is a FACT in the evidence model — it either happened or it did not. The DECISION to perform
 * it is a separate judgement, recorded as its own event.
 */
class MaintenanceTaskAction extends Model
{
    public const VIA_WORKFLOW      = 'workflow';
    public const VIA_GARAGE_PORTAL = 'garage_portal';
    public const VIA_IMPORT        = 'import';

    protected $fillable = [
        'maintenance_task_id', 'maintenance_id', 'vehicle_id', 'action_catalog_id', 'sequence',
        'performed_by', 'performed_by_name', 'vendor_id', 'performed_at', 'note',
        'line_item_id', 'recorded_via',
    ];

    protected $casts = [
        'sequence'     => 'integer',
        'performed_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(ActionCatalog::class, 'action_catalog_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
