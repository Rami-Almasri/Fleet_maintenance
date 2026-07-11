<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
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
        // The parent contract is fixed once an invoice exists, so it isn't editable here.
        return [
            'invoice_date'   => ['nullable', 'date'],
            // Financial total is now OPTIONAL — the upload is a Technical Service Log first.
            'total_value'    => ['nullable', 'numeric', 'min:0'],
            'vat_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount'       => ['nullable', 'numeric', 'min:0'],
            'period_from'    => ['nullable', 'date'],
            'period_to'      => ['nullable', 'date', 'after_or_equal:period_from'],
            'rent_days'      => ['nullable', 'integer'],
            'net_rate'       => ['nullable', 'numeric', 'min:0'],
            'notes'          => ['nullable', 'string', 'max:1000'],
            // Service Log: the garage that did the work + the list of parts/services performed.
            'vendor_id'             => ['nullable', 'integer', 'exists:vendors,id'],
            'items'                 => ['nullable', 'array'],
            'items.*.description'   => ['required_with:items', 'string', 'max:255'],
            'items.*.category_key'  => ['nullable', 'string', 'max:40'],
        ];
    }
}
