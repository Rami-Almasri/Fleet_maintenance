<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated by permission:billing.manage
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'contract_id' => ['required', 'exists:contracts,id'],
            'invoice_id'  => ['nullable', 'exists:invoices,id'],
            'amount'      => ['required', 'numeric', 'min:0.01'],
            'paid_on'     => ['nullable', 'date'],
            'method'      => ['nullable', 'string', 'max:50'],
            'reference'   => ['nullable', 'string', 'max:150'],
            'notes'       => ['nullable', 'string', 'max:1000'],
        ];
    }
}
