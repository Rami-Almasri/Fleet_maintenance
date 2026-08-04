<?php

namespace App\Http\Requests;

use App\Models\WarrantyClaim;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The counterparty's answer to a claim.
 *
 * The "reason required on rejection" rule is enforced in WarrantyService::resolveClaim() rather
 * than here, so it holds for every caller. A refusal without the reason they gave is the difference
 * between "they refused" and "they refuse corrosion claims after six months" — only the second can
 * be used when the next supply contract is written.
 */
class ResolveWarrantyClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware owns permission
    }

    public function rules(): array
    {
        return [
            'outcome'        => ['required', Rule::in(WarrantyClaim::OUTCOMES)],
            'outcome_reason' => ['nullable', 'string', 'max:2000'],
            'resolved_on'    => ['nullable', 'date'],

            'recovered_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency'         => ['nullable', 'string', 'size:3'],
            'remedy'           => ['nullable', Rule::in(WarrantyClaim::REMEDIES)],
        ];
    }
}
