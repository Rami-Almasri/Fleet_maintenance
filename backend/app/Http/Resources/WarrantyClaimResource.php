<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One claim against a warranty.
 *
 * `was_in_window` and `window_evidence` are served exactly as they were frozen at claim time — they
 * are never recomputed here. A claim filed at 11 of 12 months must still read "11 of 12 months" two
 * years later, when the same warranty is long expired and the car has done another 60,000 km.
 */
class WarrantyClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'warranty_id' => $this->warranty_id,
            'vehicle_id'  => $this->vehicle_id,

            'claimed_on'          => $this->claimed_on?->toDateString(),
            'claim_odometer'      => $this->claim_odometer,
            'failure_description' => $this->failure_description,
            'maintenance_id'      => $this->maintenance_id,

            // Frozen at claim time. Not a live computation.
            'was_in_window'   => $this->was_in_window,
            'window_evidence' => $this->window_evidence,

            'outcome'        => $this->outcome,
            'outcome_reason' => $this->outcome_reason,
            'resolved_on'    => $this->resolved_on?->toDateString(),

            'recovered_amount' => $this->recovered_amount,
            'currency'         => $this->currency,
            'remedy'           => $this->remedy,

            'created_by_name' => $this->created_by_name,
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
