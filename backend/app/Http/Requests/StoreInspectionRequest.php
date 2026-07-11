<?php

namespace App\Http\Requests;

use App\Models\InspectionRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates persistence of one inspection record AFTER its photo has been uploaded
 * straight to S3 (or for a photo-less damage finding). A record must carry either
 * an `s3_key` (a photo) or `damage_flagged=true` (a finding) — enforced in the
 * controller. When damage is flagged, a `damage_type` is required.
 */
class StoreInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is already gated by permission:inspections.manage
    }

    public function rules(): array
    {
        return [
            'contract_id'    => 'nullable|exists:contracts,id',
            'vehicle_id'     => 'nullable|exists:vehicles,id',
            'phase'          => ['required', Rule::in(InspectionRecord::PHASES)],
            'body_part'      => 'required|string|max:40',

            // image metadata (the bytes were PUT to S3 via the presigned URL)
            's3_key'         => 'nullable|string|max:1024',
            's3_disk'        => 'nullable|string|max:30',
            'mime_type'      => 'nullable|string|max:80',
            'file_size'      => 'nullable|integer|min:0',
            'width'          => 'nullable|integer|min:0',
            'height'         => 'nullable|integer|min:0',
            'captured_at'    => 'nullable|date',

            // rich manual damage flag
            'damage_flagged' => 'nullable|boolean',
            'damage_type'    => ['nullable', Rule::in(InspectionRecord::DAMAGE_TYPES)],
            'severity'       => ['nullable', Rule::in(InspectionRecord::SEVERITIES)],
            'note'           => 'nullable|string|max:1000',
        ];
    }
}
