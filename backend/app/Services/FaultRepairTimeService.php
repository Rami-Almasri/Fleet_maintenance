<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceTaskAssignment;
use App\Models\MaintenanceTaskWorkSession;
use App\Models\User;
use App\Models\VehicleLogEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-FAULT repair time — evidence class D (Derived).
 *   Consumes: maintenance_task_work_sessions (F — the active-work ledger), maintenance_task_assignments
 *             stints (F), stint labor_hours (F — human-entered).
 *   Produces: attempt segmentation, per-attempt + cumulative active/elapsed/custody/blocked/labor (D),
 *             and the maintenance_tasks.repair_hours derived cache (D).
 *
 * THE CONTRACT (the business rule this service is the single home of):
 *   A fault's cumulative timer lives on ONE task row. A failed re-inspection CONTINUES it — the same
 *   row gets a new attempt, and every earlier attempt stays immutable in the stint ledger. A PASSED
 *   re-inspection finalizes it. A later recurrence of the same problem is a DIFFERENT task row
 *   (linked via recurrence_previous_task_id for history only) and is NEVER summed into this one.
 *
 * FOUR metrics, never collapsed:
 *   • active  — THE per-fault labor number. Σ of this fault's `work` sessions (FaultWorkSessionService).
 *     Excludes every interval the fault spent blocked, so it is the only figure that means "hands on
 *     this fault". This is what a labor entry is validated against.
 *   • elapsed — work_started_at → released_at. The span from first work signal to release, INCLUDING
 *     any waiting inside it. Kept because it is what the system measured before the session ledger
 *     existed, and it is still the only number available for pre-ledger history.
 *   • custody — assigned_at → released_at. assigned_at is the DISPATCH instant, which every fault on
 *     the ticket shares, so this is the vehicle's workshop time seen from one fault. It is the honest
 *     answer to "how long did this fault keep the car off the road", but it must never be presented as
 *     how long the fault itself took — that is the trap this service exists to avoid.
 *   • labor   — the mechanic's actual time for one attempt, human-entered, stored write-once on the
 *     stint that ends the attempt, and NEVER permitted to exceed the active ceiling (see
 *     assertLaborWithinCeiling — the rule that makes the number impossible to inflate by accident).
 *
 * Every attempt reports a `basis` naming which signal started its clock — `sessions` when a real work
 * ledger exists, `confirmed_or_started` for pre-ledger rows with only a work_started_at stamp, and
 * `custody` when we had to fall back to the shared dispatch time. A caller showing a `custody`-basis
 * number must label it as car-in-workshop time, not fault time.
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

    /**
     * Grace allowed when comparing a typed labor value against the measured ceiling, in seconds.
     *
     * Labor is entered in hours to two decimals while the ceiling is measured to the second, so an
     * honest "I worked the whole session" entry of 3.00h against a session of 2h 59m 58s would be
     * rejected for a two-second shortfall. One minute absorbs that rounding without opening a hole
     * anyone could drive a false hour through.
     */
    public const LABOR_TOLERANCE_SECONDS = 60;

    public function __construct(
        private VehicleLogService $log,
        private FaultWorkSessionService $sessions,
    ) {
    }

    /**
     * The full per-fault time picture. Requires nothing pre-loaded (loads stints/sessions if absent);
     * recurrence history is intentionally NOT included here — it belongs to the occurrence card, never
     * the timer.
     *
     * `cumulative_active_seconds` is the per-fault labor answer. `cumulative_work_seconds` (the elapsed
     * span) is retained under its original name so existing readers keep working, but new surfaces
     * should prefer active. Both are NULL rather than zero when the fault never recorded a per-fault
     * work signal, because in that case the only thing we could return is the car's shared workshop
     * time, and returning it under a per-fault name is the exact error this guards against.
     *
     * @return array{
     *   attempts: list<array{n:int, stints:list<array>, active_seconds:?int, work_seconds:?int,
     *                        custody_seconds:?int, blocked_seconds:int, blocked_by_reason:array<string,int>,
     *                        labor_hours:?float, labor_basis:?string, outcome:?string, open:bool, basis:string}>,
     *   attempt_count:int, open_attempt:bool, cumulative_active_seconds:?int, cumulative_work_seconds:?int,
     *   cumulative_custody_seconds:?int, cumulative_blocked_seconds:int, blocked_by_reason:array<string,int>,
     *   cumulative_labor_hours:?float, basis:string, open_session:?array
     * }
     */
    public function forTask(MaintenanceTask $task): array
    {
        $stints = $this->stints($task);

        if ($stints->isEmpty()) {
            // No garage timeline (on-site job, or a legacy row) — say so, don't invent one. On-site work
            // can still have been clocked, so the session ledger is reported even with no stint.
            $active  = $this->sessionsFor($task)->isEmpty() ? null : $this->sessions->activeWorkSeconds($task);
            $blocked = $this->sessions->blockedBreakdown($task);

            return [
                'attempts'                   => [],
                'attempt_count'              => 0,
                'open_attempt'               => false,
                'cumulative_active_seconds'  => $active,
                'cumulative_work_seconds'    => null,
                'cumulative_custody_seconds' => null,
                'cumulative_blocked_seconds' => $blocked['total_seconds'],
                'blocked_by_reason'          => $blocked['by_reason'],
                'cumulative_labor_hours'     => $task->repair_hours !== null ? (float) $task->repair_hours : null,
                'basis'                      => $active !== null
                    ? 'sessions'
                    : ($task->isTerminal() && $task->maintenance?->isOnSite() ? 'onsite_no_timeline' : 'no_stints'),
                'open_session'               => $this->openSessionPayload($task),
            ];
        }

        $attempts = $this->segment($stints);
        $now      = Carbon::now();
        $sessions = $this->sessionsFor($task);

        $out = [];
        foreach ($attempts as $i => $a) {
            /** @var \Illuminate\Support\Collection $rows */
            $rows     = $a['stints'];
            $stintIds = $rows->pluck('id')->map(fn ($v) => (int) $v)->all();

            // ACTIVE — only this attempt's work sessions. Null when the attempt predates the ledger,
            // so a pre-ledger attempt reads "unknown", never "zero hours of work".
            $attemptSessions = $sessions->filter(
                fn ($s) => in_array((int) $s->maintenance_task_assignment_id, $stintIds, true)
            );
            $workRows    = $attemptSessions->where('kind', MaintenanceTaskWorkSession::KIND_WORK);
            $active      = $workRows->isEmpty() ? null : (int) $workRows->sum(fn ($s) => $s->seconds($now));
            $blockedRows = $attemptSessions->where('kind', MaintenanceTaskWorkSession::KIND_BLOCKED);

            $byReason = [];
            foreach ($blockedRows as $s) {
                $key            = $s->block_reason ?: MaintenanceTaskWorkSession::BLOCK_OTHER;
                $byReason[$key] = ($byReason[$key] ?? 0) + $s->seconds($now);
            }

            $elapsed = null;
            $custody = 0;
            $labor   = null;
            foreach ($rows as $s) {
                $end = $s->released_at ?? $now;

                // CUSTODY — from the shared dispatch instant. Always computable, never per-fault.
                $custody += max(0, $s->assigned_at ? (int) $s->assigned_at->diffInSeconds($end) : 0);

                // ELAPSED — only from this fault's own start signal. Absent ⇒ contributes nothing, so the
                // attempt stays honestly null rather than silently inheriting the custody number.
                if ($s->work_started_at) {
                    $elapsed = ($elapsed ?? 0) + max(0, (int) $s->work_started_at->diffInSeconds($end));
                }

                if ($s->labor_hours !== null) {
                    $labor = ($labor ?? 0) + (float) $s->labor_hours;
                }
            }

            // The labor basis of the stint that carries this attempt's entry — the audit answer to
            // "was that number checked against anything?".
            $laborStint = $rows->first(fn ($s) => $s->labor_hours !== null);

            $out[] = [
                'n'                 => $i + 1,
                'stints'            => $rows->map(fn ($s) => [
                    'id'              => $s->id,
                    'vendor_id'       => $s->vendor_id,
                    'garage'          => $s->relationLoaded('vendor') ? $s->vendor?->name : null,
                    'assigned_at'     => optional($s->assigned_at)->toIso8601String(),
                    'arrived_at'      => optional($s->arrived_at)->toIso8601String(),
                    'work_started_at' => optional($s->work_started_at)->toIso8601String(),
                    'released_at'     => optional($s->released_at)->toIso8601String(),
                    'outcome'         => $s->outcome,
                    'active_seconds'  => (int) $attemptSessions
                        ->where('kind', MaintenanceTaskWorkSession::KIND_WORK)
                        ->where('maintenance_task_assignment_id', $s->id)
                        ->sum(fn ($x) => $x->seconds($now)),
                ])->values()->all(),
                'active_seconds'    => $active,
                'work_seconds'      => $elapsed,
                'custody_seconds'   => (int) $custody,
                'blocked_seconds'   => (int) array_sum($byReason),
                'blocked_by_reason' => $byReason,
                'labor_hours'       => $labor !== null ? round($labor, 2) : null,
                'labor_basis'       => $laborStint?->labor_basis,
                'outcome'           => $a['outcome'],
                'open'              => $a['open'],
                'basis'             => $active !== null
                    ? 'sessions'
                    : ($elapsed !== null ? 'confirmed_or_started' : 'custody'),
            ];
        }

        $activeVals  = array_filter(array_column($out, 'active_seconds'), fn ($v) => $v !== null);
        $elapsedVals = array_filter(array_column($out, 'work_seconds'), fn ($v) => $v !== null);
        $laborVals   = array_filter(array_column($out, 'labor_hours'), fn ($v) => $v !== null);

        $allByReason = [];
        foreach ($out as $a) {
            foreach ($a['blocked_by_reason'] as $k => $v) {
                $allByReason[$k] = ($allByReason[$k] ?? 0) + $v;
            }
        }

        return [
            'attempts'                   => $out,
            'attempt_count'              => count($out),
            'open_attempt'               => (bool) ($out ? end($out)['open'] : false),
            // Null (not zero, not the custody figure) when this fault never recorded the signal.
            'cumulative_active_seconds'  => $activeVals ? (int) array_sum($activeVals) : null,
            'cumulative_work_seconds'    => $elapsedVals ? (int) array_sum($elapsedVals) : null,
            'cumulative_custody_seconds' => (int) array_sum(array_column($out, 'custody_seconds')),
            'cumulative_blocked_seconds' => (int) array_sum($allByReason),
            'blocked_by_reason'          => $allByReason,
            'cumulative_labor_hours'     => $laborVals ? round(array_sum($laborVals), 2) : null,
            'basis'                      => $activeVals ? 'sessions' : ($elapsedVals ? 'confirmed_or_started' : 'custody'),
            'open_session'               => $this->openSessionPayload($task),
            // What a labor entry submitted now would be bounded by — so the form can refuse an
            // impossible number before it is ever sent.
            'labor_ceiling'              => $this->currentAttemptCeiling($task),
        ];
    }

    // ── THE LABOR CEILING ─────────────────────────────────────────────────────────────────────────

    /**
     * How much labor this attempt is ALLOWED to claim, and what that limit was derived from.
     *
     * Three honest answers, in descending order of evidence:
     *   • measured      — the attempt has a work-session ledger. The ceiling is its active work total:
     *                     waiting for a part is excluded, so a 7-hour visit with 3 hours of hands-on
     *                     work permits 3 hours of labor and not a minute more.
     *   • legacy_window — no sessions (the attempt predates the ledger, or nobody clocked it), but the
     *                     stints carry work_started_at. The ceiling is that elapsed span. It is a WEAKER
     *                     bound — it still contains any waiting — but it is a real bound, and it stops
     *                     the "3 hours booked against an 11-second window" class of error outright.
     *   • declared      — no work timeline at all. There is nothing to check against, so `seconds` is
     *                     null and no ceiling is enforced. The value is still recorded with this basis
     *                     so analytics can tell trusted-but-unverified labor from measured labor rather
     *                     than averaging the two together.
     *
     * @param  list<int>  $stintIds  the attempt's stints
     * @return array{seconds:?int, basis:string}
     */
    public function laborCeiling(MaintenanceTask $task, array $stintIds): array
    {
        $now      = Carbon::now();
        $sessions = $this->sessionsFor($task)
            ->filter(fn ($s) => in_array((int) $s->maintenance_task_assignment_id, $stintIds, true));

        $workRows = $sessions->where('kind', MaintenanceTaskWorkSession::KIND_WORK);
        if ($workRows->isNotEmpty()) {
            return [
                'seconds' => (int) $workRows->sum(fn ($s) => $s->seconds($now)),
                'basis'   => MaintenanceTaskAssignment::LABOR_MEASURED,
            ];
        }

        $elapsed = null;
        foreach ($this->stints($task)->whereIn('id', $stintIds) as $s) {
            if ($s->work_started_at) {
                $elapsed = ($elapsed ?? 0) + max(0, (int) $s->work_started_at->diffInSeconds($s->released_at ?? $now));
            }
        }
        if ($elapsed !== null) {
            return ['seconds' => $elapsed, 'basis' => MaintenanceTaskAssignment::LABOR_LEGACY_WINDOW];
        }

        return ['seconds' => null, 'basis' => MaintenanceTaskAssignment::LABOR_DECLARED];
    }

    /**
     * The ceiling a labor entry submitted RIGHT NOW would be checked against, plus which stint it would
     * land on. Served to the client so the mechanic sees the limit while typing instead of discovering
     * it on rejection — the same computation the write path runs, so the two can never disagree.
     *
     * @return array{seconds:?int, basis:string, stint_id:?int, recorded_hours:?float}
     */
    public function currentAttemptCeiling(MaintenanceTask $task): array
    {
        $stint = $this->currentAttemptStint($task);

        if (! $stint) {
            // On-site fault, or one never dispatched — nothing measured to bound it.
            return [
                'seconds'        => null,
                'basis'          => MaintenanceTaskAssignment::LABOR_DECLARED,
                'stint_id'       => null,
                'recorded_hours' => $task->repair_hours !== null ? (float) $task->repair_hours : null,
            ];
        }

        $ceiling = $this->laborCeiling($task, $this->attemptStintIds($task, (int) $stint->id));

        return $ceiling + [
            'stint_id'       => (int) $stint->id,
            'recorded_hours' => $stint->labor_hours !== null ? (float) $stint->labor_hours : null,
        ];
    }

    /**
     * THE RULE: a labor entry may never exceed the attempt's recorded active work.
     *
     * You cannot book 4 hours against a fault whose entire recorded work window is 3. The value is
     * REJECTED, never clamped — silently writing 3 where a human typed 4 would destroy the very signal
     * (someone's time record disagrees with the clock) that this check exists to surface. The message
     * names the ceiling and how it was measured, so the fix is obvious: clock the missing work, or have
     * a supervisor correct the timestamps, or take the audited override.
     *
     * @throws WorkflowTransitionException
     */
    public function assertLaborWithinCeiling(float $hours, array $ceiling): void
    {
        if ($ceiling['seconds'] === null) {
            return; // `declared` basis — nothing measured to check against
        }

        $claimed = (int) round($hours * 3600);
        if ($claimed <= $ceiling['seconds'] + self::LABOR_TOLERANCE_SECONDS) {
            return;
        }

        $limit = $this->humanise($ceiling['seconds']);
        $extra = $ceiling['basis'] === MaintenanceTaskAssignment::LABOR_MEASURED
            ? 'Time the fault spent waiting (for parts, approval or the customer) is not labor and is excluded.'
            : 'That window is measured from when work started to when the fault was released.';

        throw new WorkflowTransitionException(
            'Labor time cannot exceed the recorded fault work duration of ' . $limit . '. ' . $extra
            . ' Record the missing work, correct the times, or ask a manager to authorise an override.',
            [
                'field'          => 'labor_hours',
                'ceiling_hours'  => round($ceiling['seconds'] / 3600, 2),
                'ceiling_basis'  => $ceiling['basis'],
                'claimed_hours'  => round($hours, 2),
            ],
        );
    }

    // ── Labor writes ──────────────────────────────────────────────────────────────────────────────

    /**
     * Record the CURRENT attempt's manual labor time (Option A — the user enters only this attempt's
     * hours; cumulative is always derived). WRITE-ONCE: the value lands on the fault's latest
     * attempt-ending (or open) stint only while that stint's labor_hours is NULL — an atomic
     * fill-if-null, so a duplicate Make Ready / double-click can never overwrite or double-count, and
     * attempt #1's hours survive a failed re-inspection untouched on their own closed stint.
     *
     * The ceiling is checked BEFORE the write and inside the same transaction as the row lock, so two
     * concurrent submissions cannot each pass a check that only one of them should.
     *
     * Returns true when the value was written, false when that attempt already has one (not an error —
     * duplicate submissions are expected and harmless).
     */
    public function recordAttemptLabor(MaintenanceTask $task, float $hours, User $actor): bool
    {
        $this->assertLaborValue($hours);
        $this->assertParentWritable($task);

        return DB::transaction(function () use ($task, $hours, $actor) {
            $stint = $this->currentAttemptStint($task, lock: true);

            if (! $stint) {
                // On-site fault — no stint ledger. Single write-once value in the task cache. There is no
                // work window to measure it against, so it is `declared` by definition.
                return MaintenanceTask::whereKey($task->id)
                    ->whereNull('repair_hours')
                    ->update(['repair_hours' => round($hours, 2)]) === 1;
            }

            // WRITE-ONCE is checked BEFORE the ceiling, and deliberately so: a duplicate submit changes
            // nothing, so there is nothing to validate. Throwing here would turn an expected double-tap
            // into a hard error and — worse — would leak whether the resubmitted number was legal.
            if ($stint->labor_hours !== null) {
                return false;
            }

            $fresh   = $task->fresh();
            $ceiling = $this->laborCeiling($fresh, $this->attemptStintIds($fresh, (int) $stint->id));
            $this->assertLaborWithinCeiling($hours, $ceiling);

            // Atomic fill-if-null — the concurrency guard IS the WHERE clause.
            $written = MaintenanceTaskAssignment::whereKey($stint->id)
                ->whereNull('labor_hours')
                ->update([
                    'labor_hours'       => round($hours, 2),
                    'labor_basis'       => $ceiling['basis'],
                    'labor_recorded_by' => $actor->id,
                    'labor_recorded_at' => Carbon::now(),
                ]) === 1;

            if ($written) {
                $this->refreshLaborCache($task);
            }

            return $written;
        });
    }

    /**
     * Deliberate CORRECTION of an already-recorded attempt labor value — the only mutable path, and it
     * always leaves an audit event (old → new, who, why). The ceiling applies here too: correcting a
     * value is not a way around it. Cumulative needs no fixing up: it is derived on read, and the task
     * cache is recomputed here.
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
            $ceiling = $this->laborCeiling($task, $this->attemptStintIds($task, $stint->id));
            $this->assertLaborWithinCeiling($hours, $ceiling);

            $old = $stint->labor_hours !== null ? (float) $stint->labor_hours : null;
            $stint->forceFill([
                'labor_hours'           => round($hours, 2),
                'labor_basis'           => $ceiling['basis'],
                'labor_override_reason' => null, // a corrected value is no longer an override
                'labor_recorded_by'     => $actor->id,
                'labor_recorded_at'     => Carbon::now(),
            ])->save();

            $this->refreshLaborCache($task);

            $this->log->recordTask($task, VehicleLogEvent::EVENT_TASK_LABOR_CORRECTED, $actor, [
                'description' => 'Labor time corrected for "' . $task->symptom . '": '
                    . ($old !== null ? $old . 'h' : '—') . ' → ' . round($hours, 2) . 'h — ' . $reason,
                'meta'        => [
                    'stint_id'   => $stint->id,
                    'old_hours'  => $old,
                    'new_hours'  => round($hours, 2),
                    'reason'     => $reason,
                    'basis'      => $ceiling['basis'],
                ],
            ]);
        });
    }

    /**
     * EXCEPTIONAL OVERRIDE — accept a labor value ABOVE the measured ceiling.
     *
     * This exists because the ceiling can be legitimately wrong: a technician who genuinely worked four
     * hours but forgot to clock in has a real number and a broken record. The answer is not to let the
     * ceiling be ignored quietly, so this path is deliberately expensive: it needs its own permission
     * (checked at the route, never here), a mandatory reason, and it writes both a `labor_basis` of
     * `override` on the row and a dedicated audit event naming the ceiling that was exceeded, by whom,
     * when and why. Nothing about it is silent, and no role bypasses the ordinary path by holding it.
     */
    public function overrideAttemptLabor(MaintenanceTaskAssignment $stint, float $hours, string $reason, User $actor): void
    {
        $this->assertLaborValue($hours);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new WorkflowTransitionException(
                'An override needs a real explanation of why the recorded work time is wrong (at least 10 characters).',
                ['field' => 'reason'],
            );
        }
        $task = $stint->task;
        $this->assertParentWritable($task);

        DB::transaction(function () use ($stint, $task, $hours, $reason, $actor) {
            $ceiling = $this->laborCeiling($task, $this->attemptStintIds($task, $stint->id));
            $old     = $stint->labor_hours !== null ? (float) $stint->labor_hours : null;

            $stint->forceFill([
                'labor_hours'           => round($hours, 2),
                'labor_basis'           => MaintenanceTaskAssignment::LABOR_OVERRIDE,
                'labor_override_reason' => $reason,
                'labor_recorded_by'     => $actor->id,
                'labor_recorded_at'     => Carbon::now(),
            ])->save();

            $this->refreshLaborCache($task);

            $this->log->recordTask($task, VehicleLogEvent::EVENT_TASK_LABOR_OVERRIDE, $actor, [
                'description' => 'Labor OVERRIDE on "' . $task->symptom . '": ' . round($hours, 2)
                    . 'h accepted above the recorded work time of '
                    . ($ceiling['seconds'] !== null ? $this->humanise($ceiling['seconds']) : 'none')
                    . ' by ' . $actor->name . ' — ' . $reason,
                'meta' => [
                    'stint_id'      => $stint->id,
                    'old_hours'     => $old,
                    'new_hours'     => round($hours, 2),
                    'ceiling_hours' => $ceiling['seconds'] !== null ? round($ceiling['seconds'] / 3600, 2) : null,
                    'ceiling_basis' => $ceiling['basis'],
                    'reason'        => $reason,
                ],
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

    // ── Attempt segmentation ──────────────────────────────────────────────────────────────────────

    /**
     * The stint IDs belonging to the SAME attempt as $stintId.
     *
     * This is what keeps one fault's labor from leaking across its own attempts: a value entered after
     * a failed re-inspection is checked against attempt #2's work only, and attempt #1's ceiling and
     * recorded hours are untouched on their own closed stint.
     *
     * @return list<int>
     */
    public function attemptStintIds(MaintenanceTask $task, int $stintId): array
    {
        foreach ($this->segment($this->stints($task)) as $a) {
            $ids = $a['stints']->pluck('id')->map(fn ($v) => (int) $v)->all();
            if (in_array($stintId, $ids, true)) {
                return $ids;
            }
        }

        return [$stintId];
    }

    /**
     * Group a fault's stints into ATTEMPTS. A stint closed `resolved` / `failed_reinspection` /
     * `cancelled` ends the attempt; `transferred_out` and `unable` chain into the next stint within the
     * SAME attempt, because a transfer is a hand-off mid-repair rather than a fresh try.
     *
     * @return list<array{stints:\Illuminate\Support\Collection, outcome:?string, open:bool}>
     */
    private function segment(\Illuminate\Support\Collection $stints): array
    {
        $attempts = [];
        $current  = ['stints' => collect(), 'outcome' => null, 'open' => false];

        foreach ($stints as $s) {
            $current['stints']->push($s);

            if ($s->released_at === null) {
                $current['open'] = true;   // the running attempt — flushed below
            } elseif (in_array($s->outcome, MaintenanceTaskAssignment::ATTEMPT_ENDING_OUTCOMES, true)) {
                $current['outcome'] = $s->outcome;
                $attempts[]         = $current;
                $current            = ['stints' => collect(), 'outcome' => null, 'open' => false];
            }
            // transferred_out / unable: same attempt continues at the next stint.
        }
        if ($current['stints']->isNotEmpty()) {
            $attempts[] = $current;   // trailing open (or transfer-dangling) stints
        }

        return $attempts;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────────────────────

    /**
     * The ONE stint a labor entry submitted now belongs to: the open stint if the fault is still at a
     * garage, else the most recently closed attempt-ending one (mark-fixed closes it before Make Ready
     * runs). Every caller — the write, the ceiling, and the figure served to the form — goes through
     * here, so the three can never disagree about which attempt is being priced.
     *
     * 🛑 Queried on the MODEL, not through $task->assignments(). That relation carries an
     * `orderBy('assigned_at')`, and appending `orderByDesc('released_at')` to it does NOT re-sort —
     * assigned_at stays the primary key of the sort, so the query silently returns the OLDEST stint.
     * That is how a second attempt's hours used to land on (or be measured against) attempt #1.
     */
    private function currentAttemptStint(MaintenanceTask $task, bool $lock = false): ?MaintenanceTaskAssignment
    {
        $base = fn () => MaintenanceTaskAssignment::query()
            ->where('maintenance_task_id', $task->id)
            ->when($lock, fn ($q) => $q->lockForUpdate());

        return $base()->whereNull('released_at')->orderByDesc('id')->first()
            ?? $base()->whereNotNull('released_at')
                ->whereIn('outcome', MaintenanceTaskAssignment::ATTEMPT_ENDING_OUTCOMES)
                ->orderByDesc('released_at')->orderByDesc('id')->first();
    }

    private function stints(MaintenanceTask $task): \Illuminate\Support\Collection
    {
        return $task->relationLoaded('assignments')
            ? $task->assignments->sortBy([['assigned_at', 'asc'], ['id', 'asc']])->values()
            : $task->assignments()->orderBy('assigned_at')->orderBy('id')->get();
    }

    private function sessionsFor(MaintenanceTask $task): \Illuminate\Support\Collection
    {
        return $task->relationLoaded('workSessions')
            ? $task->workSessions
            : $task->workSessions()->get();
    }

    /** The interval running right now, for a UI that must show "working since…" or "waiting for parts". */
    private function openSessionPayload(MaintenanceTask $task): ?array
    {
        $open = $this->sessionsFor($task)->firstWhere('ended_at', null);
        if (! $open) {
            return null;
        }

        return [
            'id'           => $open->id,
            'kind'         => $open->kind,
            'block_reason' => $open->block_reason,
            'label'        => $open->label(),
            'started_at'   => optional($open->started_at)->toIso8601String(),
            'seconds'      => $open->seconds(),
            'note'         => $open->note,
        ];
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

    /** "3h 20m" / "45m" / "30s" — the one wording used in every ceiling message. */
    private function humanise(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return $h > 0 ? ($m > 0 ? "{$h}h {$m}m" : "{$h}h 00m") : "{$m}m";
    }
}
