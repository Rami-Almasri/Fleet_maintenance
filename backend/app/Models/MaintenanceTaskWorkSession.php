<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One interval in a fault's working life — either hands-on WORK or a named BLOCK.
 *
 * This is the evidence behind "how many hours did this fault actually take": active work is the SUM of
 * this fault's `work` sessions, never the wall-clock between two stamps. See the table migration for
 * the worked example and the one-open-session invariant (enforced in FaultWorkSessionService).
 *
 * Blocked intervals are not waste to be hidden — they are the answer to "what was this car waiting for",
 * which is a supply-chain question the fleet cannot currently ask at all.
 */
class MaintenanceTaskWorkSession extends Model
{
    protected $table = 'maintenance_task_work_sessions';

    /** Hands-on labor for this fault. Only these sessions count toward active work time. */
    public const KIND_WORK = 'work';
    /** The fault could not progress. Counts toward waiting time, never toward labor. */
    public const KIND_BLOCKED = 'blocked';
    public const KINDS = [self::KIND_WORK, self::KIND_BLOCKED];

    // Why a fault is blocked. Deliberately a short closed list: the value of this field is that it can be
    // grouped in a report, which free text destroys. `other` carries the explanation in `note`.
    public const BLOCK_PARTS          = 'parts';           // waiting for a part to arrive
    public const BLOCK_APPROVAL       = 'approval';        // waiting for a manager/repair-gate decision
    public const BLOCK_CUSTOMER       = 'customer';        // waiting for the customer/owner
    public const BLOCK_OTHER_WORKSHOP = 'other_workshop';  // waiting on another garage/specialist team
    public const BLOCK_OTHER          = 'other';           // anything else — `note` is then required
    public const BLOCK_REASONS = [
        self::BLOCK_PARTS, self::BLOCK_APPROVAL, self::BLOCK_CUSTOMER,
        self::BLOCK_OTHER_WORKSHOP, self::BLOCK_OTHER,
    ];

    /** Human labels for the block reasons — one source, so API and UI word them identically. */
    public const BLOCK_LABELS = [
        self::BLOCK_PARTS          => 'Waiting for parts',
        self::BLOCK_APPROVAL       => 'Waiting for approval',
        self::BLOCK_CUSTOMER       => 'Waiting for the customer',
        self::BLOCK_OTHER_WORKSHOP => 'Waiting for another workshop',
        self::BLOCK_OTHER          => 'Blocked — see note',
    ];

    /** Where the row came from: clocked live, or reconstructed by the one-off backfill. */
    public const SOURCE_LIVE     = 'live';
    public const SOURCE_BACKFILL = 'backfill';

    protected $fillable = [
        'maintenance_task_id', 'maintenance_task_assignment_id',
        'kind', 'block_reason', 'started_at', 'ended_at',
        'started_by', 'ended_by', 'note', 'source',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    /** The garage stint this interval happened in (null for an on-site fault). */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTaskAssignment::class, 'maintenance_task_assignment_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    /** The session running right now (at most one per fault — see the service's lock). */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('ended_at');
    }

    public function scopeWork(Builder $q): Builder
    {
        return $q->where('kind', self::KIND_WORK);
    }

    public function scopeBlocked(Builder $q): Builder
    {
        return $q->where('kind', self::KIND_BLOCKED);
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Seconds this interval covers. An OPEN session is measured to `$now` — it is genuinely still
     * accruing, so a caller showing a live total is showing the truth, not a stale number.
     */
    public function seconds(?\DateTimeInterface $now = null): int
    {
        $end = $this->ended_at ?? \Illuminate\Support\Carbon::instance($now ?? \Illuminate\Support\Carbon::now());

        return max(0, (int) $this->started_at->diffInSeconds($end));
    }

    public function label(): string
    {
        return $this->kind === self::KIND_WORK
            ? 'Working'
            : (self::BLOCK_LABELS[$this->block_reason] ?? 'Blocked');
    }
}
