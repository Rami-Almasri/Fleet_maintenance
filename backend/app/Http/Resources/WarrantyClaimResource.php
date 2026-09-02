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
            // What we never had to spend, kept apart from what came back. Added only in the report,
            // never in the database. @see \App\Models\WarrantyClaim::totalBenefit()
            'avoided_amount'   => $this->avoided_amount,
            'total_benefit'    => $this->resource->totalBenefit(),
            'currency'         => $this->currency,
            'remedy'           => $this->remedy,

            // ── The case half: where WE are, as opposed to what THEY said ──────────────────────
            'stage'  => $this->stage,
            'origin' => $this->origin,
            'is_open'            => $this->resource->isOpen(),
            'blocks_procurement' => $this->resource->blocksProcurement(),
            'provider_overdue'   => $this->resource->providerOverdue(),

            'subject'   => $this->subject,
            'diagnosis' => $this->diagnosis,

            // The anchors — what makes "which of the four things wrong with this car?" answerable.
            'maintenance_task_id'  => $this->maintenance_task_id,
            'vehicle_component_id' => $this->vehicle_component_id,
            'component_catalog_id' => $this->component_catalog_id,

            // The coverage decision. A JUDGEMENT, so it is always served with its author and date —
            // an unattributed "covered" is worthless in the argument that follows.
            'coverage_verdict'     => $this->coverage_verdict,
            'coverage_reason_code' => $this->coverage_reason_code,
            'decided_by_name'      => $this->decided_by_name,
            'decided_at'           => $this->decided_at?->toIso8601String(),

            // The provider leg.
            'authorization_ref'          => $this->authorization_ref,
            'authorization_requested_at' => $this->authorization_requested_at?->toIso8601String(),
            'authorized_at'              => $this->authorized_at?->toIso8601String(),
            'sent_to_provider_at'        => $this->sent_to_provider_at?->toIso8601String(),
            'provider_response_due_on'   => $this->provider_response_due_on?->toDateString(),
            'claim_reference'            => $this->claim_reference,
            'submitted_at'               => $this->submitted_at?->toIso8601String(),
            'closed_at'                  => $this->closed_at?->toIso8601String(),
            'closed_by_name'             => $this->closed_by_name,

            'vehicle'  => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle->id, 'plate_no' => $this->vehicle->plate_no,
                'make' => $this->vehicle->make, 'model' => $this->vehicle->model,
                'odometer' => $this->vehicle->odometer,
            ]),
            'warranty' => $this->whenLoaded('warranty', fn () => [
                'id' => $this->warranty->id, 'subject' => $this->warranty->subject,
                'kind' => $this->warranty->kind,
                'provider_kind' => $this->warranty->provider_kind,
                'provider_name' => $this->warranty->provider_name,
                'reference_no'  => $this->warranty->reference_no,
                'contact_name'  => $this->warranty->contact_name,
                'contact_phone' => $this->warranty->contact_phone,
                'contact_email' => $this->warranty->contact_email,
                'expires_on'    => $this->warranty->expires_on?->toDateString(),
                'expires_at_km' => $this->warranty->expires_at_km,
            ]),
            'catalog'  => $this->whenLoaded('catalog', fn () => [
                'id' => $this->catalog->id, 'name' => $this->catalog->name, 'name_ar' => $this->catalog->name_ar,
            ]),
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($d) => [
                'id' => $d->id, 'kind' => $d->kind, 'original_name' => $d->original_name,
                'note' => $d->note, 'url' => $d->viewUrl(),
                'uploaded_by_name' => $d->uploaded_by_name,
                'uploaded_at' => $d->uploaded_at?->toIso8601String(),
            ])->values()),

            'created_by_name' => $this->created_by_name,
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
