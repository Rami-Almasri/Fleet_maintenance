<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Vehicle;
use App\Services\Reports\DailyMaintenanceIntelligenceService;
use App\Services\Reports\ReportDateRange;
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

    /**
     * The rules for the system dashboard's query string, exposed so they can be tested as rules
     * rather than only through a request.
     *
     * `after_or_equal:from` is the whole point of the pair: an inverted range is REFUSED, never
     * silently swapped. Swapping would answer a question the reader did not ask and print a period
     * header they did not choose. Both dates are optional and their absence means "all history",
     * which is the default so no existing reader loses their report.
     */
    public static function vehicleSystemRules(): array
    {
        return [
            'system' => ['nullable', 'string', 'in:' . implode(',', array_keys(VehicleSystemDashboardService::SYSTEMS))],
            'from'   => ['nullable', 'date_format:Y-m-d'],
            'to'     => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** GET /reports/vehicle-system/{vehicle}?system=engine&from=YYYY-MM-DD&to=YYYY-MM-DD */
    public function vehicleSystem(Request $request, Vehicle $vehicle)
    {
        try {
            $validated = $request->validate(self::vehicleSystemRules(), [
                'to.after_or_equal' => 'The end of the period must not be earlier than its start.',
            ]);

            return ResponseHelper::SuccessResponse(
                $this->systemDashboard->build(
                    $vehicle,
                    $validated['system'] ?? 'engine',
                    ReportDateRange::of($validated['from'] ?? null, $validated['to'] ?? null),
                ),
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
