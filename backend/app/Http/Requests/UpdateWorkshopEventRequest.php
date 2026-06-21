<?php

namespace App\Http\Requests;

use App\Models\Maintenance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkshopEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated by permission:maintenance.manage
    }

    /**
     * Same shape as store, but every field is optional so a partial edit only
     * touches what it sends (the service mirrors this — `array_key_exists` per field).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'vehicle_id'           => ['sometimes', 'required', 'exists:vehicles,id'],
            'vendor_id'            => ['nullable', 'exists:vendors,id'],
            'event_status'         => ['nullable', Rule::in(Maintenance::STAGES)],

            'issues'               => ['nullable', 'array'],
            'issues.*'             => ['string', 'max:100'],
            'service_sup'          => ['nullable', 'string', 'max:255'],

            'maintenance_type'     => ['nullable', 'string', 'max:100'],
            'damage_location'      => ['nullable', 'string', 'max:255'],
            'severity'             => ['nullable', 'string', 'max:50'],

            'out_date'             => ['nullable', 'date'],
            'expected_return_date' => ['nullable', 'date'],
            'follow_date'          => ['nullable', 'date'],

            'responsible'          => ['nullable', 'string', 'max:150'],
            'approved_by'          => ['nullable', 'string', 'max:150'],
            'liable_party'         => ['nullable', 'string', 'max:100'],
            'charge_to'            => ['nullable', 'string', 'max:100'],
            'driver'               => ['nullable', 'string', 'max:150'],
            'base_on'              => ['nullable', 'string', 'max:150'],

            'spare_part'           => ['nullable', 'string'],
            'invoice_no'           => ['nullable', 'string', 'max:100'],
            'cost'                 => ['nullable', 'numeric', 'min:0'],
            'cost_notes'           => ['nullable', 'string'],
            'maintenance_notes'    => ['nullable', 'string'],
        ];
    }
}
