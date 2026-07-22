<?php

namespace App\Services\Explainability;

/**
 * The canonical explanation model: a directed acyclic graph of self-describing nodes, shared across
 * every Intelligence module. A value exists ONCE (Gross Revenue is one node); Net Profit references it,
 * Economic Profit references Net Profit — nothing is duplicated. From the same edges the graph derives
 * BOTH directions of lineage:
 *   • dependencies         — "where did this come from?" (down toward the original business record)
 *   • reverse_dependencies — "what depends on this?"     (up toward KPIs, dashboards, reports)
 *
 * The graph never computes a business figure. Modules (explainers) contribute already-calculated
 * values; the graph only wires them together, derives lineage, materialises downstream consumers, and
 * checks the whole thing stays acyclic so drill-down can never loop.
 */
class ExplanationGraph
{
    /** @var array<string,array<string,mixed>> id → node */
    private array $nodes = [];

    /** List keys are unioned on merge; everything else keeps the first non-null value. */
    private const LIST_KEYS = ['children', 'dependencies', 'reverse_dependencies', 'evidence', 'source_records', 'downstream'];

    public function __construct(private ExplanationContext $context) {}

    public function context(): ExplanationContext
    {
        return $this->context;
    }

    public function has(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    /** @return array<string,mixed>|null */
    public function node(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    /**
     * Add or merge a node. Idempotent by id — a node contributed by two modules is stored once, with
     * list fields unioned so neither module's edges are lost. Returns the node id for convenient wiring.
     */
    public function upsert(array $node): string
    {
        $node['type'] = $node['type'] ?? ($node['kind'] ?? NodeType::VALUE);
        $id = $node['id'];

        $this->nodes[$id] = isset($this->nodes[$id]) ? $this->mergeNode($this->nodes[$id], $node) : $node;

        return $id;
    }

    /** Adapt a legacy flat node map (id → node array) into the graph in one call. */
    public function ingest(array $nodeMap): void
    {
        foreach ($nodeMap as $node) {
            $this->upsert($node);
        }
    }

    /**
     * Finalise and serialise: materialise downstream consumers, derive dependency edges from every
     * formula/child reference, compute reverse dependencies, verify acyclicity, and attach the audit
     * context. Safe to call once after all modules have contributed.
     *
     * @param  array<string,string>  $roots   metric key → node id
     * @param  array<int,array>      $modules module metadata for the UI (key, label, roots)
     * @return array<string,mixed>
     */
    public function toArray(array $roots, array $modules = [], ?string $default = null): array
    {
        $this->wireDownstreamConsumers();
        $this->deriveDependencies();
        $this->computeReverseDependencies();
        $cycles = $this->detectCycles();

        return [
            'roots'    => $roots,
            'modules'  => $modules,
            'default'  => $default ?? (array_values($roots)[0] ?? null),
            'nodes'    => $this->nodes,
            'context'  => $this->context->toArray(),
            'warnings' => $cycles ? ['cycles' => $cycles] : [],
        ];
    }

    // ------------------------------------------------------------------ internals -------------------

    private function mergeNode(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $val) {
            if (in_array($key, self::LIST_KEYS, true)) {
                $existing[$key] = array_values(array_unique(array_merge($existing[$key] ?? [], $val ?? []), SORT_REGULAR));
            } elseif (! array_key_exists($key, $existing) || $existing[$key] === null) {
                $existing[$key] = $val;
            }
        }

        return $existing;
    }

    /**
     * Turn each node's declared `downstream` consumers (KPIs, dashboards, reports) into real CONSUMER
     * nodes that DEPEND on the source — so reverse lineage visibly continues past the last metric all
     * the way to "Executive Dashboard". Declared, not fabricated: these are the surfaces that genuinely
     * read the number; consumer nodes carry no value of their own.
     */
    private function wireDownstreamConsumers(): void
    {
        foreach ($this->nodes as $id => $node) {
            foreach ($node['downstream'] ?? [] as $c) {
                $cid   = is_array($c) ? $c['id'] : $c;
                $label = is_array($c) ? ($c['label'] ?? $cid) : $cid;
                if (! isset($this->nodes[$cid])) {
                    $this->nodes[$cid] = [
                        'id' => $cid, 'type' => NodeType::CONSUMER, 'label' => $label,
                        'note' => 'A downstream surface that consumes this value.',
                        'dependencies' => [],
                    ];
                }
                $this->nodes[$cid]['dependencies'][] = $id;
                if (is_array($c) && ! empty($c['downstream'])) {
                    $this->nodes[$cid]['downstream'] = array_merge($this->nodes[$cid]['downstream'] ?? [], $c['downstream']);
                }
            }
        }
        // Second pass so a consumer's own downstream (KPI → Dashboard → Report) is materialised too.
        foreach ($this->nodes as $id => $node) {
            foreach ($node['downstream'] ?? [] as $c) {
                if (! is_array($c) || empty($c['downstream'])) {
                    continue;
                }
                foreach ($c['downstream'] as $d) {
                    $did   = is_array($d) ? $d['id'] : $d;
                    $label = is_array($d) ? ($d['label'] ?? $did) : $did;
                    if (! isset($this->nodes[$did])) {
                        $this->nodes[$did] = ['id' => $did, 'type' => NodeType::CONSUMER, 'label' => $label,
                            'note' => 'A downstream surface that consumes this value.', 'dependencies' => []];
                    }
                    $this->nodes[$did]['dependencies'][] = $c['id'];
                }
            }
        }
    }

    /** Dependencies of a node = its explicit deps ∪ formula operand refs ∪ drillable children. */
    private function deriveDependencies(): void
    {
        foreach ($this->nodes as $id => &$node) {
            $deps = $node['dependencies'] ?? [];
            foreach ($node['formula']['terms'] ?? [] as $t) {
                if (! empty($t['ref'])) {
                    $deps[] = $t['ref'];
                }
            }
            foreach ($node['children'] ?? [] as $c) {
                $deps[] = $c;
            }
            // Keep only edges that point at real nodes (drop dangling references defensively).
            $node['dependencies'] = array_values(array_filter(array_unique($deps), fn ($d) => isset($this->nodes[$d])));
        }
        unset($node);
    }

    /** Reverse edges: if A depends on B, then A is a reverse-dependency of B ("what depends on this"). */
    private function computeReverseDependencies(): void
    {
        foreach ($this->nodes as &$node) {
            $node['reverse_dependencies'] = [];
        }
        unset($node);

        foreach ($this->nodes as $id => $node) {
            foreach ($node['dependencies'] ?? [] as $dep) {
                if (isset($this->nodes[$dep])) {
                    $this->nodes[$dep]['reverse_dependencies'][] = $id;
                }
            }
        }
        foreach ($this->nodes as &$node) {
            $node['reverse_dependencies'] = array_values(array_unique($node['reverse_dependencies']));
        }
        unset($node);
    }

    /**
     * DFS cycle detection over the dependency edges — the graph MUST stay acyclic so drill-down can
     * never loop. Returns the offending node ids (empty when clean); reported as a payload warning
     * rather than thrown, so a data-driven miswire degrades gracefully instead of 500-ing.
     *
     * @return array<int,string>
     */
    private function detectCycles(): array
    {
        $state = [];   // id → 1 visiting, 2 done
        $found = [];

        $visit = function (string $id) use (&$visit, &$state, &$found) {
            $state[$id] = 1;
            foreach ($this->nodes[$id]['dependencies'] ?? [] as $dep) {
                if (! isset($this->nodes[$dep])) {
                    continue;
                }
                $s = $state[$dep] ?? 0;
                if ($s === 1) {
                    $found[] = $dep;
                } elseif ($s === 0) {
                    $visit($dep);
                }
            }
            $state[$id] = 2;
        };

        foreach (array_keys($this->nodes) as $id) {
            if (($state[$id] ?? 0) === 0) {
                $visit($id);
            }
        }

        return array_values(array_unique($found));
    }
}
