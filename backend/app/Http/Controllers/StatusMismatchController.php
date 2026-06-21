<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Services\StatusMismatchService;

class StatusMismatchController extends Controller
{
    /**
     * Vehicles whose lifecycle status disagrees with their open contracts — e.g. a car
     * out on a rental but not marked "Rented", or marked "Rented" with no open contract.
     */
    public function index(StatusMismatchService $mismatches)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $mismatches->all(),
                "Status mismatches retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
