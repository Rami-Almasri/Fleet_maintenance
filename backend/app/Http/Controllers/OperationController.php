<?php

namespace App\Http\Controllers;

use App\Exceptions\ReservationConflictException;
use App\Helpers\ResponseHelper;
use App\Http\Requests\CloseOperationRequest;
use App\Http\Requests\StartOperationRequest;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use App\Models\Vehicle;
use App\Services\OperationsService;

class OperationController extends Controller
{
    private OperationsService $operations;

    public function __construct(OperationsService $operations)
    {
        $this->operations = $operations;
    }

    /**
     * Start a movement for a vehicle (rent / maintenance / test_drive / transfer / sale_prep).
     * Closes any existing open contract, creates a new open one, and updates operational_status.
     */
    public function start(StartOperationRequest $request, Vehicle $vehicle)
    {
        try {
            $data = $request->validated();
            $category = $data['category'];
            unset($data['category']);

            // The "Rental-First" block (and its manager override) was removed by request: maintenance
            // on a car with an active/upcoming rental is allowed and managed manually. The only soft
            // block left here is a paid-reservation clash, which stays force-overridable below.
            $contract = $this->operations->startOperation($vehicle, $category, $data);
            $result = ContractResource::make($contract->load(['vehicle', 'customer']));

            return ResponseHelper::SuccessResponse($result, "Operation '{$category}' started", 200);
        } catch (ReservationConflictException $e) {
            // 409 + a conflict flag so the UI can explain it and offer "send anyway"
            return ResponseHelper::FailureResponse(
                ['conflict' => true, 'reservations' => $e->reservations],
                $e->getMessage(),
                409
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Close an open movement (e.g. car returned / out of garage). Frees the vehicle.
     */
    public function close(CloseOperationRequest $request, Contract $contract)
    {
        try {
            $contract = $this->operations->closeOperation($contract, $request->validated());
            $result = ContractResource::make($contract->load(['vehicle', 'customer']));

            return ResponseHelper::SuccessResponse($result, "Operation closed", 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Get a vehicle's current open movement (or null if it's available).
     */
    public function current(Vehicle $vehicle)
    {
        try {
            $open = $vehicle->openContract()->with('customer')->first();
            $result = $open ? ContractResource::make($open->load('vehicle')) : null;

            return ResponseHelper::SuccessResponse(
                $result,
                $open ? "Current open contract" : "No open contract (vehicle available)",
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
