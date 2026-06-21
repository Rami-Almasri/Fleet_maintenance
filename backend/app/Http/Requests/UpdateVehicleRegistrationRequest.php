<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleRegistrationRequest extends FormRequest
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
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'chasis_no' => 'nullable|string|max:50',
            'expiry_date' => 'nullable|date',
            'status' => 'nullable|string|max:50',
            'fines_count' => 'nullable|integer|min:0',
            'fines_amount' => 'nullable|numeric|min:0',
            'mortgaged_by' => 'nullable|string|max:255',
            'insurance_company_id' => 'nullable|exists:vendors,id',
            'insurance_expiry' => 'nullable|date',
        ];
    }
}
