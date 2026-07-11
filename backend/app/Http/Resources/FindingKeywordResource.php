<?php

namespace App\Http\Resources;

use App\Models\FindingKeyword;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API shape for a findings keyword. Ships the risk grade already resolved into its emoji / label /
 * tone (from FindingKeyword::RISK_META) so the frontend colours the risk chip without re-deriving it.
 */
class FindingKeywordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meta = FindingKeyword::riskMeta($this->risk);

        return [
            'id'                => $this->id,
            'category_key'      => $this->category_key,
            'category_label'    => $this->category_label,
            'category_label_ar' => $this->category_label_ar,
            'keyword'           => $this->keyword,
            'keyword_ar'        => $this->keyword_ar,
            'risk'              => $this->risk,
            'risk_label'     => $meta['label'],
            'risk_tone'      => $meta['tone'],
            'risk_emoji'     => $meta['emoji'],
            'description'    => $this->description,
            'is_active'      => (bool) $this->is_active,
            'sort_order'     => (int) $this->sort_order,
            'updated_at'     => $this->updated_at,
        ];
    }
}
