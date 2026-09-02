<?php

namespace App\Services\Warranty;

use App\Exceptions\WarrantyGateException;
use App\Models\PartRequest;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Models\WarrantyClaim;
use App\Services\VehicleLogService;
use App\Support\WarrantyCoverage;
use App\Support\WarrantyResponsibility;
use Illuminate\Validation\ValidationException;

/**
 * THE GUARDRAIL. The one place that stands between "this car needs a part" and "we bought a part",
 * and asks the question the whole feature exists for: could somebody else be paying for this?
 *
 * Evidence class: D (derived) with one F output — the override, which is a person's decision.
 * Produces: the warranty stamp on part_requests, coverage reviews, override audit rows.
 * Consumes: warranties, warranty_claims, vehicles.
 *
 * ── WHERE IT SITS, AND WHY THERE ───────────────────────────────────────────────────────────────
 *
 * In PartWorkflowService::createRequest — the single door every purchase request in this system
 * comes through, whatever raised it. That matters more than it sounds: the spare-key flow, the
 * inspector's required parts and the garage's diagnosis all reach procurement through that one
 * method, so guarding it guards all three WITHOUT any of them knowing this class exists. A guard in
 * the controller would have covered the form and missed the other two.
 *
 * ── WHAT IT DOES, IN ONE SENTENCE PER OUTCOME ──────────────────────────────────────────────────
 *
 *   NOT_COVERED   Nothing happens. The verdict is stamped on the request and the existing workflow
 *                 proceeds byte-for-byte as it did before this feature existed. THIS IS THE PATH
 *                 ALMOST EVERY REQUEST TAKES, and keeping it inert — one indexed query, no writes,
 *                 no notifications — is why the guard can sit on the hot path at all.
 *
 *   UNKNOWN       Stop. Open a coverage review, notify the warranty desk, and refuse the request
 *                 with a 422 the form renders as "Warranty Coverage Review Required". Nobody has
 *                 decided this yet, and a purchase order is not a way of deciding it.
 *
 *   COVERED       Stop, harder. There is a live promise that names this part. Buying it is spending
 *                 our money on somebody else's obligation.
 *
 * ── THE OVERRIDE IS THE POINT, NOT THE LOOPHOLE ────────────────────────────────────────────────
 *
 * There will be a Thursday afternoon when the car has to move and the dealer will not answer the
 * phone. A gate with no override does not prevent that purchase; it moves it outside the system,
 * where nothing is recorded and nobody can learn anything. So the override is allowed, and it is
 * expensive in exactly the right currency: it needs the `warranty.override` permission, it needs a
 * typed reason, and it writes a named, dated row onto the car's timeline. An override that costs us
 * a claim becomes a question with somebody's name on it — which is the only mechanism that has ever
 * made people stop and ring the dealer first.
 */
class WarrantyProcurementGuard
{
    public function __construct(
        private WarrantyCoverageEngine $engine,
        private WarrantyCaseService $cases,
        private VehicleLogService $log,
    ) {}

    /**
     * Run the gate for a purchase request that is about to be created.
     *
     * @param  array $data the request payload; `warranty_override_reason` opts into the override
     * @return array{verdict:string, reason_code:string, case_id:?int, override:bool} the stamp to
     *         freeze onto the request. Empty verdict is impossible — every path returns one.
     * @throws ValidationException when the gate is closed and no valid override was supplied. The
     *         message shape is deliberately the standard {message, errors} Laravel envelope plus a
     *         `warranty` payload the form reads to render the review card rather than a bare toast.
     */
    public function check(Vehicle $vehicle, array $data, User $actor): array
    {
        $subject    = CoverageSubject::fromRequestData($data);
        $assessment = $this->engine->assess($vehicle, $subject);

        // ── The ordinary path: nobody else owes us this. Untouched behaviour. ──────────────────
        if (! $assessment->blocksProcurement()) {
            return [
                'verdict'     => $assessment->verdict,
                'reason_code' => $assessment->reasonCode,
                'case_id'     => $assessment->existingCase?->id,
                'override'    => false,
            ];
        }

        // ── The gate is closed. Make sure the question is somebody's job before refusing. ──────
        //
        // The review is opened BEFORE the exception is thrown, and that ordering is deliberate: a
        // refusal that leaves no work item behind is a dead end the user works around, and the whole
        // value of stopping here is that somebody is now holding the question.
        $case = $assessment->existingCase;

        if (! $case && $assessment->hasLiveCover()) {
            $case = $assessment->isCovered()
                ? $this->cases->openCoveredCase($vehicle, $assessment, $subject, $actor, WarrantyClaim::ORIGIN_PROCUREMENT, [
                    'failure_description' => $data['reason'] ?? null,
                ])
                : $this->cases->openCoverageReview($vehicle, $assessment, $subject, $actor, WarrantyClaim::ORIGIN_PROCUREMENT, [
                    'failure_description' => $data['reason'] ?? null,
                ]);
        }

        // ── The override ───────────────────────────────────────────────────────────────────────
        $reason = trim((string) ($data['warranty_override_reason'] ?? ''));

        if ($reason !== '') {
            // Permission first, so a user without the authority gets told THAT rather than being
            // asked for a longer reason they were never allowed to give.
            if (! $actor->can(WarrantyResponsibility::OVERRIDE)) {
                throw ValidationException::withMessages([
                    'warranty_override_reason' => 'You do not have the authority to buy past a warranty. Ask somebody who holds warranty override, or wait for the coverage review.',
                ]);
            }

            // A reason of "asap" is not a reason. The bar is low but it is not zero: this sentence is
            // the entire defence when the claim we lost is reviewed.
            if (mb_strlen($reason) < 10) {
                throw ValidationException::withMessages([
                    'warranty_override_reason' => 'Say why we are paying for this instead of claiming it — in a sentence somebody can read back to you later.',
                ]);
            }

            $this->auditOverride($vehicle, $actor, $assessment, $case, $reason);

            return [
                'verdict'     => $assessment->verdict,
                'reason_code' => $assessment->reasonCode,
                'case_id'     => $case?->id,
                'override'    => true,
                'override_reason' => $reason,
            ];
        }

        // ── Refused. The payload below is what the form renders as the review card. ────────────
        //
        // NOT a ValidationException: nothing the user typed is wrong, and rendering this as a red
        // line under an input box would hide the only useful part of it — who to ring, and whether
        // this user may proceed anyway. @see WarrantyGateException
        throw new WarrantyGateException(
            $assessment->isCovered()
                ? 'Warranty coverage confirmed — this is the provider\'s responsibility. Raising a purchase request would spend our money on somebody else\'s obligation.'
                : 'Warranty coverage review required — nobody has decided whether this is covered. A review has been opened and the warranty desk notified.',
            array_merge($assessment->toArray(), [
                'case_id' => $case?->id,
                'vehicle' => ['id' => $vehicle->id, 'plate_no' => $vehicle->plate_no, 'odometer' => $vehicle->odometer],
                // What this user is allowed to do about it, decided SERVER-SIDE so the form can never
                // offer an override button the API will then refuse.
                'can_override' => $actor->can(WarrantyResponsibility::OVERRIDE),
                // The field the form must post back to proceed — named here so the client does not
                // hard-code a contract it cannot see.
                'override_field' => 'warranty_override_reason',
            ]),
        );
    }

    /**
     * Freeze the gate's answer onto the request that was just created.
     *
     * Separate from check() because the request does not exist yet when the gate runs — and it must
     * not, since the gate may refuse. Called immediately after creation, inside the same transaction.
     *
     * The override columns are written here by forceFill rather than being fillable, so that no
     * request body can ever stamp its own override. @see PartRequest::$fillable
     */
    public function stamp(PartRequest $request, array $stamp, User $actor): PartRequest
    {
        $request->forceFill([
            'warranty_verdict'     => $stamp['verdict'] ?? null,
            'warranty_reason_code' => $stamp['reason_code'] ?? null,
            'warranty_case_id'     => $stamp['case_id'] ?? null,
        ]);

        if (! empty($stamp['override'])) {
            $request->forceFill([
                'warranty_override_by'      => $actor->id,
                'warranty_override_by_name' => $actor->name ?: $actor->email,
                'warranty_override_at'      => now(),
                'warranty_override_reason'  => $stamp['override_reason'] ?? null,
            ]);
        }

        $request->save();

        return $request;
    }

    /**
     * The override's audit row — on the CAR's timeline, where somebody asking "why did we pay for
     * this?" will actually look, and carrying the verdict that was set aside so the question answers
     * itself without opening anything.
     *
     * Deliberately severity `critical` in the notification: this is the rarest event in the feature
     * and the only one that costs money by definition. If it starts arriving daily, that is
     * information — either the reviews are too slow or the override is being used as a shortcut, and
     * both are things the warranty desk needs to see rather than infer.
     */
    private function auditOverride(Vehicle $vehicle, User $actor, CoverageAssessment $assessment, ?WarrantyClaim $case, string $reason): void
    {
        $this->log->recordVehicle($vehicle, VehicleLogEvent::EVENT_WARRANTY_PROCUREMENT_OVERRIDE, $actor, [
            'source_tag'  => 'warranty',
            'description' => 'Purchase raised despite warranty cover — ' . $reason,
            'meta'        => [
                'verdict'          => $assessment->verdict,
                'reason_code'      => $assessment->reasonCode,
                'warranty_case_id' => $case?->id,
                'warranty_id'      => $assessment->decisive?->id,
                'override_by'      => $actor->name ?: $actor->email,
                'override_reason'  => $reason,
            ],
        ]);
    }
}
