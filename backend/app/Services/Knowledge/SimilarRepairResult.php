<?php

namespace App\Services\Knowledge;

/**
 * The output of RepairHistoryQueryService::similarRepairs — the four confidence tiers of matched past
 * repairs plus a flat view for aggregation. Pure data; no DB. `flat` is every matched repair (used for
 * cohort stats + confidence), while `tiers` is the display-capped, tier-grouped view.
 */
final class SimilarRepairResult
{
    /**
     * @param array{vehicle:array<int,array<string,mixed>>, model:array<int,array<string,mixed>>,
     *              make:array<int,array<string,mixed>>, fleet:array<int,array<string,mixed>>} $tiers
     * @param array<int,array<string,mixed>> $flat
     */
    public function __construct(
        public readonly array $tiers,
        public readonly array $flat,
        public readonly string $matchedOn,
        public readonly int $bestTier,
    ) {
    }

    public function sampleSize(): int
    {
        return count($this->flat);
    }

    public function hasHistory(): bool
    {
        return ! empty($this->flat);
    }
}
