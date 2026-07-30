<?php

namespace App\Ontology\DTO;

/**
 * What a [[ResearchProvider]] found.
 *
 * `passages` are the sources it actually opened, already in the universal [[RetrievedPassage]]
 * shape — so web research merges with corpus and fleet retrieval through exactly the same path and
 * gets cited the same way. `findings` is the prose brief handed to extraction.
 */
final class ResearchResult extends ProviderResult
{
    public function __construct(
        string $provider,
        string $version,
        public readonly string $findings = '',
        /** @var array<int,RetrievedPassage> */
        public readonly array $passages = [],
        int $inputTokens = 0,
        int $outputTokens = 0,
        ?string $error = null,
    ) {
        parent::__construct($provider, $version, $inputTokens, $outputTokens, $error);
    }

    /** An empty result — used by the null provider and whenever research is skipped. */
    public static function empty(string $provider = 'none', string $reason = 'research not configured'): self
    {
        return new self($provider, '-', error: $reason);
    }

    public function hasFindings(): bool
    {
        return trim($this->findings) !== '';
    }
}
