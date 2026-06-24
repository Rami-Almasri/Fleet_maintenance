<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\PolicyOverrideAudit;

/**
 * Override Audit — read-only trail of every "Rental-First" policy override: a manager
 * knowingly opened a maintenance contract on a car that still had a live rental. Shows
 * who allowed it, why (reason code + notes), which rental was broken and when.
 */
class OverrideAuditController extends Controller
{
    public function index()
    {
        try {
            $rows = PolicyOverrideAudit::query()
                ->orderByDesc('created_at')
                ->limit(500)
                ->get()
                ->map(fn (PolicyOverrideAudit $a) => [
                    'id'                 => $a->id,
                    'created_at'         => optional($a->created_at)->toIso8601String(),
                    'user_name'          => $a->user_name,
                    'plate'              => $a->plate,
                    'vehicle_id'         => $a->vehicle_id,
                    'action'             => $a->action,
                    'reason_code'        => $a->reason_code,
                    'reason_label'       => $a->reason_label,
                    'notes'              => $a->notes,
                    'rental_contract_no' => $a->rental_contract_no,
                    'result_contract_no' => $a->result_contract_no,
                ]);

            return ResponseHelper::SuccessResponse([
                'overrides'    => $rows,
                'count'        => $rows->count(),
            ], 'Override audit retrieved', 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
