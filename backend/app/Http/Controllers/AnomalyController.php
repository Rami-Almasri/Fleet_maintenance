<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Services\AnomalyService;

class AnomalyController extends Controller
{
    /**
     * Fleet "exceptional cases": data conflicts (a car rented AND in maintenance,
     * a sold car still on an open contract, a return logged before pickup…) and
     * operational gaps, each grouped with a severity and example rows.
     */
    public function index(AnomalyService $anomalies)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $anomalies->all(),
                "Anomalies retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
