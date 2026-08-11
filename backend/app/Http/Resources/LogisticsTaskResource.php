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
            // What kind of job this is. `customer_collection` moves are shown under their own
            // heading in the driver's queue — the car is still the customer's until he takes it.
            'purpose'            => $this->purpose,
            'customer_collection' => $this->isCustomerCollection(),
            'plate'            => $this->vehicle_plate ?: $this->vehicle?->plate_no,
            'car'              => $this->vehicle_label ?: trim(($this->vehicle?->make ?? '') . ' ' . ($this->vehicle?->model ?? '')) ?: null,
            'destination'      => $this->destination,
            'round_trip'       => (bool) $this->round_trip,
            // The car's last recorded mileage — the anchor the Pre/Post-trip odometer capture is checked
            // against (Odometer Continuity). Lets the driver's screen flag a big jump / backward reading
            // live and demand a note on a >10 km gap, exactly like every other capture point in the fleet.
            'previous_odometer' => $this->vehicle?->odometer !== null ? (int) $this->vehicle->odometer : null,
            // THE FRESHEST NUMBER WE HOLD, with its provenance — which is not always the one above.
            // For a car out on rental, `vehicles.odometer` was last refreshed at handover, so it is
            // a whole rental stale; the customer readings the oil follow-up has been collecting by
            // phone are newer. The driver is shown the newest, labelled with where it came from and
            // when, because "20,000 km" means two different things depending on that.
            'last_odometer'     => $this->lastOdometer(),
            // The oil follow-up this collection was raised for — what the car owes once it lands,
            // and whether it has been done. Present only on a collection; null everywhere else.
            'oil_followup'      => $this->oilFollowUp(),

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

    /**
     * The oil follow-up behind this collection — the job the trip exists to make possible.
     *
     * The driver's card walks Claim → Received → Arrived → Oil changed, and the last step needs to
     * know which contract to write against and whether the change has already been recorded (by
     * him, by Abu Maroof, or by Leen from the board — any of the three is normal). Resolved from the
     * CAR rather than from the task, because a collection with no test attached carries no
     * maintenance link at all.
     *
     * @return array{contract_id:int, decision_id:int, oil_changed:bool, odometer:?int, service_location:string, service_interval_km:?int}|null
     */
    private function oilFollowUp(): ?array
    {
        if (! $this->isCustomerCollection() || ! $this->vehicle_id) {
            return null;
        }

        $decision = \App\Models\ContractOilDecision::where('vehicle_id', $this->vehicle_id)
            ->where('decision', \App\Models\ContractOilDecision::DECISION_RECALL)
            ->orderByDesc('id')
            ->first();

        if (! $decision) {
            return null;
        }

        return [
            'contract_id'         => (int) $decision->contract_id,
            'decision_id'         => (int) $decision->id,
            'oil_changed'         => $decision->isOilChanged(),
            'odometer'            => $decision->oil_changed_odometer,
            'service_location'    => $decision->serviceLocation(),
            'service_interval_km' => $this->vehicle?->service_interval_km !== null
                ? (int) $this->vehicle->service_interval_km
                : null,
        ];
    }

    /**
     * The last odometer we actually hold for this car, and WHERE it came from.
     *
     * Two candidates, and they disagree by design:
     *   • `vehicles.odometer` — refreshed at handover, so during a rental it is a rental stale;
     *   • the newest ContractMileageReading — the number a customer read off the dash to Leen, or
     *     a colleague captured mid-rental. On a collection this is usually days old, not months.
     *
     * The newest wins, and it is published WITH its source and date rather than as a bare figure:
     * "20,535 km" from a phone call last Tuesday and "20,535 km" from the branch six months ago are
     * not the same claim, and the driver comparing his dashboard to it needs to know which he has.
     *
     * Only computed for a customer collection — the one leg where the driver is reading a dash that
     * nobody in the company has seen for weeks. Everywhere else `previous_odometer` is the anchor
     * the continuity check already uses.
     *
     * @return array{km:int, source:string, on:?string, by:?string}|null
     */
    private function lastOdometer(): ?array
    {
        if (! $this->isCustomerCollection()) {
            return null;
        }

        $stored = $this->vehicle?->odometer !== null ? (int) $this->vehicle->odometer : null;

        $reading = \App\Models\ContractMileageReading::where('vehicle_id', $this->vehicle_id)
            ->orderByDesc('reported_on')
            ->orderByDesc('id')
            ->first(['odometer', 'reported_on', 'source', 'reported_by']);

        if ($reading && (! $stored || (int) $reading->odometer >= $stored)) {
            return [
                'km'     => (int) $reading->odometer,
                'source' => $reading->source === \App\Models\ContractMileageReading::SOURCE_STAFF ? 'staff' : 'customer',
                'on'     => optional($reading->reported_on)->toDateString(),
                'by'     => $reading->reported_by,
            ];
        }

        return $stored === null ? null : [
            'km'     => $stored,
            'source' => 'handover',
            'on'     => null,
            'by'     => null,
        ];
    }
}
