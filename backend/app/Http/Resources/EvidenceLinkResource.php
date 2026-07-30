<?php

namespace App\Http\Resources;

use App\Models\EvidenceLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API shape for one citation.
 *
 * `is_grounded` is the field the UI leads with: false means this "evidence" is the model's own
 * training with nothing retrieved behind it. Surfacing that plainly is the entire point — a
 * knowledge base that renders a model prior and a Bosch service manual with the same styling has
 * quietly taught its users to trust both equally.
 */
class EvidenceLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'retrieval_method' => $this->retrieval_method,
            'is_grounded'      => $this->retrieval_method !== EvidenceLink::METHOD_MODEL_PRIOR,
            'document_title'   => $this->document_title,
            'section'          => $this->section,
            'url'              => $this->url,
            'snippet'          => $this->snippet ? mb_substr($this->snippet, 0, 400) : null,
            'confidence'       => (int) $this->confidence,
            'grounding_score'  => $this->groundingScore(),
            'retrieved_at'     => $this->retrieved_at,
        ];
    }
}
