<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Contract;
use App\Services\MileageChainService;
use Illuminate\Http\Request;

/**
 * Mileage Chain Audit — per-vehicle view of the contract-to-contract odometer chain, flagging
 * every consecutive handoff as match / mismatch / missing, plus a non-destructive "Quick Fix"
 * override on a single mis-typed reading. See MileageChainService for the chaining logic.
 *
 * Reads are gated by insights.view; the override writes by contracts.manage (they correct contract
 * mileage data) — both wired in routes/api.php.
 */
class MileageChainAuditController extends Controller
{
    public function __construct(private readonly MileageChainService $service)
    {
    }

    /** A page of vehicles' mileage chains + the fleet-wide funnel summary. */
    public function index(Request $request)
    {
        try {
            $page    = max(1, (int) $request->query('page', 1));
            $perPage = (int) $request->query('per_page', 20);
            $perPage = $perPage > 0 ? min($perPage, 100) : 20;
            $filter  = in_array($request->query('filter'), ['matches', 'errors'], true)
                ? $request->query('filter')
                : 'all';

            return ResponseHelper::SuccessResponse(
                $this->service->chains($page, $perPage, $filter),
                'Mileage chains retrieved',
                200,
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Apply (or update) a manual correction on one reading of a contract. `value` may be omitted/null
     * to leave the reading unchanged and only record a note.
     */
    public function storeOverride(Request $request, Contract $contract)
    {
        try {
            $data = $request->validate([
                'field' => 'required|in:out_milage,in_milage',
                'value' => 'nullable|integer|min:0|max:9999999',
                'note'  => 'nullable|string|max:1000',
            ]);

            $override = $this->service->saveOverride(
                $contract,
                $data['field'],
                $data['value'] ?? null,
                $data['note'] ?? null,
                $request->user(),
            );

            return ResponseHelper::SuccessResponse($override, 'Mileage correction saved', 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseHelper::FailureResponse($e->errors(), $e->getMessage(), 422);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Remove a correction, reverting the reading to its synced value. */
    public function destroyOverride(Request $request, Contract $contract)
    {
        try {
            $data = $request->validate([
                'field' => 'required|in:out_milage,in_milage',
            ]);

            $this->service->clearOverride($contract, $data['field']);

            return ResponseHelper::SuccessResponse(null, 'Mileage correction removed', 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseHelper::FailureResponse($e->errors(), $e->getMessage(), 422);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
