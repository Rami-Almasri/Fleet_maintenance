<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Dashboard KPIs: total outstanding balance, active contracts,
     * expiring registrations (default within 7 days), and cars flagged for sale.
     */
    public function summary(Request $request, DashboardService $dashboard)
    {
        try {
            $days = max(0, (int) $request->query('expiring_days', 7));
            $result = $dashboard->summary($days);

            return ResponseHelper::SuccessResponse($result, "Dashboard summary retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * The "Overdue Rentals" list: every open rental whose estimated return date has
     * already passed (most-overdue first), so late cars flag themselves on the homepage.
     */
    public function overdueRentals(DashboardService $dashboard)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $dashboard->overdueRentalsList(),
                "Overdue rentals retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Cars delayed in the garage: open maintenance past its expected return date.
     */
    public function overdueMaintenance(DashboardService $dashboard)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $dashboard->overdueMaintenanceList(),
                "Overdue maintenance retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
