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
            return ResponseHelper::fromException($e);
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
            return ResponseHelper::fromException($e);
        }
    }

    public function store(StoreContractRequest $request)
    {
        try {
            $data = $request->validated();

            // Yellow-grade manager override (Option A: one-step, permission-gated). We only forward the
            // override to the service when the ACTING user is actually authorised (holds
            // `operations.override` — managers/admins). A non-manager's flag is silently dropped, so the
            // service still blocks the Yellow car. override_by/reason land in the contract audit snapshot.
            $user = $request->user();
            if ($request->boolean('manager_override') && $user?->can('operations.override')) {
                $data['manager_override'] = true;
                $data['override_by']      = $user->name ?? (string) $user->id;
                $data['override_reason']  = trim((string) $request->input('override_reason')) ?: null;
            }

            // Deferred Maintenance: a deliberate "pull this in-shop car out for a customer" decision.
            // Only honoured for a user who may manage maintenance; it releases the maintenance blocks
            // in the eligibility guard and drives the ticket-close + flag inside ContractService.
            if ($request->boolean('pull_from_maintenance') && $user?->can('maintenance.manage')) {
                $data['pull_from_maintenance'] = true;
            }

            $contract = $this->contractService->store($data);
            $result = ContractResource::make($contract);
            return ResponseHelper::SuccessResponse($result, "Contract created successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function show(Contract $contract)
    {
        try {
            // The /api/v1/contracts import now stores the full row (financials + real
            // in/out dates), so the page is complete straight from our DB — no live
            // per-view enrichment needed.
            $contract->load(['customer', 'vehicle', 'maintenance.vendor', 'items', 'invoices', 'payments.invoice']);
            // The garage often lives in the workshop event log, not on the contract header —
            // resolve "where the car is / was last" so the detail page never shows a blank garage.
            if ($contract->contract_type === 'U') {
                $contract->current_garage = $this->contractService->resolveCurrentGarage($contract);
            }
            $result = ContractResource::make($contract);
            return ResponseHelper::SuccessResponse($result, "Contract retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function update(UpdateContractRequest $request, Contract $contract)
    {
        try {
            $contract = $this->contractService->update($request->validated(), $contract);
            $result = ContractResource::make($contract);
            return ResponseHelper::SuccessResponse($result, "Contract updated successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function destroy(Contract $contract)
    {
        try {
            $this->contractService->destroy($contract);
            return ResponseHelper::SuccessResponse(null, "Contract deleted successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
