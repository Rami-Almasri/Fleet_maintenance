<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\FaultCause;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administration of the Symptom → Root-Cause knowledge base.
 *
 * The diagnostic picker (test-drive report + garage findings) auto-creates a 'pending' row whenever
 * a user types a cause that isn't in the master list — that's the "flag for administrative review"
 * loop. This controller is the other half: a manager (maintenance.manage) reviews the queue and
 * either APPROVES a custom cause (it joins the master list and starts appearing in everyone's picker)
 * or REJECTS it (kept for the audit trail, never shown again). All gated on the route.
 */
class FaultCauseController extends Controller
{
    /**
     * The knowledge base, newest-pending first. `?status=pending|approved|rejected` filters; default
     * is the review queue (pending). `?symptom=` narrows to one symptom. Drives the admin screen and
     * doubles as the data feed for cause-trend reporting (each row carries its `usage_count`).
     */
    public function index(Request $request)
    {
        $request->validate([
            'status'  => ['nullable', Rule::in(FaultCause::STATUSES)],
            'symptom' => ['nullable', 'string', 'max:255'],
        ]);

        $rows = FaultCause::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when(! $request->filled('status'), fn ($q) => $q->where('status', FaultCause::STATUS_PENDING))
            ->when($request->filled('symptom'), fn ($q) => $q->forSymptom($request->string('symptom')))
            ->with(['submitter:id,name', 'reviewer:id,name'])
            ->orderByDesc('created_at')
            ->get();

        // Headline counts so the admin screen can badge the queue without a second request.
        $counts = FaultCause::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return ResponseHelper::SuccessResponse(
            [
                'causes' => $rows,
                'counts' => [
                    'pending'  => (int) ($counts[FaultCause::STATUS_PENDING] ?? 0),
                    'approved' => (int) ($counts[FaultCause::STATUS_APPROVED] ?? 0),
                    'rejected' => (int) ($counts[FaultCause::STATUS_REJECTED] ?? 0),
                ],
            ],
            'Fault causes retrieved successfully',
            200
        );
    }

    /** Approve a pending custom cause → it joins the master list and shows up in the picker. */
    public function approve(Request $request, FaultCause $faultCause)
    {
        $data = $request->validate([
            // Optional tidy-ups the reviewer can apply before promoting it.
            'root_cause'  => ['nullable', 'string', 'max:191'],
            'category_key' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $faultCause->fill(array_filter([
            'root_cause'   => $data['root_cause'] ?? null,
            'category_key' => $data['category_key'] ?? null,
            'description'  => $data['description'] ?? null,
        ], fn ($v) => $v !== null));

        $faultCause->status      = FaultCause::STATUS_APPROVED;
        $faultCause->reviewed_by = $request->user()->id;
        $faultCause->reviewed_at = now();
        $faultCause->save();

        return ResponseHelper::SuccessResponse($faultCause->fresh(), 'Cause approved — added to the master list', 200);
    }

    /** Reject a pending cause → hidden from the picker, kept for audit. */
    public function reject(Request $request, FaultCause $faultCause)
    {
        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);

        $faultCause->status      = FaultCause::STATUS_REJECTED;
        $faultCause->review_note = $data['review_note'] ?? null;
        $faultCause->reviewed_by = $request->user()->id;
        $faultCause->reviewed_at = now();
        $faultCause->save();

        return ResponseHelper::SuccessResponse($faultCause->fresh(), 'Cause rejected', 200);
    }
}
