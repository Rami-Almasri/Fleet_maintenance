<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\RealProfitService;
use App\Services\ServiceWindowService;

/**
 * Fleet-wide profitability — one row per car, the LIFETIME Profit Bridge for every vehicle:
 *   gross_revenue  = (rent − discount) + collected usage   (rentals only, type 'C')
 *   operating_cost = sales commissions + co-driver fees
 *   maintenance    = workshop-log repair cost (sheet + manual)
 *   net            = gross_revenue − operating_cost − maintenance   (what was actually pocketed)
 *
 * Every number comes from RealProfitService — the SAME engine as the car-profile Profit Bridge and
 * the Negative-Yield flag — so the whole platform reconciles. Sorted best-to-worst by net.
 */
class ProfitabilityController extends Controller
{
    public function index(RealProfitService $profit, ServiceWindowService $serviceWindow)
    {
        try {
            // Lifetime gross→net bridge per vehicle (rentals only), straight from the shared engine.
            $bridge = $profit->vehicleBridge();

            // In-Service Date (first rental) per vehicle — same shared source as Fleet Utilization.
            // A car with no entry here has never been rented → Pending Service / Onboarding.
            $inService = $serviceWindow->inServiceDates();

            // Workshop visit count per vehicle — display only (the bridge already carries the cost).
            $visits = Maintenance::query()
                ->whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
                ->whereNotNull('vehicle_id')
                ->groupBy('vehicle_id')
                ->selectRaw('vehicle_id as vid, COUNT(*) as visits')
                ->get()
                ->keyBy('vid');

            $rows = Vehicle::query()
                ->select('id', 'plate_no', 'make', 'model', 'status', 'for_sale', 'purchase_date')
                ->get()
                ->map(function ($v) use ($bridge, $visits, $inService) {
                    $b = $bridge[$v->id] ?? [
                        'gross_revenue' => 0.0, 'operating_cost' => 0.0, 'maintenance' => 0.0,
                        'net_profit' => 0.0, 'contracts' => 0,
                    ];

                    return [
                        'vehicle_id'      => $v->id,
                        'plate'           => $v->plate_no,
                        'car'             => trim((string) ($v->make . ' ' . $v->model)) ?: null,
                        'status'          => $v->status,
                        'for_sale'        => (bool) $v->for_sale,
                        // In-Service / Owned-Since metadata (consistent with Fleet Utilization).
                        'owned_since'     => optional($v->purchase_date)->toDateString(),
                        'in_service_date' => $inService[$v->id] ?? null,
                        'pending_service' => ! isset($inService[$v->id]),   // bought but never rented
                        'gross_revenue'   => round((float) $b['gross_revenue'], 2),
                        'operating_cost'  => round((float) $b['operating_cost'], 2),
                        'maintenance'     => round((float) $b['maintenance'], 2),
                        'net'             => round((float) $b['net_profit'], 2),
                        'rentals'         => (int) $b['contracts'],
                        'visits'          => (int) ($visits[$v->id]->visits ?? 0),
                    ];
                })
                ->sortByDesc('net')
                ->values();

            $summary = [
                'vehicles'          => $rows->count(),
                'total_gross'       => round($rows->sum('gross_revenue'), 2),
                'total_operating'   => round($rows->sum('operating_cost'), 2),
                'total_maintenance' => round($rows->sum('maintenance'), 2),
                'total_net'         => round($rows->sum('net'), 2),
                'profitable'        => $rows->where('net', '>', 0)->count(),
                'loss_making'       => $rows->where('net', '<', 0)->count(),
                'pending_service'   => $rows->where('pending_service', true)->count(),
            ];

            return ResponseHelper::SuccessResponse(
                ['vehicles' => $rows, 'summary' => $summary],
                'Fleet profitability retrieved successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
