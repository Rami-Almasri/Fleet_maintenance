<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One garage "STINT" in a fault's life: the fault was at THIS garage from assigned_at until released_at.
 *
 * The open stint (released_at IS NULL) is where the fault is now; transferring it elsewhere closes this
 * stint (outcome = 'transferred_out') and opens a new one at the next garage. The sequence of stints is
 * the fault's transfer history and the raw material for per-garage performance (time-to-fix, transfer
 * rate). See MaintenanceTask and the create_maintenance_task_assignments migration.
 */
class MaintenanceTaskAssignment extends Model
{
    protected $table = 'maintenance_task_assignments';

    // ── How a stint ended ─────────────────────────────────────────────────────────────────────────
    public const OUTCOME_RESOLVED        = 'resolved';         // fixed here
    public const OUTCOME_TRANSFERRED_OUT = 'transferred_out';  // sent to another garage
    public const OUTCOME_UNABLE          = 'unable';           // garage declined / couldn't do it
    public const OUTCOME_CANCELLED       = 'cancelled';        // fault dropped while here
    // The garage returned the car claiming it fixed, but the closing re-inspection proved it did NOT —
    // the quality-control verdict that blames this garage for the fault and sends it back for re-dispatch.
    public const OUTCOME_FAILED_REINSPECTION = 'failed_reinspection';
    public const OUTCOMES = [
        self::OUTCOME_RESOLVED, self::OUTCOME_TRANSFERRED_OUT,
        self::OUTCOME_UNABLE, self::OUTCOME_CANCELLED, self::OUTCOME_FAILED_REINSPECTION,
    ];

    /** Outcomes that END a repair ATTEMPT (transferred_out / unable continue the same attempt elsewhere). */
    public const ATTEMPT_ENDING_OUTCOMES = [
        self::OUTCOME_RESOLVED, self::OUTCOME_FAILED_REINSPECTION, self::OUTCOME_CANCELLED,
    ];

    protected $fillable = [
        'maintenance_task_id', 'vendor_id',
        // work_started_at — when work actually began on THIS fault in this attempt (stamped by the
        // per-fault confirmation verdict, else an explicit in_progress). assigned_at is dispatch, which
        // is shared by every fault on the ticket, so it can never measure one fault on its own.
        // arrived_at — when the CAR physically reached this garage (stamped at the arrival check-in).
        // Distinct from assigned_at, which is the moment the fault was pointed here: on a transfer the
        // car is still across town at that point, so the gap between the two IS the move.
        'assigned_at', 'arrived_at', 'work_started_at', 'released_at', 'outcome', 'reason',
        'assigned_by', 'released_by',
        // Manual "actual mechanic time" for the attempt this stint ends — a human FACT, write-once
        // (filled only while null; edits go through FaultRepairTimeService::overwriteAttemptLabor with
        // an audit event). Never confused with the DERIVED wall-clock elapsed (assigned_at→released_at).
        'labor_hours', 'labor_recorded_by', 'labor_recorded_at',
    ];

    protected $casts = [
        'assigned_at'       => 'datetime',
        'arrived_at'        => 'datetime',
        'work_started_at'   => 'datetime',
        'released_at'       => 'datetime',
        'labor_hours'       => 'decimal:2',
        'labor_recorded_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    /** The garage this stint is/was at. */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function laborRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'labor_recorded_by');
    }

    /** Stints still in progress (the car is at this garage now). */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('released_at');
    }
}
