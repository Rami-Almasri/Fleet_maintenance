<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Vehicle;
use App\Services\MaintenanceOperationsService;

/**
 * Maintenance Operations — the operational control center for every vehicle currently in the workshop.
 * Pure reads over existing data (composed by MaintenanceOperationsService) → maintenance.view.
 *
 *   GET  maintenance-operations                     → KPI summary + one rich card per in-shop vehicle
 *   GET  maintenance-operations/vehicle/{vehicle}   → per-vehicle detail: full checkpoint + workflow timeline
 */
class MaintenanceOperationsController extends Controller
{
    public function __construct(private MaintenanceOperationsService $ops)
    {
    }

    /** The whole board: attention-driving KPIs + every in-maintenance vehicle card (worst first). */
    public function index()
    {
        return $this->run(fn () => ResponseHelper::SuccessResponse(
            $this->ops->board(),
            'Maintenance operations board retrieved',
            200,
        ));
    }

    /** One vehicle's maintenance context — the card plus its progress + workflow timelines (drawer). */
    public function vehicle(Vehicle $vehicle)
    {
        return $this->run(fn () => ResponseHelper::SuccessResponse(
            $this->ops->vehicleDetail($vehicle),
            'Maintenance operations detail retrieved',
            200,
        ));
    }

    /** One unified error path (validation → 422, else mapped + logged). Mirrors the other controllers. */
    private function run(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
