<?php

namespace App\Ontology\Matching;

use App\Models\KeywordTerm;
use App\Support\TextNormalizer;
use Illuminate\Support\Facades\Cache;

/**
 * The in-memory term corpus every deterministic matching stage reads.
 *
 * Loaded once per request and cached briefly. Each stage needs the same rows in a different shape —
 * exact match wants a hash keyed on the normalised form, token matching wants pre-tokenised terms,
 * fuzzy matching wants only the single-token ones — so building all three views here means a
 * six-stage pipeline still costs ONE query and one tokenisation pass.
 */
class TermIndex
{
    private const CACHE_KEY = 'ontology.term_index.v2';
    private const CACHE_TTL = 300;

    /** @var array<int,array<string,mixed>> */
    private array $terms = [];

    /** @var array<string,array<int,int>>  normalised form => offsets into $terms */
    private array $byNormalized = [];

    /** @var array<int,int>  offsets of single-token terms, for the fuzzy stage */
    private array $singleToken = [];

    public function __construct()
    {
        $this->load();
    }

    private function load(): void
    {
        $this->terms = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return KeywordTerm::query()
                ->active()
                ->join('finding_keywords', 'finding_keywords.id', '=', 'keyword_terms.finding_keyword_id')
                ->where('finding_keywords.is_active', true)
                ->get([
                    'keyword_terms.id',
                    'keyword_terms.finding_keyword_id',
                    'keyword_terms.term',
                    'keyword_terms.normalized',
                    'keyword_terms.lang',
                    'keyword_terms.kind',
                    'keyword_terms.source',
                    'keyword_terms.confidence',
                    'keyword_terms.search_rank',
                    'finding_keywords.category_key',
                ])
                ->map(fn ($t) => [
                    'id'          => (int) $t->id,
                    'keyword_id'  => (int) $t->finding_keyword_id,
                    'term'        => $t->term,
                    'normalized'  => $t->normalized,
                    'tokens'      => TextNormalizer::tokens($t->normalized),
                    'lang'        => $t->lang,
                    'kind'        => $t->kind,
                    'source'      => $t->source,
                    'confidence'  => (int) $t->confidence,
                    'rank'        => (int) $t->search_rank,
                    'category'    => $t->category_key,
                ])
                ->values()
                ->all();
        });

        foreach ($this->terms as $offset => $term) {
            $this->byNormalized[$term['normalized']][] = $offset;

            if (count($term['tokens']) === 1) {
                $this->singleToken[] = $offset;
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->terms;
    }

    /**
     * Terms whose normalised form is exactly this string — O(1), which is what lets the exact and
     * alias stages run first and cheaply on every query.
     *
     * @return array<int,array<string,mixed>>
     */
    public function exact(string $normalized): array
    {
        return array_map(fn (int $o) => $this->terms[$o], $this->byNormalized[$normalized] ?? []);
    }

    /** @return array<int,array<string,mixed>> */
    public function singleTokenTerms(): array
    {
        return array_map(fn (int $o) => $this->terms[$o], $this->singleToken);
    }

    public function count(): int
    {
        return count($this->terms);
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
