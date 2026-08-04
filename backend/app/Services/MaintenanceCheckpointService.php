<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MaintenanceCheckpoint;
use App\Models\MaintenanceCheckpointReminder;
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
 *   3. WHERE a car stands (monitorState) — the single source of truth for the ETA, whether today's
 *      progress question has been answered, and the escalation level the reminder should fire at. Both
 *      the scan (to decide the notification) and the dashboard (to colour the row) read this, so they can
 *      never disagree.
 *   4. WHETHER THE CHASE WORKED (recordReminder / closeReminders / complianceReport) — every daily
 *      reminder that goes out is logged against the supervisor it went to, and closed when an answer
 *      arrives. Reminders that stay open are what the admin's Checkpoint Compliance board reports.
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
     * today's question has been answered.
     *
     * It also stores the ANSWER itself (`response`): confirmed when the supervisor stands by the promised
     * date, rescheduled when they push it back. Every submission is its own row, so a car chased five days
     * running keeps five dated answers with five reasons — that history is the point of the daily chase,
     * and nothing here ever overwrites an earlier one.
     *
     * Finally it CLOSES every reminder still open on this ticket: the chase asked, and here is the answer.
     * A reminder that never gets closed is exactly what the Checkpoint Compliance board reports.
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

            // The answer to today's question, derived from the date so the two can never disagree.
            $rescheduled = $previous === null || ! $previous->equalTo($next);

            // A confirmation has no delay reason, and only "other" carries free text. Enforced HERE rather
            // than only in the controller, so no other caller (seeder, command, future importer) can store
            // a row whose answer and reason contradict each other — "confirmed, because we're waiting on
            // parts" is not a sentence the timeline or the compliance board can render honestly.
            $reason      = $rescheduled ? ($data['delay_reason'] ?? null) : null;
            $reasonOther = ($reason === 'other') ? ($data['delay_reason_other'] ?? null) : null;

            $checkpoint = $ticket->checkpoints()->create([
                'vehicle_id'             => $ticket->vehicle_id,
                'status'                 => $data['status'] ?? null,
                'delay_reason'           => $reason,
                'delay_reason_other'     => $reasonOther,
                'summary'                => $data['summary'] ?? null,
                'response'               => $rescheduled
                    ? MaintenanceCheckpoint::RESPONSE_RESCHEDULED
                    : MaintenanceCheckpoint::RESPONSE_CONFIRMED,
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

            $this->closeReminders($ticket, $checkpoint);

            return $checkpoint;
        });
    }

    /**
     * Log that today's reminder is owed to one supervisor about one car — the receipt oversight later
     * audits — and answer the caller's real question: SHOULD WE ACTUALLY PING THEM?
     *
     * The receipt is the dedup ledger, because the notification layer has none: notifyUser() posts an
     * alert every time it is called, so cron running twice a day would otherwise put the same sentence in
     * the same supervisor's bell twice. One row per (ticket, user, day) means we can tell the difference
     * between "we already asked this today" and "the ask genuinely got louder" (request → due_today →
     * overdue), and only the second deserves a second ping.
     *
     * @return bool whether this reminder is new information for that supervisor today
     */
    public function recordReminder(Maintenance $ticket, User $user, string $level, ?string $expectedOn): bool
    {
        $reminder = MaintenanceCheckpointReminder::firstOrNew([
            'maintenance_id' => $ticket->id,
            'user_id'        => $user->id,
            'sent_on'        => today()->toDateString(),
        ]);

        // New receipt, or the same day's ask escalating to a louder level — both are worth delivering.
        $worthSending = ! $reminder->exists || $reminder->level !== $level;

        $reminder->vehicle_id  = $ticket->vehicle_id;
        $reminder->level       = $level;
        $reminder->expected_on = $expectedOn;
        $reminder->sent_at ??= now();
        $reminder->save();

        return $worthSending;
    }

    /**
     * Close every open reminder on a ticket — the supervisors were asked, and an answer arrived. Closed by
     * TICKET, not by recipient: when a car is chased with two responsible owners, one answer settles the
     * car, and holding the other owner "silent" would be a false finding.
     */
    public function closeReminders(Maintenance $ticket, MaintenanceCheckpoint $checkpoint): int
    {
        return MaintenanceCheckpointReminder::query()
            ->where('maintenance_id', $ticket->id)
            ->unanswered()
            ->update([
                'responded_checkpoint_id' => $checkpoint->id,
                'responded_at'            => now(),
                'updated_at'              => now(),
            ]);
    }

    /**
     * The users who should be notified / may act for this ticket: its explicit responsible users, or —
     * when none were assigned — the default supervisors. Active users only.
     *
     * @return Collection<int,User>
     */
    public function recipientsFor(Maintenance $ticket): Collection
    {
        // Query the RELATION, never its underlying ->getQuery(). BelongsToMany::get() selects `users.*`;
        // the raw builder selects `*` across the join, so `maintenance_responsibles.id` overwrites
        // `users.id` and every recipient comes back wearing the PIVOT ROW's id — which then addressed the
        // notification, and the reminder receipt, to a user that does not exist. Qualify `users.status`
        // for the same reason: unqualified, it is ambiguous the moment the pivot grows a status column.
        $query = $ticket->responsibles();
        if ($this->usersHaveStatusColumn()) {
            $query->where('users.status', 'active');
        }
        $explicit = $query->get();

        return $explicit->isNotEmpty() ? $explicit : $this->defaultRecipients();
    }

    /**
     * The fleet default follow-up owners, when a ticket names none of its own.
     *
     * Deliberately NARROW. The obvious implementation — "everyone holding maintenance.delegate" — reads
     * fine until you run it against the real fleet, where it also matches both super-admin accounts and
     * the QA logins. A daily nag delivered to eight people, two of whom are robots and two of whom are
     * admins, is how a fleet teaches itself to ignore the bell.
     *
     * So: the configured allow-list wins outright; otherwise permission holders are intersected with the
     * operational `fallback_roles` (the Supervisors) and stripped of `excluded_roles` (admins). Returning
     * an EMPTY collection is a legitimate, deliberate outcome — the scan then reports the ticket as
     * unassigned rather than broadcasting it. See unassignedTickets().
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
        $roles      = array_filter((array) config('maintenance.checkpoint.fallback_roles', []));
        $excluded   = array_filter((array) config('maintenance.checkpoint.excluded_roles', []));

        $query = User::permission($permission);

        // No operational role configured → no automatic fallback at all. Broadcasting to a bare permission
        // is the failure mode this guard exists to prevent, so the safe default is silence + a report.
        if (empty($roles)) {
            return collect();
        }
        $query->role($roles);

        if (! empty($excluded)) {
            $query->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', $excluded));
        }

        return $this->activeGate($query)->get();
    }

    /**
     * Cars that need chasing today but have NOBODY to chase — no assigned responsible, and no default
     * recipient after the narrowing above. These used to be invisible: the old code simply broadcast them
     * to every permission holder, which looked like coverage. Now the reminder is withheld and the gap is
     * reported, because "nobody owns this car's follow-up" is an assignment problem for an admin to fix,
     * not something to paper over by nagging everyone.
     *
     * @param Collection<int,Maintenance>|null $tickets pass the scan's already-loaded set to avoid a re-query
     * @return array<int,array>
     */
    public function unassignedTickets(?Collection $tickets = null): array
    {
        $tickets ??= Maintenance::query()
            ->whereIn('workflow_status', Maintenance::CHECKPOINT_TRACKED_STATES)
            ->with(['vehicle:id,plate_no,make,model', 'vendor:id,name', 'checkpoints'])
            ->get();

        $rows = [];
        foreach ($tickets as $ticket) {
            $state = $this->monitorState($ticket);
            if (! $state['escalation'] || $this->recipientsFor($ticket)->isNotEmpty()) {
                continue;
            }
            $rows[] = [
                'ticket_id'   => (int) $ticket->id,
                'vehicle_id'  => $ticket->vehicle_id ? (int) $ticket->vehicle_id : null,
                'plate_no'    => $ticket->vehicle?->plate_no,
                'car'         => trim(($ticket->vehicle?->make ?? '') . ' ' . ($ticket->vehicle?->model ?? '')) ?: null,
                'garage'      => $ticket->vendor?->name ?: $ticket->garage,
                'expected_on' => $state['expected_on'],
                'escalation'  => $state['escalation'],
                'days_over'   => (int) $state['days_over'],
            ];
        }

        return $rows;
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
     * The cadence is DAILY. The reminder window opens `reminder_lead_days` (1 by default) before the
     * promised date and then asks once EVERY DAY until the car is out — a car promised back on the 20th is
     * chased on the 19th, the 20th, the 21st… Each answer covers only the DAY it was filed, because "will
     * it be back tomorrow?" is a question that has to be re-asked tomorrow.
     *
     * The one thing that buys silence is a NEW date: pushing the promise to the 23rd moves the window with
     * it, so the chase goes quiet and reopens on the 22nd — one day before the new promise. That is why
     * coverage is per-day but the window is per-promise; either half alone gets it wrong.
     *
     * escalation (null when today is already answered, or the window hasn't opened):
     *   'request'   — the window just opened (the day it opens)
     *   'reminder'  — a further day inside the lead window with still no answer (lead > 1 day)
     *   'due_today' — the promised day arrived with no answer
     *   'overdue'   — past the promised day with no answer (red on the dashboard)
     *
     * @return array{expected_on:?string, is_estimated:bool, eta_status:string, days_left:int,
     *               days_over:int, has_checkpoint:bool, last_checkpoint_at:?string,
     *               answered_today:bool, needs_update:bool, overdue:bool, escalation:?string}
     */
    public function monitorState(Maintenance $ticket, ?MaintenanceCheckpoint $latest = null): array
    {
        $start = $ticket->repair_started_at ?? $ticket->out_date ?? $ticket->dispatched_at ?? $ticket->created_at;
        $eta   = Maintenance::etaFromDates($start, $ticket->effectiveExpectedCompletion());

        $lead        = max(0, (int) config('maintenance.checkpoint.reminder_lead_days', 1));
        $expectedOn  = $eta['expected_on'] ? Carbon::parse($eta['expected_on'])->startOfDay() : null;
        $windowStart = $expectedOn?->copy()->subDays($lead);

        $today = today();
        $last  = $ticket->last_checkpoint_at;

        $withinWindow = $windowStart !== null && $today->greaterThanOrEqualTo($windowStart);
        // Today's question is answered only by TODAY's update. Yesterday's answer settled yesterday.
        $answeredToday = $last !== null && $last->copy()->startOfDay()->equalTo($today);

        $escalation = null;
        if ($withinWindow && ! $answeredToday) {
            if (($eta['days_over'] ?? 0) > 0) {
                $escalation = 'overdue';
            } elseif (($eta['status'] ?? null) === 'due_today') {
                $escalation = 'due_today';
            } else {
                $escalation = $today->equalTo($windowStart) ? 'request' : 'reminder';
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
            'answered_today'     => $answeredToday,
            'needs_update'       => $escalation !== null,
            'overdue'            => $escalation === 'overdue',
            'escalation'         => $escalation,
        ];
    }

    /**
     * Checkpoint Compliance — the admin's answer to "we chased the supervisor about this car; did anyone
     * ever answer?". One row per ticket that still has an OPEN reminder: who was notified, on which days,
     * how loud the ask got, how long the silence has run, and — for context — every answer that car HAS
     * had, so a chronically-slipping job and a first-time miss don't look alike.
     *
     * `days_unanswered` counts from the FIRST unanswered reminder, so a car chased three days running with
     * no reply reads 3, not 1. `breached` marks the ones past the tolerated silence (default: one day).
     *
     * Read-only — nothing here changes a ticket. See [[workflow-oversight-suite]].
     *
     * @return array{rows:array<int,array>, summary:array, alert_days:int}
     */
    public function complianceReport(): array
    {
        $alertDays = max(1, (int) config('maintenance.checkpoint.unanswered_alert_days', 1));
        $today     = today();

        $open = MaintenanceCheckpointReminder::query()->unanswered()->orderBy('sent_on')->get();

        // Fleet-wide delivery totals — the compliance rate the header shows (answered vs sent, all time).
        $sentTotal     = MaintenanceCheckpointReminder::query()->count();
        $answeredTotal = MaintenanceCheckpointReminder::query()->whereNotNull('responded_at')->count();

        // Cars needing a chase that nobody owns — withheld reminders, surfaced instead of broadcast.
        $unassigned = $this->unassignedTickets();

        $ticketIds = $open->pluck('maintenance_id')->unique()->values();
        if ($ticketIds->isEmpty()) {
            return [
                'rows'       => [],
                'unassigned' => $unassigned,
                'summary'    => $this->complianceSummary(0, 0, $sentTotal, $answeredTotal, count($unassigned)),
                'alert_days' => $alertDays,
            ];
        }

        $tickets = Maintenance::query()->whereIn('id', $ticketIds)
            ->with(['vehicle:id,plate_no,make,model', 'vendor:id,name'])
            ->get()->keyBy('id');

        $names = User::query()->whereIn('id', $open->pluck('user_id')->unique())
            ->pluck('name', 'id');

        // Every answer these cars have given — the reason history the compliance row summarises.
        $history = MaintenanceCheckpoint::query()
            ->whereIn('maintenance_id', $ticketIds)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('maintenance_id');

        $rows = $open->groupBy('maintenance_id')->map(function ($group, $ticketId) use ($tickets, $names, $history, $today, $alertDays) {
            $ticket = $tickets->get($ticketId);
            if (! $ticket) {
                return null; // ticket deleted out from under an open reminder — nothing to show
            }

            $sorted    = $group->sortBy('sent_on')->values();
            $first     = $sorted->first();
            $last      = $sorted->last();
            $silentFor = (int) $first->sent_on->copy()->startOfDay()->diffInDays($today);

            $checkpoints = $history->get($ticketId, collect());
            $reschedules = $checkpoints->where('response', MaintenanceCheckpoint::RESPONSE_RESCHEDULED);
            $latest      = $checkpoints->first();

            return [
                'ticket_id'   => (int) $ticketId,
                'vehicle_id'  => $ticket->vehicle_id ? (int) $ticket->vehicle_id : null,
                'plate_no'    => $ticket->vehicle?->plate_no,
                'car'         => trim(($ticket->vehicle?->make ?? '') . ' ' . ($ticket->vehicle?->model ?? '')) ?: null,
                'garage'      => $ticket->vendor?->name ?: $ticket->garage,

                // THREE distinct dates, because collapsing them misleads an admin. A car first promised
                // for the 20th, moved to the 23rd, and last chased about the 23rd tells a different story
                // depending on which one you read — so all three are published, explicitly named.
                //   original — the first promise anyone made (what the slip is measured against)
                //   current  — the promise in force right now
                //   reminded_about — the date the supervisor was actually TOLD in the open reminder
                // (`expected_on` is kept as an alias of `reminded_about_on` for existing consumers.)
                'original_promised_on' => optional(
                    $reschedules->last()?->previous_expected_date          // oldest reschedule's "before"
                        ?: $checkpoints->last()?->previous_expected_date   // else the oldest answer's "before"
                        ?: $ticket->effectiveExpectedCompletion()          // else it has never moved
                )->toDateString(),
                'current_promised_on' => optional($ticket->effectiveExpectedCompletion())->toDateString(),
                'reminded_about_on'   => optional($last->expected_on)->toDateString()
                    ?: optional($ticket->effectiveExpectedCompletion())->toDateString(),
                'expected_on'         => optional($last->expected_on)->toDateString()
                    ?: optional($ticket->effectiveExpectedCompletion())->toDateString(),
                // When the date was last pushed back, and to what.
                'last_rescheduled_at' => optional($reschedules->first()?->created_at)->toIso8601String(),
                'last_rescheduled_to' => optional($reschedules->first()?->next_expected_date)->toDateString(),

                // The chase itself — who, when, how loud, how long the silence.
                'notified'          => $sorted->pluck('user_id')->unique()
                    ->map(fn ($id) => $names[$id] ?? ('User #' . $id))->values()->all(),
                'reminders_open'    => $sorted->count(),
                'days_reminded'     => $sorted->pluck('sent_on')->map(fn ($d) => $d->toDateString())->unique()->count(),
                'first_reminder_on' => $first->sent_on->toDateString(),
                'last_reminder_on'  => $last->sent_on->toDateString(),
                'last_level'        => $last->level,
                'days_unanswered'   => $silentFor,
                'breached'          => $silentFor >= $alertDays,

                // What this car HAS answered before — context, so a repeat slipper is visible.
                'checkpoint_count'  => $checkpoints->count(),
                'reschedule_count'  => $reschedules->count(),
                'last_response'     => $latest ? [
                    'response'      => $latest->response,
                    'delay_reason'  => $latest->delay_reason,
                    'reason_other'  => $latest->delay_reason_other,
                    'previous_date' => optional($latest->previous_expected_date)->toDateString(),
                    'next_date'     => optional($latest->next_expected_date)->toDateString(),
                    'by'            => $latest->submitted_by_name,
                    'at'            => optional($latest->created_at)->toIso8601String(),
                ] : null,
                // Every reason this car's date has EVER moved for, newest first — reason codes, not prose.
                'reasons'           => $reschedules->map(fn ($c) => [
                    'delay_reason'  => $c->delay_reason,
                    'reason_other'  => $c->delay_reason_other,
                    'previous_date' => optional($c->previous_expected_date)->toDateString(),
                    'next_date'     => optional($c->next_expected_date)->toDateString(),
                    'by'            => $c->submitted_by_name,
                    'at'            => optional($c->created_at)->toIso8601String(),
                ])->values()->all(),
            ];
        })->filter()->values();

        // Longest silence first — the worst offender is the one an admin needs to see.
        $rows = $rows->sortByDesc('days_unanswered')->values();

        return [
            'rows'       => $rows->all(),
            'unassigned' => $unassigned,
            'summary'    => $this->complianceSummary(
                $rows->count(),
                $rows->where('breached', true)->count(),
                $sentTotal,
                $answeredTotal,
                count($unassigned)
            ),
            'alert_days' => $alertDays,
        ];
    }

    /**
     * @return array{open:int, breached:int, unassigned:int, sent_total:int, answered_total:int,
     *               response_rate:?float}
     */
    private function complianceSummary(
        int $open, int $breached, int $sentTotal, int $answeredTotal, int $unassigned = 0
    ): array {
        return [
            'open'           => $open,
            'breached'       => $breached,
            'unassigned'     => $unassigned,
            'sent_total'     => $sentTotal,
            'answered_total' => $answeredTotal,
            'response_rate'  => $sentTotal > 0 ? round(($answeredTotal / $sentTotal) * 100, 1) : null,
        ];
    }

    /** Apply the active-user filter to a plain user query when the column exists (mirrors NotificationScanner). */
    private function activeGate($query)
    {
        if ($this->usersHaveStatusColumn()) {
            $query->where('status', 'active');
        }

        return $query;
    }

    /** Does the users table carry a `status` column? Memoised per request. */
    private function usersHaveStatusColumn(): bool
    {
        return $this->hasStatusColumn ??= DB::getSchemaBuilder()->hasColumn('users', 'status');
    }
}
