<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceTaskAssignment;
use App\Models\User;
use App\Models\VehicleLogEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-FAULT repair time — evidence class D (Derived).
 *   Consumes: maintenance_task_assignments stints (F), maintenance_tasks lifecycle stamps (F),
 *             stint labor_hours (F — human-entered).
 *   Produces: attempt segmentation, per-attempt + cumulative elapsed/labor (D), and the
 *             maintenance_tasks.repair_hours derived cache (D).
 *
 * THE CONTRACT (the business rule this service is the single home of):
 *   A fault's cumulative timer lives on ONE task row. A failed re-inspection CONTINUES it — the same
 *   row gets a new attempt, and every earlier attempt stays immutable in the stint ledger. A PASSED
 *   re-inspection finalizes it. A later recurrence of the same problem is a DIFFERENT task row
 *   (linked via recurrence_previous_task_id for history only) and is NEVER summed into this one.
 *
 * THREE metrics, never collapsed:
 *   • work    — THE per-fault number. work_started_at → released_at. work_started_at is stamped by
 *     per-fault events only (the workshop confirmation verdict, else an explicit in_progress), so two
 *     faults on the same car genuinely differ. This is what any per-fault display should show.
 *   • custody — assigned_at → released_at. assigned_at is the DISPATCH instant, which every fault on
 *     the ticket shares, so this is the vehicle's workshop time seen from one fault. Kept because it is
 *     the honest answer to "how long did this fault keep the car off the road", but it must never be
 *     presented as how long the fault itself took — that is the trap this service exists to avoid.
 *   • labor   — the mechanic's actual time for one attempt, human-entered, stored write-once on the
 *     stint that ends the attempt (see recordAttemptLabor).
 *
 * Every attempt reports a `basis` naming which signal started its work clock — `confirmed_or_started`
 * when a real per-fault signal exists, `custody` when we had to fall back to the shared dispatch time.
 * A caller showing a `custody`-basis number must label it as car-in-workshop time, not fault time.
 *
 * ATTEMPT = consecutive stints up to and including one closed with an ATTEMPT_ENDING_OUTCOME
 * (resolved / failed_reinspection / cancelled). transferred_out and unable chain into the next stint
 * WITHIN the same attempt — a transfer is a physical hand-off mid-repair, not a new attempt.
 *
 * On-site (mobile) faults have no stints at all: elapsed is honestly null (basis onsite_no_timeline —
 * we do not fabricate a wall-clock from identified_at, that's queue time), and their single manual
 * labor value lives in the task.repair_hours cache directly (setStatus stamps it on resolve).
 */
class FaultRepairTimeService
{
    /** Sanity ceiling for one attempt's manual labor entry (hours). */
    public const MAX_ATTEMPT_LABOR_HOURS = 24;

    public function __construct(private VehicleLogService $log)
    {
    }

    /**
     * The full per-fault time picture. Requires nothing pre-loaded (loads stints if absent); recurrence
     * history is intentionally NOT included here — it belongs to the occurrence card, never the timer.
     *
     * `cumulative_work_seconds` is the per-fault answer; it is NULL when no attempt ever recorded a
     * per-fault work signal, because in that case the only thing we could return is the car's shared
     * workshop time, and returning it under a per-fault name is the exact error this guards against.
     *
     * @return array{
     *   attempts: list<array{n:int, stints:list<array>, work_seconds:?int, custody_seconds:?int,
     *                        labor_hours:?float, outcome:?string, open:bool, basis:string}>,
     *   attempt_count:int, open_attempt:bool, cumulative_work_seconds:?int,
     *   cumulative_custody_seconds:?int, cumulative_labor_hours:?float, basis:string
     * }
     */
    public function forTask(MaintenanceTask $task): array
    {
        $stints = $task->relationLoaded('assignments')
            ? $task->assignments->sortBy([['assigned_at', 'asc'], ['id', 'asc']])->values()
            : $task->assignments()->orderBy('assigned_at')->orderBy('id')->get();

        if ($stints->isEmpty()) {
            // No garage timeline (on-site job, or a legacy row) — say so, don't invent one.
            return [
                'attempts'                   => [],
                'attempt_count'              => 0,
                'open_attempt'               => false,
                'cumulative_work_seconds'    => null,
                'cumulative_custody_seconds' => null,
                'cumulative_labor_hours'     => $task->repair_hours !== null ? (float) $task->repair_hours : null,
                'basis'                      => $task->isTerminal() && $task->maintenance?->isOnSite()
                    ? 'onsite_no_timeline'
                    : 'no_stints',
            ];
        }

        $blank = ['stints' => [], 'work' => null, 'custody' => 0, 'labor' => null, 'outcome' => null, 'open' => false];
        $attempts = [];
        $current  = $blank;
        $flush = function () use (&$attempts, &$current, $blank) {
            if ($current['stints']) {
                $attempts[] = $current;
            }
            $current = $blank;
        };

        foreach ($stints as $s) {
            $end = $s->released_at ?? Carbon::now();
            $current['stints'][] = [
                'id'              => $s->id,
                'vendor_id'       => $s->vendor_id,
                'garage'          => $s->relationLoaded('vendor') ? $s->vendor?->name : null,
                'assigned_at'     => optional($s->assigned_at)->toIso8601String(),
                'work_started_at' => optional($s->work_started_at)->toIso8601String(),
                'released_at'     => optional($s->released_at)->toIso8601String(),
                'outcome'         => $s->outcome,
            ];

            // CUSTODY — from the shared dispatch instant. Always computable, never per-fault.
            $current['custody'] += max(0, $s->assigned_at ? $s->assigned_at->diffInSeconds($end) : 0);

            // WORK — only from this fault's own start signal. Absent ⇒ contributes nothing, so the
            // attempt stays honestly null rather than silently inheriting the custody number.
            if ($s->work_started_at) {
                $current['work'] = ($current['work'] ?? 0) + max(0, $s->work_started_at->diffInSeconds($end));
            }

            if ($s->labor_hours !== null) {
                $current['labor'] = ($current['labor'] ?? 0) + (float) $s->labor_hours;
            }

            if ($s->released_at === null) {
                // The running attempt — flushed below as open.
                $current['open'] = true;
            } elseif (in_array($s->outcome, MaintenanceTaskAssignment::ATTEMPT_ENDING_OUTCOMES, true)) {
                $current['outcome'] = $s->outcome;
                $flush();
            }
            // transferred_out / unable: same attempt continues at the next stint.
        }
        $flush(); // trailing open (or transfer-dangling) stints form the in-progress attempt

        $out = [];
        foreach ($attempts as $i => $a) {
            $out[] = [
                'n'               => $i + 1,
                'stints'          => $a['stints'],
                'work_seconds'    => $a['work'] !== null ? (int) $a['work'] : null,
                'custody_seconds' => (int) $a['custody'],
                'labor_hours'     => $a['labor'] !== null ? round($a['labor'], 2) : null,
                'outcome'         => $a['outcome'],
                'open'            => $a['open'],
                'basis'           => $a['work'] !== null ? 'confirmed_or_started' : 'custody',
            ];
        }

        $workVals  = array_filter(array_column($out, 'work_seconds'), fn ($v) => $v !== null);
        $laborVals = array_filter(array_column($out, 'labor_hours'), fn ($v) => $v !== null);

        return [
            'attempts'                   => $out,
            'attempt_count'              => count($out),
            'open_attempt'               => (bool) ($out ? end($out)['open'] : false),
            // Null (not zero, not the custody figure) when this fault never recorded a work signal.
            'cumulative_work_seconds'    => $workVals ? (int) array_sum($workVals) : null,
            'cumulative_custody_seconds' => (int) array_sum(array_column($out, 'custody_seconds')),
            'cumulative_labor_hours'     => $laborVals ? round(array_sum($laborVals), 2) : null,
            'basis'                      => $workVals ? 'confirmed_or_started' : 'custody',
        ];
    }

    /**
     * Record the CURRENT attempt's manual labor time (Option A — the user enters only this attempt's
     * hours; cumulative is always derived). WRITE-ONCE: the value lands on the fault's latest
     * attempt-ending (or open) stint only while that stint's labor_hours is NULL — an atomic
     * fill-if-null, so a duplicate Make Ready / double-click can never overwrite or double-count, and
     * attempt #1's hours survive a failed re-inspection untouched on their own closed stint.
     *
     * Returns true when the value was written, false when that attempt already has one (not an error —
     * duplicate submissions are expected and harmless).
     */
    public function recordAttemptLabor(MaintenanceTask $task, float $hours, User $actor): bool
    {
        $this->assertLaborValue($hours);
        $this->assertParentWritable($task);

        // The stint carrying the current attempt's entry: the open stint if one exists, else the most
        // recently closed attempt-ending stint (mark-fixed already closed it by the time Make Ready runs).
        $stint = $task->assignments()->whereNull('released_at')->orderByDesc('id')->first()
            ?? $task->assignments()->whereNotNull('released_at')
                ->whereIn('outcome', MaintenanceTaskAssignment::ATTEMPT_ENDING_OUTCOMES)
                ->orderByDesc('released_at')->orderByDesc('id')->first();

        if (! $stint) {
            // On-site fault — no stint ledger. Single write-once value in the task cache.
            $written = MaintenanceTask::whereKey($task->id)
                ->whereNull('repair_hours')
                ->update(['repair_hours' => round($hours, 2)]) === 1;
            return $written;
        }

        // Atomic fill-if-null — the concurrency guard IS the WHERE clause.
        $written = MaintenanceTaskAssignment::whereKey($stint->id)
            ->whereNull('labor_hours')
            ->update([
                'labor_hours'       => round($hours, 2),
                'labor_recorded_by' => $actor->id,
                'labor_recorded_at' => Carbon::now(),
            ]) === 1;

        if ($written) {
            $this->refreshLaborCache($task);
        }

        return $written;
    }

    /**
     * Deliberate CORRECTION of an already-recorded attempt labor value — the only mutable path, and it
     * always leaves an audit event (old → new, who, why). Cumulative needs no fixing up: it is derived
     * on read, and the task cache is recomputed here.
     */
    public function overwriteAttemptLabor(MaintenanceTaskAssignment $stint, float $hours, string $reason, User $actor): void
    {
        $this->assertLaborValue($hours);
        $reason = trim($reason);
        if ($reason === '') {
            throw new WorkflowTransitionException('Give a reason for correcting a recorded labor time.', ['field' => 'reason']);
        }
        $task = $stint->task;
        $this->assertParentWritable($task);

        DB::transaction(function () use ($stint, $task, $hours, $reason, $actor) {
            $old = $stint->labor_hours !== null ? (float) $stint->labor_hours : null;
            $stint->forceFill([
                'labor_hours'       => round($hours, 2),
                'labor_recorded_by' => $actor->id,
                'labor_recorded_at' => Carbon::now(),
            ])->save();

            $this->refreshLaborCache($task);

            $this->log->recordTask($task, VehicleLogEvent::EVENT_TASK_LABOR_CORRECTED, $actor, [
                'description' => 'Labor time corrected for "' . $task->symptom . '": '
                    . ($old !== null ? $old . 'h' : '—') . ' → ' . round($hours, 2) . 'h — ' . $reason,
                'meta'        => ['stint_id' => $stint->id, 'old_hours' => $old, 'new_hours' => round($hours, 2), 'reason' => $reason],
            ]);
        });
    }

    /**
     * Recompute the task's repair_hours DERIVED CACHE = Σ(its stints' labor_hours). The stints stay the
     * source of truth; the cache only exists so per-category analytics (CarStatusService) keep their
     * cheap column read. Never written directly by any input path (on-site zero-stint faults excepted).
     */
    public function refreshLaborCache(MaintenanceTask $task): void
    {
        $sum = $task->assignments()->whereNotNull('labor_hours')->sum('labor_hours');
        MaintenanceTask::whereKey($task->id)->update([
            'repair_hours' => $sum > 0 ? round((float) $sum, 2) : null,
        ]);
    }

    private function assertLaborValue(float $hours): void
    {
        if ($hours < 0) {
            throw new WorkflowTransitionException('Labor time cannot be negative.', ['field' => 'labor_hours']);
        }
        if ($hours > self::MAX_ATTEMPT_LABOR_HOURS) {
            throw new WorkflowTransitionException(
                'Labor time for one repair attempt cannot exceed ' . self::MAX_ATTEMPT_LABOR_HOURS . ' hours — split it per attempt.',
                ['field' => 'labor_hours'],
            );
        }
    }

    /** Once the parent ticket is closed/signed-off, the occurrence is finalized — no new time enters it. */
    private function assertParentWritable(MaintenanceTask $task): void
    {
        $status = optional($task->maintenance)->workflow_status;
        if (in_array($status, [\App\Models\Maintenance::WF_CLOSED, \App\Models\Maintenance::WF_AWAITING_INVOICE], true)) {
            throw new WorkflowTransitionException(
                'This ticket is already closed — repair time on a finalized fault can no longer change. A returning fault is a new occurrence.',
                ['field' => 'labor_hours', 'from' => $status],
            );
        }
    }
}
