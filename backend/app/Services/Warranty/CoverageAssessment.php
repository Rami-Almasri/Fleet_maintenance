<?php

namespace App\Services\Warranty;

use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Support\WarrantyCoverage;

/**
 * The engine's answer: a verdict, why, and the promises it was reasoning about.
 *
 * Immutable and self-describing, because this object travels a long way — it is frozen onto a
 * purchase request, rendered on a review card, quoted in a notification and asserted on in tests.
 * Anything a caller might need to explain the decision has to be IN here; a verdict that has to be
 * re-derived downstream is a verdict that will eventually be re-derived differently.
 *
 * NOTE WHAT IS ABSENT: there is no score, no confidence, no probability. The verdict came from set
 * membership against recorded facts or it came from a human, and in either case a number attached to
 * it would be decoration pretending to be evidence ([[treat-data-as-source-of-truth]]).
 */
final class CoverageAssessment
{
    /**
     * @param string $verdict       covered | not_covered | unknown
     * @param string $reasonCode    WHY, as a code — never English. @see WarrantyCoverage
     * @param array  $reasonParams  the values the UI interpolates into the sentence it renders
     * @param Warranty[] $candidates every warranty that was considered — the ones a reviewer needs
     *                               to look at, and the reason a review card is worth opening
     * @param Warranty|null $decisive the promise the verdict actually rests on, when there is one
     * @param WarrantyClaim|null $existingCase the case already handling this question, if any
     */
    public function __construct(
        public readonly string $verdict,
        public readonly string $reasonCode,
        public readonly array $reasonParams = [],
        public readonly array $candidates = [],
        public readonly ?Warranty $decisive = null,
        public readonly ?WarrantyClaim $existingCase = null,
    ) {}

    /** Does this stop a normal purchase request? True for COVERED *and* UNKNOWN — see the support class. */
    public function blocksProcurement(): bool
    {
        return WarrantyCoverage::blocks($this->verdict);
    }

    /** Does somebody have to look at this before anything else happens? */
    public function needsReview(): bool
    {
        return $this->verdict === WarrantyCoverage::UNKNOWN;
    }

    public function isCovered(): bool
    {
        return $this->verdict === WarrantyCoverage::COVERED;
    }

    /**
     * Is there a live promise here at all?
     *
     * Distinct from isCovered(): a car under a manufacturer warranty that says nothing about wheel
     * bearings has cover but no verdict. This is the question the review card asks — "who do we ring?"
     */
    public function hasLiveCover(): bool
    {
        return $this->candidates !== [];
    }

    /** The API/frontend shape. Flat, codes not sentences, safe to freeze onto a row. */
    public function toArray(): array
    {
        return [
            'verdict'       => $this->verdict,
            'reason_code'   => $this->reasonCode,
            'reason_params' => $this->reasonParams,
            'blocks_procurement' => $this->blocksProcurement(),
            'needs_review'  => $this->needsReview(),
            'case_id'       => $this->existingCase?->id,
            'decisive_warranty_id' => $this->decisive?->id,
            'candidates'    => array_map(fn (Warranty $w) => [
                'id'            => $w->id,
                'kind'          => $w->kind,
                'subject'       => $w->subject,
                'provider_kind' => $w->provider_kind,
                'provider_name' => $w->provider_name,
                'reference_no'  => $w->reference_no,
                'expires_on'    => $w->expires_on?->toDateString(),
                'expires_at_km' => $w->expires_at_km,
                'contact_name'  => $w->contact_name,
                'contact_phone' => $w->contact_phone,
            ], $this->candidates),
        ];
    }

    /** Shorthand for the common "nothing here, carry on" answer. */
    public static function notCovered(string $reasonCode, array $params = [], array $candidates = []): self
    {
        return new self(WarrantyCoverage::NOT_COVERED, $reasonCode, $params, $candidates);
    }
}
