<?php

namespace App\Intelligence;

use App\Kpi\Kpi;
use InvalidArgumentException;

/**
 * The one place a metric code maps to the code that computes it.
 *
 * WHY THIS EXISTS. The failure mode that kills an analytics platform is not a missing chart, it is
 * two pages disagreeing about "average repair duration" — because the moment a number is arguable,
 * every number becomes arguable and people go back to spreadsheets. A single registry makes "where
 * is this defined?" answerable in one hop, and makes a second definition a merge-conflict rather
 * than a quiet divergence.
 *
 * It ships EMPTY. Resolvers register with their own module (garage resolvers arrive with Garage
 * Intelligence, and so on), because a registry pre-populated with codes nothing implements is just
 * a list of lies.
 *
 * It deliberately does NOT absorb OperationalKpiService. That service owns the 15 baseline KPIs and
 * the snapshot they feed; it works, it is consumed elsewhere, and swallowing it would be a refactor
 * with no user-visible benefit.
 */
final class MetricRegistry
{
    /** @var array<string, MetricResolver> */
    private array $resolvers = [];

    /** @param iterable<MetricResolver> $resolvers */
    public function __construct(iterable $resolvers = [])
    {
        foreach ($resolvers as $resolver) {
            $this->register($resolver);
        }
    }

    public function register(MetricResolver $resolver): self
    {
        $code = $resolver->code();

        if (isset($this->resolvers[$code])) {
            // A duplicate code means two definitions of one number — the exact thing this class exists
            // to prevent. Fail loudly at boot rather than serving whichever won the race.
            throw new InvalidArgumentException(
                "Metric code [{$code}] is already registered by " . $this->resolvers[$code]::class
                . '. A metric may have exactly one definition.'
            );
        }

        $this->resolvers[$code] = $resolver;

        return $this;
    }

    public function has(string $code): bool
    {
        return isset($this->resolvers[$code]);
    }

    public function resolve(string $code, MetricContext $context): Kpi
    {
        if (! isset($this->resolvers[$code])) {
            throw new InvalidArgumentException("No resolver registered for metric code [{$code}].");
        }

        return $this->resolvers[$code]->resolve($context);
    }

    /**
     * Resolve several codes under one context.
     *
     * @param  string[] $codes
     * @return array<string, Kpi>
     */
    public function resolveMany(array $codes, MetricContext $context): array
    {
        $out = [];
        foreach ($codes as $code) {
            $out[$code] = $this->resolve($code, $context);
        }

        return $out;
    }

    /** @return string[] */
    public function codes(): array
    {
        $codes = array_keys($this->resolvers);
        sort($codes);

        return $codes;
    }
}
