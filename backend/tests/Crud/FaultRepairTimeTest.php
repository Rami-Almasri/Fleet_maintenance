<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceTaskAssignment;
use App\Services\FaultRepairTimeService;
use App\Services\MaintenanceTaskService;
use Illuminate\Support\Carbon;

/**
 * Per-fault repair time — the business contract these tests pin:
 *
 *   1. A FAILED re-inspection CONTINUES the same fault's cumulative timer: attempt #1's elapsed and
 *      labor stay immutable on their closed stint; attempt #2 adds to the same task row.
 *   2. A recurrence AFTER a passed re-inspection is a NEW task row whose timer starts at zero — the
 *      recurrence link is history, never a sum.
 *   3. Labor entries are write-once per attempt: a duplicate Make-Ready-style submit can neither
 *      overwrite nor double-count.
 *   4. A transfer is the SAME attempt continuing at another garage, not a new attempt.
 */
class FaultRepairTimeTest extends CrudTestCase
{
    private function ticketAtGarage(): Maintenance
    {
        return Maintenance::create([
            'vehicle_id'      => $this->makeVehicle(),
            'origin'          => 'manual',
            'event_status'    => 'OUT',
            'workflow_status' => Maintenance::WF_UNDER_REPAIR,
            'garage'          => 'Attempt Garage',
        ]);
    }

    private function fault(Maintenance $ticket, string $symptom = 'Oil leak'): MaintenanceTask
    {
        return MaintenanceTask::create([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $ticket->vehicle_id,
            'symptom'        => $symptom,
            'status'         => MaintenanceTask::STATUS_PENDING,
            'identified_at'  => Carbon::now()->subDay(),
        ]);
    }

    /**
     * Open a stint by hand with a controlled start time (assign() always stamps now()).
     *
     * `assignedAt` is the DISPATCH moment — shared by every fault on the ticket. `workStartedAt` is the
     * per-fault signal the workshop confirmation stamps; passing different values per fault is what
     * makes sibling faults measurable independently.
     */
    private function openStint(MaintenanceTask $task, int $vendorId, Carbon $at, ?Carbon $workStartedAt = null): MaintenanceTaskAssignment
    {
        $task->forceFill(['status' => MaintenanceTask::STATUS_IN_PROGRESS, 'current_vendor_id' => $vendorId])->save();

        return MaintenanceTaskAssignment::create([
            'maintenance_task_id' => $task->id,
            'vendor_id'           => $vendorId,
            'assigned_at'         => $at,
            'work_started_at'     => $workStartedAt ?? $at,
            'assigned_by'         => $this->admin->id,
        ]);
    }

    public function test_failed_reinspection_accumulates_and_attempt_one_is_immutable(): void
    {
        $ticket = $this->ticketAtGarage();
        $task   = $this->fault($ticket);
        $vendor = $this->makeVendor();
        $tasks  = app(MaintenanceTaskService::class);
        $svc    = app(FaultRepairTimeService::class);

        // Attempt #1 — 4h at the garage, marked fixed with 2.0h manual labor. (setStatus stamps the
        // release at now(), so backdate it to keep the two attempts chronological, as in real life.)
        $this->openStint($task, $vendor, Carbon::now()->subHours(10));
        $tasks->setStatus($task->refresh(), MaintenanceTask::STATUS_COMPLETED, $this->admin, 'replaced gasket', null, 2.0);
        $task->assignments()->latest('id')->first()->forceFill(['released_at' => Carbon::now()->subHours(6)])->save();

        // Re-inspection FAILS — same fault continues.
        $tasks->failReinspection($task->refresh(), 'still leaking', $this->admin);
        $task->refresh();
        $this->assertSame(MaintenanceTask::STATUS_PENDING, $task->status);
        $this->assertSame(1, (int) $task->reinspection_failures);

        // Attempt #2 — 6h, fixed with 3.0h labor.
        $this->openStint($task, $vendor, Carbon::now()->subHours(6));
        $tasks->setStatus($task->refresh(), MaintenanceTask::STATUS_COMPLETED, $this->admin, 'resealed pan', null, 3.0);

        $time = $svc->forTask($task->refresh()->load('assignments'));

        $this->assertSame(2, $time['attempt_count']);
        $this->assertSame('resolved', $time['attempts'][1]['outcome']);
        // Attempt #1 survived the failed re-inspection untouched: its own labor, its own elapsed.
        $this->assertSame(2.0, $time['attempts'][0]['labor_hours']);
        $this->assertSame(3.0, $time['attempts'][1]['labor_hours']);
        $this->assertSame(5.0, $time['cumulative_labor_hours']);
        // Cumulative work ≈ 4h + 6h (seconds precision).
        $this->assertEqualsWithDelta(10 * 3600, $time['cumulative_work_seconds'], 120);
        // The derived cache on the task row followed the stints.
        $this->assertSame(5.0, (float) $task->refresh()->repair_hours);
    }

    public function test_recurrence_after_pass_is_a_new_occurrence_with_its_own_timer(): void
    {
        $svc = app(FaultRepairTimeService::class);

        // Occurrence #1 — resolved, 4h elapsed / 2h labor, on its own (closed) ticket.
        $ticket1 = $this->ticketAtGarage();
        $task1   = $this->fault($ticket1);
        $vendor  = $this->makeVendor();
        $this->openStint($task1, $vendor, Carbon::now()->subDays(14)->subHours(4));
        app(MaintenanceTaskService::class)->setStatus($task1->refresh(), MaintenanceTask::STATUS_COMPLETED, $this->admin, 'fixed', null, 2.0);

        // Occurrence #2 two weeks later — a NEW task row, soft-linked to the old one.
        $ticket2 = $this->ticketAtGarage();
        $task2   = $this->fault($ticket2);
        $task2->forceFill(['recurrence_flagged' => true, 'recurrence_previous_task_id' => $task1->id])->save();
        $this->openStint($task2, $vendor, Carbon::now()->subHours(6));
        app(MaintenanceTaskService::class)->setStatus($task2->refresh(), MaintenanceTask::STATUS_COMPLETED, $this->admin, 'fixed again', null, 3.0);

        $time2 = $svc->forTask($task2->refresh()->load('assignments'));

        // 6h / 3h — NOT 10h / 5h: the previous occurrence is never summed in.
        $this->assertSame(1, $time2['attempt_count']);
        $this->assertEqualsWithDelta(6 * 3600, $time2['cumulative_work_seconds'], 120);
        $this->assertSame(3.0, $time2['cumulative_labor_hours']);
        $this->assertSame(3.0, (float) $task2->refresh()->repair_hours);
    }

    public function test_labor_is_write_once_per_attempt_and_duplicates_are_noops(): void
    {
        $ticket = $this->ticketAtGarage();
        $task   = $this->fault($ticket);
        $vendor = $this->makeVendor();
        $svc    = app(FaultRepairTimeService::class);

        $this->openStint($task, $vendor, Carbon::now()->subHours(3));
        app(MaintenanceTaskService::class)->setStatus($task->refresh(), MaintenanceTask::STATUS_COMPLETED, $this->admin, 'done');

        // First Make-Ready-style entry lands…
        $this->assertTrue($svc->recordAttemptLabor($task->refresh(), 1.5, $this->admin));
        // …the duplicate submit (double-click / resent request) is a harmless no-op…
        $this->assertFalse($svc->recordAttemptLabor($task->refresh(), 9.0, $this->admin));

        $stint = $task->assignments()->latest('id')->first();
        $this->assertSame(1.5, (float) $stint->labor_hours);
        $this->assertSame(1.5, (float) $task->refresh()->repair_hours);

        // …and a real correction goes through the audited overwrite path.
        $svc->overwriteAttemptLabor($stint, 2.25, 'garage sent the corrected job card', $this->admin);
        $this->assertSame(2.25, (float) $task->refresh()->repair_hours);
    }

    /**
     * THE REGRESSION THIS SUITE EXISTS FOR. Three faults dispatched together on ONE car must NOT all
     * report the same duration. Their stints share `assigned_at` (dispatch is one moment for the whole
     * car), so a stint-based measure hands every fault the vehicle's workshop time — the exact thing
     * the per-fault requirement forbids. Each fault's own clock starts when the workshop CONFIRMS it,
     * which happens per fault, at different times.
     */
    public function test_sibling_faults_on_one_car_get_independent_times(): void
    {
        $ticket   = $this->ticketAtGarage();
        $vendor   = $this->makeVendor();
        $tasks    = app(MaintenanceTaskService::class);
        $svc      = app(FaultRepairTimeService::class);
        $dispatch = Carbon::now()->subHours(12); // the ONE shared dispatch instant

        // Each fault is confirmed at a different moment — 10h, 6h and 3h before it gets fixed.
        $plan = [
            ['symptom' => 'Oil leak',     'work' => 10],
            ['symptom' => 'Brake noise',  'work' => 6],
            ['symptom' => 'Battery weak', 'work' => 3],
        ];

        $faults = [];
        foreach ($plan as $p) {
            $fault = $this->fault($ticket, $p['symptom']);
            $this->openStint($fault, $vendor, $dispatch, Carbon::now()->subHours($p['work']));
            $faults[] = [$fault, $p['work']];
        }

        // All three are marked fixed in the same sweep — the realistic case where a custody-based
        // measure would collapse them onto one identical number.
        foreach ($faults as [$fault]) {
            $tasks->setStatus($fault->refresh(), MaintenanceTask::STATUS_COMPLETED, $this->admin, 'fixed');
        }

        $seen = [];
        foreach ($faults as [$fault, $expectedHours]) {
            $time = $svc->forTask($fault->refresh()->load('assignments'));

            $this->assertSame('confirmed_or_started', $time['basis']);
            $this->assertEqualsWithDelta($expectedHours * 3600, $time['cumulative_work_seconds'], 120,
                "{$fault->symptom} must report its OWN work time, not the car's workshop time");
            // Custody is the same ~12h for all three — which is exactly why it is never the fault number.
            $this->assertEqualsWithDelta(12 * 3600, $time['cumulative_custody_seconds'], 120);
            $seen[] = $time['cumulative_work_seconds'];
        }

        $this->assertCount(3, array_unique($seen), 'the three faults must not all report the same duration');
    }

    /** With no confirmation recorded, a fault has no clock of its own — and must SAY so, not borrow the car's. */
    public function test_an_unconfirmed_fault_reports_custody_basis_not_a_fake_fault_time(): void
    {
        $ticket = $this->ticketAtGarage();
        $task   = $this->fault($ticket);

        // A stint with NO work signal at all (never confirmed, never explicitly started).
        MaintenanceTaskAssignment::create([
            'maintenance_task_id' => $task->id,
            'vendor_id'           => $this->makeVendor(),
            'assigned_at'         => Carbon::now()->subHours(8),
            'released_at'         => Carbon::now(),
            'outcome'             => MaintenanceTaskAssignment::OUTCOME_RESOLVED,
        ]);

        $time = app(FaultRepairTimeService::class)->forTask($task->refresh()->load('assignments'));

        $this->assertNull($time['cumulative_work_seconds'], 'no per-fault signal ⇒ no per-fault number');
        $this->assertSame('custody', $time['basis']);
        $this->assertEqualsWithDelta(8 * 3600, $time['cumulative_custody_seconds'], 120);
    }

    /** Confirming a fault starts its clock; re-confirming must never restart or extend it. */
    public function test_confirmation_starts_the_clock_once(): void
    {
        $ticket = $this->ticketAtGarage();
        $task   = $this->fault($ticket);
        $tasks  = app(MaintenanceTaskService::class);

        MaintenanceTaskAssignment::create([
            'maintenance_task_id' => $task->id,
            'vendor_id'           => $this->makeVendor(),
            'assigned_at'         => Carbon::now()->subHours(5),
            'assigned_by'         => $this->admin->id,
        ]);
        $task->forceFill(['status' => MaintenanceTask::STATUS_PENDING])->save();

        $tasks->confirmFault($task->refresh(), MaintenanceTask::CONFIRM_CONFIRMED, $this->admin);
        $first = $task->assignments()->first()->refresh()->work_started_at;
        $this->assertNotNull($first, 'the confirmation must start this fault’s clock');

        // Re-confirm + push through in_progress — the original start must survive both.
        $tasks->confirmFault($task->refresh(), MaintenanceTask::CONFIRM_CONFIRMED, $this->admin);
        $tasks->setStatus($task->refresh(), MaintenanceTask::STATUS_IN_PROGRESS, $this->admin);

        $this->assertSame(
            $first->toDateTimeString(),
            $task->assignments()->first()->refresh()->work_started_at->toDateTimeString(),
            'the work clock must never be restarted',
        );
    }

    /**
     * The "Mark ready" screen books every fault's hours in ONE submit, keyed by task_id. Each value must
     * land on its OWN fault — and a re-submit must not double-count (the gate can be hit twice on a
     * failed-reinspection cycle, and browsers resend).
     */
    public function test_mark_ready_books_time_per_fault_in_one_submit(): void
    {
        $ticket = $this->ticketAtGarage();
        $vendor = $this->makeVendor();
        $tasks  = app(MaintenanceTaskService::class);

        $a = $this->fault($ticket, 'Engine noise');
        $b = $this->fault($ticket, 'Dashboard fault');
        foreach ([$a, $b] as $f) {
            $this->openStint($f, $vendor, Carbon::now()->subHours(6));
            $tasks->setStatus($f->refresh(), MaintenanceTask::STATUS_COMPLETED, $this->admin, 'fixed');
        }

        app(\App\Services\MaintenanceWorkflowService::class)->markReady($ticket->refresh(), [
            'repair_times' => [
                ['task_id' => $a->id, 'text' => $a->symptom, 'hours' => 4],
                ['task_id' => $b->id, 'text' => $b->symptom, 'hours' => 1.5],
            ],
        ], $this->admin);

        // Each fault kept its own number — no smearing of one figure across the ticket.
        $this->assertSame(4.0, (float) $a->refresh()->repair_hours);
        $this->assertSame(1.5, (float) $b->refresh()->repair_hours);

        // A resent submit is a no-op, not a double-count.
        app(\App\Services\FaultRepairTimeService::class)->recordAttemptLabor($a->refresh(), 4, $this->admin);
        $this->assertSame(4.0, (float) $a->refresh()->repair_hours);
    }

    public function test_transfer_is_one_attempt_across_two_garages(): void
    {
        $ticket = $this->ticketAtGarage();
        $task   = $this->fault($ticket);
        $tasks  = app(MaintenanceTaskService::class);

        $this->openStint($task, $this->makeVendor(), Carbon::now()->subHours(5));
        // transfer() closes the stint transferred_out and opens one at the next garage.
        $tasks->transfer($task->refresh(), $this->makeVendor(), 'specialist needed', $this->admin);
        $task->refresh()->forceFill(['status' => MaintenanceTask::STATUS_PENDING])->save(); // arrival check-in
        $tasks->setStatus($task->refresh(), MaintenanceTask::STATUS_COMPLETED, $this->admin, 'fixed at specialist', null, 4.0);

        $time = app(FaultRepairTimeService::class)->forTask($task->refresh()->load('assignments'));

        // Two stints, ONE attempt: the hand-off is part of the same repair.
        $this->assertSame(1, $time['attempt_count']);
        $this->assertCount(2, $time['attempts'][0]['stints']);
        $this->assertSame(4.0, $time['attempts'][0]['labor_hours']);
    }

    // ── THE WORK CLOCK AND THE LABOR CEILING ──────────────────────────────────────────────────────

    /**
     * THE HEADLINE RULE. A fault whose recorded work window is 3 hours cannot be booked 4 hours of
     * labor. It is REJECTED — never quietly clamped to 3, because silently rewriting a mechanic's entry
     * destroys the one signal worth having: that the record and the clock disagree.
     */
    public function test_labor_above_the_work_ceiling_is_rejected_and_never_clamped(): void
    {
        $ticket = $this->ticketAtGarage();
        $task   = $this->fault($ticket);
        $svc    = app(FaultRepairTimeService::class);

        // Work ran 10:00 → 13:00. Exactly three hours on the clock.
        $this->openStint($task, $this->makeVendor(), Carbon::now()->subHours(4), Carbon::now()->subHours(3));
        app(MaintenanceTaskService::class)->setStatus($task->refresh(), MaintenanceTask::STATUS_COMPLETED, $this->admin, 'done');

        try {
            $svc->recordAttemptLabor($task->refresh(), 4.0, $this->admin);
            $this->fail('4h of labor against a 3h work window must be rejected.');
        } catch (\App\Exceptions\WorkflowTransitionException $e) {
            $this->assertStringContainsString('cannot exceed the recorded fault work duration', $e->getMessage());
            $this->assertStringContainsString('3h 00m', $e->getMessage());
        }

        // NOTHING was written — not 4, and emphatically not a silently-clamped 3.
        $this->assertNull($task->refresh()->repair_hours);
        $this->assertNull($task->assignments()->latest('id')->first()->labor_hours);

        // The honest number for the same window is accepted, and recorded as MEASURED-by-window.
        $this->assertTrue($svc->recordAttemptLabor($task->refresh(), 3.0, $this->admin));
        $this->assertSame(3.0, (float) $task->refresh()->repair_hours);
        $this->assertSame(
            \App\Models\MaintenanceTaskAssignment::LABOR_LEGACY_WINDOW,
            $task->assignments()->latest('id')->first()->labor_basis,
        );
    }

    /**
     * THE WAITING-TIME RULE — the reason the session ledger exists.
     *
     * The worked example: car in at 10:00, work starts 11:00, blocked on a part 12:00 → 16:00, finished
     * 17:00. Active work is 2h + 1h = 3h even though the fault sat on the bench for six. The ceiling
     * must follow the ACTIVE total, so 4h is refused despite a six-hour work window existing.
     */
    public function test_waiting_for_parts_is_excluded_from_active_work_and_from_the_ceiling(): void
    {
        $ticket   = $this->ticketAtGarage();
        $task     = $this->fault($ticket);
        $sessions = app(\App\Services\FaultWorkSessionService::class);
        $svc      = app(FaultRepairTimeService::class);

        $this->openStint($task, $this->makeVendor(), Carbon::parse('2026-08-20 10:00:00'), null);
        // The stint helper defaults work_started_at to assigned_at; clear it so the ledger is the only
        // evidence, which is the state a freshly-clocked repair is actually in.
        $task->assignments()->latest('id')->first()->forceFill(['work_started_at' => null])->save();

        Carbon::setTestNow('2026-08-20 10:00:00');
        $sessions->startWork($task->refresh(), $this->admin);
        Carbon::setTestNow('2026-08-20 12:00:00');
        $sessions->block($task->refresh(), \App\Models\MaintenanceTaskWorkSession::BLOCK_PARTS, $this->admin);
        Carbon::setTestNow('2026-08-20 16:00:00');
        $sessions->startWork($task->refresh(), $this->admin);          // resume
        Carbon::setTestNow('2026-08-20 17:00:00');
        app(MaintenanceTaskService::class)->setStatus($task->refresh(), MaintenanceTask::STATUS_COMPLETED, $this->admin, 'part fitted');

        $time = $svc->forTask($task->refresh()->load(['assignments', 'workSessions']));

        $this->assertSame('sessions', $time['basis']);
        $this->assertSame(3 * 3600, $time['cumulative_active_seconds'], 'active work is 2h + 1h, not the 6h span');
        $this->assertSame(4 * 3600, $time['cumulative_blocked_seconds']);
        $this->assertSame(4 * 3600, $time['blocked_by_reason']['parts']);
        // Custody — the car was in the shop from 10:00. Seven hours, and never the fault's number.
        $this->assertSame(7 * 3600, $time['cumulative_custody_seconds']);

        // 4h is refused against 3h of ACTIVE work even though 6h of wall clock elapsed.
        try {
            $svc->recordAttemptLabor($task->refresh(), 4.0, $this->admin);
            $this->fail('Labor above the ACTIVE work total must be rejected.');
        } catch (\App\Exceptions\WorkflowTransitionException $e) {
            $this->assertStringContainsString('3h 00m', $e->getMessage());
            $this->assertStringContainsString('waiting', strtolower($e->getMessage()));
        }

        // 3h and 2.5h are both legitimate: labor may be less than the active window, never more.
        $this->assertTrue($svc->recordAttemptLabor($task->refresh(), 2.5, $this->admin));
        $this->assertSame(
            \App\Models\MaintenanceTaskAssignment::LABOR_MEASURED,
            $task->assignments()->latest('id')->first()->labor_basis,
        );

        Carbon::setTestNow();
    }

    /** A double-tapped "Start work" must not open a second interval and double the fault's labor. */
    public function test_double_start_does_not_open_a_second_session(): void
    {
        $ticket   = $this->ticketAtGarage();
        $task     = $this->fault($ticket);
        $sessions = app(\App\Services\FaultWorkSessionService::class);

        $this->openStint($task, $this->makeVendor(), Carbon::now()->subHour());

        $first  = $sessions->startWork($task->refresh(), $this->admin);
        $second = $sessions->startWork($task->refresh(), $this->admin);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $task->refresh()->workSessions()->count());
        // Pausing twice is equally idempotent — no second block interval from a resent request.
        $sessions->block($task->refresh(), \App\Models\MaintenanceTaskWorkSession::BLOCK_APPROVAL, $this->admin);
        $sessions->block($task->refresh(), \App\Models\MaintenanceTaskWorkSession::BLOCK_APPROVAL, $this->admin);
        $this->assertSame(1, $task->refresh()->openWorkSession()->count());
    }

    /** Releasing the fault closes the running interval, so its seconds stop growing forever. */
    public function test_release_closes_the_running_session(): void
    {
        $ticket   = $this->ticketAtGarage();
        $task     = $this->fault($ticket);
        $sessions = app(\App\Services\FaultWorkSessionService::class);

        $this->openStint($task, $this->makeVendor(), Carbon::now()->subHours(2));
        $sessions->startWork($task->refresh(), $this->admin);
        $this->assertNotNull($sessions->openSession($task->refresh()));

        app(MaintenanceTaskService::class)->setStatus($task->refresh(), MaintenanceTask::STATUS_COMPLETED, $this->admin, 'fixed');

        $this->assertNull($sessions->openSession($task->refresh()), 'the work clock must stop when the fault is released');
        $this->assertNotNull($task->refresh()->workSessions()->first()->ended_at);
    }

    /** A correction is not a way around the ceiling — the same rule applies to the audited path. */
    public function test_correction_is_still_bound_by_the_ceiling(): void
    {
        $ticket = $this->ticketAtGarage();
        $task   = $this->fault($ticket);
        $svc    = app(FaultRepairTimeService::class);

        $this->openStint($task, $this->makeVendor(), Carbon::now()->subHours(4), Carbon::now()->subHours(2));
        app(MaintenanceTaskService::class)->setStatus($task->refresh(), MaintenanceTask::STATUS_COMPLETED, $this->admin, 'done', null, 1.0);
        $stint = $task->assignments()->latest('id')->first();

        $this->expectException(\App\Exceptions\WorkflowTransitionException::class);
        $svc->overwriteAttemptLabor($stint, 5.0, 'garage says it took longer', $this->admin);
    }

    /**
     * The OVERRIDE exists for the real case (worked four hours, forgot to clock in) but is never
     * silent: it flags the row, keeps the reason, and writes its own audit event naming the ceiling.
     */
    public function test_override_records_the_breach_rather_than_hiding_it(): void
    {
        $ticket = $this->ticketAtGarage();
        $task   = $this->fault($ticket);
        $svc    = app(FaultRepairTimeService::class);

        $this->openStint($task, $this->makeVendor(), Carbon::now()->subHours(4), Carbon::now()->subHours(2));
        app(MaintenanceTaskService::class)->setStatus($task->refresh(), MaintenanceTask::STATUS_COMPLETED, $this->admin, 'done', null, 1.0);
        $stint = $task->assignments()->latest('id')->first();

        $svc->overrideAttemptLabor($stint, 5.0, 'clocked in late — job card from the garage shows 5h', $this->admin);

        $stint->refresh();
        $this->assertSame(5.0, (float) $stint->labor_hours);
        $this->assertSame(\App\Models\MaintenanceTaskAssignment::LABOR_OVERRIDE, $stint->labor_basis);
        $this->assertStringContainsString('job card', $stint->labor_override_reason);

        // The breach is on the vehicle timeline, attributed, with the ceiling it exceeded.
        $event = \App\Models\VehicleLogEvent::where('maintenance_task_id', $task->id)
            ->where('event_type', \App\Models\VehicleLogEvent::EVENT_TASK_LABOR_OVERRIDE)->first();
        $this->assertNotNull($event, 'an override must always leave an audit event');
        $this->assertSame(5.0, (float) $event->meta['new_hours']);
        $this->assertNotNull($event->meta['ceiling_hours']);

        // A blank or throwaway reason is refused — the reason IS the control.
        $this->expectException(\App\Exceptions\WorkflowTransitionException::class);
        $svc->overrideAttemptLabor($stint, 6.0, 'nope', $this->admin);
    }

    /**
     * A fault with NO work timeline at all still accepts labor — refusing would block every legacy and
     * on-site repair — but the value is stamped `declared` so analytics can tell a trusted claim from a
     * measured one instead of averaging them together.
     */
    public function test_labor_without_a_work_timeline_is_recorded_as_declared(): void
    {
        $ticket = $this->ticketAtGarage();
        $task   = $this->fault($ticket);
        $svc    = app(FaultRepairTimeService::class);

        // A stint that never received a work signal — the state 26 of 30 live closed stints are in.
        $this->openStint($task, $this->makeVendor(), Carbon::now()->subHours(6));
        $task->assignments()->latest('id')->first()->forceFill(['work_started_at' => null])->save();
        app(MaintenanceTaskService::class)->setStatus($task->refresh(), MaintenanceTask::STATUS_COMPLETED, $this->admin, 'done');

        $this->assertTrue($svc->recordAttemptLabor($task->refresh(), 8.0, $this->admin));
        $stint = $task->assignments()->latest('id')->first();
        $this->assertSame(8.0, (float) $stint->labor_hours);
        $this->assertSame(\App\Models\MaintenanceTaskAssignment::LABOR_DECLARED, $stint->labor_basis);

        // And the read layer refuses to call it a measured fault time.
        $time = $svc->forTask($task->refresh()->load(['assignments', 'workSessions']));
        $this->assertNull($time['cumulative_active_seconds']);
        $this->assertNull($time['cumulative_work_seconds']);
        $this->assertSame('custody', $time['basis']);
    }

    /**
     * Attempt #2's ceiling must be computed from attempt #2's work ONLY. If attempt #1's hours leaked
     * into the bound, a fault that came back could be booked twice over on the strength of the first
     * try's clock.
     */
    public function test_second_attempt_ceiling_ignores_the_first_attempts_work(): void
    {
        $ticket = $this->ticketAtGarage();
        $task   = $this->fault($ticket);
        $vendor = $this->makeVendor();
        $tasks  = app(MaintenanceTaskService::class);
        $svc    = app(FaultRepairTimeService::class);

        // Attempt #1 — a long 8h window, booked 6h.
        $this->openStint($task, $vendor, Carbon::now()->subHours(20), Carbon::now()->subHours(20));
        $tasks->setStatus($task->refresh(), MaintenanceTask::STATUS_COMPLETED, $this->admin, 'first go', null, 6.0);
        $task->assignments()->latest('id')->first()->forceFill(['released_at' => Carbon::now()->subHours(12)])->save();

        $tasks->failReinspection($task->refresh(), 'came back', $this->admin);

        // Attempt #2 — only a 1h window. The generous first attempt must not licence a big second entry.
        $this->openStint($task, $vendor, Carbon::now()->subHour(), Carbon::now()->subHour());
        $tasks->setStatus($task->refresh(), MaintenanceTask::STATUS_COMPLETED, $this->admin, 'second go');

        try {
            $svc->recordAttemptLabor($task->refresh(), 5.0, $this->admin);
            $this->fail('attempt #2 must be bounded by its OWN work, not attempt #1\'s');
        } catch (\App\Exceptions\WorkflowTransitionException $e) {
            $this->assertStringContainsString('1h 00m', $e->getMessage());
        }

        // Attempt #1's recorded 6h is untouched by the rejection.
        $this->assertSame(6.0, (float) $task->refresh()->repair_hours);
    }
}
