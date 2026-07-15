<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PartInvestigationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                   => $this->id,
            'type'                 => $this->type,
            'priority'             => $this->priority,
            'status'               => $this->status,
            'vehicle_id'           => $this->vehicle_id,
            'vehicle'              => $this->whenLoaded('vehicle', fn () => [
                'id'    => $this->vehicle->id,
                'plate' => $this->vehicle->plate_no,
                'make'  => $this->vehicle->make,
                'model' => $this->vehicle->model,
            ]),
            'part_purchase_id'     => $this->part_purchase_id,
            'purchase'             => new PartPurchaseResource($this->whenLoaded('purchase')),
            'previous_purchase'    => new PartPurchaseResource($this->whenLoaded('previousPurchase')),
            'maintenance_task_id'  => $this->maintenance_task_id,
            'previous_task_id'     => $this->previous_task_id,
            'reason_code'          => $this->reason_code,
            'reason_note'          => $this->reason_note,
            'context'              => $this->context,
            'opened_by'            => $this->opened_by_name,
            'opened_at'            => optional($this->opened_at)->toIso8601String(),
            'reason_by'            => $this->reason_by_name,
            'reason_at'            => optional($this->reason_at)->toIso8601String(),
            'resolved_by'          => $this->resolved_by_name,
            'resolved_at'          => optional($this->resolved_at)->toIso8601String(),
            'resolution'           => $this->resolution,
            'created_at'           => optional($this->created_at)->toIso8601String(),
        ];
    }
}
