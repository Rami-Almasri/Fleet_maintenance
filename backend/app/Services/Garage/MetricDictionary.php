<?php

namespace App\Services\Garage;

/**
 * What every number on the recommendation actually means.
 *
 * A supervisor is being asked to send a car somewhere on the strength of figures like "Coverage 84%",
 * "Specialization 89%", "Engine ×1.3". Those are meaningless — and quietly untrustworthy — unless the
 * screen can also answer three questions about each one:
 *
 *   short   a one-line definition shown UNDER the figure, with no click — because a supervisor who has
 *           to open a popover to learn what a number means has already misread it once
 *   means   what the number is claiming, in a sentence, without jargon
 *   method  how it was calculated (the actual arithmetic, not a hand-wave)
 *   source  which records produced it, so the claim can be checked or disputed
 *
 * Labels are written for a workshop supervisor, not for the engine. "Coverage 84%" provokes "84% of
 * what?"; "Fault experience" with the repair counts printed beneath it does not.
 *
 * `kind` is the honesty axis, and it matters more than the value: a POLICY number is a business rule
 * somebody chose and can change, a MEASURED number is counted from history, a FORECAST is a prediction
 * that may be wrong, and a DERIVED number is arithmetic over the others. Presenting a policy multiplier
 * and an observed comeback rate in the same visual style implies the first is evidence, which it is not.
 *
 * Definitions live HERE rather than in the UI so the API and the interface can never drift into
 * explaining a number two different ways, and so a changed formula forces a changed explanation in the
 * same commit.
 *
 * See [[garage-recommendation-engine]] and [[evidence-layer-governance]] (Fact | Judgement | Derived).
 */
class MetricDictionary
{
    public const POLICY = 'policy';       // a business rule someone chose; changeable, not evidence
    public const MEASURED = 'measured';   // counted from historical records
    public const FORECAST = 'forecast';   // a prediction about this repair; may be wrong
    public const DERIVED = 'derived';     // arithmetic over other numbers on this screen

    /**
     * PURE: the two figures that appear inside the explanations are injected rather than read from the
     * container, so the dictionary can be exercised — and its wording asserted — without booting Laravel.
     * Quoting a threshold that has drifted from the real config would be its own kind of magic number.
     */
    public function __construct(
        private int $comebackWindowDays = 90,
        private float $specializationFullShare = 0.40,
        private string $costLines = 'thousands of',
    ) {
    }

    /**
     * @return array<string, array{label:string, kind:string, short:string, means:string, method:string, source:string, caveat:?string}>
     */
    public function all(): array
    {
        $window = $this->comebackWindowDays;
        $tol = $this->specializationFullShare * 100;

        return [
            'match_score' => $this->def(
                'Match score', self::DERIVED,
                'How well this garage fits this ticket, out of 100.',
                'How well this garage fits THIS ticket, out of 100. It is about capability only — never cost or availability.',
                'Five components added together: Fault matching 40 + Vehicle similarity 20 + Historical success 20 + Specialization 10 + Confidence 10. A component that cannot be measured is dropped and its points are shared out over the rest, so the total is always out of 100.',
                'Your maintenance history: past repairs at this garage, matched against this vehicle and these faults.',
                'It deliberately excludes price and waiting time. Those are shown separately as business factors.',
            ),

            'criticality_weight' => $this->def(
                'Fault priority (×)', self::POLICY,
                'How much this fault outweighs the others when choosing the garage.',
                'How much this fault is allowed to influence the recommendation, relative to the others on the ticket.',
                'A fixed multiplier per fault category — Safety critical ×1.5, Major mechanical ×1.3, Operational ×1.1, Cosmetic ×0.6 — applied when the per-fault scores are averaged. The inspector marking a fault severe can raise it one tier, never lower it.',
                'config/garage_recommendation.php → criticality. Set by FleetView policy.',
                'This is a BUSINESS RULE, not something learned from the data. It is a deliberate choice that brake work should outweigh paintwork, and it can be changed without touching code.',
            ),

            'coverage_pct' => $this->def(
                'Fault experience', self::MEASURED,
                'How much proven history this garage has with this exact fault on this model.',
                'How well this garage\'s history covers THIS specific fault on THIS model — the strength of the evidence, not a count.',
                'Graded on a ladder, then scored 0–100%: repairs of this fault on this exact model score highest (35–100%), the same fault on other models next (20–55%), and a garage with no history in this area at all scores at most 10%. Within each band the score rises with the number of repairs.',
                'Past repairs at this garage, matched by fault category and vehicle model.',
                'It is a graded score, not a raw percentage of jobs. Two garages with one repair each can score differently if one was on this model.',
            ),

            'specialization_pct' => $this->def(
                'Specialization', self::MEASURED,
                'Share of this garage\'s classified repairs that sit in these fault areas.',
                'How much of this garage\'s own work sits in the fault areas on this ticket — a focused specialist versus a busy generalist.',
                "This garage's repairs in these fault areas ÷ its repairs that we could classify at all. Roughly {$tol}% counts as a full specialist.",
                'All repairs recorded against this garage that carry an identifiable fault category.',
                'The denominator is only the CLASSIFIED repairs, not everything the garage has ever done — most older records have no usable fault label, and including them would understate every garage.',
            ),

            // These two are ONE measurement seen from both sides, and they must say so. Read as
            // independent figures, "success 55%" beside "comeback 44%" looks like a garage that fixes
            // barely half of what it touches; they are in fact complements that always total 100%.
            'success_pct' => $this->def(
                'Predicted first-time resolution', self::FORECAST,
                "How often a repair here holds — the same fault does not return within {$window} days.",
                "How often a repair at this garage holds, so the car does not have to come back for the same fault. This is the mirror image of the repeat-repair figure beside it: the two ALWAYS add up to 100%, they are not two separate measurements.",
                "100% minus the repeat-repair rate. A repair counts as resolved when the same fault signature does NOT reappear on the same vehicle within {$window} days.",
                "Signature history for repairs attributed to this garage, over a {$window}-day window.",
                'This is a proxy. It measures "did not come back", not "was fixed correctly" — a car that is sold, written off or simply not driven also never comes back.',
            ),

            'comeback_pct' => $this->def(
                "Repeat repair probability ({$window} days)", self::FORECAST,
                "How often the same fault comes back within {$window} days of a repair here.",
                "How often the same fault returns after this garage repairs it. This is the mirror image of first-time resolution beside it — the two ALWAYS add up to 100%, so a high figure here is the same news as a low figure there, not a second problem.",
                "The share of this garage's repairs where the same fault signature reappeared on the same vehicle within {$window} days. Body and rim damage are excluded.",
                "Repair signatures joined to the garage that did the work; {$window}-day window.",
                'Exposure damage (bodywork, rims) is excluded on purpose: customers keep scraping cars, and counting that would punish garages for something no workshop can influence.',
            ),

            'duration_days' => $this->def(
                'Expected duration', self::FORECAST,
                'The typical number of days the car is off the road at this garage.',
                'The typical number of days the car is off the road for a repair at this garage.',
                'The median of this garage\'s completed repairs, measured from the day the car went out to the day it came back. Median, not average, so one disputed 100-day job cannot distort it.',
                'Completed maintenance records with both a sent and a returned date.',
                'Our own accuracy testing shows this figure runs LOW — see the risk-adjusted figure beside it for a realistic worst case.',
            ),

            'duration_p90' => $this->def(
                'Risk-adjusted duration', self::FORECAST,
                'The bad-day case: 9 in 10 repairs here finish within this many days.',
                'A realistic worst case: 9 out of 10 repairs at this garage finish within this many days.',
                'The 90th percentile of the same completed repairs used for the expected duration.',
                'Completed maintenance records with both a sent and a returned date.',
                'Shown separately rather than as a range because repair times are heavily skewed — the typical case and the bad case are different planning questions.',
            ),

            'cost_aed' => $this->def(
                'Estimated cost', self::FORECAST,
                'What this repair is likely to bill, from money actually spent on past repairs.',
                'What this job is likely to cost at this garage. Where the history allows, it is the sum of a price for each fault; where it does not, it falls back to what this garage bills across all its work, then to the fleet. The line under the figure always says which of those you are looking at.',
                "Repairs are rarely invoiced into FleetView directly, so cost is rebuilt from the vehicle expense ledger: everything booked against this car in the window around the day it went to the garage, once fuel, insurance, registration, fines, trackers and washing have been removed. The estimate then uses the most specific history available — this garage on this fault on this model, then this garage on this fault, then this garage overall, then the fleet.",
                "The vehicle expense ledger ({$this->costLines} repair lines after exclusions), matched to past tickets by vehicle and date.",
                'This is ATTRIBUTED, not invoiced. It sums everything spent on the car in that window, so two repairs running together merge into one figure. Checked against the few hundred repairs whose true cost we do know: about half land within 10%, and the typical miss is around 11%. Treat it as a budget expectation, not a quotation.',
            ),

            'fault_cost' => $this->def(
                'Cost for this fault', self::FORECAST,
                "What this garage has historically billed for this kind of fault.",
                'What this garage has typically billed for repairs of this fault category — on this model where there is enough history for that, otherwise across models.',
                "The median spend on past single-fault repairs of this category at this garage. Only single-fault visits count: a ticket covering three problems cannot tell us what any one of them cost, and splitting it evenly would invent the answer.",
                'The vehicle expense ledger, matched to past tickets by vehicle and date, restricted to tickets with exactly one identified fault.',
                'It appears only where the history earns it. If a garage has no priced record for a fault category, no figure is shown for it at all rather than one borrowed from its other work.',
            ),

            'confidence' => $this->def(
                'Confidence', self::DERIVED,
                'How much evidence is behind a figure, and whether it is about this garage at all.',
                'How much evidence sits behind a figure, and whether that evidence is about this garage or borrowed from the fleet.',
                'From the number of matching records and where they came from: a garage-specific figure with 30+ records is High, fewer is Medium or Low, and anything falling back to a fleet average is always Low.',
                'The record counts shown under each figure.',
                'A fleet average can be precise and still be Low confidence — it is a reliable answer to a question about the whole fleet, not about this garage.',
            ),

            'queue_open' => $this->def(
                'Cars in queue', self::MEASURED,
                'How many of our vehicles this garage currently has open.',
                'How many vehicles this garage currently has open with us.',
                'A live count of tickets at this garage that are not yet closed.',
                'Current open maintenance tickets.',
                'It counts only OUR cars. A garage may be busy with other customers in ways we cannot see.',
            ),

            'start_in_days' => $this->def(
                'Can start', self::DERIVED,
                'Roughly how soon this garage could begin work, judged from its queue.',
                'Roughly how soon this garage could begin work.',
                'Estimated from the queue: each car already open at the garage adds about half a day before a new one is looked at.',
                'The live open-ticket count.',
                'An estimate from queue depth, not a commitment from the garage. No garage has told us its actual lead time.',
            ),

            'business_score' => $this->def(
                'Business score', self::DERIVED,
                'How this garage compares on cost, speed and availability against this shortlist.',
                'How this garage compares commercially with the others on this shortlist — cost, speed and availability.',
                'Cost 40%, expected duration 35%, availability 25%, each scored relative to the best and worst on the shortlist.',
                'The forecast figures above, for the shortlisted garages only.',
                'Relative, not absolute: it says "cheaper than these alternatives", not "cheap". Figures that fall back to a fleet average are excluded, because a number every garage shares cannot be an advantage.',
            ),

            'plan_confidence' => $this->def(
                'Plan confidence', self::DERIVED,
                'How well the plan covers its WEAKEST fault — never the average.',
                'How well a plan covers the WEAKEST fault on the ticket.',
                'The lowest fault-coverage score in the plan — never the average.',
                'The per-fault coverage figures.',
                'Deliberately the weakest link: averaging a well-covered fault with an uncovered one hides exactly the risk worth seeing.',
            ),
        ];
    }

    /** @return array{label:string, kind:string, short:string, means:string, method:string, source:string, caveat:?string} */
    private function def(string $label, string $kind, string $short, string $means, string $method, string $source, ?string $caveat = null): array
    {
        return compact('label', 'kind', 'short', 'means', 'method', 'source', 'caveat');
    }
}
