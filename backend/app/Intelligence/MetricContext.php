<?php

namespace App\Intelligence;

use Carbon\CarbonImmutable;

/**
 * The filter bag every intelligence metric is computed under — immutable, and hashable.
 *
 * Two jobs, and the second is the reason this is a class rather than an array:
 *
 *  1. It is the ONE definition of what a caller may filter by. A resolver that wants a filter not
 *     listed here has to add it here first, which is what stops each module inventing its own
 *     slightly different notion of "period".
 *
 *  2. Its {@see hash()} is the cache key input. That hash MUST be stable under key reordering —
 *     `?to=X&from=Y` and `?from=Y&to=X` are the same question and must not occupy two cache
 *     entries. Sorting before hashing is what makes the cache bounded rather than a slow leak.
 *
 * MIN_SAMPLE lives here, not in each resolver, because "how much data before this means anything"
 * is a platform-wide policy. It mirrors OperationalKpiService::MIN_SAMPLE deliberately: two
 * different thresholds on two surfaces would produce two different answers to the same question.
 */
final class MetricContext
{
    /** Below this, a rate is an anecdote. Mirrors OperationalKpiService::MIN_SAMPLE. */
    public const MIN_SAMPLE = 30;

    /** Filters allowed into the hash. Anything else is ignored rather than silently widening the key space. */
    private const HASHABLE = [
        'from', 'to', 'vendor_ids', 'vehicle_ids', 'canonical_model',
        'signature', 'origin', 'min_sample', 'include_non_garages',
    ];

    public function __construct(
        public readonly ?CarbonImmutable $from = null,
        public readonly ?CarbonImmutable $to = null,
        /** @var int[] */
        public readonly array $vendorIds = [],
        /** @var int[] */
        public readonly array $vehicleIds = [],
        public readonly ?string $canonicalModel = null,
        public readonly ?string $signature = null,
        public readonly ?string $origin = null,
        public readonly int $minSample = self::MIN_SAMPLE,
        public readonly bool $includeNonGarages = false,
    ) {
    }

    /**
     * Build from a validated request payload.
     *
     * Deliberately tolerant of absent keys — every filter is optional, and a missing period means
     * "everything", not "error". Validation of shape belongs to the FormRequest; this only maps.
     */
    public static function fromArray(array $input): self
    {
        $ints = static fn ($v) => array_values(array_unique(array_map('intval', array_filter((array) ($v ?? []), 'is_numeric'))));

        $minSample = (int) ($input['min_sample'] ?? self::MIN_SAMPLE);

        return new self(
            from:              isset($input['from']) ? CarbonImmutable::parse($input['from'])->startOfDay() : null,
            to:                isset($input['to']) ? CarbonImmutable::parse($input['to'])->endOfDay() : null,
            vendorIds:         $ints($input['vendor_id'] ?? $input['vendor_ids'] ?? []),
            vehicleIds:        $ints($input['vehicle_id'] ?? $input['vehicle_ids'] ?? []),
            canonicalModel:    $input['model'] ?? $input['canonical_model'] ?? null,
            signature:         $input['signature'] ?? null,
            origin:            $input['origin'] ?? null,
            minSample:         max(1, min(500, $minSample)),
            includeNonGarages: filter_var($input['include_non_garages'] ?? false, FILTER_VALIDATE_BOOL),
        );
    }

    /** A copy with a different minimum sample — the only field a resolver may legitimately override. */
    public function withMinSample(int $minSample): self
    {
        return new self(
            $this->from, $this->to, $this->vendorIds, $this->vehicleIds, $this->canonicalModel,
            $this->signature, $this->origin, max(1, min(500, $minSample)), $this->includeNonGarages,
        );
    }

    public function toArray(): array
    {
        $vendors  = $this->vendorIds;
        $vehicles = $this->vehicleIds;
        sort($vendors);
        sort($vehicles);

        return [
            'from'                => $this->from?->toDateString(),
            'to'                  => $this->to?->toDateString(),
            'vendor_ids'          => $vendors,
            'vehicle_ids'         => $vehicles,
            'canonical_model'     => $this->canonicalModel,
            'signature'           => $this->signature,
            'origin'              => $this->origin,
            'min_sample'          => $this->minSample,
            'include_non_garages' => $this->includeNonGarages,
        ];
    }

    /**
     * Deterministic cache-key fragment.
     *
     * Sorted keys AND sorted id lists, so any permutation of the same filters yields one hash.
     * Truncated to 16 hex chars: collision risk is irrelevant for a cache namespace, and short keys
     * keep the cache table readable when someone is debugging a stale number.
     */
    public function hash(): string
    {
        $payload = $this->toArray();
        ksort($payload);

        return substr(sha1(json_encode($payload, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /** `intelligence:garage:leaderboard:v1:ab12cd34…` — the project's existing key convention. */
    public function cacheKey(string $domain, string $metric, int $version = 1): string
    {
        return sprintf('intelligence:%s:%s:v%d:%s', $domain, $metric, $version, $this->hash());
    }
}
