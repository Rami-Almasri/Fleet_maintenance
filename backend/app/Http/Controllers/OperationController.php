<?php

namespace App\Http\Controllers;

use App\Exceptions\RentalActiveException;
use App\Exceptions\ReservationConflictException;
use App\Helpers\ResponseHelper;
use App\Http\Requests\CloseOperationRequest;
use App\Http\Requests\StartOperationRequest;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use App\Models\PolicyOverrideAudit;
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

            // A "Rental-First" override is a privileged action: only managers (operations.override)
            // may open a maintenance contract on a rented car. Snapshot the actor for the audit trail.
            if (! empty($data['override_reason'])) {
                if (! $request->user()?->can('operations.override')) {
                    return ResponseHelper::FailureResponse(null, 'A manager override is required to open a maintenance contract on a rented car.', 403);
                }
                $data['override_by_id']   = $request->user()->id;
                $data['override_by_name'] = $request->user()->name;
            }

            $contract = $this->operations->startOperation($vehicle, $category, $data);
            $result = ContractResource::make($contract->load(['vehicle', 'customer']));

            return ResponseHelper::SuccessResponse($result, "Operation '{$category}' started", 200);
        } catch (RentalActiveException $e) {
            // 409 + a rental_block flag so the UI can show the live rental and offer a manager override
            return ResponseHelper::FailureResponse(
                [
                    'rental_block'    => true,
                    'rental'          => $e->rental,
                    'override_reasons' => collect(PolicyOverrideAudit::REASON_CODES)
                        ->map(fn ($label, $code) => ['code' => $code, 'label' => $label])
                        ->values(),
                ],
                $e->getMessage(),
                409
            );
        } catch (ReservationConflictException $e) {
            // 409 + a conflict flag so the UI can explain it and offer "send anyway"
            return ResponseHelper::FailureResponse(
                ['conflict' => true, 'reservations' => $e->reservations],
                $e->getMessage(),
                409
            );
        } catch (\Throwable $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
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
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
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
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
