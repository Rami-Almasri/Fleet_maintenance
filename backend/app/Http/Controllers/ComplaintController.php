<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Complaint;
use App\Services\ComplaintService;
use App\Services\ComplaintWorkflowService;
use Illuminate\Http\Request;

/**
 * Complaints Center — the API behind the /complaints management surface AND the per-vehicle Complaint
 * History tab. A complaint is now a first-class entity (App\Models\Complaint) with its own customer-support
 * lifecycle, so this controller both READS it (list + KPIs, single timeline) and MUTATES it (intake +
 * triage actions), delegating the state machine to ComplaintWorkflowService. It only touches the workshop
 * when a decision needs real work (the "send in" action spawns a maintenance ticket). See
 * App\Services\ComplaintService for the read assembly and [[complaint-entity]].
 */
class ComplaintController extends Controller
{
    public function __construct(
        private ComplaintService $complaints,
        private ComplaintWorkflowService $flow,
    ) {}

    /**
     * GET /complaints — the Center table + KPI roll-up.
     * Filters (all optional): status, assignee, vehicle_id, q, from, to. The Vehicle profile tab reuses
     * this with ?vehicle_id= to get one car's complaint history.
     */
    public function index(Request $request)
    {
        try {
            $filters = $request->only(['status', 'assignee', 'vehicle_id', 'q', 'from', 'to']);
            return ResponseHelper::SuccessResponse($this->complaints->list($filters), 'Complaints retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** GET /complaints/{complaint} — one complaint's full record: context, live contact, timeline. */
    public function show(Complaint $complaint)
    {
        try {
            return ResponseHelper::SuccessResponse($this->complaints->detail($complaint), 'Complaint retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * POST /complaints — Operations logs a new customer complaint. Notifies the Inspector to triage.
     * Body: vehicle_id (req), description (req), severity?, source?.
     */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'vehicle_id'  => ['required', 'integer'],
                'description' => ['required', 'string', 'max:2000'],
                'severity'    => ['nullable', 'string'],
                'source'      => ['nullable', 'string'],
            ]);
            $complaint = $this->flow->open($data, $request->user());
            return ResponseHelper::SuccessResponse($this->complaints->detail($complaint), 'Complaint logged — Inspector notified to triage it', 201);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** POST /complaints/{complaint}/contact — log a conversation with the customer. */
    public function contact(Request $request, Complaint $complaint)
    {
        try {
            $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
            $complaint = $this->flow->logContact($complaint, $data['note'] ?? null, $request->user());
            return ResponseHelper::SuccessResponse($this->complaints->detail($complaint), 'Customer contact logged', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** POST /complaints/{complaint}/decision — record the triage decision (may spawn maintenance). */
    public function decide(Request $request, Complaint $complaint)
    {
        try {
            $data = $request->validate([
                'decision' => ['required', 'string'],
                'note'     => ['nullable', 'string', 'max:2000'],
            ]);
            $complaint = $this->flow->recordDecision($complaint, $data['decision'], $data['note'] ?? null, $request->user());
            return ResponseHelper::SuccessResponse($this->complaints->detail($complaint), 'Decision recorded', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** POST /complaints/{complaint}/send-in — send the car in for inspection (spawns a maintenance ticket). */
    public function sendIn(Request $request, Complaint $complaint)
    {
        try {
            $complaint = $this->flow->spawnInspection($complaint, $request->user());
            return ResponseHelper::SuccessResponse($this->complaints->detail($complaint), 'Sent in for inspection', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** POST /complaints/{complaint}/resolve — handled as customer support, no repair needed. */
    public function resolve(Request $request, Complaint $complaint)
    {
        try {
            $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
            $complaint = $this->flow->resolve($complaint, $data['note'] ?? null, $request->user());
            return ResponseHelper::SuccessResponse($this->complaints->detail($complaint), 'Complaint resolved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** POST /complaints/{complaint}/close — terminal close. */
    public function close(Request $request, Complaint $complaint)
    {
        try {
            $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
            $complaint = $this->flow->close($complaint, $data['note'] ?? null, $request->user());
            return ResponseHelper::SuccessResponse($this->complaints->detail($complaint), 'Complaint closed', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
