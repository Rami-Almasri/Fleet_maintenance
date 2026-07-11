<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InspectionRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'contract_id'    => $this->contract_id,
            'vehicle_id'     => $this->vehicle_id,
            'inspector_id'   => $this->inspector_id,
            'inspector_name' => $this->inspector_name,
            'phase'          => $this->phase,
            'body_part'      => $this->body_part,

            // image metadata (bytes live in S3; `url` is a short-lived signed link)
            'mime_type'      => $this->mime_type,
            'file_size'      => $this->file_size,
            'width'          => $this->width,
            'height'         => $this->height,
            'url'            => $this->temporaryUrl(),

            // rich manual damage flag
            'damage_flagged' => (bool) $this->damage_flagged,
            'damage_type'    => $this->damage_type,
            'severity'       => $this->severity,
            'note'           => $this->note,

            'captured_at'    => optional($this->captured_at)->toIso8601String(),
            'created_at'     => optional($this->created_at)->toIso8601String(),
        ];
    }
}
