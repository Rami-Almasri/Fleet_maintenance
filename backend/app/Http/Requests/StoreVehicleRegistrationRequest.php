<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Records created from the website are always origin = web (protected from the sheet sync).
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['origin' => 'web']);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'vehicle_id' => 'required|exists:vehicles,id',
            'chasis_no' => 'nullable|string|max:50',
            'expiry_date' => 'nullable|date',
            'status' => 'nullable|string|max:50',
            'fines_count' => 'nullable|integer|min:0',
            'fines_amount' => 'nullable|numeric|min:0',
            'mortgaged_by' => 'nullable|string|max:255',
            'insurance_company_id' => 'nullable|exists:vendors,id',
            'insurance_expiry' => 'nullable|date',
            'origin' => 'in:web,sheet',
        ];
    }
}
