<?php

namespace App\Ontology\Retrieval;

use App\Ontology\Confidence\ConfidenceVector;
use App\Ontology\Contracts\KnowledgeRetriever;
use App\Ontology\Contracts\RerankerProvider;
use App\Ontology\DTO\RetrievedPassage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fans a query out across every registered knowledge source and merges the results.
 *
 * This is the only place that knows a source list exists. Enrichment, matching and explanation all
 * ask the manager for passages and receive one merged, ranked, source-agnostic list — so adding
 * ALLDATA, a new PDF library or a second fleet database is a config line plus one class.
 *
 * THREE THINGS IT DOES THAT A NAIVE MERGE WOULD NOT
 *
 * 1. PER-SOURCE CAPS. One chatty retriever must not crowd out the others. A corpus with 400
 *    matching chunks and a fleet source with 3 precise statistics should both be represented,
 *    because they contribute DIFFERENT confidence dimensions — losing the fleet passages would
 *    silently drop a whole dimension out of the score.
 *
 * 2. ISOLATION. A retriever that throws is logged and skipped, never propagated. Knowledge sources
 *    are external and flaky by nature; one bad source degrades the answer, it doesn't fail it.
 *
 * 3. RERANK LAST. When a [[RerankerProvider]] is configured, the merged set is re-scored by true
 *    relevance before truncation — which is the only stage that reads query and passage together.
 */
class RetrievalManager
{
    /** @var array<int,KnowledgeRetriever> */
    private array $retrievers = [];

    public function __construct(private readonly RerankerProvider $reranker)
    {
        $this->bootRetrievers();
    }

    /** Instantiate the enabled retrievers from config, in declaration order. */
    private function bootRetrievers(): void
    {
        foreach (config('knowledge_platform.retrievers', []) as $key => $spec) {
            if (! ($spec['enabled'] ?? true) || ! class_exists($spec['class'] ?? '')) {
                continue;
            }

            try {
                $this->retrievers[$key] = app($spec['class']);
            } catch (Throwable $e) {
                Log::warning("Knowledge retriever '{$key}' could not be constructed", ['error' => $e->getMessage()]);
            }
        }
    }

    /** Register a retriever at runtime — used by tests and by packages adding a source. */
    public function register(string $key, KnowledgeRetriever $retriever): self
    {
        $this->retrievers[$key] = $retriever;

        return $this;
    }

    /** @return array<string,KnowledgeRetriever> */
    public function retrievers(): array
    {
        return $this->retrievers;
    }

    /** Which sources could actually answer right now — surfaced in the UI, not just logged. */
    public function availability(): array
    {
        return collect($this->retrievers)
            ->map(fn (KnowledgeRetriever $r) => [
                'key'       => $r->key(),
                'label'     => $r->label(),
                'dimension' => $r->confidenceDimension(),
                'available' => $r->isAvailable(),
                'weight'    => (float) (config("knowledge_platform.retrievers.{$r->key()}.weight") ?? 1.0),
            ])
            ->values()
            ->all();
    }

    /**
     * Retrieve across all sources.
     *
     * @param  array<int,string>  $scopeChain
     * @return array<int,RetrievedPassage>  merged, ranked, capped
     */
    public function retrieve(string $query, array $scopeChain = ['*'], ?int $limit = null): array
    {
        $limit     = $limit ?? (int) config('knowledge_platform.retrieval.passages', 8);
        $perSource = (int) config('knowledge_platform.retrieval.per_source', 4);

        $passages = [];

        foreach ($this->retrievers as $key => $retriever) {
            if (! $retriever->isAvailable()) {
                continue;
            }

            try {
                $weight = (float) (config("knowledge_platform.retrievers.{$key}.weight") ?? 1.0);

                foreach ($retriever->retrieve($query, $scopeChain, $perSource) as $passage) {
                    // Re-weight by source without mutating the retriever's own honest score: the
                    // passage keeps its provenance, the manager applies the operator's preference.
                    $passages[] = new RetrievedPassage(
                        text: $passage->text,
                        source: $passage->source,
                        retrievalMethod: $passage->retrievalMethod,
                        score: round($passage->score * $weight, 3),
                        title: $passage->title,
                        section: $passage->section,
                        url: $passage->url,
                        chunkId: $passage->chunkId,
                        documentId: $passage->documentId,
                        sourceId: $passage->sourceId,
                        dimension: $passage->dimension,
                        meta: $passage->meta,
                    );
                }
            } catch (Throwable $e) {
                Log::warning("Knowledge retriever '{$key}' failed", ['query' => $query, 'error' => $e->getMessage()]);
            }
        }

        if ($passages === []) {
            return [];
        }

        usort($passages, fn (RetrievedPassage $a, RetrievedPassage $b) => $b->score <=> $a->score);

        if ($this->reranker->isAvailable()) {
            return $this->reranker->rerank($query, $passages, $limit);
        }

        return array_slice($passages, 0, $limit);
    }

    /**
     * The confidence contribution of a retrieval set.
     *
     * Each source feeds its OWN dimension, which is what makes documentation and fleet evidence
     * independent terms in the final score rather than one averaged "evidence" number. Two manuals
     * agreeing raises documentation confidence; a manual plus a fleet statistic raises the score
     * further still, because it adds a dimension — see [[ConfidenceVector]].
     *
     * @param  array<int,RetrievedPassage>  $passages
     */
    public function confidenceFrom(array $passages): ConfidenceVector
    {
        $vector = new ConfidenceVector();

        foreach ($passages as $passage) {
            $vector->add(
                $passage->dimension,
                $passage->score * 100,
                $passage->citation(),
            );
        }

        return $vector;
    }

    /**
     * Render passages as the grounding block of a prompt, numbered so the extractor can cite them
     * back by index. That index is what turns a generated claim into an evidence row pointing at a
     * real passage rather than a plausible-looking citation string.
     *
     * @param  array<int,RetrievedPassage>  $passages
     * @return array{text:string,index:array<int,RetrievedPassage>}
     */
    public function renderGrounding(array $passages): array
    {
        if ($passages === []) {
            return ['text' => '', 'index' => []];
        }

        $lines = [];
        $index = [];

        foreach (array_values($passages) as $i => $passage) {
            $n = $i + 1;
            $index[$n] = $passage;
            $lines[] = "[{$n}] ".$passage->citation()."\n".mb_substr(trim($passage->text), 0, 1500);
        }

        $text = "\n\nRETRIEVED KNOWLEDGE\n"
            ."These passages were retrieved from the fleet's registered knowledge sources — technical "
            ."documentation and our own maintenance history. Ground your answer in them wherever they "
            ."are relevant and cite the passage number in `evidence_refs`. Where they do not cover "
            ."something, leave the reference out rather than inventing a citation.\n\n"
            .implode("\n\n", $lines);

        return ['text' => $text, 'index' => $index];
    }
}
