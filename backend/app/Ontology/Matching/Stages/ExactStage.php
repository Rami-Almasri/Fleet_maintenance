<?php

namespace App\Ontology\Matching\Stages;

use App\Models\KeywordTerm;
use App\Ontology\Matching\MatchCandidate;
use App\Ontology\Matching\MatchQuery;
use App\Ontology\Matching\MatchStage;
use App\Ontology\Matching\TermIndex;

/**
 * Stage 1 — the query IS the fault's canonical name.
 *
 * The strongest and cheapest signal there is: an O(1) hash lookup that ends the question. Kept
 * separate from the alias stage so the explanation can distinguish "you typed the fault's name"
 * from "you typed one of its synonyms" — both are exact, but only the first is unambiguous, and a
 * technician reading the reason deserves to know which happened.
 */
class ExactStage implements MatchStage
{
    public function key(): string
    {
        return 'exact';
    }

    public function run(MatchQuery $query, TermIndex $index, array &$candidates): void
    {
        foreach ($index->exact($query->normalized) as $term) {
            if ($term['kind'] !== KeywordTerm::KIND_CANONICAL || ! $query->allowsCategory($term['category'])) {
                continue;
            }

            $candidates[$term['keyword_id']] ??= new MatchCandidate($term['keyword_id']);
            $candidates[$term['keyword_id']]->addEvidence(
                $this->key(),
                100,
                "the query is exactly this fault's name — \"{$term['term']}\"",
                'lexical',
                ['term' => $term['term'], 'term_id' => $term['id'], 'kind' => $term['kind']],
            );
        }
    }
}
