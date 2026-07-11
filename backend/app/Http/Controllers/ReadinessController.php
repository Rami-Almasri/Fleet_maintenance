<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Services\ReadinessDashboardService;
use App\Services\VehicleLogService;
use App\Services\VehicleReadinessService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Readiness Dashboard + the Pre-Delivery Readiness Gate's read side.
 *
 *  - index()   : the role-scoped, four-pillar task board (Logistics / Supervisor / Inspector queues).
 *  - vehicle() : the full readiness checklist for one car — powers the disabled "Set to Ready"
 *                button's tooltip and any single-car readiness view.
 *
 * The gate is ENFORCED at write time in VehicleStatusController::setReady; these endpoints are the
 * read side so the UI can show the verdict before the user ever clicks.
 */
class ReadinessController extends Controller
{
    public function __construct(
        private ReadinessDashboardService $dashboard,
        private VehicleReadinessService $readiness,
        private VehicleLogService $log,
    ) {
    }

    /** The whole board: every queue + headline counts. The frontend shows the queues for the user's role. */
    public function index()
    {
        try {
            return ResponseHelper::SuccessResponse($this->dashboard->build(), 'Readiness board retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** One car's Pre-Delivery checklist — each pillar's pass / warn / fail verdict. */
    public function vehicle(Vehicle $vehicle)
    {
        try {
            $vehicle->loadMissing('registration');
            return ResponseHelper::SuccessResponse($this->readiness->evaluate($vehicle), 'Vehicle readiness retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The customer-facing 8-point RENTAL READINESS CHECKLIST for one car — the interactive gate the
     * sales agent clears before confirming a rental/booking (see ContractForm's readiness step).
     */
    public function rentalChecklist(Vehicle $vehicle)
    {
        try {
            $vehicle->loadMissing('registration');
            return ResponseHelper::SuccessResponse($this->readiness->rentalChecklist($vehicle), 'Rental checklist retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Set the MANUAL Cleaning checklist point inline from the gate, then return the freshly
     * re-evaluated checklist so a resolved blocker clears without a page reload.
     */
    public function setChecklistField(Vehicle $vehicle, Request $request)
    {
        try {
            $data = $request->validate([
                'field' => 'required|in:cleaning_status',
                'value' => 'required|string|max:40',
            ]);
            if (! in_array($data['value'], ['clean', 'dirty', 'pending'], true)) {
                throw ValidationException::withMessages([
                    'value' => 'Invalid value for cleaning_status. Allowed: clean, dirty, pending',
                ]);
            }

            $previous = $vehicle->cleaning_status;
            $vehicle->update(['cleaning_status' => $data['value']]);

            // Traceability: never let a status flip happen silently. Log who changed the cleaning
            // point and when, on the same vehicle event trail the readiness gate and condition grade
            // already write to. Best-effort by design.
            if ($previous !== $data['value']) {
                $this->log->recordVehicle(
                    $vehicle,
                    VehicleLogEvent::EVENT_CLEANING_UPDATED,
                    $request->user(),
                    [
                        'description' => 'Cleaning ' . ($previous ?: 'unset') . ' → ' . $data['value'],
                        'meta'        => ['field' => 'cleaning_status', 'from' => $previous, 'to' => $data['value']],
                    ],
                );
            }

            $vehicle->loadMissing('registration');
            return ResponseHelper::SuccessResponse($this->readiness->rentalChecklist($vehicle), 'Checklist updated', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
