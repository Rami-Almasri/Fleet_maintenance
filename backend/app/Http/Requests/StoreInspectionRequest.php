<?php

namespace App\Http\Requests;

use App\Models\InspectionRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates persistence of one inspection record. A record carries either a `photo`
 * (uploaded multipart, stored locally by the controller) or `damage_flagged=true`
 * (a photo-less finding) — enforced in the controller. When damage is flagged, a
 * `damage_type` is required.
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

            // the condition photo, uploaded directly (multipart) to the local disk
            'photo'          => 'nullable|image|max:10240', // 10 MB

            // image metadata (server fills s3_key/s3_disk from the stored file)
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
