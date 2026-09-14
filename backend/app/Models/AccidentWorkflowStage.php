<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONE RUNG of one version of the ladder — and, crucially, the gate that guards it.
 *
 * ── THE RULE BELONGS TO THE STAGE, NOT TO ITS POSITION ─────────────────────────────────────────
 *
 * `requirement_key` is why this table exists. Move "Police Report" from position 2 to position 5 and
 * its gate moves with it, because the gate is a column on the row rather than an `if` comparing a
 * stage name in a service. The alternative fails silently: a reorder would leave the check pointing
 * at a position that now holds something else, and a case would walk past the police report with no
 * error and nothing in the log.
 *
 * The key is an index into a REGISTRY of compiled requirement classes. There is no rule language and
 * there must never be one — an admin picks from a fixed list, and the backend only ever evaluates
 * something it was built to understand.
 *
 * `applies_when` is the same idea for the branch. A car that cannot be driven goes to Recovery and
 * skips the Test Drive; a car that can does the reverse. Both are facts about the case, so the fork
 * is a condition on the rung rather than a routing graph.
 */
class AccidentWorkflowStage extends Model
{
    protected $fillable = [
        'workflow_id', 'key', 'label', 'label_ar', 'description', 'position',
        'is_enabled', 'is_initial', 'is_terminal', 'is_mandatory', 'blocks_rental',
        'requirement_key', 'requirement_config', 'applies_when', 'tone',
    ];

    protected $casts = [
        'position'           => 'integer',
        'is_enabled'         => 'boolean',
        'is_initial'         => 'boolean',
        'is_terminal'        => 'boolean',
        'is_mandatory'       => 'boolean',
        'blocks_rental'      => 'boolean',
        'requirement_config' => 'array',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AccidentWorkflow::class, 'workflow_id');
    }

    /** The name a person reads, in the language they are reading in. */
    public function displayLabel(?string $lang = null): string
    {
        return ($lang === 'ar' && $this->label_ar) ? $this->label_ar : $this->label;
    }

    /**
     * Does this rung exist for THIS case?
     *
     * A stage that is disabled outright, or whose condition does not hold for this particular
     * accident, is not part of this case's ladder — it is skipped when advancing and drawn as
     * inapplicable rather than pending, because "not for this car" and "not done yet" are different
     * states and colouring them the same is how a person loses confidence in the board.
     */
    public function appliesTo(AccidentCase $case, \App\Services\Accident\ConditionRegistry $conditions): bool
    {
        return $this->is_enabled && $conditions->get($this->applies_when)->applies($case);
    }
}
