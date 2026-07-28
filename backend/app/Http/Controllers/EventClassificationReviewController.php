<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\FaultCatalog;
use App\Models\InspectionType;
use App\Models\MaintenanceTask;
use App\Models\ServiceCatalog;
use App\Services\EventClassificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Classification Review — the human-in-the-loop queue for maintenance events the resolver was UNSURE about
 * (needs_review). It is the foundation for improving the classifier over time: every card shows the
 * original text, the current classification, and the resolver's fresh suggestion; a human confirms or
 * corrects, and the decision is written back as an authoritative `manual`/`catalog` classification.
 *
 * Gated by maintenance.manage. Pure Event Type layer — writing `kind` moves no money (query-builder update,
 * no cost re-trigger), and while EVENT_KIND_MODE=shadow the confirmed kind still isn't read by analytics.
 */
class EventClassificationReviewController extends Controller
{
    public function __construct(private EventClassificationService $classifier)
    {
    }

    /** The needs_review queue + the catalog options the reviewer picks from. */
    public function index()
    {
        try {
            $tasks = MaintenanceTask::needsReview()
                ->with([
                    'vehicle:id,plate_no,make,model',
                    'maintenance:id,visit_context,maintenance_type,trigger_reason',
                ])
                ->latest()
                ->limit(300)
                ->get()
                ->map(fn (MaintenanceTask $t) => [
                    'id'                => $t->id,
                    'symptom'           => $t->symptom,              // ORIGINAL text
                    'category_key'      => $t->category_key,
                    'current'           => [                          // CURRENT classification
                        'kind'               => $t->kind,
                        'fault_catalog_id'   => $t->fault_catalog_id,
                        'service_catalog_id' => $t->service_catalog_id,
                        'inspection_type_id' => $t->inspection_type_id,
                        'source'             => $t->classification_source,
                    ],
                    // SUGGESTED — re-run the resolver now (it may be smarter than when this row was written).
                    'suggested_kind'    => $this->classifier->resolveLegacyKind($t)['kind'],
                    'vehicle'           => $t->vehicle ? [
                        'plate_no' => $t->vehicle->plate_no,
                        'make'     => $t->vehicle->make,
                        'model'    => $t->vehicle->model,
                    ] : null,
                    'ticket'            => $t->maintenance ? [
                        'id'               => $t->maintenance->id,
                        'visit_context'    => $t->maintenance->visit_context,
                        'maintenance_type' => $t->maintenance->maintenance_type,
                    ] : null,
                    'created_at'        => optional($t->created_at)->toIso8601String(),
                ]);

            return ResponseHelper::SuccessResponse([
                'tasks'    => $tasks->values(),
                'count'    => $tasks->count(),
                'catalogs' => [
                    'fault'      => FaultCatalog::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'category_key']),
                    'service'    => ServiceCatalog::query()->orderBy('name')->get(['id', 'name', 'category_key']),
                    'inspection' => InspectionType::query()->orderBy('name')->get(['id', 'name']),
                ],
            ], 'Classification review queue retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Confirm/correct one task's classification — the human decision becomes authoritative. */
    public function confirm(Request $request, MaintenanceTask $task)
    {
        try {
            $data = $request->validate([
                'kind'       => ['required', Rule::in(MaintenanceTask::KINDS)],
                'catalog_id' => ['nullable', 'integer'],
            ]);

            $catalogId = $data['catalog_id'] ?? null;

            if ($catalogId) {
                // The reviewer pinned a catalog row → authoritative catalog classification.
                $attrs = $this->classifier->classifyFromCatalog(['kind' => $data['kind'], 'catalog_id' => (int) $catalogId]);
            } else {
                // A plain kind confirmation with no catalog row (allowed by the CHECK: all catalog ids null).
                $attrs = [
                    'kind'                  => $data['kind'],
                    'fault_catalog_id'      => null,
                    'service_catalog_id'    => null,
                    'inspection_type_id'    => null,
                    'classification_source' => MaintenanceTask::CLS_MANUAL,
                ];
            }
            $attrs['needs_review'] = false; // a human has ruled — clear the flag

            // Query-builder update mirrors the backfill: a kind change re-triggers no ticket cost roll-up.
            DB::table('maintenance_tasks')->where('id', $task->id)->update($attrs);

            return ResponseHelper::SuccessResponse(['id' => $task->id] + $attrs, 'Classification confirmed', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
