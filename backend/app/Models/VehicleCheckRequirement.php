<?php

namespace App\Models;

use App\Support\VehicleCheckCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One outstanding obligation: the platform asked for a check, and somebody must answer it.
 *
 * The three-way distinction this model exists to hold:
 *
 *   [[Recommendation]]  — advice the platform gave. Immutable, historical, no open/closed state.
 *   VehicleCheckRequirement — an obligation. Has a status, and stays visible until answered.
 *   [[MaintenanceTask]] — a fault. Only ever created when a RESULT says one exists.
 *
 * Status is the ONLY thing that moves. Everything about why the check was raised (reason code,
 * evidence, severity, the catalog version in force) is frozen at creation, because a requirement
 * answered six months ago must still read against the question that was actually asked.
 *
 * Every transition also writes a [[VehicleCheckEvent]]. Those are append-only and enforced as such;
 * this row is a projection of them kept for queryability, never a substitute.
 */
class VehicleCheckRequirement extends Model
{
    // ── Lifecycle ──────────────────────────────────────────────────────────────────────────────
    /** Raised, not yet on an inspection. */
    public const STATUS_PENDING = 'pending';
    /** On a live inspection ticket, waiting for the inspector. */
    public const STATUS_ATTACHED = 'attached';
    /** Answered with a result that needs a decision, which has not been made yet. */
    public const STATUS_INSPECTED = 'inspected';
    /** Decided "do it" — a real maintenance action is in flight and owns the outcome now. */
    public const STATUS_ACTION_PENDING = 'action_pending';
    /** Done, by any legitimate route (OK, monitored, repaired, deferred, declined). */
    public const STATUS_RESOLVED = 'resolved';
    /** Withdrawn for a system/domain reason — the car left the fleet, the ticket was destroyed. */
    public const STATUS_CANCELLED = 'cancelled';
    /** A newer requirement for the same cycle replaced it. */
    public const STATUS_SUPERSEDED = 'superseded';
    /** Nobody ever answered it and the evidence went stale. Deliberately NOT deleted. */
    public const STATUS_EXPIRED = 'expired';

    /**
     * Still owed. This is the set the Decide step must show and the submit gate must insist on —
     * the difference between "checked and fine" and "nobody looked" lives entirely in whether a
     * requirement is still in one of these.
     */
    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ATTACHED,
        self::STATUS_INSPECTED,
    ];

    /** Answered or withdrawn — nothing further is owed by a human. */
    public const CLOSED_STATUSES = [
        self::STATUS_RESOLVED,
        self::STATUS_CANCELLED,
        self::STATUS_SUPERSEDED,
        self::STATUS_EXPIRED,
    ];

    // ── Provenance ─────────────────────────────────────────────────────────────────────────────
    public const SOURCE_MONITOR    = 'diagnostic_monitor';
    public const SOURCE_CAPABILITY = 'capability';
    public const SOURCE_MANUAL     = 'manual';
    public const SOURCE_BACKFILL   = 'backfill';

    // ── Resolution codes ───────────────────────────────────────────────────────────────────────
    public const RESOLUTION_CONFIRMED_OK = 'confirmed_ok';
    public const RESOLUTION_MONITORING   = 'monitoring';
    public const RESOLUTION_REPAIRED     = 'repaired';
    public const RESOLUTION_DEFERRED     = 'deferred';
    public const RESOLUTION_DECLINED     = 'declined';

    protected $fillable = [
        'vehicle_id', 'check_type', 'check_key',
        'source', 'rule_key', 'catalog_version', 'recommendation_id',
        'reason_code', 'reason_params', 'detail_en', 'severity', 'evidence',
        'cycle_key', 'cycle_hash', 'cycle_seq',
        'status', 'maintenance_id', 'attached_at',
        'inspected_by', 'inspected_at', 'result_code',
        'decision_code', 'decided_by', 'decided_at', 'finding_keyword',
        'action_type', 'action_id', 'action_created_at', 'action_completed_at',
        'resolved_at', 'resolution_code', 'reraise_after',
    ];

    protected $casts = [
        'reason_params'       => 'array',
        'evidence'            => 'array',
        'cycle_seq'           => 'integer',
        'attached_at'         => 'datetime',
        'inspected_at'        => 'datetime',
        'decided_at'          => 'datetime',
        'action_created_at'   => 'datetime',
        'action_completed_at' => 'datetime',
        'resolved_at'         => 'datetime',
        'reraise_after'       => 'datetime',
    ];

    // ── Relations ──────────────────────────────────────────────────────────────────────────────

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** The inspection this check was attached to and answered on. */
    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    /** The immutable Decision Card that raised it, when one did. */
    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(VehicleCheckEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    /** The maintenance action this check produced, whatever type it is. Null unless one was created. */
    public function action(): ?Model
    {
        if (! $this->action_type || ! $this->action_id || ! class_exists($this->action_type)) {
            return null;
        }

        return $this->action_type::find($this->action_id);
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────────────────────

    /** Still owed by a human. */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', self::OPEN_STATUSES);
    }

    /** Owed AND not yet answered on any ticket — the set a new inspection may adopt. */
    public function scopeUnattached(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING);
    }

    // ── Derived reads ──────────────────────────────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Answered by a person — as opposed to withdrawn by the system or never looked at. */
    public function wasInspected(): bool
    {
        return $this->inspected_at !== null;
    }

    /** The catalog block that governs this check's options. */
    public function catalog(): array
    {
        return VehicleCheckCatalog::type($this->check_type);
    }

    /** The result options an inspector may pick, ready for the UI. */
    public function resultOptions(): array
    {
        return VehicleCheckCatalog::resultOptions($this->check_type);
    }
}
