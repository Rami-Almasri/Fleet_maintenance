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
            // WHY this buy exists when there is no fault behind it: a car was recorded as needing a
            // spare key. Carried on the board row so a request with no ticket is never a mystery.
            'spare_key_requirement_id' => $this->spare_key_requirement_id,
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

            // THE LAST TIME WE BOUGHT THIS PART FOR THIS CAR — the one fact that turns "approve this
            // request" into a decision rather than a rubber stamp: we fitted the same part here before,
            // on that date, for that money, from that supplier. Purchases belonging to THIS request are
            // excluded, so the answer is always about a PREVIOUS occasion.
            //
            // "The same part" is PartIdentityService's answer and nobody else's (catalog row → known
            // namings → SKU), so a change of wording between two buys cannot hide the repeat.
            //
            // Opt-in (?with_last_purchase=1): it costs one query per row, which is fine for the handful
            // of rows on one ticket and not fine for a 50-row page of the fleet-wide Parts board.
            'last_purchase'       => $this->when(
                $request->boolean('with_last_purchase') && $this->vehicle_id,
                fn () => $this->lastPurchaseOfSamePart(),
            ),

            'created_at'          => optional($this->created_at)->toIso8601String(),
        ];
    }

    /**
     * The most recent purchase of the same part on the same vehicle, from any earlier request.
     * Null when this car has never had it before — which is itself worth saying out loud.
     *
     * @return array<string,mixed>|null
     */
    private function lastPurchaseOfSamePart(): ?array
    {
        $identity = app(\App\Services\PartIdentityService::class)
            ->identityFor($this->component_catalog_id, $this->part_name, $this->part_number);

        $q = \App\Models\PartPurchase::query()
            ->where('vehicle_id', $this->vehicle_id)
            ->where('part_request_id', '!=', $this->id)
            ->with('sourceVendor:id,name');

        $prev = app(\App\Services\PartIdentityService::class)
            ->apply($q, $identity)
            ->orderByDesc('purchased_at')
            ->orderByDesc('id')
            ->first();

        if (! $prev) {
            return null;
        }

        return [
            'id'             => $prev->id,
            'part_name'      => $prev->part_name,
            'purchased_at'   => optional($prev->purchased_at)->toIso8601String(),
            'purchased_by'   => $prev->purchased_by_name,
            // What it actually cost us that time, net of anything returned.
            'net_cost'       => $prev->netCost(),
            'currency'       => $prev->currency,
            'quantity'       => $prev->quantity,
            // WHERE it came from: the supplier register name, else whatever was written down, else
            // just "garage"/"supplier" — never a blank that reads as "nowhere".
            'source'         => $prev->sourceVendor?->name ?: $prev->source_name,
            'source_kind'    => $prev->purchase_source,
            'maintenance_id' => $prev->maintenance_id,
            'result'         => $prev->result,
        ];
    }
}
