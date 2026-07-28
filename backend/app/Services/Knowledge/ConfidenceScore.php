<?php

namespace App\Services\Knowledge;

/**
 * The scorer's verdict. `score` is 0–100, `band` is high|medium|low, and `reasons` are the plain-language
 * drivers — INCLUDING the uncertainty ("Only 3 comparable repairs", "All evidence is fleet-wide"). The
 * band is the honest headline; the reasons make sure uncertainty is never hidden behind a single number.
 */
final class ConfidenceScore
{
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';

    /** @param array<int,string> $reasons */
    public function __construct(
        public readonly int $score,
        public readonly string $band,
        public readonly array $reasons,
    ) {
    }

    /** @return array{score:int, band:string, reasons:array<int,string>} */
    public function toArray(): array
    {
        return ['score' => $this->score, 'band' => $this->band, 'reasons' => $this->reasons];
    }
}
