<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVendorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:garage,fuel_station,parts_supplier,insurance,service_center,other',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'rating' => 'nullable|numeric|min:0|max:5',
            'active' => 'nullable|boolean',
        ];
    }
}
