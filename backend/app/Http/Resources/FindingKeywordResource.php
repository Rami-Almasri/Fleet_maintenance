<?php

namespace App\Http\Resources;

use App\Models\FindingKeyword;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API shape for a findings keyword. Ships the risk grade already resolved into its emoji / label /
 * tone (from FindingKeyword::RISK_META) so the frontend colours the risk chip without re-deriving it.
 *
 * The ontology payload (`terms` / `profile` / `runs`) is attached ONLY when the relation was
 * eager-loaded — the library listing sends a `term_count` and an `is_enriched` flag so the table
 * stays light, and the per-keyword knowledge drawer loads the full set on demand.
 */
class FindingKeywordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meta = FindingKeyword::riskMeta($this->risk);

        // The per-request singleton, so a 111-row library scans the fault catalog once, not once a row.
        $selectable = app(\App\Services\SelectableFindings::class);
        $key        = \App\Support\TextNormalizer::key($this->keyword);

        return [
            // AI knowledge base — see [[KeywordTerm]] / [[KeywordProfile]].
            'term_count'  => $this->whenCounted('terms'),
            'is_enriched' => $this->relationLoaded('profile') ? $this->profile !== null : null,
            'terms'       => KeywordTermResource::collection($this->whenLoaded('terms')),
            'profile'     => $this->whenLoaded('profile', function () {
                if (! $this->profile) {
                    return null;
                }
                // Hand the profile its parent so `severity_disagrees` can compare against the
                // admin's risk grade without lazy-loading the keyword back per row (N+1 on a list).
                $this->profile->setRelation('findingKeyword', $this->resource);

                return new KeywordProfileResource($this->profile);
            }),
            'runs'        => $this->whenLoaded('enrichmentRuns', fn () => $this->enrichmentRuns->map(fn ($r) => [
                'id'            => $r->id,
                'status'        => $r->status,
                'model'         => $r->model,
                'terms_added'   => $r->terms_added,
                'terms_updated' => $r->terms_updated,
                'input_tokens'  => $r->input_tokens,
                'output_tokens' => $r->output_tokens,
                'duration_ms'   => $r->duration_ms,
                'error'         => $r->error,
                'by'            => $r->user?->name,
                'created_at'    => $r->created_at,
            ])),

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

            // CAN ANYONE ACTUALLY TAP THIS WORD? A library row grades a fault and teaches the matcher
            // its name; the fault type is what puts it in the picker. Rows added before those two were
            // written together are graded, matchable, and offered nowhere — and the library counted
            // them without ever saying so, which is how a curator ends up certain a fault was added
            // and an inspector cannot find it. Reported per row rather than left to be discovered on
            // the picker screen ([[traceability-visibility-requirement]]).
            //
            // `withheld` is the reason it may be BOTH unselectable and right: the config declares a few
            // words understanding-only, because the garage records them during the repair rather than
            // anyone reporting them. Without this second flag they read as the same defect.
            'selectable'     => isset($selectable->keywords()[$key]),
            'withheld'       => isset($selectable->withheld()[$key]),

            'sort_order'     => (int) $this->sort_order,
            'updated_at'     => $this->updated_at,
        ];
    }
}
