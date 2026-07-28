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
            'created_at'          => optional($this->created_at)->toIso8601String(),
        ];
    }
}
