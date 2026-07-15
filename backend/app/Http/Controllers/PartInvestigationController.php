<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\PartInvestigationResource;
use App\Models\PartInvestigation;
use App\Services\PartWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The admin duplicate/recurrence investigation inbox — Open → Under Review → Reason Provided →
 * Approved/Rejected → Closed. Every action is stamped with the acting admin + timestamp.
 */
class PartInvestigationController extends Controller
{
    public function __construct(private PartWorkflowService $service) {}

    public function index(Request $request)
    {
        try {
            $q = PartInvestigation::query()
                ->with(['vehicle:id,plate_no,make,model', 'purchase', 'previousPurchase'])
                ->latest('id');

            if ($s = $request->query('status')) {
                $q->whereIn('status', explode(',', $s));
            } elseif ($request->boolean('open', true) && ! $request->query('all')) {
                $q->whereIn('status', PartInvestigation::OPEN_STATUSES);
            }
            if ($t = $request->query('type')) {
                $q->where('type', $t);
            }

            $rows = $q->paginate(min((int) $request->query('per_page', 50), 200));

            return ResponseHelper::SuccessResponse([
                'investigations' => PartInvestigationResource::collection($rows),
                'meta'           => ['total' => $rows->total(), 'per_page' => $rows->perPage(), 'current_page' => $rows->currentPage()],
            ], 'Investigations retrieved');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function review(Request $request, PartInvestigation $partInvestigation)
    {
        try {
            return ResponseHelper::SuccessResponse(
                new PartInvestigationResource($this->service->reviewInvestigation($partInvestigation, $request->user())),
                'Investigation under review'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function provideReason(Request $request, PartInvestigation $partInvestigation)
    {
        try {
            $data = $request->validate([
                'reason_code' => ['required', Rule::in(PartInvestigation::REASON_CODES)],
                'reason_note' => ['nullable', 'string', 'max:2000'],
            ]);

            return ResponseHelper::SuccessResponse(
                new PartInvestigationResource($this->service->provideReason($partInvestigation, $request->user(), $data['reason_code'], $data['reason_note'] ?? null)),
                'Reason recorded'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function approve(Request $request, PartInvestigation $partInvestigation)
    {
        return $this->resolve($request, $partInvestigation, true);
    }

    public function reject(Request $request, PartInvestigation $partInvestigation)
    {
        return $this->resolve($request, $partInvestigation, false);
    }

    private function resolve(Request $request, PartInvestigation $inv, bool $approved)
    {
        try {
            $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

            return ResponseHelper::SuccessResponse(
                new PartInvestigationResource($this->service->resolveInvestigation($inv, $request->user(), $approved, $data['note'] ?? null)),
                $approved ? 'Investigation approved & closed' : 'Investigation rejected & closed'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
