<?php

namespace App\Ontology\DTO;

/**
 * Base shape returned by every provider call.
 *
 * Carries the two things the platform needs from ANY vendor regardless of what it did: the usage it
 * consumed, and the identity of what produced it. Those identity fields are what make [[versioning]]
 * work — every enrichment run records the provider and version that generated it, so regenerating
 * an entry six months later can always be explained as "the extraction model changed" rather than
 * an unexplained diff.
 */
abstract class ProviderResult
{
    public function __construct(
        public readonly string $provider,
        public readonly string $version,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        /** Set when the provider was unavailable or failed softly — the run continues without it. */
        public readonly ?string $error = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null;
    }
}
