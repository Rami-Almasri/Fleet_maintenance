<?php

namespace App\Ontology\Providers\NullDriver;

use App\Ontology\Contracts\EmbeddingProvider;

/**
 * The "no embeddings vendor configured" driver.
 *
 * Anthropic sells no embeddings endpoint, so this capability is unconfigured out of the box. Rather
 * than scattering `if ($embeddings !== null)` through the retrieval and matching code, the absence
 * is modelled as a provider that is honestly unavailable: callers ask `isAvailable()` once and take
 * the lexical path, which is a complete and working retrieval strategy in its own right.
 *
 * To enable semantic search, implement this interface against Voyage, OpenAI, a local model or
 * anything else, register it in config/knowledge_platform.php, and backfill vectors. Nothing else
 * changes — the matching pipeline already has a semantic stage waiting for a live provider.
 */
class NullEmbeddingProvider implements EmbeddingProvider
{
    public function embed(array $texts): array
    {
        return [];
    }

    public function dimensions(): int
    {
        return 0;
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
