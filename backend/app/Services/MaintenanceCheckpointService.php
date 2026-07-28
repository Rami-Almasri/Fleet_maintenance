<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MaintenanceCheckpoint;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * Maintenance Checkpoint — the progress-tracking engine. Owns three concerns the controller, the
 * scheduled Checkpoint Scan and the dashboard all share:
 *
 *   1. WHO follows up a ticket (recipientsFor) — its explicit responsible users, else the default
 *      supervisors (Waleed & Abdullah).
 *   2. WHO may act (canSubmit / canManage) — the permission + responsible-user + admin gate.
 *   3. WHERE a car stands (monitorState) — the single source of truth for the ETA, whether a checkpoint
 *      is still owed in the current completion window, and the escalation level the reminder should fire
 *      at. Both the scan (to decide the notification) and the dashboard (to colour the row) read this,
 *      so they can never disagree.
 *
 * See [[maintenance-workflow-engine]].
 */
class MaintenanceCheckpointService
{
    /** Whether the users table carries a `status` column (active-user gating), memoised per request. */
    private ?bool $hasStatusColumn = null;

    /**
     * Record a progress update against a ticket. Every update carries the new ETA (next_expected_date,
     * required); this snapshots the ETA that was in force just before it as `previous_expected_date` so the
     * timeline shows every extension, advances the ticket's promised completion date to the new ETA (the
     * new date becomes the single source of truth), and stamps last_checkpoint_at so the escalation knows
     * the current window is covered.
     *
     * @param array{status?:?string, delay_reason?:?string, delay_reason_other?:?string,
     *              summary?:?string, next_expected_date:string} $data
     */
    public function submit(Maintenance $ticket, User $actor, array $data): MaintenanceCheckpoint
    {
        return DB::transaction(function () use ($ticket, $actor, $data) {
            // The ETA in force BEFORE this update (the promise the workshop is now revising).
            $previous = $ticket->effectiveExpectedCompletion()?->copy()->startOfDay();
            $next = Carbon::parse($data['next_expected_date'])->startOfDay();

            $checkpoint = $ticket->checkpoints()->create([
                'vehicle_id'             => $ticket->vehicle_id,
                'status'                 => $data['status'] ?? null,
                'delay_reason'           => $data['delay_reason'] ?? null,
                'delay_reason_other'     => $data['delay_reason_other'] ?? null,
                'summary'                => $data['summary'] ?? null,
                'previous_expected_date' => $previous,
                'next_expected_date'     => $next,
                'submitted_by'           => $actor->id,
                'submitted_by_name'      => $actor->name,
            ]);

            // The new ETA becomes the promise everything downstream (ETA gauge, escalation, overdue,
            // derived status) measures against — the hand-entered date wins over any derived duration.
            $ticket->expected_completion_date = $next;
            $ticket->last_checkpoint_at = now();
            $ticket->save();

            return $checkpoint;
        });
    }

    /**
     * The users who should be notified / may act for this ticket: its explicit responsible users, or —
     * when none were assigned — the default supervisors. Active users only.
     *
     * @return Collection<int,User>
     */
    public function recipientsFor(Maintenance $ticket): Collection
    {
        $explicit = $this->activeGate($ticket->responsibles()->getQuery())->get();

        return $explicit->isNotEmpty() ? $explicit : $this->defaultRecipients();
    }

    /**
     * The fleet default follow-up owners (Waleed & Abdullah): the configured user ids if set, else every
     * active user holding the fallback permission (maintenance.delegate = the Supervisors).
     *
     * @return Collection<int,User>
     */
    public function defaultRecipients(): Collection
    {
        $ids = (array) config('maintenance.checkpoint.default_user_ids', []);

        if (! empty($ids)) {
            return $this->activeGate(User::query()->whereIn('id', $ids))->get();
        }

        $permission = (string) config('maintenance.checkpoint.fallback_permission', 'maintenance.delegate');

        return $this->activeGate(User::permission($permission))->get();
    }

    /**
     * May this user SUBMIT a checkpoint on this ticket? The create permission (admins hold it via the
     * full grant / super-admin bypass), OR being one of the ticket's assigned responsible users — an
     * owner is trusted to report on their own job even without the standalone permission.
     */
    public function canSubmit(User $user, Maintenance $ticket): bool
    {
        return $user->can('maintenance.checkpoint.create')
            || $ticket->responsibles()->where('users.id', $user->id)->exists();
    }

    /** May this user MANAGE checkpoints (edit/delete) + set a ticket's responsible users? */
    public function canManage(User $user): bool
    {
        return $user->can('maintenance.checkpoint.manage');
    }

    /**
     * Replace a ticket's responsible follow-up users. Idempotent; records who assigned each. Returns the
     * refreshed active recipient list.
     *
     * @param array<int,int> $userIds
     * @return Collection<int,User>
     */
    public function setResponsibles(Maintenance $ticket, array $userIds, User $actor): Collection
    {
        $ids  = collect($userIds)->map(fn ($id) => (int) $id)->filter()->unique();
        $sync = $ids->mapWithKeys(fn ($id) => [$id => ['added_by' => $actor->id]])->all();
        $ticket->responsibles()->sync($sync);

        return $this->recipientsFor($ticket);
    }

    /**
     * The live monitoring state of one in-shop ticket — the single source of truth for the ETA, whether
     * a checkpoint is still owed, and the escalation level. `latest` is the most recent checkpoint (pass
     * it in when already loaded to avoid a re-query).
     *
     * escalation (null when covered or not yet within the reminder window):
     *   'request'   — first ask, ≤12h into the lead window (1 day before, by default)
     *   'reminder'  — nudge, >12h into the lead window and still no update
     *   'due_today' — the promised day arrived with no update
     *   'overdue'   — past the promised day with no update (re-fires daily; red on the dashboard)
     *
     * @return array{expected_on:?string, is_estimated:bool, eta_status:string, days_left:int,
     *               days_over:int, has_checkpoint:bool, last_checkpoint_at:?string,
     *               needs_update:bool, overdue:bool, escalation:?string}
     */
    public function monitorState(Maintenance $ticket, ?MaintenanceCheckpoint $latest = null): array
    {
        $start = $ticket->repair_started_at ?? $ticket->out_date ?? $ticket->dispatched_at ?? $ticket->created_at;
        $eta   = Maintenance::etaFromDates($start, $ticket->effectiveExpectedCompletion());

        $lead        = max(0, (int) config('maintenance.checkpoint.reminder_lead_days', 1));
        $expectedOn  = $eta['expected_on'] ? Carbon::parse($eta['expected_on'])->startOfDay() : null;
        $windowStart = $expectedOn?->copy()->subDays($lead);

        $now  = now();
        $last = $ticket->last_checkpoint_at;

        $withinWindow = $windowStart !== null && $now->greaterThanOrEqualTo($windowStart);
        // "Covered" = an update landed on/after this window opened. A checkpoint that pushed the date
        // forward moves expectedOn (and the window) with it, so an old update never counts for a new window.
        $covered = $withinWindow && $last !== null && $last->greaterThanOrEqualTo($windowStart);

        $escalation = null;
        if ($withinWindow && ! $covered) {
            if (($eta['days_over'] ?? 0) > 0) {
                $escalation = 'overdue';
            } elseif (($eta['status'] ?? null) === 'due_today') {
                $escalation = 'due_today';
            } else {
                $escalation = $windowStart->diffInHours($now) >= 12 ? 'reminder' : 'request';
            }
        }

        $latest ??= $ticket->relationLoaded('checkpoints')
            ? $ticket->checkpoints->first()
            : $ticket->checkpoints()->first();

        return [
            'expected_on'        => $eta['expected_on'],
            'is_estimated'       => (bool) ($eta['is_estimated'] ?? false),
            'eta_status'         => $eta['status'] ?? 'unknown',
            'days_left'          => (int) ($eta['days_left'] ?? 0),
            'days_over'          => (int) ($eta['days_over'] ?? 0),
            'has_checkpoint'     => $last !== null || $latest !== null,
            'last_checkpoint_at' => optional($last ?: $latest?->created_at)->toIso8601String(),
            'needs_update'       => $escalation !== null,
            'overdue'            => $escalation === 'overdue',
            'escalation'         => $escalation,
        ];
    }

    /** Apply the active-user filter to a user query when the column exists (mirrors NotificationScanner). */
    private function activeGate($query)
    {
        if ($this->hasStatusColumn === null) {
            $this->hasStatusColumn = DB::getSchemaBuilder()->hasColumn('users', 'status');
        }
        if ($this->hasStatusColumn) {
            $query->where('status', 'active');
        }

        return $query;
    }
}
