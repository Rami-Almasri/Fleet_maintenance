<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording which garage the car on a maintenance visit is at (In the Garage board).
 *
 * `vendor_id` is nullable on purpose — sending null CLEARS the entry. Someone who realises they
 * named the wrong garage must be able to take it back rather than leave a wrong fact standing.
 */
class RecordGarageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission is enforced at the route via middleware
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'vendor_id' => 'present|nullable|integer|exists:vendors,id',
        ];
    }
}
