<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PartPurchaseResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'part_request_id'     => $this->part_request_id,
            'vehicle_id'          => $this->vehicle_id,
            'maintenance_id'      => $this->maintenance_id,
            'maintenance_task_id' => $this->maintenance_task_id,
            'part_name'           => $this->part_name,
            // WHICH part was bought, as opposed to what it was called. See PartIdentityService.
            'component_catalog_id' => $this->component_catalog_id,
            'part_number'         => $this->part_number,
            'category_key'        => $this->category_key,
            'part_class'          => $this->part_class,
            'purchase_source'     => $this->purchase_source,
            'source_vendor_id'    => $this->source_vendor_id,
            'source_vendor'       => $this->whenLoaded('sourceVendor', fn () => $this->sourceVendor?->name),
            'source_name'         => $this->source_name,
            'repair_location'     => $this->repair_location,
            'purchase_price'      => $this->purchase_price,
            'currency'            => $this->currency,
            'quantity'            => $this->quantity,
            'purchased_by'        => $this->purchased_by_name,
            'purchased_at'        => optional($this->purchased_at)->toIso8601String(),
            'expected_delivery_date' => optional($this->expected_delivery_date)->toDateString(),
            'delivered_at'        => optional($this->delivered_at)->toIso8601String(),
            'installed_by'        => $this->installed_by_name,
            'installed_at'        => optional($this->installed_at)->toIso8601String(),
            'installed_odometer'  => $this->installed_odometer,
            'result'              => $this->result,
            'maintenance_line_item_id' => $this->maintenance_line_item_id,
            'requires_review'     => (bool) $this->requires_review,
            'duplicate_of_purchase_id' => $this->duplicate_of_purchase_id,
            'notes'               => $this->notes,

            // ── The paper behind the price (supplier buys only — a garage part is on the garage's bill) ──
            'part_invoice_id'     => $this->part_invoice_id,
            'invoice_no'          => $this->whenLoaded('invoice', fn () => $this->invoice?->invoice_no),
            'invoice_photo_url'   => $this->whenLoaded('invoice', fn () => $this->invoice?->photoUrl()),
            // A supplier buy whose price has no document yet — what the UI nags about.
            'invoice_missing'     => $this->needsPartInvoice() && ! $this->part_invoice_id,

            // ── Returns: the buy is never deleted, so the ledger reads gross → refunded → net ──
            'gross_cost'          => $this->grossCost(),
            'refunded_total'      => $this->refundedTotal(),
            'net_cost'            => $this->netCost(),
            // Whether anything is still returnable — every un-rejected return counted against the quantity.
            'fully_returned'      => round((float) $this->returns()
                ->where('status', '!=', \App\Models\PartReturn::STATUS_REJECTED)
                ->sum('quantity'), 2) >= (float) ($this->quantity ?: 1),

            'created_at'          => optional($this->created_at)->toIso8601String(),
        ];
    }
}
