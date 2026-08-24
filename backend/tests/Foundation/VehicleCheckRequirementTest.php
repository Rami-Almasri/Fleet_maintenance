<?php

namespace Tests\Foundation;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\Vehicle;
use App\Models\VehicleCheckEvent;
use App\Models\VehicleCheckRequirement;
use App\Models\VehicleLogEvent;
use App\Services\MaintenanceTaskService;
use App\Services\MaintenanceWorkflowService;
use App\Services\VehicleCheckService;
use App\Support\VehicleCheckCatalog;
use RuntimeException;

/**
 * THE DISTINCTION THIS WHOLE ENTITY EXISTS FOR:
 *
 *   "an inspector checked the battery and found nothing"   ≠   "nobody ever checked the battery"
 *
 * Before [[VehicleCheckRequirement]] those two were the same row — which is to say, no row. The only
 * way to close the loop on a system recommendation was to log a fault, so a clean check either
 * produced a fault that did not exist or produced silence indistinguishable from neglect.
 *
 * Everything below pins one half of that: a check cannot be skipped, a clean answer creates NOTHING,
 * a "replace" answer creates exactly one ordinary fault through the EXISTING pipeline, and the whole
 * chain lands on the vehicle timeline where somebody can read it six months later.
 */
class VehicleCheckRequirementTest extends FoundationTestCase
{
    /** The battery-age condition the diagnostic gate emits, in its real shape. */
    private function batteryCondition(string $cycle = 'battery_age|2023-10-01'): array
    {
        return [
            'key'             => 'battery',
            'directive'       => 'routine_maintenance',
            'label'           => 'Battery Status',
            'severity'        => 'routine',
            'detail'          => 'Battery 34 months old (past 30-month life — check the charge).',
            'axis'            => 'date',
            'cycle_key'       => $cycle,
            'resolvable'      => false,
            'finding_keyword' => 'Battery Replacement',
        ];
    }

    private function car(): Vehicle
    {
        // Only an active-fleet car may enter the workflow (assertActiveFleet).
        return $this->makeVehicle(['status' => 'ready']);
    }

    private function checks(): VehicleCheckService
    {
        return app(VehicleCheckService::class);
    }

    /**
     * Drive a car all the way to the Decide step: the monitor raises the request, a controller
     * approves it, the inspector starts the drive. Mirrors the real route exactly.
     */
    private function ticketAtDecideStep(Vehicle $vehicle, array $conditions): Maintenance
    {
        $ticket = app(MaintenanceWorkflowService::class)->systemRequestInspection($vehicle, [
            'note'       => 'Routine check-up due.',
            'conditions' => $conditions,
        ]);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/approve", [])
            ->assertSuccessful();

        // Through the service rather than the endpoint: starting a drive requires an odometer PHOTO
        // upload, which is a real rule about capture quality and nothing to do with system checks.
        // Faking a JPEG in every fixture would test the upload pipeline, not this.
        app(MaintenanceWorkflowService::class)->startDiagnostic($ticket->fresh(), [
            'test_odometer' => $vehicle->fresh()->odometer,
        ], $this->admin);

        return $ticket->fresh();
    }

    private function openChecks(Maintenance $ticket)
    {
        return VehicleCheckRequirement::where('maintenance_id', $ticket->id)->open()->get();
    }

    // ── 1 · Raising ────────────────────────────────────────────────────────────────────────────

    public function test_a_system_condition_creates_exactly_one_requirement(): void
    {
        $vehicle = $this->car();

        $this->checks()->raiseFromConditions($vehicle, [$this->batteryCondition()]);

        $rows = VehicleCheckRequirement::where('vehicle_id', $vehicle->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('battery', $rows[0]->check_type);
        $this->assertSame(VehicleCheckRequirement::STATUS_PENDING, $rows[0]->status);
        // The reason travels as a CODE plus its measured params, never as an English sentence — the
        // UI composes the wording, which is what makes the panel work in Arabic.
        $this->assertSame('check.battery_past_life', $rows[0]->reason_code);
        // …and the gate's legacy English rides along explicitly marked, never as the only copy.
        $this->assertNotNull($rows[0]->detail_en);
    }

    /**
     * THE IDEMPOTENCY GUARANTEE. The monitor runs every morning and will re-derive this same battery
     * condition until the battery is actually replaced. A hundred runs must leave one obligation.
     */
    public function test_repeated_diagnostic_runs_do_not_duplicate_the_requirement(): void
    {
        $vehicle   = $this->car();
        $condition = $this->batteryCondition();

        for ($run = 0; $run < 5; $run++) {
            $this->checks()->raiseFromConditions($vehicle, [$condition]);
        }

        $this->assertSame(1, VehicleCheckRequirement::where('vehicle_id', $vehicle->id)->count());
        // One obligation means one `raised` event too — a re-run must not even append to the trail.
        $this->assertSame(1, VehicleCheckEvent::whereIn(
            'vehicle_check_requirement_id',
            VehicleCheckRequirement::where('vehicle_id', $vehicle->id)->pluck('id'),
        )->where('event', VehicleCheckEvent::RAISED)->count());
    }

    /**
     * A genuinely NEW cycle is a new obligation. The cycle key changes only when the underlying cycle
     * actually rolls (the battery was replaced), so this is the case where a second row is correct —
     * and the dedup above must not suppress it.
     */
    public function test_a_new_cycle_raises_a_new_requirement(): void
    {
        $vehicle = $this->car();

        $this->checks()->raiseFromConditions($vehicle, [$this->batteryCondition('battery_age|2023-10-01')]);
        $this->checks()->raiseFromConditions($vehicle, [$this->batteryCondition('battery_age|2026-04-01')]);

        $this->assertSame(2, VehicleCheckRequirement::where('vehicle_id', $vehicle->id)->count());
    }

    /**
     * An AGENDA condition is several questions. Post-downtime says "go look at Battery, Fluids and
     * Brakes"; collapsing that into one row would let an inspector tick "checked" having looked at one
     * of the three — the same silent gap as not asking at all.
     */
    public function test_an_agenda_condition_fans_out_into_one_requirement_per_item(): void
    {
        $vehicle = $this->car();

        $this->checks()->raiseFromConditions($vehicle, [[
            'key'       => 'downtime',
            'directive' => 'post_downtime',
            'label'     => 'Post-Downtime Safety Check',
            'severity'  => 'moderate',
            'detail'    => '25 days since last maintenance completion.',
            'days'      => 25,
            'checklist' => ['Battery', 'Fluids', 'Brakes'],
            'cycle_key' => 'downtime|2026-07-25',
        ]]);

        $types = VehicleCheckRequirement::where('vehicle_id', $vehicle->id)->pluck('check_type')->sort()->values()->all();

        $this->assertSame(['battery', 'brakes', 'fluids'], $types);
    }

    /**
     * A car can trip the battery-age rule AND the post-downtime agenda in the same scan. Both want to
     * ask about the battery — and asking twice is worse than an untidy list: the inspector reads two
     * identical "Battery" rows, cannot tell them apart, answers the same observation twice, and the
     * analytics then count one look as two.
     *
     * The specific rule wins, because it carries the real reason and the real numbers; the agenda item
     * it covers is not raised at all. Order of the conditions must not change the outcome.
     */
    public function test_an_agenda_does_not_re_ask_what_a_specific_rule_already_asks(): void
    {
        $downtime = [
            'key'       => 'downtime',
            'directive' => 'post_downtime',
            'label'     => 'Post-Downtime Safety Check',
            'severity'  => 'moderate',
            'detail'    => '25 days since last maintenance completion.',
            'days'      => 25,
            'checklist' => ['Battery', 'Fluids', 'Brakes'],
            'cycle_key' => 'downtime|2026-07-25',
        ];

        foreach ([[$this->batteryCondition(), $downtime], [$downtime, $this->batteryCondition()]] as $order) {
            $vehicle = $this->car();
            $this->checks()->raiseFromConditions($vehicle, $order);

            $types = VehicleCheckRequirement::where('vehicle_id', $vehicle->id)->pluck('check_type');

            // Three questions, not four: battery asked ONCE.
            $this->assertSame(3, $types->count());
            $this->assertSame(1, $types->filter(fn ($t) => $t === 'battery')->count());
            $this->assertSame(['battery', 'brakes', 'fluids'], $types->sort()->values()->all());

            // …and the one that survived is the specific rule's, with its own reason and evidence —
            // not the agenda's generic "go look at it".
            $battery = VehicleCheckRequirement::where('vehicle_id', $vehicle->id)->where('check_type', 'battery')->firstOrFail();
            $this->assertSame('check.battery_past_life', $battery->reason_code);
            $this->assertSame('battery', $battery->check_key);
        }
    }

    // ── 2 · Attaching ──────────────────────────────────────────────────────────────────────────

    public function test_the_requirement_is_attached_to_the_inspection_ticket(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);

        $requirement = VehicleCheckRequirement::where('vehicle_id', $vehicle->id)->firstOrFail();

        $this->assertSame($ticket->id, $requirement->maintenance_id);
        $this->assertSame(VehicleCheckRequirement::STATUS_ATTACHED, $requirement->status);
        $this->assertNotNull($requirement->attached_at);
    }

    /**
     * A check raised BEFORE the ticket existed still reaches the next person who opens the bonnet.
     * That is the difference between an obligation and a note the system left itself.
     */
    public function test_a_previously_raised_check_is_adopted_by_the_next_inspection(): void
    {
        $vehicle = $this->car();

        // Raised on its own, with no ticket anywhere.
        $this->checks()->raiseFromConditions($vehicle, [$this->batteryCondition()]);

        // A completely unrelated inspection is now opened on the same car.
        $ticket = $this->ticketAtDecideStep($vehicle, []);

        $this->assertSame(1, $this->openChecks($ticket)->count());
    }

    // ── 3 · A check cannot be silently ignored ─────────────────────────────────────────────────

    /**
     * THE REFUSAL. Three answers are all legitimate — OK, monitor, replace. The fourth, saying
     * nothing, is the only one blocked, because it is the one that used to be indistinguishable from
     * having looked.
     */
    public function test_a_report_cannot_be_filed_while_a_system_check_is_unanswered(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);

        $response = $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => false,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Battery', $response->json('message') ?? '');

        // And the obligation is untouched — a refused report changes nothing.
        $this->assertSame(VehicleCheckRequirement::STATUS_ATTACHED, $this->openChecks($ticket)->first()->status);
    }

    /** A result that means work needs a decision about that work; recording only the result is a dead end. */
    public function test_an_action_bearing_result_cannot_be_filed_without_a_decision(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->first();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => true,
            'fault_severity'       => 'routine',
            'check_results'        => [['id' => $check->id, 'result_code' => 'replace']],
        ])->assertStatus(422);
    }

    // ── 4 · "Checked → OK" — the case the platform could not previously record ─────────────────

    /**
     * The headline behaviour. An inspector looked at the battery, it was fine, and the car's history
     * now says so — with his name and the date on it — while creating no fault, no ticket and no
     * finding. Under the old model this outcome could only be expressed by inventing a fault.
     */
    public function test_an_ok_result_resolves_the_check_and_creates_no_fault(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->first();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => false,
            'check_results'        => [['id' => $check->id, 'result_code' => 'ok']],
        ])->assertSuccessful();

        $check->refresh();

        $this->assertSame(VehicleCheckRequirement::STATUS_RESOLVED, $check->status);
        $this->assertSame('ok', $check->result_code);
        $this->assertSame(VehicleCheckRequirement::RESOLUTION_CONFIRMED_OK, $check->resolution_code);
        // Attributable: we can now name who looked and when.
        $this->assertSame($this->admin->id, $check->inspected_by);
        $this->assertNotNull($check->inspected_at);

        // NOT A FAULT. Not one, anywhere.
        $this->assertSame(0, MaintenanceTask::where('maintenance_id', $ticket->id)->count());
        $this->assertSame(0, MaintenanceTask::where('vehicle_id', $vehicle->id)->count());
        $this->assertSame(Maintenance::WF_DIAGNOSTIC_CLEARED, $ticket->fresh()->workflow_status);
    }

    /**
     * "Monitor" is not a permanent all-clear — it resolves the obligation but buys a defined amount of
     * time, after which the same cycle may legitimately ask again. Still no fault.
     */
    public function test_a_monitor_result_resolves_but_schedules_a_re_raise(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->first();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => false,
            'check_results'        => [['id' => $check->id, 'result_code' => 'monitor']],
        ])->assertSuccessful();

        $check->refresh();

        $this->assertSame(VehicleCheckRequirement::STATUS_RESOLVED, $check->status);
        $this->assertSame(VehicleCheckRequirement::RESOLUTION_MONITORING, $check->resolution_code);
        $this->assertNotNull($check->reraise_after);
        $this->assertSame(0, MaintenanceTask::where('vehicle_id', $vehicle->id)->count());
    }

    // ── 5 · "Checked → Replace → Approved" — one fault, through the existing pipeline ──────────

    public function test_an_approved_replace_creates_exactly_one_maintenance_task(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->first();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => true,
            'fault_severity'       => 'routine',
            'check_results'        => [[
                'id'            => $check->id,
                'result_code'   => 'replace',
                'decision_code' => 'approved',
            ]],
        ])->assertSuccessful();

        $tasks = MaintenanceTask::where('maintenance_id', $ticket->id)->get();

        // EXACTLY one, and it is an ordinary fault the rest of the workflow already knows how to run.
        $this->assertCount(1, $tasks);
        $this->assertSame('Battery Replacement', $tasks[0]->symptom);

        $check->refresh();
        $this->assertSame(VehicleCheckRequirement::STATUS_ACTION_PENDING, $check->status);
        $this->assertSame('approved', $check->decision_code);
        // Bound to the real fault, so completing that fault will discharge this obligation.
        $this->assertSame(MaintenanceTask::class, $check->action_type);
        $this->assertSame($tasks[0]->id, $check->action_id);
    }

    /** A deferred replacement is a decision, not an omission: resolved, recorded, and no fault raised. */
    public function test_a_deferred_decision_resolves_without_creating_a_fault(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->first();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => false,
            'check_results'        => [[
                'id'            => $check->id,
                'result_code'   => 'replace',
                'decision_code' => 'deferred',
            ]],
        ])->assertSuccessful();

        $check->refresh();

        $this->assertSame(VehicleCheckRequirement::STATUS_RESOLVED, $check->status);
        $this->assertSame(VehicleCheckRequirement::RESOLUTION_DEFERRED, $check->resolution_code);
        $this->assertSame(0, MaintenanceTask::where('vehicle_id', $vehicle->id)->count());
    }

    /** "Not required" is the inspector overruling the system — recorded as such, and no fault. */
    public function test_a_not_required_decision_resolves_as_declined(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->first();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => false,
            'check_results'        => [[
                'id'            => $check->id,
                'result_code'   => 'replace',
                'decision_code' => 'not_required',
            ]],
        ])->assertSuccessful();

        $check->refresh();

        $this->assertSame(VehicleCheckRequirement::RESOLUTION_DECLINED, $check->resolution_code);
        $this->assertSame(0, MaintenanceTask::where('vehicle_id', $vehicle->id)->count());
    }

    /**
     * A report may not approve work and simultaneously declare the car needs none. Its own message,
     * because the inspector's mistake is specific and so is the fix.
     */
    public function test_approving_work_contradicts_a_no_maintenance_clearance(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->first();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => false,
            'check_results'        => [[
                'id'            => $check->id,
                'result_code'   => 'replace',
                'decision_code' => 'approved',
            ]],
        ])->assertStatus(422);
    }

    // ── 6 · Completion closes the loop ─────────────────────────────────────────────────────────

    public function test_completing_the_maintenance_task_resolves_the_requirement(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->first();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => true,
            'fault_severity'       => 'routine',
            // Filed as an ON-SITE job: a battery is swapped where the car stands, so the fault
            // legitimately completes without a garage stint. A full garage route reaches the same
            // resolution hook by a longer road.
            'repair_location'      => Maintenance::REPAIR_ON_SITE,
            'check_results'        => [['id' => $check->id, 'result_code' => 'replace', 'decision_code' => 'approved']],
        ])->assertSuccessful();

        $task = MaintenanceTask::where('maintenance_id', $ticket->id)->firstOrFail();

        app(MaintenanceTaskService::class)->setStatus($task, MaintenanceTask::STATUS_COMPLETED, $this->admin);

        $check->refresh();

        $this->assertSame(VehicleCheckRequirement::STATUS_RESOLVED, $check->status);
        $this->assertSame(VehicleCheckRequirement::RESOLUTION_REPAIRED, $check->resolution_code);
        $this->assertNotNull($check->action_completed_at);
    }

    /**
     * A CANCELLED fault repaired nothing. Recording it as `repaired` would quietly inflate every
     * "the recommendation led to a fix" number this layer exists to make trustworthy.
     */
    public function test_a_cancelled_task_resolves_the_requirement_as_declined_not_repaired(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->first();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => true,
            'fault_severity'       => 'routine',
            'check_results'        => [['id' => $check->id, 'result_code' => 'replace', 'decision_code' => 'approved']],
        ])->assertSuccessful();

        $task = MaintenanceTask::where('maintenance_id', $ticket->id)->firstOrFail();

        app(MaintenanceTaskService::class)->setStatus($task, MaintenanceTask::STATUS_CANCELLED, $this->admin);

        $check->refresh();

        $this->assertSame(VehicleCheckRequirement::RESOLUTION_DECLINED, $check->resolution_code);
    }

    // ── 7 · The timeline chain ─────────────────────────────────────────────────────────────────

    /**
     * The story must be readable end to end on the car's own timeline, six months later, without
     * opening a second screen:
     *
     *   check raised → inspector checked → decision → action → resolved
     */
    public function test_the_full_lifecycle_lands_on_the_vehicle_timeline(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->first();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => true,
            'fault_severity'       => 'routine',
            'repair_location'      => Maintenance::REPAIR_ON_SITE,
            'check_results'        => [['id' => $check->id, 'result_code' => 'replace', 'decision_code' => 'approved']],
        ])->assertSuccessful();

        $task = MaintenanceTask::where('maintenance_id', $ticket->id)->firstOrFail();
        app(MaintenanceTaskService::class)->setStatus($task, MaintenanceTask::STATUS_COMPLETED, $this->admin);

        $events = VehicleLogEvent::where('vehicle_id', $vehicle->id)
            ->whereIn('event_type', [
                VehicleLogEvent::EVENT_CHECK_RAISED,
                VehicleLogEvent::EVENT_CHECK_INSPECTED,
                VehicleLogEvent::EVENT_CHECK_DECIDED,
                VehicleLogEvent::EVENT_CHECK_RESOLVED,
            ])
            ->pluck('event_type')
            ->unique()
            ->values()
            ->all();

        sort($events);
        $this->assertSame(
            ['check_decided', 'check_inspected', 'check_raised', 'check_resolved'],
            $events,
        );

        // Every row carries enough to answer what / why / when / by whom without a join.
        $raised = VehicleLogEvent::where('vehicle_id', $vehicle->id)
            ->where('event_type', VehicleLogEvent::EVENT_CHECK_RAISED)
            ->firstOrFail();

        $this->assertSame($check->id, data_get($raised->meta, 'check_requirement_id'));
        $this->assertSame('battery', data_get($raised->meta, 'check_type'));
        $this->assertSame('check.battery_past_life', data_get($raised->meta, 'reason_code'));
    }

    /** The obligation's own trail records each step in order, with its actor. */
    public function test_the_requirement_keeps_its_own_ordered_event_chain(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->first();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => false,
            'check_results'        => [['id' => $check->id, 'result_code' => 'ok']],
        ])->assertSuccessful();

        $chain = $check->events()->pluck('event')->all();

        $this->assertSame(
            [VehicleCheckEvent::RAISED, VehicleCheckEvent::ATTACHED, VehicleCheckEvent::INSPECTED, VehicleCheckEvent::RESOLVED],
            $chain,
        );

        $inspected = $check->events()->where('event', VehicleCheckEvent::INSPECTED)->firstOrFail();
        $this->assertSame($this->admin->id, $inspected->actor_id);
        $this->assertSame('check.result.ok', $inspected->reason_code);
    }

    // ── 8 · The history cannot be quietly rewritten ────────────────────────────────────────────

    public function test_check_events_cannot_be_updated(): void
    {
        $vehicle = $this->car();
        $this->checks()->raiseFromConditions($vehicle, [$this->batteryCondition()]);

        $event = VehicleCheckEvent::query()->latest('id')->firstOrFail();

        $this->expectException(RuntimeException::class);
        $event->update(['reason_code' => 'rewritten']);
    }

    public function test_check_events_cannot_be_deleted(): void
    {
        $vehicle = $this->car();
        $this->checks()->raiseFromConditions($vehicle, [$this->batteryCondition()]);

        $event = VehicleCheckEvent::query()->latest('id')->firstOrFail();

        $this->expectException(RuntimeException::class);
        $event->delete();
    }

    // ── 9 · Nothing that already worked stopped working ────────────────────────────────────────

    /**
     * The overwhelming majority of tickets carry no system checks at all — a driver reports a noise,
     * an inspector files a report. That path must be byte-for-byte unchanged, which is the difference
     * between adding a mechanism and disrupting one.
     */
    public function test_a_ticket_with_no_system_checks_files_a_report_exactly_as_before(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, []);

        $this->assertSame(0, $this->openChecks($ticket)->count());

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => true,
            'fault_severity'       => 'moderate',
            'symptoms'             => ['Engine noise'],
        ])->assertSuccessful();

        $this->assertSame(1, MaintenanceTask::where('maintenance_id', $ticket->id)->count());
        $this->assertSame(Maintenance::WF_INSPECTION_PENDING, $ticket->fresh()->workflow_status);
    }

    /** The pre-existing mirror rule — a clearance may not carry findings — is untouched. */
    public function test_the_existing_clearance_with_findings_guard_still_holds(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, []);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => false,
            'symptoms'             => ['Engine noise'],
        ])->assertStatus(422);
    }

    /**
     * An inspector's own finding and a check-born one coexist on the same report, and produce one
     * fault each — the check's keyword is injected into the SAME findings pipeline rather than a
     * parallel one.
     */
    public function test_check_findings_and_inspector_findings_coexist(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->first();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => true,
            'fault_severity'       => 'moderate',
            'symptoms'             => ['Engine noise'],
            'check_results'        => [['id' => $check->id, 'result_code' => 'replace', 'decision_code' => 'approved']],
        ])->assertSuccessful();

        $symptoms = MaintenanceTask::where('maintenance_id', $ticket->id)->pluck('symptom')->sort()->values()->all();

        $this->assertSame(['Battery Replacement', 'Engine noise'], $symptoms);
    }

    // ── 10 · Tickets already in flight ─────────────────────────────────────────────────────────

    /**
     * The feature must not only work forward. Without the backfill, every ticket created after deploy
     * would carry answerable checks while the tickets people are actually working today would show
     * none — and the first month of analytics would describe a fleet that stopped needing oil changes
     * on the day we shipped.
     */
    public function test_the_backfill_reconstructs_checks_for_a_ticket_already_in_flight(): void
    {
        $vehicle = $this->car();

        // A ticket raised the OLD way: the trigger snapshot exists, the obligations do not.
        $ticket = app(MaintenanceWorkflowService::class)->systemRequestInspection($vehicle, [
            'note'       => 'Routine check-up due.',
            'conditions' => [$this->batteryCondition()],
        ]);
        VehicleCheckRequirement::where('vehicle_id', $vehicle->id)->delete();
        $this->assertSame(0, VehicleCheckRequirement::where('vehicle_id', $vehicle->id)->count());

        $this->artisan('checks:backfill-open-tickets', ['--ticket' => $ticket->id])->assertSuccessful();

        $rebuilt = VehicleCheckRequirement::where('vehicle_id', $vehicle->id)->get();

        $this->assertCount(1, $rebuilt);
        $this->assertSame('battery', $rebuilt[0]->check_type);
        $this->assertSame($ticket->id, $rebuilt[0]->maintenance_id);
        // Source-stamped, so a reconstructed obligation is always distinguishable from one the
        // monitor raised live — the analytics must never present the two as the same evidence.
        $this->assertSame(VehicleCheckRequirement::SOURCE_BACKFILL, $rebuilt[0]->source);
        // …and it carries the evidence the request was ACTUALLY made on, read back from the ticket's
        // own frozen snapshot rather than re-derived from the car's state today.
        $this->assertNotNull($rebuilt[0]->detail_en);
    }

    /** Running the backfill twice must not double the board. */
    public function test_the_backfill_is_idempotent(): void
    {
        $vehicle = $this->car();
        $ticket  = app(MaintenanceWorkflowService::class)->systemRequestInspection($vehicle, [
            'conditions' => [$this->batteryCondition()],
        ]);
        VehicleCheckRequirement::where('vehicle_id', $vehicle->id)->delete();

        $this->artisan('checks:backfill-open-tickets', ['--ticket' => $ticket->id])->assertSuccessful();
        $this->artisan('checks:backfill-open-tickets', ['--ticket' => $ticket->id])->assertSuccessful();

        $this->assertSame(1, VehicleCheckRequirement::where('vehicle_id', $vehicle->id)->count());
    }

    // ── 11 · What the trail can now answer ─────────────────────────────────────────────────────

    /**
     * The question a fault log cannot answer: how many times did we ask, how many did anyone look at,
     * and how many of those came back clean. All three only became countable when "checked, nothing
     * found" started being a row.
     */
    public function test_the_analytics_can_distinguish_looked_at_from_never_looked_at(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->first();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => false,
            'check_results'        => [['id' => $check->id, 'result_code' => 'ok']],
        ])->assertSuccessful();

        // A second car asked the same question that nobody ever answered.
        $ignored = $this->car();
        $this->checks()->raiseFromConditions($ignored, [$this->batteryCondition('battery_age|2021-01-01')]);

        $rows = collect(app(\App\Services\VehicleCheckAnalyticsService::class)->report(30)['by_type'])
            ->firstWhere('check_type', 'battery');

        $this->assertGreaterThanOrEqual(2, $rows['raised']);
        $this->assertGreaterThanOrEqual(1, $rows['inspected']);     // somebody looked
        $this->assertGreaterThanOrEqual(1, $rows['confirmed_ok']);  // …and found nothing
        $this->assertGreaterThanOrEqual(1, $rows['still_open']);    // …while this one is still owed
    }

    // ── 12 · The catalog is what makes this generic ────────────────────────────────────────────

    /**
     * Every keyword must classify as the KIND its result claims — checked through the same resolver
     * the workflow uses, not against a membership list.
     *
     * The membership version of this test passed while brakes turned every scheduled check into a
     * fault, and while tyres pointed at a spelling that matched neither catalog and produced tasks
     * with no kind at all. A guard that cannot see either of those is not a guard.
     */
    public function test_every_catalog_keyword_classifies_as_declared(): void
    {
        $this->assertSame([], VehicleCheckCatalog::vocabularyViolations());
    }

    /**
     * EVERY selectable word in the findings catalog must classify — not only the ones the checks use.
     *
     * A word the catalogs do not recognise is filed as a fault by the legacy shield with no catalog
     * id, no default severity and nothing the reporting layer can group on. 21 of 95 were in that
     * state and were invisible until the picker started badging kind. This is the test that stops
     * them coming back one careless keyword at a time.
     */
    public function test_no_selectable_finding_keyword_is_unclassified(): void
    {
        $classifier   = app(\App\Services\EventClassificationService::class);
        $unclassified = [];

        foreach ((array) config('maintenance_findings.categories', []) as $category) {
            foreach ($category['keywords'] ?? [] as $keyword) {
                if (($classifier->classifyFromFinding(['text' => $keyword])['kind'] ?? null) === null) {
                    $unclassified[] = ($category['key'] ?? '?') . '/' . $keyword;
                }
            }
        }

        $this->assertSame([], $unclassified);
    }

    /**
     * An alias must point at a row that exists, and must never shadow a real catalog name — that would
     * mean the concept has two rows after all and the alias is hiding the fork rather than closing it.
     */
    public function test_every_catalog_alias_resolves_to_a_real_row(): void
    {
        $this->assertSame([], app(\App\Services\EventClassificationService::class)->aliasViolations());
    }

    /**
     * WEAR IS UPKEEP, NOT A BREAKDOWN. Brake pads reaching the end of their life on a scheduled check
     * must produce a SERVICE; a grinding noise on the same check must produce a FAULT. They route,
     * cost and read differently, and the whole point of asking the question is to tell them apart.
     */
    public function test_a_brake_check_produces_a_service_for_wear_and_a_fault_for_a_fault(): void
    {
        $cases = [
            ['result' => 'service_due',  'symptom' => 'Brake Pads (service)',        'kind' => MaintenanceTask::KIND_SERVICE],
            ['result' => 'fault_found',  'symptom' => 'Brake noise (squeal / grind)', 'kind' => MaintenanceTask::KIND_FAULT],
        ];

        foreach ($cases as $case) {
            $vehicle = $this->car();
            $ticket  = $this->ticketAtDecideStep($vehicle, [[
                'key'       => 'downtime',
                'directive' => 'post_downtime',
                'label'     => 'Post-Downtime Safety Check',
                'severity'  => 'moderate',
                'days'      => 25,
                'cycle_key' => 'downtime|2026-07-25',
            ]]);

            // Answer the brakes item as the case dictates; the other two agenda items come back clean.
            $answers = $this->openChecks($ticket)->map(fn ($c) => $c->check_type === 'brakes'
                ? ['id' => $c->id, 'result_code' => $case['result'], 'decision_code' => 'approved']
                : ['id' => $c->id, 'result_code' => 'ok'])->values()->all();

            $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
                'requires_maintenance' => true,
                'fault_severity'       => 'moderate',
                'check_results'        => $answers,
            ])->assertSuccessful();

            $task = MaintenanceTask::where('maintenance_id', $ticket->id)->firstOrFail();

            $this->assertSame($case['symptom'], $task->symptom);
            $this->assertSame($case['kind'], $task->kind, "brakes → {$case['result']} produced the wrong kind");
            // Classified FROM THE CATALOG, not guessed by the legacy shield.
            $this->assertSame(MaintenanceTask::CLS_CATALOG, $task->classification_source);
        }
    }

    /**
     * A check-born SERVICE must join the EXISTING service pipeline, not merely be labelled one.
     *
     * The proof is the catalog link: a service task carries `service_catalog_id`, which is what lets
     * the workshop screens, the invoice split and the reminder roll-forward at ticket close treat it
     * as scheduled work. A task that says kind=service with nothing behind it would read correctly and
     * behave like a fault.
     */
    public function test_a_check_born_service_is_linked_to_the_service_catalog(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->first();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => true,
            'fault_severity'       => 'routine',
            'check_results'        => [['id' => $check->id, 'result_code' => 'replace', 'decision_code' => 'approved']],
        ])->assertSuccessful();

        $task = MaintenanceTask::where('maintenance_id', $ticket->id)->firstOrFail();

        $this->assertSame(MaintenanceTask::KIND_SERVICE, $task->kind);
        $this->assertNotNull($task->service_catalog_id, 'a check-born service must be bound to its service catalog row');
        $this->assertNull($task->fault_catalog_id, 'a service must not also be filed against the fault catalog');
        // …and the catalog row it points at is the right one, carrying the reminder type that rolls
        // this car's battery service forward when the ticket closes.
        $this->assertSame('battery', $task->serviceCatalog?->service_reminder_type);
    }

    /**
     * A check-born FAULT lands on the fault side of the same split — the mirror of the test above.
     */
    public function test_a_check_born_fault_is_linked_to_the_fault_catalog(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [[
            'key' => 'downtime', 'directive' => 'post_downtime', 'label' => 'Post-Downtime Safety Check',
            'severity' => 'moderate', 'days' => 25, 'cycle_key' => 'downtime|2026-07-25',
        ]]);

        $answers = $this->openChecks($ticket)->map(fn ($c) => $c->check_type === 'brakes'
            ? ['id' => $c->id, 'result_code' => 'fault_found', 'decision_code' => 'approved']
            : ['id' => $c->id, 'result_code' => 'ok'])->values()->all();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => true,
            'fault_severity'       => 'moderate',
            'check_results'        => $answers,
        ])->assertSuccessful();

        $task = MaintenanceTask::where('maintenance_id', $ticket->id)->firstOrFail();

        $this->assertSame(MaintenanceTask::KIND_FAULT, $task->kind);
        $this->assertNotNull($task->fault_catalog_id);
        $this->assertNull($task->service_catalog_id);
    }

    /**
     * THE UI WIRING, not just the backend: the payload the Decide step actually renders must carry the
     * options AND, for anything that creates work, the kind it will create.
     *
     * Asserted on the real resource rather than on the service, because a correct service behind a
     * resource that forgets to ship the field is a feature nobody can use.
     */
    public function test_the_ticket_payload_carries_the_checks_and_their_kinds(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);

        $payload = $this->getJson("/api/maintenance-tickets/{$ticket->id}")
            ->assertSuccessful()
            ->json('data.required_checks');

        $this->assertIsArray($payload);
        $this->assertCount(1, $payload);

        $battery = $payload[0];
        $this->assertSame('battery', $battery['check_type']);
        $this->assertTrue($battery['is_open']);
        // Reason as a CODE with its params — the UI composes the sentence, so this reads in Arabic.
        $this->assertSame('check.battery_past_life', $battery['reason_code']);
        // Where the line came from, in one sentence — no black boxes.
        $this->assertNotEmpty($battery['origin']);

        $replace = collect($battery['result_options'])->firstWhere('code', 'replace');
        $this->assertTrue($replace['creates_action']);
        $this->assertSame('Battery Replacement', $replace['finding_keyword']);
        // The kind the inspector sees BEFORE committing to the decision.
        $this->assertSame('service', $replace['kind']);
        $this->assertSame(['approved', 'deferred', 'not_required'], array_column($replace['decisions'], 'code'));

        // …and a clean answer offers no decisions at all, because it creates nothing.
        $ok = collect($battery['result_options'])->firstWhere('code', 'ok');
        $this->assertFalse($ok['creates_action']);
        $this->assertSame([], $ok['decisions']);
        $this->assertNull($ok['kind']);
    }

    /**
     * Every selectable word in the findings catalog reaches the picker carrying its kind, so a
     * supervisor can see whether what he is adding is planned upkeep or something wrong with the car.
     *
     * `null` is a legitimate value and is asserted as PRESENT rather than absent: a word neither
     * catalog recognises falls to the legacy shield and is filed as a fault, and the picker has to be
     * able to say so.
     */
    public function test_the_findings_catalog_ships_a_kind_for_every_keyword(): void
    {
        $meta = $this->getJson('/api/maintenance-tickets/findings-catalog')
            ->assertSuccessful()
            ->json('data.keyword_risk');

        foreach ((array) config('maintenance_findings.categories', []) as $category) {
            foreach ($category['keywords'] ?? [] as $keyword) {
                $this->assertArrayHasKey($keyword, $meta, "no metadata shipped for “{$keyword}”");
                $this->assertArrayHasKey('kind', $meta[$keyword], "no kind shipped for “{$keyword}”");
            }
        }

        $this->assertSame('service', $meta['Oil Change']['kind']);
        $this->assertSame('Service', $meta['Oil Change']['kind_label']);
        $this->assertSame('fault', $meta['Engine noise']['kind']);
    }

    /** The same rule for tyres, which previously produced a task with no kind at all. */
    public function test_a_tyre_check_produces_a_classified_service(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [[
            'key'          => 'reminder:1',
            'label'        => 'Tyre Rotation',
            'severity'     => 'moderate',
            'service_type' => 'tire_rotation',
            'cycle_key'    => 'reminder:1|60000',
        ]]);

        $check = $this->openChecks($ticket)->first();
        $this->assertSame('tyres', $check->check_type);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => true,
            'fault_severity'       => 'routine',
            'check_results'        => [['id' => $check->id, 'result_code' => 'rotate', 'decision_code' => 'approved']],
        ])->assertSuccessful();

        $task = MaintenanceTask::where('maintenance_id', $ticket->id)->firstOrFail();

        $this->assertSame('Tire Rotation', $task->symptom);
        $this->assertSame(MaintenanceTask::KIND_SERVICE, $task->kind);
        $this->assertNotNull($task->kind, 'a check must never produce an unclassified task');

        // AND IT MUST CLOSE ITS OWN LOOP. A service that classifies correctly but does not roll its
        // reminder forward leaves the car flagged overdue the instant it is serviced — which is how
        // the first version of this shipped, using the service catalog's "Tyre" spelling: it typed
        // perfectly and silently never rolled anything. Classification and roll-forward key off the
        // SAME string, so the test asserts both.
        $this->assertSame('tire_rotation', Maintenance::routineServiceTypeFor($task->symptom));
    }

    /**
     * Every keyword a check can produce must satisfy BOTH halves of being a service: it classifies as
     * one, and it rolls its reminder forward when the ticket closes. Asserted across the whole catalog
     * rather than per type, so a service check added next year cannot pass the classification guard
     * while quietly leaving its reminder stuck.
     */
    public function test_every_service_check_keyword_rolls_its_reminder_forward(): void
    {
        $unrolled = [];

        foreach (VehicleCheckCatalog::typeKeys() as $type) {
            foreach (VehicleCheckCatalog::resultOptions($type) as $option) {
                if ($option['kind'] !== 'service' || ! $option['finding_keyword']) {
                    continue;
                }
                if (Maintenance::routineServiceTypeFor($option['finding_keyword']) === null) {
                    $unrolled[] = "$type → {$option['code']} → “{$option['finding_keyword']}”";
                }
            }
        }

        $this->assertSame(
            // KNOWN AND ACCEPTED: brake pads are a real service, but `routine_service_types` has no
            // brake entry, so nothing rolls forward for them today. That is a pre-existing gap in the
            // reminder config — brakes were not a service type before this feature existed — and
            // closing it changes what ticket close writes to the vehicle master record, which is not a
            // decision to slip into a catalog fix. Listed explicitly so it stays visible rather than
            // being discovered again as a mystery.
            ['brakes → service_due → “Brake Pads (service)”'],
            $unrolled,
        );
    }

    /**
     * A gate rule nobody remembered to map still produces an ANSWERABLE obligation rather than
     * vanishing — degrading to `general` is what stops an unmapped rule going unchecked.
     */
    public function test_an_unmapped_condition_still_produces_an_answerable_check(): void
    {
        $vehicle = $this->car();

        $this->checks()->raiseFromConditions($vehicle, [[
            'key'       => 'some_rule_invented_next_year',
            'label'     => 'A rule nobody mapped',
            'severity'  => 'routine',
            'cycle_key' => 'novel|1',
        ]]);

        $requirement = VehicleCheckRequirement::where('vehicle_id', $vehicle->id)->firstOrFail();

        $this->assertSame(VehicleCheckCatalog::FALLBACK_TYPE, $requirement->check_type);
        $this->assertNotEmpty($requirement->resultOptions());
    }

    // ── 12 · The read endpoints, over real HTTP ────────────────────────────────────────────────
    //
    // Everything above drives the lifecycle through the report endpoint, which is where a check is
    // actually answered. These four routes are the OTHER surface — the obligation trail people open
    // when they ask "what did we ask of this car, and did anyone answer?" — and they had no
    // end-to-end coverage at all. A service can be perfect while the route that exposes it binds the
    // wrong model, orders its routes so a static path is swallowed by a wildcard, or hands the
    // frontend a shape it does not expect. None of that is visible from a service-level test.

    /** The obligation trail for one car, over the wire, in the shape the frontend destructures. */
    public function test_the_vehicle_check_trail_endpoint_returns_the_cars_obligations(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->firstOrFail();

        $payload = $this->getJson("/api/vehicle-checks/vehicle/{$vehicle->id}")
            ->assertSuccessful()
            ->json('data');

        $this->assertSame($vehicle->id, $payload['vehicle_id']);
        $this->assertSame(1, $payload['open']);
        $this->assertCount(1, $payload['requirements']);

        $row = $payload['requirements'][0];
        $this->assertSame($check->id, $row['id']);
        $this->assertSame('battery', $row['check_type']);
        $this->assertTrue($row['is_open']);
        // The panel composes its sentence from the CODE and its params, never from English prose.
        $this->assertSame('check.battery_past_life', $row['reason_code']);
        $this->assertNotEmpty($row['result_options']);
        $this->assertNotEmpty($row['origin']);
    }

    /**
     * A RESOLVED check must still come back. "We asked in May, an inspector looked, it was fine" is
     * the answer to most questions this screen gets asked, and it is the whole reason a clean result
     * is a row at all — filtering closed rows out of the default view would restore the blindness.
     */
    public function test_the_vehicle_check_trail_keeps_answered_checks_visible(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->firstOrFail();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => false,
            'check_results'        => [['id' => $check->id, 'result_code' => 'ok']],
        ])->assertSuccessful();

        $all = $this->getJson("/api/vehicle-checks/vehicle/{$vehicle->id}")
            ->assertSuccessful()
            ->json('data');

        $this->assertSame(0, $all['open'], 'the check was answered, so nothing is owed');
        $this->assertCount(1, $all['requirements'], 'but the answered check is still on the record');
        $this->assertSame('ok', $all['requirements'][0]['result_code']);
        $this->assertSame(
            VehicleCheckRequirement::RESOLUTION_CONFIRMED_OK,
            $all['requirements'][0]['resolution_code'],
        );

        // ...and open_only genuinely narrows it, rather than being an ignored parameter.
        $openOnly = $this->getJson("/api/vehicle-checks/vehicle/{$vehicle->id}?open_only=1")
            ->assertSuccessful()
            ->json('data');

        $this->assertCount(0, $openOnly['requirements']);
    }

    /** The append-only chain, over HTTP, in order — what the timeline drawer renders. */
    public function test_the_history_endpoint_returns_the_ordered_event_chain(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->firstOrFail();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => false,
            'check_results'        => [['id' => $check->id, 'result_code' => 'ok']],
        ])->assertSuccessful();

        $payload = $this->getJson("/api/vehicle-checks/{$check->id}/history")
            ->assertSuccessful()
            ->json('data');

        $this->assertSame($check->id, $payload['requirement']['id']);

        $events = array_column($payload['events'], 'event');
        $this->assertSame(
            [
                VehicleCheckEvent::RAISED,
                VehicleCheckEvent::ATTACHED,
                VehicleCheckEvent::INSPECTED,
                VehicleCheckEvent::RESOLVED,
            ],
            $events,
            'the chain must read raised → attached → inspected → resolved, in that order',
        );
    }

    /**
     * `/analytics` is a STATIC path declared before `/{requirement}`. Declared the other way round
     * the wildcard swallows it and the word "analytics" is bound as a requirement id — a 404 that
     * looks like missing data rather than a routing mistake. This pins the ordering.
     */
    public function test_the_analytics_route_is_not_swallowed_by_the_wildcard(): void
    {
        $vehicle = $this->car();
        $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);

        $payload = $this->getJson('/api/vehicle-checks/analytics?days=30')
            ->assertSuccessful()
            ->json('data');

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('overrides', $payload);
        $this->assertArrayHasKey('events', $payload);
    }

    /** Cancelling states a reason, lands on the trail, and cannot be done twice. */
    public function test_cancel_requires_a_reason_records_it_and_refuses_a_closed_check(): void
    {
        $vehicle = $this->car();
        $ticket  = $this->ticketAtDecideStep($vehicle, [$this->batteryCondition()]);
        $check   = $this->openChecks($ticket)->firstOrFail();

        // A reason is not optional — a cancelled check always says why.
        $this->postJson("/api/vehicle-checks/{$check->id}/cancel", [])
            ->assertStatus(422);

        $this->postJson("/api/vehicle-checks/{$check->id}/cancel", [
            'reason_code' => 'check.vehicle_left_fleet',
        ])->assertSuccessful();

        $check->refresh();
        $this->assertSame(VehicleCheckRequirement::STATUS_CANCELLED, $check->status);
        $this->assertFalse($check->isOpen());
        // Never silently: the reason is on the append-only trail with the actor.
        $cancelled = $check->events()->where('event', VehicleCheckEvent::CANCELLED)->firstOrFail();
        $this->assertSame('check.vehicle_left_fleet', $cancelled->reason_code);
        $this->assertSame($this->admin->id, $cancelled->actor_id);

        // And a closed check cannot be cancelled again into a different reason.
        $this->postJson("/api/vehicle-checks/{$check->id}/cancel", [
            'reason_code' => 'check.changed_my_mind',
        ])->assertStatus(422);
    }
}
