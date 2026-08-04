<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reminder the daily Checkpoint Scan actually pushed to one supervisor about one car, on one day.
 *
 * This is the receipt, not the notification: the notification lives in the alerts table and gets read and
 * forgotten. This row survives, and it carries the only fact oversight needs — whether the supervisor it
 * went to ever answered it. `responded_at` is stamped when they file a checkpoint on the ticket; a row
 * that stays open is the finding an admin sees on the Checkpoint Compliance board.
 *
 * See [[maintenance-checkpoint-feature]].
 */
class MaintenanceCheckpointReminder extends Model
{
    protected $table = 'maintenance_checkpoint_reminders';

    protected $fillable = [
        'maintenance_id', 'vehicle_id', 'user_id', 'level',
        'expected_on', 'sent_on', 'sent_at', 'responded_checkpoint_id', 'responded_at',
    ];

    protected $casts = [
        'expected_on'  => 'date',
        'sent_on'      => 'date',
        'sent_at'      => 'datetime',
        'responded_at' => 'datetime',
    ];

    /** Reminders nobody has answered yet — the raw material of the compliance board. */
    public function scopeUnanswered(Builder $query): Builder
    {
        return $query->whereNull('responded_at');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class, 'maintenance_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /** The supervisor this reminder was pushed to. */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(MaintenanceCheckpoint::class, 'responded_checkpoint_id');
    }
}
