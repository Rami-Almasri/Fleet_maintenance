<?php

namespace App\Http\Resources;

use App\Models\LogisticsTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UI-ready shape of a Logistics Dispatch task. Reads the self-contained snapshots, falling back to the
 * live vehicle when it's eager-loaded, so a card renders without extra lookups. Carries the claim
 * lifecycle the board needs: who decided it, who's on it, what phase it's in, what the driver can do
 * next, and (on a returned car) the GPS proof it's back at base.
 */
class LogisticsTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'vehicle_id'       => $this->vehicle_id,
            'maintenance_id'   => $this->maintenance_id,
            'plate'            => $this->vehicle_plate ?: $this->vehicle?->plate_no,
            'car'              => $this->vehicle_label ?: trim(($this->vehicle?->make ?? '') . ' ' . ($this->vehicle?->model ?? '')) ?: null,
            'destination'      => $this->destination,
            'round_trip'       => (bool) $this->round_trip,
            // The car's last recorded mileage — the anchor the Pre/Post-trip odometer capture is checked
            // against (Odometer Continuity). Lets the driver's screen flag a big jump / backward reading
            // live and demand a note on a >10 km gap, exactly like every other capture point in the fleet.
            'previous_odometer' => $this->vehicle?->odometer !== null ? (int) $this->vehicle->odometer : null,

            // People — "Decided by" (the coordinator) and "Assigned to" (the driver who claimed it).
            'decided_by'       => $this->assigned_by_name,
            'assigned_by_name' => $this->assigned_by_name,
            'assigned_to_id'   => $this->assigned_to_id,
            'assigned_to_name' => $this->assigned_to_name,

            // Lifecycle — raw status + a spelled-out phase label + what the driver can do from here.
            'status'           => $this->status,
            'status_label'     => $this->phaseLabel(),
            'claimable'        => $this->isClaimable(),
            'is_open'          => $this->isActive(),
            'next_actions'     => $this->nextActions(),

            'notes'            => $this->notes,
            'dispatched_at'    => optional($this->dispatched_at)->toIso8601String(),
            'claimed_at'       => optional($this->claimed_at)->toIso8601String(),
            'status_changed_at' => optional($this->status_changed_at)->toIso8601String(),
            'completed_at'     => optional($this->completed_at)->toIso8601String(),
            'completed_by_name' => $this->completed_by_name,

            // GPS proof-of-return — present only once the car is marked Returned / Arrived.
            'returned_at'      => optional($this->returned_at)->toIso8601String(),
            'returned_location' => ($this->returned_lat !== null && $this->returned_lng !== null) ? [
                'lat'      => (float) $this->returned_lat,
                'lng'      => (float) $this->returned_lng,
                'accuracy' => $this->returned_accuracy !== null ? (float) $this->returned_accuracy : null,
            ] : null,

            // "Where is the car?" — last one-click reply + when it / the last ping happened.
            'last_status'      => $this->last_status,
            'last_status_at'   => optional($this->last_status_at)->toIso8601String(),
            'last_pinged_at'   => optional($this->last_pinged_at)->toIso8601String(),
            'awaiting_reply'   => (bool) ($this->last_pinged_at && (! $this->last_status_at || $this->last_pinged_at->gt($this->last_status_at))),
            'status_presets'   => LogisticsTask::STATUS_PRESETS,

            // Auto-audit trail (when eager-loaded) — every status change, who + when, GPS on return.
            'events'           => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'id'          => $e->id,
                'event'       => $e->event,
                'from_status' => $e->from_status,
                'to_status'   => $e->to_status,
                'actor_name'  => $e->actor_name,
                'note'        => $e->note,
                'location'    => ($e->lat !== null && $e->lng !== null)
                    ? ['lat' => (float) $e->lat, 'lng' => (float) $e->lng, 'accuracy' => $e->accuracy !== null ? (float) $e->accuracy : null]
                    : null,
                'occurred_at' => optional($e->occurred_at)->toIso8601String(),
            ])->values()),
        ];
    }
}
