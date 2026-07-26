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
 * The four-step escalation, keyed off the promised completion date:
 *   request   → info      · a day before (lead window opens)
 *   reminder  → warning   · 12h later, still no update
 *   due_today → critical  · the promised day arrived with no update
 *   overdue   → critical  · past due with no update (re-fires daily; the dashboard shows it red)
 *
 * Idempotent: the key carries the level + expected date (+ today, for the daily overdue nag), so a level
 * never double-fires and the whole chain clears the moment a checkpoint is submitted (which moves the
 * window and stamps last_checkpoint_at). Runs a few times a day so the 12h reminder lands on time.
 */
class CheckpointsScan extends Command
{
    protected $signature = 'checkpoints:scan';

    protected $description = 'Chase workshop progress updates: notify responsible users before a car goes overdue';

    /** Per-level presentation (severity + title prefix). */
    private const LEVELS = [
        'request'   => ['severity' => 'info',     'label' => '🔧 Checkpoint required'],
        'reminder'  => ['severity' => 'warning',  'label' => '⏰ Checkpoint reminder'],
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

        $pushed  = 0;
        $flagged = 0;
        foreach ($tickets as $ticket) {
            $state = $checkpoints->monitorState($ticket);
            $level = $state['escalation'];
            if (! $level || ! isset(self::LEVELS[$level])) {
                continue; // covered, or not yet within the reminder window
            }
            $flagged++;

            $recipients = $checkpoints->recipientsFor($ticket);
            if ($recipients->isEmpty()) {
                continue; // no one to chase — nothing to send
            }

            $plate  = $ticket->vehicle?->plate_no
                ?: (trim(($ticket->vehicle?->make ?? '') . ' ' . ($ticket->vehicle?->model ?? '')) ?: ('Ticket #' . $ticket->id));
            $garage = $ticket->vendor?->name ?: $ticket->garage;
            $meta   = self::LEVELS[$level];

            // The daily overdue nag carries the date so it re-fires once a day; the one-shot levels carry
            // only the expected date so each fires exactly once per completion window.
            $key = 'maint_checkpoint:' . $ticket->id . ':' . $level . ':' . ($state['expected_on'] ?? 'na')
                . ($level === 'overdue' ? ':' . $today : '');

            $body = $this->body($level, $plate, $garage, $state);

            foreach ($recipients as $user) {
                $notifier->notifyUser($user, [
                    'type'     => 'maint_checkpoint',
                    'category' => 'maintenance',
                    'severity' => $meta['severity'],
                    'title'    => $meta['label'] . ' · ' . $plate,
                    'body'     => $body,
                    'url'      => $ticket->vehicle_id ? ('/vehicles/' . $ticket->vehicle_id . '?tab=checkpoints') : '/dashboard',
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

        return self::SUCCESS;
    }

    private function body(string $level, string $plate, ?string $garage, array $state): string
    {
        $at  = $garage ? (' at ' . $garage) : '';
        $exp = $state['expected_on'] ? (\Carbon\Carbon::parse($state['expected_on'])->toFormattedDateString()) : null;

        return match ($level) {
            'request'   => trim($plate . $at . ' is expected ready ' . ($exp ?: 'soon')
                . '. Contact the workshop and submit a progress update (status, note, photos/video).'),
            'reminder'  => trim('Still no update on ' . $plate . $at . ' (expected ' . ($exp ?: 'soon')
                . '). Please file a checkpoint.'),
            'due_today' => trim($plate . $at . ' is due today with no checkpoint. Confirm whether it is ready or delayed.'),
            'overdue'   => trim($plate . $at . ' is ' . max(1, (int) $state['days_over'])
                . ' day(s) past its expected completion with no update — please file a checkpoint now.'),
            default     => $plate . ' needs a checkpoint.',
        };
    }
}
