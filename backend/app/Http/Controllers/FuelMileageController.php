<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Services\FuelMileageService;
use Illuminate\Http\Request;

/**
 * Fuel & Mileage Reconciliation — the live "fuel_data_plate_summary" report for the whole fleet.
 *
 *   index()   → one reconciliation row per car (actual vs contract mileage, out-of-contract leakage,
 *               fuel debit, odometer-rollback flags) + fleet totals.
 *   vehicle() → the per-contract ledger behind one car, with the off-contract gap driven before each leg.
 *
 * Everything is derived from already-synced contracts, so it is always current and needs no import.
 */
class FuelMileageController extends Controller
{
    public function index(Request $request, FuelMileageService $service)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $service->fleet($request->query('from'), $request->query('to')),
                'Fuel & mileage reconciliation retrieved successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function vehicle(int $vehicle, Request $request, FuelMileageService $service)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $service->vehicle($vehicle, $request->query('from'), $request->query('to')),
                'Vehicle fuel & mileage ledger retrieved successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
