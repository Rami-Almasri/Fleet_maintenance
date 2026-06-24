<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'payment_ref' => $this->payment_ref,
            'origin'      => $this->origin,
            'editable'    => $this->origin === 'manual',
            'contract_id' => $this->contract_id,
            'invoice_id'  => $this->invoice_id,
            'customer_id' => $this->customer_id,
            'amount'      => $this->amount,
            'paid_on'     => optional($this->paid_on)->toDateString(),
            'method'      => $this->method,
            'reference'   => $this->reference,
            'notes'       => $this->notes,
            'recorded_by' => $this->recorded_by,
            'created_at'  => optional($this->created_at)->toDateTimeString(),

            'contract'    => $this->whenLoaded('contract', fn () => $this->contract ? [
                'id'            => $this->contract->id,
                'contract_no'   => $this->contract->contract_no,
                'contract_type' => $this->contract->contract_type,
            ] : null),
            'customer'    => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id'          => $this->customer->id,
                'name_en'     => $this->customer->name_en,
                'customer_no' => $this->customer->customer_no,
            ] : null),
            'invoice'     => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id'     => $this->invoice->id,
                'number' => $this->invoice->invoice_ref ?: ($this->invoice->invoice_no ? '#'.$this->invoice->invoice_no : null),
            ] : null),
        ];
    }
}
