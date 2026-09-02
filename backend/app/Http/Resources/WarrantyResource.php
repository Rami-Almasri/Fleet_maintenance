<?php

namespace App\Http\Resources;

use App\Models\Warranty;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One warranty, always shipped WITH its computed verdict.
 *
 * The verdict is the whole point and it is never a column: whether a distance-bounded warranty is
 * still live depends on the car's odometer today. The resource therefore judges at read time,
 * against the vehicle's current reading when the relation is loaded.
 *
 * `expires_on` / `expires_at_km` are exposed as what they are — the two ends of the window — while
 * `verdict.state` is the answer. A client that renders `expires_on` alone will overstate the cover
 * on exactly the cars that are driven hardest, which is why `verdict` is not optional.
 */
class WarrantyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Warranty $w */
        $w = $this->resource;

        $odometer = $this->whenLoaded('vehicle', fn () => $w->vehicle?->odometer, null);
        $verdict  = $w->evaluate(null, $odometer !== null ? (int) $odometer : null);

        return [
            'id'   => $w->id,
            'kind' => $w->kind,

            'vehicle_id' => $w->vehicle_id,
            'vehicle'    => $this->whenLoaded('vehicle', fn () => [
                'id'       => $w->vehicle->id,
                'plate_no' => $w->vehicle->plate_no,
                'odometer' => $w->vehicle->odometer,
            ]),

            // What is covered.
            'subject'              => $w->subject,
            'component_catalog_id' => $w->component_catalog_id,
            'catalog'              => $this->whenLoaded('catalog', fn () => [
                'id'      => $w->catalog->id,
                'name'    => $w->catalog->name,
                'name_ar' => $w->catalog->name_ar,
            ]),

            // The anchors — which one is set depends on the kind.
            'part_purchase_id'     => $w->part_purchase_id,
            'vehicle_component_id' => $w->vehicle_component_id,
            'maintenance_task_id'  => $w->maintenance_task_id,
            'maintenance_id'       => $w->maintenance_id,

            // Who owes us, and how to reach them — a vehicle warranty is claimed by ringing a service
            // department and quoting a number, so the contact travels with the promise.
            'provider_vendor_id' => $w->provider_vendor_id,
            'provider_name'      => $w->provider_name ?: $this->whenLoaded('provider', fn () => $w->provider?->name),
            'provider_kind'      => $w->provider_kind,
            'reference_no'       => $w->reference_no,
            'contact_name'       => $w->contact_name,
            'contact_phone'      => $w->contact_phone,
            'contact_email'      => $w->contact_email,

            /**
             * WHAT IS AND IS NOT COVERED, as ids in the shared parts vocabulary.
             *
             * NULL and [] are different answers and both are shipped as-is: null means "nobody has
             * itemised this booklet", [] means "itemised, and this list is empty". `is_itemised` is
             * the flag the UI branches on, because a warranty nobody has read yields UNKNOWN for
             * every part and the page needs to say so rather than showing an empty covered list as if
             * it meant "covers nothing".
             */
            'covered_catalog_ids'  => $w->covered_catalog_ids,
            'excluded_catalog_ids' => $w->excluded_catalog_ids,
            'is_itemised'          => $w->isItemised(),
            'coverage_notes'       => $w->coverage_notes,

            // The window: the promise, then the two derived ends of it.
            'starts_on'       => $w->starts_on?->toDateString(),
            'start_odometer'  => $w->start_odometer,
            'duration_months' => $w->duration_months,
            'duration_km'     => $w->duration_km,
            'expires_on'      => $w->expires_on?->toDateString(),
            'expires_at_km'   => $w->expires_at_km,

            'status'      => $w->status,
            'void_reason' => $w->void_reason,
            'notes'       => $w->notes,

            // THE ANSWER. state = active | expired | void; ended_by = time | distance.
            // distance_unknown=true means a km-bounded warranty was judged with no odometer, so
            // "active" here means "not out of time" and nothing more — say so, never imply cover.
            'verdict' => $verdict,

            'claims'       => WarrantyClaimResource::collection($this->whenLoaded('claims')),
            'claims_count' => $this->whenCounted('claims'),

            'created_by_name' => $w->created_by_name,
            'created_at'      => $w->created_at?->toIso8601String(),
            'updated_at'      => $w->updated_at?->toIso8601String(),
        ];
    }
}
