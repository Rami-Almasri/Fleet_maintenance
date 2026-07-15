<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\PartPurchaseResource;
use App\Models\PartPurchase;
use App\Services\PartIntelligenceService;
use App\Services\PartWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The purchase ledger + the install (cost-bridge) step + the pre-buy duplicate check. Purchases are
 * CREATED via PartRequestController::purchase (where the request context + duplicate engine live); this
 * controller reads them, checks duplicates ahead of a buy, and fits a purchased part.
 */
class PartPurchaseController extends Controller
{
    public function __construct(
        private PartWorkflowService $service,
        private PartIntelligenceService $intel,
    ) {}

    public function index(Request $request)
    {
        try {
            $q = PartPurchase::query()->with('sourceVendor:id,name')->latest('id');

            if ($v = $request->query('vehicle_id')) {
                $q->where('vehicle_id', $v);
            }
            if ($request->boolean('flagged')) {
                $q->where('requires_review', true);
            }
            if ($src = $request->query('purchase_source')) {
                $q->where('purchase_source', $src);
            }

            $rows = $q->paginate(min((int) $request->query('per_page', 50), 200));

            return ResponseHelper::SuccessResponse([
                'purchases' => PartPurchaseResource::collection($rows),
                'meta'      => ['total' => $rows->total(), 'per_page' => $rows->perPage(), 'current_page' => $rows->currentPage()],
            ], 'Purchases retrieved');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Pre-buy check: given a vehicle + part, would this trip a duplicate alert, and what's the prior repair?
     * Lets the purchase modal surface the "already received this part N days ago" prompt BEFORE the buy.
     */
    public function duplicateCheck(Request $request)
    {
        try {
            $data = $request->validate([
                'vehicle_id'   => ['required', 'exists:vehicles,id'],
                'part_name'    => ['nullable', 'string', 'max:255'],
                'part_number'  => ['nullable', 'string', 'max:255'],
                'category_key' => ['nullable', 'string', 'max:60'],
            ]);

            $verdict = $this->intel->detectDuplicate(
                (int) $data['vehicle_id'], $data['part_name'] ?? null, $data['part_number'] ?? null, $data['category_key'] ?? null
            );

            return ResponseHelper::SuccessResponse([
                'duplicate'    => $verdict['duplicate'],
                'priority'     => $verdict['priority'],
                'part_class'   => $verdict['part_class'],
                'days_between' => $verdict['days_between'],
                'window_days'  => $verdict['window_days'],
                'context'      => $this->intel->duplicateContext($verdict),
            ], 'Duplicate check complete');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The unified repair-intelligence history for one vehicle (Part 8 admin view): every purchase with its
     * Vehicle + Fault + Repair + Part + Purchase Source + Technician + Result — the SAME projection across
     * all four garage/supplier × in-shop/on-site cases — plus the vehicle's open investigations.
     */
    public function vehicleHistory(Request $request, \App\Models\Vehicle $vehicle)
    {
        try {
            $purchases = PartPurchase::where('vehicle_id', $vehicle->id)
                ->with(['sourceVendor:id,name', 'task:id,symptom,category_key,root_cause'])
                ->latest('purchased_at')->latest('id')->get()
                ->map(fn (PartPurchase $p) => [
                    'purchase_id'     => $p->id,
                    'fault'           => $p->task?->symptom,
                    'root_cause'      => $p->task?->root_cause,
                    'part_name'       => $p->part_name,
                    'part_number'     => $p->part_number,
                    'part_class'      => $p->part_class,
                    'purchase_source' => $p->purchase_source,          // garage | supplier
                    'source'          => $p->sourceVendor?->name ?: $p->source_name,
                    'repair_location' => $p->repair_location,          // garage | onsite
                    'technician'      => $p->installed_by_name ?: $p->purchased_by_name,
                    'price'           => $p->purchase_price,
                    'currency'        => $p->currency,
                    'result'          => $p->result,                   // success | failed | pending
                    'purchased_at'    => optional($p->purchased_at)->toDateString(),
                    'installed_at'    => optional($p->installed_at)->toDateString(),
                    'flagged'         => (bool) $p->requires_review,
                ]);

            $investigations = \App\Models\PartInvestigation::where('vehicle_id', $vehicle->id)
                ->latest('id')->get()
                ->map(fn ($i) => ['id' => $i->id, 'type' => $i->type, 'priority' => $i->priority, 'status' => $i->status, 'reason_code' => $i->reason_code]);

            return ResponseHelper::SuccessResponse([
                'vehicle'        => ['id' => $vehicle->id, 'plate' => $vehicle->plate_no, 'make' => $vehicle->make, 'model' => $vehicle->model],
                'purchases'      => $purchases,
                'investigations' => $investigations,
                'summary'        => [
                    'total_purchases' => $purchases->count(),
                    'flagged'         => $purchases->where('flagged', true)->count(),
                    'from_garage'     => $purchases->where('purchase_source', 'garage')->count(),
                    'from_supplier'   => $purchases->where('purchase_source', 'supplier')->count(),
                ],
            ], 'Vehicle part history retrieved');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Fault-recurrence check (Parts 5 & 6): has this fault been fixed before and come back? Surfaces the
     * previous repair (days ago, technician, parts used, cost) so the diagnosis step can warn about a
     * failed fix / wrong diagnosis. Self-contained so the Decide UI can call it without a ticket write.
     */
    public function recurrenceCheck(Request $request)
    {
        try {
            $data = $request->validate([
                'vehicle_id'      => ['required', 'exists:vehicles,id'],
                'category_key'    => ['nullable', 'string', 'max:60'],
                'symptom'         => ['nullable', 'string', 'max:255'],
                'exclude_task_id' => ['nullable', 'integer'],
            ]);

            $hit = $this->intel->detectRecurrence(
                (int) $data['vehicle_id'], $data['category_key'] ?? null, $data['symptom'] ?? null, $data['exclude_task_id'] ?? null
            );

            if (! $hit) {
                return ResponseHelper::SuccessResponse(['recurrence' => false], 'No prior repair found');
            }

            return ResponseHelper::SuccessResponse([
                'recurrence'  => true,
                'days_ago'    => $hit['days_ago'],
                'technician'  => $hit['technician'],
                'parts'       => $hit['parts'],
                'cost'        => $hit['cost'],
                'previous_task_id' => $hit['previous_task']->id,
                'escalated'   => $hit['open_investigation'],
            ], 'Previous repair found');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Fit a purchased part — generates the maintenance line item (cost bridge) when tied to a ticket. */
    public function install(Request $request, PartPurchase $partPurchase)
    {
        try {
            $data = $request->validate([
                'installed_odometer' => ['nullable', 'integer', 'min:0'],
                'warranty_months'    => ['nullable', 'integer', 'min:0', 'max:120'],
                'result'             => ['nullable', Rule::in(PartPurchase::RESULTS)],
                'notes'              => ['nullable', 'string', 'max:2000'],
            ]);

            return ResponseHelper::SuccessResponse(
                new PartPurchaseResource($this->service->installPurchase($partPurchase, $data, $request->user())),
                'Part installed'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
