<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Going back to the counterparty: "this one is yours".
 *
 * Note what is NOT accepted here: `was_in_window` and `window_evidence`. Those are computed by
 * WarrantyService::fileClaim() from the date and odometer of the failure and frozen. Letting a
 * caller assert "it was in warranty" would make the one field that has to be trustworthy the one
 * field anybody could type.
 */
class StoreWarrantyClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware owns permission
    }

    public function rules(): array
    {
        return [
            // The date the failure happened, not the date the form was opened — the window is
            // judged against this, so a claim filed late is still judged fairly.
            'claimed_on'     => ['nullable', 'date'],
            'claim_odometer' => ['nullable', 'integer', 'min:0', 'max:9999999'],

            'failure_description' => ['required', 'string', 'max:2000'],
            'maintenance_id'      => ['nullable', 'exists:maintenances,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'failure_description.required' => 'Describe what failed. The supplier will ask, and a claim with no description cannot be argued months later.',
        ];
    }
}
