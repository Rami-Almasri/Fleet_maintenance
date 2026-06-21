<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Driver;
use App\Http\Requests\StoreDriverRequest;
use App\Http\Requests\UpdateDriverRequest;
use App\Http\Resources\DriverResource;
use App\Services\DriverService;

class DriverController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    private $driverService;
    public function __construct(DriverService $driverService)
    {
        $this->driverService = $driverService;
    }
    public function index()
    {
        try {
            $driver = $this->driverService->index();
            $result = DriverResource::collection($driver);
            return ResponseHelper::SuccessResponse($result, "Driver retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDriverRequest $request)
    {
        try {
            $driver = $this->driverService->store($request->validated());
            $result = DriverResource::make($driver);
            return ResponseHelper::SuccessResponse($result, "Driver created successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Driver $driver)
    {
        try {
            $result = DriverResource::make($driver->load('user'));
            return ResponseHelper::SuccessResponse($result, "Driver retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDriverRequest $request, Driver $driver)
    {
        try {
            $driver = $this->driverService->update($request->validated(), $driver);
            $result = DriverResource::make($driver);
            return ResponseHelper::SuccessResponse($result, "Driver updated successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Driver $driver)
    {
        try {
            $this->driverService->destroy($driver);
            return ResponseHelper::SuccessResponse(null, "Driver deleted successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
