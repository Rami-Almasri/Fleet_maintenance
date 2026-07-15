<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An admin accountability record raised when the intelligence layer detects a repeated spend or a
 * repeated fault. Mirrors {@see MaintenanceIncident}: it opens automatically, forces a reason to be
 * captured, and is held open until an admin resolves it. It never blocks the underlying purchase.
 */
class PartInvestigation extends Model
{
    public const TYPE_DUPLICATE_PURCHASE = 'duplicate_purchase';
    public const TYPE_FAULT_RECURRENCE   = 'fault_recurrence';
    public const TYPES = [self::TYPE_DUPLICATE_PURCHASE, self::TYPE_FAULT_RECURRENCE];

    public const PRIORITY_LOW    = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH   = 'high';
    public const PRIORITIES = [self::PRIORITY_LOW, self::PRIORITY_MEDIUM, self::PRIORITY_HIGH];

    public const STATUS_OPEN            = 'open';
    public const STATUS_UNDER_REVIEW    = 'under_review';
    public const STATUS_REASON_PROVIDED = 'reason_provided';
    public const STATUS_APPROVED        = 'approved';
    public const STATUS_REJECTED        = 'rejected';
    public const STATUS_CLOSED          = 'closed';

    public const OPEN_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_REASON_PROVIDED,
    ];

    /** The enumerated reasons a duplicate/recurrence is explained by (plus free text in reason_note). */
    public const REASON_PREVIOUS_PART_FAILED = 'previous_part_failed';
    public const REASON_WRONG_DIAGNOSIS      = 'wrong_diagnosis';
    public const REASON_CUSTOMER_REQUESTED   = 'customer_requested';
    public const REASON_ACCIDENT_DAMAGE      = 'accident_damage';
    public const REASON_OTHER                = 'other';
    public const REASON_CODES = [
        self::REASON_PREVIOUS_PART_FAILED,
        self::REASON_WRONG_DIAGNOSIS,
        self::REASON_CUSTOMER_REQUESTED,
        self::REASON_ACCIDENT_DAMAGE,
        self::REASON_OTHER,
    ];

    protected $fillable = [
        'type', 'priority', 'status',
        'vehicle_id',
        'part_purchase_id', 'previous_purchase_id',
        'maintenance_task_id', 'previous_task_id',
        'reason_code', 'reason_note', 'context',
        'opened_by', 'opened_by_name', 'opened_at',
        'reason_by', 'reason_by_name', 'reason_at',
        'resolved_by', 'resolved_by_name', 'resolved_at', 'resolution',
    ];

    protected $casts = [
        'context'     => 'array',
        'opened_at'   => 'datetime',
        'reason_at'   => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(PartPurchase::class, 'part_purchase_id');
    }

    public function previousPurchase(): BelongsTo
    {
        return $this->belongsTo(PartPurchase::class, 'previous_purchase_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    public function previousTask(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'previous_task_id');
    }
}
