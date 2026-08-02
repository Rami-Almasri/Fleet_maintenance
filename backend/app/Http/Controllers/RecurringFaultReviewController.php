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

            $rows = $this->service->index($filters);

            return ResponseHelper::SuccessResponse([
                'reviews' => RecurringFaultReviewResource::collection($rows),
                'meta'    => [
                    'open'  => $rows->where('status', RecurringFaultReview::STATUS_OPEN)->count(),
                    'total' => $rows->count(),
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
