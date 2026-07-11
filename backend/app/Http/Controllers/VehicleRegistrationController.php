<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\VehicleRegistration;
use App\Http\Requests\StoreVehicleRegistrationRequest;
use App\Http\Requests\UpdateVehicleRegistrationRequest;
use App\Http\Resources\VehicleRegistrationResource;
use App\Services\VehicleRegistrationService;

class VehicleRegistrationController extends Controller
{
    private $registrationService;
    public function __construct(VehicleRegistrationService $registrationService)
    {
        $this->registrationService = $registrationService;
    }
    public function index()
    {
        try {
            $registration = $this->registrationService->index();
            $result = VehicleRegistrationResource::collection($registration);
            return ResponseHelper::SuccessResponse($result, "Vehicle registration retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Every car with its registration + insurance coverage status (vehicle-anchored).
     */
    public function coverage()
    {
        try {
            $data = $this->registrationService->coverage();
            return ResponseHelper::SuccessResponse($data, "Coverage retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function store(StoreVehicleRegistrationRequest $request)
    {
        try {
            $registration = $this->registrationService->store($request->validated());
            $result = VehicleRegistrationResource::make($registration);
            return ResponseHelper::SuccessResponse($result, "Vehicle registration created successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function show(VehicleRegistration $registration)
    {
        try {
            $result = VehicleRegistrationResource::make($registration->load(['vehicle', 'insuranceCompany']));
            return ResponseHelper::SuccessResponse($result, "Vehicle registration retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function update(UpdateVehicleRegistrationRequest $request, VehicleRegistration $registration)
    {
        try {
            $registration = $this->registrationService->update($request->validated(), $registration);
            $result = VehicleRegistrationResource::make($registration);
            return ResponseHelper::SuccessResponse($result, "Vehicle registration updated successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function destroy(VehicleRegistration $registration)
    {
        try {
            $this->registrationService->destroy($registration);
            return ResponseHelper::SuccessResponse(null, "Vehicle registration deleted successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
