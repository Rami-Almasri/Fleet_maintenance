<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Services\CostIntelligenceService;

/**
 * Fleet Intelligence — read-only Phase-1 analytics surfaces. Each action delegates to a service and
 * returns the standard { data } envelope; authorization is the route's insights.view middleware
 * (no in-controller checks, matching the other read controllers). Frontend visibility of these
 * surfaces is gated by the SHOW_FLEET_INTELLIGENCE flag.
 */
class IntelligenceController extends Controller
{
    /** Maintenance cost per km / day / rental, per vehicle + fleet totals. */
    public function cost(CostIntelligenceService $cost)
    {
        try {
            return ResponseHelper::SuccessResponse($cost->fleet(), 'Cost intelligence retrieved successfully', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
