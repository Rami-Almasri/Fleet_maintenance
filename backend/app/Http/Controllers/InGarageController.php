<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\RecordGarageRequest;
use App\Models\Contract;
use App\Services\InGarageService;

/**
 * In the Garage — which cars are at a garage right now, and which garage each one is at.
 * All logic lives in InGarageService; this only orchestrates.
 */
class InGarageController extends Controller
{
    private InGarageService $inGarage;

    public function __construct(InGarageService $inGarage)
    {
        $this->inGarage = $inGarage;
    }

    public function index()
    {
        try {
            return ResponseHelper::SuccessResponse(
                $this->inGarage->board(),
                'Cars in the garage retrieved successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Record (or clear, by sending vendor_id = null) the garage for one open maintenance visit.
     * Returns the row as it now reads, so the board can swap that one line without a full reload.
     */
    public function recordGarage(RecordGarageRequest $request, Contract $contract)
    {
        try {
            $saved = $this->inGarage->recordGarage(
                $contract,
                $request->validated()['vendor_id'] ?? null,
                $request->user()
            );

            return ResponseHelper::SuccessResponse(
                ['row' => $this->inGarage->rowForContract($saved)],
                'Garage recorded successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
