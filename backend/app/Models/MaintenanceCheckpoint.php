<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Maintenance Checkpoint — a dated progress report a responsible user (Waleed/Abdullah, or a ticket's
 * assigned owners) files while a car is in the workshop. It is the atom of the Maintenance Progress
 * Tracking System: an OUTCOME the dashboard colours by, a workshop STATUS, an optional structured DELAY
 * REASON, a summary, a pushed-back completion date, and its own photos/videos (maintenance_media). See
 * [[maintenance-workflow-engine]].
 */
class MaintenanceCheckpoint extends Model
{
    protected $table = 'maintenance_checkpoints';

    /** Management verdict — drives dashboard colour + reporting (never parsed from free text). */
    public const OUTCOME_ON_TRACK = 'on_track';
    public const OUTCOME_DELAYED  = 'delayed';
    public const OUTCOME_CRITICAL = 'critical';
    public const OUTCOMES = [self::OUTCOME_ON_TRACK, self::OUTCOME_DELAYED, self::OUTCOME_CRITICAL];

    /** The workshop's current stage at the moment of the checkpoint. */
    public const STATUSES = [
        'waiting_parts', 'under_repair', 'painting', 'testing', 'ready_today', 'delayed', 'other',
    ];

    /** Structured delay reasons (required when outcome = delayed). */
    public const DELAY_REASONS = [
        'waiting_parts', 'workshop_busy', 'additional_damage', 'customer_approval',
        'insurance_approval', 'vendor_delay', 'other',
    ];

    protected $fillable = [
        'maintenance_id', 'vehicle_id', 'outcome', 'status', 'delay_reason', 'delay_reason_other',
        'summary', 'next_expected_date', 'submitted_by', 'submitted_by_name',
    ];

    protected $casts = [
        'next_expected_date' => 'date',
    ];

    /** The ticket this checkpoint reports on (loose link, mirroring media / line items). */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class, 'maintenance_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** The photos/videos captured with this checkpoint. */
    public function media(): HasMany
    {
        return $this->hasMany(MaintenanceMedia::class, 'maintenance_checkpoint_id')->latest();
    }
}
