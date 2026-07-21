<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\DepreciationService;
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
 *
 * ECONOMIC PROFIT (Phase 1): net is cash pocketed but ignores the asset value the car lost over its
 * life. economic_profit = net − accumulated_depreciation (straight-line, from DepreciationService) —
 * the truer "did this car create value" figure. A car with no purchase price/date has UNKNOWN
 * depreciation, so its depreciation / book_value / economic_profit are NULL, never 0 (unknown ≠ free).
 * All economic fields are additive — the pre-existing keys are unchanged (backward-compatible).
 */
class ProfitabilityController extends Controller
{
    public function index(RealProfitService $profit, ServiceWindowService $serviceWindow, DepreciationService $depreciation)
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
                ->select('id', 'plate_no', 'make', 'model', 'status', 'for_sale', 'purchase_date', 'purchase_price', 'category')
                ->get()
                ->map(function ($v) use ($bridge, $visits, $inService, $depreciation) {
                    $b = $bridge[$v->id] ?? [
                        'gross_revenue' => 0.0, 'operating_cost' => 0.0, 'maintenance' => 0.0,
                        'net_profit' => 0.0, 'contracts' => 0,
                    ];

                    $net = round((float) $b['net_profit'], 2);

                    // Straight-line depreciation for this car (NULL money when purchase data missing).
                    $dep       = $depreciation->forVehicle($v);
                    $depAmount = $dep['has_data'] ? $dep['accumulated_depreciation'] : null;

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
                        'net'             => $net,
                        // Economic layer (additive) — net after the asset's lost value.
                        'purchase_price'  => $dep['purchase_price'],
                        'depreciation'    => $depAmount,
                        'book_value'      => $dep['has_data'] ? $dep['book_value'] : null,
                        'economic_profit' => $depAmount !== null ? round($net - $depAmount, 2) : null,
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
                // Economic layer (additive) — sums/counts over cars with KNOWN purchase data only.
                'total_depreciation'   => round($rows->whereNotNull('depreciation')->sum('depreciation'), 2),
                'total_book_value'     => round($rows->whereNotNull('book_value')->sum('book_value'), 2),
                'total_economic'       => round($rows->whereNotNull('economic_profit')->sum('economic_profit'), 2),
                'depreciation_known'   => $rows->whereNotNull('depreciation')->count(),
                'depreciation_unknown' => $rows->whereNull('depreciation')->count(),
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
