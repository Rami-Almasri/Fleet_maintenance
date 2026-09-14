<?php

namespace App\Services\Accident;

use App\Models\AccidentWorkflow;
use App\Models\AccidentWorkflowStage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * EDITING THE PROCESS — drafts, validation, and publishing.
 *
 * ── THE ADMIN SCREEN IS NOT THE GUARD ──────────────────────────────────────────────────────────
 *
 * Every rule below is enforced here, on the server, on every write. The configuration UI mirrors
 * them so somebody is warned before they save rather than after — but a workflow arriving by any
 * other route gets the identical treatment. A frontend-only rule is a rule that holds until the
 * first person opens the network tab.
 *
 * ── WHAT CANNOT BE BUILT ───────────────────────────────────────────────────────────────────────
 *
 * A workflow with no beginning · no ending · two beginnings · a terminal stage sitting in the middle
 * · duplicate keys · duplicate positions · a gate the backend has never heard of · a condition it
 * cannot evaluate. Each of those produces a case nobody can move, which is the one failure mode
 * worse than an awkward process.
 *
 * ── WHY PUBLISHING MINTS A VERSION ─────────────────────────────────────────────────────────────
 *
 * So that a case in flight is never dragged sideways by somebody rearranging the list. Editing works
 * on a DRAFT — a private copy nobody's case is running — and publishing swaps it in for new cases
 * only. Existing cases stay on the version they were born on until somebody explicitly migrates them.
 */
class AccidentWorkflowConfigService
{
    public function __construct(
        private RequirementRegistry $requirements,
        private ConditionRegistry $conditions,
    ) {}

    // ══ DRAFTS ════════════════════════════════════════════════════════════════════════════════

    /**
     * Open a draft to edit. Copies the active version wholesale rather than editing it in place —
     * the active version is what live cases are reading, and it must not change under them.
     *
     * Returns the existing draft when there is one, so two people editing do not create two drafts
     * that silently overwrite each other on publish.
     */
    public function openDraft(User $actor): AccidentWorkflow
    {
        if ($draft = AccidentWorkflow::where('status', AccidentWorkflow::STATUS_DRAFT)->with('stages')->first()) {
            return $draft;
        }

        $active = AccidentWorkflow::active()->with('stages')->first();

        return DB::transaction(function () use ($active) {
            $draft = AccidentWorkflow::create([
                'version' => (int) AccidentWorkflow::max('version') + 1,
                'name'    => $active?->name,
                'status'  => AccidentWorkflow::STATUS_DRAFT,
                'notes'   => null,
            ]);

            foreach ($active?->stages ?? collect() as $s) {
                $draft->stages()->create($s->only([
                    'key', 'label', 'label_ar', 'description', 'position', 'is_enabled',
                    'is_initial', 'is_terminal', 'is_mandatory', 'blocks_rental',
                    'requirement_key', 'requirement_config', 'applies_when', 'tone',
                ]));
            }

            return $draft->load('stages');
        });
    }

    /** Throw away a draft. Only a draft — a published version owns cases and is never deleted. */
    public function discardDraft(AccidentWorkflow $draft): void
    {
        $this->assertDraft($draft);
        $draft->delete();
    }

    // ══ STAGES ════════════════════════════════════════════════════════════════════════════════

    /**
     * Add a rung. Lands directly before the terminal stage by default — a new step is almost always
     * another thing to do BEFORE finishing, and appending after "Closed" would produce a stage no
     * case could ever reach.
     */
    public function addStage(AccidentWorkflow $draft, array $data, User $actor): AccidentWorkflowStage
    {
        $this->assertDraft($draft);

        $key = Str::slug($data['key'] ?? $data['label'], '_');
        if ($draft->stages()->where('key', $key)->exists()) {
            throw ValidationException::withMessages([
                'key' => 'A stage with that key already exists in this workflow.',
            ]);
        }

        $terminalPosition = $draft->stages()->where('is_terminal', true)->min('position');
        $position = $data['position']
            ?? ($terminalPosition ? $terminalPosition : ((int) $draft->stages()->max('position') + 1));

        return DB::transaction(function () use ($draft, $data, $key, $position) {
            // MAKE ROOM BY PARKING THE TAIL OUT OF RANGE, exactly as reorder() and resequence() do.
            // The unique index on (workflow_id, position) means any momentary clash is a 500, and
            // shuffling in place only avoids one if every update lands in descending order.
            //
            // It did not. `stages()` carries its own `orderBy('position')`, so an `orderByDesc()` here
            // APPENDED a second clause rather than replacing the first — ascending won, the shuffle ran
            // low-to-high, and inserting anywhere but the end died on a duplicate key. Moving the whole
            // tail beyond every real position removes the ordering question altogether instead of
            // depending on getting it right.
            $draft->stages()->where('position', '>=', $position)
                ->update(['position' => DB::raw('position + 1000')]);

            $stage = $draft->stages()->create([
                'key'                => $key,
                'label'              => $data['label'],
                'label_ar'           => $data['label_ar'] ?? null,
                'description'        => $data['description'] ?? null,
                'position'           => $position,
                'is_enabled'         => $data['is_enabled'] ?? true,
                'is_initial'         => false,      // set deliberately via setInitial()
                'is_terminal'        => false,
                'is_mandatory'       => $data['is_mandatory'] ?? true,
                'blocks_rental'      => $data['blocks_rental'] ?? false,
                'requirement_key'    => $data['requirement_key'] ?? 'none',
                'requirement_config' => $data['requirement_config'] ?? null,
                'applies_when'       => $data['applies_when'] ?? 'always',
                'tone'               => $data['tone'] ?? null,
            ]);

            // Bring the parked tail back one rung higher than it started (+1000 then −999).
            $draft->stages()->where('position', '>', 1000)
                ->update(['position' => DB::raw('position - 999')]);

            $this->resequence($draft);

            return $stage;
        });
    }

    /**
     * Edit a rung. The KEY is immutable once the workflow has been published — cases store it, and
     * renaming it would orphan every case standing on that stage. Labels are free to change.
     */
    public function updateStage(AccidentWorkflowStage $stage, array $data): AccidentWorkflowStage
    {
        $this->assertDraft($stage->workflow);

        if (isset($data['key']) && $data['key'] !== $stage->key) {
            throw ValidationException::withMessages([
                'key' => 'A stage key cannot be changed — cases are stored against it. Add a new stage instead.',
            ]);
        }

        $stage->update(array_intersect_key($data, array_flip([
            'label', 'label_ar', 'description', 'is_enabled', 'is_mandatory',
            'blocks_rental', 'requirement_key', 'requirement_config', 'applies_when', 'tone',
        ])));

        return $stage->fresh();
    }

    /** Delete a rung from a draft. Never allowed for the initial or a terminal stage. */
    public function deleteStage(AccidentWorkflowStage $stage): void
    {
        $this->assertDraft($stage->workflow);

        if ($stage->is_initial) {
            throw ValidationException::withMessages([
                'stage' => 'The first stage cannot be removed — a case has to begin somewhere. Make another stage the first one instead.',
            ]);
        }
        if ($stage->is_terminal && $stage->workflow->stages()->where('is_terminal', true)->count() <= 1) {
            throw ValidationException::withMessages([
                'stage' => 'The last stage cannot be removed — a case has to be able to finish.',
            ]);
        }

        $workflow = $stage->workflow;
        $stage->delete();
        $this->resequence($workflow);
    }

    /**
     * REORDER — the whole point of the feature. Takes the keys in their new order.
     *
     * Written as a full replacement rather than a move-one-item operation because a drag produces a
     * new ORDER, and applying it as a sequence of swaps is how two people dragging at once end up
     * with a list neither of them chose.
     */
    public function reorder(AccidentWorkflow $draft, array $orderedKeys): AccidentWorkflow
    {
        $this->assertDraft($draft);

        $existing = $draft->stages()->pluck('key')->all();
        if (count($orderedKeys) !== count($existing) || array_diff($existing, $orderedKeys)) {
            throw ValidationException::withMessages([
                'order' => 'The new order must list every stage exactly once.',
            ]);
        }

        DB::transaction(function () use ($draft, $orderedKeys) {
            // Park everything out of range first: positions are uniquely indexed, so writing the new
            // order directly would collide the moment two stages swap.
            $draft->stages()->update(['position' => DB::raw('position + 1000')]);

            foreach (array_values($orderedKeys) as $i => $key) {
                $draft->stages()->where('key', $key)->update(['position' => $i + 1]);
            }
        });

        return $draft->fresh('stages');
    }

    /** Name the stage a case is born on. Exactly one, and it must be enabled. */
    public function setInitial(AccidentWorkflow $draft, string $key): AccidentWorkflow
    {
        $this->assertDraft($draft);

        DB::transaction(function () use ($draft, $key) {
            $draft->stages()->update(['is_initial' => false]);
            $draft->stages()->where('key', $key)->update(['is_initial' => true, 'is_enabled' => true]);
        });

        return $draft->fresh('stages');
    }

    /** Name a stage as an ending. More than one is allowed — a case can finish several ways. */
    public function setTerminal(AccidentWorkflow $draft, string $key, bool $terminal): AccidentWorkflow
    {
        $this->assertDraft($draft);
        $draft->stages()->where('key', $key)->update(['is_terminal' => $terminal]);

        return $draft->fresh('stages');
    }

    // ══ VALIDATION ════════════════════════════════════════════════════════════════════════════

    /**
     * EVERY GUARANTEE, CHECKED. Returns the list of problems — empty means publishable.
     *
     * Exposed as a read so the configuration screen can show them live while somebody edits, and
     * called again inside publish() so the screen is a courtesy rather than the guard.
     *
     * @return array<int, array{key:string, message:string}>
     */
    public function validate(AccidentWorkflow $workflow): array
    {
        $stages = $workflow->stages()->orderBy('position')->get();
        $errors = [];

        if ($stages->isEmpty()) {
            return [['key' => 'empty', 'message' => 'A workflow needs at least one stage.']];
        }

        // ── a beginning, and exactly one ──────────────────────────────────────────────────────
        $initial = $stages->where('is_initial', true);
        if ($initial->isEmpty()) {
            $errors[] = ['key' => 'no_initial', 'message' => 'No first stage is set — a case has to begin somewhere.'];
        } elseif ($initial->count() > 1) {
            $errors[] = ['key' => 'many_initial', 'message' => 'More than one stage is marked as the first. Pick one.'];
        } elseif (! $initial->first()->is_enabled) {
            $errors[] = ['key' => 'initial_disabled', 'message' => 'The first stage is disabled, so no new case could start.'];
        }

        // ── an ending ─────────────────────────────────────────────────────────────────────────
        $terminals = $stages->where('is_terminal', true);
        if ($terminals->isEmpty()) {
            $errors[] = ['key' => 'no_terminal', 'message' => 'No final stage is set — a case would never be able to finish.'];
        }

        // ── the ending is actually at the end ─────────────────────────────────────────────────
        //
        // A terminal stage sitting mid-list would let a case finish while stages after it still read
        // as pending — a closed case with outstanding work behind it.
        $lastActive = $stages->where('is_terminal', false)->where('is_enabled', true)->last();
        foreach ($terminals as $t) {
            if ($lastActive && $t->position < $lastActive->position) {
                $errors[] = ['key' => 'terminal_before_active', 'message' => sprintf(
                    '“%s” is a final stage but sits before “%s”. A final stage has to come last.',
                    $t->label, $lastActive->label,
                )];
            }
        }

        // ── no duplicates ─────────────────────────────────────────────────────────────────────
        foreach (['key' => 'key', 'position' => 'position'] as $field => $noun) {
            $dupes = $stages->groupBy($field)->filter(fn ($g) => $g->count() > 1)->keys();
            foreach ($dupes as $d) {
                $errors[] = ['key' => "duplicate_$field", 'message' => "Two stages share the same $noun (“$d”)."];
            }
        }

        // ── every gate and condition is one the backend can actually evaluate ─────────────────
        foreach ($stages as $s) {
            if (! $this->requirements->has($s->requirement_key)) {
                $errors[] = ['key' => 'unknown_requirement', 'message' => sprintf(
                    '“%s” has a requirement this system does not recognise (%s).', $s->label, $s->requirement_key,
                )];
            }
            if (! $this->conditions->has($s->applies_when)) {
                $errors[] = ['key' => 'unknown_condition', 'message' => sprintf(
                    '“%s” has a condition this system does not recognise (%s).', $s->label, $s->applies_when,
                )];
            }
        }

        // ── at least one rung a case can stand on ─────────────────────────────────────────────
        if ($stages->where('is_enabled', true)->isEmpty()) {
            $errors[] = ['key' => 'all_disabled', 'message' => 'Every stage is disabled — no case could move at all.'];
        }

        return $errors;
    }

    // ══ PUBLISH ═══════════════════════════════════════════════════════════════════════════════

    /**
     * Make the draft the arrangement NEW cases run. Existing cases are untouched — they keep reading
     * the version they were born on, which is the entire reason versions exist.
     */
    public function publish(AccidentWorkflow $draft, User $actor, ?string $notes = null): AccidentWorkflow
    {
        $this->assertDraft($draft);

        $errors = $this->validate($draft);
        if ($errors !== []) {
            throw ValidationException::withMessages([
                'workflow' => array_column($errors, 'message'),
            ]);
        }

        return DB::transaction(function () use ($draft, $actor, $notes) {
            $this->resequence($draft);

            AccidentWorkflow::active()->update(['status' => AccidentWorkflow::STATUS_ARCHIVED]);

            $draft->update([
                'status'            => AccidentWorkflow::STATUS_ACTIVE,
                'published_at'      => Carbon::now(),
                'published_by'      => $actor->id,
                'published_by_name' => $actor->name ?: $actor->email,
                'notes'             => $notes,
            ]);

            // WHICH version is active has just changed, and the engine holds the old answer. Without
            // this, the rest of this request would keep reporting new cases onto the archived ladder.
            // @see AccidentWorkflowService::flushCache()
            app(AccidentWorkflowService::class)->flushCache();

            return $draft->fresh('stages');
        });
    }

    /** Positions must be 1..n with no gaps — a drag leaves holes and the unique index is unforgiving. */
    private function resequence(AccidentWorkflow $workflow): void
    {
        DB::transaction(function () use ($workflow) {
            $workflow->stages()->update(['position' => DB::raw('position + 1000')]);
            $workflow->stages()->orderBy('position')->get()
                ->each(fn ($s, $i) => $s->update(['position' => $i + 1]));
        });
    }

    private function assertDraft(?AccidentWorkflow $workflow): void
    {
        if (! $workflow || ! $workflow->isDraft()) {
            throw ValidationException::withMessages([
                'workflow' => 'Only a draft can be edited. Published versions are read-only — open a new draft to change the process.',
            ]);
        }
    }
}
