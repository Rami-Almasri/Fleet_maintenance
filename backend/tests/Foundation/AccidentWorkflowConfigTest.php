<?php

namespace Tests\Foundation;

use App\Models\AccidentCase;
use App\Models\AccidentWorkflow;
use App\Models\AccidentWorkflowStage;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\VehicleDocument;

/**
 * THE PROMISE, TESTED: the accident process is configuration, not code.
 *
 * The three acceptance tests at the bottom of this file are the ones that matter. Each performs a
 * change an operations manager should be able to make on a Tuesday afternoon — reorder the process,
 * switch a stage off, insert a new approval — and each does it through nothing but the HTTP API.
 *
 * NO PHP IS WRITTEN. NO MIGRATION IS RUN. NOTHING IS DEPLOYED.
 *
 * If any of those three ever needs a code change to pass, the architecture has quietly failed and
 * gone back to being a hard-coded ladder wearing a configuration table as a disguise. That is the
 * regression this file exists to catch, and it is not one a unit test of the service could catch —
 * the point is specifically that the WHOLE PATH, from HTTP request to a case's behaviour, contains no
 * step that a developer has to perform.
 *
 * The tests above them cover the guardrails: an admin must not be able to save a process that cannot
 * be walked, and the backend must refuse independently of whatever the screen allowed.
 */
class AccidentWorkflowConfigTest extends FoundationTestCase
{
    // ══ helpers ═══════════════════════════════════════════════════════════════════════════════

    private function openDraft(): array
    {
        return $this->postJson('/api/accident-workflow/draft')->assertOk()->json('data');
    }

    private function config(): array
    {
        return $this->getJson('/api/accident-workflow')->assertOk()->json('data');
    }

    /** The keys of the published process, in the order the office put them. */
    private function publishedOrder(): array
    {
        return AccidentWorkflowStage::whereHas('workflow', fn ($w) => $w->where('status', 'active'))
            ->orderBy('position')->pluck('key')->all();
    }

    private function report(Vehicle $vehicle, array $extra = []): int
    {
        return (int) $this->postJson('/api/accidents', array_merge([
            'vehicle_id'  => $vehicle->id,
            'occurred_at' => now()->subHours(2)->toIso8601String(),
            'location'    => 'Emirates Road',
            'description' => 'Side-swiped changing lanes.',
        ], $extra))->assertCreated()->json('data.id');
    }

    /** A car on hire, so a case has a customer and a contract behind it. */
    private function rentedVehicle(): Vehicle
    {
        $vehicle = $this->makeVehicle(['status' => 'ready', 'operational_status' => 'available']);

        $customer = Customer::create([
            'customer_no' => 'C' . random_int(100000, 999999),
            'name_en'     => 'Config Test Customer',
            'mobile1'     => '0500000000',
        ]);

        Contract::create([
            'contract_no'   => 'RC' . random_int(100000, 999999),
            'contract_type' => 'C',
            'state'         => 'open',
            'vehicle_id'    => $vehicle->id,
            'customer_id'   => $customer->id,
            'out_date'      => now()->subDays(3)->toDateString(),
            'out_time'      => '09:00:00',
        ]);

        return $vehicle;
    }

    /** Answer whatever the case's current rung is asking, so it can be walked forward. */
    private function openTheGate(int $id): void
    {
        $stage = $this->getJson("/api/accidents/$id")->json('data.stage');
        $row   = AccidentWorkflowStage::whereHas('workflow', fn ($w) => $w->where('status', 'active'))
            ->where('key', $stage)->first();

        switch ($row?->requirement_key) {
            case 'police_report':
                $this->postJson("/api/accidents/$id/police", [
                    'police_report_no'   => 'DXB-' . random_int(10000, 99999),
                    'police_report_date' => now()->subDay()->toDateString(),
                    'police_authority'   => 'Dubai Police',
                ])->assertOk();
                $this->postJson("/api/accidents/$id/police/verify")->assertOk();
                break;

            case 'liability_decision':
                $this->postJson("/api/accidents/$id/liability", [
                    'liability_status' => AccidentCase::LIABILITY_UNKNOWN,
                    'liability_source' => 'internal',
                    'note'             => 'Undetermined — no witnesses.',
                ])->assertOk();
                break;

            case 'document_uploaded':
                $case = AccidentCase::findOrFail($id);
                VehicleDocument::create([
                    'vehicle_id'       => $case->vehicle_id,
                    'accident_case_id' => $case->id,
                    'kind'             => VehicleDocument::KIND_DAMAGE_PHOTO,
                    'disk'             => 'public',
                    'file_path'        => 'test/inspection.jpg',
                    'original_name'    => 'inspection.jpg',
                    'mime_type'        => 'image/jpeg',
                    'uploaded_at'      => now(),
                ]);
                break;

            case 'manual_confirmation':
                $this->postJson("/api/accidents/$id/confirm-stage", ['stage' => $row->key, 'note' => 'Done.'])
                    ->assertOk();
                break;
        }
    }

    /**
     * What the case's CURRENT rung is refusing on, before anybody answers it. Empty means the case is
     * free to move. Used to prove a rung is NOT carrying a rule that belongs to a different stage.
     */
    private function refusals(int $id): array
    {
        $show = $this->getJson("/api/accidents/$id")->assertOk();

        $blocker = $show->json('data.workflow.blocked_by');

        return $blocker === null ? [] : [$blocker];
    }

    /** Walk a case forward one rung, answering the gate first. Returns the stage it landed on. */
    private function step(int $id): string
    {
        $this->openTheGate($id);
        $this->postJson("/api/accidents/$id/advance")->assertOk();

        return $this->getJson("/api/accidents/$id")->json('data.stage');
    }

    // ══ THE GUARDRAILS ════════════════════════════════════════════════════════════════════════

    /** Editing never touches the live process — that is what makes it safe to edit at all. */
    public function test_opening_a_draft_copies_the_live_process_and_leaves_it_alone(): void
    {
        $before = $this->publishedOrder();

        $draft = $this->openDraft();

        $this->assertSame(AccidentWorkflow::STATUS_DRAFT, $draft['status']);
        $this->assertSame($before, array_column($draft['stages'], 'key'), 'the draft starts as a copy');
        $this->assertSame($before, $this->publishedOrder(), 'and the published process has not moved');
    }

    /** Two people clicking Edit get the SAME draft, not two rival drafts nobody can reconcile. */
    public function test_opening_a_draft_twice_returns_the_same_draft(): void
    {
        $first  = $this->openDraft();
        $second = $this->openDraft();

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, AccidentWorkflow::where('status', AccidentWorkflow::STATUS_DRAFT)->count());
    }

    /**
     * A PROCESS WITH NO BEGINNING CANNOT BE SAVED — refused by the BACKEND, not by a greyed-out button.
     *
     * The screen stops this too, but the screen is a convenience. Anybody with a stale tab or a curl
     * command has to hit the same wall, which is why the rule lives in the service and is re-checked
     * from scratch at publish rather than trusted from the request.
     */
    public function test_a_workflow_with_no_starting_stage_is_refused(): void
    {
        $draft = $this->openDraft();
        $initial = collect($draft['stages'])->firstWhere('is_initial', true);

        AccidentWorkflowStage::where('workflow_id', $draft['id'])
            ->where('key', $initial['key'])->update(['is_initial' => false]);

        $check = $this->getJson("/api/accident-workflow/{$draft['id']}/validate")->assertOk();
        $this->assertFalse($check->json('data.ok'));
        $this->assertContains('no_initial', array_column($check->json('data.problems'), 'key'));

        $this->postJson("/api/accident-workflow/{$draft['id']}/publish")->assertStatus(422);
        $this->assertSame(AccidentWorkflow::STATUS_DRAFT,
            AccidentWorkflow::find($draft['id'])->status, 'it did not sneak through');
    }

    /** A process with no ending is a process every case is trapped in. Also refused. */
    public function test_a_workflow_with_no_ending_stage_is_refused(): void
    {
        $draft = $this->openDraft();

        AccidentWorkflowStage::where('workflow_id', $draft['id'])->update(['is_terminal' => false]);

        $check = $this->getJson("/api/accident-workflow/{$draft['id']}/validate")->assertOk();
        $this->assertContains('no_terminal', array_column($check->json('data.problems'), 'key'));
        $this->postJson("/api/accident-workflow/{$draft['id']}/publish")->assertStatus(422);
    }

    /**
     * The starting stage cannot be DELETED. Not "should not" — the service refuses, because a case
     * reported the second after such a publish would have nowhere to be born.
     */
    public function test_the_starting_stage_cannot_be_deleted(): void
    {
        $draft   = $this->openDraft();
        $initial = collect($draft['stages'])->firstWhere('is_initial', true);

        $this->deleteJson("/api/accident-workflow/stages/{$initial['id']}")->assertStatus(422);
        $this->assertDatabaseHas('accident_workflow_stages', ['id' => $initial['id']]);
    }

    /**
     * A STAGE KEY IS IMMUTABLE. Cases are stored against it, so renaming one would orphan every case
     * standing on that stage — the system would be holding rows pointing at a rung that no longer
     * exists. Labels are free to change; the key is the identity.
     */
    public function test_a_stage_key_cannot_be_rewritten(): void
    {
        $draft = $this->openDraft();
        $stage = $draft['stages'][1];

        $this->patchJson("/api/accident-workflow/stages/{$stage['id']}", ['key' => 'something_else'])
            ->assertStatus(422);

        // …but its display name is ordinary editable text.
        $this->patchJson("/api/accident-workflow/stages/{$stage['id']}", ['label' => 'Renamed On A Tuesday'])
            ->assertOk()->assertJsonPath('data.label', 'Renamed On A Tuesday');
    }

    /**
     * THE VOCABULARY IS CLOSED. An admin picks a requirement from a list of compiled classes; there is
     * no expression to type, and a key that is not in the registry is a 422 naming the field.
     *
     * This is the answer to "I do not want a dangerous system where an admin can type arbitrary
     * rules": there is nothing to type, so there is nothing to inject.
     */
    public function test_a_requirement_outside_the_vocabulary_is_refused(): void
    {
        $draft = $this->openDraft();

        $this->postJson("/api/accident-workflow/{$draft['id']}/stages", [
            'label'           => 'Creative Gate',
            'requirement_key' => 'exec(rm -rf)',
        ])->assertStatus(422)->assertJsonValidationErrors('requirement_key');

        $this->postJson("/api/accident-workflow/{$draft['id']}/stages", [
            'label'        => 'Creative Condition',
            'applies_when' => 'whenever_i_feel_like_it',
        ])->assertStatus(422)->assertJsonValidationErrors('applies_when');
    }

    /**
     * PUBLISHING DOES NOT DISTURB A CASE ALREADY IN FLIGHT.
     *
     * The case keeps the workflow it was reported under, so a process edited this morning cannot
     * rewrite the history of a case opened last month — or, worse, leave it standing on a rung its own
     * ladder no longer contains.
     */
    public function test_a_case_in_flight_keeps_the_process_it_was_reported_under(): void
    {
        $id     = $this->report($this->rentedVehicle());
        $pinned = AccidentCase::find($id)->workflow_id;
        $stage  = $this->getJson("/api/accidents/$id")->json('data.stage');

        $draft = $this->openDraft();
        $this->postJson("/api/accident-workflow/{$draft['id']}/publish")->assertOk();

        $case = AccidentCase::find($id);
        $this->assertSame($pinned, $case->workflow_id, 'the case is pinned to its own version');
        $this->assertSame($stage, $case->stage, 'and it has not been moved');
        $this->assertNotSame($pinned, AccidentWorkflow::active()->value('id'),
            'while new cases get the new version');
    }

    // ══ THE ACCEPTANCE TESTS ══════════════════════════════════════════════════════════════════
    //
    // No PHP changes. No migration. No deployment. Configuration only.

    /**
     * §18 — REORDER THE PROCESS, AND THE REQUIREMENT MOVES WITH ITS STAGE.
     *
     * This is the test the whole architecture was rebuilt for. The old ladder carried the police gate
     * as an inline check comparing a stage NAME, which meant the rule effectively belonged to POSITION
     * TWO. Reordering would have left the guardrail watching the wrong rung — silently, with nothing
     * failing and nobody told.
     *
     * So: drag the police report from second to fourth, publish, report a crash — and prove the gate
     * still refuses at the rung it now occupies, and that the rungs before it do not inherit it.
     */
    public function test_acceptance_18_stages_can_be_reordered_and_the_rule_travels_with_the_stage(): void
    {
        $draft = $this->openDraft();
        $order = array_column($draft['stages'], 'key');

        $this->assertSame('police_report', $order[1], 'precondition: it starts second');

        // Move it two rungs later — the manager drags the card down; the screen sends the new order.
        $moved = array_values(array_diff($order, ['police_report']));
        array_splice($moved, 3, 0, 'police_report');

        $this->postJson("/api/accident-workflow/{$draft['id']}/reorder", ['order' => $moved])->assertOk();
        $this->postJson("/api/accident-workflow/{$draft['id']}/publish")->assertOk();

        $published = $this->publishedOrder();
        $this->assertSame(3, array_search('police_report', $published, true), 'it is now fourth');

        // Now walk a real case and watch the gate hold where the stage now IS.
        $id = $this->report($this->rentedVehicle());

        // Walk until the police rung, asserting nothing BEFORE it demands a police report — the rule
        // travelled with its stage rather than staying behind at position two.
        //
        // Walked against what the case actually does rather than against `$published`, because the two
        // legitimately differ: `charge_customer` only applies when the renter is liable, and this case
        // records `unknown`, so that rung is not on this case's ladder at all. Asserting the raw
        // published order here would be asserting that conditional stages do not work.
        $visited = [$this->getJson("/api/accidents/$id")->json('data.stage')];
        for ($i = 0; $i < 8 && end($visited) !== 'police_report'; $i++) {
            // Each rung may well block on its OWN rule — the liability rung quite properly refuses
            // until somebody decides fault. What none of them may do is demand the POLICE REPORT,
            // which now belongs four rungs further down.
            $this->assertStringNotContainsStringIgnoringCase('police report', implode(' ', $this->refusals($id)),
                'rung ' . end($visited) . ' demanded paperwork it no longer owns');
            $visited[] = $this->step($id);
        }

        $this->assertSame('police_report', end($visited),
            'the walk reached the police rung: ' . implode(' → ', $visited));
        $this->assertNotContains('police_report', array_slice($visited, 0, -1));
        $refusal = $this->postJson("/api/accidents/$id/advance")->assertStatus(422);
        $this->assertStringContainsString('police report',
            strtolower($refusal->json('message') . json_encode($refusal->json('errors'))));

        // Answer it, and the case moves on. The gate is a gate, not a wall.
        $this->openTheGate($id);
        $this->postJson("/api/accidents/$id/advance")->assertOk();
        $this->assertSame($published[4], $this->getJson("/api/accidents/$id")->json('data.stage'));
    }

    /**
     * §19 — DISABLE A STAGE, AND NEW CASES STOP VISITING IT.
     *
     * Switching Settlement off is the realistic version of this: an office that tracks money elsewhere
     * should not have to walk every crash through a rung that means nothing to them.
     *
     * The two halves that matter are in tension. A disabled stage must receive NO new cases — and a
     * case ALREADY standing on it must still be resolvable, because switching a stage off must never
     * strand the cars that were mid-process when somebody clicked the toggle.
     */
    public function test_acceptance_19_a_disabled_stage_is_skipped_but_never_strands_a_case(): void
    {
        // A case parked ON settlement under the CURRENT configuration, before anybody disables it.
        $stranded = $this->report($this->rentedVehicle());
        AccidentCase::where('id', $stranded)->update(['stage' => 'settlement']);

        $draft      = $this->openDraft();
        $settlement = collect($draft['stages'])->firstWhere('key', 'settlement');

        $this->patchJson("/api/accident-workflow/stages/{$settlement['id']}", ['is_enabled' => false])
            ->assertOk()->assertJsonPath('data.is_enabled', false);
        $this->postJson("/api/accident-workflow/{$draft['id']}/publish")->assertOk();

        // ── new cases never see it ────────────────────────────────────────────────────────────
        $fresh = $this->report($this->rentedVehicle());
        $seen  = [];
        for ($i = 0; $i < 12; $i++) {
            $stage = $this->getJson("/api/accidents/$fresh")->json('data.stage');
            $seen[] = $stage;
            if (AccidentWorkflowStage::whereHas('workflow', fn ($w) => $w->where('status', 'active'))
                ->where('key', $stage)->value('is_terminal')) {
                break;
            }
            $this->step($fresh);
        }

        $this->assertNotContains('settlement', $seen, 'a disabled stage receives no new cases');
        $this->assertContains('closed', $seen, 'and the case still reaches an ending');

        // ── but the case already standing there is not trapped ────────────────────────────────
        $show = $this->getJson("/api/accidents/$stranded")->assertOk();
        $this->assertSame('settlement', $show->json('data.stage'), 'it is still where it was');
        $this->assertNotEmpty(
            collect($show->json('data.workflow.stages'))->firstWhere('key', 'settlement'),
            'its own ladder still contains the rung it stands on'
        );

        $this->postJson("/api/accidents/$stranded/advance")->assertOk();
        $this->assertNotSame('settlement', $this->getJson("/api/accidents/$stranded")->json('data.stage'),
            'and it can still be moved along');
    }

    /**
     * §20 — INSERT A BRAND-NEW STAGE THAT NOBODY WROTE CODE FOR.
     *
     * "Management Approval" does not exist anywhere in this codebase. It is invented in the request
     * body, given the generic `manual_confirmation` gate, dropped into the middle of the process and
     * published — and from that moment it holds real cases until a named person confirms it.
     *
     * `manual_confirmation` is what makes this possible without a migration: it is the gate that means
     * "somebody did this thing and said so", and it stores the confirmation generically. Without it,
     * every new stage would need a table, a service and a deploy — which is the situation this whole
     * architecture exists to end.
     */
    public function test_acceptance_20_a_brand_new_approval_stage_can_be_inserted_and_it_holds_cases(): void
    {
        $draft = $this->openDraft();

        // Invented here, in the request. No class, no migration, no constant.
        $created = $this->postJson("/api/accident-workflow/{$draft['id']}/stages", [
            'label'           => 'Management Approval',
            'label_ar'        => 'موافقة الإدارة',
            'description'     => 'A manager signs the case off before the insurer is involved.',
            'requirement_key' => 'manual_confirmation',
            'applies_when'    => 'always',
            'is_mandatory'    => true,
            'blocks_rental'   => true,
            'position'        => 3,
            'tone'            => '#0ea5e9',
        ])->assertCreated()->json('data');

        $this->assertSame('management_approval', $created['key'], 'the key is slugged from the label');

        $this->postJson("/api/accident-workflow/{$draft['id']}/publish")->assertOk();

        $published = $this->publishedOrder();
        $this->assertSame(2, array_search('management_approval', $published, true),
            'it sits third, where it was dropped');

        // ── and it actually holds a case ──────────────────────────────────────────────────────
        $id = $this->report($this->rentedVehicle());
        $this->step($id);
        $this->step($id);

        $this->assertSame('management_approval', $this->getJson("/api/accidents/$id")->json('data.stage'));

        // Nobody has confirmed it, so nothing moves. A stage invented five minutes ago gates as hard
        // as the police report that has been in the code since the beginning.
        $this->postJson("/api/accidents/$id/advance")->assertStatus(422);
        $this->assertSame('management_approval', $this->getJson("/api/accidents/$id")->json('data.stage'));

        // A named person confirms it — and that confirmation is stored, not merely waved through.
        $this->postJson("/api/accidents/$id/confirm-stage", [
            'stage' => 'management_approval',
            'note'  => 'Approved by the ops manager.',
        ])->assertOk();

        $this->assertDatabaseHas('accident_stage_completions', [
            'accident_case_id' => $id,
            'stage_key'        => 'management_approval',
        ]);

        $this->postJson("/api/accidents/$id/advance")->assertOk();
        $this->assertNotSame('management_approval', $this->getJson("/api/accidents/$id")->json('data.stage'));
    }
}
