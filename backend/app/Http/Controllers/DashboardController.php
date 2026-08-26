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
     * "Most Maintained Models" — which car types (make + model) go to the workshop most, by all-time
     * ticket volume. Powers the homepage ranking card. Read-only; no date window (all-time by design).
     */
    public function mostMaintainedModels(Request $request, DashboardService $dashboard)
    {
        try {
            $limit = min(20, max(1, (int) $request->query('limit', 8)));

            return ResponseHelper::SuccessResponse(
                $dashboard->mostMaintainedModels($limit),
                "Most-maintained models retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * "Maintenance Progress" — the operational monitoring centre for every car currently in the
     * workshop: live ETA, last checkpoint, responsible owner, and a colour-coded progress status
     * (on_track / delayed / critical / needs_update / overdue) plus the roll-up counts.
     */
    public function maintenanceProgress(Request $request, DashboardService $dashboard)
    {
        try {
            $limit = min(200, max(1, (int) $request->query('limit', 100)));

            return ResponseHelper::SuccessResponse(
                $dashboard->maintenanceProgress($limit),
                "Maintenance progress retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * "Most Frequent Faults" — the fleet's most-reported symptoms, ranked by occurrence. Powers the
     * homepage circular (donut) KPI. Read-only; all-time by design.
     */
    public function topFaults(Request $request, DashboardService $dashboard)
    {
        try {
            $limit = min(12, max(1, (int) $request->query('limit', 6)));

            return ResponseHelper::SuccessResponse(
                $dashboard->topFaults($limit),
                "Top faults retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * "What keeps coming back" — the fault that returned after its repair, the part that went on the
     * same car twice, and the service that was done again too soon. One card, three ranked tabs.
     *
     * `dashboard.view` opens the endpoint; each SECTION is then gated on the permission that owns its
     * ledger, and a section the caller cannot read is never computed. A dispatcher with `maintenance.view`
     * but no `parts.view` therefore gets the faults and services tabs and no parts tab at all — rather
     * than a parts tab that 403s, or worse, one that renders the parts ledger to someone without it.
     */
    public function repeats(Request $request, DashboardService $dashboard)
    {
        try {
            $windowDays = min(365, max(1, (int) $request->query('window_days', DashboardService::REPEAT_WINDOW_DAYS)));
            $limit      = min(20, max(1, (int) $request->query('limit', 6)));

            $user = $request->user();
            $only = array_values(array_filter([
                $user?->can('maintenance.recurring.view') ? 'faults' : null,
                $user?->can('parts.view') ? 'parts' : null,
                $user?->can('maintenance.view') ? 'services' : null,
            ]));

            if (! $only) {
                // Nothing this user may read. An empty card is the honest answer — not a 403, which would
                // read as "something went wrong" on a dashboard the user is legitimately allowed to open.
                return ResponseHelper::SuccessResponse(
                    ['window_days' => $windowDays, 'sections' => []],
                    'Repeat leaderboards retrieved successfully',
                    200
                );
            }

            return ResponseHelper::SuccessResponse(
                $dashboard->repeats($windowDays, $limit, $only),
                'Repeat leaderboards retrieved successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * DAMAGE dashboard — externally-caused damage, which is deliberately absent from every fault figure.
     *
     * Damage is not a reliability signal, so it never appears in Top Faults, health, recurrence or
     * forecasting. That exclusion is only defensible because the events remain fully visible HERE:
     * counts, exposed vehicles, cost, damage type and the chargeable/insurable split. Excluding damage
     * from the fault charts without giving it its own surface would have been hiding it, not modelling
     * it. Optional ?from= / ?to= (Y-m-d) window; all-time by default.
     */
    public function damage(Request $request, \App\Services\DamageAnalyticsService $damage)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $damage->report(
                    $request->query('from') ? (string) $request->query('from') : null,
                    $request->query('to') ? (string) $request->query('to') : null,
                ),
                'Damage analytics retrieved successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Fault Leaderboard drill-down — "which cars fixed this fault the most". For one canonical fault
     * category (?fault=Brakes) returns the vehicles ranked by how many times that fault hit them,
     * combining our system + the historical workshop sheet exactly as the leaderboard bar does.
     */
    public function faultCars(Request $request, DashboardService $dashboard)
    {
        try {
            $fault = trim((string) $request->query('fault', ''));
            $limit = min(25, max(1, (int) $request->query('limit', 12)));

            return ResponseHelper::SuccessResponse(
                $dashboard->faultCars($fault, $limit),
                "Fault cars retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The FULL "Most in Maintenance" list behind the homepage column's "All →" link — every in-fleet car
     * that saw the workshop over a trailing window (?days=N, default 90), with how often (visits) and how
     * long (total days in the shop). Powers the Maintenance History page.
     */
    public function maintenanceHistory(Request $request, DashboardService $dashboard)
    {
        try {
            // days=0 → all-time (no trailing window); otherwise a trailing window capped at 2 years.
            $days = min(730, max(0, (int) $request->query('days', 90)));
            [$from, $to] = $this->parseDateRange($request);

            return ResponseHelper::SuccessResponse(
                $dashboard->maintenanceHistory($days, $from, $to),
                "Maintenance history retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * "Most Maintained Cars" — the vehicles ranked by TOTAL LIFETIME days in maintenance (all-time,
     * no window): the sum of every maintenance period the car has ever had. Powers the homepage widget.
     */
    public function mostMaintainedCars(Request $request, DashboardService $dashboard)
    {
        try {
            $limit = min(50, max(1, (int) $request->query('limit', 8)));
            $sort = $request->query('sort', 'downtime'); // 'downtime' (days) | 'periods'

            return ResponseHelper::SuccessResponse(
                $dashboard->lifetimeMaintenanceDays($limit, $sort),
                "Most-maintained cars retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The individual workshop visits for ONE car — the "see N visits" drill-down on the
     * Maintenance History page. Same trailing window (?days=N, default 90) as the summary list.
     */
    public function maintenanceHistoryVisits(Request $request, int $vehicle, DashboardService $dashboard)
    {
        try {
            // days=0 → all-time (matches the summary list's window).
            $days = min(730, max(0, (int) $request->query('days', 90)));
            [$from, $to] = $this->parseDateRange($request);

            return ResponseHelper::SuccessResponse(
                $dashboard->maintenanceHistoryVisits($vehicle, $days, $from, $to),
                "Vehicle maintenance visits retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Parse an optional explicit `from`/`to` date-range filter (YYYY-MM-DD) from the request.
     * Invalid dates are dropped; if both are present and reversed they're swapped so from <= to.
     * Either bound (or both) present means the caller wants an explicit range over the trailing window.
     *
     * @return array{0: ?string, 1: ?string} [from, to]
     */
    private function parseDateRange(Request $request): array
    {
        $norm = function ($v) {
            $v = is_string($v) ? trim($v) : '';
            if ($v === '') {
                return null;
            }
            try {
                return \Carbon\Carbon::parse($v)->toDateString();
            } catch (\Exception $e) {
                return null;
            }
        };

        $from = $norm($request->query('from'));
        $to   = $norm($request->query('to'));

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
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
