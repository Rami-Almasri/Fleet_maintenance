<?php

namespace App\Http\Resources;

use App\Models\MaintenanceRequiredPart;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One inspector-recorded technical requirement. `is_pending` is exposed so the coordinator's UI never has
 * to re-derive which lines are still actionable, and `requests` carries the traceability link forward once
 * the line has been converted into procurement.
 */
class MaintenanceRequiredPartResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'maintenance_id'      => $this->maintenance_id,
            'vehicle_id'          => $this->vehicle_id,
            // Null until the report's findings are promoted to first-class faults; `finding` is the
            // originating symptom text, which is what the UI shows either way.
            'maintenance_task_id' => $this->maintenance_task_id,
            'finding'             => $this->task?->symptom ?: $this->finding_text,

            'part_name'           => $this->part_name,
            'quantity'            => $this->quantity,
            'priority'            => $this->priority,
            'notes'               => $this->notes,

            'status'              => $this->status,
            'is_pending'          => $this->status === MaintenanceRequiredPart::STATUS_PENDING,
            'dismissal_reason'    => $this->dismissal_reason,

            'recorded_by'         => $this->recorded_by_name,
            'recorded_at'         => optional($this->recorded_at)->toIso8601String(),
            'actioned_by'         => $this->actioned_by_name,
            'actioned_at'         => optional($this->actioned_at)->toIso8601String(),

            // The procurement requests this requirement became — the "what happened to it" trail.
            'requests'            => $this->whenLoaded('requests', fn () => $this->requests->map(fn ($r) => [
                'id'     => $r->id,
                'status' => $r->status,
                'part_name' => $r->part_name,
            ])->values()),

            'created_at'          => optional($this->created_at)->toIso8601String(),
        ];
    }
}
