<?php

namespace App\Ontology\Retrieval;

use App\Models\EvidenceLink;
use App\Models\OntologyEdge;
use App\Ontology\Contracts\KnowledgeRetriever;
use App\Ontology\DTO\RetrievedPassage;
use App\Services\KeywordOntologyService;
use App\Support\VehicleScope;

/**
 * Our own maintenance history, as a retrieval source.
 *
 * THIS IS THE POINT OF THE INTERFACE. Fleet history is not a post-processing enhancement bolted
 * onto documentation — it implements the same [[KnowledgeRetriever]] contract as a Bosch manual,
 * gets merged in the same pass, cited in the same table, and feeds the confidence blend as its own
 * independent dimension. For questions about OUR cars it is frequently the *best* source in the
 * system: measured on this fleet, in this climate, with these drivers and these garages.
 *
 * What it returns are not documents but findings, rendered as prose the model can read and the UI
 * can cite: "In our own maintenance history, 69% of 94 recorded cases of Oil leak also involved
 * Engine noise." Those come from the fleet-sourced edges that [[FleetEvidenceService]] mined, so
 * every passage is a real count over real tickets, never an estimate.
 *
 * Availability is honest: with no mined edges yet, `isAvailable()` is false and the source simply
 * contributes nothing rather than emitting weak filler.
 */
class FleetRetriever implements KnowledgeRetriever
{
    /** Below this sample size a pattern is an anecdote — not worth grounding an answer on. */
    private const MIN_OBSERVATIONS = 5;

    public function __construct(private readonly KeywordOntologyService $ontology)
    {
    }

    public function key(): string
    {
        return 'fleet';
    }

    public function label(): string
    {
        return 'Our maintenance history';
    }

    public function confidenceDimension(): string
    {
        return 'fleet';
    }

    public function isAvailable(): bool
    {
        return OntologyEdge::query()->where('source', OntologyEdge::SOURCE_FLEET)->exists();
    }

    /** @return array<int,RetrievedPassage> */
    public function retrieve(string $query, array $scopeChain, int $limit): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        // Resolve the query to a fault concept through the ordinary matcher — the same path a
        // technician's search takes. If the ontology cannot recognise the query, we have no fleet
        // evidence to offer, and inventing a looser rule here would produce confident nonsense.
        $match = $this->ontology->resolve($query, ['limit' => 1, 'min_score' => 55])->first();
        $node = $match['keyword']->ontologyNode ?? null;

        if (! $node) {
            return [];
        }

        $edges = OntologyEdge::query()
            ->active()
            ->where('from_node_id', $node->id)
            ->where('source', OntologyEdge::SOURCE_FLEET)
            ->where('observed_count', '>=', self::MIN_OBSERVATIONS)
            ->inScope($scopeChain)
            ->with('to')
            ->orderByDesc('observed_count')
            ->limit($limit)
            ->get();

        return $edges
            ->filter(fn (OntologyEdge $e) => $e->to !== null)
            ->map(function (OntologyEdge $edge) use ($match) {
                $scope = VehicleScope::label($edge->scope_key);

                return new RetrievedPassage(
                    text: $this->render($match['keyword']->keyword, $edge, $scope),
                    source: $this->key(),
                    retrievalMethod: EvidenceLink::METHOD_FLEET,
                    // Confidence in a fleet observation is sample-driven: 5 cases is a hint, 50+ is
                    // a pattern. Capped below 1.0 because our history describes our fleet, not
                    // vehicles in general — it is the strongest evidence for us, not universal truth.
                    score: round(min(0.95, 0.45 + log10(max(1, $edge->observed_count)) * 0.28), 3),
                    title: 'FleetView maintenance history'.($scope ? " — {$scope}" : ''),
                    section: $edge->relation,
                    dimension: $this->confidenceDimension(),
                    meta: [
                        'observed_count' => $edge->observed_count,
                        'observed_rate'  => $edge->observed_rate,
                        'relation'       => $edge->relation,
                        'scope'          => $edge->scope_key,
                        // The concept on the other end of the edge. Carried so the semantic stage
                        // can pull a neighbouring fault into the candidate set without re-querying
                        // the graph — this is what lets fleet history contribute recall, not just
                        // confidence.
                        'related_label'      => $edge->to->label,
                        'related_keyword_id' => $edge->to->finding_keyword_id,
                    ],
                );
            })
            ->values()
            ->all();
    }

    /** Render an edge as a sentence — readable by the model, quotable by the UI. */
    private function render(string $fault, OntologyEdge $edge, ?string $scope): string
    {
        $where = $scope ? " on {$scope} vehicles" : '';

        $phrase = match ($edge->relation) {
            OntologyEdge::REL_FIXED_BY  => 'were resolved by',
            OntologyEdge::REL_CAUSED_BY => 'were traced to',
            default                     => 'also involved',
        };

        return "In our own maintenance history{$where}, {$edge->observed_rate}% of "
            ."{$edge->observed_count} recorded cases of \"{$fault}\" {$phrase} \"{$edge->to->label}\".";
    }
}
