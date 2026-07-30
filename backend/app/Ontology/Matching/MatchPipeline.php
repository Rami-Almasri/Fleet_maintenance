<?php

namespace App\Ontology\Matching;

use App\Models\FindingKeyword;
use App\Ontology\Matching\Stages\AliasStage;
use App\Ontology\Matching\Stages\ExactStage;
use App\Ontology\Matching\Stages\FuzzyStage;
use App\Ontology\Matching\Stages\PhraseStage;
use App\Ontology\Matching\Stages\SemanticStage;
use App\Ontology\Matching\Stages\TokenStage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The deterministic matching pipeline: free text in, ranked and explained fault concepts out.
 *
 *      Exact → Alias → Phrase → Token → Fuzzy → Semantic
 *
 * Each stage contributes evidence to a shared candidate set; none can suppress another's finding.
 * A candidate's SCORE is the strongest single piece of evidence, while its CONFIDENCE is the
 * weighted blend across every dimension that supported it — so "one perfect match" and "four
 * agreeing signals" are distinguishable, which a single number can never be.
 *
 * WHY DETERMINISTIC STAGES STAY PRIMARY. They are auditable, instant, offline-capable, and
 * explainable to a technician in one sentence. Semantic retrieval runs last and is capped below
 * the exact/alias bands, so it adds recall without ever being able to override a literal match.
 * That ordering is a permanent design stance, not a stepping stone to replacing it.
 *
 * A stage that throws is logged and skipped — matching degrades rather than failing, because a
 * search box that returns nothing is worse than one that returns fewer results.
 */
class MatchPipeline
{
    /** @var array<int,MatchStage> */
    private array $stages = [];

    /**
     * Re-entrancy depth.
     *
     * The semantic stage consults fleet knowledge, and fleet knowledge resolves its own text
     * through this very pipeline — a genuine, intended cycle in the data flow rather than a design
     * mistake. Left unguarded it recurses until the process dies (which is exactly how it was
     * found). Skipping the semantic stage on nested runs breaks it at the only safe point: semantic
     * is additive-only, so a nested match that omits it still returns a correct, complete result —
     * it just doesn't recursively expand recall a second time.
     */
    private int $depth = 0;

    public function __construct()
    {
        $available = [
            'exact'    => ExactStage::class,
            'alias'    => AliasStage::class,
            'phrase'   => PhraseStage::class,
            'token'    => TokenStage::class,
            'fuzzy'    => FuzzyStage::class,
            'semantic' => SemanticStage::class,
        ];

        // Order comes from config so a deployment can disable a stage (drop 'fuzzy' if typo
        // matching causes trouble) without touching code.
        foreach (config('knowledge_platform.matching.stages', array_keys($available)) as $key) {
            if (! isset($available[$key])) {
                continue;
            }

            try {
                $this->stages[] = app($available[$key]);
            } catch (Throwable $e) {
                Log::warning("Match stage '{$key}' could not be constructed", ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Run the pipeline.
     *
     * @return Collection<int,array{keyword:FindingKeyword,score:int,confidence:array,stages:array,evidence:array}>
     */
    public function run(MatchQuery $query): Collection
    {
        if ($query->isEmpty()) {
            return collect();
        }

        $index = app(TermIndex::class);

        /** @var array<int,MatchCandidate> $candidates */
        $candidates = [];

        $this->depth++;

        try {
            foreach ($this->stages as $stage) {
                // See $depth: semantic recall consults sources that resolve text through this
                // pipeline, so it runs on the outermost call only.
                if ($stage->key() === 'semantic' && $this->depth > 1) {
                    continue;
                }

                try {
                    $stage->run($query, $index, $candidates);
                } catch (Throwable $e) {
                    Log::warning("Match stage '{$stage->key()}' failed", [
                        'query' => $query->text,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            $this->depth--;
        }

        if ($candidates === []) {
            return collect();
        }

        $this->enforceSemanticSubordination($candidates);

        // The stages could only speak to whether the WORDS matched. Credit each candidate with what
        // the knowledge layers know about it — citations, measured history, human curation — so the
        // final confidence reflects every layer rather than lexical agreement alone.
        app(\App\Ontology\Confidence\ConfidenceEnricher::class)->enrich($candidates);

        $keywords = FindingKeyword::query()
            ->with('profile')
            ->whereIn('id', array_keys($candidates))
            ->get()
            ->keyBy('id');

        return collect($candidates)
            ->map(function (MatchCandidate $candidate) use ($keywords) {
                $keyword = $keywords->get($candidate->keywordId);
                if (! $keyword) {
                    return null;    // concept deleted mid-request
                }

                return [
                    'keyword'    => $keyword,
                    'score'      => $candidate->score(),
                    'confidence' => $candidate->confidence(),
                    'stages'     => $candidate->stages(),
                    'evidence'   => $candidate->evidence(),
                ];
            })
            ->filter()
            ->filter(fn (array $r) => $r['score'] >= $query->minScore)
            // Rank by score first, then by confidence — two candidates with the same best evidence
            // are separated by how many independent kinds of evidence back them up.
            ->sortByDesc(fn (array $r) => $r['score'] * 1000 + $r['confidence']->score())
            ->take($query->limit)
            ->values();
    }

    /**
     * Enforce "semantic adds recall, never authority" as a hard rule rather than a hoped-for
     * consequence of score tuning.
     *
     * A candidate found ONLY by semantic recall is clamped strictly below the weakest deterministic
     * match in the same result set. Capping the semantic stage's own score was not enough: a
     * capped-but-high semantic score can still tie or beat a modest token match, which is how
     * "radiater leak" briefly surfaced an unrelated fault level with the right one.
     *
     * Clamping relatively — against this query's own results rather than a fixed number — is what
     * makes it correct for every query shape, from a one-word search to a full sentence.
     *
     * @param  array<int,MatchCandidate>  $candidates
     */
    private function enforceSemanticSubordination(array &$candidates): void
    {
        $deterministic = [];
        foreach ($candidates as $candidate) {
            if (! $candidate->isSemanticOnly()) {
                $deterministic[] = $candidate->score();
            }
        }

        if ($deterministic === []) {
            return;     // nothing deterministic to be subordinate to — semantic stands alone
        }

        $floor = min($deterministic);

        foreach ($candidates as $candidate) {
            if ($candidate->isSemanticOnly()) {
                $candidate->clampTo($floor - 1);
            }
        }
    }

    /** Which stages are active — surfaced in the API so the pipeline is never a black box. */
    public function activeStages(): array
    {
        return array_map(fn (MatchStage $s) => $s->key(), $this->stages);
    }
}
