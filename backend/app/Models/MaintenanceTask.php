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
    // the catalog the user picked (fault_catalog / service_catalog / inspection_types / damage_catalog)
    // — exactly one *_catalog_id is set, matching `kind`, enforced by the DB CHECK + the saving() guard
    // below. NEVER decide type from symptom/category_key/severity/maintenance_type again — read `kind`.
    // See docs/Service-vs-Fault-Domain-Separation.md and docs/Service-Fault-Damage-Domain.md.
    //
    // THE FOUR KINDS ANSWER FOUR DIFFERENT QUESTIONS:
    //   service    — we planned this work.        Recurring is normal.
    //   fault      — the vehicle failed.          Recurring is a reliability signal.
    //   damage     — something was done TO it.    Recurring says something about DRIVERS, not the car.
    //   inspection — we looked at it.             May spawn a separate fault or damage event.
    public const KIND_FAULT      = 'fault';       // 🔴 unplanned failure/defect — counts in fault stats
    public const KIND_SERVICE    = 'service';     // 🔵 planned preventive work — excluded from fault stats
    public const KIND_INSPECTION = 'inspection';  // 🟨 a check — may spawn a separate fault
    public const KIND_DAMAGE     = 'damage';      // 🟣 externally-caused damage — billable, never a reliability signal
    public const KINDS = [self::KIND_FAULT, self::KIND_SERVICE, self::KIND_INSPECTION, self::KIND_DAMAGE];

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
        self::KIND_DAMAGE     => ['emoji' => '🟣', 'label' => 'Damage',     'tone' => 'purple'],
    ];

    /** Which *_catalog_id column backs each kind (used by the guard + catalog() resolver). */
    public const KIND_CATALOG_FK = [
        self::KIND_FAULT      => 'fault_catalog_id',
        self::KIND_SERVICE    => 'service_catalog_id',
        self::KIND_INSPECTION => 'inspection_type_id',
        self::KIND_DAMAGE     => 'damage_catalog_id',
    ];

    /** Which belongsTo relation backs each kind (used by API resources to serialise `catalog`). */
    public const KIND_CATALOG_RELATIONS = [
        self::KIND_FAULT      => 'faultCatalog',
        self::KIND_SERVICE    => 'serviceCatalog',
        self::KIND_INSPECTION => 'inspectionType',
        self::KIND_DAMAGE     => 'damageCatalog',
    ];

    /**
     * Kinds that count as evidence about the VEHICLE's own condition.
     *
     * This is the list reliability, recurrence, health, foresight and predictive maintenance filter on —
     * and it is deliberately a named concept rather than `where kind = fault` scattered across a dozen
     * services, so that adding a fifth kind one day is one edit here instead of a hunt.
     *
     * Damage is excluded BY DEFINITION: a kerbed rim is a fact about a driver, not about the car. It is
     * still costed, billed, reported and searchable — it simply is not evidence of unreliability.
     */
    public const RELIABILITY_KINDS = [self::KIND_FAULT];

    /**
     * Which kinds a reliability-shaped reader should include RIGHT NOW, given the rollout mode.
     *
     * Two different rules live here, and the difference is deliberate:
     *
     *   • DAMAGE is excluded ALWAYS. It is a new kind — no reader has ever counted a `damage` row, so
     *     there is no previous behaviour to preserve and nothing to stage. Letting the rollout flag
     *     decide whether a kerbed rim counts as a reliability signal would be inventing a wrong mode.
     *   • SERVICE is excluded only once EVENT_KIND_MODE is `enforced`, because services HAVE been counted
     *     historically and the flag exists precisely so that change is comparable before/after.
     *
     * One place, so no reader has to remember either rule.
     *
     * @return array<int,string>
     */
    public static function reliabilityKindsForMode(): array
    {
        return \App\Support\EventKind::enforced()
            ? self::RELIABILITY_KINDS
            // "everything except damage", DERIVED — a hand-written list would silently omit a fifth kind
            // and quietly start counting it as reliability evidence.
            : array_values(array_diff(self::KINDS, [self::KIND_DAMAGE]));
    }

    protected $fillable = [
        'maintenance_id', 'vehicle_id',
        // Domain classification — the primary type + its catalog source-of-truth + provenance.
        'kind', 'fault_catalog_id', 'service_catalog_id', 'inspection_type_id', 'damage_catalog_id',
        'classification_source', 'needs_review',
        // HOW MANY physical occurrences this ONE routable record covers (2 scratches = 1 task, qty 2).
        // Never a row count — see the add_quantity_to_maintenance_tasks migration for why.
        'quantity',
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

    /**
     * `kind` mirrors its column default in PHP so a new instance is never momentarily untyped.
     *
     * Laravel fires `saving` BEFORE `creating`, so the integrity guard below would otherwise see a null
     * kind on every insert — the column default only applies once the row reaches the database. Starting
     * at `fault` matches the migration default (and is the safe default: it is exactly the pre-separation
     * behaviour), and the `creating` hook immediately replaces it with the resolved or catalog-given type.
     */
    protected $attributes = [
        'kind' => self::KIND_FAULT,
    ];

    protected $casts = [
        'quantity'              => 'integer',
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
        // Event Type layer — every task is BORN classified (kind + the matching catalog id when resolvable),
        // so no creation path silently defaults to `fault`. Runs on CREATE only. An explicit catalog pick
        // (classification_source already set — e.g. type-first intake) is respected; otherwise the SHIELD
        // resolver stamps kind from the symptom + ticket context. Best-effort: a classification hiccup never
        // blocks the task being created. The DB CHECK still validates the final kind↔catalog integrity.
        static::creating(function (MaintenanceTask $t) {
            if (filled($t->classification_source)) {
                return; // already classified by the caller (catalog pick)
            }
            if (blank($t->symptom) && blank($t->maintenance_id)) {
                return; // nothing to classify on
            }
            try {
                foreach (app(\App\Services\EventClassificationService::class)->resolveLegacyKind($t) as $k => $v) {
                    $t->{$k} = $v;
                }
            } catch (\Throwable $e) {
                report($e); // never let classification sink a task creation
            }
        });

        // Findings CATEGORY — derived here because every writer forgot it, and none of them errored.
        //
        // `category_key` was NULL on all 108 rows ever written. Both creation paths read it off the
        // caller (`$f['category_key'] ?? null` in MaintenanceTaskService, `$issue['category_key'] ?? null`
        // in the re-inspection controller) and nothing upstream ever put it there — the findings JSON the
        // inspector's report builds carries text, source, severity and cause, but never a category. A
        // silent `?? null` in two places is indistinguishable from "this fault genuinely has no category".
        //
        // WHAT IT COST. The column is the middle tier of the repair-history matcher
        // (fault_catalog_id → category_key → symptom), so every lookup fell through to an EXACT symptom
        // string match — the narrowest possible test — and "Previous Similar Repairs" reported nothing
        // for faults the fleet had repaired hundreds of times. Garage routing and the recommendation
        // engine re-derive the category from the text on every call for the same reason.
        //
        // Derived on CREATE, in the model, for the same reason `kind` is: a rule that lives in the
        // writers is a rule each new writer has to remember, and the two that exist both forgot it.
        // An explicit value from the caller always wins.
        //
        // EXACT CATALOG MATCH ONLY, deliberately. A symptom the catalog does not know stays NULL rather
        // than being fuzzy-matched into a neighbouring category — a task filed under the wrong category
        // routes to the wrong garage, and silence is the cheaper error. See [[findings-vocabulary-contract]].
        static::creating(function (MaintenanceTask $t) {
            if (blank($t->category_key) && filled($t->symptom)) {
                $t->category_key = Maintenance::categoryForKeyword($t->symptom);
            }
        });

        // Classification integrity — the FIRST line of defence (the DB CHECK is the last). Exactly the
        // one *_catalog_id matching `kind` may be set; more than one is a bug; zero is allowed only as the
        // legacy/unclassified case (matches the CHECK, so flag-off behaviour is unchanged).
        static::saving(function (MaintenanceTask $t) {
            // The discriminator itself must be a known type. Checked BEFORE the early return below,
            // because the all-catalogs-null case is the overwhelming majority of rows — leaving it
            // unchecked meant `kind` was effectively a free-text column for every legacy row, and the DB
            // CHECK permits any string when the FKs are null too (audit L1).
            if (! in_array($t->kind, self::KINDS, true)) {
                throw new \DomainException(
                    "maintenance_task kind must be one of [" . implode(', ', self::KINDS) . "], got " . var_export($t->kind, true) . '.'
                );
            }

            // Derived from KIND_CATALOG_FK, never hand-listed. When this WAS hand-listed it silently
            // stopped covering `damage` the moment the fourth kind landed: a row with kind=fault and a
            // damage_catalog_id produced an EMPTY $set, took the early return below, and was accepted —
            // leaving the DB CHECK (which production's MySQL 8 cannot install) as the only defence
            // against a combination the application considers impossible.
            $set = array_filter(
                array_map(fn ($column) => $t->{$column}, self::KIND_CATALOG_FK),
                fn ($v) => $v !== null
            );

            if (count($set) === 0) {
                return; // legacy / not-yet-classified — permitted
            }
            if (count($set) > 1) {
                throw new \DomainException(
                    'A maintenance_task may reference at most one catalog (' . implode('/', self::KINDS) . ').'
                );
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

    public function damageCatalog(): BelongsTo
    {
        return $this->belongsTo(DamageCatalog::class, 'damage_catalog_id');
    }

    /** The one catalog row backing this task's kind (null when unclassified/legacy). Query-free if loaded. */
    public function catalog(): ?Model
    {
        return match ($this->kind) {
            self::KIND_FAULT      => $this->faultCatalog,
            self::KIND_SERVICE    => $this->serviceCatalog,
            self::KIND_INSPECTION => $this->inspectionType,
            self::KIND_DAMAGE     => $this->damageCatalog,
            default               => null,
        };
    }

    /**
     * The recurring ServiceReminder type this event closes when it is marked fixed, or null if it closes
     * none. THE single answer to "does completing this roll a service forward?" — used by the workflow
     * (confirmRoutineServices), the re-inspection gate and the API resource, so all three agree.
     *
     * Resolution order, authoritative first:
     *   1. `service_catalog.service_reminder_type` — the typed link the catalog exists to provide.
     *   2. Legacy text match, ONLY for a `kind=service` row with no catalog id (pre-catalog history).
     *   3. Anything else → null.
     *
     * A fault or an inspection returns null even when its wording resembles a service. That is the whole
     * point of the type: "Oil Change" written on a fault row must not stamp the car as serviced (audit C2).
     * The text path survives only as the legacy shim in case 2 and is what will be deleted once every row
     * carries a catalog id.
     */
    public function serviceReminderType(): ?string
    {
        if (! $this->isService()) {
            return null;
        }

        if ($this->service_catalog_id) {
            $catalog = $this->relationLoaded('serviceCatalog') ? $this->serviceCatalog : $this->serviceCatalog()->first();
            if ($catalog) {
                return $catalog->service_reminder_type ?: null;
            }
        }

        return Maintenance::serviceTypeForSymptom($this->symptom);
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

    /**
     * What the INSPECTOR said this fault would need — technical requirements only. They precede the part
     * requests above: the coordinator converts them once the garage is known.
     * See {@see \App\Services\MaintenanceRequiredPartService}.
     */
    public function requiredParts(): HasMany
    {
        return $this->hasMany(MaintenanceRequiredPart::class, 'maintenance_task_id');
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

    /**
     * WHERE on the car this event is — none, one, or several, in the order the inspector picked them.
     *
     * The third axis beside WHAT (`kind` + its catalog) and HOW BAD (`severity`), and deliberately
     * type-agnostic: a scratch, a dent, a crack and a fault type invented next year all use this same
     * relation. Empty is a valid and common answer — every fault recorded before this existed has no
     * rows here, which reads as "we do not know where it was" rather than as missing data.
     *
     * Written only via {@see \App\Services\FaultLocationService::sync()}.
     */
    public function locations(): HasMany
    {
        return $this->hasMany(MaintenanceTaskLocation::class, 'maintenance_task_id')->orderBy('sort_order');
    }

    /**
     * The one operational sentence for this event: "2 scratches — rims and body".
     *
     * A convenience over {@see \App\Services\FaultLocationService::describe()} so a caller holding a
     * task does not have to resolve the service; the formatting itself still happens in exactly one
     * place ({@see \App\Support\FaultPhrase}) for every surface that prints a fault.
     */
    public function describe(string $locale = 'en'): string
    {
        return app(\App\Services\FaultLocationService::class)->describe($this, $locale);
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

    public function scopeDamages(Builder $q): Builder
    {
        return $q->where('kind', self::KIND_DAMAGE);
    }

    public function scopeOfKind(Builder $q, string ...$kinds): Builder
    {
        return $q->whereIn('kind', $kinds);
    }

    /**
     * Events that are evidence about the VEHICLE — the scope every reliability-shaped query should use.
     *
     * Prefer this over ->faults() in analytics: it states the INTENT ("things that tell me about this
     * car") rather than naming a kind, so the rule lives in reliabilityKindsForMode() and a new kind is
     * one edit rather than a hunt through a dozen services. Damage is always excluded; services follow
     * the rollout flag.
     */
    public function scopeAffectingReliability(Builder $q): Builder
    {
        return $q->whereIn('kind', self::reliabilityKindsForMode());
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

    public function isDamage(): bool
    {
        return $this->kind === self::KIND_DAMAGE;
    }

    /**
     * Does this event say anything about how RELIABLE the vehicle is?
     *
     * The one predicate every reliability-shaped reader should ask — health, recurrence, foresight,
     * predictive maintenance, part-recurrence. Only a fault qualifies: a service was planned, an
     * inspection is a look, and damage is a fact about a driver rather than about the car.
     */
    public function affectsReliability(): bool
    {
        return in_array($this->kind, self::RELIABILITY_KINDS, true);
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
