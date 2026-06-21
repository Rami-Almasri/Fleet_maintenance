<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Services\MaintenanceReturnService;

class MaintenanceReturnController extends Controller
{
    /**
     * Maintenance contracts still open in OfficeManager even though the N-Maintenance
     * sheet shows the car is already back from the garage — plus the in-progress and
     * not-in-sheet jobs, so the page reads as a full contract ↔ sheet reconciliation.
     */
    public function index(MaintenanceReturnService $returns)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $returns->reconcile(),
                "Maintenance return reconciliation retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
