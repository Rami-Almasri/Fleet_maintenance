<?php

namespace App\Http\Resources;

use App\Models\KeywordTerm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API shape for one surface form of a fault concept.
 *
 * Ships the kind already resolved to its label + chip tone (KeywordTerm::KIND_META) so the frontend
 * groups and colours the chips without re-deriving the vocabulary, and carries the full provenance
 * trio — source / confidence / evidence quality — because [[traceability-visibility-requirement]]
 * means a generated word must always be able to say where it came from.
 */
class KeywordTermResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meta = KeywordTerm::kindMeta($this->kind);

        return [
            'id'                 => $this->id,
            'term'               => $this->term,
            'normalized'         => $this->normalized,
            'lang'               => $this->lang,
            'kind'               => $this->kind,
            'kind_label'         => $meta['label'],
            'kind_tone'          => $meta['tone'],
            'confidence'         => (int) $this->confidence,
            'source'             => $this->source,
            'source_quality'     => $this->source_quality,
            'workshop_frequency' => $this->workshop_frequency,
            'search_rank'        => (int) $this->search_rank,
            'is_active'          => (bool) $this->is_active,
        ];
    }
}
