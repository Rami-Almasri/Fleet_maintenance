<?php

namespace App\Ontology\Retrieval;

use App\Models\KnowledgeSource;
use App\Ontology\Contracts\KnowledgeRetriever;
use App\Ontology\Contracts\ResearchProvider;
use App\Ontology\DTO\RetrievedPassage;

/**
 * Live retrieval from the open technical web, strictly inside the registered domain allowlist.
 *
 * Unlike the corpus and fleet retrievers, this one does not read a table — it delegates to the
 * configured [[ResearchProvider]], because searching and reading pages requires a tool-using model.
 * That indirection is what keeps the platform vendor-neutral here too: this class knows it wants
 * "web research", not that Claude is doing it.
 *
 * ⚠️ THE ALLOWLIST IS THE LICENSING BOUNDARY. Domains come only from sources registered as
 * `public_web` in [[KnowledgeSource]]. A `licensed` source (ALLDATA, Mitchell 1, Haynes, Chilton,
 * OEM factory manuals) is never in that list, so this retriever cannot reach it even accidentally —
 * their content arrives through their own licensed integration into the corpus, or not at all.
 * With no registered domains, `isAvailable()` is false and nothing is searched.
 */
class WebRetriever implements KnowledgeRetriever
{
    public function __construct(private readonly ResearchProvider $research)
    {
    }

    public function key(): string
    {
        return 'web';
    }

    public function label(): string
    {
        return 'Public technical sources';
    }

    public function confidenceDimension(): string
    {
        return 'documentation';
    }

    public function isAvailable(): bool
    {
        return $this->research->isAvailable() && KnowledgeSource::allowedDomains() !== [];
    }

    /** @return array<int,RetrievedPassage> */
    public function retrieve(string $query, array $scopeChain, int $limit): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $scope = end($scopeChain);
        $context = $scope && $scope !== '*'
            ? 'Focus on '.\App\Support\VehicleScope::label($scope).'.'
            : 'Cover vehicles in general.';

        $result = $this->research->research($query, $context, KnowledgeSource::allowedDomains());

        if (! $result->ok()) {
            return [];      // a failing source degrades the answer; it never breaks the request
        }

        return array_slice($result->passages, 0, $limit);
    }

    /**
     * The research brief itself — prose rather than passages. Exposed separately because
     * enrichment wants the narrative as context, while the passage list is what gets cited.
     */
    public function brief(string $query, array $scopeChain): ?string
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $scope = end($scopeChain);
        $result = $this->research->research(
            $query,
            $scope && $scope !== '*' ? 'Focus on '.\App\Support\VehicleScope::label($scope).'.' : '',
            KnowledgeSource::allowedDomains(),
        );

        return $result->hasFindings() ? $result->findings : null;
    }
}
