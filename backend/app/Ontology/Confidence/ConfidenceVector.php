<?php

namespace App\Ontology\Confidence;

/**
 * Confidence, decomposed — one score per kind of evidence, plus the blend.
 *
 * A single number ("87% confident") is unusable: it can't be argued with, tuned, or debugged. This
 * keeps the dimensions apart all the way to the UI, so a supervisor sees WHY a match is confident
 * and an engineer can tune WHICH evidence counts for how much without touching logic.
 *
 *      lexical        the words actually matched
 *      documentation  retrieved from professional sources
 *      fleet          measured on our own vehicles
 *      embedding      semantically close
 *      ai             model prior, nothing retrieved
 *      human          a person stated it
 *
 * TWO RULES MAKE THE BLEND HONEST
 *
 * 1. ONLY PRESENT DIMENSIONS COUNT. A fault with no corpus behind it isn't 0% confident, it's
 *    "confident on the evidence available". Missing dimensions are excluded and the weights
 *    renormalised over what's there — otherwise every answer would score low until the corpus is
 *    complete, and the number would tell you nothing about the answer.
 *
 * 2. BREADTH RAISES THE CEILING. One dimension, however strong, is capped (72 by default); four
 *    agreeing dimensions can reach 100. This is the part that prevents the classic failure mode
 *    where one exact string match reads as certainty. Corroboration is a distinct thing from
 *    strength, and the score has to reflect both.
 *
 * Weights and ceilings live in config/knowledge_platform.php — deliberately tunable, because the
 * right balance shifts as the corpus and the fleet history grow.
 */
final class ConfidenceVector
{
    public const DIMENSIONS = ['lexical', 'documentation', 'fleet', 'embedding', 'ai', 'human'];

    /** @var array<string,float>  dimension => 0-100, only for dimensions that contributed */
    private array $scores = [];

    /** @var array<string,array<int,string>>  dimension => human-readable reasons */
    private array $reasons = [];

    /**
     * Record a dimension's confidence. Calling twice for the same dimension keeps the STRONGEST
     * value and accumulates both reasons — two documents supporting a claim is not weaker than one,
     * and averaging them would perversely punish corroboration within a dimension.
     */
    public function add(string $dimension, float $score, ?string $reason = null): self
    {
        if (! in_array($dimension, self::DIMENSIONS, true)) {
            return $this;
        }

        $score = max(0.0, min(100.0, $score));
        $this->scores[$dimension] = max($this->scores[$dimension] ?? 0.0, $score);

        if ($reason !== null) {
            $this->reasons[$dimension][] = $reason;
        }

        return $this;
    }

    /** The blended score, 0–100. See the class doc for the two rules that shape it. */
    public function score(): int
    {
        if ($this->scores === []) {
            return 0;
        }

        $weights = config('knowledge_platform.confidence.weights', []);

        $weighted = 0.0;
        $total = 0.0;

        foreach ($this->scores as $dimension => $score) {
            $w = (float) ($weights[$dimension] ?? 0.5);
            $weighted += $score * $w;
            $total += $w;
        }

        $blended = $total > 0 ? $weighted / $total : 0.0;

        return (int) round(min($blended, $this->ceiling()));
    }

    /** The maximum this vector can score given how many kinds of evidence support it. */
    public function ceiling(): int
    {
        $ceilings = config('knowledge_platform.confidence.coverage_ceiling', [1 => 72, 2 => 86, 3 => 94, 4 => 100]);
        $count = count($this->scores);

        // Beyond the largest configured tier, no further ceiling applies.
        $max = 100;
        foreach ($ceilings as $dims => $cap) {
            if ($count <= $dims) {
                return (int) $cap;
            }
            $max = (int) $cap;
        }

        return $max;
    }

    /** True when nothing but the model's own prior supports this. */
    public function isUngrounded(): bool
    {
        $grounded = array_diff(array_keys($this->scores), ['ai']);

        return $grounded === [];
    }

    public function has(string $dimension): bool
    {
        return isset($this->scores[$dimension]);
    }

    public function get(string $dimension): ?float
    {
        return $this->scores[$dimension] ?? null;
    }

    /** @return array<string,float> */
    public function dimensions(): array
    {
        return $this->scores;
    }

    /**
     * The full breakdown for the API and the UI: every dimension, its score, its weight, and the
     * reasons behind it — plus the ceiling that was applied, so a capped score is explainable
     * rather than looking like an arithmetic error.
     *
     * @return array<string,mixed>
     */
    public function explain(): array
    {
        $weights = config('knowledge_platform.confidence.weights', []);

        return [
            'score'      => $this->score(),
            'ceiling'    => $this->ceiling(),
            'ungrounded' => $this->isUngrounded(),
            'dimensions' => collect($this->scores)
                ->map(fn (float $score, string $dim) => [
                    'dimension' => $dim,
                    'score'     => (int) round($score),
                    'weight'    => (float) ($weights[$dim] ?? 0.5),
                    'reasons'   => $this->reasons[$dim] ?? [],
                ])
                ->sortByDesc('score')
                ->values()
                ->all(),
        ];
    }

    /** Merge another vector in — used when several pieces of evidence describe one candidate. */
    public function merge(self $other): self
    {
        foreach ($other->scores as $dimension => $score) {
            $this->add($dimension, $score);
            foreach ($other->reasons[$dimension] ?? [] as $reason) {
                $this->reasons[$dimension][] = $reason;
            }
        }

        return $this;
    }
}
