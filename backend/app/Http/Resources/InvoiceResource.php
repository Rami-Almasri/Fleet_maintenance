<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'invoice_no'           => $this->invoice_no,
            'invoice_ref'          => $this->invoice_ref,
            // What the UI shows as the invoice number: the website ref for manual rows,
            // else the OfficeManager integer.
            'number'               => $this->invoice_ref ?: ($this->invoice_no ? '#'.$this->invoice_no : null),
            'origin'               => $this->origin,
            // Only website-created invoices can be edited/deleted here; OM rows are read-only.
            'editable'             => $this->origin === 'manual',
            'date'                 => optional($this->invoice_date)->toDateString(),
            'invoice_date'         => optional($this->invoice_date)->toDateString(),
            'contract_id'          => $this->contract_id,
            'customer_id'          => $this->customer_id,
            'car_no'               => $this->car_no,
            'total_value'          => $this->total_value,
            'vat_value'            => $this->vat_value,
            'discount'             => $this->discount,
            'total_after_discount' => $this->total_after_discount,
            'total_after_vat'      => $this->total_after_vat,
            // Track A — live settlement state synced from OfficeManager (rental invoices).
            'payment_status'       => $this->payment_status,
            'is_pending'           => $this->is_pending,
            'balance_value'        => $this->balance_value,
            'paid_amount'          => $this->paid_amount,
            'status_no'            => $this->status_no,
            'period_from'          => optional($this->period_from)->toDateString(),
            'period_to'            => optional($this->period_to)->toDateString(),
            'rent_days'            => $this->rent_days,
            'net_rate'             => $this->net_rate,
            'notes'                => $this->notes,
            // Service Log: garage + the parts/services done.
            'vehicle_id'           => $this->vehicle_id,
            'vendor_id'            => $this->vendor_id,
            'garage'               => $this->whenLoaded('vendor', fn () => $this->vendor?->name),
            'items'                => $this->whenLoaded('items', fn () => $this->items->map(fn ($it) => [
                'id'           => $it->id,
                'description'  => $it->description,
                'category_key' => $it->category_key,
            ])->values()),
            'contract'             => $this->whenLoaded('contract', fn () => $this->contract ? [
                'id'            => $this->contract->id,
                'contract_no'   => $this->contract->contract_no,
                'contract_type' => $this->contract->contract_type,
            ] : null),
        ];
    }
}
