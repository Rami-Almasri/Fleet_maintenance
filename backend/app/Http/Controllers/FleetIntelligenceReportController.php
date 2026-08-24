<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Vehicle;
use App\Services\Reports\DailyMaintenanceIntelligenceService;
use App\Services\Reports\VehicleSystemDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The two management reports: the day's workshop picture, and one car's history on one system.
 * Both are read-only views over records a person already entered — all logic lives in the services.
 */
class FleetIntelligenceReportController extends Controller
{
    public function __construct(
        private DailyMaintenanceIntelligenceService $daily,
        private VehicleSystemDashboardService $systemDashboard,
    ) {
    }

    /** GET /reports/daily-maintenance?date=YYYY-MM-DD */
    public function dailyMaintenance(Request $request)
    {
        try {
            $validated = $request->validate(['date' => ['nullable', 'date']]);
            $date = ! empty($validated['date']) ? Carbon::parse($validated['date']) : null;

            return ResponseHelper::SuccessResponse(
                $this->daily->build($date),
                'Daily fleet maintenance intelligence retrieved successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** GET /reports/vehicle-system/{vehicle}?system=engine */
    public function vehicleSystem(Request $request, Vehicle $vehicle)
    {
        try {
            $validated = $request->validate([
                'system' => ['nullable', 'string', 'in:' . implode(',', array_keys(VehicleSystemDashboardService::SYSTEMS))],
            ]);

            return ResponseHelper::SuccessResponse(
                $this->systemDashboard->build($vehicle, $validated['system'] ?? 'engine'),
                'Vehicle system dashboard retrieved successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** GET /reports/systems — the systems a dashboard can be built for (drives the picker). */
    public function systems()
    {
        return ResponseHelper::SuccessResponse(
            collect(VehicleSystemDashboardService::SYSTEMS)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
            'Systems retrieved successfully',
            200
        );
    }
}
