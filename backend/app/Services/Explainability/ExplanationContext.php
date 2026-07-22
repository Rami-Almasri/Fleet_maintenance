<?php

namespace App\Services\Explainability;

use Illuminate\Support\Carbon;

/**
 * The audit + snapshot envelope every explanation is produced under. It answers "under what conditions
 * was this explained?" — the engine version, the moment of generation, the snapshot date (as-of), the
 * currency/precision the numbers are stated in, and any filters applied. The SAME engine can therefore
 * explain today's value, last month's, or year-end's simply by changing `asOf`; the context is echoed
 * back so every explanation is itself auditable.
 */
final class ExplanationContext
{
    /** Bump when the graph schema or a builder's semantics change (shows in every audit block). */
    public const ENGINE_VERSION = '1.0.0';

    public function __construct(
        public readonly ?string $asOf = null,          // snapshot date (Y-m-d); null = live / lifetime-to-now
        public readonly string $currency = 'AED',
        public readonly int $precision = 2,
        public readonly array $filters = [],           // arbitrary applied filters, echoed for audit
        public readonly string $engineVersion = self::ENGINE_VERSION,
        public readonly ?string $generatedAt = null,   // injectable for deterministic tests
    ) {}

    /** The effective snapshot instant — the as-of date, or now when live. */
    public function asOfDate(): Carbon
    {
        return $this->asOf ? Carbon::parse($this->asOf)->endOfDay() : Carbon::now();
    }

    /** Human label for the snapshot ("Live" or the as-of date). */
    public function snapshotLabel(): string
    {
        return $this->asOf ? 'As of ' . $this->asOf : 'Live';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'engine_version' => $this->engineVersion,
            'generated_at'   => $this->generatedAt ?? Carbon::now()->toIso8601String(),
            'as_of'          => $this->asOf,
            'snapshot'       => $this->snapshotLabel(),
            'currency'       => $this->currency,
            'precision'      => $this->precision,
            'filters'        => $this->filters,
        ];
    }
}
