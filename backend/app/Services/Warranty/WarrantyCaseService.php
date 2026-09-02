<?php

namespace App\Services\Warranty;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\NotificationScanner;
use App\Services\VehicleLogService;
use App\Support\WarrantyCoverage;
use App\Support\WarrantyResponsibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every write to a warranty CASE goes through here — the single choke point that owns the stage
 * machine, the audit trail and who gets told.
 *
 * Evidence class: F (fact capture) with one J (judgement) — the coverage verdict, which is a named
 * human's decision and is stored as such. Produces: warranty_claims, vehicle_log_events,
 * notifications. Consumes: warranties, vehicles.
 *
 * ── WHY A SERVICE AND NOT CONTROLLER CODE ──────────────────────────────────────────────────────
 *
 * Four rules have to hold no matter which door a case is moved through — the API, the procurement
 * guard, the pre-expiry sweep — and not one of them can be a column constraint:
 *
 *  1. A STAGE CHANGE IS THREE WRITES, ALWAYS. The row, the vehicle timeline, and the people whose
 *     work just changed. A transition that updates the row and tells nobody is how a case ends up
 *     sitting at `authorization_requested` for five weeks. They are written together, here.
 *
 *  2. THE COVERAGE VERDICT IS ATTRIBUTED OR IT IS NOT RECORDED. This decision spends or saves the
 *     company's money; "covered" with no name and no date on it is worthless in the argument that
 *     follows six months later. decideCoverage() refuses without an actor.
 *
 *  3. A REFUSAL MUST CARRY THEIR REASON — the same rule WarrantyService already enforces on a claim
 *     outcome, applied one level up to a coverage rejection. "Not covered" teaches nobody anything;
 *     "not covered, wear item after 40,000 km" is something the next contract can be written
 *     against, and something the engine can be told once and never ask again.
 *
 *  4. THE CASE STAYS JOINED TO THE CAR AND TO THE WORK. vehicle_id, the ticket, the fault and the
 *     part type are set at creation and never re-pointed. A case whose anchors drift is a case you
 *     cannot find from the thing that caused it.
 *
 * NOTIFICATIONS GO TO CAPABILITIES, NEVER TO PEOPLE. Every recipient below is resolved through
 * {@see WarrantyResponsibility} — a permission — so who receives them is managed on the Users page
 * and changes without a deploy. There is not a user id anywhere in this file, and there must never
 * be one. See that class for why the names in the original requirement are absent here.
 */
class WarrantyCaseService
{
    public function __construct(
        private VehicleLogService $log,
        private NotificationScanner $notifier,
    ) {}

    // ── Opening ────────────────────────────────────────────────────────────────────────────────

    /**
     * "We don't know whether they owe us this" becomes somebody's job.
     *
     * THE MOST IMPORTANT METHOD IN THE FEATURE. Everything else is bookkeeping around the moment an
     * unanswered question stops being a shrug and becomes a row with an owner. It is called by the
     * procurement guard before a purchase request is allowed to exist, by the maintenance path when a
     * fault lands on a car with live cover, and by the pre-expiry inspection.
     *
     * IDEMPOTENT ON THE SUBJECT. A second purchase request for the same part type on the same car
     * returns the SAME review rather than opening a second one — otherwise two buyers each open a
     * review, two people ring the same dealer, and the warranty desk learns to ignore the queue.
     *
     * @param CoverageAssessment $assessment what the engine said, frozen onto the case as its starting point
     * @throws ValidationException when the assessment names no live warranty — a review with nothing
     *                             to review is a task with no possible answer
     */
    public function openCoverageReview(
        Vehicle $vehicle,
        CoverageAssessment $assessment,
        CoverageSubject $subject,
        User $actor,
        string $origin = WarrantyClaim::ORIGIN_MANUAL,
        array $context = [],
    ): WarrantyClaim {
        if ($assessment->existingCase && $assessment->existingCase->isOpen()) {
            return $assessment->existingCase;   // already asked; do not ask twice
        }

        $warranty = $assessment->decisive ?? ($assessment->candidates[0] ?? null);

        if (! $warranty instanceof Warranty) {
            throw ValidationException::withMessages([
                'warranty' => 'There is no live warranty on this car to review. A coverage review needs a promise to review against.',
            ]);
        }

        return DB::transaction(function () use ($vehicle, $warranty, $assessment, $subject, $actor, $origin, $context) {
            $case = new WarrantyClaim([
                'warranty_id'          => $warranty->id,
                'vehicle_id'           => $vehicle->id,
                'maintenance_id'       => $subject->maintenanceId,
                'maintenance_task_id'  => $subject->taskId,
                'vehicle_component_id' => $subject->componentId,
                'component_catalog_id' => $subject->catalogId,
                'origin'               => $origin,
                'subject'              => $subject->describe(),
                'failure_description'  => $context['failure_description'] ?? null,
                'diagnosis'            => $context['diagnosis'] ?? null,
                'claimed_on'           => now()->toDateString(),
                // The reading of the moment: what judges the distance leg, and what a dealer will ask
                // for first. Frozen here for the same reason the window verdict is.
                'claim_odometer'       => $vehicle->odometer !== null ? (int) $vehicle->odometer : null,
            ]);

            // Not fillable — the state machine is this service's to set. @see WarrantyClaim::$fillable
            $case->stage           = WarrantyClaim::STAGE_COVERAGE_REVIEW;
            $case->created_by      = $actor->id;
            $case->created_by_name = $actor->name ?: $actor->email;

            // The engine's reading at the instant of asking, kept so the reviewer can see what the
            // system thought and why — and so a later change to the warranty data does not silently
            // rewrite the question that was put to them.
            $verdict = $warranty->evaluate(null, $case->claim_odometer);
            $case->was_in_window   = $verdict['state'] === Warranty::STATE_ACTIVE;
            $case->window_evidence = $verdict['evidence'];
            $case->coverage_reason_code = $assessment->reasonCode;

            $case->save();

            $this->audit($vehicle, VehicleLogEvent::EVENT_COVERAGE_REVIEW_OPENED, $actor, $case,
                "Warranty coverage review opened — {$case->subject}", [
                    'engine_verdict' => $assessment->verdict,
                    'reason_code'    => $assessment->reasonCode,
                    'origin'         => $origin,
                ]);

            $this->tellWarrantyDesk($case, $vehicle, 'warranty_coverage_review', 'critical',
                'Warranty coverage review required',
                $this->sentence($vehicle, $case, 'Decide whether this is covered before anything is bought.'),
                $actor,
            );

            return $case->fresh();
        });
    }

    /**
     * Open a case for something already KNOWN to be covered — the engine said so, or a reviewer did.
     * Skips the review rung and starts at COVERED, because asking somebody to review a question the
     * data already answers is the fastest way to teach them to rubber-stamp the queue.
     */
    public function openCoveredCase(
        Vehicle $vehicle,
        CoverageAssessment $assessment,
        CoverageSubject $subject,
        User $actor,
        string $origin = WarrantyClaim::ORIGIN_MANUAL,
        array $context = [],
    ): WarrantyClaim {
        $case = $this->openCoverageReview($vehicle, $assessment, $subject, $actor, $origin, $context);

        if ($case->stage === WarrantyClaim::STAGE_COVERAGE_REVIEW) {
            $case = $this->decideCoverage($case, WarrantyCoverage::COVERED, $assessment->reasonCode, $actor,
                'Confirmed from the recorded cover — no review needed.');
        }

        return $case;
    }

    // ── The decision ───────────────────────────────────────────────────────────────────────────

    /**
     * A human answers the question. The moment the money is either spent or saved.
     *
     * Both answers are successes and both are recorded identically. NOT_COVERED is terminal and
     * releases procurement; COVERED opens the dealer route and shuts procurement for good until
     * somebody overrides it. There is no third answer here on purpose — "still not sure" is the
     * review staying open, not a verdict.
     *
     * @param string $verdict     covered | not_covered
     * @param string $reasonCode  WHY, as a code — this is what the engine reads next time so the
     *                            same question is never asked twice. @see WarrantyCoverage
     * @throws ValidationException on an unusable verdict, or a rejection with no reason
     */
    public function decideCoverage(
        WarrantyClaim $case,
        string $verdict,
        string $reasonCode,
        User $actor,
        ?string $note = null,
    ): WarrantyClaim {
        if (! in_array($verdict, [WarrantyCoverage::COVERED, WarrantyCoverage::NOT_COVERED], true)) {
            throw ValidationException::withMessages([
                'verdict' => 'A coverage review ends in covered or not covered. "Unknown" is the review staying open, not an answer.',
            ]);
        }

        // Rule 3: a refusal without a reason teaches nobody anything and cannot be argued with later.
        if ($verdict === WarrantyCoverage::NOT_COVERED && trim((string) $note) === '' && $reasonCode === '') {
            throw ValidationException::withMessages([
                'note' => 'Say why it is not covered — the next person to buy this part reads your answer instead of asking again.',
            ]);
        }

        return DB::transaction(function () use ($case, $verdict, $reasonCode, $actor, $note) {
            $case->coverage_verdict     = $verdict;
            $case->coverage_reason_code = $reasonCode;
            $case->decided_by           = $actor->id;
            $case->decided_by_name      = $actor->name ?: $actor->email;
            $case->decided_at           = now();
            $case->stage = $verdict === WarrantyCoverage::COVERED
                ? WarrantyClaim::STAGE_COVERED
                : WarrantyClaim::STAGE_NOT_COVERED;

            if ($note) {
                $case->outcome_reason = trim($note);
            }
            $case->updated_by      = $actor->id;
            $case->updated_by_name = $actor->name ?: $actor->email;
            $case->save();

            $vehicle = $case->vehicle;

            $this->audit($vehicle, $verdict === WarrantyCoverage::COVERED
                ? VehicleLogEvent::EVENT_COVERAGE_CONFIRMED
                : VehicleLogEvent::EVENT_COVERAGE_REJECTED, $actor, $case,
                $verdict === WarrantyCoverage::COVERED
                    ? "Coverage confirmed — {$case->subject} is the provider's responsibility"
                    : "Coverage declined — {$case->subject} is ours to pay for",
                ['verdict' => $verdict, 'reason_code' => $reasonCode, 'note' => $note],
            );

            if ($verdict === WarrantyCoverage::COVERED) {
                // The workshop needs to know the car is going down the dealer route, not the garage
                // one — it changes where the car goes and how long it will be away.
                $this->notify(WarrantyResponsibility::warrantyAndMaintenance(), $case, $vehicle,
                    'warranty_case_opened', 'warning', 'Warranty case opened',
                    $this->sentence($vehicle, $case, 'Covered by the provider — contact them and track the authorization.'),
                    $actor,
                );
            } else {
                // Procurement is released. Telling the buyers matters as much as telling the desk:
                // they are the ones who were held up, and silence here is what teaches people to
                // override the gate rather than wait for it.
                $this->notify(WarrantyResponsibility::procurementResponsible(), $case, $vehicle,
                    'warranty_not_covered', 'info', 'Not covered — purchase may proceed',
                    $this->sentence($vehicle, $case, 'Reviewed and confirmed ours to pay. The normal purchase workflow applies.'),
                    $actor,
                );
            }

            return $case->fresh();
        });
    }

    // ── The provider leg ───────────────────────────────────────────────────────────────────────

    /**
     * Move a covered case along its dealer path.
     *
     * One method for the whole ladder rather than six near-identical ones, because every rung does
     * exactly the same three things (stamp, audit, tell) and differs only in which timestamp is set.
     * Six methods would have meant six chances for one of them to forget the notification.
     *
     * FORWARD ONLY. A case cannot be walked back up the ladder, because the rungs record things that
     * HAPPENED — a dealer either gave an authorisation or did not, and un-recording it would erase
     * the fact rather than correct it. A case that went the wrong way is closed with a reason.
     *
     * @param array $data authorization_ref?, claim_reference?, provider_response_due_on?, note?
     * @throws ValidationException moving backwards, or authorising with no reference
     */
    public function advance(WarrantyClaim $case, string $stage, User $actor, array $data = []): WarrantyClaim
    {
        $order = array_flip(WarrantyClaim::STAGES);

        if (($order[$stage] ?? -1) <= ($order[$case->stage] ?? 0)) {
            throw ValidationException::withMessages([
                'stage' => "This case is already at {$case->stage}. A case moves forward only — close it with a reason if it went the wrong way.",
            ]);
        }

        // An authorisation with no reference is not an authorisation: it is somebody's recollection
        // of a phone call, and it is exactly what a provider disputes when the invoice arrives.
        if ($stage === WarrantyClaim::STAGE_AUTHORIZED && trim((string) ($data['authorization_ref'] ?? $case->authorization_ref)) === '') {
            throw ValidationException::withMessages([
                'authorization_ref' => 'Record the reference they gave you. An authorization nobody can quote is one they can deny.',
            ]);
        }

        return DB::transaction(function () use ($case, $stage, $actor, $data) {
            $case->stage = $stage;

            match ($stage) {
                WarrantyClaim::STAGE_AUTHORIZATION_REQUESTED => $case->authorization_requested_at = now(),
                WarrantyClaim::STAGE_AUTHORIZED => tap($case, function ($c) use ($data) {
                    $c->authorized_at = now();
                    $c->authorization_ref = $data['authorization_ref'] ?? $c->authorization_ref;
                }),
                WarrantyClaim::STAGE_SENT_TO_PROVIDER => $case->sent_to_provider_at = now(),
                WarrantyClaim::STAGE_CLAIM_SUBMITTED => tap($case, function ($c) use ($data) {
                    $c->submitted_at = now();
                    $c->claim_reference = $data['claim_reference'] ?? $c->claim_reference;
                }),
                default => null,
            };

            /**
             * When we expect to hear back. Set explicitly if a real commitment was given, otherwise
             * filled from config — because an "awaiting dealer" with no due date is a column things
             * rot in, and three weeks of silence stops looking abnormal remarkably fast.
             */
            if (in_array($stage, WarrantyClaim::AWAITING_PROVIDER_STAGES, true)) {
                $case->provider_response_due_on = $data['provider_response_due_on']
                    ?? $case->provider_response_due_on
                    ?? now()->addDays((int) config('warranty.provider_response_days', 7))->toDateString();
            }

            if (! empty($data['diagnosis'])) {
                $case->diagnosis = $data['diagnosis'];
            }

            $case->updated_by      = $actor->id;
            $case->updated_by_name = $actor->name ?: $actor->email;
            $case->save();

            $vehicle = $case->vehicle;

            $event = match ($stage) {
                WarrantyClaim::STAGE_AUTHORIZED         => VehicleLogEvent::EVENT_WARRANTY_AUTHORIZED,
                WarrantyClaim::STAGE_SENT_TO_PROVIDER   => VehicleLogEvent::EVENT_WARRANTY_SENT_TO_PROVIDER,
                WarrantyClaim::STAGE_CLAIM_SUBMITTED    => VehicleLogEvent::EVENT_WARRANTY_CLAIM_SUBMITTED,
                default                                 => VehicleLogEvent::EVENT_WARRANTY_CASE_OPENED,
            };

            $this->audit($vehicle, $event, $actor, $case,
                "Warranty case #{$case->id} → {$stage}", [
                    'stage'             => $stage,
                    'authorization_ref' => $case->authorization_ref,
                    'claim_reference'   => $case->claim_reference,
                    'note'              => $data['note'] ?? null,
                ]);

            // An authorisation landing is the one rung the WORKSHOP has to act on — the car can now
            // physically go. Everything else stays with the warranty desk so the bell is not noise.
            $audience = $stage === WarrantyClaim::STAGE_AUTHORIZED
                ? WarrantyResponsibility::warrantyAndMaintenance()
                : WarrantyResponsibility::warrantyResponsible();

            $this->notify($audience, $case, $vehicle,
                'warranty_case_' . $stage,
                $stage === WarrantyClaim::STAGE_AUTHORIZED ? 'info' : 'warning',
                $this->stageTitle($stage),
                $this->sentence($vehicle, $case, $this->stageAsk($stage)),
                $actor,
            );

            return $case->fresh();
        });
    }

    // ── The end ────────────────────────────────────────────────────────────────────────────────

    /**
     * Write down what the case actually got us, then close it.
     *
     * TWO FIGURES, KEPT APART. `recovered` is money that came back (a credit, a refund); `avoided`
     * is money we never had to spend because they did the work. A dealer replacing a gearbox for
     * free recovers nothing and avoids a great deal — reporting them as one number would make the
     * feature's own value unauditable, and adding them in the database would double-count every case
     * where both happen. They are added only at the point of reporting. @see WarrantyClaim::totalBenefit()
     */
    public function recordRecovery(WarrantyClaim $case, array $data, User $actor): WarrantyClaim
    {
        return DB::transaction(function () use ($case, $data, $actor) {
            $case->recovered_amount = $data['recovered_amount'] ?? $case->recovered_amount;
            $case->avoided_amount   = $data['avoided_amount'] ?? $case->avoided_amount;
            $case->currency         = $data['currency'] ?? $case->currency ?? 'AED';
            $case->remedy           = $data['remedy'] ?? $case->remedy;
            $case->stage            = WarrantyClaim::STAGE_RECOVERY_RECORDED;
            $case->updated_by       = $actor->id;
            $case->updated_by_name  = $actor->name ?: $actor->email;
            $case->save();

            $vehicle = $case->vehicle;

            $this->audit($vehicle, VehicleLogEvent::EVENT_WARRANTY_RECOVERY, $actor, $case,
                sprintf('Warranty recovery recorded — %s %s recovered, %s avoided',
                    $case->currency,
                    number_format((float) $case->recovered_amount, 2),
                    number_format((float) $case->avoided_amount, 2)),
                [
                    'recovered_amount' => $case->recovered_amount !== null ? (float) $case->recovered_amount : null,
                    'avoided_amount'   => $case->avoided_amount !== null ? (float) $case->avoided_amount : null,
                    'remedy'           => $case->remedy,
                ]);

            // Finance cares about this one and about no other rung on the ladder.
            $this->notify(array_merge(WarrantyResponsibility::warrantyResponsible(), ['billing.view']),
                $case, $vehicle, 'warranty_recovery_recorded', 'success',
                'Warranty recovery recorded',
                $this->sentence($vehicle, $case, sprintf('%s %s recovered · %s avoided.',
                    $case->currency,
                    number_format((float) $case->recovered_amount, 2),
                    number_format((float) $case->avoided_amount, 2))),
                $actor,
            );

            return $case->fresh();
        });
    }

    /** Finished, whatever the answer was. A closing reason is kept because "why did this end?" is asked. */
    public function close(WarrantyClaim $case, User $actor, ?string $reason = null): WarrantyClaim
    {
        return DB::transaction(function () use ($case, $actor, $reason) {
            $case->stage          = WarrantyClaim::STAGE_CLOSED;
            $case->closed_at      = now();
            $case->closed_by      = $actor->id;
            $case->closed_by_name = $actor->name ?: $actor->email;
            if ($reason) {
                $case->outcome_reason = trim($reason);
            }
            $case->save();

            $this->audit($case->vehicle, VehicleLogEvent::EVENT_WARRANTY_CASE_CLOSED, $actor, $case,
                "Warranty case #{$case->id} closed" . ($reason ? " — {$reason}" : ''),
                ['outcome' => $case->outcome, 'reason' => $reason]);

            return $case->fresh();
        });
    }

    // ── internals ──────────────────────────────────────────────────────────────────────────────

    /**
     * One audit row on the CAR's timeline, carrying the case id and the structured facts in meta.
     *
     * On the vehicle rather than only on the case because the question these rows answer — "why did
     * we pay for this?" — is asked about a car, months later, by somebody who has never heard of the
     * case. Best-effort inside VehicleLogService: an audit write must never sink the transition that
     * produced it.
     */
    private function audit(?Vehicle $vehicle, string $event, User $actor, WarrantyClaim $case, string $description, array $meta = []): void
    {
        if (! $vehicle) {
            return;
        }

        $this->log->recordVehicle($vehicle, $event, $actor, [
            'source_tag'  => 'warranty',
            'description' => $description,
            'meta'        => array_merge([
                'warranty_case_id' => $case->id,
                'warranty_id'      => $case->warranty_id,
                'stage'            => $case->stage,
                'subject'          => $case->subject,
            ], $meta),
        ]);
    }

    /** Tell the warranty desk — the audience for most of the ladder. */
    private function tellWarrantyDesk(WarrantyClaim $case, ?Vehicle $vehicle, string $type, string $severity, string $title, string $body, User $actor): void
    {
        $this->notify(WarrantyResponsibility::warrantyResponsible(), $case, $vehicle, $type, $severity, $title, $body, $actor);
    }

    /**
     * Fan an alert out to an AUDIENCE DEFINED BY PERMISSION.
     *
     * `$actor->id` is excluded throughout: the person who just took the action does not need a
     * notification telling them they took it, and the fastest way to make a bell ignorable is to
     * fill it with echoes of the user's own clicks.
     *
     * The deep link is the whole point of a warranty alert — a card that says "review coverage"
     * without landing on the case is a card that generates a search.
     */
    private function notify(array $permissions, WarrantyClaim $case, ?Vehicle $vehicle, string $type, string $severity, string $title, string $body, User $actor): void
    {
        $this->notifier->notifyByAnyPermission($permissions, [
            'type'     => $type,
            'category' => 'warranty',
            'severity' => $severity,
            'title'    => $title,
            'body'     => $body,
            'url'      => '/warranty/cases/' . $case->id,
            // Keyed on the case AND the stage so each transition is its own card, and re-running a
            // transition cannot raise a duplicate.
            'key'      => 'warranty_case:' . $case->id . ':' . $case->stage,
            'icon'     => 'shield',
            'meta'     => [
                'warranty_case_id' => $case->id,
                'warranty_id'      => $case->warranty_id,
                'vehicle_id'       => $case->vehicle_id,
                'plate'            => $vehicle?->plate_no,
                'stage'            => $case->stage,
                'subject'          => $case->subject,
            ],
        ], $actor->id);
    }

    /**
     * The card's body: the car, the problem, the reading, and what the cover says.
     *
     * Composed here rather than in the UI because a notification body is a stored string — it has to
     * read correctly in six months when the warranty may have been edited. The *reason codes* stay
     * structured in `meta` for anything that needs to branch on them.
     */
    private function sentence(?Vehicle $vehicle, WarrantyClaim $case, string $ask): string
    {
        $bits = array_filter([
            $vehicle ? trim(($vehicle->make ?: '') . ' ' . ($vehicle->model ?: '')) ?: null : null,
            $vehicle?->plate_no ? 'Plate ' . $vehicle->plate_no : null,
            $case->subject,
            $case->claim_odometer !== null ? number_format($case->claim_odometer) . ' km' : null,
            $case->warranty?->expires_on ? 'Cover to ' . $case->warranty->expires_on->toDateString() : null,
        ]);

        return implode(' · ', $bits) . ' — ' . $ask;
    }

    private function stageTitle(string $stage): string
    {
        return match ($stage) {
            WarrantyClaim::STAGE_AUTHORIZATION_REQUESTED => 'Warranty authorization requested',
            WarrantyClaim::STAGE_AUTHORIZED              => 'Warranty authorization received',
            WarrantyClaim::STAGE_SENT_TO_PROVIDER        => 'Sent to the warranty provider',
            WarrantyClaim::STAGE_REPAIR_IN_PROGRESS      => 'Warranty repair in progress',
            WarrantyClaim::STAGE_REPAIR_COMPLETED        => 'Warranty repair completed',
            WarrantyClaim::STAGE_CLAIM_SUBMITTED         => 'Warranty claim submitted',
            default                                      => 'Warranty case updated',
        };
    }

    /** What the person receiving the card is actually being asked to do next. */
    private function stageAsk(string $stage): string
    {
        return match ($stage) {
            WarrantyClaim::STAGE_AUTHORIZATION_REQUESTED => 'Chase the provider for the go-ahead.',
            WarrantyClaim::STAGE_AUTHORIZED              => 'Authorized — the car can go.',
            WarrantyClaim::STAGE_SENT_TO_PROVIDER        => 'With the provider — track the repair.',
            WarrantyClaim::STAGE_REPAIR_COMPLETED        => 'Repair done — submit the claim.',
            WarrantyClaim::STAGE_CLAIM_SUBMITTED         => 'Claim submitted — record their answer when it lands.',
            default                                      => 'Review the case.',
        };
    }
}
