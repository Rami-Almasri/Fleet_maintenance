<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CloseOperationRequest extends FormRequest
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
            // return / closing
            'in_date' => 'nullable|date',
            'in_time' => 'nullable|string|max:20',
            'in_milage' => 'nullable|integer|min:0',
            'in_fuel' => 'nullable|string|max:20',
            'closed_by' => 'nullable|string|max:100',
            'driver_in' => 'nullable|string|max:100',
            'km' => 'nullable|integer|min:0',

            // final figures
            'contract_debit' => 'nullable|numeric',
            'contract_credit' => 'nullable|numeric',
            'contract_balance' => 'nullable|numeric',
            'remarks' => 'nullable|string',
        ];
    }
}
