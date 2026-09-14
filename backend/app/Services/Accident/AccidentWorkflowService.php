<?php

namespace App\Services\Accident;

use App\Models\AccidentCase;
use App\Models\AccidentStageCompletion;
use App\Models\AccidentWorkflow;
use App\Models\AccidentWorkflowStage;
use App\Models\User;
use App\Models\VehicleLogEvent;
use App\Services\VehicleLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * THE ENGINE. Everything that reads or moves a case's position on its ladder goes through here.
 *
 * Evidence class: D (derived) over F (configuration). Produces: accident_cases.stage,
 * accident_stage_completions, accident_workflows/_stages, vehicle_log_events. Consumes: the
 * requirement and condition registries.
 *
 * ── FOUR RULES ─────────────────────────────────────────────────────────────────────────────────
 *
 *  1. THE ORDER COMES FROM THE DATABASE. There is not one `if ($stage === '…')` in this file, and
 *     there must never be one. `nextStage()` asks the case's own workflow what comes after where it
 *     is standing — so reordering the list in the UI reorders the process, with no deploy.
 *
 *  2. THE GATE TRAVELS WITH THE STAGE. `canLeave()` reads `requirement_key` off the row the case is
 *     standing on. Move Police Report to position five and its gate is at position five. The old
 *     code compared stage names, which meant a reorder would have silently switched the guardrail
 *     off — no error, nothing in the log, just a case walking past the police report.
 *
 *  3. A CASE NEVER MOVES BECAUSE SOMEBODY EDITED THE CONFIGURATION. Cases are pinned to the version
 *     they were born on. Publishing a new arrangement affects new cases only; moving an in-flight
 *     case onto it is an explicit, audited act.
 *
 *  4. FORWARD IS EARNED, BACKWARD IS AUTHORISED. Advancing needs the gate open. Going back needs a
 *     permission and a reason, and says so in the timeline — because reopening a stage is usually
 *     somebody discovering that an earlier answer was wrong, and that is worth recording.
 */
class AccidentWorkflowService
{
    public function __construct(
        private RequirementRegistry $requirements,
        private ConditionRegistry $conditions,
        private VehicleLogService $log,
    ) {}

    /**
     * WORKFLOWS READ ONCE PER PROCESS, keyed by id.
     *
     * Without this, every surface that renders a LIST of cases re-queried the whole ladder per row —
     * the accident register, the board's accident lane, and now every maintenance ticket that carries
     * an accident block. Fifty crashed cars meant fifty identical workflow queries plus fifty stage
     * queries, to build fifty copies of the same ten rows.
     *
     * Safe to hold because a PUBLISHED workflow is immutable: editing mints a draft and publishing
     * mints a new version rather than rewriting one. The only thing that can move underneath this is
     * WHICH version is active, and publish() calls flushCache() for exactly that reason.
     *
     * An INSTANCE property, with the service bound as a singleton in AppServiceProvider — deliberately
     * not `static`. A static memo would survive the transaction rollback between tests and hand the
     * next test a workflow that no longer exists in the database. Singleton scope gives exactly the
     * lifetime wanted: one request, or one test.
     */
    private array $memo = [];

    /** Forget the cached ladders. Called by the config service the moment a new version goes live. */
    public function flushCache(): void
    {
        $this->memo = [];
    }

    // ══ READING THE LADDER ════════════════════════════════════════════════════════════════════

    /** The version every NEW case is pinned to. */
    public function activeWorkflow(): ?AccidentWorkflow
    {
        return $this->memo['active'] ??= AccidentWorkflow::active()->with('stages')->first();
    }

    /**
     * The workflow THIS case runs — its own pinned version, never today's active one.
     *
     * The fallback to active() exists only for rows that predate versioning; every case created from
     * now on carries its workflow_id from birth.
     */
    public function workflowFor(AccidentCase $case): ?AccidentWorkflow
    {
        if ($case->workflow_id) {
            return $this->memo[$case->workflow_id] ??= AccidentWorkflow::with('stages')->find($case->workflow_id);
        }

        return $this->activeWorkflow();
    }

    /**
     * THIS case's ladder — the stages that actually apply to it, in order.
     *
     * Disabled stages and stages whose condition does not hold are dropped: a car that still drives
     * has no Recovery rung, and a case the other party caused has no Charge rung. They are not
     * "pending" on those stages, they simply do not have them.
     *
     * ONE EXCEPTION, and it matters: the stage the case is CURRENTLY standing on is always kept,
     * even if it has since been disabled or its condition has stopped holding. A case must never be
     * standing somewhere its own ladder says does not exist — that is the state that makes a case
     * unresolvable, and it is exactly what somebody toggling a checkbox would otherwise cause.
     *
     * @return Collection<int, AccidentWorkflowStage>
     */
    public function ladderFor(AccidentCase $case): Collection
    {
        $workflow = $this->workflowFor($case);
        if (! $workflow) {
            return collect();
        }

        return $workflow->stages
            ->filter(fn (AccidentWorkflowStage $s) => $s->key === $case->stage || $s->appliesTo($case, $this->conditions))
            ->sortBy('position')
            ->values();
    }

    /** Where the case is standing right now. */
    public function currentStage(AccidentCase $case): ?AccidentWorkflowStage
    {
        return $this->workflowFor($case)?->stages->firstWhere('key', $case->stage);
    }

    /**
     * The next rung this case can climb — the first APPLICABLE stage after where it stands.
     *
     * Disabled and non-applicable stages are stepped over rather than landed on, which is how
     * "disable Settlement" makes the ladder read Repair → Closed with no code change, and how a
     * drivable car walks past Recovery without anybody skipping anything by hand.
     */
    public function nextStage(AccidentCase $case): ?AccidentWorkflowStage
    {
        $current = $this->currentStage($case);
        if (! $current) {
            return $this->ladderFor($case)->first();
        }

        return $this->ladderFor($case)
            ->first(fn (AccidentWorkflowStage $s) => $s->position > $current->position && $s->key !== $case->stage);
    }

    /** The rung before — for a rewind, and only the applicable ones. */
    public function previousStage(AccidentCase $case): ?AccidentWorkflowStage
    {
        $current = $this->currentStage($case);
        if (! $current) {
            return null;
        }

        return $this->ladderFor($case)
            ->filter(fn (AccidentWorkflowStage $s) => $s->position < $current->position)
            ->last();
    }

    /**
     * Is the gate on the case's current stage open?
     *
     * @return array{ok:bool, missing:?string, requirement:string}
     */
    public function canLeave(AccidentCase $case): array
    {
        $stage = $this->currentStage($case);
        if (! $stage) {
            // No stage row for the stored key: the case is on a workflow that no longer describes it.
            // Let it move rather than trapping it — an unresolvable case is worse than a loose one.
            return ['ok' => true, 'missing' => null, 'requirement' => 'none'];
        }

        $rule = $this->requirements->get($stage->requirement_key);

        return $rule->isSatisfied($case, $stage)
            ? ['ok' => true, 'missing' => null, 'requirement' => $rule->key()]
            : ['ok' => false, 'missing' => $rule->missing($case, $stage), 'requirement' => $rule->key()];
    }

    /**
     * THE LADDER AS THE UI DRAWS IT — every rung with its state.
     *
     * `done | current | blocked | pending | skipped`. `blocked` is the current stage with an unmet
     * gate, and it carries the sentence saying what is missing, so the screen never has to work out
     * why the button is disabled.
     */
    public function progressFor(AccidentCase $case): array
    {
        $workflow = $this->workflowFor($case);
        if (! $workflow) {
            return ['workflow' => null, 'stages' => []];
        }

        $current = $this->currentStage($case);
        $gate    = $this->canLeave($case);

        $stages = $workflow->stages->sortBy('position')->values()->map(function (AccidentWorkflowStage $s) use ($case, $current, $gate) {
            $applies = $s->key === $case->stage || $s->appliesTo($case, $this->conditions);
            $rule    = $this->requirements->get($s->requirement_key);

            $state = match (true) {
                ! $applies                                        => 'skipped',
                $current && $s->key === $current->key             => ($gate['ok'] ? 'current' : 'blocked'),
                $current && $s->position < $current->position     => 'done',
                default                                           => 'pending',
            };

            return [
                'key'          => $s->key,
                'label'        => $s->label,
                'label_ar'     => $s->label_ar,
                'description'  => $s->description,
                'position'     => $s->position,
                'state'        => $state,
                'is_terminal'  => $s->is_terminal,
                'is_mandatory' => $s->is_mandatory,
                'is_enabled'   => $s->is_enabled,
                'applies'      => $applies,
                'tone'         => $s->tone,
                'requirement'  => [
                    'key'       => $rule->key(),
                    'label'     => $rule->label(),
                    'satisfied' => $rule->isSatisfied($case, $s),
                    // Only the CURRENT stage explains itself. A pending stage listing what it will
                    // one day need reads as a wall of blockers on a case where nothing is wrong yet.
                    'missing'   => ($state === 'blocked') ? $gate['missing'] : null,
                ],
                'applies_when' => $s->applies_when,
            ];
        })->all();

        return [
            'workflow' => [
                'id' => $workflow->id, 'version' => $workflow->version,
                'name' => $workflow->name, 'status' => $workflow->status,
                // A case on an archived version is running yesterday's process ON PURPOSE. Said out
                // loud so nobody reads it as a bug.
                'is_latest' => $workflow->isActive(),
            ],
            'stages'       => $stages,
            'current'      => $current?->key,
            'next'         => $this->nextStage($case)?->key,
            'can_advance'  => $gate['ok'] && $this->nextStage($case) !== null,
            'blocked_by'   => $gate['ok'] ? null : $gate['missing'],
            'is_terminal'  => (bool) $current?->is_terminal,
        ];
    }

    // ══ MOVING ════════════════════════════════════════════════════════════════════════════════

    /** Put a brand-new case on the active workflow's first rung. */
    public function startCase(AccidentCase $case): AccidentCase
    {
        $workflow = $this->activeWorkflow();
        if (! $workflow) {
            throw ValidationException::withMessages([
                'workflow' => 'No accident workflow is published. Configure one before reporting accidents.',
            ]);
        }

        $case->workflow_id = $workflow->id;
        $case->stage = ($workflow->initialStage()?->key) ?? 'reported';

        return $case;
    }

    /**
     * ADVANCE — evaluate the gate, then move to whatever the configuration says comes next.
     *
     * No stage names, no positions, no special cases. The only reason this can refuse is that the
     * current rung's own requirement is unmet, and the refusal quotes it.
     */
    public function advance(AccidentCase $case, User $actor, ?string $note = null): AccidentCase
    {
        $gate = $this->canLeave($case);
        if (! $gate['ok']) {
            throw ValidationException::withMessages(['stage' => $gate['missing']]);
        }

        $next = $this->nextStage($case);
        if (! $next) {
            throw ValidationException::withMessages([
                'stage' => 'This case is already at the end of its workflow.',
            ]);
        }

        return $this->moveTo($case, $next, $actor, $note, 'forward');
    }

    /**
     * REWIND — authorised and reasoned, never automatic.
     *
     * Going back is almost always somebody discovering an earlier answer was wrong ("new damage
     * found after the insurer had already been"), which is exactly the kind of thing that must be
     * legible six months later. Permission is enforced at the route; the reason is enforced here.
     */
    public function rewind(AccidentCase $case, string $stageKey, string $reason, User $actor): AccidentCase
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Say why the case is going back. A stage reopened with no reason is indistinguishable from a mistake.',
            ]);
        }

        $current = $this->currentStage($case);
        $target  = $this->workflowFor($case)?->stages->firstWhere('key', $stageKey);

        if (! $target) {
            throw ValidationException::withMessages(['stage' => 'That stage is not part of this case’s workflow.']);
        }
        if ($current && $target->position >= $current->position) {
            throw ValidationException::withMessages([
                'stage' => 'That is not a step backwards. Use Advance to move the case forward.',
            ]);
        }

        return $this->moveTo($case, $target, $actor, $reason, 'backward');
    }

    /**
     * Jump straight to a named stage — the escape hatch for a case that must skip an OPTIONAL rung.
     * Mandatory stages cannot be skipped this way; that is what `is_mandatory` is for.
     */
    public function jumpTo(AccidentCase $case, string $stageKey, User $actor, ?string $note = null): AccidentCase
    {
        $current = $this->currentStage($case);
        $target  = $this->workflowFor($case)?->stages->firstWhere('key', $stageKey);

        if (! $target) {
            throw ValidationException::withMessages(['stage' => 'That stage is not part of this case’s workflow.']);
        }
        if ($current && $target->position <= $current->position) {
            throw ValidationException::withMessages(['stage' => 'Use Move back to return to an earlier stage.']);
        }

        // The gate on the stage being LEFT still applies — skipping ahead must not skip the rule.
        $gate = $this->canLeave($case);
        if (! $gate['ok']) {
            throw ValidationException::withMessages(['stage' => $gate['missing']]);
        }

        // Everything between here and there that is MANDATORY and applicable blocks the jump.
        $skipped = $this->ladderFor($case)->filter(fn ($s) => $current
            && $s->position > $current->position && $s->position < $target->position && $s->is_mandatory);

        if ($skipped->isNotEmpty()) {
            throw ValidationException::withMessages([
                'stage' => 'This would skip a required stage: ' . $skipped->pluck('label')->implode(', ') . '.',
            ]);
        }

        return $this->moveTo($case, $target, $actor, $note, 'skip');
    }

    /** The one write that changes a case's position, and the timeline row that records it. */
    private function moveTo(AccidentCase $case, AccidentWorkflowStage $to, User $actor, ?string $note, string $direction): AccidentCase
    {
        $from = $case->stage;
        $fromStage = $this->currentStage($case);

        $case->stage = $to->key;
        $case->save();

        $this->log->recordAccident($case, VehicleLogEvent::EVENT_ACCIDENT_STAGE_CHANGED, $actor, [
            'description' => $direction === 'backward'
                ? sprintf('Moved back to %s — %s', $to->label, $note)
                : sprintf('Moved to %s', $to->label),
            'meta' => [
                'accident_case_id' => $case->id,
                'reference'        => $case->reference,
                'previous_stage'   => $from,
                'previous_label'   => $fromStage?->label,
                'new_stage'        => $to->key,
                'new_label'        => $to->label,
                'direction'        => $direction,
                'note'             => $note,
                // WHICH ARRANGEMENT this move was made under. A year from now, "why did it go from
                // Insurance to Repair without a police report?" is answerable only if the version is
                // on the row — the configuration will have moved on.
                'workflow_id'      => $case->workflow_id,
                'workflow_version' => $this->workflowFor($case)?->version,
                'moved_by'         => $actor->name ?: $actor->email,
            ],
        ]);

        return $case->fresh();
    }

    // ══ THE GENERIC GATE ══════════════════════════════════════════════════════════════════════

    /**
     * Mark a `manual_confirmation` stage done. The mechanism that lets a brand-new stage work with no
     * migration and no deploy.
     *
     * Re-confirming REPLACES rather than stacks, so a stage can never read as doubly-done — and the
     * timeline keeps both acts, so the history of who said what is not lost to the replacement.
     */
    public function confirmStage(AccidentCase $case, string $stageKey, User $actor, ?string $note = null): AccidentStageCompletion
    {
        $stage = $this->workflowFor($case)?->stages->firstWhere('key', $stageKey);
        if (! $stage) {
            throw ValidationException::withMessages(['stage' => 'That stage is not part of this case’s workflow.']);
        }

        return DB::transaction(function () use ($case, $stage, $actor, $note) {
            AccidentStageCompletion::where('accident_case_id', $case->id)
                ->where('stage_key', $stage->key)->delete();

            $done = AccidentStageCompletion::create([
                'accident_case_id'  => $case->id,
                'stage_key'         => $stage->key,
                'completed_at'      => Carbon::now(),
                'completed_by'      => $actor->id,
                'completed_by_name' => $actor->name ?: $actor->email,
                'note'              => $note,
            ]);

            $this->log->recordAccident($case, VehicleLogEvent::EVENT_ACCIDENT_STAGE_CONFIRMED, $actor, [
                'description' => sprintf('%s confirmed by %s', $stage->label, $done->completed_by_name),
                'meta' => [
                    'accident_case_id' => $case->id,
                    'reference'        => $case->reference,
                    'stage'            => $stage->key,
                    'stage_label'      => $stage->label,
                    'note'             => $note,
                    'confirmed_by'     => $done->completed_by_name,
                ],
            ]);

            return $done;
        });
    }

    // ══ DOES THIS CASE HOLD THE CAR? ══════════════════════════════════════════════════════════

    /**
     * Replaces the hard-coded RENTAL_BLOCKING_STAGES list: the answer now comes off the stage the
     * case is standing on, so "does settlement ground the car?" is the office's decision.
     */
    public function blocksRental(AccidentCase $case): bool
    {
        return (bool) $this->currentStage($case)?->blocks_rental;
    }

    /** The stage keys that hold a car, for the one query rental eligibility runs. */
    public function rentalBlockingStageKeys(): array
    {
        return AccidentWorkflowStage::where('blocks_rental', true)->pluck('key')->unique()->values()->all();
    }

    /** The terminal stage keys across every version — "is this case finished?" as a query. */
    public function terminalStageKeys(): array
    {
        return AccidentWorkflowStage::where('is_terminal', true)->pluck('key')->unique()->values()->all();
    }
}
