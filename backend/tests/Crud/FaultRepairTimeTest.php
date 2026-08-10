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
}
