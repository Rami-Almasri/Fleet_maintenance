<?php

namespace App\Services\Explainability;

/**
 * Confidence grade of a value — how much to trust it, and why. Essential for operational intelligence:
 * a "measured" odometer is stronger evidence than an "estimated" usage rate, and a "missing" input must
 * read as unknown rather than zero. Every node in the graph carries one of these.
 */
final class Confidence
{
    /** Directly measured / observed (an odometer reading captured at handover). */
    public const MEASURED = 'measured';

    /** Imported verbatim from a system of record (OfficeManager contract, asset sheet). */
    public const IMPORTED = 'imported';

    /** Derived arithmetically from other nodes (a formula result / reconciled aggregate). */
    public const CALCULATED = 'calculated';

    /** Projected / inferred from a model or rate (projected service date from km/day). */
    public const ESTIMATED = 'estimated';

    /** Manually corrected / overridden by a human (a sync-audit fix). */
    public const CORRECTED = 'corrected';

    /** Passed a validation gate (a re-inspection PASS). */
    public const VALIDATED = 'validated';

    /** Not available — the input is absent, so the value is unknown (never silently 0). */
    public const MISSING = 'missing';
}
