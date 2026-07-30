<?php

namespace App\Services\Intelligence\Contracts;

use App\Services\Intelligence\CapabilityContext;
use App\Services\Intelligence\DecisionCard;

/**
 * A single thing the platform has learned how to say.
 *
 * EVERY capability plugs into the same pipeline:
 *
 *   Historical Projection → Capability → Evidence → Decision Card → Decision Engine
 *   → Operational Intelligence → Workflow UI → Feedback → Outcome → Learning Loop
 *
 * The capability owns exactly one question: **what does history tell us here?** It does not decide
 * whether it is shown, where, in what order, how confident it sounds, or how its response is
 * captured. Those belong to the shared pipeline, so that Comeback Detection, Garage Recommendation,
 * Procurement Intelligence and Repair-vs-Replace all reach the user by an identical route and only
 * the evidence differs.
 *
 * Returning null is the normal case and carries no cost: most capabilities have nothing to say
 * about most decisions, and silence is always preferable to a card nobody needed.
 *
 * TWO STANDING PROHIBITIONS, both structural rather than stylistic:
 *
 * 1. A CAPABILITY NEVER TALKS TO ANOTHER CAPABILITY. If Garage Recommendation needs comeback
 *    information it asks the query layer, never ComebackCapability. Allow one chain and within a
 *    year A depends on B, B on C, and the Decision Engine is ranking recommendations that silently
 *    contain each other's conclusions — at which point nothing is independently explainable and a
 *    single wrong inference is laundered through three cards that look like corroboration. The only
 *    shared language between capabilities is historical evidence.
 *    Enforced by tests/Unit/CapabilityIsolationTest.
 *
 * 2. A CAPABILITY NEVER COMPOSES SQL. It asks [[RepairHistoryQuery]] questions. See that interface.
 */
interface IntelligenceCapability
{
    /** Stable slug, matching the card id it produces. */
    public function id(): string;

    /**
     * This capability's own version — its LOGIC, not the classifier's and not the query layer's.
     *
     * Stamped onto every recommendation it produces. Bump it whenever the reasoning, thresholds,
     * wording or leverage change, because that is what later makes "if this were generated today,
     * would it be different?" an answerable question instead of a guess. History is never rewritten;
     * versions are how two generations of a capability are compared without touching the past.
     */
    public function version(): string;

    /**
     * Cheap pre-check. Keep it to flags and context shape — no queries. The engine calls this on
     * every capability for every decision, so anything expensive belongs in evaluate().
     */
    public function appliesTo(CapabilityContext $context): bool;

    /** The one question. Null when history has nothing worth interrupting the user for. */
    public function evaluate(CapabilityContext $context): ?DecisionCard;
}
