<?php

namespace App\Ontology\Providers\NullDriver;

use App\Ontology\Contracts\ResearchProvider;
use App\Ontology\DTO\ResearchResult;

/**
 * The "no research provider" driver — enrichment runs on the local corpus and fleet history alone.
 *
 * A legitimate production configuration, not just a fallback: an air-gapped deployment, or one that
 * only trusts its own licensed corpus, disables live research entirely. The engine reports lower
 * documentation confidence and says so, which is the correct outcome rather than an error.
 */
class NullResearchProvider implements ResearchProvider
{
    public function research(string $topic, string $context, array $allowedDomains): ResearchResult
    {
        return ResearchResult::empty($this->name(), 'research provider disabled');
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
