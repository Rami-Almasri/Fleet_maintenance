<?php

namespace App\Http\Requests;

use App\Models\InspectionRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a request for a presigned S3 upload URL. The client asks for a slot to
 * upload one compressed photo of a given body zone; the server hands back a signed
 * PUT URL + object key. No file touches the app server.
 */
class PresignInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is already gated by permission:inspections.manage
    }

    public function rules(): array
    {
        return [
            'contract_id'  => 'nullable|exists:contracts,id',
            'vehicle_id'   => 'nullable|exists:vehicles,id',
            'phase'        => ['required', Rule::in(InspectionRecord::PHASES)],
            'body_part'    => 'required|string|max:40',
            'content_type' => 'required|string|max:80',   // e.g. image/jpeg
            'extension'    => 'nullable|string|max:8',
        ];
    }
}
