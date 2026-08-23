<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Contract;
use App\Models\ContractMileageReading;
use App\Models\ContractOilDecision;
use App\Models\OilRecallTask;
use App\Services\OilChangeProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The ops surface for the mid-rental oil chase.
 *
 * `index()` is the queue Leen and Marwa work from — every car currently out, with where its
 * odometer has probably reached and whether we are still inside the limit. `reading()` is the
 * one write: the number the customer gave over the phone. Storing it re-anchors the projection
 * immediately, which is also what re-arms the alert for the next round.
 *
 * Everything here consumes OilChangeProjectionService; none of it recomputes the rule locally.
 */
class OilProjectionController extends Controller
{
    public function __construct(private OilChangeProjectionService $projection) {}

    /**
     * Every currently-open rental with its projection, most urgent first.
     *
     * `?status=chase_due` narrows to the cars actually needing a call — the rest are shown so the
     * page can be read as "here is the whole fleet position", not just a list of problems.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $only = (string) $request->query('status', '');

            $open = Contract::query()
                ->currentlyOpen()
                ->where('contract_type', 'C')
                ->whereNotNull('vehicle_id')
                // The customer's phone numbers and account number travel with the row: this board is
                // worked by phoning people, and a call list that makes someone open a second screen
                // to find the number is a call list nobody uses.
                ->with([
                    // odometer_source + odometer_reading_on are NOT optional here: they are what let
                    // the projection anchor on the Oil Change sheet's reading instead of a months-old
                    // handover. Leaving them out of this list does not fail — Eloquent returns null
                    // for an unselected column — it just silently drops every car back to the
                    // handover anchor, which is exactly the stale board this list once produced.
                    'vehicle:id,code,make,model,plate_no,last_service_odometer,service_interval_km,odometer,odometer_source,odometer_reading_on',
                    'customer:id,name_en,customer_no,mobile1,mobile2,whatsapp',
                ])
                ->get();

            // "Has the system already asked for a test on this car?" — answered for the WHOLE board
            // in one query rather than one per card, then handed to each row. A controller must see
            // that before deciding, or they file a second request for a car that already has one.
            $pendingTests = \App\Models\Maintenance::query()
                ->whereIn('vehicle_id', $open->pluck('vehicle_id')->filter()->unique())
                ->where('workflow_status', \App\Models\Maintenance::WF_PENDING_REVIEW)
                ->whereNotIn('id', ContractOilDecision::whereNotNull('inspection_ticket_id')->select('inspection_ticket_id'))
                ->orderBy('id')
                ->get(['id', 'vehicle_id', 'created_at', 'trigger_reason', 'request_origin', 'customer_complaint'])
                ->groupBy('vehicle_id');

            $rows = $open
                ->map(function (Contract $c) use ($pendingTests) {
                    $p = $this->projection->project($c);

                    return [
                        'contract_id'   => $c->id,
                        'contract_no'   => $c->contract_no,
                        'customer'      => $c->customer?->name_en,
                        // Who to ring, and under which account. `customer_phone` is the number to
                        // dial first (mobile1, then whatsapp, then the second mobile) — the raw
                        // fields ride along so a call list can show every number we hold.
                        'customer_no'      => $c->customer?->customer_no,
                        'customer_phone'   => $c->customer?->mobile1
                                            ?: ($c->customer?->whatsapp ?: $c->customer?->mobile2),
                        'customer_mobile2' => $c->customer?->mobile2,
                        'customer_whatsapp'=> $c->customer?->whatsapp,
                        // What the branch wrote on the contract in OM ("BETA TEAM / ONLINE / CARDO").
                        // It says which team owns this rental and how it was booked, so the person
                        // dialling knows whose customer they are ringing before they ring them.
                        // Verbatim from the API's `Remarks` — never parsed, never interpreted.
                        'contract_remarks' => $this->cleanRemarks($c->remarks),
                        'vehicle_id'    => $c->vehicle?->id,
                        'plate'         => $c->vehicle?->plate_no,
                        'car'           => trim(($c->vehicle?->make ?? '') . ' ' . ($c->vehicle?->model ?? '')),
                        'out_date'      => $c->out_date?->toDateString(),
                        // The odometer the SYSTEM holds — refreshed from this handover, so during an
                        // open rental it is a rental's worth of stale. Shown on the board purely so
                        // the caller can see how far the projection has moved past what we last stored.
                        'stored_odometer' => $c->vehicle?->odometer,
                        // The oil limit's own ingredients, so the board can print "last service
                        // 20,535 + 7,000" instead of a figure that looks like it came from nowhere.
                        'last_service_odometer' => $c->vehicle?->last_service_odometer,
                        'service_interval_km'   => $c->vehicle?->service_interval_km,
                        'projection'    => $p,
                        // The request already waiting in /inspection-review for this car, if any.
                        'pending_test_request' => $this->pendingTestPayload(
                            $pendingTests->get($c->vehicle_id)?->first()
                        ),
                    ];
                })
                ->when($only !== '', fn ($rows) => $rows->where('projection.status', $only))
                // Ordered by the operational lane: interventions first, then the cars arriving
                // today, then the calls to make, then everything running its course. No-data cars
                // last — there is nothing to act on until someone captures a handover reading.
                ->sortBy(fn ($r) => match ($r['projection']['lane'] ?? null) {
                    OilChangeProjectionService::LANE_ACTION_REQUIRED   => 0,
                    OilChangeProjectionService::LANE_SERVICE_ON_RETURN => 1,
                    OilChangeProjectionService::LANE_CALL_CUSTOMER     => 2,
                    OilChangeProjectionService::LANE_SAFE              => 3,
                    default                                            => 4,
                })
                ->values();

            $summary = [
                'chase_due' => $rows->where('projection.status', 'chase_due')->count(),
                'ok'        => $rows->where('projection.status', 'ok')->count(),
                'no_data'   => $rows->where('projection.status', 'no_data')->count(),
                'total'     => $rows->count(),
                // The decision axis — what the board is actually worked from. `decision_required`
                // counts only the cars a person can actually answer for TODAY (projection.decision_ready):
                // any long rental is arithmetically certain to bust its allowance, so counting the raw
                // state would put most of the fleet under "Decision needed" every morning and the queue
                // would be ignored within a week. The rest are counted as what they are — cars whose
                // number needs refreshing before anyone can decide anything.
                'decision_required'          => $rows->where('projection.decision_ready', true)->count(),
                'awaiting_reading'           => $rows->where('projection.oil_status', OilChangeProjectionService::OIL_DECISION_REQUIRED)
                                                     ->where('projection.decision_ready', false)->count(),
                'recall_required'            => $rows->where('projection.oil_status', OilChangeProjectionService::OIL_RECALL_REQUIRED)->count(),
                'service_required_on_return' => $rows->where('projection.oil_status', OilChangeProjectionService::OIL_SERVICE_ON_RETURN)->count(),
                'within_tolerance'           => $rows->where('projection.oil_status', OilChangeProjectionService::OIL_WITHIN_TOLERANCE)->count(),
                // The operational lanes — the tabs the board is worked from. Counted from the same
                // `lane` field that routes each card, so the tab count can never disagree with the
                // cards inside it.
                'lanes' => [
                    'action_required'   => $rows->where('projection.lane', OilChangeProjectionService::LANE_ACTION_REQUIRED)->count(),
                    'service_on_return' => $rows->where('projection.lane', OilChangeProjectionService::LANE_SERVICE_ON_RETURN)->count(),
                    'call_customer'     => $rows->where('projection.lane', OilChangeProjectionService::LANE_CALL_CUSTOMER)->count(),
                    'safe'              => $rows->where('projection.lane', OilChangeProjectionService::LANE_SAFE)->count(),
                    'no_data'           => $rows->where('projection.lane', OilChangeProjectionService::LANE_NO_DATA)->count(),
                ],
            ];

            return ResponseHelper::SuccessResponse([
                'contracts' => $rows,
                'summary'   => $summary,
                'model'     => [
                    'rate_km_per_day' => $this->projection->rate(),
                    'grace_km'        => $this->projection->grace(),
                    'tolerance_km'    => $this->projection->grace(),
                    // Traceability: the page must be able to say where every number came from.
                    'basis'           => 'expected = anchor odometer + days since anchor × rate;'
                                       . ' oil limit = last service odometer + interval (Oil Change sheet);'
                                       . ' allowed max = oil limit + tolerance;'
                                       . ' expected on return = expected + remaining rental days × rate',
                ],
            ]);
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** One contract's projection plus the readings behind it (the audit trail for the number). */
    public function show(Contract $contract): JsonResponse
    {
        try {
            return ResponseHelper::SuccessResponse([
                'contract_id' => $contract->id,
                'projection'  => $this->projection->project($contract),
                'pending_test_request' => $this->pendingTestPayload(
                    $this->projection->pendingTestRequest($contract->vehicle_id)
                ),
                'readings'    => $contract->mileageReadings()
                    ->orderByDesc('reported_on')->orderByDesc('id')
                    ->get(['id', 'odometer', 'reported_on', 'source', 'reported_by', 'note', 'recorded_by', 'created_at']),
            ]);
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Record the mileage the customer reported. This becomes the new projection anchor.
     *
     * Guards worth their weight: the reading may not run backwards past the anchor it replaces
     * (a customer misreading 41,000 as 14,000 would otherwise push the next chase months out and
     * silently park the car's oil life), and it may not be dated before the car went out or in
     * the future. A number that fails these is a data-entry problem, not a mileage fact.
     */
    public function reading(Request $request, Contract $contract): JsonResponse
    {
        try {
            $data = $request->validate([
                'odometer'    => ['required', 'integer', 'min:2', 'max:9999999'],
                'reported_on' => ['nullable', 'date'],
                'reported_by' => ['nullable', 'string', 'max:120'],
                'source'      => ['nullable', 'in:' . ContractMileageReading::SOURCE_CUSTOMER . ',' . ContractMileageReading::SOURCE_STAFF],
                'note'        => ['nullable', 'string', 'max:1000'],
            ]);

            if (! $contract->vehicle_id) {
                throw ValidationException::withMessages(['odometer' => 'This contract has no vehicle.']);
            }

            $reportedOn = isset($data['reported_on'])
                ? Carbon::parse($data['reported_on'])->startOfDay()
                : Carbon::now()->startOfDay();

            if ($reportedOn->isFuture()) {
                throw ValidationException::withMessages(['reported_on' => 'A mileage reading cannot be dated in the future.']);
            }
            if ($contract->out_date && $reportedOn->lt(Carbon::parse($contract->out_date)->startOfDay())) {
                throw ValidationException::withMessages(['reported_on' => 'The reading predates the day the car went out.']);
            }

            $anchor = $this->projection->anchor($contract);
            if ($anchor && $data['odometer'] < $anchor['odometer']) {
                throw ValidationException::withMessages([
                    'odometer' => 'Mileage cannot go backwards — the last known reading is '
                                . number_format($anchor['odometer']) . ' km.',
                ]);
            }

            $reading = ContractMileageReading::create([
                'contract_id' => $contract->id,
                'vehicle_id'  => $contract->vehicle_id,
                'odometer'    => $data['odometer'],
                'reported_on' => $reportedOn->toDateString(),
                'recorded_by' => $request->user()?->id,
                'reported_by' => $data['reported_by'] ?? null,
                'source'      => $data['source'] ?? ContractMileageReading::SOURCE_CUSTOMER,
                'note'        => $data['note'] ?? null,
            ]);

            // Recalculate straight away. Saving the number is only half the job: the caller needs to
            // know, on the same screen and before they hang up, whether this car now finishes inside
            // the allowance or has become a decision. That verdict — `oil_status` — is the point of
            // making the call at all, so the write path always returns the full recomputed answer.
            return ResponseHelper::SuccessResponse([
                'reading'    => $reading,
                'projection' => $this->projection->project($contract->fresh()),
            ]);
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The Controllers' recall queue: cars whose customers need phoning about getting them back.
     *
     * Deliberately thin — a recall task is a conversation to have, so the row carries the figures
     * that conversation is about and nothing else. There is no route, no driver and no ETA here;
     * that is a logistics concern the fleet has no module for yet.
     */
    public function recallTasks(Request $request): JsonResponse
    {
        try {
            $all = $request->boolean('all');

            $tasks = OilRecallTask::query()
                ->when(! $all, fn ($q) => $q->open())
                ->with(['vehicle:id,code,make,model,plate_no', 'contract:id,contract_no,customer_id', 'contract.customer:id,name_en'])
                ->orderByRaw("FIELD(status, 'open', 'contacted', 'done', 'cancelled')")
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->map(fn (OilRecallTask $t) => [
                    'id'              => $t->id,
                    'status'          => $t->status,
                    'reason_code'     => $t->reason_code,
                    'contract_id'     => $t->contract_id,
                    'contract_no'     => $t->contract?->contract_no,
                    'customer'        => $t->contract?->customer?->name_en,
                    'vehicle_id'      => $t->vehicle_id,
                    'plate'           => $t->vehicle?->plate_no,
                    'car'             => trim(($t->vehicle?->make ?? '') . ' ' . ($t->vehicle?->model ?? '')),
                    // The frozen figures the caller reads out.
                    'customer_reading'         => $t->customer_reading,
                    'customer_reading_on'      => optional($t->customer_reading_on)->toDateString(),
                    'oil_limit'                => $t->oil_limit,
                    'allowed_max'              => $t->allowed_max,
                    'expected_return_odometer' => $t->expected_return_odometer,
                    'over_tolerance_km'        => $t->overToleranceKm(),
                    'remaining_days'           => $t->remaining_days,
                    'created_by'               => $t->created_by_name,
                    'decided_at'               => optional($t->decided_at)->toDateTimeString(),
                    'note'                     => $t->note,
                    'outcome_note'             => $t->outcome_note,
                    'claimed_by'               => $t->claimedBy?->name,
                    'completed_at'             => optional($t->completed_at)->toDateTimeString(),
                ]);

            return ResponseHelper::SuccessResponse([
                'tasks'   => $tasks,
                'summary' => [
                    'open'      => OilRecallTask::where('status', OilRecallTask::STATUS_OPEN)->count(),
                    'contacted' => OilRecallTask::where('status', OilRecallTask::STATUS_CONTACTED)->count(),
                ],
            ]);
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Move a recall call along: reached the customer, or finished with it. */
    public function updateRecallTask(Request $request, OilRecallTask $task): JsonResponse
    {
        try {
            $data = $request->validate([
                'status'       => ['required', 'in:' . implode(',', OilRecallTask::STATUSES)],
                'outcome_note' => ['nullable', 'string', 'max:1000'],
            ]);

            $user = $request->user();
            $task->status = $data['status'];
            if (array_key_exists('outcome_note', $data) && $data['outcome_note'] !== null) {
                $task->outcome_note = $data['outcome_note'];
            }

            // First person to move it off `open` owns the call from then on.
            if ($task->claimed_by === null && $data['status'] !== OilRecallTask::STATUS_OPEN) {
                $task->claimed_by = $user?->id;
                $task->claimed_at = now();
            }

            if (in_array($data['status'], [OilRecallTask::STATUS_DONE, OilRecallTask::STATUS_CANCELLED], true)) {
                $task->completed_at = now();
                $task->completed_by = $user?->id;
            } else {
                // Re-opening clears the completion stamps rather than leaving a task that claims to
                // be both open and finished.
                $task->completed_at = null;
                $task->completed_by = null;
            }

            $task->save();

            return ResponseHelper::SuccessResponse(['task' => $task->fresh()]);
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Take the call on a rental that cannot finish inside the oil tolerance.
     *
     * Two answers only, because operationally there are only two: bring the car back now, or accept
     * the overrun and service it at close. The service refuses anything else, and refuses the whole
     * question for a car that is projected to finish inside the allowance — that car is not a
     * decision, it is an oil change already booked for its return.
     */
    public function decide(Request $request, Contract $contract): JsonResponse
    {
        try {
            $data = $request->validate([
                'decision' => ['required', 'in:' . implode(',', ContractOilDecision::DECISIONS)],
                'note'     => ['nullable', 'string', 'max:1000'],
                // Leen's two operational choices, taken in the same breath as the decision.
                // `test_required` null = "don't change what the fleet already believes" (the service
                // defaults it to yes when the system already has a test request open on this car).
                'test_required'    => ['nullable', 'boolean'],
                'service_location' => ['nullable', 'in:' . implode(',', ContractOilDecision::LOCATIONS)],
            ]);

            $decision = $this->projection->decide(
                $contract,
                $data['decision'],
                $request->user(),
                $data['note'] ?? null,
                [
                    'test_required'    => $data['test_required'] ?? null,
                    'service_location' => $data['service_location'] ?? null,
                ],
            );

            return ResponseHelper::SuccessResponse([
                'decision'   => $decision,
                'projection' => $this->projection->project($contract->fresh()),
            ]);
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The contract remark exactly as OM holds it, or null when there is nothing written.
     *
     * Whitespace only — collapsing runs of spaces and newlines so a remark typed across two lines
     * still reads as one line in a table cell. The WORDS are never touched: "BETA TEAM / ONLINE /
     * CARDO" is a branch's own shorthand and this app does not pretend to know what it decodes to.
     */
    private function cleanRemarks(?string $remarks): ?string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', (string) $remarks));

        return $clean === '' ? null : $clean;
    }

    /**
     * The test request the fleet ALREADY has open on this car, if any.
     *
     * Read before deciding, so the person recalling a car is told "the system already asked for a
     * test on this one" instead of unknowingly filing a second card for the same car in the same
     * queue. Shaped as the dialog reads it: what it is, who asked, and why.
     *
     * @return array<string,mixed>|null
     */
    private function pendingTestPayload(?\App\Models\Maintenance $req): ?array
    {
        if (! $req) {
            return null;
        }

        return [
            'id'             => $req->id,
            'requested_at'   => optional($req->created_at)->toIso8601String(),
            'trigger_reason' => $req->trigger_reason,
            'request_origin' => $req->request_origin,
            // The system's own words for why this car was flagged — the sentence the review card
            // shows. Trimmed, because it is read inside a dialog, not on a page of its own.
            'reason'         => \Illuminate\Support\Str::limit((string) $req->customer_complaint, 220),
        ];
    }

    /**
     * SALES OK — Sales have agreed the return with the customer, so the recall may now move.
     *
     * The one gate between an oil decision and a driver being sent to a customer's doorstep. It is
     * a confirmation, not a data-entry form: the actor is authenticated (the audit event records
     * who), the note is optional colour. Safe to click twice — the service is idempotent, so a
     * double tap never raises a second collection or rings the Supervisors again.
     */
    public function confirmSales(Request $request, Contract $contract): JsonResponse
    {
        try {
            $data = $request->validate([
                'note' => ['nullable', 'string', 'max:1000'],
            ]);

            $decision = $this->projection->confirmSales($contract, $request->user(), $data['note'] ?? null);

            return ResponseHelper::SuccessResponse([
                'decision'   => $decision,
                'recall'     => $this->projection->recallState($decision),
                'projection' => $this->projection->project($contract->fresh()),
            ]);
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * THE OIL IS CHANGED — the one write that ends the follow-up and moves the car's own schedule.
     *
     * Takes the odometer the change was performed at and nothing else that matters: from that single
     * number the service re-anchors the vehicle (next change = this reading + its interval), stores
     * the reading so the projection recomputes from a fact, and stands down the recall, the
     * collection and the announcing inspection request.
     */
    public function oilChanged(Request $request, Contract $contract): JsonResponse
    {
        try {
            $data = $request->validate([
                'odometer' => ['required', 'integer', 'min:2', 'max:9999999'],
                'note'     => ['nullable', 'string', 'max:1000'],
            ]);

            $decision = $this->projection->recordOilChange(
                $contract,
                (int) $data['odometer'],
                $request->user(),
                $data['note'] ?? null,
            );

            $vehicle = $contract->fresh()->vehicle;

            return ResponseHelper::SuccessResponse([
                'decision'   => $decision,
                'projection' => $this->projection->project($contract->fresh()),
                // What the car's own profile now says — the point of recording it at all.
                'vehicle'    => [
                    'id'                    => $vehicle?->id,
                    'last_service_odometer' => $vehicle?->last_service_odometer,
                    'service_interval_km'   => $vehicle?->service_interval_km,
                    'next_service_odometer' => $vehicle ? $this->projection->oilLimit($vehicle) : null,
                    // The board's own ceiling, restated so nobody confuses the two: the service
                    // point is the car's, the +grace allowance is the projection's.
                    'allowed_max'           => $vehicle ? $this->projection->threshold($vehicle) : null,
                ],
            ]);
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * THE CAR IS BACK WITH THE CUSTOMER — the step that actually ends a recall.
     *
     * We took a car off a paying rental. The oil change is what the fleet wanted; the customer wants
     * their car. Until this is pressed the chase keeps ringing every few minutes, because a car
     * standing in our yard with its work finished is invisible to every other part of the system.
     */
    public function returnedToCustomer(Request $request, Contract $contract): JsonResponse
    {
        try {
            $data = $request->validate([
                'note' => ['nullable', 'string', 'max:1000'],
            ]);

            $decision = $this->projection->markReturnedToCustomer($contract, $request->user(), $data['note'] ?? null);

            return ResponseHelper::SuccessResponse([
                'decision'   => $decision,
                'recall'     => $this->projection->recallState($decision),
                'projection' => $this->projection->project($contract->fresh()),
            ]);
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * What the car owes once it has been collected — the dispatcher's instruction to the driver.
     *
     * `test_required` is the ONLY field. The oil change is not accepted here in any form: this
     * recall exists because the oil lifecycle asked for it, so the requirement is derived from the
     * decision itself and there is no request body that can switch it off.
     */
    public function collectionInstructions(Request $request, Contract $contract): JsonResponse
    {
        try {
            $data = $request->validate([
                'test_required' => ['required', 'boolean'],
            ]);

            $decision = $this->projection->setCollectionInstructions(
                $contract,
                (bool) $data['test_required'],
                $request->user(),
            );

            return ResponseHelper::SuccessResponse([
                'decision'   => $decision,
                'recall'     => $this->projection->recallState($decision),
                'projection' => $this->projection->project($contract->fresh()),
            ]);
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
