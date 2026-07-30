<?php

namespace App\Services;

use App\Ontology\Matching\MatchPipeline;
use App\Ontology\Matching\MatchQuery;
use App\Ontology\Matching\TermIndex;
use App\Support\VehicleScope;
use Illuminate\Support\Collection;

/**
 * Free text → ranked fault concepts. The application's entry point into ontology matching.
 *
 * As of the platform refactor this is a thin facade over [[MatchPipeline]], which runs the staged
 * pipeline: Exact → Alias → Phrase → Token → Fuzzy → Semantic. The matching LOGIC lives in the
 * stages; this class exists to keep one stable signature for the many callers across the app
 * (the controller, [[FleetEvidenceService]], the inspection picker) so that reshaping the pipeline
 * never turns into a codebase-wide edit.
 *
 * BACKWARD-COMPATIBLE SHAPE. Callers still receive `{keyword, score, confidence, matches}` with
 * `confidence` as 'strong'|'possible' and `matches` as the per-term hit list — the same contract as
 * before the refactor. The richer platform data (per-dimension confidence, stage trace, full
 * evidence) is additive: `confidence_detail`, `stages`, `evidence`. Nothing that worked before had
 * to change to keep working.
 *
 * WHY THE FACADE EARNS ITS KEEP. The staged pipeline is a better design, but it would have been a
 * bad trade if adopting it meant touching every call site. Keeping the old surface means the
 * refactor is verifiable — existing behaviour is expected to be identical — and the new capability
 * is opt-in per caller.
 */
class KeywordOntologyService
{
    public function __construct(private readonly MatchPipeline $pipeline)
    {
    }

    /**
     * Resolve free text to ranked fault concepts.
     *
     * @param  array{limit?:int,category?:string,min_score?:int,scope?:array<int,string>}  $options
     * @return Collection<int,array>
     */
    public function resolve(string $text, array $options = []): Collection
    {
        $query = new MatchQuery(
            text: $text,
            scopeChain: $options['scope'] ?? [VehicleScope::UNIVERSAL],
            category: $options['category'] ?? null,
            limit: (int) ($options['limit'] ?? config('knowledge_platform.matching.limit', 8)),
            minScore: (int) ($options['min_score'] ?? config('knowledge_platform.matching.min_score', 25)),
        );

        $strong = (int) config('knowledge_platform.matching.strong', 70);

        return $this->pipeline->run($query)->map(function (array $result) use ($strong) {
            return [
                'keyword'    => $result['keyword'],
                'score'      => $result['score'],
                'confidence' => $result['score'] >= $strong ? 'strong' : 'possible',

                // Legacy shape: the per-term hits, flattened from the stage evidence so existing
                // callers and the frontend keep working unchanged.
                'matches' => collect($result['evidence'])
                    ->filter(fn (array $e) => isset($e['meta']['term']))
                    ->take(5)
                    ->map(fn (array $e) => [
                        'term'  => $e['meta']['term'],
                        'kind'  => $e['meta']['kind'] ?? null,
                        'lang'  => $e['meta']['lang'] ?? null,
                        'how'   => $e['stage'],
                        'score' => (int) round($e['score']),
                    ])
                    ->values()
                    ->all(),

                // Platform additions — every layer's confidence, and how the match was reached.
                'confidence_detail' => $result['confidence']->explain(),
                'stages'            => $result['stages'],
                'evidence'          => $result['evidence'],
            ];
        });
    }

    /** Which matching stages are active — exposed so the pipeline is inspectable from the API. */
    public function activeStages(): array
    {
        return $this->pipeline->activeStages();
    }

    /**
     * Drop the cached term corpus. Called after any write to keywords or terms.
     *
     * Kept as a static on this class because that is where every existing caller already reaches
     * for it; it delegates to the index that actually owns the cache.
     */
    public static function flushCache(): void
    {
        TermIndex::flush();

        // The pre-refactor cache key, cleared too so a deployment mid-rollout can't serve a stale
        // corpus from the old key if anything is still reading it.
        \Illuminate\Support\Facades\Cache::forget('keyword_ontology.terms.v1');
    }
}
