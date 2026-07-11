<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\InspectionSchedule
 */
class InspectionScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->statusInfo(); // overdue | due_soon | ok | no_data — drives UI badges/dots

        return [
            'id'                      => $this->id,
            'vehicle_id'              => $this->vehicle_id,
            'vehicle'                 => $this->whenLoaded('vehicle', fn () => [
                'id'       => $this->vehicle->id,
                'label'    => $this->vehicle->plate_no ?: $this->vehicle->code,
                'make'     => $this->vehicle->make,
                'model'    => $this->vehicle->model,
                'odometer' => $this->vehicle->odometer,
            ]),
            'name'                    => $this->name,
            'description'             => $this->description,
            'pillar'                  => $this->pillar,

            'interval_type'           => $this->interval_type,
            'interval_days'           => $this->interval_days,
            'interval_km'             => $this->interval_km,

            'last_inspected_at'       => optional($this->last_inspected_at)->toIso8601String(),
            'last_inspected_odometer' => $this->last_inspected_odometer,
            'next_due_at'             => optional($this->next_due_at)->toIso8601String(),
            'next_due_odometer'       => $this->next_due_odometer,

            'assigned_to'             => $this->assigned_to,
            'assignee_name'           => $this->whenLoaded('assignee', fn () => $this->assignee?->name),

            'active'                  => (bool) $this->active,
            'notes'                   => $this->notes,

            // Live status for badges/dots
            'status'                  => $status['status'],
            'status_label'            => $status['label'],
            'days_remaining'          => $status['days_remaining'],
            'km_remaining'            => $status['km_remaining'],

            'created_at'              => optional($this->created_at)->toIso8601String(),
            'updated_at'              => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
