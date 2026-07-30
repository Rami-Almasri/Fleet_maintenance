<?php

namespace App\Ontology\Matching;

use App\Ontology\Confidence\ConfidenceVector;

/**
 * One fault concept under consideration, accumulating evidence as the pipeline runs.
 *
 * Stages don't overwrite each other — they each ADD evidence, and the candidate keeps the best
 * score plus every reason it was reached. That is what makes a match explainable: by the end of the
 * pipeline a candidate can say "an exact alias matched, three tokens overlapped, and it is
 * semantically close", and the confidence vector reflects all three.
 *
 * The score is the strongest single piece of evidence, not a sum — corroboration is expressed
 * through the confidence vector's breadth rule rather than by inflating the score, so a concept
 * with many weak hits never outranks one with a genuine exact match.
 */
class MatchCandidate
{
    /** @var array<int,array<string,mixed>> */
    private array $evidence = [];

    private float $best = 0.0;

    private ConfidenceVector $confidence;

    public function __construct(public readonly int $keywordId)
    {
        $this->confidence = new ConfidenceVector();
    }

    /**
     * Record what a stage found.
     *
     * @param  string  $stage       'exact' | 'alias' | 'phrase' | 'token' | 'fuzzy' | 'semantic'
     * @param  float   $score       0-100, this stage's assessment
     * @param  string  $dimension   which confidence dimension this feeds
     * @param  array<string,mixed>  $meta  the term that matched, how, etc.
     */
    public function addEvidence(string $stage, float $score, string $explanation, string $dimension = 'lexical', array $meta = []): self
    {
        $this->evidence[] = [
            'stage'       => $stage,
            'score'       => round($score, 1),
            'explanation' => $explanation,
            'meta'        => $meta,
        ];

        $this->best = max($this->best, $score);
        $this->confidence->add($dimension, $score, $explanation);

        return $this;
    }

    /** Fold in evidence discovered outside the lexical pipeline (fleet history, documentation). */
    public function addConfidence(string $dimension, float $score, string $reason): self
    {
        $this->confidence->add($dimension, $score, $reason);

        return $this;
    }

    public function score(): int
    {
        return (int) round($this->best);
    }

    public function confidence(): ConfidenceVector
    {
        return $this->confidence;
    }

    /** @return array<int,array<string,mixed>> strongest first */
    public function evidence(): array
    {
        $sorted = $this->evidence;
        usort($sorted, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $sorted;
    }

    /** The stages that contributed, in pipeline order — a compact trace of how this was reached. */
    public function stages(): array
    {
        return array_values(array_unique(array_column($this->evidence, 'stage')));
    }

    public function matchedBy(string $stage): bool
    {
        return in_array($stage, array_column($this->evidence, 'stage'), true);
    }

    /**
     * True when NOTHING deterministic found this — it exists purely because semantic recall
     * surfaced it. Such a candidate is a suggestion, not a match, and the pipeline clamps it below
     * every deterministic result so it can never displace one.
     */
    public function isSemanticOnly(): bool
    {
        $stages = array_unique(array_column($this->evidence, 'stage'));

        return $stages === ['semantic'];
    }

    /** Lower the score to a ceiling, leaving the evidence and confidence breakdown untouched. */
    public function clampTo(float $max): self
    {
        $this->best = min($this->best, max(0.0, $max));

        return $this;
    }
}
