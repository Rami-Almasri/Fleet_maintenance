<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Services\MaintenanceCheckpointService;
use App\Services\NotificationScanner;
use Illuminate\Console\Command;

/**
 * Maintenance Checkpoint Scan — the proactive engine behind the progress-tracking system. For every car
 * actively in the workshop it asks MaintenanceCheckpointService::monitorState "does this job still owe a
 * progress update, and how loud should the ask be?", then pushes the reminder ONLY to that ticket's
 * responsible follow-up owners (Waleed/Abdullah, or its assigned users).
 *
 * The cadence is DAILY, and it does not stop until the car does. From the day the reminder window opens
 * (a day before the promised date) the supervisor is asked EVERY DAY, and how loud the ask is depends only
 * on where that day sits against the promise:
 *   request   → info      · the day the window opens
 *   reminder  → warning   · a further day still inside the lead window (lead > 1 day)
 *   due_today → critical  · the promised day arrived with no answer
 *   overdue   → critical  · past due with no answer (the dashboard shows it red)
 *
 * Answering settles the DAY, not the car: file a checkpoint and today goes quiet, but tomorrow the
 * question is asked again — unless the answer pushed the date back, which moves the whole window with it
 * (promise the 23rd → the chase reopens on the 22nd). monitorState owns that rule; this command only
 * delivers what it decides.
 *
 * Idempotent: the notification key carries the day, so running several times a day never double-pushes.
 * Every push is also LOGGED (maintenance_checkpoint_reminders) against the supervisor it went to, and the
 * checkpoint that eventually arrives closes it — which is how the Checkpoint Compliance board can show an
 * admin exactly which reminders went out and were never answered.
 */
class CheckpointsScan extends Command
{
    protected $signature = 'checkpoints:scan';

    protected $description = 'Chase workshop progress updates: notify responsible users before a car goes overdue';

    /** Per-level presentation (severity + title prefix). */
    private const LEVELS = [
        'request'   => ['severity' => 'info',     'label' => '🔧 Checkpoint required'],
        'reminder'  => ['severity' => 'warning',  'label' => '⏰ Daily checkpoint reminder'],
        'due_today' => ['severity' => 'critical', 'label' => '🚩 Due today — no update'],
        'overdue'   => ['severity' => 'critical', 'label' => '🔴 Checkpoint overdue'],
    ];

    public function handle(NotificationScanner $notifier, MaintenanceCheckpointService $checkpoints): int
    {
        $today = today()->toDateString();

        $tickets = Maintenance::query()
            ->whereIn('workflow_status', Maintenance::CHECKPOINT_TRACKED_STATES)
            ->with(['vehicle:id,plate_no,make,model', 'vendor:id,name', 'checkpoints'])
            ->get();

        $pushed     = 0;
        $flagged    = 0;
        $unassigned = 0;
        foreach ($tickets as $ticket) {
            $state = $checkpoints->monitorState($ticket);
            $level = $state['escalation'];
            if (! $level || ! isset(self::LEVELS[$level])) {
                continue; // covered, or not yet within the reminder window
            }
            $flagged++;

            $recipients = $checkpoints->recipientsFor($ticket);
            if ($recipients->isEmpty()) {
                // Nobody owns this car's follow-up. We deliberately do NOT fall back to "everyone holding
                // a broad permission" — that trains people to ignore the bell. The gap is reported here
                // and listed on the Checkpoint Compliance board for an admin to assign.
                $unassigned++;
                continue;
            }

            $plate  = $ticket->vehicle?->plate_no
                ?: (trim(($ticket->vehicle?->make ?? '') . ' ' . ($ticket->vehicle?->model ?? '')) ?: ('Ticket #' . $ticket->id));
            $garage = $ticket->vendor?->name ?: $ticket->garage;
            $meta   = self::LEVELS[$level];

            // Keyed on the DAY: every level re-fires once a day for as long as the question goes
            // unanswered, and running the scan repeatedly within a day never double-pushes.
            $key = 'maint_checkpoint:' . $ticket->id . ':' . $level . ':' . ($state['expected_on'] ?? 'na')
                . ':' . $today;

            $body = $this->body($level, $plate, $garage, $state);

            foreach ($recipients as $user) {
                // Log the receipt BEFORE the push, so a delivery that fails downstream still leaves the
                // trace an admin audits — silence must never look like "we never asked". The receipt is
                // also the dedup ledger: it returns false when this supervisor has already had this exact
                // ask today, so the twice-daily cron cannot double-ping (notifyUser has no dedup of its
                // own). A genuinely louder level later the same day still gets through.
                if (! $checkpoints->recordReminder($ticket, $user, $level, $state['expected_on'] ?? null)) {
                    continue;
                }

                $notifier->notifyUser($user, [
                    'type'     => 'maint_checkpoint',
                    'category' => 'maintenance',
                    'severity' => $meta['severity'],
                    'title'    => $meta['label'] . ' · ' . $plate,
                    'body'     => $body,
                    // Deep-link to THE TICKET — which carries the same checkpoint panel and "File update"
                    // button, and is the one page about the car this reminder is about.
                    //
                    // Deliberately NOT the Dashboard: the recipients here are the supervisors
                    // (recipientsFor → Waleed/Abdullah), and the `supervisor` role is DENIED /dashboard in
                    // config/access.js. Sending the chase to a page its recipient cannot open silently
                    // kills the whole checkpoint loop. The ticket is reachable by every role that can be
                    // asked to file one.
                    'url'      => '/maintenance-workflow/' . $ticket->id,
                    'key'      => $key . ':u' . $user->id,
                    'icon'     => 'wrench',
                    'meta'     => [
                        'ticket_id'   => $ticket->id,
                        'vehicle_id'  => $ticket->vehicle_id,
                        'plate'       => $ticket->vehicle?->plate_no,
                        'garage'      => $garage,
                        'expected_on' => $state['expected_on'],
                        'days_over'   => $state['days_over'],
                        'escalation'  => $level,
                    ],
                ]);
                $pushed++;
            }
        }

        $this->info(sprintf(
            'Checkpoint scan — %d in-shop ticket(s), %d need an update, %d reminder(s) pushed.',
            $tickets->count(), $flagged, $pushed
        ));

        if ($unassigned > 0) {
            $this->warn(sprintf(
                '%d car(s) need a checkpoint but have no responsible owner — no reminder was sent. '
                . 'Assign one on /oversight/checkpoint-compliance.',
                $unassigned
            ));
        }

        return self::SUCCESS;
    }

    private function body(string $level, string $plate, ?string $garage, array $state): string
    {
        $at  = $garage ? (' at ' . $garage) : '';
        $exp = $state['expected_on'] ? (\Carbon\Carbon::parse($state['expected_on'])->toFormattedDateString()) : null;

        // Every message asks the SAME question — "is it still coming back on that date?" — because that is
        // the one the checkpoint form now puts in front of them: confirm the date, or give a new one and
        // say why.
        return match ($level) {
            'request'   => trim($plate . $at . ' is expected back ' . ($exp ?: 'soon')
                . '. Confirm it is still coming back that day, or set a new date and the reason.'),
            'reminder'  => trim('Still no answer on ' . $plate . $at . ' (expected ' . ($exp ?: 'soon')
                . '). Confirm the date or give a new one with the reason.'),
            'due_today' => trim($plate . $at . ' is due back today with no update. Confirm it is ready, or set a new date and the reason.'),
            'overdue'   => trim($plate . $at . ' is ' . max(1, (int) $state['days_over'])
                . ' day(s) past the date it was promised back, with no update — set a new date and the reason now.'),
            default     => $plate . ' needs a checkpoint.',
        };
    }
}
