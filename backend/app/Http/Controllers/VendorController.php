<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Vendor;
use App\Http\Requests\StoreVendorRequest;
use App\Http\Requests\UpdateVendorRequest;
use App\Http\Resources\VendorResource;
use App\Services\VendorService;

class VendorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    private $vendorService;
    public function __construct(VendorService $vendorService)
    {
        $this->vendorService = $vendorService;
    }
    public function index()
    {
        try {
            $vendor = $this->vendorService->index();
            $result = VendorResource::collection($vendor);
            return ResponseHelper::SuccessResponse($result, "Vendor retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreVendorRequest $request)
    {
        try {
            $vendor = $this->vendorService->store($request->validated());
            $result = VendorResource::make($vendor);
            return ResponseHelper::SuccessResponse($result, "Vendor created successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Vendor $vendor)
    {
        try {
            $result = VendorResource::make($vendor);
            return ResponseHelper::SuccessResponse($result, "Vendor retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateVendorRequest $request, Vendor $vendor)
    {
        try {
            $vendor = $this->vendorService->update($request->validated(), $vendor);
            $result = VendorResource::make($vendor);
            return ResponseHelper::SuccessResponse($result, "Vendor updated successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Vendor $vendor)
    {
        try {
            $this->vendorService->destroy($vendor);
            return ResponseHelper::SuccessResponse(null, "Vendor deleted successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
