<?php

namespace Tests\Unit;

use App\Models\Maintenance;
use App\Services\MaintenanceCheckpointService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The supervisor chase cadence — the rule that makes the reminder daily WITHOUT making it noise.
 *
 * Two halves that only work together:
 *   · coverage is per DAY — answering settles today, and tomorrow the question is asked again, because
 *     "is it still coming back on the 20th?" is a question that expires overnight;
 *   · the window is per PROMISE — pushing the date to the 23rd moves the whole window with it, so the
 *     chase goes quiet and reopens on the 22nd, one day before the new promise.
 *
 * Keep either half alone and the behaviour is wrong in an obvious way: per-window-only coverage asks once
 * and then goes silent for a week; per-day-only coverage nags every day of a date the supervisor already
 * pushed out and explained. Pure derivation over an in-memory ticket — no DB, frozen clock.
 *
 * See [[maintenance-checkpoint-feature]].
 */
class MaintenanceCheckpointCadenceTest extends TestCase
{
    private MaintenanceCheckpointService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MaintenanceCheckpointService();
        config()->set('maintenance.checkpoint.reminder_lead_days', 1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A ticket promised back on $expected, last answered on $lastAnswer (null = never). */
    private function ticket(string $expected, ?string $lastAnswer = null): Maintenance
    {
        $ticket = (new Maintenance())->forceFill([
            'id'                       => 1,
            'repair_started_at'        => Carbon::parse('2026-08-10'),
            'expected_completion_date' => Carbon::parse($expected),
            'last_checkpoint_at'       => $lastAnswer ? Carbon::parse($lastAnswer) : null,
        ]);
        // monitorState reads the newest checkpoint off the relation when it's loaded — keep it off the DB.
        $ticket->setRelation('checkpoints', collect());

        return $ticket;
    }

    private function escalationOn(string $day, Maintenance $ticket): ?string
    {
        Carbon::setTestNow(Carbon::parse($day . ' 09:00'));

        return $this->service->monitorState($ticket)['escalation'];
    }

    public function test_it_stays_silent_before_the_reminder_window_opens(): void
    {
        $ticket = $this->ticket('2026-08-20');

        $this->assertNull($this->escalationOn('2026-08-18', $ticket));
    }

    public function test_it_asks_every_day_from_one_day_before_until_the_car_is_out(): void
    {
        $ticket = $this->ticket('2026-08-20');

        $this->assertSame('request',   $this->escalationOn('2026-08-19', $ticket), 'the window opens');
        $this->assertSame('due_today', $this->escalationOn('2026-08-20', $ticket), 'the promised day');
        $this->assertSame('overdue',   $this->escalationOn('2026-08-21', $ticket), 'past the promise');
        $this->assertSame('overdue',   $this->escalationOn('2026-08-22', $ticket), 'and it keeps asking');
    }

    public function test_an_answer_settles_only_the_day_it_was_filed(): void
    {
        // Answered on the 19th, the promise unchanged: quiet that day, asked again on the 20th.
        $ticket = $this->ticket('2026-08-20', '2026-08-19 14:00');

        $this->assertNull($this->escalationOn('2026-08-19', $ticket));
        $this->assertSame('due_today', $this->escalationOn('2026-08-20', $ticket));
    }

    public function test_pushing_the_date_back_moves_the_window_and_buys_silence_until_a_day_before_it(): void
    {
        // On the 19th the supervisor said "it moves to the 23rd" — so the promise IS the 23rd now.
        $ticket = $this->ticket('2026-08-23', '2026-08-19 14:00');

        $this->assertNull($this->escalationOn('2026-08-20', $ticket), 'no nagging about a date already moved');
        $this->assertNull($this->escalationOn('2026-08-21', $ticket));
        $this->assertSame('request',   $this->escalationOn('2026-08-22', $ticket), 'reopens one day before');
        $this->assertSame('due_today', $this->escalationOn('2026-08-23', $ticket));
    }

    public function test_answered_today_is_reported_so_the_dashboard_and_the_scan_agree(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 16:00'));
        $answered = $this->service->monitorState($this->ticket('2026-08-20', '2026-08-19 14:00'));
        $silent   = $this->service->monitorState($this->ticket('2026-08-20', '2026-08-18 14:00'));

        $this->assertTrue($answered['answered_today']);
        $this->assertFalse($answered['needs_update']);
        $this->assertFalse($silent['answered_today'], "yesterday's answer does not cover today");
        $this->assertTrue($silent['needs_update']);
    }
}
