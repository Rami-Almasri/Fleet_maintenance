<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends FormRequest
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
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('vehicles', 'code')->ignore($this->route('vehicle')),
            ],
            'vin' => [
                'sometimes',
                'required',
                'string',
                'max:32',
                Rule::unique('vehicles', 'vin')->ignore($this->route('vehicle')),
            ],
            'plate_no' => 'sometimes|nullable|string|max:20',
            'make' => 'sometimes|nullable|string|max:100',
            'model' => 'sometimes|nullable|string|max:100',
            'year' => 'sometimes|nullable|integer|min:1950|max:' . (date('Y') + 1),
            'color' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:100',
            'status' => 'sometimes|required|in:office_use,ready,rented,out_of_order,under_maintenance,suspended,disposed,sold,returned',
            'for_sale' => 'sometimes|boolean',
            'odometer' => 'nullable|integer|min:0',
            'engine_hours' => 'nullable|numeric|min:0',
            'source' => 'nullable|in:new,used,auction,accident',
            'purchase_price' => 'nullable|numeric|min:0',
            'purchase_date' => 'nullable|date',
            'warranty_end_date' => 'nullable|date',
            'warranty_end_km' => 'nullable|integer|min:0',
            'replacement_due_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ];
    }
}
