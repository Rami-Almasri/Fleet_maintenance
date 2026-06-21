<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_no' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('customers', 'customer_no')->ignore($this->route('customer')),
            ],
            'name_en' => 'nullable|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'nationality' => 'nullable|string|max:100',
            'date_of_birth' => 'nullable|date',
            'mobile1' => 'nullable|string|max:30',
            'mobile2' => 'nullable|string|max:30',
            'whatsapp' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'sex' => 'nullable|string|max:10',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:255',
            'po_box' => 'nullable|string|max:50',
            'passport_no' => 'nullable|string|max:50',
            'passport_expiry' => 'nullable|date',
            'license_no' => 'nullable|string|max:50',
            'license_expiry' => 'nullable|date',
            'id_no' => 'nullable|string|max:50',
            'id_expiry' => 'nullable|date',
            'residency_no' => 'nullable|string|max:50',
            'residency_expiry' => 'nullable|date',
            'traffic_file_no' => 'nullable|string|max:50',
            'vat_number' => 'nullable|string|max:50',
            'makani' => 'nullable|string|max:50',
            'lat' => 'nullable|numeric',
            'lon' => 'nullable|numeric',
            'debit' => 'nullable|numeric',
            'credit' => 'nullable|numeric',
            'balance' => 'nullable|numeric',
            'deposit' => 'nullable|numeric',
        ];
    }
}
