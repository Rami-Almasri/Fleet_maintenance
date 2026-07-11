<?php

namespace App\Http\Requests;

use App\Models\InspectionSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates creation of a recurring inspection schedule. Route is already gated by
 * permission:inspections.manage.
 */
class StoreInspectionScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id'              => 'nullable|exists:vehicles,id',
            'name'                    => 'required|string|max:120',
            'description'             => 'nullable|string|max:2000',
            'pillar'                  => ['nullable', Rule::in(InspectionSchedule::PILLARS)],
            'interval_type'           => ['required', Rule::in(InspectionSchedule::INTERVAL_TYPES)],
            'interval_days'           => 'nullable|integer|min:1',
            'interval_km'             => 'nullable|integer|min:1',
            'last_inspected_at'       => 'nullable|date',
            'last_inspected_odometer' => 'nullable|integer|min:0',
            'assigned_to'             => 'nullable|exists:users,id',
            'active'                  => 'nullable|boolean',
            'notes'                   => 'nullable|string|max:2000',
        ];
    }
}
