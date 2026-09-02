<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * THE WARRANTY CASE — the file opened the moment somebody suspects this is not ours to pay for, and
 * closed when that question has an answer either way.
 *
 * It began life as the narrower thing its table name still says: the record of one time we went back
 * to a counterparty. That is still the END of the story, and everything below about frozen windows
 * and rejected claims is still exactly true. What was added is the part BEFORE the answer — the
 * coverage review, the authorisation, the dealer leg — because "we don't know whether they owe us
 * this" needed to be a work item assigned to somebody rather than a shrug that quietly resolves
 * itself into a purchase order. See the add_case_lifecycle migration for why that lives on this row
 * rather than in a second table.
 *
 * TWO AXES, AND THEY ARE NOT THE SAME AXIS:
 *   `stage`   where WE are   — ours to advance, and what the board columns are.
 *   `outcome` what THEY said — pending → accepted | rejected | partial. Unchanged, and still the
 *             thing the recovered-money scope reads.
 *
 * ── the original note, still load-bearing ──────────────────────────────────────────────────────
 *
 * A claim is separate from its warranty because the relationship is genuinely one-to-many, and
 * because a REJECTED claim is the most valuable row in this table — it is the evidence that a
 * supplier does not honour what he sells. Collapsing claims into columns on the warranty would keep
 * only the latest attempt and erase exactly that.
 *
 * `was_in_window` and `window_evidence` are FROZEN at claim time, computed by WarrantyService
 * against the odometer of that moment. They are deliberately not recomputed on read: "was it in
 * date when it failed" must not change its answer six months later when the car has done another
 * 40,000 km. That is the whole point of writing it down.
 */
class WarrantyClaim extends Model
{
    use SoftDeletes;

    // ── STAGE: where WE are. Ours to advance. ──────────────────────────────────────────────────
    //
    // The ladder, and what each rung means operationally. Not every case climbs every rung — a
    // coverage review that comes back not_covered stops at NOT_COVERED and never sees a dealer, which
    // is the most common ending and a completely successful one. The rungs exist so that at any
    // moment the answer to "what is somebody supposed to do next?" is a column, not a conversation.

    /** Somebody suspects this might be someone else's problem. Nothing has been decided. */
    public const STAGE_IDENTIFIED = 'identified';
    /** The question is with the warranty desk. THE STAGE THIS WHOLE FEATURE EXISTS TO CREATE. */
    public const STAGE_COVERAGE_REVIEW = 'coverage_review';
    /** A human confirmed it is covered. Procurement stays shut; the dealer route opens. */
    public const STAGE_COVERED = 'covered';
    /**
     * A human confirmed it is ours to pay. TERMINAL, and a success: it is the record that releases
     * procurement, and the proof the question was asked before the money moved.
     */
    public const STAGE_NOT_COVERED = 'not_covered';
    /** We have asked the provider for a go-ahead. */
    public const STAGE_AUTHORIZATION_REQUESTED = 'authorization_requested';
    /** They gave it, with a reference. Work started without this is work they can refuse to pay for. */
    public const STAGE_AUTHORIZED = 'authorized';
    /** The car (or the part) is with the provider. */
    public const STAGE_SENT_TO_PROVIDER = 'sent_to_provider';
    public const STAGE_REPAIR_IN_PROGRESS = 'repair_in_progress';
    public const STAGE_REPAIR_COMPLETED = 'repair_completed';
    /** The paperwork is in. From here `outcome` — THEIR answer — is what moves. */
    public const STAGE_CLAIM_SUBMITTED = 'claim_submitted';
    /** Money or work recovered, and what we avoided spending, written down. */
    public const STAGE_RECOVERY_RECORDED = 'recovery_recorded';
    /** Finished, whatever the answer was. */
    public const STAGE_CLOSED = 'closed';

    /** Board column order — the sequence a case actually travels. */
    public const STAGES = [
        self::STAGE_IDENTIFIED,
        self::STAGE_COVERAGE_REVIEW,
        self::STAGE_COVERED,
        self::STAGE_NOT_COVERED,
        self::STAGE_AUTHORIZATION_REQUESTED,
        self::STAGE_AUTHORIZED,
        self::STAGE_SENT_TO_PROVIDER,
        self::STAGE_REPAIR_IN_PROGRESS,
        self::STAGE_REPAIR_COMPLETED,
        self::STAGE_CLAIM_SUBMITTED,
        self::STAGE_RECOVERY_RECORDED,
        self::STAGE_CLOSED,
    ];

    /**
     * Stages where somebody still owes somebody something.
     *
     * NOT_COVERED is deliberately NOT here: the question was answered, the answer was "ours", and
     * there is nothing further to chase. It is not an abandoned case and must not sit on the board
     * looking like one. CLOSED is likewise finished.
     */
    public const OPEN_STAGES = [
        self::STAGE_IDENTIFIED,
        self::STAGE_COVERAGE_REVIEW,
        self::STAGE_COVERED,
        self::STAGE_AUTHORIZATION_REQUESTED,
        self::STAGE_AUTHORIZED,
        self::STAGE_SENT_TO_PROVIDER,
        self::STAGE_REPAIR_IN_PROGRESS,
        self::STAGE_REPAIR_COMPLETED,
        self::STAGE_CLAIM_SUBMITTED,
        self::STAGE_RECOVERY_RECORDED,
    ];

    /** The stages where the ball is in the PROVIDER's court — the ones worth chasing them over. */
    public const AWAITING_PROVIDER_STAGES = [
        self::STAGE_AUTHORIZATION_REQUESTED,
        self::STAGE_SENT_TO_PROVIDER,
        self::STAGE_REPAIR_IN_PROGRESS,
        self::STAGE_CLAIM_SUBMITTED,
    ];

    // ── ORIGIN: why this file was opened at all ────────────────────────────────────────────────
    public const ORIGIN_MAINTENANCE   = 'maintenance';    // a fault on a ticket
    public const ORIGIN_PROCUREMENT   = 'procurement';    // a purchase request the gate stopped
    public const ORIGIN_SPARE_KEY     = 'spare_key';      // a car needs a key
    public const ORIGIN_INSPECTION    = 'inspection';     // the pre-expiry inspection found something
    public const ORIGIN_COMPONENT     = 'component';      // a fitted part failed
    public const ORIGIN_MANUAL        = 'manual';         // somebody typed it in
    public const ORIGINS = [
        self::ORIGIN_MAINTENANCE, self::ORIGIN_PROCUREMENT, self::ORIGIN_SPARE_KEY,
        self::ORIGIN_INSPECTION, self::ORIGIN_COMPONENT, self::ORIGIN_MANUAL,
    ];

    public const OUTCOME_PENDING  = 'pending';
    public const OUTCOME_ACCEPTED = 'accepted';
    public const OUTCOME_REJECTED = 'rejected';
    public const OUTCOME_PARTIAL  = 'partial';
    public const OUTCOMES = [
        self::OUTCOME_PENDING, self::OUTCOME_ACCEPTED, self::OUTCOME_REJECTED, self::OUTCOME_PARTIAL,
    ];

    /** What we actually got back. 'none' is a real answer, not a missing one. */
    public const REMEDIES = ['replacement', 'repair', 'credit', 'refund', 'none'];

    /** Outcomes that are finished — anything else is still owed us an answer. */
    public const RESOLVED_OUTCOMES = [self::OUTCOME_ACCEPTED, self::OUTCOME_REJECTED, self::OUTCOME_PARTIAL];

    /**
     * `stage`, `coverage_verdict` and the decision/authorisation/closure stamps are deliberately
     * ABSENT: they are the state machine, and they are set by explicit assignment inside
     * WarrantyCaseService — the single write choke point that owns the transitions, the audit events
     * and the notifications. A mass-assigned `stage` would let a request body claim a case was
     * authorised with no dealer behind it.
     */
    protected $fillable = [
        'warranty_id', 'vehicle_id', 'maintenance_id', 'maintenance_task_id',
        'vehicle_component_id', 'component_catalog_id',
        'origin', 'subject', 'diagnosis',
        'failure_description', 'claimed_on', 'claim_odometer',
        'was_in_window', 'window_evidence',
        'outcome', 'outcome_reason', 'resolved_on',
        'recovered_amount', 'avoided_amount', 'currency', 'remedy',
        'authorization_ref', 'claim_reference', 'provider_response_due_on',
        'created_by', 'created_by_name',
    ];

    protected $casts = [
        'claimed_on'       => 'date',
        'resolved_on'      => 'date',
        'claim_odometer'   => 'integer',
        'was_in_window'    => 'boolean',
        'recovered_amount' => 'decimal:2',
        // What we did NOT have to spend. Kept apart from recovered_amount on purpose: a dealer
        // replacing a gearbox for free recovers nothing and avoids a great deal, and adding the two
        // together would double-count every case where both happen.
        'avoided_amount'   => 'decimal:2',
        'decided_at'                 => 'datetime',
        'authorization_requested_at' => 'datetime',
        'authorized_at'              => 'datetime',
        'sent_to_provider_at'        => 'datetime',
        'provider_response_due_on'   => 'date',
        'submitted_at'               => 'datetime',
        'closed_at'                  => 'datetime',
    ];

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    /** The fault the provider is being asked about — what a dealer will want described. */
    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    /** The physical thing that failed, when it is one we track as an asset. */
    public function component(): BelongsTo
    {
        return $this->belongsTo(VehicleComponent::class, 'vehicle_component_id');
    }

    /** The part TYPE — what makes "which parts do dealers actually honour?" a group-by. */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(ComponentCatalog::class, 'component_catalog_id');
    }

    /** Whoever decided the coverage question. The name on the money decision. */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Every purchase request this case stands in front of (or was raised alongside). */
    public function partRequests(): HasMany
    {
        return $this->hasMany(PartRequest::class, 'warranty_case_id');
    }

    /** The evidence dossier: photos, diagnostic reports, the dealer's authorisation, the invoice. */
    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class, 'warranty_claim_id');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('outcome', self::OUTCOME_PENDING);
    }

    /** Still owed an action by somebody. Excludes not_covered — see OPEN_STAGES. */
    public function scopeOpenCases(Builder $q): Builder
    {
        return $q->whereIn('stage', self::OPEN_STAGES);
    }

    /** The reviews holding procurement up. The queue the warranty desk works. */
    public function scopeAwaitingReview(Builder $q): Builder
    {
        return $q->where('stage', self::STAGE_COVERAGE_REVIEW);
    }

    /** The ball is in the provider's court. The chase list. */
    public function scopeAwaitingProvider(Builder $q): Builder
    {
        return $q->whereIn('stage', self::AWAITING_PROVIDER_STAGES);
    }

    public function scopeForVehicle(Builder $q, int $vehicleId): Builder
    {
        return $q->where('vehicle_id', $vehicleId);
    }

    /** Money actually recovered — accepted and partial claims only; a rejection recovers nothing. */
    public function scopeRecovered(Builder $q): Builder
    {
        return $q->whereIn('outcome', [self::OUTCOME_ACCEPTED, self::OUTCOME_PARTIAL]);
    }

    public function isResolved(): bool
    {
        return in_array($this->outcome, self::RESOLVED_OUTCOMES, true);
    }

    /** Does somebody still owe an action on this case? */
    public function isOpen(): bool
    {
        return in_array($this->stage, self::OPEN_STAGES, true);
    }

    /** Is this case what is standing between a purchase request and a purchase order? */
    public function blocksProcurement(): bool
    {
        return in_array($this->stage, [self::STAGE_COVERAGE_REVIEW, self::STAGE_COVERED], true)
            || in_array($this->stage, self::AWAITING_PROVIDER_STAGES, true);
    }

    /**
     * Has the provider gone quiet past the date they were expected to answer?
     *
     * Undated cases are NEVER overdue. That is deliberate: inventing an SLA nobody agreed to would
     * manufacture a daily alert out of nothing, and an alert that is always on is an alert nobody
     * reads. WarrantyCaseService fills the date from config when a case is sent, so "undated" means
     * a case that was moved by hand without one — visible on the board, silent in the bell.
     */
    public function providerOverdue(?\DateTimeInterface $on = null): bool
    {
        if (! in_array($this->stage, self::AWAITING_PROVIDER_STAGES, true) || ! $this->provider_response_due_on) {
            return false;
        }

        return $this->provider_response_due_on->lt(
            $on ? \Illuminate\Support\Carbon::parse($on)->startOfDay() : now()->startOfDay()
        );
    }

    /**
     * Everything this case got us, as one figure, for the tiles.
     *
     * Recovered AND avoided, added — which is safe here and unsafe in the database precisely because
     * they are two columns: a case that got a AED 400 credit and a AED 9,000 free gearbox is worth
     * AED 9,400 to the fleet, and the only way to say so without double-counting is to keep the two
     * facts separate and add them at the point of reporting.
     */
    public function totalBenefit(): float
    {
        return (float) ($this->recovered_amount ?? 0) + (float) ($this->avoided_amount ?? 0);
    }
}
