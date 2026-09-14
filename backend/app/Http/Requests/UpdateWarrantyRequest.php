<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing a warranty — correcting what was agreed, not re-pointing it.
 *
 * `kind` and the four anchors are absent by design and stripped again in WarrantyService::update().
 * Moving a warranty onto a different part or a different fault is not an edit: it rewrites the
 * history of whatever it used to cover, and leaves the old subject silently uncovered.
 */
class UpdateWarrantyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware owns permission
    }

    public function rules(): array
    {
        return [
            'subject'              => ['sometimes', 'string', 'max:300'],
            'component_catalog_id' => ['nullable', 'exists:component_catalog,id'],

            'provider_vendor_id' => ['nullable', 'exists:vendors,id'],
            'provider_name'      => ['nullable', 'string', 'max:255'],
            'reference_no'       => ['nullable', 'string', 'max:120'],

            // Editable, unlike the anchors: a dealer's service number changing is a correction to the
            // record, not a re-pointing of the promise.
            'provider_kind'  => ['nullable', \Illuminate\Validation\Rule::in(\App\Models\Warranty::PROVIDER_KINDS)],
            'contact_name'   => ['nullable', 'string', 'max:160'],
            'contact_phone'  => ['nullable', 'string', 'max:60'],
            'contact_email'  => ['nullable', 'email', 'max:160'],


            'starts_on'       => ['sometimes', 'date'],
            'start_odometer'  => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'duration_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'duration_km'     => ['nullable', 'integer', 'min:0', 'max:2000000'],

            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
