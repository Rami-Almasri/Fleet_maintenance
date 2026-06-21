<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Contract;
use App\Http\Requests\StoreContractRequest;
use App\Http\Requests\UpdateContractRequest;
use App\Http\Resources\ContractResource;
use App\Services\ContractService;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    private $contractService;
    public function __construct(ContractService $contractService)
    {
        $this->contractService = $contractService;
    }
    public function index(Request $request)
    {
        try {
            $contract = $this->contractService->index($request->only('contract_type', 'state', 'balance', 'contract_serial', 'search', 'vehicle_id'));
            // Surface the paginator total: nesting the resource collection inside
            // ResponseHelper drops Laravel's pagination meta, so pass it explicitly.
            $result = [
                'items'     => ContractResource::collection($contract),
                'total'     => $contract->total(),
                'page'      => $contract->currentPage(),
                'last_page' => $contract->lastPage(),
            ];
            return ResponseHelper::SuccessResponse($result, "Contract retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Suggest the next auto-generated contract number for the New Contract form,
     * so the user never has to type one. Web contracts use a "W-" prefix to stay
     * clear of OfficeManager's authoritative integer sequences.
     */
    public function nextNo()
    {
        try {
            $contractNo = $this->contractService->nextWebContractNo();
            return ResponseHelper::SuccessResponse(['contract_no' => $contractNo], "Next contract number generated", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function store(StoreContractRequest $request)
    {
        try {
            $contract = $this->contractService->store($request->validated());
            $result = ContractResource::make($contract);
            return ResponseHelper::SuccessResponse($result, "Contract created successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function show(Contract $contract)
    {
        try {
            // The /api/v1/contracts import now stores the full row (financials + real
            // in/out dates), so the page is complete straight from our DB — no live
            // per-view enrichment needed.
            $contract->load(['customer', 'vehicle', 'maintenance.vendor', 'items', 'invoices']);
            // The garage often lives in the workshop event log, not on the contract header —
            // resolve "where the car is / was last" so the detail page never shows a blank garage.
            if ($contract->contract_type === 'U') {
                $contract->current_garage = $this->contractService->resolveCurrentGarage($contract);
            }
            $result = ContractResource::make($contract);
            return ResponseHelper::SuccessResponse($result, "Contract retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function update(UpdateContractRequest $request, Contract $contract)
    {
        try {
            $contract = $this->contractService->update($request->validated(), $contract);
            $result = ContractResource::make($contract);
            return ResponseHelper::SuccessResponse($result, "Contract updated successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function destroy(Contract $contract)
    {
        try {
            $this->contractService->destroy($contract);
            return ResponseHelper::SuccessResponse(null, "Contract deleted successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
