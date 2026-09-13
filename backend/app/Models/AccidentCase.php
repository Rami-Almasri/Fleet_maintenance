<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * THE ACCIDENT CASE — opened the moment a car is reported damaged in an incident, closed when four
 * separate questions all have answers: what happened, whose fault it was, what the insurer will pay,
 * and whether the car is fixed.
 *
 * Evidence class: F (fact capture) carrying two J (judgement) fields — the liability verdict and the
 * coverage/settlement figures, both of which are named humans' decisions and are stored as such.
 * Produces: accident_cases, accident_damage_items, accident_financial_entries, vehicle_log_events,
 * vehicle_documents. Consumes: vehicles, contracts, customers, maintenances, damage_catalog.
 *
 * ── FOUR AXES, AND THEY ARE NOT ONE AXIS ───────────────────────────────────────────────────────
 *
 *   `stage`            where the CASE is        ours to advance; the board's columns.
 *   `police_status`    what the PAPERWORK says  missing until somebody produces it (or bypasses it).
 *   `liability_status` whose FAULT it was       `pending` until a human decides. Never inferred.
 *   `claim_status`     what the INSURER says    theirs to move; we only record it.
 *
 * The car's own availability is a fifth thing and lives where it always has — on the vehicle, driven
 * by the maintenance workflow. This model does not write it. What it does do is answer
 * `restrictsRental()`, which ContractEligibilityService reads as a hard block, so a car with an
 * unresolved accident cannot be re-let by somebody who never opened this page.
 *
 * ── WHAT THIS MODEL WILL NOT DO ────────────────────────────────────────────────────────────────
 *
 * It will not close a rental contract, and there is no code path here that touches `contracts`. The
 * car being off the road and the customer still being on hire are two true facts at once; the
 * charges run on the contract's own rules and the accident's money is settled separately. Collapsing
 * them was the single most expensive thing this feature could have done.
 *
 * It will not assume the customer is liable because the customer was driving. `liability_status`
 * defaults to `pending` and only a human with `accidents.liability` moves it.
 */
class AccidentCase extends Model
{
    use SoftDeletes;

    // ── STAGE: where WE are ────────────────────────────────────────────────────────────────────
    /** Reported. Somebody has told us; almost nothing is known yet. */
    public const STAGE_REPORTED        = 'reported';
    /** The paperwork is outstanding. The first real gate, and the most commonly stuck one. */
    public const STAGE_AWAITING_POLICE = 'awaiting_police';
    /** Somebody is looking at the car and writing down what is broken. */
    public const STAGE_ASSESSMENT      = 'assessment';
    /** Whose fault was it? Open until a named person answers. */
    public const STAGE_LIABILITY       = 'liability';
    /** With the insurer. */
    public const STAGE_INSURANCE       = 'insurance';
    /** The car is being (or has been) repaired — the maintenance workflow owns this part. */
    public const STAGE_REPAIR          = 'repair';
    /** The money. Who owed what, and whether it arrived. */
    public const STAGE_SETTLEMENT      = 'settlement';
    /** Finished. */
    public const STAGE_CLOSED          = 'closed';

    /**
     * The ladder, in the order a case travels it — which is also the order the progress bar renders.
     *
     * A case does NOT have to climb every rung: a car scraped in our own yard bypasses the police
     * report and may never see an insurer. The ladder is the shape of the journey, not a checklist
     * every case must complete, and advance() enforces only that it moves FORWARD.
     */
    public const STAGES = [
        self::STAGE_REPORTED,
        self::STAGE_AWAITING_POLICE,
        self::STAGE_ASSESSMENT,
        self::STAGE_LIABILITY,
        self::STAGE_INSURANCE,
        self::STAGE_REPAIR,
        self::STAGE_SETTLEMENT,
        self::STAGE_CLOSED,
    ];

    /** Somebody still owes somebody something. Everything except the end of the ladder. */
    public const OPEN_STAGES = [
        self::STAGE_REPORTED, self::STAGE_AWAITING_POLICE, self::STAGE_ASSESSMENT,
        self::STAGE_LIABILITY, self::STAGE_INSURANCE, self::STAGE_REPAIR, self::STAGE_SETTLEMENT,
    ];

    /**
     * The stages where the CAR is still compromised, so it must not be offered to a renter.
     *
     * Deliberately NOT the whole open set. A case sitting at `settlement` is an argument about money
     * with a repaired car standing in the yard; grounding it would cost real rental days over an
     * insurer's paperwork. Repair is where the car itself is out, and the maintenance workflow
     * already grounds it there — this list is what stops it being let while nobody has yet looked at
     * the damage. @see \App\Services\ContractEligibilityService
     */
    public const RENTAL_BLOCKING_STAGES = [
        self::STAGE_REPORTED, self::STAGE_AWAITING_POLICE, self::STAGE_ASSESSMENT,
        self::STAGE_LIABILITY, self::STAGE_INSURANCE, self::STAGE_REPAIR,
    ];

    // ── WHO HAD THE CAR ────────────────────────────────────────────────────────────────────────
    public const PARTY_RENTAL_CUSTOMER   = 'rental_customer';
    public const PARTY_EMPLOYEE          = 'employee';
    public const PARTY_AUTHORIZED_DRIVER = 'authorized_driver';
    public const PARTY_TEST_DRIVER       = 'test_driver';
    public const PARTY_WORKSHOP          = 'workshop';
    public const PARTY_LOGISTICS         = 'logistics';
    /** Nobody was driving it — parked, hit in a car park, vandalised. A real and common answer. */
    public const PARTY_PARKED            = 'parked';
    public const PARTY_UNKNOWN           = 'unknown';
    public const PARTY_OTHER             = 'other';

    public const RESPONSIBLE_PARTY_TYPES = [
        self::PARTY_RENTAL_CUSTOMER, self::PARTY_EMPLOYEE, self::PARTY_AUTHORIZED_DRIVER,
        self::PARTY_TEST_DRIVER, self::PARTY_WORKSHOP, self::PARTY_LOGISTICS,
        self::PARTY_PARKED, self::PARTY_UNKNOWN, self::PARTY_OTHER,
    ];

    // ── HOW IT HAPPENED ────────────────────────────────────────────────────────────────────────
    public const ACCIDENT_TYPES = [
        'collision', 'rear_end', 'side_impact', 'head_on', 'single_vehicle', 'rollover',
        'parked_hit', 'pedestrian', 'animal', 'flood', 'fire', 'vandalism', 'other',
    ];

    // ── THE POLICE REPORT ──────────────────────────────────────────────────────────────────────
    public const POLICE_MISSING  = 'missing';
    public const POLICE_RECORDED = 'recorded';   // number + date captured, not yet checked by us
    public const POLICE_VERIFIED = 'verified';   // somebody has read it against the file
    public const POLICE_BYPASSED = 'bypassed';   // waived on purpose, with a name and a reason
    public const POLICE_STATUSES = [self::POLICE_MISSING, self::POLICE_RECORDED, self::POLICE_VERIFIED, self::POLICE_BYPASSED];

    /** Statuses that satisfy the documentation gate — one earned, one consciously waived. */
    public const POLICE_SATISFIED = [self::POLICE_VERIFIED, self::POLICE_BYPASSED];

    // ── LIABILITY ──────────────────────────────────────────────────────────────────────────────
    public const LIABILITY_PENDING     = 'pending';
    public const LIABILITY_CUSTOMER    = 'customer';
    public const LIABILITY_OTHER_PARTY = 'other_party';
    public const LIABILITY_COMPANY     = 'company';
    public const LIABILITY_EMPLOYEE    = 'employee';
    public const LIABILITY_SHARED      = 'shared';
    public const LIABILITY_UNKNOWN     = 'unknown';   // investigated, and genuinely not establishable
    public const LIABILITY_STATUSES = [
        self::LIABILITY_PENDING, self::LIABILITY_CUSTOMER, self::LIABILITY_OTHER_PARTY,
        self::LIABILITY_COMPANY, self::LIABILITY_EMPLOYEE, self::LIABILITY_SHARED, self::LIABILITY_UNKNOWN,
    ];

    /** Where the verdict came from. A liability decision with no stated source is an opinion. */
    public const LIABILITY_SOURCES = ['police_report', 'insurance', 'internal', 'legal', 'other'];

    // ── THE INSURER'S SIDE ─────────────────────────────────────────────────────────────────────
    public const CLAIM_NOT_SUBMITTED      = 'not_submitted';
    public const CLAIM_PREPARING          = 'preparing';
    public const CLAIM_SUBMITTED          = 'submitted';
    public const CLAIM_UNDER_REVIEW       = 'under_review';
    public const CLAIM_INFO_REQUIRED      = 'info_required';
    public const CLAIM_APPROVED           = 'approved';
    public const CLAIM_PARTIALLY_APPROVED = 'partially_approved';
    public const CLAIM_REJECTED           = 'rejected';
    public const CLAIM_CLOSED             = 'closed';
    public const CLAIM_STATUSES = [
        self::CLAIM_NOT_SUBMITTED, self::CLAIM_PREPARING, self::CLAIM_SUBMITTED, self::CLAIM_UNDER_REVIEW,
        self::CLAIM_INFO_REQUIRED, self::CLAIM_APPROVED, self::CLAIM_PARTIALLY_APPROVED,
        self::CLAIM_REJECTED, self::CLAIM_CLOSED,
    ];

    /** The insurer owes us an answer. The chase list. */
    public const CLAIM_AWAITING_INSURER = [self::CLAIM_SUBMITTED, self::CLAIM_UNDER_REVIEW, self::CLAIM_INFO_REQUIRED];

    /**
     * `stage`, `reference`, every decision stamp and every *_by column are ABSENT on purpose.
     *
     * They are the state machine and the audit trail, and both belong to AccidentCaseService — the
     * one write choke point that also writes the timeline event and the audit meta. A mass-assigned
     * `liability_status` would let a request body settle who pays for a crash with nobody's name on
     * the decision, which is the exact failure this whole feature exists to prevent.
     */
    protected $fillable = [
        'vehicle_id',
        'occurred_at', 'location', 'description', 'odometer',
        'accident_type',
        'other_party_involved', 'other_party_name', 'other_party_phone', 'other_party_plate',
        'other_party_insurer', 'other_party_policy_no', 'other_party_note',
        'drivable', 'towing_required', 'safety_concerns',
        'driver_name', 'driver_phone', 'driver_user_id', 'responsible_party_note',
        'insurer_vendor_id', 'insurer_name', 'policy_no',
        'insurance_contact_name', 'insurance_contact_phone', 'insurance_contact_email',
        'currency',
    ];

    protected $casts = [
        'reported_at'                 => 'datetime',
        'occurred_at'                 => 'datetime',
        'odometer'                    => 'integer',
        'context_detected'            => 'boolean',
        'context_snapshot'            => 'array',
        'contract_out_date_snapshot'  => 'date',
        'contract_in_date_snapshot'   => 'date',
        'other_party_involved'        => 'boolean',
        'drivable'                    => 'boolean',
        'towing_required'             => 'boolean',
        'assessed_at'                 => 'datetime',
        'police_report_date'          => 'date',
        'police_recorded_at'          => 'datetime',
        'police_verified_at'          => 'datetime',
        'police_bypassed_at'          => 'datetime',
        'liability_share_pct'         => 'integer',
        'liability_decided_at'        => 'datetime',
        'claim_submitted_at'          => 'datetime',
        'claim_response_due_on'       => 'date',
        'insurance_updated_at'        => 'datetime',
        'closed_at'                   => 'datetime',
        'reopened_at'                 => 'datetime',
    ];

    // ── relations ──────────────────────────────────────────────────────────────────────────────

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * The rental the car was on when it happened — the LIVE link, which may be closed by now and may
     * one day be deleted. Read the *_snapshot columns for what was true at the time; this is here so
     * the case can still deep-link to a contract that still exists.
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function driverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function insurerVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'insurer_vendor_id');
    }

    public function damageItems(): HasMany
    {
        return $this->hasMany(AccidentDamageItem::class);
    }

    /** Every figure ever recorded, superseded ones included. @see liveFinancials() for the current set. */
    public function financialEntries(): HasMany
    {
        return $this->hasMany(AccidentFinancialEntry::class);
    }

    /** The current picture — one live row per (phase, party). */
    public function liveFinancials(): HasMany
    {
        return $this->hasMany(AccidentFinancialEntry::class)->whereNull('superseded_at');
    }

    /** The repair tickets this accident caused. Ordinary maintenance tickets, parented here. */
    public function repairs(): HasMany
    {
        return $this->hasMany(Maintenance::class, 'accident_case_id');
    }

    /** The dossier — police report, photos, insurer decisions, estimates, invoices. */
    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class, 'accident_case_id');
    }

    /**
     * THE CUSTOMER CHARGE — the manual invoice that put this accident on the renter's account.
     *
     * A hasOne rather than a hasMany, matching the unique index: an accident is billed once or not
     * at all. Withdrawing a charge removes the invoice, so the absence of this relation is the
     * honest state "not currently billed" rather than "never was" — the timeline holds the history.
     */
    public function customerCharge(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Invoice::class, 'accident_case_id');
    }

    /** This case's own slice of the car's timeline. */
    public function timeline(): HasMany
    {
        return $this->hasMany(VehicleLogEvent::class, 'accident_case_id')->orderBy('occurred_at');
    }

    // ── scopes ─────────────────────────────────────────────────────────────────────────────────

    public function scopeOpenCases(Builder $q): Builder
    {
        return $q->whereIn('stage', self::OPEN_STAGES);
    }

    public function scopeForVehicle(Builder $q, int $vehicleId): Builder
    {
        return $q->where('vehicle_id', $vehicleId);
    }

    /** Cases whose car must not be let. The query behind the rental-eligibility block. */
    public function scopeRentalBlocking(Builder $q): Builder
    {
        return $q->whereIn('stage', self::RENTAL_BLOCKING_STAGES);
    }

    public function scopeAwaitingPolice(Builder $q): Builder
    {
        return $q->whereIn('police_status', [self::POLICE_MISSING, self::POLICE_RECORDED])
            ->whereIn('stage', self::OPEN_STAGES);
    }

    public function scopeAwaitingLiability(Builder $q): Builder
    {
        return $q->where('liability_status', self::LIABILITY_PENDING)->whereIn('stage', self::OPEN_STAGES);
    }

    public function scopeAwaitingInsurer(Builder $q): Builder
    {
        return $q->whereIn('claim_status', self::CLAIM_AWAITING_INSURER);
    }

    // ── questions the UI and the guards ask ────────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return in_array($this->stage, self::OPEN_STAGES, true);
    }

    public function isClosed(): bool
    {
        return $this->stage === self::STAGE_CLOSED;
    }

    /** Must this car stay out of the rental pool? @see RENTAL_BLOCKING_STAGES for why not all of them. */
    public function restrictsRental(): bool
    {
        return in_array($this->stage, self::RENTAL_BLOCKING_STAGES, true);
    }

    /** Was the car on hire when it happened? The banner the whole detail page leads with. */
    public function wasWithCustomer(): bool
    {
        return $this->responsible_party_type === self::PARTY_RENTAL_CUSTOMER
            && ($this->customer_ref !== null || $this->customer_name_snapshot !== null);
    }

    /** Has the documentation gate been satisfied — earned or consciously waived? */
    public function policeSatisfied(): bool
    {
        return in_array($this->police_status, self::POLICE_SATISFIED, true);
    }

    /** Has anybody actually decided whose fault it was? `unknown` counts — it is a decided answer. */
    public function liabilityDecided(): bool
    {
        return $this->liability_status !== self::LIABILITY_PENDING;
    }

    /** Has the insurer gone quiet past the date they were expected to answer? */
    public function insurerOverdue(?\DateTimeInterface $on = null): bool
    {
        if (! in_array($this->claim_status, self::CLAIM_AWAITING_INSURER, true) || ! $this->claim_response_due_on) {
            return false;
        }

        return $this->claim_response_due_on->lt(
            $on ? \Illuminate\Support\Carbon::parse($on)->startOfDay() : now()->startOfDay()
        );
    }

    /** Where this stage sits on the ladder — what the progress bar counts. */
    public function stageIndex(): int
    {
        $i = array_search($this->stage, self::STAGES, true);

        return $i === false ? 0 : $i;
    }

    /**
     * THE MONEY, ASSEMBLED — the live entries folded into the breakdown the page renders.
     *
     * Phases are kept APART and never summed across: an estimate is not an approval and an approval
     * is not a payment. Within a phase the parties add up to that phase's total, and `unresolved` is
     * carried as its own party so the total never quietly shrinks to the part somebody has decided.
     *
     * @return array{currency:string, phases:array<string,array{total:float, by_party:array<string,float>}>,
     *               estimated:float, approved:float, actual:float, paid:float, unresolved:float}
     */
    public function financialBreakdown(): array
    {
        $rows = $this->relationLoaded('liveFinancials')
            ? $this->liveFinancials
            : $this->liveFinancials()->get();

        $phases = [];
        foreach ($rows as $row) {
            $phase = $row->phase;
            $phases[$phase] ??= ['total' => 0.0, 'by_party' => []];
            $amount = (float) $row->amount;
            $phases[$phase]['total'] += $amount;
            $phases[$phase]['by_party'][$row->party] = ($phases[$phase]['by_party'][$row->party] ?? 0.0) + $amount;
        }

        // A revised estimate REPLACES the original as "the estimate" — that is what revising means.
        // The original is still on the ledger and still readable; it is simply no longer the figure.
        $estimate = $phases[AccidentFinancialEntry::PHASE_REVISED_ESTIMATE]['total']
            ?? ($phases[AccidentFinancialEntry::PHASE_ESTIMATE]['total'] ?? 0.0);

        return [
            'currency'   => $this->currency ?: 'AED',
            'phases'     => $phases,
            'estimated'  => round((float) $estimate, 2),
            'approved'   => round((float) ($phases[AccidentFinancialEntry::PHASE_APPROVED]['total'] ?? 0), 2),
            'actual'     => round((float) ($phases[AccidentFinancialEntry::PHASE_ACTUAL]['total'] ?? 0), 2),
            'paid'       => round((float) ($phases[AccidentFinancialEntry::PHASE_PAID]['total'] ?? 0), 2),
            // What nobody has taken responsibility for yet, across every phase. The number a total
            // that only counted decided amounts would hide.
            'unresolved' => round((float) collect($phases)
                ->sum(fn ($p) => $p['by_party'][AccidentFinancialEntry::PARTY_UNRESOLVED] ?? 0), 2),
        ];
    }
}
