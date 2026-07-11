<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Oversight case: a car was transferred to a different garage while EVERY fault on its ticket was
 * already fixed. See the migration for the "why" — the move is gated behind a mandatory justification
 * note (in MaintenanceWorkflowController::transferGarage) and recorded here for review on
 * /oversight/resolved-transfers. Read-only after creation; nothing mutates it.
 */
class ResolvedTransferFlag extends Model
{
    protected $table = 'resolved_transfer_flags';

    protected $fillable = [
        'maintenance_id',
        'vehicle_id',
        'from_vendor_id',
        'from_garage',
        'to_vendor_id',
        'to_garage',
        'note',
        'odometer',
        'flagged_by',
    ];

    protected $casts = [
        'odometer' => 'integer',
    ];

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class, 'maintenance_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function flaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }
}
