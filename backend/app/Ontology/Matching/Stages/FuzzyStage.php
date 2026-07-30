<?php

namespace App\Ontology\Matching\Stages;

use App\Ontology\Matching\MatchCandidate;
use App\Ontology\Matching\MatchQuery;
use App\Ontology\Matching\MatchStage;
use App\Ontology\Matching\TermIndex;

/**
 * Stage 5 — the typo net.
 *
 * Catches the misspellings nobody thought to enumerate: "radiater" → "radiator", "coolent" →
 * "coolant". Enrichment generates the COMMON misspellings as real terms (which the alias stage
 * catches exactly); this stage covers the long tail it couldn't predict.
 *
 * RESTRICTED TO SINGLE-WORD TERMS, ON PURPOSE. Edit distance across a whole phrase produces
 * confident nonsense — two unrelated multi-word terms can be 85% similar as strings while meaning
 * completely different things. Restricting to one word, requiring a minimum length, and rejecting
 * on a length gap before the expensive comparison keeps this stage precise and cheap.
 */
class FuzzyStage implements MatchStage
{
    private const MIN_LENGTH = 4;
    private const MAX_LENGTH_GAP = 2;

    public function key(): string
    {
        return 'fuzzy';
    }

    public function run(MatchQuery $query, TermIndex $index, array &$candidates): void
    {
        if ($query->tokens === []) {
            return;
        }

        $threshold = (float) config('keyword_ai.matching.fuzzy_threshold', 0.84);

        foreach ($index->singleTokenTerms() as $term) {
            if (! $query->allowsCategory($term['category'])) {
                continue;
            }

            // Already resolved exactly by an earlier stage — don't also report it as a typo.
            if ($term['normalized'] === $query->normalized) {
                continue;
            }

            $needle = $term['tokens'][0];
            if (mb_strlen($needle) < self::MIN_LENGTH) {
                continue;
            }

            foreach ($query->tokens as $token) {
                if (abs(mb_strlen($token) - mb_strlen($needle)) > self::MAX_LENGTH_GAP) {
                    continue;   // cheap reject before similar_text
                }

                similar_text($needle, $token, $percent);
                $ratio = $percent / 100;

                if ($ratio < $threshold || $ratio >= 1.0) {
                    continue;
                }

                $candidates[$term['keyword_id']] ??= new MatchCandidate($term['keyword_id']);
                $candidates[$term['keyword_id']]->addEvidence(
                    $this->key(),
                    55 + 15 * $ratio,
                    "\"{$token}\" looks like a misspelling of \"{$term['term']}\"",
                    'lexical',
                    ['term' => $term['term'], 'term_id' => $term['id'], 'typed' => $token, 'similarity' => round($ratio, 2)],
                );

                break;  // one fuzzy hit per term is enough evidence
            }
        }
    }
}
