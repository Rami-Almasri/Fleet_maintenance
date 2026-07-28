<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\MaintenanceTask;
use App\Models\Vehicle;
use App\Services\Knowledge\RepairIntelligencePresenter;
use App\Services\Knowledge\RepairRecommendationService;
use App\Services\Knowledge\SimilarRepairQuery;
use Illuminate\Http\Request;

/**
 * Fleet Knowledge Engine — "Previous Similar Repairs + Recommendation Explanation" (P0).
 *
 * Two pure-READ endpoints over the maintenance history (no writes, no new tables):
 *   • forTask()  — intelligence for an existing fault (its vehicle + fault identity).
 *   • preview()  — intelligence while a fault is being TYPED, before a task row exists (vehicle + symptom).
 *
 * Both return the same envelope: { query, has_history, sample_size, confidence, recommendation,
 * similar_repairs, evidence, why }. Money is included; the frontend gates it behind SHOW_FINANCIALS.
 */
class RepairIntelligenceController extends Controller
{
    public function __construct(private RepairRecommendationService $service)
    {
    }

    /** Intelligence for an existing fault. */
    public function forTask(Request $request, MaintenanceTask $task)
    {
        try {
            $task->loadMissing('vehicle:id,make,model');
            $data = $this->service->forFault(SimilarRepairQuery::fromTask($task));

            return ResponseHelper::SuccessResponse($this->present($request, $data), 'Repair intelligence retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Intelligence for a vehicle + a symptom being entered (before the fault exists). */
    public function preview(Request $request)
    {
        try {
            $validated = $request->validate([
                'vehicle_id'       => ['required', 'integer', 'exists:vehicles,id'],
                'symptom'          => ['nullable', 'string', 'max:255'],
                'category_key'     => ['nullable', 'string', 'max:60'],
                'fault_catalog_id' => ['nullable', 'integer', 'exists:fault_catalog,id'],
            ]);

            $vehicle = Vehicle::query()->select(['id', 'make', 'model'])->findOrFail($validated['vehicle_id']);

            $query = SimilarRepairQuery::fromVehicleAndSymptom(
                vehicle: $vehicle,
                symptom: $validated['symptom'] ?? null,
                categoryKey: $validated['category_key'] ?? null,
                faultCatalogId: $validated['fault_catalog_id'] ?? null,
            );

            return ResponseHelper::SuccessResponse($this->present($request, $this->service->forFault($query)), 'Repair intelligence retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Map the engine output to the frozen public contract, redacting money on the SERVER for any user
     * without `billing.view` (so cost never leaks over the wire, only in the UI).
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function present(Request $request, array $data): array
    {
        $showFinancials = (bool) $request->user()?->can('billing.view');

        return RepairIntelligencePresenter::present($data, $showFinancials);
    }
}
