<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates edits to a service reminder. Any edit here flips the row to source='manual'
 * (handled in the controller) so the auto-seeder leaves it alone. Route gated by
 * inspections.manage.
 */
class UpdateServiceReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_type'          => 'sometimes|required|string|max:40',
            'name'                  => 'sometimes|nullable|string|max:80',
            'interval_km'           => 'sometimes|nullable|integer|min:1',
            'interval_days'         => 'sometimes|nullable|integer|min:1',
            'last_service_odometer' => 'sometimes|nullable|integer|min:0',
            'last_service_at'       => 'sometimes|nullable|date',
            'is_muted'              => 'sometimes|boolean',
            'active'                => 'sometimes|boolean',
            'notes'                 => 'sometimes|nullable|string|max:2000',
        ];
    }
}
