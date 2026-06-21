<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleRequest extends FormRequest
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
            'code' => 'nullable|string|max:50|unique:vehicles,code',
            'vin' => 'required|string|max:32|unique:vehicles,vin',
            'plate_no' => 'nullable|string|max:20',
            'make' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'year' => 'nullable|integer|min:1950|max:' . (date('Y') + 1),
            'color' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:100',
            'status' => 'nullable|in:office_use,ready,rented,out_of_order,under_maintenance,suspended,disposed,sold,returned',
            'for_sale' => 'nullable|boolean',
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
