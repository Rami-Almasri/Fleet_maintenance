<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Exceptions\WorkflowTransitionException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE SECOND PAIR OF EYES ON A FINDING THE DATA DISAGREES WITH.
 *
 * Two things a person can tap on the findings picker that we already know something about:
 *
 *   not_needed — a monitored routine (Oil Change / Battery Replacement / Tire Rotation / Tire Change)
 *                logged while the car's OWN live status says it is not due. The picker already warns
 *                about this in amber ("5,415 km left of the 7,000 km limit"); until now the warning was
 *                advisory and the job went ahead anyway, surfacing days later as a Data Health audit row
 *                — after the work was done and the bill was written.
 *   repeat     — the same finding was already raised on this car inside the repeat window. Either the
 *                first one was never actually fixed, or the same job is being logged twice.
 *
 * Neither is REFUSED. Refusing would be wrong: the car is in front of the person tapping and the data
 * can be stale or simply beaten by what they can see. So the finding is HELD instead:
 *
 *   1. it is written onto the ticket, stamped `approval.state = pending`, attributed to whoever logged it;
 *   2. it is NOT promoted into a workable fault — nobody starts the job (MaintenanceTaskService);
 *   3. the TICKET cannot leave its stage until it is decided (MaintenanceWorkflowService::assertTransition);
 *   4. the approvers are notified, with a link that lands on that exact card;
 *   5. approve → the fault is promoted and work proceeds. reject → it stays on the ticket, marked
 *      rejected, and never becomes work. Either way the ticket unblocks.
 *
 * WHO TRIED AND WHO SIGNED IT OFF is the whole point, so both are recorded twice: on the finding itself
 * (so the ticket carries its own history) and on the vehicle's timeline as three distinct events —
 * asked / approved / rejected. See [[vehicle-log-events-audit]], [[system-suggestion-is-not-a-human-decision]].
 *
 * The decision lives in the findings JSON rather than a table of its own, following the same doctrine as
 * [[severity-review-qc]]: the state is small, it belongs to exactly one finding on exactly one ticket, and
 * the audit trail is the durable record. It survives a re-filed report because carryForward() below reads
 * the previous findings back before writing the new ones.
 */
class FindingApprovalService
{
    public const STATE_PENDING  = 'pending';
    public const STATE_APPROVED = 'approved';
    public const STATE_REJECTED = 'rejected';

    /** Why this finding was held. CODES, never English — the sentence is composed in the UI. */
    public const REASON_NOT_NEEDED = 'not_needed';
    public const REASON_REPEAT     = 'repeat';

    public function __construct(
        private NotificationScanner $notifier,
        private VehicleLogService $log,
    ) {
    }

    // ── Raising the hold ────────────────────────────────────────────────────────

    /**
     * Should this finding be held, and if so under what? Returns the `approval` block to stamp onto the
     * finding, or null when there is nothing to challenge (the overwhelmingly common case).
     *
     * A finding that was ALREADY decided on this ticket keeps that decision — see carryForward(). An
     * inspector who re-files his report does not re-open an approval his manager already signed off, and
     * must not be able to launder a rejected finding by submitting the same form again.
     *
     * @param  array|null  $statusCheck  the finding's `status_check` (MaintenanceWorkflowService::statusConflictFor)
     * @param  array       $priorFindings  the ticket's findings BEFORE this write
     * @return array|null
     */
    public function challenge(string $text, ?Vehicle $vehicle, Maintenance $ticket, ?array $statusCheck, User $actor, array $priorFindings = []): ?array
    {
        $carried = $this->carryForward($text, $priorFindings);
        if ($carried !== null) {
            return $carried;
        }

        [$reason, $params] = $this->reasonFor($text, $vehicle, $ticket, $statusCheck);
        if ($reason === null) {
            return null;
        }

        return [
            'state'           => self::STATE_PENDING,
            'reason'          => $reason,
            'params'          => $params,
            'requested_by'    => $actor->name,
            'requested_by_id' => $actor->id,
            'requested_at'    => Carbon::now()->toIso8601String(),
            'decided_by'      => null,
            'decided_by_id'   => null,
            'decided_at'      => null,
            'note'            => null,
        ];
    }

    /**
     * The decision already on record for this finding text, if any. Only a DECIDED approval carries
     * forward: a still-pending one is re-derived from scratch so a finding that has since become
     * legitimately due (a rental put 900 km on the car between the two filings) stops being held.
     */
    private function carryForward(string $text, array $priorFindings): ?array
    {
        $key = $this->key($text);
        foreach ($priorFindings as $f) {
            if (! is_array($f) || $this->key((string) ($f['text'] ?? '')) !== $key) {
                continue;
            }
            $state = $f['approval']['state'] ?? null;
            if ($state === self::STATE_APPROVED || $state === self::STATE_REJECTED) {
                return $f['approval'];
            }
        }

        return null;
    }

    /**
     * WHY this finding is being questioned — the first reason that fires, or [null, null].
     *
     * "Not needed" is checked first because it is the stronger statement: the car's own instrumentation
     * says the job is not due, which is a claim about THIS finding right now. "Repeat" is a claim about
     * history, and a routine that is genuinely due again after 90 days is not a duplicate.
     *
     * @return array{0:?string,1:array}
     */
    private function reasonFor(string $text, ?Vehicle $vehicle, Maintenance $ticket, ?array $statusCheck): array
    {
        // 1. The car says this routine is not due. `status_check` is non-null ONLY in that case — it is
        //    stamped by the same call that builds the finding, so the two can never disagree.
        if (($statusCheck['status'] ?? null) === 'ok') {
            return [self::REASON_NOT_NEEDED, [
                'summary' => $statusCheck['summary'] ?? null,
            ]];
        }

        // 2. The same thing was raised on this car recently.
        $prior = $this->priorOccurrence($text, $vehicle, $ticket);
        if ($prior) {
            return [self::REASON_REPEAT, $prior];
        }

        return [null, []];
    }

    /**
     * The most recent time this exact finding was raised on this car, on a DIFFERENT ticket, inside the
     * window. Read from `maintenance_tasks` rather than by scanning findings JSON across the fleet: the
     * task table is the promoted, indexed form of the same statement, and a finding that never became a
     * task never became work — which is precisely the case where calling it a repeat would be wrong.
     *
     * A fault the workshop marked incorrect or not-found is skipped: it was withdrawn, so raising it
     * again is a fresh claim, not a duplicate. See [[mark-fault-incorrect-feature]].
     *
     * @return array{prior_ticket_id:int,prior_at:?string,prior_status:string,days_ago:int}|null
     */
    private function priorOccurrence(string $text, ?Vehicle $vehicle, Maintenance $ticket): ?array
    {
        if (! $vehicle) {
            return null;
        }

        $days  = max(1, (int) config('maintenance.finding_approval.repeat_window_days', 90));
        $since = Carbon::now()->subDays($days);

        $prior = MaintenanceTask::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('maintenance_id', '!=', $ticket->id)
            ->where('identified_at', '>=', $since)
            ->whereNull('marked_incorrect_at')
            ->where('status', '!=', MaintenanceTask::STATUS_NOT_FOUND)
            ->orderByDesc('identified_at')
            ->get(['id', 'maintenance_id', 'symptom', 'status', 'identified_at'])
            ->first(fn (MaintenanceTask $t) => $this->key((string) $t->symptom) === $this->key($text));

        if (! $prior) {
            return null;
        }

        return [
            'prior_ticket_id' => (int) $prior->maintenance_id,
            'prior_at'        => $prior->identified_at?->toIso8601String(),
            'prior_status'    => (string) $prior->status,
            'days_ago'        => $prior->identified_at ? (int) $prior->identified_at->diffInDays(Carbon::now()) : 0,
        ];
    }

    /**
     * Tell the world a finding is being held: one timeline row per held finding (so the car's own history
     * names who tried to log it and what the data said), then ONE notification to the approvers carrying
     * the link that opens this ticket's card.
     *
     * Called by whichever write raised the hold, AFTER the findings are saved — a notification pointing at
     * a card that does not carry the finding yet is worse than a late one.
     *
     * @param array $held  the held findings (each the full findings-JSON entry)
     */
    public function announce(Maintenance $ticket, array $held, User $actor): void
    {
        if (! $held) {
            return;
        }

        foreach ($held as $f) {
            $this->log->record($ticket, VehicleLogEvent::EVENT_FINDING_APPROVAL_REQUIRED, $actor, [
                'description' => $actor->name . ' logged "' . $f['text'] . '" — ' . $this->sentence($f['approval'])
                    . '. Held for approval; the ticket cannot move until it is decided.',
                'meta'        => [
                    'finding'  => $f['text'],
                    'reason'   => $f['approval']['reason'] ?? null,
                    'params'   => $f['approval']['params'] ?? [],
                ],
            ]);
        }

        $vehicle = $ticket->loadMissing('vehicle')->vehicle;
        $names   = collect($held)->pluck('text')->implode(', ');
        $label   = trim(($vehicle?->plate_no ?: '') . ' ' . trim((string) ($vehicle?->make . ' ' . $vehicle?->model)));

        $this->notifier->notifyByPermission($this->approverPermission(), [
            'type'     => 'maint_finding_approval',
            'category' => 'maintenance',
            'severity' => 'warning',
            'title'    => '🛑 Approval needed · ' . ($label ?: ('Ticket #' . $ticket->id)),
            'body'     => $actor->name . ' logged ' . $names . ' on ' . ($label ?: ('ticket #' . $ticket->id))
                . ', but the car\'s data disagrees — ' . $this->sentence($held[0]['approval'])
                . '. The ticket is held until you approve or reject it.',
            'url'      => '/maintenance-workflow/' . $ticket->id,
            // Keyed on the ticket AND the held set, so a second held finding raises its own ask instead of
            // being swallowed as a duplicate of the first, while a re-filed identical report does not nag.
            'key'      => 'maint_wf:' . $ticket->id . ':finding_approval:' . md5(mb_strtolower($names)),
            'icon'     => 'alert',
            'meta'     => [
                'ticket_id' => $ticket->id,
                'plate'     => $vehicle?->plate_no,
                'findings'  => collect($held)->pluck('text')->values()->all(),
            ],
        ], $actor->id);
    }

    // ── Reading the hold ────────────────────────────────────────────────────────

    /** Is this finding waiting on someone? */
    public static function isPending(array $finding): bool
    {
        return ($finding['approval']['state'] ?? null) === self::STATE_PENDING;
    }

    /**
     * Should this finding be kept OUT of the workable fault list? Pending (nobody has said yes yet) and
     * rejected (someone said no) both answer yes; approved and un-challenged findings promote normally.
     */
    public static function blocksPromotion(array $finding): bool
    {
        $state = $finding['approval']['state'] ?? null;

        return $state === self::STATE_PENDING || $state === self::STATE_REJECTED;
    }

    /** Every finding on this ticket still waiting on a decision. */
    public function pending(Maintenance $ticket): array
    {
        return collect($ticket->findings ?? [])
            ->filter(fn ($f) => is_array($f) && self::isPending($f))
            ->values()
            ->all();
    }

    /**
     * THE HOLD ITSELF. Called from MaintenanceWorkflowService::assertTransition, which every staged
     * transition routes through, so the ticket sits exactly where it was raised until it is decided.
     *
     * Deliberately blocks EVERY destination including the terminal ones: closing a ticket carrying an
     * unapproved oil change is the outcome this exists to prevent, and "reject it" is always available as
     * the way out — a rejected finding stops being pending and the ticket moves on.
     */
    public function assertNothingPending(Maintenance $ticket, ?string $to = null): void
    {
        $pending = $this->pending($ticket);
        if (! $pending) {
            return;
        }

        $names = collect($pending)->pluck('text')->implode(', ');

        throw new WorkflowTransitionException(
            'This ticket is waiting for an approval on ' . $names . ' — ' . $this->sentence($pending[0]['approval'])
            . '. An approver must approve or reject it before the ticket moves on.',
            [
                'from'    => $ticket->workflow_status,
                'to'      => $to,
                'field'   => 'finding_approval',
                'pending' => collect($pending)->map(fn ($f) => [
                    'finding' => $f['text'],
                    'reason'  => $f['approval']['reason'] ?? null,
                    'params'  => $f['approval']['params'] ?? [],
                ])->values()->all(),
            ],
        );
    }

    // ── Deciding ────────────────────────────────────────────────────────────────

    /**
     * An approver signs one held finding off, or throws it out.
     *
     * APPROVE promotes it: the fault/service becomes a real MaintenanceTask and the work can start —
     * the approver has overruled the data, which is a legitimate thing for a person looking at the car to
     * do, and the trail records that they did it.
     *
     * REJECT leaves the finding on the ticket, marked rejected. It is NOT deleted: "someone tried to log
     * an oil change this car did not need, and it was refused" is exactly the fact worth keeping. It never
     * becomes work, and the ticket is free to move.
     *
     * @param  string  $action  'approve' | 'reject'
     */
    public function decide(Maintenance $ticket, string $findingText, string $action, ?string $note, User $actor): Maintenance
    {
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw new WorkflowTransitionException('Say approve or reject.', ['field' => 'action']);
        }

        return DB::transaction(function () use ($ticket, $findingText, $action, $note, $actor) {
            // Lock the row: two approvers opening the same notification must not both decide.
            $locked   = Maintenance::where('id', $ticket->id)->lockForUpdate()->firstOrFail();
            $findings = is_array($locked->findings) ? $locked->findings : [];
            $key      = $this->key($findingText);
            $index    = null;

            foreach ($findings as $i => $f) {
                if (is_array($f) && $this->key((string) ($f['text'] ?? '')) === $key && self::isPending($f)) {
                    $index = $i;
                    break;
                }
            }

            if ($index === null) {
                throw new WorkflowTransitionException(
                    'There is no finding waiting for approval called "' . $findingText . '" on this ticket — it may already have been decided.',
                    ['field' => 'finding', 'finding' => $findingText],
                );
            }

            $approved = $action === 'approve';
            $approval = $findings[$index]['approval'];
            $approval['state']         = $approved ? self::STATE_APPROVED : self::STATE_REJECTED;
            $approval['decided_by']    = $actor->name;
            $approval['decided_by_id'] = $actor->id;
            $approval['decided_at']    = Carbon::now()->toIso8601String();
            $approval['note']          = $note !== null && trim($note) !== '' ? trim($note) : null;
            $findings[$index]['approval'] = $approval;

            $locked->findings = array_values($findings);
            $locked->save();
            $ticket->setRawAttributes($locked->getAttributes(), true);
            $ticket->syncOriginal();

            $text = (string) $findings[$index]['text'];

            // Approved → the finding becomes work. syncFromFindings is idempotent and now sees an
            // approved (no longer blocking) entry, so exactly this one fault is promoted.
            if ($approved) {
                app(MaintenanceTaskService::class)->syncFromFindings($ticket, $actor);
            }

            $this->log->record(
                $ticket,
                $approved ? VehicleLogEvent::EVENT_FINDING_APPROVED : VehicleLogEvent::EVENT_FINDING_REJECTED,
                $actor,
                [
                    // WHO TRIED AND WHO DECIDED, in one sentence, on the car's own timeline.
                    'description' => $actor->name . ($approved ? ' approved "' : ' rejected "') . $text . '" '
                        . 'logged by ' . ($approval['requested_by'] ?? 'unknown')
                        . ' — ' . $this->sentence($approval)
                        . ($approval['note'] ? '. Note: ' . $approval['note'] : '')
                        . ($approved ? '. The job goes ahead.' : '. The job does not go ahead.'),
                    'meta' => [
                        'finding'         => $text,
                        'reason'          => $approval['reason'] ?? null,
                        'params'          => $approval['params'] ?? [],
                        'requested_by'    => $approval['requested_by'] ?? null,
                        'requested_by_id' => $approval['requested_by_id'] ?? null,
                        'decision'        => $approval['state'],
                        'note'            => $approval['note'],
                    ],
                ],
            );

            // Tell the person who logged it what happened to it. They are waiting on this answer and are
            // not necessarily in the approvers' group, so this is a direct ping, not a broadcast.
            $this->notifyRequester($ticket, $approval, $text, $approved, $actor);

            return $ticket;
        });
    }

    private function notifyRequester(Maintenance $ticket, array $approval, string $text, bool $approved, User $actor): void
    {
        $requesterId = $approval['requested_by_id'] ?? null;
        if (! $requesterId || (int) $requesterId === $actor->id) {
            return;
        }

        $requester = User::find($requesterId);
        if (! $requester) {
            return;
        }

        $vehicle = $ticket->loadMissing('vehicle')->vehicle;
        $label   = trim(($vehicle?->plate_no ?: '') . ' ' . trim((string) ($vehicle?->make . ' ' . $vehicle?->model)));

        $this->notifier->notifyUser($requester, [
            'type'     => 'maint_finding_approval_decided',
            'category' => 'maintenance',
            'severity' => $approved ? 'info' : 'warning',
            'title'    => ($approved ? '✅ Approved · ' : '⛔ Rejected · ') . $text,
            'body'     => $actor->name . ($approved ? ' approved ' : ' rejected ') . $text . ' on '
                . ($label ?: ('ticket #' . $ticket->id))
                . ($approval['note'] ? ' — ' . $approval['note'] : '') . '.',
            'url'      => '/maintenance-workflow/' . $ticket->id,
            'key'      => 'maint_wf:' . $ticket->id . ':finding_approval_decided:' . md5(mb_strtolower($text)),
            'icon'     => 'wrench',
            'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'finding' => $text],
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /**
     * The held reason as an English sentence, for the audit trail and the notification body — the two
     * places that are plain text by nature. Every SCREEN composes its own from the code + params so it
     * reads natively in Arabic ([[reason-code-contract]], [[operational-language-over-engine-vocabulary]]).
     */
    private function sentence(array $approval): string
    {
        $params = $approval['params'] ?? [];

        return match ($approval['reason'] ?? null) {
            self::REASON_NOT_NEEDED => 'the car\'s own status says this is not due'
                . (! empty($params['summary']) ? ' (' . $params['summary'] . ')' : ''),
            self::REASON_REPEAT => 'the same thing was already raised on this car'
                . (isset($params['days_ago']) ? ' ' . $params['days_ago'] . ' days ago' : '')
                . (isset($params['prior_ticket_id']) ? ' on ticket #' . $params['prior_ticket_id'] : ''),
            default => 'the car\'s data disagrees with it',
        };
    }

    /** Normalise a finding/symptom label so "Oil Change" and "oil  change" are the same thing. */
    private function key(string $text): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($text))) ?? '';
    }

    private function approverPermission(): string
    {
        return (string) config('maintenance.finding_approval.approver_permission', 'maintenance.manage');
    }
}
