<?php

namespace App\Ontology\Contracts;

/**
 * Turns text into vectors, for semantic retrieval and semantic matching.
 *
 * Almost always a different vendor from the generation providers — Anthropic sells no embeddings
 * endpoint at all, so this is the clearest case for keeping the capabilities separate.
 *
 * SEMANTIC SEARCH IS ADDITIVE, NEVER AUTHORITATIVE. Wherever this is used, it may only ADD recall:
 * surface a candidate the deterministic stages missed. It must never outrank an exact match or
 * suppress a lexical hit. That rule lives in the matching pipeline, but it is worth stating here
 * because it is the reason this interface can be absent entirely without breaking anything.
 */
interface EmbeddingProvider
{
    /**
     * Embed one or more texts, in order.
     *
     * @param  array<int,string>  $texts
     * @return array<int,array<int,float>>  one vector per input, same order and count
     */
    public function embed(array $texts): array;

    /** Vector dimensionality — stored with each embedding so a model change is detectable. */
    public function dimensions(): int;

    public function name(): string;

    /**
     * The embedding model identifier. Vectors from different models are NOT comparable, so this is
     * written next to every stored vector and checked before any similarity computation.
     */
    public function version(): string;

    public function isAvailable(): bool;
}
