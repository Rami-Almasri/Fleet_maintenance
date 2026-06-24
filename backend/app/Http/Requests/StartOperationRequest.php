<?php

namespace App\Http\Requests;

use App\Models\PolicyOverrideAudit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartOperationRequest extends FormRequest
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
            // which movement is starting
            'category' => 'required|in:rent,maintenance,test_drive,transfer,sale_prep',

            // override a soft block (e.g. send to maintenance despite a reservation clash)
            'force' => 'nullable|boolean',

            // manager-only "Rental-First" override: open a maintenance contract on a rented car.
            // A reason code is mandatory; 'other' must come with notes. Permission is checked in
            // the controller (operations.override) and the action is written to the audit trail.
            'override_reason' => ['nullable', Rule::in(array_keys(PolicyOverrideAudit::REASON_CODES))],
            'override_notes'  => ['nullable', 'string', 'max:1000', 'required_if:override_reason,other'],

            // who / where
            'customer_id' => 'nullable|exists:customers,id',
            'driver_out' => 'nullable|string|max:100',
            'opened_by' => 'nullable|string|max:100',

            // handover
            'contract_no' => 'nullable|string|max:50',
            'out_date' => 'nullable|date',
            'out_time' => 'nullable|string|max:20',
            'out_milage' => 'nullable|integer|min:0',
            'out_fuel' => 'nullable|string|max:20',

            // pricing (mainly for rent)
            'day_price' => 'nullable|numeric',
            'week_price' => 'nullable|numeric',
            'month_price' => 'nullable|numeric',
            'hour_price' => 'nullable|numeric',

            'source' => 'nullable|string|max:100',
            'remarks' => 'nullable|string',

            // maintenance details (when category = maintenance)
            'vendor_id' => 'nullable|exists:vendors,id',
            'expected_return_date' => 'nullable|date',
            'maintenance_tags' => 'nullable|array',
            'maintenance_tags.*' => 'string|max:50',
            'responsible' => 'nullable|string|max:150',
            'maintenance_notes' => 'nullable|string',
        ];
    }
}
