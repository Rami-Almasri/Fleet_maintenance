<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Maintenance Checkpoint — a dated progress UPDATE a responsible user (Waleed/Abdullah, or a ticket's
 * assigned owners) files while a car is in the workshop. It is the atom of the Maintenance Progress
 * Tracking System: it captures the ETA (previous_expected_date → next_expected_date), the structured
 * REASON the ETA moved (delay_reason), a workshop STATUS, a progress summary, and its own photos/videos
 * (maintenance_media).
 *
 * There is deliberately NO manual "Progress outcome" here: nobody classifies the job as On Track /
 * Delayed / Critical. The dashboard DERIVES that from the promised date (today ≤ ETA → On Schedule,
 * today > ETA → Overdue) and the workflow (Ready for Pickup / Completed). See
 * [[maintenance-checkpoint-feature]] and [[maintenance-workflow-engine]].
 */
class MaintenanceCheckpoint extends Model
{
    protected $table = 'maintenance_checkpoints';

    /** The workshop's current stage at the moment of the checkpoint. */
    public const STATUSES = [
        'waiting_parts', 'under_repair', 'painting', 'testing', 'ready_today', 'delayed', 'other',
    ];

    /** Structured reasons the ETA moved (required only when next_expected_date differs from the old ETA). */
    public const DELAY_REASONS = [
        'waiting_parts', 'workshop_busy', 'additional_damage', 'customer_approval',
        'insurance_approval', 'vendor_delay', 'other',
    ];

    /**
     * The supervisor's ANSWER to the daily question "will this car be back on the date we promised?".
     * DERIVED at submit from whether the date actually moved — never chosen independently of the date, so
     * the two can't disagree. `rescheduled` always carries a delay_reason; `confirmed` never does.
     */
    public const RESPONSE_CONFIRMED   = 'confirmed';
    public const RESPONSE_RESCHEDULED = 'rescheduled';

    protected $fillable = [
        'maintenance_id', 'vehicle_id', 'status', 'delay_reason', 'delay_reason_other',
        'summary', 'response', 'previous_expected_date', 'next_expected_date',
        'submitted_by', 'submitted_by_name',
    ];

    protected $casts = [
        'previous_expected_date' => 'date',
        'next_expected_date'     => 'date',
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

    /** Did this update push the promised date back (as opposed to confirming it)? */
    public function isReschedule(): bool
    {
        return $this->response === self::RESPONSE_RESCHEDULED;
    }

    /** The daily reminders this checkpoint answered. */
    public function remindersAnswered(): HasMany
    {
        return $this->hasMany(MaintenanceCheckpointReminder::class, 'responded_checkpoint_id');
    }

    /** The photos/videos captured with this checkpoint. */
    public function media(): HasMany
    {
        return $this->hasMany(MaintenanceMedia::class, 'maintenance_checkpoint_id')->latest();
    }
}
