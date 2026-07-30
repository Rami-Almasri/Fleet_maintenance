<?php

namespace App\Ontology\Contracts;

use App\Ontology\DTO\RetrievedPassage;

/**
 * Reorders retrieved passages by true relevance to a query.
 *
 * Retrieval is recall-oriented — it casts wide and cheap. Reranking is precision-oriented: a
 * cross-encoder (or a small model) reads the query and each passage TOGETHER and scores them
 * properly, which is far more accurate than the vector or lexical similarity that found them.
 *
 * It is separate from retrieval because it is a different vendor, a different cost profile, and
 * genuinely optional: without it the merged retrieval order is used as-is, which is good enough to
 * ground an answer. With it, the passages actually handed to extraction are the right ones — which
 * matters most exactly when the corpus is large, i.e. later.
 */
interface RerankerProvider
{
    /**
     * @param  array<int,RetrievedPassage>  $passages
     * @return array<int,RetrievedPassage>  re-ordered and truncated to $limit, scores updated
     */
    public function rerank(string $query, array $passages, int $limit): array;

    public function name(): string;

    public function version(): string;

    public function isAvailable(): bool;
}
