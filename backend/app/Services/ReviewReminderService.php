<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\ReviewReminder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Remind me about this request later" — the engine behind the Inspection Review Queue's snooze.
 *
 * Evidence class: R (Record). Every field it writes is a fact about a HUMAN DECISION — this person, on
 * this request, asked to be reminded at this moment — not a judgement about the car. Nothing here is
 * inferred, scored or confidence-weighted, and nothing here may be re-derived: a reminder that could be
 * recomputed tomorrow from fleet state would not need storing at all.
 *
 *   Produces — review_reminders rows; `maint_review_reminder` notifications (via ReviewRemindersDispatch).
 *   Consumes — maintenances (the request being remembered), users (the person who asked).
 *
 * The cancellation rule is the part worth reading twice: a `pending_review` reminder exists only because
 * the request was still undecided, so approving or rejecting that request cancels it. Otherwise a
 * Controller who approves a car at 14:00 gets pinged at 16:00 to "review" something they already sent to
 * Abu Maroof, and the queue's reminders become noise nobody trusts. A `rejected_revisit` reminder is the
 * exception and survives, because the rejection is what created it.
 *
 * See [[inspection-review-system-detail]].
 */
class ReviewReminderService
{
    /**
     * The presets the queue offers, as MINUTES from now. The custom option sends an explicit timestamp
     * instead and does not appear here. `tomorrow_morning` is deliberately absent: "tomorrow at 08:00" is
     * a clock time, not an offset, and is resolved by presetToMoment() below.
     */
    public const OFFSET_PRESETS = [
        '30m' => 30,
        '1h'  => 60,
        '2h'  => 120,
        '4h'  => 240,
    ];

    /** Presets that resolve to a wall-clock moment rather than an offset. */
    public const CLOCK_PRESETS = ['tomorrow_morning', 'next_week'];

    /** Every accepted preset key — the validator's list. */
    public static function presets(): array
    {
        return array_merge(array_keys(self::OFFSET_PRESETS), self::CLOCK_PRESETS);
    }

    /**
     * Turn a preset key into the actual moment, in the app's timezone.
     *
     * "Tomorrow morning" means the start of the next working day for the person setting it (08:00), not
     * "24 hours from now" — a reminder set at 19:00 that fires at 19:00 the next day has missed the day
     * it was meant to protect.
     */
    public function presetToMoment(string $preset, ?Carbon $from = null): ?Carbon
    {
        $now = ($from ?: Carbon::now())->copy();

        if (isset(self::OFFSET_PRESETS[$preset])) {
            return $now->addMinutes(self::OFFSET_PRESETS[$preset]);
        }

        return match ($preset) {
            'tomorrow_morning' => $now->addDay()->setTime(8, 0),
            'next_week'        => $now->addWeek()->setTime(8, 0),
            default            => null,
        };
    }

    /**
     * Set (or move) this user's reminder on a request.
     *
     * One live reminder per person per request: asking again REPLACES the previous one rather than
     * stacking a second ping. That is what "remind me in 2 hours — actually, make it 4" means, and it
     * keeps the card able to show a single, unambiguous "you'll be reminded at …".
     */
    public function schedule(
        Maintenance $ticket,
        User $user,
        Carbon $remindAt,
        ?string $note = null,
        string $kind = ReviewReminder::KIND_PENDING_REVIEW,
    ): ReviewReminder {
        return DB::transaction(function () use ($ticket, $user, $remindAt, $note, $kind) {
            // Supersede, don't stack. Cancelled rather than deleted so the history of "they kept pushing
            // this one back" stays readable.
            ReviewReminder::where('maintenance_id', $ticket->id)
                ->where('user_id', $user->id)
                ->where('kind', $kind)
                ->pending()
                ->update([
                    'status'           => ReviewReminder::STATUS_CANCELLED,
                    'cancelled_at'     => Carbon::now(),
                    'cancelled_reason' => ReviewReminder::CANCELLED_BY_USER,
                    'updated_at'       => Carbon::now(),
                ]);

            return ReviewReminder::create([
                'maintenance_id' => $ticket->id,
                'vehicle_id'     => $ticket->vehicle_id,
                'user_id'        => $user->id,
                'kind'           => $kind,
                'remind_at'      => $remindAt,
                'note'           => $note !== null && trim($note) !== '' ? trim($note) : null,
                'status'         => ReviewReminder::STATUS_PENDING,
            ]);
        });
    }

    /** Drop this user's own pending reminder on a request. Returns how many were cancelled (0 or 1). */
    public function cancelForUser(Maintenance $ticket, User $user): int
    {
        return ReviewReminder::where('maintenance_id', $ticket->id)
            ->where('user_id', $user->id)
            ->pending()
            ->update([
                'status'           => ReviewReminder::STATUS_CANCELLED,
                'cancelled_at'     => Carbon::now(),
                'cancelled_reason' => ReviewReminder::CANCELLED_BY_USER,
                'updated_at'       => Carbon::now(),
            ]);
    }

    /**
     * The request was decided — cancel every "look at this again" reminder anyone set on it.
     *
     * Scoped to KIND_PENDING_REVIEW on purpose: a revisit reminder attached to the rejection must outlive
     * the rejection that created it.
     */
    public function cancelOnDecision(Maintenance $ticket): int
    {
        return ReviewReminder::where('maintenance_id', $ticket->id)
            ->where('kind', ReviewReminder::KIND_PENDING_REVIEW)
            ->pending()
            ->update([
                'status'           => ReviewReminder::STATUS_CANCELLED,
                'cancelled_at'     => Carbon::now(),
                'cancelled_reason' => ReviewReminder::CANCELLED_REVIEWED,
                'updated_at'       => Carbon::now(),
            ]);
    }

    /** This user's live reminder on a request, if they set one. What the card renders. */
    public function forUser(Maintenance $ticket, ?User $user): ?ReviewReminder
    {
        if (! $user) {
            return null;
        }

        return ReviewReminder::where('maintenance_id', $ticket->id)
            ->where('user_id', $user->id)
            ->pending()
            ->orderBy('remind_at')
            ->first();
    }

    /**
     * Same read, for a whole queue at once — the review queue renders ~140 cards and must not fire one
     * query per card. Returns maintenance_id => ReviewReminder.
     *
     * @param  iterable<int>  $ticketIds
     */
    public function forUserAcross(iterable $ticketIds, ?User $user): Collection
    {
        $ids = collect($ticketIds)->filter()->unique()->values();
        if (! $user || $ids->isEmpty()) {
            return collect();
        }

        return ReviewReminder::whereIn('maintenance_id', $ids)
            ->where('user_id', $user->id)
            ->pending()
            ->orderBy('remind_at')
            ->get()
            ->keyBy('maintenance_id');
    }
}
