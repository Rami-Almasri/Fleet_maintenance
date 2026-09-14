<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\AccidentWorkflow;
use App\Models\AccidentWorkflowStage;
use App\Services\Accident\AccidentWorkflowConfigService;
use App\Services\Accident\ConditionRegistry;
use App\Services\Accident\RequirementRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * THE ACCIDENT PROCESS, EDITED FROM THE FRONT END.
 *
 * This is the controller behind the promise that the accident workflow is configuration rather than
 * code: stages can be added, renamed, reordered, disabled and given different requirements without a
 * migration, a deployment or a developer.
 *
 * THIN ON PURPOSE. Not one rule about what makes a workflow valid lives here — every decision belongs
 * to AccidentWorkflowConfigService, which is also the only thing the acceptance tests exercise. A
 * controller that knew "a workflow needs exactly one initial stage" would be a second opinion on the
 * subject, and the two would eventually disagree; worse, an admin could then reach the invalid state
 * through any route that did not happen to pass through here.
 *
 * BACKEND VALIDATION IS THE REAL VALIDATION. The screen greys out the buttons that would produce an
 * invalid workflow, but the screen is a convenience. `publish()` re-checks everything from scratch and
 * refuses, because the frontend is not a security boundary and never was: a stale tab, a replayed
 * request or somebody with curl must hit exactly the same wall.
 *
 * DRAFT, THEN PUBLISH — never live edits. The active workflow is immutable. Editing opens a DRAFT
 * copy; publishing archives the old version and activates the new one, and every case carries the
 * `workflow_id` it was reported under. That is what stops a reorder today from rewriting the process
 * a six-month-old case was actually worked through. @see AccidentWorkflow
 */
class AccidentWorkflowConfigController extends Controller
{
    public function __construct(
        private AccidentWorkflowConfigService $config,
        private RequirementRegistry $requirements,
        private ConditionRegistry $conditions,
    ) {}

    /**
     * The configuration screen's whole payload: the published process, the draft if one is open, and
     * the CONTROLLED VOCABULARIES.
     *
     * The vocabularies are the answer to "I do not want a system where an admin can type arbitrary
     * rules". A requirement is a KEY into a registry of compiled classes — there is no expression
     * language, no stored code, nothing to inject. The screen renders a dropdown because the dropdown
     * is genuinely all there is.
     */
    public function index()
    {
        $active = AccidentWorkflow::active()->with('stages')->first();
        $draft  = AccidentWorkflow::where('status', AccidentWorkflow::STATUS_DRAFT)->with('stages')->first();

        return ResponseHelper::SuccessResponse([
            'active' => $active ? $this->shape($active) : null,
            'draft'  => $draft ? $this->shape($draft) : null,
            // Present even with no draft open, so the editor can render its pickers before the first edit.
            'vocabulary' => [
                'requirements' => $this->requirements->options(),
                'conditions'   => $this->conditions->options(),
            ],
            'versions' => AccidentWorkflow::orderByDesc('version')
                ->get(['id', 'version', 'name', 'status', 'published_at', 'published_by_name'])
                ->map(fn ($w) => [
                    'id'           => $w->id,
                    'version'      => $w->version,
                    'name'         => $w->name,
                    'status'       => $w->status,
                    'published_at' => optional($w->published_at)->toIso8601String(),
                    'published_by' => $w->published_by_name,
                ]),
        ], 'Accident workflow configuration retrieved');
    }

    /** Start editing — copies the published process into a draft, or returns the draft already open. */
    public function openDraft(Request $request)
    {
        $draft = $this->config->openDraft($request->user());

        return ResponseHelper::SuccessResponse($this->shape($draft), 'Draft opened');
    }

    /** Throw the draft away. The published process is untouched — it always was. */
    public function discardDraft(AccidentWorkflow $workflow)
    {
        $this->config->discardDraft($workflow);

        return ResponseHelper::SuccessResponse(null, 'Draft discarded');
    }

    public function addStage(Request $request, AccidentWorkflow $workflow)
    {
        $data = $this->stageRules($request, creating: true);

        $stage = $this->config->addStage($workflow, $data, $request->user());

        return ResponseHelper::SuccessResponse($this->stage($stage), 'Stage added', 201);
    }

    public function updateStage(Request $request, AccidentWorkflowStage $stage)
    {
        $data = $this->stageRules($request, creating: false);

        return ResponseHelper::SuccessResponse(
            $this->stage($this->config->updateStage($stage, $data)), 'Stage updated');
    }

    public function deleteStage(AccidentWorkflowStage $stage)
    {
        $this->config->deleteStage($stage);

        return ResponseHelper::SuccessResponse(null, 'Stage removed');
    }

    /**
     * THE DRAG-AND-DROP SAVE. The screen sends the keys in the order they now appear and the service
     * rewrites positions to match.
     *
     * Sent as a whole ORDER rather than "move stage X to index 3" deliberately: a move is only
     * meaningful relative to what everything else is doing, and two admins dragging at once with
     * index-based moves would interleave into an order neither of them chose.
     */
    public function reorder(Request $request, AccidentWorkflow $workflow)
    {
        $data = $request->validate([
            'order'   => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'string', 'max:64'],
        ]);

        return ResponseHelper::SuccessResponse(
            $this->shape($this->config->reorder($workflow, $data['order'])), 'Order saved');
    }

    /** Which rung a new case is born on. Exactly one, enforced by the service. */
    public function setInitial(Request $request, AccidentWorkflow $workflow)
    {
        $data = $request->validate(['stage' => ['required', 'string', 'max:64']]);

        return ResponseHelper::SuccessResponse(
            $this->shape($this->config->setInitial($workflow, $data['stage'])), 'Starting stage set');
    }

    /** Which rungs END a case. At least one must exist, and none may sit before an active stage. */
    public function setTerminal(Request $request, AccidentWorkflow $workflow)
    {
        $data = $request->validate([
            'stage'    => ['required', 'string', 'max:64'],
            'terminal' => ['required', 'boolean'],
        ]);

        return ResponseHelper::SuccessResponse(
            $this->shape($this->config->setTerminal($workflow, $data['stage'], $data['terminal'])),
            'Ending stage updated');
    }

    /**
     * A DRY RUN of publish. The screen calls this on every edit so problems are named while they are
     * still cheap to fix, rather than at the end when somebody has built a whole process around one.
     *
     * Returns the same list `publish()` refuses on — one implementation, so the preview can never
     * promise something the save will reject.
     */
    public function validateDraft(AccidentWorkflow $workflow)
    {
        $problems = $this->config->validate($workflow);

        return ResponseHelper::SuccessResponse([
            'ok'       => $problems === [],
            'problems' => $problems,
        ], $problems === [] ? 'This workflow is ready to publish' : 'This workflow cannot be published yet');
    }

    /**
     * PUBLISH — the draft becomes the live process and the previous version is archived, not deleted.
     *
     * From this moment new cases are reported onto the new ladder and every existing case keeps
     * standing on the one it was reported under. Nothing in flight moves, changes stage, or loses its
     * history because somebody edited the process this morning.
     */
    public function publish(Request $request, AccidentWorkflow $workflow)
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        $published = $this->config->publish($workflow, $request->user(), $data['notes'] ?? null);

        return ResponseHelper::SuccessResponse($this->shape($published), 'Workflow published');
    }

    // ── shaping ───────────────────────────────────────────────────────────────────────────────

    /**
     * Validation for a stage, in ONE place for create and edit.
     *
     * `requirement_key` and `applies_when` are constrained to the registries' own key lists — the
     * controlled vocabulary enforced at the edge as well as in the service, so a typo is a 422 naming
     * the field rather than a stage that silently gates on nothing.
     */
    private function stageRules(Request $request, bool $creating): array
    {
        return $request->validate([
            // The key is only accepted on creation; updateStage refuses a change outright.
            'key'                => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'label'              => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'label_ar'           => ['nullable', 'string', 'max:120'],
            'description'        => ['nullable', 'string', 'max:1000'],
            'position'           => ['nullable', 'integer', 'min:1'],
            'is_enabled'         => ['nullable', 'boolean'],
            'is_mandatory'       => ['nullable', 'boolean'],
            'blocks_rental'      => ['nullable', 'boolean'],
            'requirement_key'    => ['nullable', Rule::in($this->requirements->keys())],
            'requirement_config' => ['nullable', 'array'],
            'applies_when'       => ['nullable', Rule::in($this->conditions->keys())],
            'tone'               => ['nullable', 'string', 'max:20'],
        ]);
    }

    private function shape(AccidentWorkflow $workflow): array
    {
        return [
            'id'           => $workflow->id,
            'version'      => $workflow->version,
            'name'         => $workflow->name,
            'status'       => $workflow->status,
            'notes'        => $workflow->notes,
            'published_at' => optional($workflow->published_at)->toIso8601String(),
            'published_by' => $workflow->published_by_name,
            // Whether editing this version would disturb anybody. The screen warns before a publish
            // that lands on top of live cases — it is allowed (they are pinned and safe), but somebody
            // should know they are doing it.
            'has_cases'    => $workflow->hasCases(),
            'stages'       => $workflow->stages()->orderBy('position')->get()
                ->map(fn ($s) => $this->stage($s))->values(),
        ];
    }

    private function stage(AccidentWorkflowStage $stage): array
    {
        $requirement = $this->requirements->get($stage->requirement_key);
        $condition   = $this->conditions->get($stage->applies_when);

        return [
            'id'             => $stage->id,
            'key'            => $stage->key,
            'label'          => $stage->label,
            'label_ar'       => $stage->label_ar,
            'description'    => $stage->description,
            'position'       => $stage->position,
            'is_enabled'     => (bool) $stage->is_enabled,
            'is_initial'     => (bool) $stage->is_initial,
            'is_terminal'    => (bool) $stage->is_terminal,
            'is_mandatory'   => (bool) $stage->is_mandatory,
            'blocks_rental'  => (bool) $stage->blocks_rental,
            'tone'           => $stage->tone,
            'requirement_key'    => $stage->requirement_key,
            'requirement_config' => $stage->requirement_config,
            'applies_when'       => $stage->applies_when,
            // The vocabulary resolved to sentences, so a row reads as a rule rather than as two keys.
            // Read off the SAME registry the engine gates on — a label that drifted from the behaviour
            // would be worse than no label at all.
            'requirement_label'  => $requirement->label(),
            'requirement_hint'   => $requirement->description(),
            'condition_label'    => $condition->label(),
        ];
    }
}
