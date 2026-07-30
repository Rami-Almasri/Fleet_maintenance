<?php

namespace App\Ontology\Matching\Stages;

use App\Ontology\Matching\MatchCandidate;
use App\Ontology\Matching\MatchQuery;
use App\Ontology\Matching\MatchStage;
use App\Ontology\Matching\TermIndex;

/**
 * Stage 4 — the query and a term share enough meaningful words.
 *
 * Handles reordering and padding: "noise from the brakes" versus "brake noise". Deliberately
 * ASYMMETRIC — the primary measure is what share of the TERM is present in the query, because a
 * two-word term fully contained in a rambling sentence is a strong hit and dividing by the sentence
 * length would wrongly punish it.
 *
 * A small part of the score comes from the reverse direction (how much of the QUERY the term
 * accounts for) purely as a tie-break. Without it "brake noise" scores identically against "Engine
 * noise" and "Brake noise" — each shares one of two tokens — and the wrong fault can sort first on
 * nothing but row order. That correction was found by inspecting real output, not by theory.
 */
class TokenStage implements MatchStage
{
    /** Below this share of the term's words, the overlap is coincidence rather than meaning. */
    private const MIN_TERM_COVERAGE = 0.5;

    public function key(): string
    {
        return 'token';
    }

    public function run(MatchQuery $query, TermIndex $index, array &$candidates): void
    {
        if ($query->tokens === []) {
            return;
        }

        foreach ($index->all() as $term) {
            if ($term['tokens'] === [] || ! $query->allowsCategory($term['category'])) {
                continue;
            }

            $shared = count(array_intersect($term['tokens'], $query->tokens));
            if ($shared === 0) {
                continue;
            }

            $termRatio = $shared / count($term['tokens']);
            if ($termRatio < self::MIN_TERM_COVERAGE) {
                continue;
            }

            $queryRatio = $shared / count($query->tokens);
            $score = (30 + 44 * $termRatio + 10 * $queryRatio) * $this->kindWeight($term['kind']);

            // Nudge by the term's own rank so a very common workshop phrase edges out a rare
            // textbook synonym at equal overlap.
            $score += ($term['rank'] - 50) * 0.06;

            $candidates[$term['keyword_id']] ??= new MatchCandidate($term['keyword_id']);
            $candidates[$term['keyword_id']]->addEvidence(
                $this->key(),
                max(0, $score),
                sprintf('shares %d of %d words with "%s"', $shared, count($term['tokens']), $term['term']),
                'lexical',
                ['term' => $term['term'], 'term_id' => $term['id'], 'kind' => $term['kind'], 'shared' => $shared],
            );
        }
    }

    private function kindWeight(string $kind): float
    {
        return (float) (config("keyword_ai.matching.kind_weights.{$kind}") ?? 0.9);
    }
}
