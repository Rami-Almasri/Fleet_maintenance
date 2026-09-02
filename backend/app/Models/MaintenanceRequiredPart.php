<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One part the INSPECTOR expects a repair to need — a technical requirement, not a procurement request.
 *
 * This is the clean half of the responsibility split: the inspector diagnoses and says what the job will
 * need; he does NOT start procurement, because the decisions that drive a purchase (which garage, does the
 * garage supply parts, which supplier, what budget) are not his to make and are not yet known. Those belong
 * to the maintenance coordinator, AFTER the garage is chosen — at which point the coordinator converts the
 * lines he still wants into {@see PartRequest} rows, which own the real Requested → … → Completed lifecycle.
 *
 * The two are linked many-to-many ({@see requests}) so one line can be sourced across several requests, and
 * one request can cover the same part asked for by several faults.
 *
 * See [[maintenance-workflow-engine]] for the ticket lifecycle this hangs off.
 */
class MaintenanceRequiredPart extends Model
{
    protected $table = 'maintenance_required_parts';

    /** Awaiting the coordinator's decision — the state every inspector-recorded line starts in. */
    public const STATUS_PENDING   = 'pending';
    /** Converted into at least one part request; procurement owns it from here. */
    public const STATUS_REQUESTED = 'requested';
    /** The coordinator decided not to source it (garage supplies it / not needed / deferred). */
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_REQUESTED, self::STATUS_DISMISSED];

    public const PRIORITY_URGENT = 'urgent';
    public const PRIORITY_HIGH   = 'high';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_LOW    = 'low';
    public const PRIORITIES = [self::PRIORITY_URGENT, self::PRIORITY_HIGH, self::PRIORITY_NORMAL, self::PRIORITY_LOW];

    protected $fillable = [
        'maintenance_id', 'vehicle_id', 'maintenance_task_id', 'finding_key', 'finding_text',
        // The catalog reference is the identity; `part_name` beside it is the inspector's own
        // wording, kept as evidence. Both are fillable — omitting the reference here silently
        // dropped it on every create while the service believed it had been written.
        'component_catalog_id', 'catalog_matched_by',
        // The size the inspector asked for, so the buyer is not left guessing at the counter.
        // @see \App\Support\PartSpecs
        'part_name', 'specs', 'notes', 'quantity', 'priority', 'status',
        'recorded_by', 'recorded_by_name', 'recorded_at',
        'actioned_by', 'actioned_by_name', 'actioned_at', 'dismissal_reason',
    ];

    protected $casts = [
        'specs'       => 'array',
        'quantity'    => 'decimal:2',
        'recorded_at' => 'datetime',
        'actioned_at' => 'datetime',
    ];

    /**
     * The normalised form of a finding's symptom text — the bridge between a required-part line recorded at
     * report time (when the fault is still `findings` JSON) and the MaintenanceTask it becomes later.
     *
     * Must mirror MaintenanceTaskService::key() EXACTLY — strtolower(trim(…)) — or a line would never bind
     * to its fault. The only addition is a 191-char clamp to fit the column; the resolver applies the same
     * clamp to the task's symptom before comparing, so the two still agree on very long symptom text.
     */
    public static function findingKey(?string $text): ?string
    {
        $key = strtolower(trim((string) $text));

        return $key === '' ? null : substr($key, 0, 191);
    }

    /** Still awaiting the coordinator — the queue the ticket view surfaces as "Required Parts". */
    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING);
    }

    /** A line is actionable while nothing has been decided about it yet. */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** The fault this part serves — null until the report's findings are promoted to tasks. */
    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    /** The procurement requests this technical line was converted into (traceability, both directions). */
    public function requests(): BelongsToMany
    {
        return $this->belongsToMany(PartRequest::class, 'part_request_required_part', 'maintenance_required_part_id', 'part_request_id')
            ->withTimestamps();
    }

    /** The inspector who recorded the requirement. */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** The coordinator who converted or dismissed it. */
    public function actioner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }
}
