<?php

namespace App\Services\Explainability;

/**
 * Node types in the Explainability graph. A node's type drives how the UI renders it and what the
 * drill-down expects to find (a formula, a business rule, a raw record, …). Kept as string constants
 * (not a PHP enum) so legacy node maps that carry a plain `kind` string ingest without conversion.
 */
final class NodeType
{
    /** A headline figure a module surfaces (Gross Revenue, Net Profit, Service status). */
    public const METRIC = 'metric';

    /** A named intermediate that composes a metric (Rental Income, Depreciable Base). */
    public const COMPONENT = 'component';

    /** A derived ratio (Cost / km). */
    public const RATIO = 'ratio';

    /** A grouping node with no value of its own (Running Cost). */
    public const GROUP = 'group';

    /** An original business record — the leaf of a lineage (contract, payment, invoice line, purchase). */
    public const RECORD = 'record';

    /** A decision node: a business rule that fired (threshold crossed → action taken). */
    public const RULE = 'rule';

    /** A piece of supporting evidence (measurement, photo, sensor reading, inspection). */
    public const EVIDENCE = 'evidence';

    /** A downstream consumer of a value (KPI, dashboard, report) — the far end of reverse lineage. */
    public const CONSUMER = 'consumer';

    /** Generic value node (fallback). */
    public const VALUE = 'value';
}
