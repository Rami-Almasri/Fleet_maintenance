<?php

namespace App\Services\Explainability;

use App\Models\Vehicle;

/**
 * The Explainability platform's entry point — the shared engine every Intelligence module runs through.
 *
 * It owns the registry of explainers, runs the requested ones into ONE shared graph (so values are
 * shared and lineage crosses module boundaries), namespaces each module's roots, and returns the
 * finalised platform payload (nodes + roots + module tabs + audit context). Adding a new intelligence
 * surface to the platform is one line: register its Explainer here.
 */
class ExplanationEngine
{
    /** @var array<string,Explainer> module key → explainer */
    private array $explainers;

    public function __construct(FinancialExplainer $finance, ServiceDueExplainer $service)
    {
        // Registry — extend by adding InspectionExplainer, FleetHealthExplainer, … here.
        $this->explainers = [
            $finance->module() => $finance,
            $service->module() => $service,
        ];
    }

    /** @return array<int,array{key:string,label:string}> the modules this engine can explain */
    public function modules(): array
    {
        return array_map(fn (Explainer $e) => ['key' => $e->module(), 'label' => $e->label()], array_values($this->explainers));
    }

    /**
     * Explain every figure for one vehicle, across the requested modules (default: all).
     *
     * @param  array<int,string>|null  $modules  limit to these module keys (null = all)
     * @return array<string,mixed>
     */
    public function forVehicle(Vehicle $vehicle, ExplanationContext $context, ?array $modules = null): array
    {
        $graph = new ExplanationGraph($context);

        $roots = [];
        $moduleMeta = [];
        $default = null;

        foreach ($this->explainers as $key => $explainer) {
            if ($modules !== null && ! in_array($key, $modules, true)) {
                continue;
            }
            $moduleRoots = $explainer->explain($vehicle, $graph, $context);

            foreach ($moduleRoots as $metric => $id) {
                $roots[$metric] = $id;                // bare key (backward compatible: 'net', 'revenue', …)
                $roots["{$key}.{$metric}"] = $id;     // namespaced key ('finance.net', 'service.service_due')
            }
            $moduleMeta[] = ['key' => $key, 'label' => $explainer->label(), 'roots' => $moduleRoots];
            $default ??= (array_values($moduleRoots)[0] ?? null);
        }

        $payload = $graph->toArray($roots, $moduleMeta, $default);
        $payload['vehicle'] = [
            'id'     => $vehicle->id,
            'plate'  => $vehicle->plate_no,
            'car'    => trim((string) ($vehicle->make . ' ' . $vehicle->model)) ?: null,
            'status' => $vehicle->status,
        ];

        return $payload;
    }
}
