<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ServiceReminder
 */
class ServiceReminderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->statusInfo(); // overdue | due_soon | ok | no_data — drives UI badges/dots

        return [
            'id'                    => $this->id,
            'vehicle_id'            => $this->vehicle_id,
            'vehicle'               => $this->whenLoaded('vehicle', fn () => [
                'id'       => $this->vehicle->id,
                'label'    => $this->vehicle->plate_no ?: $this->vehicle->code,
                'make'     => $this->vehicle->make,
                'model'    => $this->vehicle->model,
                'odometer' => $this->vehicle->odometer,
            ]),
            'service_type'          => $this->service_type,
            'name'                  => $this->displayName(),

            'interval_km'           => $this->interval_km,
            'interval_days'         => $this->interval_days,
            'last_service_odometer' => $this->last_service_odometer,
            'last_service_at'       => optional($this->last_service_at)->toDateString(),
            'next_due_odometer'     => $this->next_due_odometer,
            'next_due_at'           => optional($this->next_due_at)->toDateString(),

            'source'                => $this->source,       // auto | manual
            'is_muted'              => (bool) $this->is_muted,
            'active'                => (bool) $this->active,
            'notes'                 => $this->notes,

            // Live status for badges/dots
            'status'                => $status['status'],
            'status_label'          => $status['label'],
            'km_remaining'          => $status['km_remaining'],
            'days_remaining'        => $status['days_remaining'],

            // Active-communication workflow: has an alert gone out to the team, and when/who.
            'notified'              => $this->isNotified(),
            'last_notified_at'      => optional($this->last_notified_at)->toIso8601String(),
            'notified_count'        => (int) $this->notified_count,
            'notified_by_name'      => $this->whenLoaded('notifier', fn () => $this->notifier?->name),

            'created_at'            => optional($this->created_at)->toIso8601String(),
            'updated_at'            => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
