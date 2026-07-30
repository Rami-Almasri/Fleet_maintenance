<?php

namespace App\Services\Intelligence\Readiness;

/**
 * One number for a product owner, computed from the dimensions that already exist.
 *
 * It REPLACES NOTHING. The detailed tables remain the instrument; this is the triage line that says
 * which capability to open first.
 *
 * THE RISK A COMPOSITE SCORE CARRIES, and how it is handled here:
 *
 *   1. AVERAGING HIDES BLOCKERS. A capability that cannot work at all — parts data that does not
 *      exist, durations that run backwards — would otherwise score respectably on freshness and
 *      coverage and land mid-table. So a blocker CAPS the score rather than subtracting from it. The
 *      platform already uses weakest-input-wins for confidence; this is the same principle applied
 *      to health, and for the same reason: a chain is not the average of its links.
 *
 *   2. A SCORE INVITES OPTIMISATION OF ITSELF. Every dimension here is a measurement of the world,
 *      not of the platform's effort, and each is reported alongside the total. `weakestDimension()`
 *      exists so the answer to "how do we raise this?" is always a specific fact about the fleet
 *      rather than a target to game.
 *
 *   3. IT FLATTENS DIFFERENT KINDS OF PROBLEM. Ready-but-stale and fresh-but-underpowered are
 *      genuinely different situations and score similarly. That is why `summary()` names the reason.
 */
final readonly class CapabilityHealth
{
    /** Weights sum to 1.0. Deliberately equal-ish: no dimension has earned the right to dominate. */
    private const WEIGHTS = [
        'coverage'   => 0.20,   // are eligible events producing evidence at all?
        'volume'     => 0.20,   // is there enough of it?
        'freshness'  => 0.20,   // is it about today's fleet?
        'evaluation' => 0.20,   // has anyone recently checked whether it predicts?
        'trust'      => 0.20,   // proxy or measured, and promoted or not
    ];

    /** A capability that cannot work cannot be healthy, however good its other numbers look. */
    private const BLOCKED_CEILING = 0.35;

    /**
     * @param array<string, float> $dimensions each 0..1, exposed so the total is never the whole story
     */
    public function __construct(
        public string $capabilityId,
        public string $label,
        public float $score,               // 0..100
        public array $dimensions,
        public ?string $blocker = null,
        public ?string $weakest = null,
        public ?string $attention = null,
    ) {}

    public static function for(EvidenceRequirement $r, ?bool $promoted = null): self
    {
        $dimensions = [
            'coverage'   => $r->coverage ?? 0.0,
            'volume'     => $r->threshold > 0 ? min($r->current / $r->threshold, 1.0) : 1.0,
            'freshness'  => self::freshnessScore($r),
            'evaluation' => self::evaluationScore($r),
            'trust'      => self::trustScore($r, $promoted),
        ];

        $raw = 0.0;
        foreach (self::WEIGHTS as $key => $weight) {
            $raw += $dimensions[$key] * $weight;
        }

        // The cap, not a penalty: no amount of freshness makes an unbuildable capability healthy.
        if ($r->blocker !== null) {
            $raw = min($raw, self::BLOCKED_CEILING);
        }

        asort($dimensions);
        $weakest = array_key_first($dimensions);

        return new self(
            capabilityId: $r->capabilityId,
            label: $r->label,
            score: round($raw * 100, 1),
            dimensions: $dimensions,
            blocker: $r->blocker,
            weakest: $weakest,
            attention: self::attention($r, $dimensions, $weakest),
        );
    }

    /**
     * Decays from a year: evidence whose middle observation is a year old describes a fleet that has
     * since re-contracted, re-routed and half-replaced itself.
     */
    private static function freshnessScore(EvidenceRequirement $r): float
    {
        if ($r->current === 0) {
            return 0.0;   // no evidence is a volume problem, but it is not fresh either
        }

        if ($r->medianAgeDays === null) {
            return 0.5;   // unknown age — neither credited nor punished
        }

        $score = max(0.0, 1.0 - $r->medianAgeDays / 365);

        // A feed that has gone quiet is worse than old evidence: it means nothing is arriving to fix it.
        return $r->feedIsQuiet() ? $score * 0.5 : $score;
    }

    private static function evaluationScore(EvidenceRequirement $r): float
    {
        if ($r->lastEvaluatedAt === null) {
            // Never evaluated. That is a bigger gap when the evidence is there and nobody has looked.
            return $r->isReady() ? 0.0 : 0.3;
        }

        $age = (int) $r->lastEvaluatedAt->diffInDays(now());

        return max(0.0, 1.0 - $age / (EvidenceRequirement::STALE_AFTER_DAYS * 2));
    }

    /**
     * How much the capability's reasoning can be trusted: measured beats proxy, and a proxy that has
     * been evaluated and knowingly kept beats one nobody has tested.
     */
    private static function trustScore(EvidenceRequirement $r, ?bool $promoted): float
    {
        if ($promoted === true) {
            return 1.0;
        }

        if ($r->quality === EvidenceRequirement::QUALITY_MEASURED) {
            return 0.85;   // measured evidence, but no promotion decision behind it
        }

        // Proxy. Knowingly kept after a comparison is better than never examined.
        return $promoted === false ? 0.6 : 0.4;
    }

    /** The one sentence a product owner needs: what to do about this capability first. */
    private static function attention(EvidenceRequirement $r, array $dimensions, string $weakest): string
    {
        if ($r->blocker !== null) {
            return 'Blocked by data, not by build: '.$r->blocker;
        }

        if ($reason = $r->reevaluationReason()) {
            return 'Re-evaluate — '.$reason;
        }

        return match ($weakest) {
            'coverage'   => 'Evidence is not being captured on every eligible event.',
            'volume'     => sprintf('Needs %d more %s.', $r->remaining(), $r->evidence),
            'freshness'  => 'Evidence is ageing — most of it no longer describes the current fleet.',
            'evaluation' => 'Nobody has tested whether this actually predicts.',
            default      => 'Reasoning from a proxy — promotion needs measured evidence.',
        };
    }

    /** healthy | watch | at risk | blocked — bands, because a decimal invites false precision. */
    public function band(): string
    {
        return match (true) {
            $this->blocker !== null => 'blocked',
            $this->score >= 75      => 'healthy',
            $this->score >= 50      => 'watch',
            default                 => 'at risk',
        };
    }

    public function summary(): string
    {
        return sprintf('%s (%.0f/100) — %s', $this->band(), $this->score, $this->attention);
    }
}
