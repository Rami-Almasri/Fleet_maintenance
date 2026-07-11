<?php

namespace App\Http\Requests;

use App\Models\InspectionSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates edits to an inspection schedule. All fields optional (`sometimes`) so a
 * partial update is fine. Route is gated by permission:inspections.manage.
 */
class UpdateInspectionScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id'              => 'sometimes|nullable|exists:vehicles,id',
            'name'                    => 'sometimes|required|string|max:120',
            'description'             => 'sometimes|nullable|string|max:2000',
            'pillar'                  => ['sometimes', 'nullable', Rule::in(InspectionSchedule::PILLARS)],
            'interval_type'           => ['sometimes', 'required', Rule::in(InspectionSchedule::INTERVAL_TYPES)],
            'interval_days'           => 'sometimes|nullable|integer|min:1',
            'interval_km'             => 'sometimes|nullable|integer|min:1',
            'last_inspected_at'       => 'sometimes|nullable|date',
            'last_inspected_odometer' => 'sometimes|nullable|integer|min:0',
            'assigned_to'             => 'sometimes|nullable|exists:users,id',
            'active'                  => 'sometimes|boolean',
            'notes'                   => 'sometimes|nullable|string|max:2000',
        ];
    }
}
