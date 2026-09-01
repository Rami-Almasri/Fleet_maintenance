<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\PartRequestResource;
use App\Models\SpareKeyRequirement;
use App\Models\Vehicle;
use App\Services\SpareKeyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Spare Key lifecycle API — the NEED half. Everything after "create a purchase request" happens
 * on the EXISTING parts endpoints (POST /part-requests/{id}/approve|reject|purchase), which is why
 * there is no approve() or reject() here: a second approval door for one part type is exactly the
 * parallel workflow this feature was asked not to build.
 *
 * Permissions ride the parts ladder, unchanged and un-extended:
 *   parts.view      read the board and a car's keys
 *   parts.request   raise a need, raise the purchase request off it, cancel a need
 *   parts.purchase  mark a key received (the same bar as recording any other buy's arrival)
 *
 * Business logic lives in {@see SpareKeyService}; this class validates, delegates, and shapes the
 * envelope.
 */
class SpareKeyRequirementController extends Controller
{
    public function __construct(private SpareKeyService $service) {}

    /** The operations board — stage counts plus the rows behind them. */
    public function board(Request $request)
    {
        try {
            $data = $request->validate([
                'status' => ['nullable', 'string', 'max:200'],
                'limit'  => ['nullable', 'integer', 'min:1', 'max:500'],
            ]);

            return ResponseHelper::SuccessResponse(
                $this->service->board($data['status'] ?? null, (int) ($data['limit'] ?? 200)),
                'Spare key board retrieved'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** One car's spare keys: how many it HAS, and every requirement it has ever had. */
    public function forVehicle(Vehicle $vehicle)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $this->service->forVehicle($vehicle),
                'Vehicle spare keys retrieved'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function show(SpareKeyRequirement $spareKeyRequirement)
    {
        try {
            $spareKeyRequirement->load(['vehicle', 'purchaseRequests.purchases.sourceVendor:id,name', 'requester:id,name']);

            return ResponseHelper::SuccessResponse(
                $this->service->forVehicle($spareKeyRequirement->vehicle),
                'Spare key requirement retrieved'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** "This vehicle needs a spare key." */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'vehicle_id'  => ['required', 'exists:vehicles,id'],
                // Capped at the size of a key set: a requirement for five keys on a car that can hold
                // four could never be completed, and a need that cannot be met is not a need.
                'quantity'    => ['nullable', 'integer', 'min:1', 'max:4'],
                'reason_code' => ['nullable', Rule::in(SpareKeyRequirement::REASONS)],
                'notes'       => ['nullable', 'string', 'max:2000'],
            ]);

            $requirement = $this->service->raise(
                Vehicle::findOrFail($data['vehicle_id']),
                $data,
                $request->user()
            );

            return ResponseHelper::SuccessResponse(
                $this->service->forVehicle($requirement->vehicle),
                'Spare key requirement created — the supervisors have been notified',
                201
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Hand the need to procurement. From here the existing parts board owns it. */
    public function createPurchaseRequest(Request $request, SpareKeyRequirement $spareKeyRequirement)
    {
        try {
            $data = $request->validate([
                'quantity'        => ['nullable', 'integer', 'min:1', 'max:4'],
                'estimated_price' => ['nullable', 'numeric', 'min:0'],
                'currency'        => ['nullable', 'string', 'size:3'],
                'notes'           => ['nullable', 'string', 'max:2000'],
            ]);

            $partRequest = $this->service->createPurchaseRequest($spareKeyRequirement, $data, $request->user());

            return ResponseHelper::SuccessResponse(
                new PartRequestResource($partRequest->load('vehicle')),
                'Purchase request created — it is now on the parts board awaiting approval',
                201
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The key physically arrived. Marks the purchase delivered AND registers the key as a component
     * on the vehicle, in one transaction. Safe to submit twice — see SpareKeyService::receive().
     */
    public function receive(Request $request, SpareKeyRequirement $spareKeyRequirement)
    {
        try {
            $data = $request->validate([
                'quantity'  => ['nullable', 'integer', 'min:1', 'max:4'],
                // Only meaningful when a single key is being received — a key code cannot describe two.
                'serial_no' => ['nullable', 'string', 'max:120'],
                'brand'     => ['nullable', 'string', 'max:120'],
                'model'     => ['nullable', 'string', 'max:120'],
                'note'      => ['nullable', 'string', 'max:500'],
            ]);

            $result = $this->service->receive($spareKeyRequirement, $data, $request->user());

            return ResponseHelper::SuccessResponse([
                'requirement' => $this->service->forVehicle($result['requirement']->vehicle),
                'component_ids' => array_map(fn ($c) => $c->id, $result['components']),
            ], 'Spare key received and registered to the vehicle', 201);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function cancel(Request $request, SpareKeyRequirement $spareKeyRequirement)
    {
        try {
            $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

            $requirement = $this->service->cancel($spareKeyRequirement, $request->user(), $data['reason']);

            return ResponseHelper::SuccessResponse(
                $this->service->forVehicle($requirement->vehicle),
                'Spare key requirement cancelled'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
