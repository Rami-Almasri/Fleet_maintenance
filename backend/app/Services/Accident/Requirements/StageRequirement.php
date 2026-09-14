<?php

namespace App\Services\Accident\Requirements;

use App\Models\AccidentCase;
use App\Models\AccidentWorkflowStage;

/**
 * ONE GATE a stage can carry.
 *
 * ── WHY THIS IS AN INTERFACE AND NOT A RULE LANGUAGE ───────────────────────────────────────────
 *
 * The office needs to choose WHICH rule guards a stage. It must never be able to WRITE one. A system
 * where an admin types a condition into a box is a system where a typo silently disables a guardrail,
 * where nobody can test the rules, and where "what is actually stopping this case?" has no answer
 * short of reading somebody's expression.
 *
 * So every rule is a small compiled class with a fixed key. The admin picks a key; the backend
 * evaluates only what it was built to understand. Adding a genuinely new KIND of rule is a developer
 * change — which is correct, because a new kind of rule is new behaviour. Re-ARRANGING the rules is
 * not, and that is the part the office owns.
 *
 * `manual_confirmation` and `document_uploaded` are the two generics that make brand-new stages work
 * with no code at all: almost any stage a person can invent is really "somebody did this" or
 * "somebody filed this".
 */
interface StageRequirement
{
    /** The stored key. Immutable — stage rows point at it. */
    public function key(): string;

    /** What the admin sees when choosing it. */
    public function label(): string;

    /** What the admin sees underneath, explaining what it actually checks. */
    public function description(): string;

    /** Does this requirement need configuration (e.g. which document kind)? Drives the editor. */
    public function configSchema(): array;

    /** Is the gate open for this case? */
    public function isSatisfied(AccidentCase $case, AccidentWorkflowStage $stage): bool;

    /**
     * The sentence shown when it is not — in the words the person needs, naming what to do rather
     * than what failed. "The police report is still outstanding" beats "requirement not met".
     */
    public function missing(AccidentCase $case, AccidentWorkflowStage $stage): string;
}
