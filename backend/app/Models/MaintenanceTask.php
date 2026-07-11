<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One independently-routable FAULT on a maintenance ticket.
 *
 * A ticket (`maintenances`) is a CONTAINER; each MaintenanceTask is one symptom inside it that can be
 * routed to its own garage, move between garages, and carry its own cost — all without closing the
 * ticket. The fault's "where is it / who worked on it / when did it move" history lives in its garage
 * stints (maintenance_task_assignments); its money rolls up from the part/labor lines tagged with this
 * task (maintenance_line_items). When a task changes, the parent ticket's roll-ups (cost, headline
 * fault_severity, primary garage) are recomputed — see Maintenance::recalcFromTasks().
 *
 * See [[maintenance-workflow-engine]]; the per-fault model is the multi-garage evolution of the
 * single-block ticket.
 */
class MaintenanceTask extends Model
{
    protected $table = 'maintenance_tasks';

    // ── Status lifecycle ─────────────────────────────────────────────────────────────────────────
    public const STATUS_PENDING     = 'pending';      // identified, not yet started at a garage
    public const STATUS_IN_PROGRESS = 'in_progress';  // a garage is actively working it
    public const STATUS_COMPLETED   = 'completed';    // this fault is fixed
    public const STATUS_TRANSFERRED = 'transferred';  // transient — being moved between garages
    public const STATUS_CANCELLED   = 'cancelled';    // a non-issue; excluded from the "all resolved" gate
    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED,
        self::STATUS_TRANSFERRED, self::STATUS_CANCELLED,
    ];

    /** Terminal states — the fault needs no more work (resolved or dropped). */
    public const TERMINAL = [self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    /** Severity rank for rolling the headline ticket severity up to its worst open fault. */
    public const SEVERITY_RANK = [
        Maintenance::FAULT_SEVERITY_CRITICAL => 4,
        Maintenance::FAULT_SEVERITY_HIGH     => 3,
        Maintenance::FAULT_SEVERITY_MODERATE => 2,
        Maintenance::FAULT_SEVERITY_ROUTINE  => 1,
    ];

    protected $fillable = [
        'maintenance_id', 'vehicle_id',
        'symptom', 'category_key', 'source', 'severity',
        'root_cause_id', 'root_cause', 'notes', 'resolution_note',
        'status', 'current_vendor_id',
        'identified_by', 'identified_at', 'started_at', 'resolved_at', 'resolved_by',
        'parts_cost', 'labor_cost', 'repair_hours',
        // Quality-Control: this fault flunked the closing re-inspection (garage returned it unfixed).
        'reinspection_failures', 'last_failed_vendor_id', 'last_failed_at',
        // Delegate dispute: a supervisor overruled the inspector — this fault is a mis-diagnosis.
        'marked_incorrect_by', 'marked_incorrect_at', 'incorrect_reason',
    ];

    protected $casts = [
        'identified_at'         => 'datetime',
        'started_at'            => 'datetime',
        'resolved_at'           => 'datetime',
        'parts_cost'            => 'decimal:2',
        'labor_cost'            => 'decimal:2',
        'repair_hours'          => 'decimal:2',
        'reinspection_failures' => 'integer',
        'last_failed_at'        => 'datetime',
        'marked_incorrect_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        // A task appearing/changing/leaving re-derives the parent ticket's roll-ups. saveQuietly on the
        // parent side means this never recurses back into task events.
        static::saved(fn (MaintenanceTask $t) => optional($t->maintenance)->recalcFromTasks(true));
        static::deleted(fn (MaintenanceTask $t) => optional($t->maintenance)->recalcFromTasks(true));
    }

    // ── Relationships ─────────────────────────────────────────────────────────────────────────────

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** The garage that owns this fault right now (mirror of the open stint). */
    public function currentVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'current_vendor_id');
    }

    /** The garage that last handed this fault back UNFIXED at a re-inspection (the blame badge). */
    public function lastFailedVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'last_failed_vendor_id');
    }

    public function rootCause(): BelongsTo
    {
        return $this->belongsTo(FaultCause::class, 'root_cause_id');
    }

    public function identifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'identified_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** The delegate (supervisor) who overruled the inspector and marked this fault a mis-diagnosis. */
    public function markedIncorrectBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_incorrect_by');
    }

    /** Every garage stint this fault has been through, oldest first — the per-fault timeline. */
    public function assignments(): HasMany
    {
        return $this->hasMany(MaintenanceTaskAssignment::class)->orderBy('assigned_at');
    }

    /** The currently-open garage stint (released_at IS NULL), if the fault is at a garage now. */
    public function openAssignment(): HasMany
    {
        return $this->hasMany(MaintenanceTaskAssignment::class)->whereNull('released_at');
    }

    /** The part/labor cost lines attributed to this specific fault. */
    public function lineItems(): HasMany
    {
        return $this->hasMany(MaintenanceLineItem::class, 'maintenance_task_id');
    }

    /** The garage invoice this fault is billed on (null = not yet invoiced; fault → one invoice). */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(MaintenanceInvoice::class, 'maintenance_invoice_id');
    }

    /** Fix-evidence videos attached to this fault when it was marked fixed (newest first). */
    public function media(): HasMany
    {
        return $this->hasMany(MaintenanceMedia::class, 'maintenance_task_id')->latest();
    }

    // ── Scopes ──────────────────────────────────────────────────────────────────────────────────

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNotIn('status', self::TERMINAL);
    }

    /**
     * "Pending Assignment" — an OPEN fault that has NOT been routed to any garage yet (no open stint). In
     * the split-dispatch model the delegate (Waleed/Abdullah) may send only SOME faults to a garage at
     * dispatch and leave the rest here; they surface in the Dispatch Queue to be assigned later. Distinct
     * from a `pending` fault that DOES sit at a garage (e.g. arrived on a transfer) — that one has an open
     * stint, so current_vendor_id is set. A never-routed fault has current_vendor_id null.
     */
    public function scopePendingAssignment(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING)->whereNull('current_vendor_id');
    }

    // ── Roll-up helpers ───────────────────────────────────────────────────────────────────────────

    /**
     * Re-derive this fault's cached cost from its line items (parts_cost / labor_cost), then bubble the
     * change up to the parent ticket. Called from the MaintenanceLineItem write hook so the cached sums
     * stay honest on every line change. Persists quietly to avoid re-triggering the saved() roll-up loop;
     * the parent recompute is invoked explicitly afterwards.
     */
    public function recalcCosts(): void
    {
        $lines = $this->relationLoaded('lineItems') ? $this->lineItems : $this->lineItems()->get();

        $this->parts_cost = round((float) $lines->where('kind', MaintenanceLineItem::KIND_PART)->sum('line_total'), 2);
        $this->labor_cost = round((float) $lines->where('kind', MaintenanceLineItem::KIND_LABOR)->sum('line_total'), 2);
        $this->saveQuietly();

        optional($this->maintenance)->recalcFromTasks(true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    /** Is this an OPEN fault not yet routed to any garage (see scopePendingAssignment)? Query-free. */
    public function isPendingAssignment(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->current_vendor_id === null;
    }

    /** Was this fault ruled a mis-diagnosis by a delegate (distinct from a plain cancel)? */
    public function isIncorrect(): bool
    {
        return $this->marked_incorrect_at !== null;
    }
}
