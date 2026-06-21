<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Services\DataHealthService;

class DataHealthController extends Controller
{
    /**
     * "Data Health" — incomplete or broken records (cars without VIN/plate/mileage,
     * contracts with no car/customer, duplicate VINs, nameless customers, …) grouped
     * by severity with example rows and links, so the data can be cleaned up.
     */
    public function index(DataHealthService $health)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $health->all(),
                "Data health retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
