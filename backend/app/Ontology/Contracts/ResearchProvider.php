<?php

namespace App\Ontology\Contracts;

use App\Ontology\DTO\ResearchResult;

/**
 * Reads the open technical literature and reports what it found.
 *
 * The one capability that genuinely needs a tool-using model: it must be able to search, open
 * pages and summarise, staying inside an allowlist of domains it is given. Implementations exist
 * for whichever vendor provides that; the engine only ever sees this interface.
 *
 * WHY THIS IS SEPARATE FROM EXTRACTION. Research is expensive, slow, variable-latency and
 * optional. Extraction is cheap, fast and mandatory. Tying them to one provider would mean you
 * could never run research on a strong model and extraction on a cheap one — which is the normal
 * production configuration. It also means a provider that cannot search (most local models) can
 * still serve extraction perfectly well by pairing with the null research provider.
 */
interface ResearchProvider
{
    /**
     * Research a topic within a domain allowlist.
     *
     * Implementations MUST NOT retrieve outside `$allowedDomains` — that list is the licensing and
     * safety boundary of the whole platform, not a hint. A provider that cannot enforce a domain
     * restriction must return an empty result rather than searching the open web.
     *
     * @param  string  $topic          what to research
     * @param  string  $context        surrounding detail (category, vehicle scope, admin notes)
     * @param  array<int,string>  $allowedDomains  hosts this provider may read; empty = do not search
     */
    public function research(string $topic, string $context, array $allowedDomains): ResearchResult;

    /** Stable identifier recorded on every run for reproducibility, e.g. "anthropic". */
    public function name(): string;

    /** The concrete model/version used, recorded alongside `name()`, e.g. "claude-opus-5". */
    public function version(): string;

    /** False when credentials are missing — the engine skips the step instead of failing the run. */
    public function isAvailable(): bool;
}
