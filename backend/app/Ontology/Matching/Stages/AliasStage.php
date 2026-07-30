<?php

namespace App\Ontology\Matching\Stages;

use App\Models\KeywordTerm;
use App\Ontology\Matching\MatchCandidate;
use App\Ontology\Matching\MatchQuery;
use App\Ontology\Matching\MatchStage;
use App\Ontology\Matching\TermIndex;

/**
 * Stage 2 — the query exactly matches one of the fault's other surface forms.
 *
 * This is where the generated vocabulary earns its keep: a synonym, workshop phrase, abbreviation,
 * spelling variant, deliberate misspelling or Arabic term, matched exactly. "brake squeal",
 * "aircon", "radiater", "صرير الفرامل" all land here.
 *
 * A human-authored alias scores higher than a generated one and contributes the `human` confidence
 * dimension rather than `lexical` — if the workshop wrote the word themselves, that is not a
 * lexical coincidence, it is a person telling us this is what they call it.
 */
class AliasStage implements MatchStage
{
    public function key(): string
    {
        return 'alias';
    }

    public function run(MatchQuery $query, TermIndex $index, array &$candidates): void
    {
        foreach ($index->exact($query->normalized) as $term) {
            if ($term['kind'] === KeywordTerm::KIND_CANONICAL || ! $query->allowsCategory($term['category'])) {
                continue;
            }

            $meta = KeywordTerm::kindMeta($term['kind']);
            $isHuman = $term['source'] === KeywordTerm::SOURCE_HUMAN;

            // A misspelling is a weaker signal than a true synonym even when matched exactly —
            // "break noise" could plausibly be a different word entirely.
            $score = $term['kind'] === KeywordTerm::KIND_MISSPELLING ? 88.0 : 96.0;

            $candidates[$term['keyword_id']] ??= new MatchCandidate($term['keyword_id']);
            $candidates[$term['keyword_id']]->addEvidence(
                $this->key(),
                $score,
                'matched the '.mb_strtolower($meta['label'])." \"{$term['term']}\""
                    .($isHuman ? ' (written by your staff)' : ''),
                $isHuman ? 'human' : 'lexical',
                ['term' => $term['term'], 'term_id' => $term['id'], 'kind' => $term['kind'], 'source' => $term['source']],
            );
        }
    }
}
