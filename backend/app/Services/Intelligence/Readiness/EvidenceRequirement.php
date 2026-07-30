<?php

namespace App\Services\Intelligence\Readiness;

use Illuminate\Support\Carbon;

/**
 * One capability's evidence position — what it needs, what it has, and when it will be ready.
 *
 * The platform's operating KPI. It exists because "is this capability ready?" was being answered by
 * judgement, and judgement was wrong twice: Garage Recommendation looked obviously buildable until a
 * persistence test killed it, and the comeback card's own firing rate was eight times what a small
 * live sample suggested. A readiness question with a numeric answer stops being an argument.
 *
 * QUALITY is the field that matters most and is easiest to fake. `proxy` means the capability is
 * reasoning from something that stands in for the truth — a car coming back, rather than an
 * inspector finding the repair failed. No amount of volume converts a proxy into a measurement; only
 * a different input does.
 */
final readonly class EvidenceRequirement
{
    public const QUALITY_PROXY    = 'proxy';
    public const QUALITY_MEASURED = 'measured';

    public const STATUS_READY    = 'ready';
    public const STATUS_GROWING  = 'growing';   // on track, just not there yet
    public const STATUS_BLOCKED  = 'blocked';   // no rate of arrival — waiting will not fix it

    /**
     * @param string      $capabilityId  matches the capability's id(), or a planned one
     * @param string      $label         human name for the report
     * @param string      $evidence      what is actually being counted, in words
     * @param int         $current       how much exists now
     * @param int         $threshold     how much is needed
     * @param float       $weeklyRate    arrivals per week, measured not assumed
     * @param string      $quality       QUALITY_* — what the capability reasons from TODAY
     * @param string|null $blocker       why waiting will not help, when that is the case
     * @param float|null  $coverage      0..1 share of eligible events that produced evidence
     * @param int|null    $medianAgeDays age of the middle observation — is the evidence current?
     * @param Carbon|null $oldestAt      first observation
     * @param Carbon|null $newestAt      most recent observation — evidence still arriving?
     * @param int|null    $datasetAgeDays days since the corpus last saw anything
     * @param Carbon|null $lastEvaluatedAt when a comparison last actually ran
     * @param int|null    $evidenceAtLastEvaluation how much evidence existed when it did
     * @param bool        $datasetMoved  the corpus fingerprint changed since that evaluation
     */
    public function __construct(
        public string $capabilityId,
        public string $label,
        public string $evidence,
        public int $current,
        public int $threshold,
        public float $weeklyRate = 0.0,
        public string $quality = self::QUALITY_PROXY,
        public ?string $blocker = null,
        public ?float $coverage = null,
        public ?int $medianAgeDays = null,
        public ?Carbon $oldestAt = null,
        public ?Carbon $newestAt = null,
        public ?int $datasetAgeDays = null,
        public ?Carbon $lastEvaluatedAt = null,
        public ?int $evidenceAtLastEvaluation = null,
        public bool $datasetMoved = false,
    ) {}

    public function isReady(): bool
    {
        return $this->blocker === null && $this->current >= $this->threshold;
    }

    public function status(): string
    {
        if ($this->blocker !== null) {
            return self::STATUS_BLOCKED;
        }

        return $this->current >= $this->threshold ? self::STATUS_READY : self::STATUS_GROWING;
    }

    public function remaining(): int
    {
        return max($this->threshold - $this->current, 0);
    }

    /**
     * When the threshold will be met at the CURRENT arrival rate.
     *
     * Null when it never will — a zero rate is not "some day", it is "not without a change", and
     * reporting a distant date would disguise that as patience.
     */
    public function readyAt(): ?Carbon
    {
        if ($this->isReady() || $this->blocker !== null) {
            return null;
        }

        if ($this->weeklyRate <= 0) {
            return null;
        }

        return now()->addDays((int) ceil($this->remaining() / ($this->weeklyRate / 7)));
    }

    /** A capability with enough evidence can still be reasoning from a world that no longer exists. */
    public const STALE_AFTER_DAYS = 90;

    /** Evidence must grow by this share before a re-run could plausibly change the answer. */
    private const MATERIAL_GROWTH = 0.5;

    /**
     * Should this capability be re-evaluated because the evidence it was judged on has materially
     * changed?
     *
     * This never promotes or demotes anything by itself — it says only that the last evaluation may
     * no longer describe today's fleet. That distinction matters: a decision made on last year's
     * corpus is not WRONG, it simply answered a question about a different fleet, and treating those
     * two things as the same is how a platform either clings to stale conclusions or throws away good
     * ones the moment anything moves.
     *
     * @return string|null the reason, or null when the last evaluation still stands
     */
    public function reevaluationReason(): ?string
    {
        if ($this->lastEvaluatedAt === null) {
            return $this->isReady() ? 'never evaluated, and the evidence threshold is met' : null;
        }

        if ($this->datasetMoved) {
            return 'the corpus has been rebuilt since the last evaluation';
        }

        if ($this->evidenceAtLastEvaluation !== null && $this->evidenceAtLastEvaluation > 0) {
            $growth = ($this->current - $this->evidenceAtLastEvaluation) / $this->evidenceAtLastEvaluation;

            if ($growth >= self::MATERIAL_GROWTH) {
                return sprintf('evidence has grown %.0f%% since the last evaluation (%d → %d)',
                    $growth * 100, $this->evidenceAtLastEvaluation, $this->current);
            }
        }

        $age = (int) $this->lastEvaluatedAt->diffInDays(now());

        if ($age >= self::STALE_AFTER_DAYS) {
            return sprintf('last evaluated %d days ago', $age);
        }

        return null;
    }

    public function needsReevaluation(): bool
    {
        return $this->reevaluationReason() !== null;
    }

    /**
     * Is the evidence itself stale — enough of it, but drawn from a fleet that has since moved on?
     *
     * Volume and freshness are independent failures. A capability sitting on 5,000 observations whose
     * median is two years old is not well-evidenced; it is well-evidenced about the past.
     */
    public function isEvidenceStale(): bool
    {
        return $this->medianAgeDays !== null && $this->medianAgeDays > 365;
    }

    /** Has evidence stopped arriving? A dead feed looks identical to a healthy one on a count alone. */
    public function feedIsQuiet(): bool
    {
        return $this->newestAt !== null && $this->newestAt->diffInDays(now()) > 30;
    }

    public function readinessLabel(): string
    {
        return match ($this->status()) {
            self::STATUS_READY   => 'READY',
            self::STATUS_BLOCKED => 'BLOCKED',
            default              => $this->readyAt()?->toDateString() ?? 'never at this rate',
        };
    }
}
