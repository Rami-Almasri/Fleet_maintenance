<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One frozen question in the Concept Bridge benchmark: a real ticket segment plus what the matcher
 * predicted for it at capture time.
 *
 * Immutable by intent. Re-running the matcher does NOT rewrite these rows — a new matcher means a
 * new `sample_set`, so a benchmark score always refers to a fixed set of questions and predictions.
 *
 * See the create_concept_bridge_review_tables migration.
 */
class ConceptBridgeSample extends Model
{
    protected $fillable = [
        'sample_set', 'row_no', 'stratum', 'human_review',
        'maintenance_id', 'v1_signature', 'source_field', 'segment_text',
        'pred1_concept', 'pred1_score', 'pred1_primary_stage', 'pred1_all_stages', 'pred1_matched_term',
        'pred2_concept', 'pred2_score', 'pred3_concept', 'pred3_score',
    ];

    protected $casts = [
        'row_no'       => 'integer',
        'human_review' => 'boolean',
        'pred1_score' => 'integer',
        'pred2_score' => 'integer',
        'pred3_score' => 'integer',
    ];

    public function labels(): HasMany
    {
        return $this->hasMany(ConceptBridgeLabel::class);
    }

    /** The predictions as a list, in rank order, skipping empty slots. */
    public function predictions(): array
    {
        $out = [];
        foreach ([1, 2, 3] as $i) {
            $c = $this->{"pred{$i}_concept"};
            if ($c) {
                $out[] = ['rank' => $i, 'concept' => $c, 'score' => (int) $this->{"pred{$i}_score"}];
            }
        }
        return $out;
    }
}
