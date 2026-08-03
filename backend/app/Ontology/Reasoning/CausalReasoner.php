<?php

namespace App\Ontology\Reasoning;

use App\Models\FindingKeyword;
use App\Models\OntologyEdge;
use App\Models\OntologyNode;
use App\Ontology\Confidence\ConfidenceVector;
use App\Services\OntologyGraphService;
use App\Support\VehicleScope;
use Illuminate\Support\Facades\DB;

/**
 * "This fault is present. What is actually causing it?"
 *
 * The graph already stored causal relationships. What it could not do was WEIGH them: every cause of
 * overheating was listed with equal weight, which is a catalogue, not reasoning. This class turns the
 * list into a ranked hypothesis set — probability, evidence and confidence per candidate.
 *
 * WHERE PROBABILITY COMES FROM. Two independent inputs, kept separate because they are not equally
 * trustworthy:
 *
 *   PRIOR      the curated catalogue asserts the cause is possible. Uniform by design — the
 *              catalogue lists causes without ranking them, and inventing a spread would fabricate
 *              precision the source does not have.
 *   FLEET LIFT many root causes are themselves faults we track ("Coolant leak" causes overheating
 *              AND is a concept in its own right). For those, our own history says how often the two
 *              actually appear together, which is measured evidence about OUR cars.
 *
 * The lift is multiplicative on the prior, then the set is normalised so probabilities sum to 100 —
 * these are competing explanations for one fault, not independent events.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO. It does not invent a probability when there is no evidence for
 * one. A fault whose causes are all unmeasured returns a flat distribution and says so, in both the
 * confidence score (one dimension, capped) and the evidence text. A flat distribution honestly
 * labelled is useful; a confident-looking ranking built from nothing teaches people to ignore the
 * ranking everywhere else.
 */
class CausalReasoner
{
    /** Below this many co-occurrences, fleet history is an anecdote and is reported but not used to rank. */
    private const MIN_OBSERVATIONS = 3;

    /** Ceiling on how far measured history may multiply a cause's prior. */
    private const MAX_LIFT = 3.0;

    public function __construct(private readonly OntologyGraphService $graph)
    {
    }

    /**
     * Rank the probable causes of a fault.
     *
     * @param  array<int,string>  $scopeChain  from VehicleScope::chain()
     * @return array<int,ProbableCause>
     */
    public function causesOf(FindingKeyword $fault, array $scopeChain = [VehicleScope::UNIVERSAL], int $limit = 8): array
    {
        $node = OntologyNode::query()
            ->active()
            ->where('finding_keyword_id', $fault->id)
            ->ofType(OntologyNode::TYPE_FAULT)
            ->inScope($scopeChain)
            ->first();

        if (! $node) {
            return [];
        }

        $edges = OntologyEdge::query()
            ->active()
            ->where('from_node_id', $node->id)
            ->relation(OntologyEdge::REL_CAUSED_BY)
            ->inScope($scopeChain)
            ->with('to')
            ->get()
            ->filter(fn (OntologyEdge $e) => $e->to !== null);

        if ($edges->isEmpty()) {
            return [];
        }

        $lift = $this->fleetLift($fault, $edges);

        // Score each candidate before normalising — probability is only meaningful relative to the
        // rest of the set, so nothing can be finalised until every candidate has been weighed.
        $scored = $edges->map(function (OntologyEdge $edge) use ($fault, $lift) {
            $causeKeywordId = $edge->to->finding_keyword_id;
            $observed = $lift[$causeKeywordId]['count'] ?? 0;

            $prior = max(1.0, $edge->rank());
            $multiplier = $observed >= self::MIN_OBSERVATIONS
                ? min(self::MAX_LIFT, 1.0 + log10(1 + $observed))
                : 1.0;

            return [
                'edge'     => $edge,
                'raw'      => $prior * $multiplier,
                'observed' => $observed,
                'together' => $lift[$causeKeywordId]['together'] ?? null,
                'fault'    => $fault,
            ];
        })->sortByDesc('raw')->take($limit)->values();

        $total = max(0.001, $scored->sum('raw'));

        return $scored
            ->map(fn (array $row) => $this->toCause($row, $total))
            ->all();
    }

    /**
     * Convenience: reason from free text rather than a resolved concept.
     *
     * @return array{fault:?FindingKeyword,causes:array<int,ProbableCause>}
     */
    public function causesOfText(string $text, array $scopeChain = [VehicleScope::UNIVERSAL], int $limit = 8): array
    {
        /** @var \App\Services\KeywordOntologyService $matcher */
        $matcher = app(\App\Services\KeywordOntologyService::class);

        // FAULT LANE — this returns the fault whose CAUSES are then reasoned about. A scheduled service
        // has no causal story to tell ("caused by: the odometer reached the service point"), so letting
        // one win here would produce a confident, useless diagnosis (audit H6).
        $best = $matcher->resolve($text, [
            'limit' => 1, 'scope' => $scopeChain, 'kinds' => [\App\Models\MaintenanceTask::KIND_FAULT],
        ])->first();

        if (! $best) {
            return ['fault' => null, 'causes' => []];
        }

        return [
            'fault'  => $best['keyword'],
            'causes' => $this->causesOf($best['keyword'], $scopeChain, $limit),
        ];
    }

    /**
     * How often each candidate cause has actually accompanied this fault on our own vehicles.
     *
     * Only causes that are themselves tracked concepts can be measured this way — "Coolant leak" can,
     * "Radiator blockage / scaling" cannot, because nothing in the fleet record ever names it. That
     * asymmetry is real and is surfaced rather than smoothed over: some causes carry measured
     * evidence and some carry only the catalogue, and the technician should know which is which.
     *
     * @param  \Illuminate\Support\Collection<int,OntologyEdge>  $edges
     * @return array<int,array{count:int,together:int}>  keyed by the cause's finding_keyword_id
     */
    private function fleetLift(FindingKeyword $fault, $edges): array
    {
        $causeKeywordIds = $edges
            ->pluck('to.finding_keyword_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($causeKeywordIds === []) {
            return [];
        }

        // The fleet miner records co-occurrence as `related_to` edges between fault nodes, with
        // observed_count on them. Read that rather than re-mining: the miner already applied the
        // exclusions that make the number meaningful (routine servicing, exposure damage).
        $rows = DB::table('ontology_edges as e')
            ->join('ontology_nodes as a', 'a.id', '=', 'e.from_node_id')
            ->join('ontology_nodes as b', 'b.id', '=', 'e.to_node_id')
            ->where('e.relation', OntologyEdge::REL_RELATED_TO)
            ->where('e.source', OntologyEdge::SOURCE_FLEET)
            ->where('e.is_active', true)
            ->where('a.finding_keyword_id', $fault->id)
            ->whereIn('b.finding_keyword_id', $causeKeywordIds)
            ->get(['b.finding_keyword_id as cause_id', 'e.observed_count', 'e.observed_rate']);

        $lift = [];
        foreach ($rows as $row) {
            $lift[(int) $row->cause_id] = [
                'count'    => (int) $row->observed_count,
                'together' => (int) $row->observed_rate,
            ];
        }

        return $lift;
    }

    /** Assemble one ranked candidate, with its confidence decomposed and its reasoning spelled out. */
    private function toCause(array $row, float $total): ProbableCause
    {
        /** @var OntologyEdge $edge */
        $edge = $row['edge'];
        $observed = (int) $row['observed'];

        $confidence = new ConfidenceVector();
        $evidence = [];

        // --- what the catalogue says -------------------------------------------------------------
        if ($edge->source === OntologyEdge::SOURCE_HUMAN) {
            $confidence->add('human', 90, 'listed as a cause by your workshop');
            $evidence[] = 'Your workshop recorded this as a cause of '.$row['fault']->keyword.'.';
        } else {
            $confidence->add('documentation', (float) $edge->confidence, 'in the curated symptom → root-cause catalogue');
            $evidence[] = 'Listed in the standard symptom → root-cause catalogue.';
        }

        // --- what our own cars say ---------------------------------------------------------------
        if ($observed >= self::MIN_OBSERVATIONS) {
            $score = min(95, 35 + log10(max(1, $observed)) * 30);
            $confidence->add('fleet', $score, "{$observed} case(s) in our own history");

            $rate = $row['together'];
            $evidence[] = $rate
                ? "Seen together with {$row['fault']->keyword} on {$observed} of our own repairs ({$rate}% of that fault's visits)."
                : "Seen together with {$row['fault']->keyword} on {$observed} of our own repairs.";
        } elseif ($observed > 0) {
            // Reported, not ranked on — see MIN_OBSERVATIONS.
            $evidence[] = "Only {$observed} case(s) in our history — too few to affect the ranking.";
        } else {
            $evidence[] = 'No measured cases in our own history for this cause.';
        }

        if ($edge->note) {
            $evidence[] = $edge->note;
        }

        return new ProbableCause(
            nodeId: $edge->to->id,
            label: $edge->to->label,
            labelAr: $edge->to->label_ar,
            probability: (int) round(($row['raw'] / $total) * 100),
            confidence: $confidence,
            evidence: $evidence,
            provenance: $edge->source,
            findingKeywordId: $edge->to->finding_keyword_id,
            observedCount: $observed,
        );
    }
}
