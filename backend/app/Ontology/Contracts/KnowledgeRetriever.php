<?php

namespace App\Ontology\Contracts;

use App\Ontology\DTO\RetrievedPassage;

/**
 * A source of knowledge passages. ONE interface for every source there will ever be.
 *
 * An ingested PDF, the public web, an OEM manual, our own repair history, ALLDATA once licensed —
 * from the engine's point of view these are indistinguishable. It asks for passages relevant to a
 * query within a vehicle scope, and gets back [[RetrievedPassage]] objects. It never learns, and
 * must never branch on, where a passage came from.
 *
 * THAT INDIFFERENCE IS THE POINT. Adding Mitchell 1 means writing one class and adding one config
 * line — no change to enrichment, confidence scoring, evidence storage or explanation. It is also
 * what makes FLEET HISTORY a first-class source rather than a bolted-on enhancement: our own
 * repair record implements exactly the same interface as a Bosch manual and is merged, weighted
 * and cited on the same footing.
 *
 * IMPLEMENTATION RULES
 *  - `isAvailable()` must return false when the source cannot legally or practically be read —
 *    a licensed source without credentials returns false rather than falling back to scraping.
 *  - Passages MUST carry honest provenance: the retrieval method and enough citation detail to
 *    reconstruct where the claim came from after the underlying row is gone.
 *  - Returning fewer passages than asked for is normal and fine. Throwing is not: a failing source
 *    must degrade the answer, never break the request.
 */
interface KnowledgeRetriever
{
    /**
     * @param  array<int,string>  $scopeChain  vehicle scope keys, widest first (VehicleScope::chain)
     * @return array<int,RetrievedPassage>
     */
    public function retrieve(string $query, array $scopeChain, int $limit): array;

    /** Stable key, matching the config entry: 'corpus', 'web', 'fleet', 'alldata'… */
    public function key(): string;

    /** Human-readable, for the UI's source breakdown. */
    public function label(): string;

    /**
     * Which confidence dimension this source feeds — 'documentation', 'fleet', or 'human'.
     * This is how a retriever's output reaches the right term of the confidence blend without the
     * scorer needing to know the source list.
     */
    public function confidenceDimension(): string;

    public function isAvailable(): bool;
}
