<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates creation of a service reminder (manual). Auto reminders are written by the
 * service:sync-reminders command, not this endpoint. Route gated by inspections.manage.
 */
class StoreServiceReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id'            => 'required|exists:vehicles,id',
            'service_type'          => 'required|string|max:40',
            'name'                  => 'nullable|string|max:80',
            'interval_km'           => 'nullable|integer|min:1',
            'interval_days'         => 'nullable|integer|min:1',
            'last_service_odometer' => 'nullable|integer|min:0',
            'last_service_at'       => 'nullable|date',
            'is_muted'              => 'nullable|boolean',
            'active'                => 'nullable|boolean',
            'notes'                 => 'nullable|string|max:2000',
        ];
    }
}
