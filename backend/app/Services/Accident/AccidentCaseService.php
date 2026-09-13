<?php

namespace App\Services\Accident;

use App\Models\AccidentCase;
use App\Models\AccidentDamageItem;
use App\Models\AccidentFinancialEntry;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleLogEvent;
use App\Services\MaintenanceWorkflowService;
use App\Services\NotificationScanner;
use App\Services\VehicleLogService;
use App\Support\AccidentResponsibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EVERY WRITE TO AN ACCIDENT CASE GOES THROUGH HERE.
 *
 * Evidence class: F (fact capture) carrying two J (judgement) writes — the liability verdict and the
 * settlement figures, each of which is a named human's decision and is refused without an actor.
 * Produces: accident_cases, accident_damage_items, accident_financial_entries, vehicle_log_events,
 * notifications. Consumes: vehicles, contracts, customers, maintenances, damage_catalog.
 *
 * ── SIX RULES THAT MUST HOLD NO MATTER WHICH DOOR A CASE IS TOUCHED THROUGH ────────────────────
 *
 *  1. A CHANGE IS TWO WRITES, ALWAYS — the row, and the vehicle timeline. There is no path here
 *     that alters a case without leaving a dated, attributed line on the car's history. The audit
 *     trail is not a feature of this service; it is the reason it exists as one.
 *
 *  2. THE RENTAL CONTRACT IS NEVER TOUCHED. Search this file for `Contract::` and you will find
 *     reads only. A car being wrecked does not end a hire: the customer is still on contract, the
 *     charges still run on the contract's own rules, and the accident's money is settled separately
 *     and later. Closing the contract here would destroy the company's own claim.
 *
 *  3. LIABILITY IS NEVER INFERRED. It starts `pending` and only setLiability() moves it, only with
 *     an actor, and only with a stated source. The customer having been at the wheel is not evidence
 *     of anything, and the most expensive mistake this feature could make is to imply that it is.
 *
 *  4. A REQUIRED DOCUMENT MAY BE WAIVED, NEVER SKIPPED. bypassPoliceReport() exists because a car
 *     scraped in our own yard has no police report and never will — but it demands a reason, records
 *     who waived it, and the case wears `police_status = bypassed` for the rest of its life. What is
 *     forbidden is the SILENT version: advancing past the gate with the field simply left empty.
 *
 *  5. MONEY IS APPENDED, NEVER OVERWRITTEN. recordFinancial() supersedes; it does not update. An
 *     estimate that was revised is still readable, because the revision is the argument.
 *
 *  6. A CLOSED CASE IS FROZEN. Every mutator below refuses on a closed case. reopen() is the one way
 *     back in, it needs `accidents.override`, and it needs a reason.
 *
 * NOTIFICATIONS GO TO CAPABILITIES, NEVER TO PEOPLE. @see \App\Support\AccidentResponsibility.
 */
class AccidentCaseService
{
    public function __construct(
        private VehicleLogService $log,
        private NotificationScanner $notifier,
        private AccidentContextResolver $context,
        private MaintenanceWorkflowService $workflow,
    ) {}

    // ══ OPENING ═══════════════════════════════════════════════════════════════════════════════

    /**
     * REPORT AN ACCIDENT — the one entry point, and the most important method in the feature.
     *
     * It is deliberately forgiving about detail and uncompromising about two things: the car, and
     * when it happened. Everything else may arrive later through the workflow stages. A crash that
     * goes unrecorded because the form demanded a police report number at the roadside is the worst
     * outcome available, so the intake asks for what the reporter can actually answer.
     *
     * The context resolution happens HERE and only here — who had the car, frozen onto the row. See
     * AccidentContextResolver for why re-deriving it later would be a fabrication.
     *
     * The case is born at `reported` and immediately advanced to `awaiting_police` unless the police
     * report is irrelevant by nature (a car damaged in our own yard, with nobody driving). That
     * automatic step is what makes "which accidents are missing paperwork?" answerable on day one
     * rather than after somebody remembers to move the case along.
     *
     * @param array<string,mixed> $data validated payload
     */
    public function report(array $data, User $actor): AccidentCase
    {
        $vehicle = Vehicle::find($data['vehicle_id'] ?? null);
        if (! $vehicle) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'An accident has to be about a car. Pick the vehicle it happened to.',
            ]);
        }

        $occurredAt = ! empty($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : Carbon::now();
        if ($occurredAt->isFuture()) {
            throw ValidationException::withMessages([
                'occurred_at' => 'An accident cannot have happened in the future. Check the date and time.',
            ]);
        }

        return DB::transaction(function () use ($vehicle, $data, $actor, $occurredAt) {
            $case = new AccidentCase([
                'vehicle_id'             => $vehicle->id,
                'occurred_at'            => $occurredAt,
                'location'               => $data['location'] ?? null,
                'description'            => $data['description'] ?? null,
                'odometer'               => $data['odometer'] ?? ($vehicle->odometer !== null ? (int) $vehicle->odometer : null),
                'accident_type'          => $data['accident_type'] ?? null,
                'other_party_involved'   => (bool) ($data['other_party_involved'] ?? false),
                'other_party_name'       => $data['other_party_name'] ?? null,
                'other_party_phone'      => $data['other_party_phone'] ?? null,
                'other_party_plate'      => $data['other_party_plate'] ?? null,
                'other_party_insurer'    => $data['other_party_insurer'] ?? null,
                'other_party_policy_no'  => $data['other_party_policy_no'] ?? null,
                'other_party_note'       => $data['other_party_note'] ?? null,
                'drivable'               => $data['drivable'] ?? null,
                'towing_required'        => $data['towing_required'] ?? null,
                'safety_concerns'        => $data['safety_concerns'] ?? null,
                'driver_name'            => $data['driver_name'] ?? null,
                'driver_phone'           => $data['driver_phone'] ?? null,
                'driver_user_id'         => $data['driver_user_id'] ?? null,
                'responsible_party_note' => $data['responsible_party_note'] ?? null,
            ]);

            // WHO HAD THE CAR — resolved once, frozen. Not fillable: these are the record, and a
            // request body must never be able to claim a customer was driving.
            foreach ($this->context->resolve($vehicle, $occurredAt, $data['responsible_party_type'] ?? null) as $col => $val) {
                $case->{$col} = $val;
            }

            $case->reference        = $this->nextReference($occurredAt);
            $case->stage            = AccidentCase::STAGE_REPORTED;
            $case->reported_by      = $actor->id;
            $case->reported_by_name = $actor->name ?: $actor->email;
            $case->reported_at      = Carbon::now();
            $case->save();

            $this->audit($case, VehicleLogEvent::EVENT_ACCIDENT_REPORTED, $actor,
                $this->reportSentence($case), [
                    'reference'     => $case->reference,
                    'accident_type' => $case->accident_type,
                    'location'      => $case->location,
                    'occurred_at'   => $case->occurred_at?->toIso8601String(),
                    'drivable'      => $case->drivable,
                    'towing'        => $case->towing_required,
                ]);

            // The context is its own event, not a footnote on the one above, because "the car was
            // with a customer" is the single fact an insurer, a lawyer and a manager all come looking
            // for — and a fact folded into another event's meta is a fact nobody finds.
            $this->audit($case, VehicleLogEvent::EVENT_ACCIDENT_CONTEXT_CAPTURED, $actor,
                $this->contextSentence($case), [
                    'responsible_party_type' => $case->responsible_party_type,
                    'detected'               => $case->context_detected,
                    'contract_id'            => $case->contract_ref,
                    'contract_no'            => $case->contract_no_snapshot,
                    'customer_id'            => $case->customer_ref,
                    'customer_name'          => $case->customer_name_snapshot,
                    'rental_period'          => [
                        'from' => optional($case->contract_out_date_snapshot)->toDateString(),
                        'to'   => optional($case->contract_in_date_snapshot)->toDateString(),
                    ],
                ]);

            // The damage the reporter could already see. Optional — most roadside reports have none.
            foreach ($data['damage_items'] ?? [] as $item) {
                $this->addDamageItem($case, $item, $actor, notify: false);
            }

            // Straight to the gate. A yard scrape with nobody driving has no police report to chase,
            // so it skips to assessment — but that is a NAMED exception in one place, not a silent
            // "the field is empty so let's not ask".
            $needsPolice = ! in_array($case->responsible_party_type, [
                AccidentCase::PARTY_WORKSHOP, AccidentCase::PARTY_PARKED,
            ], true);

            $this->moveStage(
                $case,
                $needsPolice ? AccidentCase::STAGE_AWAITING_POLICE : AccidentCase::STAGE_ASSESSMENT,
                $actor,
                $needsPolice ? 'Police report required' : 'No police report expected for this context',
            );

            $this->tell(AccidentResponsibility::accidentDesk(), $case, 'accident_reported',
                $case->wasWithCustomer() ? 'critical' : 'warning',
                'Accident reported — ' . ($vehicle->plate_no ?: 'vehicle'),
                $this->reportSentence($case), $actor);

            // The rental desk is told SEPARATELY and only when it is their problem: a customer is
            // holding a damaged car on a contract that is still running. They cannot learn this from
            // the maintenance board, because nothing has been sent to a garage yet.
            if ($case->wasWithCustomer()) {
                $this->tell(AccidentResponsibility::rentalDesk(), $case, 'accident_on_rental', 'critical',
                    'Accident on an active rental',
                    trim(sprintf(
                        '%s was on hire to %s on contract %s when the accident happened. The contract is unchanged — decide what to do about the hire.',
                        $vehicle->plate_no ?: 'The vehicle',
                        $case->customer_name_snapshot ?: 'a customer',
                        $case->contract_no_snapshot ?: '—',
                    )), $actor);
            }

            return $case->fresh();
        });
    }

    /**
     * ACC-YYYY-NNNN, unique, allocated once and quoted to insurers and police.
     *
     * Sequential within the year rather than a random token: humans read these out over the phone,
     * and "ACC-2026-0047" survives that where a uuid does not. The max() is scoped to the prefix so a
     * new year restarts at 1, and the whole thing runs inside report()'s transaction.
     */
    private function nextReference(Carbon $occurredAt): string
    {
        $prefix = 'ACC-' . $occurredAt->format('Y') . '-';
        $last = AccidentCase::withTrashed()
            ->where('reference', 'like', $prefix . '%')
            ->orderByDesc('reference')
            ->value('reference');

        $n = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    // ══ THE NARRATIVE ═════════════════════════════════════════════════════════════════════════

    /**
     * Correct or expand what happened. The whole detail block, including the other party.
     *
     * Diffed against the previous values and the CHANGES are what goes into the audit meta — "who
     * changed the accident type from single-vehicle to collision, and when" is a question that gets
     * asked, and a meta blob containing the whole row after the fact cannot answer it.
     */
    public function updateDetails(AccidentCase $case, array $data, User $actor): AccidentCase
    {
        $this->assertOpen($case);

        $before = $case->only(array_keys($data));
        $case->fill($data);
        $changes = $this->diff($before, $case->only(array_keys($data)));

        if ($changes === []) {
            return $case;
        }

        $case->save();

        $this->audit($case, VehicleLogEvent::EVENT_ACCIDENT_DETAILS_UPDATED, $actor,
            'Accident details updated', ['changes' => $changes]);

        return $case->fresh();
    }

    /** Add one damaged area. Countable, costable, and later joinable to the repair that fixed it. */
    public function addDamageItem(AccidentCase $case, array $data, User $actor, bool $notify = true): AccidentDamageItem
    {
        $this->assertOpen($case);

        $item = $case->damageItems()->create([
            'damage_catalog_id'    => $data['damage_catalog_id'] ?? null,
            'vehicle_location_id'  => $data['vehicle_location_id'] ?? null,
            'area_label'           => $data['area_label'],
            'severity'             => $data['severity'] ?? AccidentDamageItem::SEVERITY_UNKNOWN,
            'description'          => $data['description'] ?? null,
            'requires_replacement' => $data['requires_replacement'] ?? null,
            'estimated_cost'       => $data['estimated_cost'] ?? null,
            'created_by'           => $actor->id,
            'created_by_name'      => $actor->name ?: $actor->email,
        ]);

        $this->audit($case, VehicleLogEvent::EVENT_ACCIDENT_DAMAGE_RECORDED, $actor,
            'Damage recorded: ' . $item->area_label, [
                'damage_item_id' => $item->id,
                'area'           => $item->area_label,
                'severity'       => $item->severity,
                'estimated_cost' => $item->estimated_cost,
            ]);

        return $item;
    }

    /** Remove a damage item entered in error. The timeline keeps the row that says it was there. */
    public function removeDamageItem(AccidentCase $case, AccidentDamageItem $item, User $actor): void
    {
        $this->assertOpen($case);

        if ($item->accident_case_id !== $case->id) {
            throw ValidationException::withMessages(['damage_item' => 'That damage item belongs to a different accident.']);
        }

        $label = $item->area_label;
        $item->delete();

        $this->audit($case, VehicleLogEvent::EVENT_ACCIDENT_DAMAGE_RECORDED, $actor,
            'Damage item removed: ' . $label, ['removed' => $label]);
    }

    /**
     * THE ASSESSMENT IS DONE — somebody has looked at the car and written down what is broken.
     *
     * Refused with no damage items on the case, and that refusal is the point. "Assessment complete,
     * damage: none recorded" is not an assessment; it is a button somebody pressed. If the car
     * genuinely has no visible damage, that is a finding and it gets an item saying so.
     */
    public function completeAssessment(AccidentCase $case, array $data, User $actor): AccidentCase
    {
        $this->assertOpen($case);

        if ($case->damageItems()->count() === 0) {
            throw ValidationException::withMessages([
                'damage_items' => 'Record what is damaged before completing the assessment — even "no visible damage" is a finding worth writing down.',
            ]);
        }

        $case->fill(array_intersect_key($data, array_flip(['drivable', 'towing_required', 'safety_concerns'])));
        $case->assessed_at      = Carbon::now();
        $case->assessed_by      = $actor->id;
        $case->assessed_by_name = $actor->name ?: $actor->email;
        $case->save();

        $items = $case->damageItems()->get();

        $this->audit($case, VehicleLogEvent::EVENT_ACCIDENT_ASSESSED, $actor,
            'Damage assessment completed — ' . $items->count() . ' area(s) recorded', [
                'items'    => $items->map(fn ($i) => ['area' => $i->area_label, 'severity' => $i->severity])->all(),
                'drivable' => $case->drivable,
                'towing'   => $case->towing_required,
                'estimate' => (float) $items->sum('estimated_cost'),
            ]);

        if ($case->stage === AccidentCase::STAGE_ASSESSMENT || $case->stage === AccidentCase::STAGE_AWAITING_POLICE) {
            $this->moveStage($case, AccidentCase::STAGE_LIABILITY, $actor, 'Assessment complete');
            $this->tell(AccidentResponsibility::liabilityAuthority(), $case, 'accident_liability_due', 'warning',
                'Liability decision needed', $this->sentence($case, 'The damage is assessed. Somebody has to decide whose fault it was.'), $actor);
        }

        return $case->fresh();
    }

    // ══ THE POLICE REPORT ═════════════════════════════════════════════════════════════════════

    /**
     * Capture the report's identifying facts. NOT verification — this is "we have it", and a
     * separate person asserts "we have read it". A single step collapsing the two would make the
     * verification meaningless, since the uploader would be verifying their own upload.
     */
    public function recordPoliceReport(AccidentCase $case, array $data, User $actor): AccidentCase
    {
        $this->assertOpen($case);

        $previous = $case->police_status;

        $case->police_report_no   = $data['police_report_no'];
        $case->police_report_date = $data['police_report_date'] ?? null;
        $case->police_authority   = $data['police_authority'] ?? null;
        $case->police_note        = $data['police_note'] ?? null;
        // A report re-recorded on an already-verified case drops back to `recorded`: the numbers
        // changed, so whatever was verified is no longer what the case says.
        $case->police_status       = AccidentCase::POLICE_RECORDED;
        $case->police_recorded_at  = Carbon::now();
        $case->police_recorded_by  = $actor->id;
        $case->police_verified_at  = null;
        $case->police_verified_by  = null;
        $case->police_verified_by_name = null;
        $case->save();

        $this->audit($case, VehicleLogEvent::EVENT_POLICE_REPORT_RECORDED, $actor,
            'Police report recorded — ' . $case->police_report_no, [
                'previous_status'  => $previous,
                'new_status'       => $case->police_status,
                'report_no'        => $case->police_report_no,
                'report_date'      => optional($case->police_report_date)->toDateString(),
                'authority'        => $case->police_authority,
                're_verification_required' => $previous === AccidentCase::POLICE_VERIFIED,
            ]);

        return $case->fresh();
    }

    /**
     * Somebody has read the police report against the file and says it is what it claims to be.
     *
     * Refused unless the report has been recorded first, and refused on a bypassed case — a waived
     * requirement cannot be retroactively "verified" without the waiver being withdrawn, because
     * that would erase the record of the exception.
     */
    public function verifyPoliceReport(AccidentCase $case, User $actor, ?string $note = null): AccidentCase
    {
        $this->assertOpen($case);

        if ($case->police_status !== AccidentCase::POLICE_RECORDED) {
            throw ValidationException::withMessages([
                'police_status' => $case->police_status === AccidentCase::POLICE_MISSING
                    ? 'There is no police report on this case yet. Record its number and date first.'
                    : 'Only a recorded police report can be verified. This one reads "' . $case->police_status . '".',
            ]);
        }

        $case->police_status           = AccidentCase::POLICE_VERIFIED;
        $case->police_verified_at      = Carbon::now();
        $case->police_verified_by      = $actor->id;
        $case->police_verified_by_name = $actor->name ?: $actor->email;
        if ($note) {
            $case->police_note = trim(($case->police_note ? $case->police_note . "\n" : '') . $note);
        }
        $case->save();

        $this->audit($case, VehicleLogEvent::EVENT_POLICE_REPORT_VERIFIED, $actor,
            'Police report verified — ' . $case->police_report_no, [
                'previous_status' => AccidentCase::POLICE_RECORDED,
                'new_status'      => AccidentCase::POLICE_VERIFIED,
                'report_no'       => $case->police_report_no,
                'verified_by'     => $case->police_verified_by_name,
                'note'            => $note,
            ]);

        if ($case->stage === AccidentCase::STAGE_AWAITING_POLICE) {
            $this->moveStage($case, AccidentCase::STAGE_ASSESSMENT, $actor, 'Police report verified');
        }

        return $case->fresh();
    }

    /**
     * WAIVE THE POLICE REPORT — the exception, recorded as one.
     *
     * The reason is mandatory and is stored verbatim. This is the method that keeps the workflow
     * honest in both directions: the gate is real (nothing else lets a case past it undocumented),
     * and the business is not held hostage by it (a yard scrape has no report and never will).
     *
     * What it must never become is invisible. The case wears `bypassed` for life, the timeline
     * carries a dedicated event type — not a generic "updated" — and the name on it is the person
     * who made the call, never the person who benefited from it.
     */
    public function bypassPoliceReport(AccidentCase $case, string $reason, User $actor): AccidentCase
    {
        $this->assertOpen($case);

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Say why the police report is being waived. A bypass with no reason is indistinguishable from a missing document.',
            ]);
        }

        $previous = $case->police_status;

        $case->police_status            = AccidentCase::POLICE_BYPASSED;
        $case->police_bypass_reason     = $reason;
        $case->police_bypassed_at       = Carbon::now();
        $case->police_bypassed_by       = $actor->id;
        $case->police_bypassed_by_name  = $actor->name ?: $actor->email;
        $case->save();

        $this->audit($case, VehicleLogEvent::EVENT_POLICE_REPORT_BYPASSED, $actor,
            'Police report requirement waived by ' . $case->police_bypassed_by_name, [
                'previous_status' => $previous,
                'new_status'      => AccidentCase::POLICE_BYPASSED,
                'reason'          => $reason,
                'waived_by'       => $case->police_bypassed_by_name,
            ]);

        // Loud on purpose. A waiver is a governance event, and the desk that owns these cases should
        // learn about it from the bell rather than from an audit six months later.
        $this->tell(AccidentResponsibility::accidentDesk(), $case, 'accident_police_bypassed', 'warning',
            'Police report waived on ' . $case->reference,
            $this->sentence($case, $case->police_bypassed_by_name . ' waived the police report: ' . $reason), $actor);

        if ($case->stage === AccidentCase::STAGE_AWAITING_POLICE) {
            $this->moveStage($case, AccidentCase::STAGE_ASSESSMENT, $actor, 'Police report waived');
        }

        return $case->fresh();
    }

    // ══ LIABILITY ═════════════════════════════════════════════════════════════════════════════

    /**
     * WHOSE FAULT IT WAS. A judgement, so it is attributed or it is not recorded.
     *
     * `source` is mandatory and constrained: a liability verdict with no stated basis is an opinion,
     * and the difference between "the police report says the other driver ran a red light" and
     * "somebody in the office reckons it was the customer" is the entire value of the field.
     *
     * The share percentage is only meaningful on `shared` and is refused elsewhere, because "customer
     * liable, 60%" is two contradictory statements and whichever one a reader believes, the other one
     * was also written down.
     */
    public function setLiability(AccidentCase $case, array $data, User $actor): AccidentCase
    {
        $this->assertOpen($case);

        $status = $data['liability_status'];
        $share  = $data['liability_share_pct'] ?? null;

        if ($status === AccidentCase::LIABILITY_SHARED && $share === null) {
            throw ValidationException::withMessages([
                'liability_share_pct' => 'Shared responsibility needs a share. Say what percentage sits with us.',
            ]);
        }
        if ($status !== AccidentCase::LIABILITY_SHARED && $share !== null) {
            throw ValidationException::withMessages([
                'liability_share_pct' => 'A percentage only means something on shared responsibility. Pick "Shared" or leave the share empty.',
            ]);
        }

        $previous = [
            'status' => $case->liability_status,
            'share'  => $case->liability_share_pct,
            'source' => $case->liability_source,
        ];

        $case->liability_status          = $status;
        $case->liability_share_pct       = $share;
        $case->liability_source          = $data['liability_source'];
        $case->liability_note            = $data['liability_note'] ?? null;
        $case->liability_decided_at      = Carbon::now();
        $case->liability_decided_by      = $actor->id;
        $case->liability_decided_by_name = $actor->name ?: $actor->email;
        $case->save();

        $this->audit($case, VehicleLogEvent::EVENT_ACCIDENT_LIABILITY_SET, $actor,
            'Liability: ' . $this->liabilityPhrase($case), [
                'previous'   => $previous,
                'new'        => ['status' => $status, 'share' => $share, 'source' => $case->liability_source],
                'note'       => $case->liability_note,
                'decided_by' => $case->liability_decided_by_name,
                // Named separately because a CHANGED verdict is a different event, commercially, from
                // a first one — it usually means an insurer or a court disagreed with us.
                'revised'    => $previous['status'] !== AccidentCase::LIABILITY_PENDING,
            ]);

        if ($case->stage === AccidentCase::STAGE_LIABILITY) {
            $this->moveStage($case, AccidentCase::STAGE_INSURANCE, $actor, 'Liability decided');
        }

        return $case->fresh();
    }

    // ══ INSURANCE ═════════════════════════════════════════════════════════════════════════════

    /**
     * The insurer's side of the case: who they are, the policy, the claim, and what they last said.
     *
     * One method for the whole block rather than a submit/approve/reject trio, because the claim
     * status is THEIRS and we are recording it, not driving it — an insurer can jump from
     * `submitted` straight to `rejected` and a state machine that refused would simply make the
     * system unable to record the truth.
     */
    public function updateInsurance(AccidentCase $case, array $data, User $actor): AccidentCase
    {
        $this->assertOpen($case);

        $previousStatus = $case->claim_status;

        $case->fill(array_intersect_key($data, array_flip([
            'insurer_vendor_id', 'insurer_name', 'policy_no',
            'insurance_contact_name', 'insurance_contact_phone', 'insurance_contact_email',
        ])));

        if (array_key_exists('claim_no', $data)) {
            $case->claim_no = $data['claim_no'];
        }
        if (array_key_exists('claim_response_due_on', $data)) {
            $case->claim_response_due_on = $data['claim_response_due_on'];
        }
        if (! empty($data['insurance_note'])) {
            $case->insurance_note = trim(($case->insurance_note ? $case->insurance_note . "\n" : '') . $data['insurance_note']);
        }

        if (! empty($data['claim_status']) && $data['claim_status'] !== $previousStatus) {
            $case->claim_status = $data['claim_status'];
            // Stamped once, on the first submission — the date an insurer's clock starts is a fact
            // about the claim, and re-stamping it every time the status moves would erase it.
            if ($case->claim_status === AccidentCase::CLAIM_SUBMITTED && ! $case->claim_submitted_at) {
                $case->claim_submitted_at = Carbon::now();
            }
        }

        $case->insurance_updated_at = Carbon::now();
        $case->save();

        $this->audit($case, VehicleLogEvent::EVENT_ACCIDENT_CLAIM_UPDATED, $actor,
            $this->claimSentence($case), [
                'previous_status' => $previousStatus,
                'new_status'      => $case->claim_status,
                'insurer'         => $case->insurer_name,
                'claim_no'        => $case->claim_no,
                'policy_no'       => $case->policy_no,
                'due_on'          => optional($case->claim_response_due_on)->toDateString(),
                'note'            => $data['insurance_note'] ?? null,
            ]);

        // An answer from the insurer is finance's cue, not the workshop's.
        if (in_array($case->claim_status, [
            AccidentCase::CLAIM_APPROVED, AccidentCase::CLAIM_PARTIALLY_APPROVED, AccidentCase::CLAIM_REJECTED,
        ], true) && $case->claim_status !== $previousStatus) {
            $this->tell(AccidentResponsibility::financeAuthority(), $case, 'accident_claim_answered', 'warning',
                'Insurer answered on ' . $case->reference,
                $this->sentence($case, $this->claimSentence($case) . ' Record what they will actually pay.'), $actor);
        }

        if ($case->stage === AccidentCase::STAGE_INSURANCE
            && in_array($case->claim_status, [AccidentCase::CLAIM_APPROVED, AccidentCase::CLAIM_PARTIALLY_APPROVED, AccidentCase::CLAIM_REJECTED], true)) {
            $this->moveStage($case, AccidentCase::STAGE_REPAIR, $actor, 'Insurer answered');
        }

        return $case->fresh();
    }

    // ══ MONEY ═════════════════════════════════════════════════════════════════════════════════

    /**
     * Write down one figure. APPENDS — never updates, never deletes.
     *
     * A figure for a (phase, party) that already has a live entry SUPERSEDES it: the old row is
     * stamped `superseded_at` and points at its replacement, so the trail reads forwards ("the
     * estimate went from 12,000 to 9,400 on the 14th") instead of appearing to have always been
     * 9,400. This is rule 5, and it is the whole reason the money lives in its own table.
     */
    public function recordFinancial(AccidentCase $case, array $data, User $actor): AccidentFinancialEntry
    {
        $this->assertOpen($case);

        return DB::transaction(function () use ($case, $data, $actor) {
            $entry = $case->financialEntries()->create([
                'phase'               => $data['phase'],
                'party'               => $data['party'],
                'amount'              => $data['amount'],
                'currency'            => $data['currency'] ?? $case->currency ?: 'AED',
                'note'                => $data['note'] ?? null,
                'maintenance_id'      => $data['maintenance_id'] ?? null,
                'vehicle_document_id' => $data['vehicle_document_id'] ?? null,
                'external_ref'        => $data['external_ref'] ?? null,
                'recorded_by'         => $actor->id,
                'recorded_by_name'    => $actor->name ?: $actor->email,
                'recorded_at'         => Carbon::now(),
            ]);

            $superseded = $case->financialEntries()
                ->where('phase', $entry->phase)
                ->where('party', $entry->party)
                ->whereNull('superseded_at')
                ->where('id', '!=', $entry->id)
                ->get();

            foreach ($superseded as $old) {
                $old->forceFill([
                    'superseded_at'          => Carbon::now(),
                    'superseded_by_entry_id' => $entry->id,
                ])->save();
            }

            $this->audit($case, VehicleLogEvent::EVENT_ACCIDENT_FINANCIAL_RECORDED, $actor,
                sprintf('%s (%s): %s %s',
                    ucfirst(str_replace('_', ' ', $entry->phase)),
                    str_replace('_', ' ', $entry->party),
                    $entry->currency,
                    number_format((float) $entry->amount, 2)),
                [
                    'entry_id'  => $entry->id,
                    'phase'     => $entry->phase,
                    'party'     => $entry->party,
                    'amount'    => (float) $entry->amount,
                    'currency'  => $entry->currency,
                    // The previous figure, by name, so the change is legible without a second query.
                    'supersedes' => $superseded->map(fn ($o) => ['id' => $o->id, 'amount' => (float) $o->amount])->all(),
                    'note'      => $entry->note,
                ]);

            return $entry;
        });
    }

    // ══ THE REPAIR ════════════════════════════════════════════════════════════════════════════

    /**
     * SEND THE CAR IN — raise the repair ticket this accident needs, as a CHILD of the case.
     *
     * It does NOT reimplement the maintenance workflow; it calls the same `openDirectDispatch` the
     * "straight to the garage" door uses, so the ticket is born at Needs Dispatch, opens its own
     * maintenance contract, appears on the supervisors' board and runs the ordinary lifecycle with
     * nothing about it special-cased. The only thing this method adds is the parent link and the
     * accident's own reason code.
     *
     * The workshop's refusals are left to propagate untouched — a car already in the pipeline is
     * refused here exactly as it would be from any other door, because a second ticket competing for
     * the same physical car is no less wrong for having an accident behind it.
     */
    public function raiseRepair(AccidentCase $case, array $data, User $actor): Maintenance
    {
        $this->assertOpen($case);

        $areas = $case->damageItems()->pluck('area_label')->all();
        $complaint = trim(($data['note'] ?? '') ?: sprintf(
            'Accident %s (%s). Damage: %s',
            $case->reference,
            optional($case->occurred_at)->toDateString() ?: 'date unknown',
            $areas ? implode(', ', $areas) : 'to be assessed',
        ));

        $ticket = $this->workflow->openDirectDispatch([
            'vehicle_id'          => $case->vehicle_id,
            'customer_complaint'  => $complaint,
            'request_reason_code' => 'accident_damage',
            'transport'           => $data['transport'] ?? ($case->towing_required ? 'recovery' : null),
        ], $actor);

        return $this->linkRepair($case, $ticket, $actor);
    }

    /**
     * Parent an EXISTING maintenance ticket to this case — for the repair that was opened before
     * anybody thought to open an accident file, which is the normal order of events in a busy week.
     */
    public function linkRepair(AccidentCase $case, Maintenance $ticket, User $actor): Maintenance
    {
        $this->assertOpen($case);

        if ($ticket->vehicle_id !== $case->vehicle_id) {
            throw ValidationException::withMessages([
                'maintenance_id' => 'That repair is for a different car. A ticket cannot be attached to another vehicle’s accident.',
            ]);
        }
        if ($ticket->accident_case_id && $ticket->accident_case_id !== $case->id) {
            throw ValidationException::withMessages([
                'maintenance_id' => 'That repair already belongs to accident case #' . $ticket->accident_case_id . '.',
            ]);
        }

        $ticket->accident_case_id = $case->id;
        $ticket->save();

        $this->audit($case, VehicleLogEvent::EVENT_ACCIDENT_REPAIR_LINKED, $actor,
            'Repair ticket #' . $ticket->id . ' raised for this accident', [
                'maintenance_id'  => $ticket->id,
                'workflow_status' => $ticket->workflow_status,
            ], ['maintenance_id' => $ticket->id]);

        if ($case->stage === AccidentCase::STAGE_INSURANCE || $case->stage === AccidentCase::STAGE_LIABILITY) {
            $this->moveStage($case, AccidentCase::STAGE_REPAIR, $actor, 'Repair raised');
        }

        return $ticket;
    }

    // ══ DOCUMENTS ═════════════════════════════════════════════════════════════════════════════

    /**
     * A file joined the dossier. Called by the document controller AFTER the upload has landed, so
     * the timeline carries the document's identity and the UI can open it straight from the event.
     *
     * A POLICE REPORT UPLOAD IS NOT A POLICE REPORT. Uploading the scan moves `police_status` to
     * `recorded` only when the case has nothing at all — attaching a photo of a report is evidence
     * that one exists, and is deliberately NOT the same as somebody having read it. Verification
     * stays a separate, separately-permissioned act. @see verifyPoliceReport()
     */
    public function documentAttached(AccidentCase $case, VehicleDocument $document, User $actor): AccidentCase
    {
        $this->audit($case, VehicleLogEvent::EVENT_ACCIDENT_DOCUMENT_ADDED, $actor,
            (VehicleDocument::KINDS[$document->kind] ?? 'Document') . ' uploaded', [
                'document_id'   => $document->id,
                'kind'          => $document->kind,
                'kind_label'    => VehicleDocument::KINDS[$document->kind] ?? $document->kind,
                'original_name' => $document->original_name,
                // The link the timeline renders as "Open Police Report".
                'document_url'  => $document->viewUrl(),
            ]);

        if ($document->kind === VehicleDocument::KIND_POLICE_REPORT
            && $case->police_status === AccidentCase::POLICE_MISSING) {
            $case->police_status      = AccidentCase::POLICE_RECORDED;
            $case->police_recorded_at = Carbon::now();
            $case->police_recorded_by = $actor->id;
            $case->save();

            $this->audit($case, VehicleLogEvent::EVENT_POLICE_REPORT_RECORDED, $actor,
                'Police report document uploaded — awaiting verification', [
                    'previous_status' => AccidentCase::POLICE_MISSING,
                    'new_status'      => AccidentCase::POLICE_RECORDED,
                    'document_id'     => $document->id,
                    'via'             => 'upload',
                ]);
        }

        return $case->fresh();
    }

    // ══ THE LADDER ════════════════════════════════════════════════════════════════════════════

    /**
     * Move a case along by hand. FORWARD ONLY — a case that has been to the insurer does not go back
     * to "reported", and allowing it would make the timeline unreadable.
     *
     * The documentation gate lives here: nothing may pass `awaiting_police` while the police report
     * is neither verified nor consciously waived. That is the one hard rule on the ladder, and the
     * escape hatch is bypassPoliceReport() — an authorised, reasoned, permanently-recorded exception
     * rather than a quiet skip.
     */
    public function advance(AccidentCase $case, string $stage, User $actor, ?string $note = null): AccidentCase
    {
        $this->assertOpen($case);

        if (! in_array($stage, AccidentCase::STAGES, true)) {
            throw ValidationException::withMessages(['stage' => 'Unknown accident stage.']);
        }
        if ($stage === AccidentCase::STAGE_CLOSED) {
            throw ValidationException::withMessages([
                'stage' => 'Close the case through the close action — closing records who did it and why.',
            ]);
        }

        $from = array_search($case->stage, AccidentCase::STAGES, true);
        $to   = array_search($stage, AccidentCase::STAGES, true);
        if ($to <= $from) {
            throw ValidationException::withMessages([
                'stage' => 'An accident case only moves forward. It is already at "' . $case->stage . '".',
            ]);
        }

        // THE GATE. Leaving the police stage means the paperwork question has an answer.
        if ($case->stage === AccidentCase::STAGE_AWAITING_POLICE && ! $case->policeSatisfied()) {
            throw ValidationException::withMessages([
                'police_status' => 'The police report is still outstanding. Verify it, or waive it with a reason — this case cannot move on with the question unanswered.',
            ]);
        }

        return $this->moveStage($case, $stage, $actor, $note)->fresh();
    }

    /** The stage write itself + its timeline row. Used by advance() and by the automatic steps. */
    private function moveStage(AccidentCase $case, string $stage, User $actor, ?string $note = null): AccidentCase
    {
        if ($case->stage === $stage) {
            return $case;
        }

        $previous = $case->stage;
        $case->stage = $stage;
        $case->save();

        $this->audit($case, VehicleLogEvent::EVENT_ACCIDENT_STAGE_CHANGED, $actor,
            'Accident case moved to ' . str_replace('_', ' ', $stage), [
                'previous_stage' => $previous,
                'new_stage'      => $stage,
                'note'           => $note,
            ]);

        return $case;
    }

    /**
     * CLOSE THE CASE.
     *
     * Two questions must have answers, and neither of them is "the car is fixed": whose fault it was,
     * and whether the paperwork question was ever settled. A case closed with liability `pending` is
     * a case where the company has quietly given up on recovering money and left no record of the
     * decision — so it is refused, and the way through is to record the honest verdict
     * (`unknown` is a valid, decided answer) rather than to leave the field empty.
     *
     * Money is deliberately NOT a closing condition. An accident whose settlement never arrives is a
     * real and common outcome; refusing to close it would fill the board with cases nobody can act on.
     * What closing does is record the outstanding position on the timeline, so it is stated rather
     * than forgotten.
     */
    public function close(AccidentCase $case, User $actor, ?string $note = null): AccidentCase
    {
        $this->assertOpen($case);

        if (! $case->liabilityDecided()) {
            throw ValidationException::withMessages([
                'liability_status' => 'Decide liability before closing. "Unknown" is an acceptable answer; leaving it undecided is not.',
            ]);
        }
        if ($case->police_status === AccidentCase::POLICE_MISSING) {
            throw ValidationException::withMessages([
                'police_status' => 'This case has no police report and no recorded waiver. Record the report, or waive it with a reason, before closing.',
            ]);
        }

        $breakdown = $case->financialBreakdown();

        $case->stage          = AccidentCase::STAGE_CLOSED;
        $case->closed_at      = Carbon::now();
        $case->closed_by      = $actor->id;
        $case->closed_by_name = $actor->name ?: $actor->email;
        $case->closure_note   = $note;
        $case->save();

        $this->audit($case, VehicleLogEvent::EVENT_ACCIDENT_CLOSED, $actor,
            'Accident case closed by ' . $case->closed_by_name, [
                'liability'   => $case->liability_status,
                'police'      => $case->police_status,
                'claim'       => $case->claim_status,
                // Stated at closure so the final position is legible without re-deriving it, and so a
                // later change to the ledger cannot rewrite what was true when it was closed.
                'financials'  => $breakdown,
                'outstanding' => round($breakdown['actual'] - $breakdown['paid'], 2),
                'note'        => $note,
            ]);

        return $case->fresh();
    }

    /**
     * REOPEN. The one way back into a closed case, gated on `accidents.override` at the route and on
     * a stated reason here. An insurer who reopens a settlement six months later is exactly why this
     * exists — and exactly why it is audited rather than allowed to look like an ordinary edit.
     */
    public function reopen(AccidentCase $case, string $reason, User $actor): AccidentCase
    {
        if (! $case->isClosed()) {
            throw ValidationException::withMessages(['stage' => 'This case is not closed.']);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the case is being reopened.']);
        }

        // Back to settlement, not to the beginning: what is being reopened is almost always the
        // money, and dropping a repaired car back to "reported" would misstate its whole history.
        $case->stage         = AccidentCase::STAGE_SETTLEMENT;
        $case->reopened_at   = Carbon::now();
        $case->reopened_by   = $actor->id;
        $case->reopen_reason = $reason;
        $case->closed_at     = null;
        $case->save();

        $this->audit($case, VehicleLogEvent::EVENT_ACCIDENT_REOPENED, $actor,
            'Accident case reopened by ' . ($actor->name ?: $actor->email), [
                'reason'        => $reason,
                'previous_stage' => AccidentCase::STAGE_CLOSED,
                'new_stage'     => $case->stage,
                'closed_by'     => $case->closed_by_name,
            ]);

        $this->tell(AccidentResponsibility::financeAuthority(), $case, 'accident_reopened', 'warning',
            'Accident case reopened — ' . $case->reference,
            $this->sentence($case, $reason), $actor);

        return $case->fresh();
    }

    // ══ internals ═════════════════════════════════════════════════════════════════════════════

    /** Rule 6: a closed case is frozen. reopen() is the only door. */
    private function assertOpen(AccidentCase $case): void
    {
        if ($case->isClosed()) {
            throw ValidationException::withMessages([
                'stage' => 'This accident case is closed. Reopen it — with a reason — before changing anything on it.',
            ]);
        }
    }

    /** One timeline row per change. @see VehicleLogService::recordAccident() */
    private function audit(AccidentCase $case, string $event, ?User $actor, string $description, array $meta = [], array $opts = []): void
    {
        $this->log->recordAccident($case, $event, $actor, array_merge($opts, [
            'description' => $description,
            'meta'        => array_merge([
                'accident_case_id' => $case->id,
                'reference'        => $case->reference,
                'stage'            => $case->stage,
            ], $meta),
        ]));
    }

    /** Fan an alert out to an audience defined by permission, deep-linked to the case. */
    private function tell(array $permissions, AccidentCase $case, string $type, string $severity, string $title, string $body, User $actor): void
    {
        try {
            $this->notifier->notifyByAnyPermission($permissions, [
                'type'     => $type,
                'category' => 'accident',
                'severity' => $severity,
                'title'    => $title,
                'body'     => $body,
                'url'      => '/accidents/' . $case->id,
                // Keyed on the case AND the event so each moment is its own card and a repeated
                // action cannot raise a duplicate.
                'key'      => 'accident:' . $case->id . ':' . $type,
                'icon'     => 'alert',
                'meta'     => [
                    'accident_case_id' => $case->id,
                    'reference'        => $case->reference,
                    'vehicle_id'       => $case->vehicle_id,
                    'stage'            => $case->stage,
                    'customer'         => $case->customer_name_snapshot,
                    'contract_no'      => $case->contract_no_snapshot,
                ],
            ], $actor->id);
        } catch (\Throwable $e) {
            report($e);   // a bell must never sink an accident decision
        }
    }

    /** Only the keys that actually changed, old → new. What an audit reader wants. */
    private function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $value) {
            $old = $before[$key] ?? null;
            $normalise = fn ($v) => $v instanceof \DateTimeInterface ? Carbon::instance($v)->toIso8601String() : $v;
            if ($normalise($old) !== $normalise($value)) {
                $changes[$key] = ['from' => $normalise($old), 'to' => $normalise($value)];
            }
        }

        return $changes;
    }

    // ── the sentences the timeline and the bell read in ────────────────────────────────────────

    private function sentence(AccidentCase $case, string $tail): string
    {
        $plate = $case->vehicle?->plate_no ?: ('vehicle #' . $case->vehicle_id);

        return trim($case->reference . ' · ' . $plate . ' — ' . $tail);
    }

    private function reportSentence(AccidentCase $case): string
    {
        return trim(sprintf('Accident reported%s%s%s',
            $case->accident_type ? ' (' . str_replace('_', ' ', $case->accident_type) . ')' : '',
            $case->location ? ' at ' . $case->location : '',
            $case->occurred_at ? ' on ' . $case->occurred_at->format('d M Y H:i') : '',
        ));
    }

    /** The banner sentence — the one fact the whole detail page leads with. */
    private function contextSentence(AccidentCase $case): string
    {
        if ($case->wasWithCustomer()) {
            return sprintf('Vehicle was with %s on rental contract %s at the time of the accident',
                $case->customer_name_snapshot ?: 'a customer',
                $case->contract_no_snapshot ?: '—');
        }

        return 'Responsibility at the time: ' . str_replace('_', ' ', $case->responsible_party_type)
            . ($case->driver_name ? ' (' . $case->driver_name . ')' : '');
    }

    private function liabilityPhrase(AccidentCase $case): string
    {
        $who = str_replace('_', ' ', $case->liability_status);
        $share = $case->liability_share_pct !== null ? ' (' . $case->liability_share_pct . '%)' : '';

        return $who . $share . ' — per ' . str_replace('_', ' ', (string) $case->liability_source);
    }

    private function claimSentence(AccidentCase $case): string
    {
        return sprintf('Insurance claim %s%s%s',
            str_replace('_', ' ', (string) $case->claim_status),
            $case->claim_no ? ' (' . $case->claim_no . ')' : '',
            $case->insurer_name ? ' with ' . $case->insurer_name : '');
    }
}
