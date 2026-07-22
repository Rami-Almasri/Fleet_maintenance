<?php

namespace App\Services\Explainability;

use App\Models\Vehicle;
use App\Services\FinancialExplanationService;

/**
 * Finance module on the Explainability platform — Profitability + Cost Intelligence.
 *
 * It does NOT re-derive anything: it takes the already-built financial node map from
 * FinancialExplanationService (which itself only reads the authoritative engines) and ENRICHES it into
 * the platform node contract — assigning a confidence grade to every node, attaching audit metadata to
 * the headline metrics, and declaring the downstream consumers (Fleet KPIs → dashboards → reports) so
 * reverse lineage extends past the last number. All values pass through untouched.
 */
class FinancialExplainer implements Explainer
{
    public function __construct(private FinancialExplanationService $finance) {}

    public function module(): string
    {
        return 'finance';
    }

    public function label(): string
    {
        return 'Profitability & Cost';
    }

    public function explain(Vehicle $vehicle, ExplanationGraph $graph, ExplanationContext $context): array
    {
        $d = $this->finance->forVehicle($vehicle);
        $nodes = $d['nodes'];

        // Enrich each node with a confidence grade (and evidence where the record supports it).
        foreach ($nodes as $id => &$n) {
            $n['confidence'] = $n['confidence'] ?? $this->confidenceFor($id, $n);
        }
        unset($n);

        // Audit blocks on the headline metrics — how many records went in, currency, snapshot, window.
        $contracts = count($d['revenue']['rows'] ?? $this->childrenOf($nodes, 'rent_income'));
        $orders    = count($this->childrenOf($nodes, 'maint'));
        $this->attachAudit($nodes, 'gross', $context, ['included_records' => $this->countContracts($nodes), 'basis' => 'All rental (type-C) contracts, lifetime to date']);
        $this->attachAudit($nodes, 'operating', $context, ['basis' => 'Per-contract commissions + co-driver fees']);
        $this->attachAudit($nodes, 'maint', $context, ['included_records' => $orders, 'basis' => 'Workshop-log maintenance orders']);
        $this->attachAudit($nodes, 'net', $context, ['basis' => 'Gross − Operating − Maintenance']);
        $this->attachAudit($nodes, 'economic', $context, ['basis' => 'Net − Accumulated depreciation (straight-line)']);

        // Declare downstream consumers so "what depends on this?" reaches the KPIs and surfaces that
        // genuinely read these numbers. Structural links (no fabricated values).
        $dash = ['id' => 'surface:profitability', 'label' => 'Profitability Dashboard'];
        $exec = ['id' => 'surface:exec', 'label' => 'Executive Report'];
        $costUi = ['id' => 'surface:cost_intel', 'label' => 'Cost Intelligence'];
        $this->addDownstream($nodes, 'gross', [['id' => 'kpi:fleet_gross', 'label' => 'Fleet Gross Revenue KPI', 'downstream' => [$dash]]]);
        $this->addDownstream($nodes, 'net', [['id' => 'kpi:fleet_net', 'label' => 'Fleet Net Profit KPI', 'downstream' => [$dash, $exec]]]);
        $this->addDownstream($nodes, 'economic', [['id' => 'kpi:fleet_economic', 'label' => 'Fleet Economic Profit KPI', 'downstream' => [$exec]]]);
        $this->addDownstream($nodes, 'maint', [['id' => 'kpi:fleet_maint', 'label' => 'Fleet Maintenance KPI', 'downstream' => [$costUi]]]);
        $this->addDownstream($nodes, 'cost_per_km', [$costUi]);

        // Evidence chains — a maintenance order's invoice lines are its evidence; distance is evidenced
        // by the two odometer readings behind it.
        $this->attachMaintenanceEvidence($nodes);

        $graph->ingest($nodes);

        unset($contracts);

        return $d['roots'];
    }

    // ------------------------------------------------------------------ helpers ---------------------

    /** Assign a confidence grade from the node's type / id / value. */
    private function confidenceFor(string $id, array $n): string
    {
        $type = $n['type'] ?? $n['kind'] ?? null;

        if ($n['value'] === null && in_array($type, [NodeType::METRIC, NodeType::RATIO, NodeType::COMPONENT], true)) {
            return Confidence::MISSING;
        }
        if (str_starts_with($id, 'distance')) {
            return Confidence::MEASURED;      // odometer readings
        }
        if ($type === NodeType::RECORD) {
            return Confidence::IMPORTED;       // contracts / payments / invoice lines / purchase — systems of record
        }
        if (in_array($type, [NodeType::METRIC, NodeType::COMPONENT, NodeType::RATIO], true)) {
            return Confidence::CALCULATED;
        }

        return Confidence::CALCULATED;
    }

    /** @param array<string,array> $nodes */
    private function attachAudit(array &$nodes, string $id, ExplanationContext $ctx, array $extra): void
    {
        if (! isset($nodes[$id])) {
            return;
        }
        $nodes[$id]['audit'] = array_merge([
            'engine_version'   => $ctx->engineVersion,
            'as_of'            => $ctx->asOf,
            'window'           => 'Lifetime to ' . ($ctx->asOf ?? 'today'),
            'currency'         => $ctx->currency,
            'precision'        => $ctx->precision,
            'source_module'    => $nodes[$id]['source_module'] ?? null,
        ], $extra);
    }

    private function addDownstream(array &$nodes, string $id, array $consumers): void
    {
        if (isset($nodes[$id])) {
            $nodes[$id]['downstream'] = array_merge($nodes[$id]['downstream'] ?? [], $consumers);
        }
    }

    private function attachMaintenanceEvidence(array &$nodes): void
    {
        foreach ($nodes as $id => &$n) {
            if (! str_starts_with($id, 'maint:')) {
                continue;
            }
            $evidence = [];
            if (! empty($n['record']['Invoice no'])) {
                $evidence[] = ['label' => 'Invoice ' . $n['record']['Invoice no'], 'kind' => 'invoice'];
            }
            foreach ($n['children'] ?? [] as $lineId) {
                if (isset($nodes[$lineId])) {
                    $evidence[] = ['label' => $nodes[$lineId]['label'], 'kind' => 'invoice_line', 'ref' => $lineId];
                }
            }
            if ($evidence) {
                $n['evidence'] = $evidence;
                $n['source_records'] = $n['children'] ?? [];
            }
        }
        unset($n);
    }

    /** @return array<int,string> */
    private function childrenOf(array $nodes, string $id): array
    {
        return $nodes[$id]['children'] ?? [];
    }

    private function countContracts(array $nodes): int
    {
        return count(array_filter(array_keys($nodes), fn ($k) => str_starts_with($k, 'contract:')));
    }
}
