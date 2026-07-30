<?php

namespace App\Ontology\Reasoning;

use App\Ontology\Confidence\ConfidenceVector;

/**
 * One candidate explanation for a fault, with its probability, its evidence, and its confidence —
 * three different questions that a single number cannot answer.
 *
 *   probability — how likely THIS cause is relative to the other candidates for this fault
 *   confidence  — how much we trust that estimate at all
 *   evidence    — what we are basing it on, in words a technician can check
 *
 * Keeping them apart is the point. "Most likely cause, but we are guessing" and "less likely cause,
 * but we have measured it 200 times" are both useful and completely different, and a workshop that
 * cannot tell them apart will learn to distrust the whole system.
 */
final class ProbableCause
{
    /**
     * @param  array<int,string>  $evidence
     */
    public function __construct(
        public readonly int $nodeId,
        public readonly string $label,
        public readonly ?string $labelAr,
        public readonly int $probability,
        public readonly ConfidenceVector $confidence,
        public readonly array $evidence,
        public readonly string $provenance,
        public readonly ?int $findingKeywordId = null,
        public readonly int $observedCount = 0,
    ) {
    }

    /** True when our own history — not a catalogue or a model — stands behind this cause. */
    public function isMeasured(): bool
    {
        return $this->observedCount > 0;
    }

    public function toArray(): array
    {
        return [
            'node_id'            => $this->nodeId,
            'label'              => $this->label,
            'label_ar'           => $this->labelAr,
            'probability'        => $this->probability,
            'confidence'         => $this->confidence->score(),
            'confidence_detail'  => $this->confidence->explain(),
            'evidence'           => $this->evidence,
            'provenance'         => $this->provenance,
            'measured'           => $this->isMeasured(),
            'observed_count'     => $this->observedCount,
            'finding_keyword_id' => $this->findingKeywordId,
        ];
    }
}
