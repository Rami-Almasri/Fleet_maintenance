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
            return ResponseHelper::fromException($e);
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
            return ResponseHelper::fromException($e);
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
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Month-by-month maintenance trends powering the dashboard charts: workshop cost
     * per month (bar) and average days-in-shop per visit (trend line). `months` query
     * param controls the window (default 12, clamped server-side).
     */
    public function trends(Request $request, DashboardService $dashboard)
    {
        try {
            $months = (int) $request->query('months', 12);

            return ResponseHelper::SuccessResponse(
                $dashboard->maintenanceTrends($months),
                "Dashboard trends retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * "Proactive Flags" — three forward-looking conditions for the homepage: rentals expiring
     * within ?days=N (default 7), concluded rentals with an unpaid balance, and inspections
     * due/overdue. Reads the same source lists the NotificationScanner raises its
     * rental_expiring / invoice_overdue / inspection_due alerts from; every item deep-linkable.
     */
    public function proactiveFlags(Request $request, DashboardService $dashboard)
    {
        try {
            $days = max(1, (int) $request->query('days', DashboardService::EXPIRY_WINDOW_DAYS));

            return ResponseHelper::SuccessResponse(
                $dashboard->proactiveFlags($days),
                "Proactive flags retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * "Most in Maintenance" — the cars with the most workshop visits over a trailing window
     * (?days=N, default 90). Drives the homepage column whose date filter lets the user widen or
     * narrow the window without reloading the rest of the dashboard.
     */
    public function mostMaintained(Request $request, DashboardService $dashboard)
    {
        try {
            $days = min(730, max(1, (int) $request->query('days', 90)));

            return ResponseHelper::SuccessResponse(
                $dashboard->mostMaintained($days),
                "Most-maintained cars retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The "Fleet Pulse" grid: every active car with a colour-coded live state and a
     * maintenance-completion percentage for the ones in the shop.
     */
    public function fleetPulse(DashboardService $dashboard)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $dashboard->fleetPulse(),
                "Fleet pulse retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
