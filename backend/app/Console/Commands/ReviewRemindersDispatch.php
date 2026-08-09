<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Models\ReviewReminder;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\NotificationScanner;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Fire the reminders a Controller set on the Inspection Review Queue ("remind me about this car in 2
 * hours"). One row, one moment, one person — see ReviewReminderService.
 *
 * This is the ONE reminder in the app that is not derive-on-scan. Everything else here (checkpoints:scan,
 * service:sync-reminders) recomputes who is late from the state of the fleet; that cannot express a
 * person's decision to wait, so this command does the dull thing instead: read the rows whose moment has
 * passed, push each to its owner, mark it sent.
 *
 * CADENCE: every ten minutes. The presets are 30m / 1h / 2h / 4h / tomorrow 08:00, so a ten-minute wheel
 * makes the worst case "you asked for 2 hours and got 2 hours 9 minutes" — close enough to be trusted,
 * cheap enough to run all day (the query is one indexed read of status+remind_at, empty almost every time).
 *
 * IDEMPOTENT by row, not by key: a reminder leaves the working set the instant it flips to `sent`, so
 * running this by hand as often as you like can never double-push. That matters because the scheduler is
 * unverified on the server ([[scheduler-audit]]) — this is safe to run manually, and safe to run twice.
 *
 * SELF-CANCELLING: a pending_review reminder about a request somebody has already approved or rejected is
 * discarded rather than sent. The decision paths cancel their own reminders, so reaching this branch means
 * the request left pending_review some other way (deleted, migrated, cascaded) — it is the safety net, and
 * it is the reason a Controller never gets pinged to "review" a car that went to Abu Maroof hours ago.
 */
class ReviewRemindersDispatch extends Command
{
    protected $signature = 'review-reminders:dispatch
                            {--dry-run : List what would fire without notifying anyone or marking rows sent}';

    protected $description = 'Push the due "remind me later" reminders set on the Inspection Review Queue';

    public function handle(NotificationScanner $notifier): int
    {
        $dry = (bool) $this->option('dry-run');
        $now = Carbon::now();

        $due = ReviewReminder::due($now)->orderBy('remind_at')->get();

        if ($due->isEmpty()) {
            $this->info('No reminders due.');

            return self::SUCCESS;
        }

        // One read each for the tickets and the people, rather than one per reminder.
        $tickets = Maintenance::withTrashed()
            ->whereIn('id', $due->pluck('maintenance_id')->unique()->all())
            ->get()
            ->keyBy('id');
        $vehicles = Vehicle::whereIn('id', $tickets->pluck('vehicle_id')->filter()->unique()->all())
            ->get(['id', 'plate_no', 'make', 'model'])
            ->keyBy('id');
        $users = User::whereIn('id', $due->pluck('user_id')->unique()->all())->get()->keyBy('id');

        $sent = $skipped = 0;

        foreach ($due as $reminder) {
            $ticket = $tickets->get($reminder->maintenance_id);
            $user   = $users->get($reminder->user_id);

            // The ticket or the person is gone — close the row quietly; there is nobody to tell.
            if (! $ticket || ! $user) {
                $skipped++;
                $this->line("· #{$reminder->id} — ticket or recipient no longer exists, closing");
                if (! $dry) {
                    $this->close($reminder, ReviewReminder::CANCELLED_GONE);
                }
                continue;
            }

            // Already decided — see the class doc. Nothing is sent.
            if ($reminder->kind === ReviewReminder::KIND_PENDING_REVIEW
                && $ticket->workflow_status !== Maintenance::WF_PENDING_REVIEW) {
                $skipped++;
                $this->line("· #{$reminder->id} — request already reviewed, closing");
                if (! $dry) {
                    $this->close($reminder, ReviewReminder::CANCELLED_REVIEWED);
                }
                continue;
            }

            $vehicle = $vehicles->get($ticket->vehicle_id);
            $plate   = $vehicle?->plate_no ?: ('#' . $ticket->id);
            $revisit = $reminder->kind === ReviewReminder::KIND_REJECTED_REVISIT;

            $this->line("→ #{$reminder->id} {$plate} → {$user->name} ({$reminder->kind})");

            if ($dry) {
                $sent++;
                continue;
            }

            $notifier->notifyUser($user, [
                'type'     => 'maint_review_reminder',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => ($revisit ? 'Revisit the rejected request · ' : 'Reminder: inspection request · ') . $plate,
                'body'     => $reminder->note
                    ?: ($revisit
                        ? 'You asked to look at this request again.'
                        : 'You asked to be reminded about this request awaiting your review.'),
                // Back to the exact card. The queue reads ?ticket= and scrolls it into view.
                'url'      => $revisit
                    ? '/maintenance-workflow/' . $ticket->id
                    : '/inspection-review?ticket=' . $ticket->id,
                // Keyed by the reminder ROW, not the ticket: setting a second reminder on the same car
                // later is a different ask and must not be swallowed as a duplicate of this one.
                'key'      => 'review_reminder:' . $reminder->id,
                'icon'     => 'clock',
                'meta'     => [
                    'ticket_id'   => $ticket->id,
                    'reminder_id' => $reminder->id,
                    'plate'       => $vehicle?->plate_no,
                    'kind'        => $reminder->kind,
                ],
            ]);

            $reminder->forceFill([
                'status'  => ReviewReminder::STATUS_SENT,
                'sent_at' => $now,
            ])->save();
            $sent++;
        }

        $this->info(($dry ? '[dry run] ' : '') . "Reminders sent: {$sent} · closed without sending: {$skipped}");

        return self::SUCCESS;
    }

    /** Retire a reminder that should never fire, with the reason it stopped mattering. */
    private function close(ReviewReminder $reminder, string $reason): void
    {
        $reminder->forceFill([
            'status'           => ReviewReminder::STATUS_CANCELLED,
            'cancelled_at'     => Carbon::now(),
            'cancelled_reason' => $reason,
        ])->save();
    }
}
