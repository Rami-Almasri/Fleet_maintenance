<?php

namespace App\Http\Resources;

use App\Models\RecurringFaultReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Recurring Fault Review case, shaped for the management inbox + detail modal: the current confirmed
 * fault, the previous FIXED repair it matched (garage, parts, result), and the elapsed-time / distance
 * signals — plus the recorded decision. Money-free by design.
 */
class RecurringFaultReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var RecurringFaultReview $r */
        $r = $this->resource;

        return [
            'id'       => $r->id,
            'status'   => $r->status,
            'decision' => $r->decision,

            'symptom'      => $r->symptom,
            'category_key' => $r->category_key,

            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id'    => $r->vehicle?->id,
                'plate' => $r->vehicle?->plate_no,
                'make'  => $r->vehicle?->make,
                'model' => $r->vehicle?->model,
            ]),

            // The current (recurring) fault + its ticket.
            'maintenance_id'      => $r->maintenance_id,
            'maintenance_task_id' => $r->maintenance_task_id,
            // Repair gate state on the current fault: null | pending | approved | rejected.
            'repair_gate'         => $this->whenLoaded('task', fn () => $r->task?->repair_gate),

            // The previous FIXED occurrence.
            'previous_maintenance_id' => $r->previous_maintenance_id,
            'previous_task_id'        => $r->previous_task_id,
            'previous_garage'         => $r->previous_garage_name,
            'previous_result'         => $r->previous_result, // 'verified_fixed' | 'fixed'
            'previous_repaired_at'    => optional($r->previous_repaired_at)->toIso8601String(),

            // Elapsed-time / distance signals.
            'days_since_repair'     => $r->days_since_repair,
            'previous_odometer'     => $r->previous_odometer,
            'current_odometer'      => $r->current_odometer,
            'distance_since_repair' => $r->distance_since_repair,
            'occurrence_count'      => $r->occurrence_count,
            'parts'                 => $r->parts ?? [],
            'context'               => $r->context,

            // Audit.
            'opened_by'     => $r->opened_by_name,
            'opened_at'     => optional($r->opened_at)->toIso8601String(),
            'decided_by'    => $r->decided_by_name,
            'decided_at'    => optional($r->decided_at)->toIso8601String(),
            'decision_note' => $r->decision_note,
            'created_at'    => optional($r->created_at)->toIso8601String(),
        ];
    }
}
