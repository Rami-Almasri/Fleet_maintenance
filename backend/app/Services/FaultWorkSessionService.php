<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceTaskAssignment;
use App\Models\MaintenanceTaskWorkSession;
use App\Models\User;
use App\Models\VehicleLogEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE WORK CLOCK — evidence class F (Fact).
 *   Produces: maintenance_task_work_sessions rows (F), and the maintenance_task_assignments
 *             .work_started_at first-signal cache (D).
 *   Consumes: the fault's open stint (F).
 *
 * This service is the ONLY writer of the work-session ledger. Everything else — the labor ceiling, the
 * per-garage active hours, the waiting-for-parts report — reads what it writes.
 *
 * WHAT IT MODELS (and the gap it closes): before this existed, a fault had one `work_started_at` stamp
 * and its "work time" was the wall clock from there to release. That number silently includes every hour
 * the car sat waiting for a part, an approval, or the customer. This ledger splits that span into named
 * intervals, so active labor and waiting time stop being the same number.
 *
 * THE INVARIANT: at most ONE open session per fault. A fault cannot be simultaneously worked and blocked,
 * and it cannot be worked twice over. MySQL has no partial unique index to express that, so every mutating
 * method runs inside a transaction that first takes `lockForUpdate()` on the fault's open sessions — the
 * row lock IS the concurrency guard. A double-tapped "Start work" therefore closes as a no-op on the
 * second request instead of opening a second interval and doubling the fault's measured labor.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: it never touches labor_hours. Active work is the CEILING that
 * FaultRepairTimeService validates a human labor entry against; the two must stay separate, because the
 * moment the clock starts writing the labor value there is nothing left to check it against.
 */
class FaultWorkSessionService
{
    public function __construct(private VehicleLogService $log)
    {
    }

    // ── Reads ─────────────────────────────────────────────────────────────────────────────────────

    /** The interval running right now on this fault (work or blocked), or null if nothing is running. */
    public function openSession(MaintenanceTask $task): ?MaintenanceTaskWorkSession
    {
        return $task->openWorkSession()->orderByDesc('id')->first();
    }

    /**
     * Active work seconds for this fault, optionally restricted to one attempt's stints.
     *
     * Sums only `work` intervals — a blocked interval is time the fault spent waiting and is never labor.
     * An open session counts up to now, so a live display is honest rather than stale.
     *
     * @param  list<int>|null  $stintIds  restrict to these stints (one attempt); null = the whole fault.
     *                                    Sessions with a NULL stint (on-site) are included only when
     *                                    $stintIds is null — they belong to no attempt.
     */
    public function activeWorkSeconds(MaintenanceTask $task, ?array $stintIds = null): int
    {
        $sessions = $task->relationLoaded('workSessions')
            ? $task->workSessions
            : $task->workSessions()->get();

        $now = Carbon::now();

        return (int) $sessions
            ->where('kind', MaintenanceTaskWorkSession::KIND_WORK)
            ->filter(fn ($s) => $stintIds === null || in_array((int) $s->maintenance_task_assignment_id, $stintIds, true))
            ->sum(fn ($s) => $s->seconds($now));
    }

    /**
     * Waiting time for this fault broken down by reason — the answer to "what was this car waiting for".
     *
     * @return array{total_seconds:int, by_reason:array<string,int>}
     */
    public function blockedBreakdown(MaintenanceTask $task, ?array $stintIds = null): array
    {
        $sessions = $task->relationLoaded('workSessions')
            ? $task->workSessions
            : $task->workSessions()->get();

        $now  = Carbon::now();
        $rows = $sessions
            ->where('kind', MaintenanceTaskWorkSession::KIND_BLOCKED)
            ->filter(fn ($s) => $stintIds === null || in_array((int) $s->maintenance_task_assignment_id, $stintIds, true));

        $byReason = [];
        $total    = 0;
        foreach ($rows as $s) {
            $secs   = $s->seconds($now);
            $reason = $s->block_reason ?: MaintenanceTaskWorkSession::BLOCK_OTHER;
            $byReason[$reason] = ($byReason[$reason] ?? 0) + $secs;
            $total += $secs;
        }

        return ['total_seconds' => $total, 'by_reason' => $byReason];
    }

    // ── Writes ────────────────────────────────────────────────────────────────────────────────────

    /**
     * START (or RESUME) hands-on work on this fault.
     *
     * Idempotent by design: if work is already running this returns that session untouched, so a
     * double-tap cannot open a second overlapping interval. If the fault is currently BLOCKED, the block
     * is closed first and the resume is recorded — that transition is the whole point of the ledger.
     *
     * Also fills the stint's `work_started_at` cache when it is still null, so every existing reader
     * (FaultRepairTimeService's legacy basis, the ⏱ chip, the visit journey) keeps working unchanged.
     */
    public function startWork(MaintenanceTask $task, User $actor, ?string $note = null): MaintenanceTaskWorkSession
    {
        $this->assertWorkable($task);

        return DB::transaction(function () use ($task, $actor, $note) {
            $open = $this->lockOpenSession($task);

            if ($open && $open->kind === MaintenanceTaskWorkSession::KIND_WORK) {
                return $open; // already working — not an error, just nothing to do
            }

            $now   = Carbon::now();
            $stint = $task->openAssignment()->first();

            if ($open) {
                // Was blocked — close the block and say how long it cost.
                $open->forceFill(['ended_at' => $now, 'ended_by' => $actor->id])->save();
                $this->log->recordTask($task, VehicleLogEvent::EVENT_TASK_WORK_RESUMED, $actor, [
                    'description' => 'Work resumed on "' . $task->symptom . '" after '
                        . $this->humanise($open->seconds($now)) . ' ' . strtolower($open->label()),
                    'meta' => [
                        'session_id'      => $open->id,
                        'block_reason'    => $open->block_reason,
                        'blocked_seconds' => $open->seconds($now),
                    ],
                ]);
            }

            $session = MaintenanceTaskWorkSession::create([
                'maintenance_task_id'            => $task->id,
                'maintenance_task_assignment_id' => $stint?->id,
                'kind'                           => MaintenanceTaskWorkSession::KIND_WORK,
                'started_at'                     => $now,
                'started_by'                     => $actor->id,
                'note'                           => $note,
                'source'                         => MaintenanceTaskWorkSession::SOURCE_LIVE,
            ]);

            // Keep the legacy first-signal stamp in step — fill-if-null, never moved.
            if ($stint) {
                MaintenanceTaskAssignment::whereKey($stint->id)
                    ->whereNull('work_started_at')
                    ->update(['work_started_at' => $now]);
            }

            if (! $open) {
                $this->log->recordTask($task, VehicleLogEvent::EVENT_TASK_WORK_STARTED, $actor, [
                    'description' => 'Work started on "' . $task->symptom . '" by ' . $actor->name
                        . ($note ? ' — ' . $note : ''),
                    'meta' => ['session_id' => $session->id, 'stint_id' => $stint?->id],
                ]);
            }

            return $session;
        });
    }

    /**
     * PAUSE work and say WHY — the fault stops accruing labor and starts accruing waiting time.
     *
     * A pause with no reason is exactly the ambiguity this system exists to remove, so `reason` is
     * required and comes from a closed list; `other` additionally requires a note. Pausing when nothing
     * is running is rejected rather than silently opening a block from nowhere — a block that never
     * interrupted any work is not evidence of anything.
     */
    public function block(MaintenanceTask $task, string $reason, User $actor, ?string $note = null): MaintenanceTaskWorkSession
    {
        $this->assertWorkable($task);

        if (! in_array($reason, MaintenanceTaskWorkSession::BLOCK_REASONS, true)) {
            throw new WorkflowTransitionException('Pick a valid reason for pausing the work.', ['field' => 'block_reason']);
        }
        $note = $note !== null ? (trim($note) ?: null) : null;
        if ($reason === MaintenanceTaskWorkSession::BLOCK_OTHER && $note === null) {
            throw new WorkflowTransitionException('Describe what the work is waiting for.', ['field' => 'note']);
        }

        return DB::transaction(function () use ($task, $reason, $actor, $note) {
            $open = $this->lockOpenSession($task);

            if ($open && $open->kind === MaintenanceTaskWorkSession::KIND_BLOCKED) {
                return $open; // already paused — idempotent, no second block
            }
            if (! $open) {
                throw new WorkflowTransitionException(
                    'Work is not running on this fault, so there is nothing to pause. Start the work first.',
                    ['field' => 'block_reason'],
                );
            }

            $now = Carbon::now();
            $open->forceFill(['ended_at' => $now, 'ended_by' => $actor->id])->save();

            $session = MaintenanceTaskWorkSession::create([
                'maintenance_task_id'            => $task->id,
                'maintenance_task_assignment_id' => $open->maintenance_task_assignment_id,
                'kind'                           => MaintenanceTaskWorkSession::KIND_BLOCKED,
                'block_reason'                   => $reason,
                'started_at'                     => $now,
                'started_by'                     => $actor->id,
                'note'                           => $note,
                'source'                         => MaintenanceTaskWorkSession::SOURCE_LIVE,
            ]);

            $this->log->recordTask($task, VehicleLogEvent::EVENT_TASK_WORK_PAUSED, $actor, [
                'description' => 'Work paused on "' . $task->symptom . '" — '
                    . strtolower(MaintenanceTaskWorkSession::BLOCK_LABELS[$reason])
                    . ($note ? ' (' . $note . ')' : '') . '. '
                    . $this->humanise($open->seconds($now)) . ' worked in this session.',
                'meta' => [
                    'session_id'   => $session->id,
                    'block_reason' => $reason,
                    'work_seconds' => $open->seconds($now),
                ],
            ]);

            return $session;
        });
    }

    /**
     * CLOSE whatever interval is running — called by the workflow when the fault leaves the bench
     * (resolved, cancelled, transferred, failed re-inspection). Silent no-op when nothing is running.
     *
     * This is what makes a released fault's ledger complete: without it, the last session would stay
     * open forever and its seconds would keep growing every time anyone opened the page.
     */
    public function closeOpen(MaintenanceTask $task, User $actor, string $why = 'released'): ?MaintenanceTaskWorkSession
    {
        return DB::transaction(function () use ($task, $actor, $why) {
            $open = $this->lockOpenSession($task);
            if (! $open) {
                return null;
            }

            $now = Carbon::now();
            $open->forceFill(['ended_at' => $now, 'ended_by' => $actor->id])->save();

            $this->log->recordTask($task, VehicleLogEvent::EVENT_TASK_WORK_STOPPED, $actor, [
                'description' => ucfirst($open->label()) . ' on "' . $task->symptom . '" ended ('
                    . $why . ') after ' . $this->humanise($open->seconds($now)) . '.',
                'meta' => [
                    'session_id' => $open->id,
                    'kind'       => $open->kind,
                    'seconds'    => $open->seconds($now),
                    'why'        => $why,
                ],
            ]);

            return $open;
        });
    }

    // ── Guards ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Take the row lock that enforces the one-open-session invariant. Every mutating method funnels
     * through here, so two concurrent requests serialise instead of racing.
     */
    private function lockOpenSession(MaintenanceTask $task): ?MaintenanceTaskWorkSession
    {
        return MaintenanceTaskWorkSession::where('maintenance_task_id', $task->id)
            ->whereNull('ended_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    /** A closed fault, or one on a finalized ticket, no longer accrues time. */
    private function assertWorkable(MaintenanceTask $task): void
    {
        if ($task->isTerminal()) {
            throw new WorkflowTransitionException(
                'This fault is already ' . $task->status . ' — its work clock is finished.',
                ['field' => 'status'],
            );
        }

        $status = optional($task->maintenance)->workflow_status;
        if (in_array($status, [Maintenance::WF_CLOSED, Maintenance::WF_AWAITING_INVOICE], true)) {
            throw new WorkflowTransitionException(
                'This ticket is already closed — no more work time can be recorded against it.',
                ['field' => 'status', 'from' => $status],
            );
        }
    }

    /** "3h 20m" / "45m" / "30s" — one wording for every log line this service writes. */
    private function humanise(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return $h > 0 ? ($m > 0 ? "{$h}h {$m}m" : "{$h}h") : "{$m}m";
    }
}
