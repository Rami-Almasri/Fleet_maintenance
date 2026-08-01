<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\PartRequestResource;
use App\Models\PartRequest;
use App\Services\PartSpendService;
use App\Services\PartWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Part Request lifecycle API — Requested → Under Review → Approved → Purchased → Installed → Completed.
 * Purchases are created here (POST /{id}/purchase) so the duplicate engine runs where the request context
 * lives; the actual buy record + install live under PartPurchaseController.
 */
class PartRequestController extends Controller
{
    public function __construct(private PartWorkflowService $service) {}

    /** Board / list with light filters. */
    public function index(Request $request)
    {
        try {
            // `purchases` is loaded so the board's Install action can find the buy to fit (an approved →
            // purchased request carries its purchase here); without it the Install modal has nothing to act on.
            $q = PartRequest::query()->with(['vehicle:id,plate_no,make,model', 'customer:id,name_en,name_ar', 'task:id,symptom', 'purchases'])
                ->latest('id');

            if ($s = $request->query('status')) {
                $q->whereIn('status', explode(',', $s));
            }
            if ($src = $request->query('source')) {
                $q->where('source', $src);
            }
            if ($v = $request->query('vehicle_id')) {
                $q->where('vehicle_id', $v);
            }
            // Scope to one maintenance ticket — powers the Parts section inside the ticket drawer/command view.
            if ($m = $request->query('maintenance_id')) {
                $q->where('maintenance_id', $m);
            }

            $rows = $q->paginate(min((int) $request->query('per_page', 50), 200));

            return ResponseHelper::SuccessResponse([
                'requests' => PartRequestResource::collection($rows),
                'meta'     => ['total' => $rows->total(), 'per_page' => $rows->perPage(), 'current_page' => $rows->currentPage()],
            ], 'Part requests retrieved');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Fleet-wide parts spend for the board's "Where parts money goes" chart. Deliberately NOT derived
     * from the request list: most parts money is itemised on garage invoices and never passes through
     * a part request, so the chart reads the unified ledger (see PartSpendService).
     */
    public function spend(Request $request, PartSpendService $spend)
    {
        try {
            $data = $request->validate([
                'by'         => ['nullable', Rule::in(['part', 'car'])],
                'limit'      => ['nullable', 'integer', 'min:1', 'max:50'],
                'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            ]);

            return ResponseHelper::SuccessResponse(
                $spend->ranked($data['by'] ?? 'part', (int) ($data['limit'] ?? 10), $data['vehicle_id'] ?? null),
                'Parts spend retrieved'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function store(Request $request)
    {
        try {
            // A part is ALWAYS requested against a maintenance ticket (vehicle → ticket → fault). There is no
            // standalone customer-part flow: source is forced to 'garage' and the ticket is mandatory.
            $data = $request->validate([
                'vehicle_id'          => ['required', 'exists:vehicles,id'],
                'maintenance_id'      => ['required', 'exists:maintenances,id'],
                'maintenance_task_id' => ['nullable', 'exists:maintenance_tasks,id'],
                'part_name'           => ['required', 'string', 'max:255'],
                'part_number'         => ['nullable', 'string', 'max:255'],
                'category_key'        => ['nullable', 'string', 'max:60'],
                'repair_location'     => ['nullable', Rule::in(PartRequest::LOCATIONS)],
                'quantity'            => ['nullable', 'numeric', 'gt:0'],
                'reason'              => ['required', 'string', 'max:2000'],
                'estimated_price'     => ['nullable', 'numeric', 'min:0'],
                'currency'            => ['nullable', 'string', 'size:3'],
                'notes'               => ['nullable', 'string', 'max:2000'],
            ]);
            $data['source'] = PartRequest::SOURCE_GARAGE;

            $req = $this->service->createRequest($data, $request->user());

            return ResponseHelper::SuccessResponse(new PartRequestResource($req->load('vehicle', 'customer', 'task')), 'Part request created', 201);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function show(PartRequest $partRequest)
    {
        try {
            return ResponseHelper::SuccessResponse(
                new PartRequestResource($partRequest->load('vehicle', 'customer', 'task', 'purchases.sourceVendor')),
                'Part request retrieved'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function approve(Request $request, PartRequest $partRequest)
    {
        try {
            // Optional acknowledgment note when the approver green-lights a flagged duplicate anyway.
            $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

            return ResponseHelper::SuccessResponse(
                new PartRequestResource($this->service->approve($partRequest, $request->user(), $data['notes'] ?? null)),
                'Part request approved'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function reject(Request $request, PartRequest $partRequest)
    {
        try {
            $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

            return ResponseHelper::SuccessResponse(
                new PartRequestResource($this->service->reject($partRequest, $request->user(), $data['reason'])),
                'Part request rejected'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Record a purchase against this request. Price is REQUIRED — cannot mark purchased without one. */
    public function purchase(Request $request, PartRequest $partRequest)
    {
        try {
            $data = $request->validate([
                'purchase_source'       => ['required', Rule::in(\App\Models\PartPurchase::PURCHASE_SOURCES)],
                'source_vendor_id'      => ['nullable', 'exists:vendors,id'],
                'source_name'           => ['nullable', 'string', 'max:255'],
                // The supplier's PO / invoice reference for a DIRECT buy. PartWorkflowService already
                // reads it (it was only ever populated from an RFQ award), but it was missing here, so
                // anything the purchaser typed was silently dropped — leaving the Purchase Order link
                // on the component dossier permanently empty for every non-RFQ purchase.
                'po_number'             => ['nullable', 'string', 'max:40'],
                'repair_location'       => ['nullable', Rule::in(PartRequest::LOCATIONS)],
                'purchase_price'        => ['required', 'numeric', 'gt:0'],
                'currency'              => ['nullable', 'string', 'size:3'],
                'quantity'              => ['nullable', 'numeric', 'gt:0'],
                'notes'                 => ['nullable', 'string', 'max:2000'],
                // Optional inline reason when the buyer is knowingly repurchasing a flagged part.
                'duplicate_reason_code' => ['nullable', Rule::in(\App\Models\PartInvestigation::REASON_CODES)],
                'duplicate_reason_note' => ['nullable', 'string', 'max:2000'],
            ]);

            $result = $this->service->purchase($partRequest, $data, $request->user());

            return ResponseHelper::SuccessResponse([
                'purchase'      => new \App\Http\Resources\PartPurchaseResource($result['purchase']),
                'duplicate'     => $result['verdict']['duplicate'],
                'priority'      => $result['verdict']['priority'],
                'days_between'  => $result['verdict']['days_between'],
                'investigation_id' => optional($result['investigation'])->id,
            ], $result['verdict']['duplicate'] ? 'Purchase recorded — duplicate flagged for review' : 'Purchase recorded', 201);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function complete(Request $request, PartRequest $partRequest)
    {
        try {
            return ResponseHelper::SuccessResponse(
                new PartRequestResource($this->service->complete($partRequest, $request->user())),
                'Part request completed'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
