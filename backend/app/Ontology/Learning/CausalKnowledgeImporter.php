<?php

namespace App\Ontology\Learning;

use App\Models\FaultCause;
use App\Models\FindingKeyword;
use App\Models\OntologyEdge;
use App\Models\OntologyNode;
use App\Services\OntologyGraphService;
use App\Support\TextNormalizer;
use App\Support\VehicleScope;

/**
 * Brings the curated symptom → root-cause catalogue into the knowledge graph.
 *
 * WHY THIS EXISTS. The reasoning layer was built to rank probable causes, and then had almost
 * nothing to rank: the graph held ONE causal edge, because the only writer producing them was the
 * fleet miner, and our history records what broke without ever recording why. Meanwhile
 * `fault_causes` already held 108 approved symptom→cause rows that people in this workshop wrote —
 * sitting in a picker table, invisible to every reasoning path in the platform.
 *
 * So the first move toward causal reasoning is not a smarter algorithm. It is connecting knowledge
 * the fleet already owns to the engine that can use it.
 *
 * PROVENANCE IS PRESERVED, NOT FLATTENED. Rows written by staff import as `human` edges, which
 * [[OntologyGraphService]] then refuses to let any later AI run overwrite. Seeded rows import as
 * `seed`. Neither is ever presented as measured fleet evidence — a plausible cause from a catalogue
 * and a cause observed 200 times are different claims, and the graph keeps them different.
 *
 * A CAUSE THAT IS ALSO A FAULT IS LINKED, NOT DUPLICATED. "Coolant leak" is both a root cause of
 * overheating and a fault we track in its own right. When a cause resolves to a known concept, its
 * node carries that concept's `finding_keyword_id`, so the cause inherits everything the platform
 * knows about it — vocabulary, fleet history, confidence. That is what later lets the reasoner cite
 * measured co-occurrence for some causes and only documentation for others.
 */
class CausalKnowledgeImporter
{
    public function __construct(private readonly OntologyGraphService $graph)
    {
    }

    /**
     * Import every approved cause.
     *
     * @return array{symptoms:int,causes:int,edges:int,linked:int,unmatched:array<int,string>}
     */
    public function import(bool $dryRun = false): array
    {
        $stats = ['symptoms' => 0, 'causes' => 0, 'edges' => 0, 'linked' => 0, 'unmatched' => []];

        // The concept list, indexed by normalised label. `fault_causes.symptom_label` was authored
        // from the same picker as `finding_keywords.keyword`, so this is an exact join in practice —
        // but normalising both sides means a stray capital or space can't silently drop a symptom.
        $concepts = FindingKeyword::query()
            ->get(['id', 'keyword', 'keyword_ar', 'category_key'])
            ->keyBy(fn (FindingKeyword $k) => TextNormalizer::key($k->keyword));

        $rows = FaultCause::query()
            ->approved()
            ->get()
            ->groupBy('symptom_label');

        foreach ($rows as $symptomLabel => $causes) {
            $concept = $concepts->get(TextNormalizer::key((string) $symptomLabel));

            if (! $concept) {
                // Recorded rather than skipped silently: an unmatched symptom is a real gap between
                // the catalogue and the concept list, and someone should see it.
                $stats['unmatched'][] = (string) $symptomLabel;

                continue;
            }

            $stats['symptoms']++;

            if ($dryRun) {
                $stats['causes'] += $causes->count();
                $stats['edges']  += $causes->count();

                continue;
            }

            $faultNode = $this->graph->nodeForKeyword($concept);

            foreach ($causes as $cause) {
                $causeNode = $this->causeNode($cause, $concepts);

                if (! $causeNode) {
                    continue;
                }

                $stats['causes']++;

                if ($causeNode->finding_keyword_id) {
                    $stats['linked']++;
                }

                $edge = $this->graph->upsertEdge(
                    $faultNode,
                    $causeNode,
                    OntologyEdge::REL_CAUSED_BY,
                    [
                        'source'     => $this->sourceFor($cause),
                        // Deliberately uniform. The catalogue lists causes without ranking them, and
                        // inventing a spread here would fabricate precision the source does not
                        // have. Differentiation comes later, from measured fleet evidence — see
                        // [[CausalReasoner]].
                        'weight'     => 50,
                        'confidence' => $cause->source === 'seed' ? 70 : 85,
                        'note'       => $cause->description ?: null,
                    ],
                    VehicleScope::UNIVERSAL,
                );

                if ($edge) {
                    $stats['edges']++;
                }
            }
        }

        return $stats;
    }

    /**
     * The node for a root cause — reusing the fault concept when the cause is one we already track.
     *
     * @param  \Illuminate\Support\Collection<string,FindingKeyword>  $concepts
     */
    private function causeNode(FaultCause $cause, $concepts): ?OntologyNode
    {
        $label   = trim((string) $cause->root_cause);
        $concept = $concepts->get(TextNormalizer::key($label));

        return $this->graph->upsertNode(
            OntologyNode::TYPE_CAUSE,
            $label,
            array_filter([
                'finding_keyword_id' => $concept?->id,
                'label_ar'           => $concept?->keyword_ar,
                'source'             => $this->sourceFor($cause),
                'confidence'         => $cause->source === 'seed' ? 75 : 90,
            ]),
        );
    }

    /**
     * Catalogue rows written by a person are human knowledge and must outrank later AI enrichment;
     * rows that shipped with the system are seed knowledge, which enrichment may refine.
     */
    private function sourceFor(FaultCause $cause): string
    {
        return $cause->submitted_by ? OntologyEdge::SOURCE_HUMAN : OntologyEdge::SOURCE_SEED;
    }
}
