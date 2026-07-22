<?php

namespace App\Services\Explainability;

use App\Models\Vehicle;

/**
 * Contract every Intelligence module implements to plug into the shared Explainability platform.
 * An explainer NEVER computes a business figure — it reads already-calculated values from its domain
 * service and contributes self-describing nodes to the shared graph. Because every module writes into
 * the SAME graph, values are shared (Cost Intelligence reuses Finance's Maintenance node) and lineage
 * crosses module boundaries for free.
 */
interface Explainer
{
    /** Stable machine key ("finance", "service"). Namespaces this module's roots. */
    public function module(): string;

    /** Human label for the module tab ("Profitability & Cost"). */
    public function label(): string;

    /**
     * Contribute this module's nodes to the shared graph and return its roots (metric key → node id).
     *
     * @return array<string,string>
     */
    public function explain(Vehicle $vehicle, ExplanationGraph $graph, ExplanationContext $context): array;
}
