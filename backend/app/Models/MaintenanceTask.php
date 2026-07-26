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
    public const STATUS_NOT_FOUND   = 'not_found';    // workshop checked and the reported fault does not exist — closed, never repaired
    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED,
        self::STATUS_TRANSFERRED, self::STATUS_CANCELLED, self::STATUS_NOT_FOUND,
    ];

    /** Terminal states — the fault needs no more work (resolved or dropped). */
    public const TERMINAL = [self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_NOT_FOUND];

    /** Terminal states that were NOT a repair (no fix happened) — excluded from repair/service sync. */
    public const NON_REPAIR_TERMINAL = [self::STATUS_CANCELLED, self::STATUS_NOT_FOUND];

    // ── Workshop confirmation verdict ───────────────────────────────────────────────────────────────
    // Recorded by the technician at the "In Workshop" (under_repair) stage: a reported fault is only a
    // claim until the workshop physically checks it. The verdict is now binary — the workshop either
    // CONFIRMS the fault (the only verdict that may trigger recurring-fault intelligence, the gate against
    // false duplicate alerts) or rules it INCORRECT via markIncorrect() (→ cancelled, single not-a-real-
    // fault path; see markIncorrect). `confirmed` is therefore the ONLY verdict accepted as new input.
    public const CONFIRM_CONFIRMED       = 'confirmed';       // the fault genuinely exists
    // Legacy verdicts — no longer selectable; retained ONLY so historical rows still render/label. The
    // "not a real fault" outcome is now the single Incorrect path (markIncorrect → cancelled), not a verdict.
    public const CONFIRM_NOT_FOUND       = 'not_found';       // legacy: workshop found no fault
    public const CONFIRM_DIFFERENT_CAUSE = 'different_cause'; // legacy: a fault existed but the cause differed
    public const CONFIRMATION_STATUSES = [
        self::CONFIRM_CONFIRMED,
    ];

    // ── Recurring-fault REPAIR GATE ─────────────────────────────────────────────────────────────────
    // Set when a CONFIRMED fault recurred within the window: the repair is blocked until a manager
    // approves it (the guard against paying twice for the same recently-fixed fault).
    public const GATE_PENDING  = 'pending';   // awaiting approval — repair blocked
    public const GATE_APPROVED = 'approved';  // cleared — repair may proceed
    public const GATE_REJECTED = 'rejected';  // refused — fault cancelled, not repaired again here

    /** Severity rank for rolling the headline ticket severity up to its worst open fault. */
    public const SEVERITY_RANK = [
        Maintenance::FAULT_SEVERITY_CRITICAL => 4,
        Maintenance::FAULT_SEVERITY_HIGH     => 3,
        Maintenance::FAULT_SEVERITY_MODERATE => 2,
        Maintenance::FAULT_SEVERITY_ROUTINE  => 1,
    ];

    // ── PRIMARY DOMAIN CLASSIFICATION (Event Type layer) ──────────────────────────────────────────
    // `kind` is the ONE field the whole system reads to know what an event IS. Its source of truth is
    // the catalog the user picked (fault_catalog / service_catalog / inspection_types) — exactly one
    // *_catalog_id is set, matching `kind`, enforced by the DB CHECK + the saving() guard below. NEVER
    // decide type from symptom/category_key/severity/maintenance_type again — read `kind`.
    // See docs/Service-vs-Fault-Domain-Separation.md.
    public const KIND_FAULT      = 'fault';       // 🔴 unplanned failure/defect — counts in fault stats
    public const KIND_SERVICE    = 'service';     // 🔵 planned preventive work — excluded from fault stats
    public const KIND_INSPECTION = 'inspection';  // 🟨 a check — may spawn a separate fault
    public const KINDS = [self::KIND_FAULT, self::KIND_SERVICE, self::KIND_INSPECTION];

    /** Provenance of the `kind` value (audit + review targeting). */
    public const CLS_CATALOG  = 'catalog';   // user picked a catalog row (authoritative)
    public const CLS_RESOLVER = 'resolver';  // legacy backfill heuristic
    public const CLS_IMPORT   = 'import';    // sheet/OM import mapping
    public const CLS_MANUAL   = 'manual';    // admin override
    public const CLS_SOURCES  = [self::CLS_CATALOG, self::CLS_RESOLVER, self::CLS_IMPORT, self::CLS_MANUAL];

    /** Single source of the colour/emoji every surface (backend + frontend) uses to render a kind. */
    public const KIND_META = [
        self::KIND_FAULT      => ['emoji' => '🔴', 'label' => 'Fault',      'tone' => 'red'],
        self::KIND_SERVICE    => ['emoji' => '🔵', 'label' => 'Service',    'tone' => 'blue'],
        self::KIND_INSPECTION => ['emoji' => '🟨', 'label' => 'Inspection', 'tone' => 'amber'],
    ];

    /** Which *_catalog_id column backs each kind (used by the guard + catalog() resolver). */
    public const KIND_CATALOG_FK = [
        self::KIND_FAULT      => 'fault_catalog_id',
        self::KIND_SERVICE    => 'service_catalog_id',
        self::KIND_INSPECTION => 'inspection_type_id',
    ];

    protected $fillable = [
        'maintenance_id', 'vehicle_id',
        // Domain classification — the primary type + its catalog source-of-truth + provenance.
        'kind', 'fault_catalog_id', 'service_catalog_id', 'inspection_type_id',
        'classification_source', 'needs_review',
        'symptom', 'category_key', 'source', 'severity',
        'root_cause_id', 'root_cause', 'notes', 'resolution_note',
        'status', 'current_vendor_id',
        // Workshop confirmation gate + report-time recurring-fault flag + repair approval gate.
        'confirmation_status', 'confirmation_note', 'confirmed_by', 'confirmed_at',
        'recurrence_flagged', 'recurrence_previous_task_id',
        'repair_gate', 'repair_gate_by', 'repair_gate_at', 'repair_gate_note',
        // "Different fault" link — this fault was raised because another fault was reviewed Not found.
        'derived_from_task_id',
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
        'confirmed_at'          => 'datetime',
        'recurrence_flagged'    => 'boolean',
        'needs_review'          => 'boolean',
        'repair_gate_at'        => 'datetime',
        'parts_cost'            => 'decimal:2',
        'labor_cost'            => 'decimal:2',
        'repair_hours'          => 'decimal:2',
        'reinspection_failures' => 'integer',
        'last_failed_at'        => 'datetime',
        'marked_incorrect_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        // Classification integrity — the FIRST line of defence (the DB CHECK is the last). Exactly the
        // one *_catalog_id matching `kind` may be set; more than one is a bug; zero is allowed only as the
        // legacy/unclassified case (matches the CHECK, so flag-off behaviour is unchanged).
        static::saving(function (MaintenanceTask $t) {
            $set = array_filter([
                self::KIND_FAULT      => $t->fault_catalog_id,
                self::KIND_SERVICE    => $t->service_catalog_id,
                self::KIND_INSPECTION => $t->inspection_type_id,
            ], fn ($v) => $v !== null);

            if (count($set) === 0) {
                return; // legacy / not-yet-classified — permitted
            }
            if (count($set) > 1) {
                throw new \DomainException('A maintenance_task may reference at most one catalog (fault/service/inspection).');
            }
            $catalogKind = array_key_first($set);
            if ($t->kind !== $catalogKind) {
                throw new \DomainException("maintenance_task kind '{$t->kind}' does not match its catalog reference ('{$catalogKind}').");
            }
        });

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

    // ── Catalog (source-of-truth for `kind`) ────────────────────────────────────────────────────

    public function faultCatalog(): BelongsTo
    {
        return $this->belongsTo(FaultCatalog::class, 'fault_catalog_id');
    }

    public function serviceCatalog(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalog::class, 'service_catalog_id');
    }

    public function inspectionType(): BelongsTo
    {
        return $this->belongsTo(InspectionType::class, 'inspection_type_id');
    }

    /** The one catalog row backing this task's kind (null when unclassified/legacy). Query-free if loaded. */
    public function catalog(): ?Model
    {
        return match ($this->kind) {
            self::KIND_FAULT      => $this->faultCatalog,
            self::KIND_SERVICE    => $this->serviceCatalog,
            self::KIND_INSPECTION => $this->inspectionType,
            default               => null,
        };
    }

    public function identifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'identified_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** The technician who recorded the workshop confirmation verdict (confirmed / not_found / …). */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** The prior FIXED fault this one was flagged as possibly recurring from (report-time background flag). */
    public function recurrencePreviousTask(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'recurrence_previous_task_id');
    }

    /** The manager who approved / rejected the recurring-fault repair gate. */
    public function repairGateBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repair_gate_by');
    }

    /** The original (Not found) fault this one was raised from — the "turned out to be" link. */
    public function derivedFrom(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'derived_from_task_id');
    }

    /** New faults raised because THIS fault was reviewed Not found (the reverse of derivedFrom). */
    public function derivedFaults(): HasMany
    {
        return $this->hasMany(MaintenanceTask::class, 'derived_from_task_id');
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

    /** Part requests raised against THIS fault (Parts Purchase workflow) — newest first for the fault card. */
    public function partRequests(): HasMany
    {
        return $this->hasMany(PartRequest::class, 'maintenance_task_id')->latest('id');
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

    // ── Kind scopes — the ONLY sanctioned way to filter events by type ────────────────────────────
    // Every fault-oriented feature MUST start from ->faults(); every service feature from ->services().
    // Do NOT write where('kind', …) inline, and NEVER filter type via category_key/symptom/severity.

    public function scopeFaults(Builder $q): Builder
    {
        return $q->where('kind', self::KIND_FAULT);
    }

    public function scopeServices(Builder $q): Builder
    {
        return $q->where('kind', self::KIND_SERVICE);
    }

    public function scopeInspections(Builder $q): Builder
    {
        return $q->where('kind', self::KIND_INSPECTION);
    }

    public function scopeOfKind(Builder $q, string ...$kinds): Builder
    {
        return $q->whereIn('kind', $kinds);
    }

    public function scopeNeedsReview(Builder $q): Builder
    {
        return $q->where('needs_review', true);
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

    // ── Kind predicates + presentation ────────────────────────────────────────────────────────────

    public function isFault(): bool
    {
        return $this->kind === self::KIND_FAULT;
    }

    public function isService(): bool
    {
        return $this->kind === self::KIND_SERVICE;
    }

    public function isInspection(): bool
    {
        return $this->kind === self::KIND_INSPECTION;
    }

    /** Emoji/label/tone for rendering this task's kind (falls back to Fault meta for legacy rows). */
    public function kindMeta(): array
    {
        return self::KIND_META[$this->kind] ?? self::KIND_META[self::KIND_FAULT];
    }

    /** Has the workshop confirmed this fault genuinely exists (the gate for recurring-fault intelligence)? */
    public function isConfirmed(): bool
    {
        return $this->confirmation_status === self::CONFIRM_CONFIRMED;
    }

    /** Is the repair blocked awaiting recurring-fault approval? (Repair cannot start while true.) */
    public function isRepairBlocked(): bool
    {
        return $this->repair_gate === self::GATE_PENDING;
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
