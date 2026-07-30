<?php

namespace App\Ontology\Retrieval;

use App\Models\EvidenceLink;
use App\Models\KnowledgeChunk;
use App\Ontology\Contracts\EmbeddingProvider;
use App\Ontology\Contracts\KnowledgeRetriever;
use App\Ontology\DTO\RetrievedPassage;
use App\Support\TextNormalizer;

/**
 * Retrieves from the ingested document corpus — manuals, bulletins, uploaded PDFs, and anything a
 * licensed integration has pulled down.
 *
 * TWO SCORING PATHS, ONE RESULT. When an [[EmbeddingProvider]] is configured, chunks that have a
 * vector from the SAME embedding model are scored by cosine similarity; every other chunk falls
 * back to lexical overlap. That per-chunk fallback matters: a corpus is embedded incrementally, so
 * for a long time it will be part-vectorised, and an all-or-nothing implementation would go blind
 * on the un-embedded half. Vectors from a different model are ignored rather than compared —
 * cross-model cosine is meaningless and would quietly return nonsense.
 */
class CorpusRetriever implements KnowledgeRetriever
{
    public function __construct(private readonly EmbeddingProvider $embeddings)
    {
    }

    public function key(): string
    {
        return 'corpus';
    }

    public function label(): string
    {
        return 'Document corpus';
    }

    public function confidenceDimension(): string
    {
        return 'documentation';
    }

    public function isAvailable(): bool
    {
        return KnowledgeChunk::query()->exists();
    }

    /** @return array<int,RetrievedPassage> */
    public function retrieve(string $query, array $scopeChain, int $limit): array
    {
        $tokens = TextNormalizer::tokens($query);
        if ($tokens === [] || ! $this->isAvailable()) {
            return [];
        }

        $queryVector = $this->queryVector($query);

        // Cheap SQL pre-filter, precise scoring in PHP. A LIKE per token narrows thousands of rows
        // to dozens; ranking properly in SQL would need a full-text index we cannot assume exists
        // on every deployment (MariaDB and MySQL differ — see [[mariadb-local-mysql8-prod]]).
        //
        // When embeddings are in play the pre-filter is widened, because a semantically relevant
        // passage may share no literal token with the query — which is the entire point of vectors.
        $candidates = KnowledgeChunk::query()
            ->whereHas('document', fn ($q) => $q->inScope($scopeChain))
            ->when(
                $queryVector === null,
                fn ($q) => $q->where(function ($w) use ($tokens) {
                    foreach (array_slice($tokens, 0, 6) as $token) {
                        $w->orWhere('normalized', 'like', '%'.$token.'%');
                    }
                }),
                fn ($q) => $q->where(function ($w) use ($tokens) {
                    $w->whereNotNull('embedding');
                    foreach (array_slice($tokens, 0, 6) as $token) {
                        $w->orWhere('normalized', 'like', '%'.$token.'%');
                    }
                })
            )
            ->with('document.source')
            ->limit(300)
            ->get();

        return collect($candidates)
            ->map(function (KnowledgeChunk $chunk) use ($tokens, $queryVector) {
                $semantic = $queryVector !== null && $chunk->embedding_model === $this->embeddings->version()
                    ? $chunk->cosineTo($queryVector)
                    : null;

                // Cosine runs -1..1; only positive similarity is meaningful for relevance.
                $score = $semantic !== null ? max(0.0, $semantic) : $this->lexicalScore($chunk, $tokens);

                // Trust-weight by source tier: an OEM procedure outranks a general reference at the
                // same textual relevance, which is the whole reason the source registry has tiers.
                $trust = ($chunk->document?->source?->trust_weight ?? 50) / 100;

                return ['chunk' => $chunk, 'score' => $score * (0.7 + 0.3 * $trust), 'semantic' => $semantic !== null];
            })
            ->filter(fn (array $r) => $r['score'] > 0.12)
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn (array $r) => new RetrievedPassage(
                text: $r['chunk']->text,
                source: $this->key(),
                retrievalMethod: EvidenceLink::METHOD_CORPUS,
                score: round(min(1.0, $r['score']), 3),
                title: $r['chunk']->document?->citation(),
                section: $r['chunk']->section,
                url: $r['chunk']->document?->url,
                chunkId: $r['chunk']->id,
                documentId: $r['chunk']->knowledge_document_id,
                sourceId: $r['chunk']->document?->knowledge_source_id,
                dimension: $this->confidenceDimension(),
                meta: ['matched_by' => $r['semantic'] ? 'embedding' : 'lexical'],
            ))
            ->values()
            ->all();
    }

    /** The query vector, or null when no usable embedding provider is configured. */
    private function queryVector(string $query): ?array
    {
        if (! $this->embeddings->isAvailable()) {
            return null;
        }

        $vectors = $this->embeddings->embed([$query]);

        return $vectors[0] ?? null;
    }

    /**
     * Share of the query's meaningful tokens present in the passage, with a bonus for repetition —
     * a chunk that says "rotor" six times is about rotors; one that mentions it once in a parts
     * list is not.
     *
     * @param  array<int,string>  $tokens
     */
    private function lexicalScore(KnowledgeChunk $chunk, array $tokens): float
    {
        $text = ' '.$chunk->normalized.' ';
        $hits = 0;
        $density = 0;

        foreach ($tokens as $token) {
            $count = substr_count($text, ' '.$token);
            if ($count > 0) {
                $hits++;
                $density += min(3, $count);
            }
        }

        if ($hits === 0) {
            return 0.0;
        }

        return ($hits / count($tokens)) * (1 + ($density / max(1, count($tokens))) * 0.15);
    }
}
