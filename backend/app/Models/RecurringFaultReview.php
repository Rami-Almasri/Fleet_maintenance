<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A management REVIEW CASE for a confirmed fault that recurred after a completed repair.
 *
 * Opened by RecurringFaultService when the workshop CONFIRMS a fault (verdict = confirmed) that matches
 * the same fault previously FIXED on the same vehicle within the recurrence window. It is evidence + one
 * management decision — it never blames the garage on its own. See the recurring_fault_reviews migration
 * and [[maintenance-tasks-container-model]].
 */
class RecurringFaultReview extends Model
{
    protected $table = 'recurring_fault_reviews';

    // ── Lifecycle ────────────────────────────────────────────────────────────────────────────────
    public const STATUS_OPEN    = 'open';
    public const STATUS_DECIDED = 'decided';
    public const STATUSES = [self::STATUS_OPEN, self::STATUS_DECIDED];

    // ── Management decisions (the only place responsibility is assigned — by a human, never automatically) ─
    public const DECISION_SAME_REPAIR_FAILED     = 'same_repair_failed';
    public const DECISION_NEW_UNRELATED_FAILURE  = 'new_unrelated_failure';
    public const DECISION_WORKSHOP_RESPONSIBILITY = 'workshop_responsibility';
    public const DECISION_CUSTOMER_MISUSE        = 'customer_misuse';
    public const DECISION_INVESTIGATION_REQUIRED = 'investigation_required';
    public const DECISIONS = [
        self::DECISION_SAME_REPAIR_FAILED,
        self::DECISION_NEW_UNRELATED_FAILURE,
        self::DECISION_WORKSHOP_RESPONSIBILITY,
        self::DECISION_CUSTOMER_MISUSE,
        self::DECISION_INVESTIGATION_REQUIRED,
    ];

    // ── Previous-repair verification quality ───────────────────────────────────────────────────────
    public const RESULT_VERIFIED_FIXED = 'verified_fixed'; // a post-repair inspection signed it off as fixed
    public const RESULT_FIXED          = 'fixed';           // marked completed, but not independently verified

    protected $fillable = [
        'status', 'decision',
        'vehicle_id', 'maintenance_id', 'maintenance_task_id',
        'previous_maintenance_id', 'previous_task_id', 'repair_inspection_id',
        'symptom', 'category_key',
        'previous_garage_id', 'previous_garage_name', 'previous_result',
        'previous_repaired_at', 'days_since_repair',
        'previous_odometer', 'current_odometer', 'distance_since_repair',
        'occurrence_count', 'parts', 'context',
        'opened_by', 'opened_by_name', 'opened_at',
        'decided_by', 'decided_by_name', 'decided_at', 'decision_note',
    ];

    protected $casts = [
        'parts'                 => 'array',
        'context'               => 'array',
        'previous_repaired_at'  => 'datetime',
        'opened_at'             => 'datetime',
        'decided_at'            => 'datetime',
        'days_since_repair'     => 'integer',
        'previous_odometer'     => 'integer',
        'current_odometer'      => 'integer',
        'distance_since_repair' => 'integer',
        'occurrence_count'      => 'integer',
    ];

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────────────────────────
    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_OPEN);
    }

    // ── Relationships ──────────────────────────────────────────────────────────────────────────────
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

    public function previousMaintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class, 'previous_maintenance_id');
    }

    public function previousTask(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'previous_task_id');
    }

    public function repairInspection(): BelongsTo
    {
        return $this->belongsTo(RepairInspection::class);
    }

    public function previousGarage(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'previous_garage_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
