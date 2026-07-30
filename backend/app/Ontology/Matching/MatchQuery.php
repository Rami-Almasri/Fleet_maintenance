<?php

namespace App\Ontology\Matching;

use App\Support\TextNormalizer;
use App\Support\VehicleScope;

/**
 * A normalised matching request, prepared once and read by every stage.
 *
 * Normalisation and tokenisation happen here rather than in each stage so that six stages cannot
 * drift into six slightly different interpretations of the same input — the class equivalent of the
 * one-normaliser rule that [[TextNormalizer]] enforces for storage.
 */
final class MatchQuery
{
    public readonly string $normalized;

    /** @var array<int,string> */
    public readonly array $tokens;

    /** Normalised with spaces removed — for compound-word matching ("taillight" vs "tail light"). */
    public readonly string $joined;

    public function __construct(
        public readonly string $text,
        /** @var array<int,string> */
        public readonly array $scopeChain = [VehicleScope::UNIVERSAL],
        public readonly ?string $category = null,
        public readonly int $limit = 8,
        public readonly int $minScore = 25,
    ) {
        $this->normalized = TextNormalizer::key($text);
        $this->tokens     = TextNormalizer::tokens($text);
        $this->joined     = str_replace(' ', '', $this->normalized);
    }

    public function isEmpty(): bool
    {
        return $this->normalized === '';
    }

    /** True when this term belongs to the category the caller restricted the search to. */
    public function allowsCategory(?string $category): bool
    {
        return $this->category === null || $this->category === $category;
    }
}
