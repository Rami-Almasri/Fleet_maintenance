<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceCheckpoint;
use App\Models\MaintenanceCheckpointReminder;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end acceptance of the DAILY supervisor chase — driven through the real HTTP API and the real
 * scheduled command, as a real supervisor and a real admin would, with the clock moved day by day.
 *
 * The story under test, in the operator's words: a car is promised back on the 20th. From the 19th the
 * supervisor is asked EVERY DAY whether that is still true. Saying "yes" settles that day and no more.
 * Saying "no, the 23rd, waiting on parts" moves the whole question to the 23rd — silence on the 20th and
 * 21st, and the chase reopens on the 22nd. Everything the supervisor ever answered is kept, and every
 * reminder that went out unanswered is visible to an admin.
 *
 * See [[maintenance-checkpoint-feature]].
 */
class MaintenanceCheckpointChaseTest extends TestCase
{
    // NOTE: deliberately NOT RefreshDatabase (which CrudTestCase uses). Its migrate:fresh currently dies
    // on this project's known "migrations cannot run from empty" defect, so this suite runs against the
    // already-migrated `laravel_test` schema and rolls each test back instead. See the acceptance report.
    use DatabaseTransactions;

    private User $admin;
    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('maintenance.checkpoint.reminder_lead_days', 1);
        config()->set('maintenance.checkpoint.unanswered_alert_days', 1);

        $this->admin = User::create([
            'name'     => 'Test Super Admin',
            'email'    => 'super.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);
        $this->admin->assignRole('super-admin');
        Sanctum::actingAs($this->admin, ['*']);

        $this->supervisor = User::create([
            'name'     => 'Waleed Supervisor',
            'email'    => 'waleed.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);
        $this->supervisor->givePermissionTo(['maintenance.view', 'maintenance.checkpoint.create']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────────────────────────

    private function idOf(TestResponse $res): int
    {
        return (int) data_get($res->json(), 'data.id');
    }

    private function makeVehicle(): int
    {
        $res = $this->postJson('/api/Vehicle', [
            'vin'      => 'VIN' . strtoupper(uniqid()),
            'plate_no' => 'T-' . random_int(10000, 99999),
            'make'     => 'Toyota', 'model' => 'Camry', 'year' => 2022, 'odometer' => 40000,
        ])->assertSuccessful();

        return $this->idOf($res);
    }

    private function makeVendor(string $name): int
    {
        $res = $this->postJson('/api/Vendor', ['name' => $name . ' ' . uniqid(), 'type' => 'garage'])
            ->assertSuccessful();

        return $this->idOf($res);
    }

    /** A car in the workshop, promised back on $due, with our supervisor as its responsible owner. */
    private function ticketDueOn(string $due): Maintenance
    {
        $vehicleId = $this->makeVehicle();
        Vehicle::find($vehicleId)->update(['operational_status' => 'maintenance']);

        $ticket = Maintenance::create([
            'vehicle_id'      => $vehicleId,
            'vendor_id'       => $this->makeVendor('Sticar Workshop'),
            'origin'          => Maintenance::ORIGIN_MANUAL,
            'workflow_status' => Maintenance::WF_UNDER_REPAIR,
            'event_status'    => 'OUT',
            'out_date'        => '2026-08-10',
            'findings'        => [['text' => 'AC not cooling', 'source' => 'inspector']],
        ]);

        // The promise, set through the real manager endpoint.
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/expected-completion", [
            'expected_completion_date' => $due,
        ])->assertSuccessful();

        // Pin the chase to ONE supervisor so the assertions are deterministic.
        $this->putJson("/api/maintenance-tickets/{$ticket->id}/responsibles", [
            'user_ids' => [$this->supervisor->id],
        ])->assertSuccessful()
          ->assertJsonPath('data.assigned', [$this->supervisor->id]);

        return $ticket->fresh();
    }

    /** The same car, but with NOBODY assigned — so the fallback rules decide who (if anyone) hears about it. */
    private function unassignedTicketDueOn(string $due): Maintenance
    {
        $vehicleId = $this->makeVehicle();
        Vehicle::find($vehicleId)->update(['operational_status' => 'maintenance']);

        $ticket = Maintenance::create([
            'vehicle_id'      => $vehicleId,
            'vendor_id'       => $this->makeVendor('Orphan Workshop'),
            'origin'          => Maintenance::ORIGIN_MANUAL,
            'workflow_status' => Maintenance::WF_UNDER_REPAIR,
            'event_status'    => 'OUT',
            'out_date'        => '2026-08-10',
        ]);
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/expected-completion", [
            'expected_completion_date' => $due,
        ])->assertSuccessful();

        return $ticket->fresh();
    }

    // ── recipient safety ────────────────────────────────────────────────────────────────────────

    public function test_the_fallback_picks_operational_supervisors_and_never_admin_or_qa_accounts(): void
    {
        // The live fleet's `maintenance.delegate` holders include two super-admins and two QA logins.
        // Reproduce that shape and assert only the real supervisor is auto-selected.
        $realSupervisor = User::create(['name' => 'Abdullah Asham', 'email' => 'ab.' . uniqid() . '@fleet.test',
            'password' => Hash::make('x'), 'status' => 'active']);
        $realSupervisor->assignRole('supervisor');

        $qaMaintenance = User::create(['name' => 'QA Maintenance', 'email' => 'qam.' . uniqid() . '@fleet.test',
            'password' => Hash::make('x'), 'status' => 'active']);
        $qaMaintenance->assignRole('maintenance');

        $qaAdmin = User::create(['name' => 'QA Admin', 'email' => 'qaa.' . uniqid() . '@fleet.test',
            'password' => Hash::make('x'), 'status' => 'active']);
        $qaAdmin->assignRole('admin');

        $defaults = app(\App\Services\MaintenanceCheckpointService::class)->defaultRecipients();
        $ids = $defaults->pluck('id')->all();

        $this->assertContains($realSupervisor->id, $ids, 'the supervisor owns workshop follow-up');
        $this->assertNotContains($qaAdmin->id, $ids, 'admin accounts must never be auto-chased');
        $this->assertNotContains($this->admin->id, $ids, 'nor super-admins');
        $this->assertNotContains($qaMaintenance->id, $ids, 'nor non-supervisor roles that merely hold the permission');
        $this->assertNotContains($this->supervisor->id, $ids, 'nor a bare permission grant with no operational role');
    }

    public function test_an_explicit_allow_list_wins_outright_over_any_role_matching(): void
    {
        $chosen = User::create(['name' => 'Named Owner', 'email' => 'named.' . uniqid() . '@fleet.test',
            'password' => Hash::make('x'), 'status' => 'active']);
        $roleHolder = User::create(['name' => 'Some Supervisor', 'email' => 'sup.' . uniqid() . '@fleet.test',
            'password' => Hash::make('x'), 'status' => 'active']);
        $roleHolder->assignRole('supervisor');

        config()->set('maintenance.checkpoint.default_user_ids', [$chosen->id]);

        $ids = app(\App\Services\MaintenanceCheckpointService::class)->defaultRecipients()->pluck('id')->all();
        $this->assertSame([$chosen->id], $ids);
    }

    public function test_a_car_nobody_owns_is_reported_to_the_admin_instead_of_broadcast(): void
    {
        // No assigned responsible, and no supervisor-role user exists to fall back to.
        $ticket = $this->unassignedTicketDueOn('2026-08-20');

        $this->scanOn('2026-08-19');

        $this->assertCount(0, $this->remindersFor($ticket), 'nothing may be broadcast to a broad permission');
        $this->assertSame(0, DB::table('notifications')->count(), 'and nobody is nagged');

        // But the gap is visible: the car appears as unassigned on the compliance board.
        $this->travelTo('2026-08-19 10:00');
        $board = $this->getJson('/api/Oversight/checkpoint-compliance')->assertSuccessful()->json('data');

        $row = collect($board['unassigned'])->firstWhere('ticket_id', $ticket->id);
        $this->assertNotNull($row, 'a car with no follow-up owner must be reported, not silently dropped');
        $this->assertSame('request', $row['escalation']);
        $this->assertSame('2026-08-20', $row['expected_on']);
        $this->assertGreaterThanOrEqual(1, $board['summary']['unassigned']);
    }

    public function test_assigning_an_owner_clears_the_unassigned_finding(): void
    {
        $ticket = $this->unassignedTicketDueOn('2026-08-20');
        $this->travelTo('2026-08-19 09:00');

        $svc = app(\App\Services\MaintenanceCheckpointService::class);
        $this->assertCount(1, collect($svc->unassignedTickets())->where('ticket_id', $ticket->id));

        $this->putJson("/api/maintenance-tickets/{$ticket->id}/responsibles", [
            'user_ids' => [$this->supervisor->id],
        ])->assertSuccessful();

        $this->assertCount(0, collect($svc->unassignedTickets())->where('ticket_id', $ticket->id));

        // And now the chase actually reaches them.
        $this->scanOn('2026-08-19');
        $this->assertCount(1, $this->remindersFor($ticket));
        $this->assertSame($this->supervisor->id, (int) $this->remindersFor($ticket)->first()->user_id);
    }

    // ── the three promised dates ────────────────────────────────────────────────────────────────

    public function test_the_board_publishes_the_original_current_and_chased_about_dates_separately(): void
    {
        $ticket = $this->ticketDueOn('2026-08-20');

        // Promised the 20th, moved to the 23rd on the 19th.
        $this->travelTo('2026-08-19 14:00');
        $this->answerAs($this->supervisor, $ticket, [
            'next_expected_date' => '2026-08-23', 'delay_reason' => 'waiting_parts',
        ])->assertSuccessful();

        // Chased again on the 22nd about the NEW date, and left unanswered.
        $this->scanOn('2026-08-22');

        $this->travelTo('2026-08-22 10:00');
        $row = collect($this->getJson('/api/Oversight/checkpoint-compliance')->json('data.rows'))
            ->firstWhere('ticket_id', $ticket->id);

        $this->assertSame('2026-08-20', $row['original_promised_on'], 'what was promised first');
        $this->assertSame('2026-08-23', $row['current_promised_on'], 'what is promised now');
        $this->assertSame('2026-08-23', $row['reminded_about_on'], 'what the supervisor was told');
        $this->assertSame('2026-08-23', $row['last_rescheduled_to']);
        $this->assertNotNull($row['last_rescheduled_at']);
    }

    /** The assignment must actually redirect the chase — otherwise every reminder goes to the wrong desk. */
    public function test_assigning_a_responsible_supervisor_redirects_the_chase_to_them(): void
    {
        $ticket = $this->ticketDueOn('2026-08-20');

        $recipients = app(\App\Services\MaintenanceCheckpointService::class)->recipientsFor($ticket);

        $this->assertSame([$this->supervisor->id], $recipients->pluck('id')->all(),
            'an explicitly assigned owner must be THE recipient, not the permission-holder fallback');
    }

    /** Move the clock to 09:00 on $day and run the scheduled chase, as cron would. */
    private function scanOn(string $day): void
    {
        $this->travelTo($day . ' 09:00');
        $this->artisan('checkpoints:scan')->assertSuccessful();
    }

    /** The live monitor state the dashboard and the modal both read. */
    private function monitorOn(string $day, Maintenance $ticket): array
    {
        $this->travelTo($day . ' 09:30');
        $res = $this->getJson("/api/maintenance-tickets/{$ticket->id}/checkpoints")->assertSuccessful();

        return $res->json('data.monitor');
    }

    /** File an answer as the supervisor, through the real endpoint they use. */
    private function answerAs(User $user, Maintenance $ticket, array $payload)
    {
        Sanctum::actingAs($user, ['*']);
        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/checkpoints", $payload);
        Sanctum::actingAs($this->admin, ['*']);

        return $res;
    }

    private function remindersFor(Maintenance $ticket)
    {
        return MaintenanceCheckpointReminder::where('maintenance_id', $ticket->id)->get();
    }

    /** The supervisor's checkpoint notifications actually delivered to their bell. */
    private function alertsFor(User $user): array
    {
        return DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->get()
            ->map(fn ($n) => json_decode($n->data, true))
            ->filter(fn ($d) => ($d['type'] ?? null) === 'maint_checkpoint')
            ->values()->all();
    }

    // ── 1 + 2: the reminder lifecycle ───────────────────────────────────────────────────────────

    public function test_the_chase_is_silent_until_one_day_before_the_promised_date(): void
    {
        $ticket = $this->ticketDueOn('2026-08-20');

        $this->assertNull($this->monitorOn('2026-08-17', $ticket)['escalation']);
        $this->assertNull($this->monitorOn('2026-08-18', $ticket)['escalation']);

        $this->scanOn('2026-08-18');
        $this->assertCount(0, $this->remindersFor($ticket), 'nothing may be pushed before the window opens');
        $this->assertCount(0, $this->alertsFor($this->supervisor));
    }

    public function test_the_reminder_fires_every_day_from_the_19th_and_keeps_going_once_overdue(): void
    {
        $ticket = $this->ticketDueOn('2026-08-20');

        // 19/08 — the window opens: the supervisor is asked for the first time.
        $this->scanOn('2026-08-19');
        $this->assertSame('request', $this->monitorOn('2026-08-19', $ticket)['escalation']);
        $day19 = $this->remindersFor($ticket);
        $this->assertCount(1, $day19);
        $this->assertSame($this->supervisor->id, (int) $day19->first()->user_id);
        $this->assertSame('request', $day19->first()->level);
        $this->assertSame('2026-08-20', $day19->first()->expected_on->toDateString());
        $this->assertNull($day19->first()->responded_at, 'unanswered on arrival');

        // 20/08 — still no answer, so the system asks again on the promised day itself.
        $this->scanOn('2026-08-20');
        $this->assertSame('due_today', $this->monitorOn('2026-08-20', $ticket)['escalation']);
        $this->assertCount(2, $this->remindersFor($ticket));

        // 21/08 and 22/08 — past the promise, and it keeps asking, once per day.
        $this->scanOn('2026-08-21');
        $this->assertSame('overdue', $this->monitorOn('2026-08-21', $ticket)['escalation']);
        $this->scanOn('2026-08-22');
        $this->assertSame('overdue', $this->monitorOn('2026-08-22', $ticket)['escalation']);

        $all = $this->remindersFor($ticket);
        $this->assertCount(4, $all, 'one reminder per day, four days running');
        $this->assertSame(
            ['2026-08-19', '2026-08-20', '2026-08-21', '2026-08-22'],
            $all->sortBy('sent_on')->map(fn ($r) => $r->sent_on->toDateString())->values()->all()
        );
        $this->assertSame(
            ['request', 'due_today', 'overdue', 'overdue'],
            $all->sortBy('sent_on')->pluck('level')->all()
        );

        // And the supervisor really did get four bell alerts, not four silent DB rows.
        $this->assertCount(4, $this->alertsFor($this->supervisor));
    }

    // ── 3A: the confirm case ────────────────────────────────────────────────────────────────────

    public function test_confirming_the_date_is_recorded_closes_todays_reminder_and_the_question_returns_tomorrow(): void
    {
        $ticket = $this->ticketDueOn('2026-08-20');
        $this->scanOn('2026-08-19');

        // The supervisor answers "yes, still the 20th" — the form sends the unchanged date, no reason.
        $this->travelTo('2026-08-19 14:00');
        $res = $this->answerAs($this->supervisor, $ticket, [
            'next_expected_date' => '2026-08-20',
            'status'             => 'under_repair',
            'summary'            => 'Compressor refitted, road test tomorrow morning.',
        ])->assertSuccessful();

        $this->assertSame('confirmed', $res->json('data.checkpoint.response'));
        $this->assertNull($res->json('data.checkpoint.delay_reason'), 'a confirmation carries no delay reason');
        $this->assertSame('2026-08-20', $res->json('data.checkpoint.next_expected_date'));

        $stored = MaintenanceCheckpoint::where('maintenance_id', $ticket->id)->sole();
        $this->assertSame(MaintenanceCheckpoint::RESPONSE_CONFIRMED, $stored->response);
        $this->assertSame($this->supervisor->id, (int) $stored->submitted_by);

        // Today's reminder is closed and points at the answer that closed it.
        $reminder = $this->remindersFor($ticket)->sole();
        $this->assertNotNull($reminder->responded_at, "today's reminder must close");
        $this->assertSame($stored->id, (int) $reminder->responded_checkpoint_id);

        // The rest of today is quiet — even if cron runs again.
        $this->assertNull($this->monitorOn('2026-08-19', $ticket)['escalation']);
        $this->assertTrue($this->monitorOn('2026-08-19', $ticket)['answered_today']);
        $this->scanOn('2026-08-19');
        $this->assertCount(1, $this->remindersFor($ticket), 'no second reminder the same day');

        // Tomorrow the car is still open, so the question is asked again.
        $this->scanOn('2026-08-20');
        $this->assertSame('due_today', $this->monitorOn('2026-08-20', $ticket)['escalation']);
        $this->assertCount(2, $this->remindersFor($ticket));
        $this->assertCount(1, $this->remindersFor($ticket)->whereNull('responded_at'));
    }

    // ── 3B: the reschedule case ─────────────────────────────────────────────────────────────────

    public function test_rescheduling_stores_both_dates_and_the_reason_and_moves_the_whole_window(): void
    {
        $ticket = $this->ticketDueOn('2026-08-20');
        $this->scanOn('2026-08-19');

        // "No — it moves to the 23rd, we're waiting on parts."
        $this->travelTo('2026-08-19 14:00');
        $res = $this->answerAs($this->supervisor, $ticket, [
            'next_expected_date' => '2026-08-23',
            'delay_reason'       => 'waiting_parts',
            'status'             => 'waiting_parts',
            'summary'            => 'Compressor on order from Dubai, ETA Saturday.',
        ])->assertSuccessful();

        $this->assertSame('rescheduled', $res->json('data.checkpoint.response'));
        $this->assertSame('waiting_parts', $res->json('data.checkpoint.delay_reason'));
        $this->assertSame('2026-08-20', $res->json('data.checkpoint.previous_expected_date'), 'the promise it revised');
        $this->assertSame('2026-08-23', $res->json('data.checkpoint.next_expected_date'), 'the new promise');

        // The ticket's promise really moved — the new date is now the source of truth.
        $this->assertSame('2026-08-23', $ticket->fresh()->expected_completion_date->toDateString());

        // 20/08 and 21/08 — no nagging about a date the supervisor already moved and explained.
        $this->scanOn('2026-08-20');
        $this->assertNull($this->monitorOn('2026-08-20', $ticket)['escalation']);
        $this->scanOn('2026-08-21');
        $this->assertNull($this->monitorOn('2026-08-21', $ticket)['escalation']);
        $this->assertCount(1, $this->remindersFor($ticket), 'the window moved — nothing new went out');

        // 22/08 — one day before the NEW date, the chase reopens.
        $this->scanOn('2026-08-22');
        $this->assertSame('request', $this->monitorOn('2026-08-22', $ticket)['escalation']);
        $this->assertCount(2, $this->remindersFor($ticket));
        $this->assertSame('2026-08-23', $this->remindersFor($ticket)->sortBy('sent_on')->last()->expected_on->toDateString());

        // 23/08 — the new promised day.
        $this->scanOn('2026-08-23');
        $this->assertSame('due_today', $this->monitorOn('2026-08-23', $ticket)['escalation']);
    }

    public function test_a_confirmation_can_never_carry_a_delay_reason_whatever_the_caller_sends(): void
    {
        // The controller strips it, but the invariant belongs to the service: "confirmed, because we are
        // waiting on parts" is a row neither the timeline nor the compliance board can render honestly.
        $ticket = $this->ticketDueOn('2026-08-20');
        $this->travelTo('2026-08-19 14:00');

        $checkpoint = app(\App\Services\MaintenanceCheckpointService::class)->submit(
            $ticket, $this->supervisor,
            ['next_expected_date' => '2026-08-20', 'delay_reason' => 'waiting_parts', 'delay_reason_other' => 'x']
        );

        $this->assertSame(MaintenanceCheckpoint::RESPONSE_CONFIRMED, $checkpoint->response);
        $this->assertNull($checkpoint->delay_reason);
        $this->assertNull($checkpoint->delay_reason_other);
    }

    public function test_a_reschedule_without_a_reason_is_rejected(): void
    {
        $ticket = $this->ticketDueOn('2026-08-20');
        $this->travelTo('2026-08-19 14:00');

        $this->answerAs($this->supervisor, $ticket, ['next_expected_date' => '2026-08-23'])
            ->assertStatus(422);

        $this->assertSame(0, MaintenanceCheckpoint::where('maintenance_id', $ticket->id)->count());
        $this->assertSame('2026-08-20', $ticket->fresh()->expected_completion_date->toDateString(), 'the promise must not move');
    }

    public function test_every_answer_is_kept_with_its_own_reason(): void
    {
        $ticket = $this->ticketDueOn('2026-08-20');

        $this->travelTo('2026-08-19 14:00');
        $this->answerAs($this->supervisor, $ticket, [
            'next_expected_date' => '2026-08-23', 'delay_reason' => 'waiting_parts',
        ])->assertSuccessful();

        $this->travelTo('2026-08-22 10:00');
        $this->answerAs($this->supervisor, $ticket, ['next_expected_date' => '2026-08-23'])->assertSuccessful();

        $this->travelTo('2026-08-23 10:00');
        $this->answerAs($this->supervisor, $ticket, [
            'next_expected_date' => '2026-08-26', 'delay_reason' => 'additional_damage',
        ])->assertSuccessful();

        $history = MaintenanceCheckpoint::where('maintenance_id', $ticket->id)
            ->orderBy('created_at')->get();

        $this->assertCount(3, $history, 'nothing is overwritten');
        $this->assertSame(['rescheduled', 'confirmed', 'rescheduled'], $history->pluck('response')->all());
        $this->assertSame(['waiting_parts', null, 'additional_damage'], $history->pluck('delay_reason')->all());
        $this->assertSame(
            [['2026-08-20', '2026-08-23'], ['2026-08-23', '2026-08-23'], ['2026-08-23', '2026-08-26']],
            $history->map(fn ($c) => [
                $c->previous_expected_date->toDateString(), $c->next_expected_date->toDateString(),
            ])->all()
        );
    }

    // ── 5: duplicate prevention ─────────────────────────────────────────────────────────────────

    public function test_running_the_scan_repeatedly_in_one_day_never_duplicates_the_reminder(): void
    {
        $ticket = $this->ticketDueOn('2026-08-20');

        $this->travelTo('2026-08-19 08:00');
        $this->artisan('checkpoints:scan')->assertSuccessful();
        $this->travelTo('2026-08-19 12:00');
        $this->artisan('checkpoints:scan')->assertSuccessful();
        $this->travelTo('2026-08-19 20:00');
        $this->artisan('checkpoints:scan')->assertSuccessful();

        $this->assertCount(1, $this->remindersFor($ticket), 'three runs, one receipt');
        $this->assertCount(1, $this->alertsFor($this->supervisor), 'three runs, ONE bell alert — no spam');
    }

    public function test_a_louder_ask_later_the_same_day_still_reaches_the_supervisor(): void
    {
        // A car that crosses from "due today" into nothing new is quiet; but a car whose level genuinely
        // changes during the day must re-push, or the escalation would be swallowed by the dedup.
        $ticket = $this->ticketDueOn('2026-08-20');

        $this->travelTo('2026-08-19 08:00');
        $this->artisan('checkpoints:scan')->assertSuccessful();
        $this->assertSame('request', $this->remindersFor($ticket)->sole()->level);
        $this->assertCount(1, $this->alertsFor($this->supervisor));

        // The manager pulls the promise in to today — the ask is now materially louder.
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/expected-completion", [
            'expected_completion_date' => '2026-08-19',
        ])->assertSuccessful();

        $this->travelTo('2026-08-19 20:00');
        $this->artisan('checkpoints:scan')->assertSuccessful();

        $this->assertCount(1, $this->remindersFor($ticket), 'still one receipt for the day');
        $this->assertSame('due_today', $this->remindersFor($ticket)->sole()->level, 'upgraded in place');
        $this->assertCount(2, $this->alertsFor($this->supervisor), 'the louder ask must actually be delivered');
    }

    // ── 6: the admin compliance board ───────────────────────────────────────────────────────────

    public function test_the_compliance_board_reports_unanswered_reminders_and_drops_them_when_answered(): void
    {
        $ticket = $this->ticketDueOn('2026-08-20');

        // Two days of silence after one reschedule, so the row has a history to show.
        $this->travelTo('2026-08-19 14:00');
        $this->answerAs($this->supervisor, $ticket, [
            'next_expected_date' => '2026-08-23', 'delay_reason' => 'waiting_parts',
        ])->assertSuccessful();

        $this->scanOn('2026-08-22');
        $this->scanOn('2026-08-23');

        $this->travelTo('2026-08-23 10:00');
        $board = $this->getJson('/api/Oversight/checkpoint-compliance')->assertSuccessful()->json('data');

        $row = collect($board['rows'])->firstWhere('ticket_id', $ticket->id);
        $this->assertNotNull($row, 'a silent supervisor must appear on the board');
        $this->assertSame(['Waleed Supervisor'], $row['notified'], 'WHO was notified');
        $this->assertSame(2, $row['reminders_open']);
        $this->assertSame(2, $row['days_reminded']);
        $this->assertSame('2026-08-22', $row['first_reminder_on']);
        $this->assertSame(1, $row['days_unanswered'], 'silent since the 22nd, today is the 23rd');
        $this->assertTrue($row['breached'], 'past the tolerated one day');
        $this->assertSame('2026-08-23', $row['expected_on'], 'the date the supervisor was actually told');
        $this->assertSame('due_today', $row['last_level']);

        // The history behind the row: the earlier reschedule and its reason.
        $this->assertSame(1, $row['reschedule_count']);
        $this->assertSame(1, $row['checkpoint_count']);
        $this->assertSame('waiting_parts', $row['reasons'][0]['delay_reason']);
        $this->assertSame('2026-08-20', $row['reasons'][0]['previous_date']);
        $this->assertSame('2026-08-23', $row['reasons'][0]['next_date']);
        $this->assertSame('Waleed Supervisor', $row['reasons'][0]['by']);
        $this->assertGreaterThanOrEqual(1, $board['summary']['breached']);

        // The admin roll-up card agrees with the board.
        $overview = $this->getJson('/api/Oversight/overview')->assertSuccessful()->json('data');
        $this->assertGreaterThanOrEqual(1, $overview['checkpoint_open']);
        $this->assertGreaterThanOrEqual(1, $overview['checkpoint_silent']);

        // The supervisor answers → the row leaves the open list.
        $this->travelTo('2026-08-23 11:00');
        $this->answerAs($this->supervisor, $ticket, ['next_expected_date' => '2026-08-23'])->assertSuccessful();

        $after = $this->getJson('/api/Oversight/checkpoint-compliance')->assertSuccessful()->json('data');
        $this->assertNull(collect($after['rows'])->firstWhere('ticket_id', $ticket->id), 'answered → off the board');
        // Both of this car's reminders are now closed and point at the answer that closed them.
        // (The summary's totals are fleet-wide, so they are deliberately not asserted to an exact number.)
        $closed = $this->remindersFor($ticket);
        $this->assertCount(2, $closed);
        $this->assertCount(0, $closed->whereNull('responded_at'));
        $this->assertGreaterThanOrEqual(2, $after['summary']['answered_total']);
    }

    // ── 7: permissions ──────────────────────────────────────────────────────────────────────────

    public function test_a_responsible_supervisor_may_answer_but_an_outsider_may_not(): void
    {
        $ticket = $this->ticketDueOn('2026-08-20');
        $this->travelTo('2026-08-19 14:00');

        // The assigned supervisor: allowed.
        $this->answerAs($this->supervisor, $ticket, ['next_expected_date' => '2026-08-20'])
            ->assertSuccessful();

        // A user with no maintenance rights at all: blocked at the route gate.
        $outsider = User::create([
            'name' => 'Outsider', 'email' => 'out.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'), 'status' => 'active',
        ]);
        $this->answerAs($outsider, $ticket, ['next_expected_date' => '2026-08-21', 'delay_reason' => 'vendor_delay'])
            ->assertStatus(403);

        // A user who may VIEW maintenance but is neither responsible nor a checkpoint author: blocked in
        // the controller, which is the gate the route middleware alone cannot express.
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'view.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'), 'status' => 'active',
        ]);
        $viewer->givePermissionTo('maintenance.view');
        $this->answerAs($viewer, $ticket, ['next_expected_date' => '2026-08-21', 'delay_reason' => 'vendor_delay'])
            ->assertStatus(403);

        $this->assertSame(1, MaintenanceCheckpoint::where('maintenance_id', $ticket->id)->count(),
            'only the supervisor’s answer was recorded');
    }

    public function test_only_a_manager_may_set_the_promise_or_the_responsible_owners(): void
    {
        $ticket = $this->ticketDueOn('2026-08-20');

        // The supervisor can answer, but may not re-write the promise or reassign the chase.
        Sanctum::actingAs($this->supervisor, ['*']);
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/expected-completion", [
            'expected_completion_date' => '2026-09-30',
        ])->assertStatus(403);
        $this->putJson("/api/maintenance-tickets/{$ticket->id}/responsibles", ['user_ids' => []])
            ->assertStatus(403);
        Sanctum::actingAs($this->admin, ['*']);

        $this->assertSame('2026-08-20', $ticket->fresh()->expected_completion_date->toDateString());
    }

    public function test_the_compliance_board_is_closed_to_users_without_insight_rights(): void
    {
        Sanctum::actingAs($this->supervisor, ['*']);
        $this->getJson('/api/Oversight/checkpoint-compliance')->assertStatus(403);
        Sanctum::actingAs($this->admin, ['*']);
    }
}
