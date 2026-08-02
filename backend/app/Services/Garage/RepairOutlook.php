<?php

namespace App\Services\Garage;

use App\Models\FindingKeyword;
use App\Services\MatchExplanationService;
use App\Support\TextNormalizer;
use App\Support\VehicleScope;

/**
 * WHAT THE GARAGE WILL ACTUALLY DO — the inspector's findings, answered with the work they imply.
 *
 * The supervisor assigning a garage sees which shop is strongest per fault, what it costs and how long
 * the car is off the road. What they could not see is the thing they are actually buying: the WORK.
 * "Rough idle / misfire" tells an inspector plenty and a supervisor almost nothing — is that an
 * afternoon on spark plugs or a week on an injector? Without it, the choice between two garages is
 * made on numbers whose subject is invisible.
 *
 * This reads that work off the fault ontology the inspector's finding already resolved to: the
 * concept's likely causes ([[KeywordProfile]]::likely_causes) and its normalised repair actions
 * ([[ActionCatalog]] via fault_concept_actions, 'typical' before 'possible').
 *
 * IT IS TYPICAL WORK, NOT A DIAGNOSIS. Nobody has opened this car. These are the causes and repairs
 * that usually sit behind this fault across the fleet and the trade, and the UI must say so — a
 * supervisor reading "Replace spark plugs" as a decision already taken is exactly the failure this
 * would otherwise introduce. The engine's job here is to set expectations, not to prescribe.
 *
 * EXACT VOCABULARY MATCH ONLY. A finding is matched to a concept by normalised name and nothing else.
 * There is a strong temptation to fall back to the fuzzy matcher for hand-written findings, and it is
 * the wrong call on this surface: a plausible-but-wrong repair list shown to the person authorising
 * the work is worse than no list, because it reads as fact rather than as a suggestion. A finding with
 * no concept behind it is reported as such and the supervisor asks the garage. The fuzzy matcher
 * belongs at CAPTURE time, where an inspector confirms it ([[FindingsAiSuggestion]]) — by the time it
 * reaches here it is already vocabulary.
 *
 * @see \App\Services\GarageRecommendationService
 */
class RepairOutlook
{
    /** Enough to set expectations; more turns a decision aid into a repair manual. */
    private const MAX_CAUSES = 3;
    private const MAX_FIXES  = 4;

    /**
     * Resolved through the container rather than newed: MatchExplanationService has its own dependency
     * (the graph service), and hard-coding its construction here would make this class break every time
     * that one gains a collaborator.
     */
    public function __construct(private ?MatchExplanationService $explainer = null)
    {
        $this->explainer = $explainer ?? app(MatchExplanationService::class);
    }

    /**
     * @param  array<int,array{symptom:?string,category_key:?string,label?:?string}>  $faultsDetail
     * @param  array<int,string>  $scopeChain  vehicle scope, narrowest last — see [[VehicleScope]]
     * @return array<int,array<string,mixed>>
     */
    public function for(array $faultsDetail, array $scopeChain = []): array
    {
        $symptoms = [];

        foreach ($faultsDetail as $detail) {
            $symptom = trim((string) ($detail['symptom'] ?? ''));

            if ($symptom !== '') {
                // Keyed by normalised symptom: the same fault logged twice on one ticket is one row of
                // expected work, not two.
                $symptoms[TextNormalizer::key($symptom)] ??= $detail;
            }
        }

        if ($symptoms === []) {
            return [];
        }

        $concepts = $this->conceptsFor(array_keys($symptoms));

        $out = [];

        foreach ($symptoms as $key => $detail) {
            $concept = $concepts[$key] ?? null;

            $out[] = [
                'symptom'      => $detail['symptom'],
                'category_key' => $detail['category_key'] ?? null,
                'label'        => $detail['label'] ?? null,

                // Distinguishes "we have nothing for this fault" from "this fault has no known causes",
                // which are different statements and must not render as the same empty list.
                'known'        => $concept !== null,

                'risk'         => $concept?->risk,
                'risk_label'   => $concept ? FindingKeyword::riskMeta($concept->risk)['label'] : null,
                'risk_tone'    => $concept ? FindingKeyword::riskMeta($concept->risk)['tone'] : null,

                'causes'       => $concept ? self::causesOf($concept) : [],
                'fixes'        => $concept ? self::fixesOf($concept) : [],

                // WHAT THIS FLEET'S OWN RECORDS SAY — the one part of this card that is measured
                // rather than curated, mined from ~8k historical repairs into the ontology graph.
                //
                // It is here because it changes a dispatch decision in a way the causes and repairs
                // above do not: "34% of 72 alignment jobs also involved engine noise" tells a
                // supervisor the job may grow, which is exactly what they need before choosing a
                // garage and promising the car back.
                //
                // Emitted as DATA, never as a sentence. The same rows drive the admin explanation,
                // which phrases them in English; this surface has to say it in Arabic too, and an
                // engine that returns prose cannot ([[reason-code-contract]]).
                'history'      => $concept
                    ? $this->explainer->fleetHistory($concept, $scopeChain !== [] ? $scopeChain : [VehicleScope::UNIVERSAL], 2)->all()
                    : [],
            ];
        }

        return $out;
    }

    /**
     * What a fault is usually caused by — the ontology's answer, trimmed to what fits on a card.
     *
     * Static and public because the SAME two lists are now read in three places: the supervisor's
     * dispatch plan, the inspector's findings picker (via the findings catalog) and the AI suggestion
     * card. How many to show and which repairs come first is a product decision, and three copies of
     * it would answer the same question three different ways on three screens.
     *
     * @return array<int,string>
     */
    public static function causesOf(FindingKeyword $concept): array
    {
        return array_slice((array) ($concept->profile?->likely_causes ?? []), 0, self::MAX_CAUSES);
    }

    /**
     * …and what usually fixes it, typical repairs first.
     *
     * @return array<int,array{label:string,label_ar:?string,typical:bool}>
     */
    public static function fixesOf(FindingKeyword $concept): array
    {
        return $concept->repairActions
            // 'typical' is the couple of repairs that usually fix it; 'possible' is the rest. Position
            // in the ontology files sets it, so the order is a person's judgement rather than a score.
            ->sortBy(fn ($action) => $action->pivot->relevance === 'typical' ? 0 : 1)
            ->take(self::MAX_FIXES)
            ->map(fn ($action) => [
                'label'    => $action->label,
                'label_ar' => $action->label_ar,
                'typical'  => $action->pivot->relevance === 'typical',
            ])
            ->values()
            ->all();
    }

    /**
     * Load every concept behind this ticket's findings in one pass.
     *
     * Matched in PHP rather than SQL because normalisation is [[TextNormalizer]]'s rules (Arabic marks,
     * punctuation, spacing), not the database collation's — the two disagree, and the collation is the
     * one that would silently miss.
     *
     * @param  array<int,string>  $normalisedKeys
     * @return array<string,FindingKeyword>
     */
    private function conceptsFor(array $normalisedKeys): array
    {
        $wanted = array_flip($normalisedKeys);

        return FindingKeyword::query()
            ->with(['profile:id,finding_keyword_id,likely_causes', 'repairActions:id,label,label_ar', 'ontologyNode'])
            ->get(['id', 'keyword', 'risk'])
            ->reduce(function (array $carry, FindingKeyword $keyword) use ($wanted) {
                $key = TextNormalizer::key($keyword->keyword);

                if (isset($wanted[$key])) {
                    $carry[$key] = $keyword;
                }

                return $carry;
            }, []);
    }
}
