<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PartRequestResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'source'              => $this->source,
            'status'              => $this->status,
            'vehicle_id'          => $this->vehicle_id,
            'vehicle'             => $this->whenLoaded('vehicle', fn () => [
                'id'      => $this->vehicle->id,
                'plate'   => $this->vehicle->plate_no,
                'make'    => $this->vehicle->make,
                'model'   => $this->vehicle->model,
            ]),
            'customer_id'         => $this->customer_id,
            'customer'            => $this->whenLoaded('customer', fn () => [
                'id'   => $this->customer->id,
                'name' => $this->customer->name_en ?: $this->customer->name_ar,
            ]),
            'maintenance_id'      => $this->maintenance_id,
            'maintenance_task_id' => $this->maintenance_task_id,
            'fault_symptom'       => $this->whenLoaded('task', fn () => $this->task?->symptom),
            'part_name'           => $this->part_name,
            // WHICH part, not just what it was called — the client passes this back to the
            // duplicate-check endpoint so the repeat-buy question survives a change of wording.
            'component_catalog_id' => $this->component_catalog_id,
            'catalog_matched_by'   => $this->catalog_matched_by,
            'part_number'         => $this->part_number,
            'category_key'        => $this->category_key,
            'part_class'          => $this->part_class,
            'repair_location'     => $this->repair_location,
            'quantity'            => $this->quantity,
            'reason'              => $this->reason,
            'estimated_price'     => $this->estimated_price,
            'currency'            => $this->currency,
            'notes'               => $this->notes,
            'requested_by'        => $this->requested_by_name,
            'requested_at'        => optional($this->requested_at)->toIso8601String(),
            'reviewed_by'         => $this->reviewed_by_name,
            'reviewed_at'         => optional($this->reviewed_at)->toIso8601String(),
            'review_notes'        => $this->review_notes,
            'approved_by'         => $this->approved_by_name,
            'approved_at'         => optional($this->approved_at)->toIso8601String(),
            'rejected_by'         => $this->rejected_by_name,
            'rejected_at'         => optional($this->rejected_at)->toIso8601String(),
            'rejection_reason'    => $this->rejection_reason,
            'purchases'           => PartPurchaseResource::collection($this->whenLoaded('purchases')),
            'created_at'          => optional($this->created_at)->toIso8601String(),
        ];
    }
}
