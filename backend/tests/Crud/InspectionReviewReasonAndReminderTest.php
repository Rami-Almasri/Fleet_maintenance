<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\ReviewReminder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Inspection Review Gate — the REASON on a rejection and the "remind me later" on a pending request.
 *
 * The two behaviours are tested together because they meet: rejecting a request cancels the reminders
 * that were waiting on it, EXCEPT the revisit reminder the rejection itself booked. That single
 * asymmetry is the thing most likely to break silently, so it gets its own test twice over.
 */
class InspectionReviewReasonAndReminderTest extends CrudTestCase
{
    /** A request sitting in the review queue, exactly as a Driver's /request leaves it. */
    private function pendingRequest(): Maintenance
    {
        $vehicleId = $this->makeVehicle();

        $res = $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'         => $vehicleId,
            // TRIGGER_DRIVER_REPORTED is deliberately NOT accepted at this endpoint (it is set only by
            // DriverObservationService), so a Driver's own flag comes in as a test drive.
            'trigger_reason'     => Maintenance::TRIGGER_TEST_DRIVE,
            'customer_complaint' => 'Knocking noise from the front left.',
        ]);
        $res->assertSuccessful();

        $ticket = Maintenance::findOrFail($this->idOf($res));
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->workflow_status);

        return $ticket;
    }

    // ── The rejection REASON ──────────────────────────────────────────────────────────────────────

    public function test_rejection_stores_the_reason_code_and_the_note(): void
    {
        $ticket = $this->pendingRequest();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/reject", [
            'rejection_code'   => Maintenance::REVIEW_REJECT_CAR_UNAVAILABLE,
            'rejection_reason' => 'Customer has it until Sunday.',
        ])->assertSuccessful();

        $ticket->refresh();
        $this->assertSame(Maintenance::WF_REVIEW_REJECTED, $ticket->workflow_status);
        $this->assertSame(Maintenance::REVIEW_REJECT_CAR_UNAVAILABLE, $ticket->review_rejection_code);
        $this->assertSame('Customer has it until Sunday.', $ticket->review_rejection_reason);

        // The code, not the sentence, is what the audit trail can be counted on.
        $this->assertDatabaseHas('vehicle_log_events', [
            'maintenance_id' => $ticket->id,
            'event_type'     => 'review_rejected',
        ]);
    }

    public function test_rejection_reason_code_is_exposed_with_its_label(): void
    {
        $ticket = $this->pendingRequest();

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/reject", [
            'rejection_code' => Maintenance::REVIEW_REJECT_DUPLICATE,
        ]);
        $res->assertSuccessful();

        $this->assertSame(Maintenance::REVIEW_REJECT_DUPLICATE, data_get($res->json(), 'data.review.rejection_code'));
        $this->assertSame(
            Maintenance::REVIEW_REJECTION_REASONS[Maintenance::REVIEW_REJECT_DUPLICATE],
            data_get($res->json(), 'data.review.rejection_label'),
        );
    }

    public function test_the_reason_list_endpoint_matches_the_model(): void
    {
        $res = $this->getJson('/api/maintenance-tickets/review/rejection-reasons');
        $res->assertSuccessful();

        $codes = collect($res->json('data'))->pluck('code')->all();
        $this->assertSame(array_keys(Maintenance::REVIEW_REJECTION_REASONS), $codes);

        // The frontend derives "this one needs a note" from the payload rather than hardcoding it.
        $other = collect($res->json('data'))->firstWhere('code', Maintenance::REVIEW_REJECT_OTHER);
        $this->assertTrue($other['requires_note']);
    }

    public function test_an_unrecognised_reason_code_is_refused(): void
    {
        $ticket = $this->pendingRequest();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/reject", [
            'rejection_code' => 'because_i_said_so',
        ])->assertStatus(422);

        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->refresh()->workflow_status);
    }

    public function test_other_cannot_stand_alone(): void
    {
        $ticket = $this->pendingRequest();

        // "Other" with nothing written in it records nothing at all — the one code that needs the note.
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/reject", [
            'rejection_code' => Maintenance::REVIEW_REJECT_OTHER,
        ])->assertStatus(422);

        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->refresh()->workflow_status);

        // With the note, it goes through.
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/reject", [
            'rejection_code'   => Maintenance::REVIEW_REJECT_OTHER,
            'rejection_reason' => 'Owner is selling the car this week.',
        ])->assertSuccessful();

        $this->assertSame(Maintenance::WF_REVIEW_REJECTED, $ticket->refresh()->workflow_status);
    }

    public function test_a_rejection_with_neither_code_nor_note_is_refused(): void
    {
        $ticket = $this->pendingRequest();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/reject", [])->assertStatus(422);
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->refresh()->workflow_status);
    }

    public function test_a_legacy_text_only_rejection_still_works(): void
    {
        // Every caller written before the code existed sends text and no code. They must keep working,
        // and must record an honest "code not captured" rather than a guessed one.
        $ticket = $this->pendingRequest();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/reject", [
            'rejection_reason' => 'Not needed, spoke to the driver.',
        ])->assertSuccessful();

        $ticket->refresh();
        $this->assertSame(Maintenance::WF_REVIEW_REJECTED, $ticket->workflow_status);
        $this->assertNull($ticket->review_rejection_code);
        $this->assertSame('Not needed, spoke to the driver.', $ticket->review_rejection_reason);
    }

    // ── "Remind me later" ─────────────────────────────────────────────────────────────────────────

    public function test_a_preset_reminder_is_scheduled_for_the_caller(): void
    {
        $ticket = $this->pendingRequest();

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/remind", ['preset' => '2h']);
        $res->assertSuccessful();

        $reminder = ReviewReminder::where('maintenance_id', $ticket->id)->firstOrFail();
        $this->assertSame($this->admin->id, $reminder->user_id);
        $this->assertSame(ReviewReminder::KIND_PENDING_REVIEW, $reminder->kind);
        $this->assertSame(ReviewReminder::STATUS_PENDING, $reminder->status);
        // ~2 hours out, with a minute of slack for the round-trip.
        $this->assertEqualsWithDelta(120, Carbon::now()->diffInMinutes($reminder->remind_at, false), 1.5);

        // The request itself is untouched: a reminder decides nothing.
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->refresh()->workflow_status);
    }

    public function test_tomorrow_morning_means_eight_am_not_twenty_four_hours(): void
    {
        $ticket = $this->pendingRequest();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/remind", ['preset' => 'tomorrow_morning'])
            ->assertSuccessful();

        $reminder = ReviewReminder::where('maintenance_id', $ticket->id)->firstOrFail();
        $this->assertSame(8, $reminder->remind_at->hour);
        $this->assertSame(0, $reminder->remind_at->minute);
        $this->assertSame(Carbon::now()->addDay()->toDateString(), $reminder->remind_at->toDateString());
    }

    public function test_asking_again_moves_the_reminder_instead_of_stacking_a_second_one(): void
    {
        $ticket = $this->pendingRequest();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/remind", ['preset' => '1h'])->assertSuccessful();
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/remind", ['preset' => '4h'])->assertSuccessful();

        $live = ReviewReminder::where('maintenance_id', $ticket->id)->pending()->get();
        $this->assertCount(1, $live, 'a second ask must replace the first, not add to it');
        $this->assertEqualsWithDelta(240, Carbon::now()->diffInMinutes($live->first()->remind_at, false), 1.5);

        // The superseded one is cancelled, not deleted — "they kept pushing this back" stays readable.
        $this->assertSame(1, ReviewReminder::where('maintenance_id', $ticket->id)
            ->where('status', ReviewReminder::STATUS_CANCELLED)->count());
    }

    public function test_a_reminder_in_the_past_is_refused(): void
    {
        $ticket = $this->pendingRequest();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/remind", [
            'remind_at' => Carbon::now()->subHours(3)->toIso8601String(),
        ])->assertStatus(422);

        $this->assertSame(0, ReviewReminder::where('maintenance_id', $ticket->id)->count());
    }

    public function test_the_queue_shows_only_your_own_reminder(): void
    {
        $ticket = $this->pendingRequest();
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/remind", ['preset' => '2h'])->assertSuccessful();

        // Somebody else's reminder on the same request must not appear as yours.
        $other = User::create([
            'name' => 'Other Controller', 'email' => 'other.' . uniqid() . '@fleet.test',
            'password' => bcrypt('password'), 'status' => 'active',
        ]);
        ReviewReminder::create([
            'maintenance_id' => $ticket->id,
            'user_id'        => $other->id,
            'kind'           => ReviewReminder::KIND_PENDING_REVIEW,
            'remind_at'      => Carbon::now()->addMinutes(15),
            'status'         => ReviewReminder::STATUS_PENDING,
        ]);

        $res = $this->getJson('/api/maintenance-tickets/pending-review');
        $res->assertSuccessful();

        $card = collect($res->json('data'))->firstWhere('id', $ticket->id);
        $this->assertNotNull($card, 'the request should still be in the queue');
        $this->assertNotNull($card['my_reminder']);
        $this->assertEqualsWithDelta(
            120,
            Carbon::now()->diffInMinutes(Carbon::parse($card['my_reminder']['remind_at']), false),
            1.5,
            'the card must show the caller’s own reminder, not the other reviewer’s 15-minute one',
        );
    }

    public function test_a_request_with_no_reminder_reports_that_explicitly(): void
    {
        $ticket = $this->pendingRequest();

        $res = $this->getJson('/api/maintenance-tickets/pending-review');
        $card = collect($res->json('data'))->firstWhere('id', $ticket->id);

        // Present and null — "we looked, you have none" — never a missing key.
        $this->assertArrayHasKey('my_reminder', $card);
        $this->assertNull($card['my_reminder']);
    }

    public function test_you_can_cancel_your_own_reminder(): void
    {
        $ticket = $this->pendingRequest();
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/remind", ['preset' => '2h'])->assertSuccessful();

        $this->deleteJson("/api/maintenance-tickets/{$ticket->id}/review/remind")->assertSuccessful();

        $this->assertSame(0, ReviewReminder::where('maintenance_id', $ticket->id)->pending()->count());
    }

    public function test_a_reminder_cannot_be_set_on_a_request_already_decided(): void
    {
        $ticket = $this->pendingRequest();
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/approve", [])->assertSuccessful();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/remind", ['preset' => '2h'])
            ->assertStatus(422);
    }

    // ── Where the two meet ────────────────────────────────────────────────────────────────────────

    public function test_approving_cancels_the_reminders_waiting_on_the_request(): void
    {
        $ticket = $this->pendingRequest();
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/remind", ['preset' => '2h'])->assertSuccessful();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/approve", [])->assertSuccessful();

        $reminder = ReviewReminder::where('maintenance_id', $ticket->id)->firstOrFail();
        $this->assertSame(ReviewReminder::STATUS_CANCELLED, $reminder->status);
        $this->assertSame(ReviewReminder::CANCELLED_REVIEWED, $reminder->cancelled_reason);
    }

    public function test_rejecting_cancels_the_waiting_reminder_but_keeps_the_revisit_one(): void
    {
        $ticket = $this->pendingRequest();
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/remind", ['preset' => '2h'])->assertSuccessful();

        $revisitAt = Carbon::now()->addDays(3)->setTime(9, 0);
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/reject", [
            'rejection_code' => Maintenance::REVIEW_REJECT_RECENTLY_DONE,
            'remind_at'      => $revisitAt->toIso8601String(),
        ])->assertSuccessful();

        // The "look at this again before deciding" reminder is dead — the decision was taken.
        $waiting = ReviewReminder::where('maintenance_id', $ticket->id)
            ->where('kind', ReviewReminder::KIND_PENDING_REVIEW)->firstOrFail();
        $this->assertSame(ReviewReminder::STATUS_CANCELLED, $waiting->status);
        $this->assertSame(ReviewReminder::CANCELLED_REVIEWED, $waiting->cancelled_reason);

        // The revisit reminder OUTLIVES the rejection that created it — that is the whole point of it.
        $revisit = ReviewReminder::where('maintenance_id', $ticket->id)
            ->where('kind', ReviewReminder::KIND_REJECTED_REVISIT)->firstOrFail();
        $this->assertSame(ReviewReminder::STATUS_PENDING, $revisit->status);
        $this->assertSame($revisitAt->toDateTimeString(), $revisit->remind_at->toDateTimeString());
    }

    public function test_a_rejection_reminder_in_the_past_fails_the_whole_rejection(): void
    {
        $ticket = $this->pendingRequest();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/reject", [
            'rejection_code' => Maintenance::REVIEW_REJECT_NOT_NEEDED,
            'remind_at'      => Carbon::now()->subDay()->toIso8601String(),
        ])->assertStatus(422);

        // Nothing half-applied: the request is still awaiting review.
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->refresh()->workflow_status);
    }

    // ── The dispatcher ────────────────────────────────────────────────────────────────────────────

    public function test_the_dispatcher_fires_a_due_reminder_once(): void
    {
        $ticket = $this->pendingRequest();
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/remind", [
            'preset' => '30m',
            'note'   => 'Check whether the customer brought it back.',
        ])->assertSuccessful();

        $reminder = ReviewReminder::where('maintenance_id', $ticket->id)->firstOrFail();

        // Not due yet — nothing goes out.
        Artisan::call('review-reminders:dispatch');
        $this->assertSame(ReviewReminder::STATUS_PENDING, $reminder->refresh()->status);

        // The moment arrives.
        $reminder->forceFill(['remind_at' => Carbon::now()->subMinute()])->save();
        Artisan::call('review-reminders:dispatch');

        $this->assertSame(ReviewReminder::STATUS_SENT, $reminder->refresh()->status);
        $this->assertNotNull($reminder->sent_at);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->admin->id]);

        // Running again cannot re-push it: a sent row has left the working set.
        Artisan::call('review-reminders:dispatch');
        $this->assertSame(1, ReviewReminder::where('maintenance_id', $ticket->id)
            ->where('status', ReviewReminder::STATUS_SENT)->count());
    }

    public function test_the_dispatcher_never_pings_about_a_request_already_reviewed(): void
    {
        $ticket = $this->pendingRequest();
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/remind", ['preset' => '30m'])->assertSuccessful();

        $reminder = ReviewReminder::where('maintenance_id', $ticket->id)->firstOrFail();
        $reminder->forceFill(['remind_at' => Carbon::now()->subMinute()])->save();

        // Approve WITHOUT going through the endpoint's cancellation, so the dispatcher's own safety net
        // is what is under test here — this is the branch that catches a decision taken any other way.
        $ticket->forceFill(['workflow_status' => Maintenance::WF_INSPECTION_REQUESTED])->save();
        ReviewReminder::where('id', $reminder->id)->update(['status' => ReviewReminder::STATUS_PENDING]);

        Artisan::call('review-reminders:dispatch');

        $reminder->refresh();
        $this->assertSame(ReviewReminder::STATUS_CANCELLED, $reminder->status);
        $this->assertSame(ReviewReminder::CANCELLED_REVIEWED, $reminder->cancelled_reason);
        $this->assertNull($reminder->sent_at);
    }

    public function test_a_revisit_reminder_still_fires_after_the_rejection(): void
    {
        $ticket = $this->pendingRequest();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/reject", [
            'rejection_code' => Maintenance::REVIEW_REJECT_CAR_UNAVAILABLE,
            'remind_at'      => Carbon::now()->addHours(2)->toIso8601String(),
        ])->assertSuccessful();

        $revisit = ReviewReminder::where('maintenance_id', $ticket->id)
            ->where('kind', ReviewReminder::KIND_REJECTED_REVISIT)->firstOrFail();
        $revisit->forceFill(['remind_at' => Carbon::now()->subMinute()])->save();

        Artisan::call('review-reminders:dispatch');

        // The request is rejected, and this one is SUPPOSED to arrive anyway.
        $this->assertSame(ReviewReminder::STATUS_SENT, $revisit->refresh()->status);
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $ticket = $this->pendingRequest();
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/remind", ['preset' => '30m'])->assertSuccessful();

        $reminder = ReviewReminder::where('maintenance_id', $ticket->id)->firstOrFail();
        $reminder->forceFill(['remind_at' => Carbon::now()->subMinute()])->save();

        Artisan::call('review-reminders:dispatch', ['--dry-run' => true]);

        $this->assertSame(ReviewReminder::STATUS_PENDING, $reminder->refresh()->status);
        $this->assertNull($reminder->sent_at);
    }
}
