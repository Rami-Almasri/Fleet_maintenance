<?php

namespace App\Ontology\Matching\Stages;

use App\Ontology\Matching\MatchCandidate;
use App\Ontology\Matching\MatchQuery;
use App\Ontology\Matching\MatchStage;
use App\Ontology\Matching\TermIndex;

/**
 * Stage 3 — a term appears intact inside a longer sentence.
 *
 * The stage that handles real technician writing: "customer says there is a brake noise when
 * stopping" contains "brake noise" verbatim. Scored by COVERAGE — how much of the sentence the term
 * accounts for — so a long specific term in a short query scores near-exact, while a two-letter
 * term buried in a paragraph scores modestly.
 *
 * Also catches compound-word splits ("over heating" ↔ "overheating", "tail light" ↔ "taillight"),
 * which are the single most common near-miss in both English and Arabic typing and would otherwise
 * fall all the way through to the fuzzy stage or be missed entirely.
 */
class PhraseStage implements MatchStage
{
    public function key(): string
    {
        return 'phrase';
    }

    public function run(MatchQuery $query, TermIndex $index, array &$candidates): void
    {
        if ($query->normalized === '') {
            return;
        }

        $haystack = ' '.$query->normalized.' ';

        foreach ($index->all() as $term) {
            if ($term['normalized'] === '' || ! $query->allowsCategory($term['category'])) {
                continue;
            }

            // Skip what the exact/alias stages already resolved — no point double-reporting.
            if ($term['normalized'] === $query->normalized) {
                continue;
            }

            $matched = null;
            $score = 0.0;

            // Word-boundary containment — "ac" matches "ac not cooling" but not "back door".
            if (str_contains($haystack, ' '.$term['normalized'].' ')) {
                $coverage = mb_strlen($term['normalized']) / max(1, mb_strlen($query->normalized));
                $score = 60 + 35 * min(1.0, $coverage);
                $matched = 'phrase';
            } elseif (mb_strlen($term['normalized']) >= 6
                && str_contains($query->joined, str_replace(' ', '', $term['normalized']))) {
                // Same words, different spacing. Length-guarded so short terms don't match inside
                // unrelated long words once spaces are stripped.
                $score = 72;
                $matched = 'spacing';
            }

            if ($matched === null) {
                continue;
            }

            $candidates[$term['keyword_id']] ??= new MatchCandidate($term['keyword_id']);
            $candidates[$term['keyword_id']]->addEvidence(
                $this->key(),
                $score * $this->kindWeight($term['kind']),
                $matched === 'spacing'
                    ? "contains \"{$term['term']}\" written with different spacing"
                    : "contains the phrase \"{$term['term']}\"",
                'lexical',
                ['term' => $term['term'], 'term_id' => $term['id'], 'kind' => $term['kind'], 'how' => $matched],
            );
        }
    }

    private function kindWeight(string $kind): float
    {
        return (float) (config("keyword_ai.matching.kind_weights.{$kind}") ?? 0.9);
    }
}
