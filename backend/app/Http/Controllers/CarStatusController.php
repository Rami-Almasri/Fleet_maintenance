<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Vehicle;
use App\Services\CarStatusService;

/**
 * Car Status — the enterprise "what is happening in my workshop right now?" surface.
 *
 * Two pure-read endpoints backed by CarStatusService:
 *   • dashboard()  — the KPI strip, the live one-row-per-vehicle table, and the six manager sections.
 *   • vehicle()    — the full Maintenance Intelligence Center for one vehicle.
 *
 * Nothing here mutates state or touches the workflow state machine — it only reads existing data. Gated
 * by maintenance.view (the same permission that already opens the maintenance board / ticket pages).
 */
class CarStatusController extends Controller
{
    public function __construct(private CarStatusService $service)
    {
    }

    /** The full Car Status dashboard payload (KPIs + live rows + sections). */
    public function dashboard()
    {
        try {
            return ResponseHelper::SuccessResponse($this->service->dashboard(), 'Car status dashboard retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** The Maintenance Intelligence Center analytics for one vehicle. */
    public function vehicle(Vehicle $vehicle)
    {
        try {
            return ResponseHelper::SuccessResponse($this->service->vehicleIntelligence($vehicle), 'Vehicle maintenance intelligence retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
