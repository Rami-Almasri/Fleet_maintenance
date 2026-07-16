<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One post-repair quality-control verdict — the structured answer to "did the garage actually fix it?"
 *
 * Recorded when a car comes back and the inspector signs it off at the WF_READY_REINSPECTION gate
 * (PASS → close(), FAIL → markReinspectionFailed()). Each row is per-fault: a ticket sign-off writes
 * one row per fault it verified. This is the DURABLE layer beneath the transient reinspection_failures
 * counter on MaintenanceTask — it retains the reason a repair failed, the repair it judged, and the
 * recurrence signal, which the technician/part-failure intelligence reads. See [[reinspection-qc-layer]].
 */
class RepairInspection extends Model
{
    protected $table = 'repair_inspections';

    // ── Result ───────────────────────────────────────────────────────────────────────────────────
    public const RESULT_FIXED        = 'fixed';        // problem gone → close the fault, repair successful
    public const RESULT_STILL_EXISTS = 'still_exists'; // problem remains → reopen + Repair Failure Alert
    public const RESULT_NEW_ISSUE    = 'new_issue';    // original fixed, but a NEW problem surfaced
    public const RESULTS = [self::RESULT_FIXED, self::RESULT_STILL_EXISTS, self::RESULT_NEW_ISSUE];

    // ── Failure reason (only when result = still_exists) ───────────────────────────────────────────
    public const REASON_WRONG_DIAGNOSIS  = 'wrong_diagnosis';
    public const REASON_PART_FAILED       = 'part_failed';
    public const REASON_REPAIR_INCOMPLETE = 'repair_incomplete';
    public const REASON_WRONG_PART        = 'wrong_part';
    public const REASON_CUSTOMER_COMPLAINT = 'customer_complaint';
    public const REASON_UNKNOWN           = 'unknown';
    public const REASONS = [
        self::REASON_WRONG_DIAGNOSIS, self::REASON_PART_FAILED, self::REASON_REPAIR_INCOMPLETE,
        self::REASON_WRONG_PART, self::REASON_CUSTOMER_COMPLAINT, self::REASON_UNKNOWN,
    ];

    /** A repair is "returned / failed" for quality metrics when the problem was still there. */
    public const RETURNED_RESULTS = [self::RESULT_STILL_EXISTS];

    protected $fillable = [
        'maintenance_id', 'vehicle_id', 'fault_id', 'new_fault_id', 'inspector_id',
        'result', 'failure_reason', 'notes',
        'previous_vendor_id', 'previous_repaired_at', 'days_since_repair', 'is_recurrence',
        'inspection_date',
    ];

    protected $casts = [
        'previous_repaired_at' => 'datetime',
        'inspection_date'      => 'datetime',
        'days_since_repair'    => 'integer',
        'is_recurrence'        => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────────────────────────

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** The original fault that was repaired and re-checked. */
    public function fault(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'fault_id');
    }

    /** Case C — the new fault this inspection created. */
    public function newFault(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'new_fault_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    /** The garage that did the repair being judged (blame anchor). */
    public function previousVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'previous_vendor_id');
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────────────────────────

    public function scopeForVehicle(Builder $q, int $vehicleId): Builder
    {
        return $q->where('vehicle_id', $vehicleId);
    }

    /** Verdicts where the repair did not hold — the numerator of "returned problems". */
    public function scopeReturned(Builder $q): Builder
    {
        return $q->whereIn('result', self::RETURNED_RESULTS);
    }

    public function scopeFixed(Builder $q): Builder
    {
        return $q->where('result', self::RESULT_FIXED);
    }
}
