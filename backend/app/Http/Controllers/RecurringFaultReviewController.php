<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\RecurringFaultReviewResource;
use App\Models\RecurringFaultReview;
use App\Services\RecurringFaultService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Recurring Fault Reviews — the management inbox for confirmed faults that recurred after a completed
 * repair (see RecurringFaultService). Read the open cases and record ONE decision per case. Cases are
 * OPENED automatically at the workshop-confirmation step, never here.
 */
class RecurringFaultReviewController extends Controller
{
    public function __construct(private RecurringFaultService $service)
    {
    }

    /** GET /recurring-fault-reviews — the review list, filterable by status / decision / vehicle. */
    public function index(Request $request)
    {
        try {
            $filters = $request->validate([
                'status'     => ['nullable', Rule::in(RecurringFaultReview::STATUSES)],
                'decision'   => ['nullable', Rule::in(RecurringFaultReview::DECISIONS)],
                'vehicle_id' => ['nullable', 'integer'],
            ]);

            // DETECTED cases, read from the workshop log AND the ticket workflow, with any management
            // ruling joined on. Previously this listed `recurring_fault_reviews` alone — a table that
            // had only ever held demo rows, because a case can only land in it through the ticket
            // workflow and this fleet's fault history lives in the imported sheet.
            $rows = $this->service->detectedCases()
                ->when($filters['vehicle_id'] ?? null, fn ($c, $id) => $c->where('vehicle_id', (int) $id))
                ->when($filters['status'] ?? null, fn ($c, $s) => $c->where('status', $s))
                ->when($filters['decision'] ?? null, fn ($c, $d) => $c->where('decision', $d))
                ->sortByDesc('latest_at')
                ->values();

            return ResponseHelper::SuccessResponse([
                'reviews' => $rows->map(fn ($c) => [
                    // null until someone rules on it — the row does not exist before that.
                    'id'           => $c->review_id,
                    'detected_key' => $c->key,
                    'status'       => $c->status,
                    'decision'     => $c->decision,

                    // THE EXACT FAULT, and when it came back. Both were the point of the rebuild: the
                    // page used to name the system and date the case, not the fault and its return.
                    'symptom'      => $c->symptom,
                    'category_key' => null,
                    'last_seen'    => $c->latest_at,

                    'vehicle' => [
                        'id' => $c->vehicle_id, 'plate' => $c->plate,
                        'make' => $c->make, 'model' => $c->model,
                    ],

                    'maintenance_id'          => $c->ticket_id,
                    'previous_maintenance_id' => $c->prev_ticket_id,
                    'previous_garage'         => $c->garage,
                    'previous_result'         => RecurringFaultReview::RESULT_FIXED,
                    'previous_repaired_at'    => $c->previous_at,

                    'days_since_repair' => $c->gap_days,
                    'occurrence_count'  => $c->occurrence,
                    'parts'             => [],

                    // Provenance and strength, so a ruling is never made blind to what it rests on.
                    'sources'     => $c->sources,
                    'source_code' => $c->source_code,
                    'match'       => $c->match,
                    'evidence'    => $c->evidence,

                    // WHERE EACH OCCURRENCE LIVES. A case nobody can open back to its records is an
                    // assertion, not evidence — and on a sheet-sourced case the "Ticket" fields are
                    // legitimately empty, which read as missing data until there was something to click.
                    // A ticket episode goes to the ticket; a sheet one to the car's Timeline, anchored
                    // on the exact log row (VehicleProfile already honours ?event=).
                    'previous_href' => $c->prev_ticket_id
                        ? '/maintenance-workflow/' . $c->prev_ticket_id
                        : (($c->prev_ref_ids[0] ?? null) ? '/vehicles/' . $c->vehicle_id . '?event=' . $c->prev_ref_ids[0] : null),
                    'latest_href'   => $c->ticket_id
                        ? '/maintenance-workflow/' . $c->ticket_id
                        : (($c->ref_ids[0] ?? null) ? '/vehicles/' . $c->vehicle_id . '?event=' . $c->ref_ids[0] : null),

                    // The Type-U contract covering each visit, resolved by date. Null is normal — most
                    // sheet-logged visits never had one raised.
                    'previous_contract' => $c->prev_contract_id
                        ? ['id' => $c->prev_contract_id, 'no' => $c->prev_contract_no] : null,
                    'latest_contract'   => $c->contract_id
                        ? ['id' => $c->contract_id, 'no' => $c->contract_no] : null,

                    'opened_by'     => $c->opened_by,
                    'decided_by'    => $c->decided_by,
                    'decided_at'    => optional($c->decided_at)->toIso8601String(),
                    'decision_note' => $c->decision_note,
                ]),
                'meta'    => [
                    'open'         => $rows->where('status', 'detected')->count(),
                    'total'        => $rows->count(),
                    'named_fault'  => $rows->where('evidence', 'named_fault')->count(),
                    'system_word'  => $rows->where('evidence', 'system_word')->count(),
                    'origin'       => 'workshop_log_and_tickets',
                ],
            ], 'Recurring fault reviews retrieved');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * GET /recurring-fault-reviews/stats — fleet-wide analytics for the dashboard charts.
     *
     * Takes no case filters: the table answers "what must I rule on now", these charts answer "how is
     * rework trending across the fleet". The exceptions are the two independent date windows — `faults_*`
     * and `cars_*` — which scope the "keeps coming back" rankings by when the case was opened. See
     * RecurringFaultService::stats(). Everything else stays all-time whatever is sent.
     */
    public function stats(Request $request)
    {
        try {
            $w = $request->validate([
                'faults_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
                'faults_from' => ['nullable', 'date'],
                'faults_to'   => ['nullable', 'date'],
                'cars_days'   => ['nullable', 'integer', 'min:0', 'max:3650'],
                'cars_from'   => ['nullable', 'date'],
                'cars_to'     => ['nullable', 'date'],
            ]);

            return ResponseHelper::SuccessResponse($this->service->stats(
                ['days' => $w['faults_days'] ?? null, 'from' => $w['faults_from'] ?? null, 'to' => $w['faults_to'] ?? null],
                ['days' => $w['cars_days'] ?? null,   'from' => $w['cars_from'] ?? null,   'to' => $w['cars_to'] ?? null],
            ), 'Recurring fault stats retrieved');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** POST /recurring-fault-reviews/{recurringFaultReview}/decide — record the management decision. */
    public function decide(Request $request, RecurringFaultReview $recurringFaultReview)
    {
        try {
            $data = $request->validate([
                'decision' => ['required', Rule::in(RecurringFaultReview::DECISIONS)],
                'note'     => ['nullable', 'string', 'max:2000'],
            ]);

            $review = $this->service->decide(
                $recurringFaultReview,
                $request->user(),
                $data['decision'],
                $data['note'] ?? null,
            );

            return ResponseHelper::SuccessResponse(
                new RecurringFaultReviewResource($review->loadMissing([
                    'vehicle:id,plate_no,make,model', 'task:id,symptom,severity,repair_gate', 'previousGarage:id,name',
                ])),
                'Decision recorded',
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
