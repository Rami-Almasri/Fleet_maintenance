<?php

namespace App\Services;

use App\Models\FindingKeyword;
use App\Models\OntologyEdge;
use App\Models\OntologyNode;
use App\Support\TextNormalizer;
use App\Support\VehicleScope;
use Illuminate\Support\Collection;

/**
 * Reading and writing the automotive knowledge graph.
 *
 * The graph answers questions a keyword table cannot:
 *   - "what usually causes this fault, ranked?"          → traverse caused_by, order by rank
 *   - "what else fails on this component?"               → reverse-traverse affects_component
 *   - "what parts will this repair need?"                → fault → fixed_by → requires_part
 *   - "in what order should I check things?"             → inspected_by, ordered by precedes
 *   - "what other faults look like this one?"            → shared symptom / component neighbours
 *
 * TWO DESIGN DECISIONS WORTH KNOWING
 *
 * 1. WRITES ARE IDEMPOTENT AND PROVENANCE-AWARE. `upsertEdge()` merges into an existing edge rather
 *    than duplicating it, and refuses to let a weaker source overwrite a stronger one — an AI run
 *    can never quietly flatten an edge a human corrected or the fleet measured. This is the same
 *    "humans win" contract the term layer has, extended to relationships.
 *
 * 2. TRAVERSAL IS BREADTH-FIRST AND BUDGETED. Real ontologies grow hub nodes ("Brake pads" ends up
 *    connected to everything), so an unbounded walk would return the whole graph. Every traversal
 *    takes a depth and a per-level fan-out cap, and decays edge rank with distance, so results stay
 *    relevant and the query stays bounded regardless of how dense the graph gets.
 */
class OntologyGraphService
{
    /** Per-level fan-out cap — see the class doc on hub nodes. */
    private const FAN_OUT = 12;

    // ---------------------------------------------------------------------------------------
    // Writing
    // ---------------------------------------------------------------------------------------

    /**
     * Find or create a node. Matching is on (type, normalised label, scope), so the same component
     * referenced by three different faults resolves to ONE node — which is what makes cross-fault
     * questions ("what else fails on the caliper?") answerable at all.
     */
    public function upsertNode(
        string $type,
        string $label,
        array $attributes = [],
        string $scopeKey = VehicleScope::UNIVERSAL,
    ): ?OntologyNode {
        $label = trim($label);
        $key   = TextNormalizer::key($label);

        if ($label === '' || $key === '' || ! in_array($type, OntologyNode::TYPES, true)) {
            return null;
        }

        $node = OntologyNode::firstOrNew([
            'type'      => $type,
            'key'       => $key,
            'scope_key' => $scopeKey,
        ]);

        // Never downgrade a human-curated node back to an AI label on a re-run.
        if ($node->exists && $node->source === 'human') {
            return $node;
        }

        $node->fill(array_merge([
            'label'      => $label,
            'source'     => 'ai',
            'confidence' => 70,
            'is_active'  => true,
        ], $attributes));

        // Re-derive the scope columns from the key we were given, so a node created with an
        // explicit scope string still has queryable make/model fields.
        if ($scopeKey !== VehicleScope::UNIVERSAL && blank($node->make)) {
            $parts = explode('|', $scopeKey);
            $node->make       = $parts[0] ?? null;
            $node->model      = $parts[1] ?? null;
            $node->generation = $parts[2] ?? null;
        }

        $node->save();

        return $node;
    }

    /** The graph entry point for a fault: the node that mirrors a findings keyword. */
    public function nodeForKeyword(FindingKeyword $keyword): OntologyNode
    {
        $node = $this->upsertNode(OntologyNode::TYPE_FAULT, $keyword->keyword, [
            'finding_keyword_id' => $keyword->id,
            'label_ar'           => $keyword->keyword_ar,
            'source'             => 'seed',
            'confidence'         => 100,
        ]);

        // upsertNode only returns null for an unusable label, which a keyword cannot have.
        return $node;
    }

    /**
     * Create or strengthen a relationship.
     *
     * Merge rules, in priority order — this is where "the graph never loses a correction" lives:
     *  - a human edge is never modified by anything else;
     *  - a fleet edge is never overwritten by an AI edge (measured beats asserted), but a newer
     *    fleet run does update its own counters;
     *  - otherwise the incoming values win, so re-enrichment refreshes weights.
     */
    public function upsertEdge(
        OntologyNode $from,
        OntologyNode $to,
        string $relation,
        array $attributes = [],
        string $scopeKey = VehicleScope::UNIVERSAL,
    ): ?OntologyEdge {
        if (! in_array($relation, OntologyEdge::RELATIONS, true) || $from->id === $to->id) {
            return null;    // self-edges carry no information and break traversal termination
        }

        $edge = OntologyEdge::firstOrNew([
            'from_node_id' => $from->id,
            'to_node_id'   => $to->id,
            'relation'     => $relation,
            'scope_key'    => $scopeKey,
        ]);

        $incoming = $attributes['source'] ?? OntologyEdge::SOURCE_AI;

        if ($edge->exists) {
            if ($edge->source === OntologyEdge::SOURCE_HUMAN && $incoming !== OntologyEdge::SOURCE_HUMAN) {
                return $edge;   // a person decided this; leave it alone
            }
            if ($edge->source === OntologyEdge::SOURCE_FLEET && $incoming === OntologyEdge::SOURCE_AI) {
                return $edge;   // measured beats asserted
            }
        }

        $edge->fill(array_merge([
            'weight'     => 50,
            'confidence' => 70,
            'source'     => OntologyEdge::SOURCE_AI,
            'is_active'  => true,
        ], $attributes));

        $edge->save();

        return $edge;
    }

    // ---------------------------------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------------------------------

    /**
     * The immediate neighbourhood of a node, grouped by relation and ranked within each group.
     * This is what the knowledge drawer renders: causes, repairs, components, procedures, each
     * already in the order a technician should consider them.
     *
     * @param  array<int,string>  $scopeChain  from VehicleScope::chain() — universal + this vehicle
     * @return array<string,array<int,array>>  relation => [ {node, weight, source, observed…}, … ]
     */
    public function neighborhood(OntologyNode $node, array $scopeChain = [VehicleScope::UNIVERSAL], ?array $relations = null): array
    {
        $edges = OntologyEdge::query()
            ->active()
            ->where('from_node_id', $node->id)
            ->when($relations, fn ($q) => $q->relation($relations))
            ->inScope($scopeChain)
            ->with('to')
            ->get()
            ->filter(fn (OntologyEdge $e) => $e->to !== null);

        return $edges
            ->sortByDesc(fn (OntologyEdge $e) => $e->rank())
            ->groupBy('relation')
            ->map(fn (Collection $group) => $group->take(self::FAN_OUT)->map(fn (OntologyEdge $e) => [
                'id'             => $e->to->id,
                'edge_id'        => $e->id,
                'label'          => $e->to->label,
                'label_ar'       => $e->to->label_ar,
                'type'           => $e->to->type,
                'weight'         => $e->weight,
                'confidence'     => $e->confidence,
                'source'         => $e->source,
                'observed_count' => $e->observed_count,
                'observed_rate'  => $e->observed_rate,
                'scope'          => VehicleScope::label($e->scope_key),
                'rank'           => round($e->rank(), 1),
                'note'           => $e->note,
            ])->values()->all())
            ->all();
    }

    /**
     * Walk outward from a node, breadth-first, with rank decaying by distance. Used to answer
     * "what is connected to this, however indirectly?" — the input to a semantic-style expansion
     * where a symptom pulls in the components and repairs that surround it.
     *
     * @return Collection<int,array>  {node, depth, score, via} ordered by score
     */
    public function traverse(OntologyNode $start, int $depth = 2, array $scopeChain = [VehicleScope::UNIVERSAL], ?array $relations = null): Collection
    {
        $seen    = [$start->id => true];
        $results = [];
        $frontier = [['node_id' => $start->id, 'score' => 100.0, 'via' => null]];

        for ($level = 1; $level <= $depth && $frontier !== []; $level++) {
            $edges = OntologyEdge::query()
                ->active()
                ->whereIn('from_node_id', array_column($frontier, 'node_id'))
                ->when($relations, fn ($q) => $q->relation($relations))
                ->inScope($scopeChain)
                ->with('to')
                ->get();

            $byParent = collect($frontier)->keyBy('node_id');
            $next = [];

            foreach ($edges->sortByDesc(fn (OntologyEdge $e) => $e->rank()) as $edge) {
                if (! $edge->to || isset($seen[$edge->to_node_id])) {
                    continue;
                }

                $parent = $byParent->get($edge->from_node_id);
                // Decay per level: a second-hop relationship is real but weaker evidence than a
                // direct one, and without decay a hub node's whole neighbourhood outranks the
                // fault's own direct causes.
                $score = ($parent['score'] ?? 100) * ($edge->rank() / 100) * 0.65;

                if ($score < 5) {
                    continue;   // below this the connection is noise
                }

                $seen[$edge->to_node_id] = true;
                $entry = [
                    'node'  => $edge->to,
                    'depth' => $level,
                    'score' => round($score, 1),
                    'via'   => ['relation' => $edge->relation, 'from_node_id' => $edge->from_node_id],
                ];
                $results[] = $entry;
                $next[] = ['node_id' => $edge->to_node_id, 'score' => $score, 'via' => $edge->relation];

                if (count($next) >= self::FAN_OUT * $level) {
                    break;
                }
            }

            $frontier = $next;
        }

        return collect($results)->sortByDesc('score')->values();
    }

    /**
     * The diagnostic answer for a fault: ranked causes, the checks that confirm them, the repairs
     * that fix them, and the parts those repairs consume — in one shaped payload.
     *
     * This is the structure a maintenance recommendation is eventually built from, which is why it
     * carries the fleet counters through rather than flattening everything to a single number:
     * "replace pads — 92% of 214 of our own cases" is a different claim from "replace pads —
     * documented repair", and a supervisor deserves to see which one they're being told.
     */
    public function diagnosticProfile(OntologyNode $fault, array $scopeChain = [VehicleScope::UNIVERSAL]): array
    {
        $n = $this->neighborhood($fault, $scopeChain);

        return [
            'symptoms'   => $n[OntologyEdge::REL_PRESENTS_AS]  ?? [],
            'components' => $n[OntologyEdge::REL_AFFECTS]      ?? [],
            'causes'     => $n[OntologyEdge::REL_CAUSED_BY]    ?? [],
            'repairs'    => $this->withRequirements($n[OntologyEdge::REL_FIXED_BY] ?? [], $scopeChain),
            'inspection' => $n[OntologyEdge::REL_INSPECTED_BY] ?? [],
            'related'    => $n[OntologyEdge::REL_RELATED_TO]   ?? [],
        ];
    }

    /**
     * Hang each repair's parts / tools / skills off it — one extra query for the whole set rather
     * than a neighbourhood call per repair.
     */
    private function withRequirements(array $repairs, array $scopeChain): array
    {
        if ($repairs === []) {
            return [];
        }

        $edges = OntologyEdge::query()
            ->active()
            ->whereIn('from_node_id', array_column($repairs, 'id'))
            ->relation([OntologyEdge::REL_REQUIRES_PART, OntologyEdge::REL_REQUIRES_TOOL, OntologyEdge::REL_REQUIRES_SKILL])
            ->inScope($scopeChain)
            ->with('to')
            ->get()
            ->groupBy('from_node_id');

        return collect($repairs)->map(function (array $repair) use ($edges) {
            $mine = $edges->get($repair['id'], collect());

            $repair['requires'] = $mine
                ->filter(fn ($e) => $e->to !== null)
                ->groupBy('relation')
                ->map(fn ($g) => $g->pluck('to.label')->values()->all())
                ->all();

            return $repair;
        })->all();
    }

    /** Resolve free text to an existing node — the bridge from a technician's words into the graph. */
    public function findNode(string $text, ?string $type = null, array $scopeChain = [VehicleScope::UNIVERSAL]): ?OntologyNode
    {
        $key = TextNormalizer::key($text);
        if ($key === '') {
            return null;
        }

        return OntologyNode::query()
            ->active()
            ->where('key', $key)
            ->when($type, fn ($q) => $q->ofType($type))
            ->inScope($scopeChain)
            // Prefer the most vehicle-specific node available, falling back to universal.
            ->orderByRaw('CHAR_LENGTH(scope_key) DESC')
            ->first();
    }
}
