<?php

namespace App\Ontology\Providers\NullDriver;

use App\Ontology\Contracts\RerankerProvider;

/**
 * The "no reranker configured" driver — passes the merged retrieval order straight through.
 *
 * Reranking improves precision; its absence costs quality, not correctness, so the honest no-op is
 * simply to truncate at the requested limit. It matters most when the corpus is large, which is
 * later — so shipping without one is the right default rather than a gap.
 */
class NullRerankerProvider implements RerankerProvider
{
    public function rerank(string $query, array $passages, int $limit): array
    {
        return array_slice($passages, 0, $limit);
    }

    public function name(): string
    {
        return 'null';
    }

    public function version(): string
    {
        return 'none';
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
