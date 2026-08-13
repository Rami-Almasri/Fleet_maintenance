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
     *
     * The verdict is windowed (that's what makes it an alert), but the `history` block it ships alongside is
     * NOT: it is every purchase of this part on this vehicle, so the buyer sees the part's whole record and
     * not merely the fact that something happened inside 90 days.
     */
    public function duplicateCheck(Request $request)
    {
        try {
            $data = $request->validate([
                'vehicle_id'         => ['required', 'exists:vehicles,id'],
                'part_name'          => ['nullable', 'string', 'max:255'],
                // The catalog part the buyer picked. THE important field on this endpoint: with it the
                // check sees every other name the part is known by, without it only the exact wording.
                'component_catalog_id' => ['nullable', 'integer', 'exists:component_catalog,id'],
                'part_number'        => ['nullable', 'string', 'max:255'],
                'category_key'       => ['nullable', 'string', 'max:60'],
                // The FAULT this part is for — enables the stronger Vehicle + Part + Fault duplicate signal.
                'fault_category_key' => ['nullable', 'string', 'max:60'],
                'fault_symptom'      => ['nullable', 'string', 'max:255'],
                // Skip the full record when the caller only wants the alert verdict (a keystroke-level check).
                'include_history'    => ['nullable', 'boolean'],
            ]);

            $catalogId = isset($data['component_catalog_id']) ? (int) $data['component_catalog_id'] : null;

            $verdict = $this->intel->detectDuplicate(
                (int) $data['vehicle_id'], $data['part_name'] ?? null, $data['part_number'] ?? null, $data['category_key'] ?? null,
                null, null, false, $data['fault_category_key'] ?? null, $data['fault_symptom'] ?? null, $catalogId
            );

            $history = $request->boolean('include_history', true)
                ? $this->intel->partHistory(
                    (int) $data['vehicle_id'], $data['part_name'] ?? null, $data['part_number'] ?? null,
                    $data['category_key'] ?? null, null, $verdict['part_class'], $catalogId
                )
                : null;

            return ResponseHelper::SuccessResponse([
                'duplicate'    => $verdict['duplicate'],
                'priority'     => $verdict['priority'],
                'same_fault'   => $verdict['same_fault'] ?? false,
                'part_class'   => $verdict['part_class'],
                'days_between' => $verdict['days_between'],
                'window_days'  => $verdict['window_days'],
                // Why the earlier purchase counts as this same part: 'catalog' (a human identified both),
                // 'name' (a known other name for it) or 'part_number'. The modal leads with it whenever
                // it is not 'catalog', because that is the case where the two records look unalike.
                'matched_via'  => $verdict['matched_via'] ?? null,
                'context'      => $this->intel->duplicateContext($verdict),
                // The unwindowed record. Present even when `duplicate` is false — "no alert" is not the same
                // as "no history", and the buyer is entitled to the difference.
                'history'      => $history,
            ], 'Duplicate check complete');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * FLEET-WIDE repeat buys: every car that received the same part twice inside `window_days`, newest
     * first, each pair carrying the approval that authorised it. Backs the dashboard's "Bought Again"
     * card — the supervisor's retrospective view, as opposed to duplicateCheck()'s pre-buy warning.
     */
    public function repeats(Request $request)
    {
        try {
            $data = $request->validate([
                // The "again" window — how close the two buys must be to count as a repeat.
                'window_days'   => ['nullable', 'integer', 'min:1', 'max:3650'],
                // How far back the SECOND buy may be — how much history the card lists.
                'lookback_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
                'limit'         => ['nullable', 'integer', 'min:1', 'max:200'],
                'vehicle_id'    => ['nullable', 'integer', 'exists:vehicles,id'],
                // Filters, wipers and oil are MEANT to be re-bought; off unless explicitly asked for.
                'include_consumables' => ['nullable', 'boolean'],
            ]);

            return ResponseHelper::SuccessResponse(
                $this->intel->repeatPurchases(
                    (int) ($data['window_days'] ?? 30),
                    (int) ($data['lookback_days'] ?? 365),
                    (int) ($data['limit'] ?? 50),
                    isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null,
                    $request->boolean('include_consumables')
                ),
                'Repeat purchases retrieved'
            );
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

                // ── Asset Layer (optional; read only when features.asset_layer ≠ off) ──
                // component: the physical identity of what is being fitted.
                'component'                       => ['nullable', 'array'],
                'component.component_catalog_id'  => ['nullable', 'integer', 'exists:component_catalog,id'],
                'component.serial_no'             => ['nullable', 'string', 'max:80'],
                'component.brand'                 => ['nullable', 'string', 'max:80'],
                'component.model'                 => ['nullable', 'string', 'max:120'],
                'component.position'              => ['nullable', 'string', 'max:20'],
                'component.technician_name'       => ['nullable', 'string', 'max:120'],
                // predecessor: the removal decision for the part currently in the slot —
                // "what happened to the old one?" (mandatory in enforced mode when a slot is occupied).
                'predecessor'                     => ['nullable', 'array'],
                'predecessor.removal_reason'      => ['nullable', Rule::in(\App\Models\VehicleComponent::REMOVAL_REASONS)],
                'predecessor.disposition'         => ['nullable', Rule::in(\App\Models\VehicleComponent::DISPOSITIONS)],
                'predecessor.removed_odometer'    => ['nullable', 'integer', 'min:0'],
                'predecessor.removal_note'        => ['nullable', 'string', 'max:2000'],
            ]);

            return ResponseHelper::SuccessResponse(
                new PartPurchaseResource($this->service->installPurchase($partPurchase, $data, $request->user())),
                'Part installed'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Mark a purchased part as delivered to the workshop — sets delivered_at, so the derived state unblocks the repair. */
    public function markDelivered(Request $request, PartPurchase $partPurchase)
    {
        try {
            return ResponseHelper::SuccessResponse(
                new PartPurchaseResource($this->service->markDelivered($partPurchase, $request->user())),
                'Part marked delivered'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
