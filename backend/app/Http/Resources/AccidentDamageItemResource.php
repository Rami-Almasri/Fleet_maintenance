<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One damaged area, with its link to the repair task that fixed it (or the absence of one). */
class AccidentDamageItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'accident_case_id'     => $this->accident_case_id,
            'area_label'           => $this->area_label,
            'severity'             => $this->severity,
            'description'          => $this->description,
            'requires_replacement' => $this->requires_replacement,
            'estimated_cost'       => $this->estimated_cost,
            'damage_catalog_id'    => $this->damage_catalog_id,
            'catalog_name'         => $this->whenLoaded('catalog', fn () => $this->catalog?->name),
            'vehicle_location_id'  => $this->vehicle_location_id,
            'location_name'        => $this->whenLoaded('location', fn () => $this->location?->name),
            // Null forever on items the insurer refused or the owner chose to live with. That is not
            // a gap — an unrepaired dent is a recorded fact about the car.
            'maintenance_task_id'  => $this->maintenance_task_id,
            'repaired'             => $this->maintenance_task_id !== null,
            'created_by_name'      => $this->created_by_name,
            'created_at'           => $this->created_at?->toIso8601String(),
        ];
    }
}
