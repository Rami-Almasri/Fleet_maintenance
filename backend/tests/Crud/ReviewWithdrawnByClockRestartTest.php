<?php

namespace Tests\Crud;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\DiagnosticGateService;
use App\Services\MaintenanceWorkflowService;
use Illuminate\Support\Carbon;

/**
 * Review Gate — a system request is retired once its car has been to a workshop and COME BACK.
 *
 * The two sweeps that shipped before this one ask "is the car away RIGHT NOW", so they stop protecting
 * the queue at the exact moment the request became most obsolete: the day the car returned. This is the
 * rule they are snapshots of — the post-downtime count restarted on that day, so what the system asked
 * for is no longer due, whether or not the visit is still open anywhere.
 *
 * Pinned here: which requests may be retired this way (system only), which record supplies the return
 * date (the LATEST of ticket / garage log / OM contract), and that an open contract holds the clock.
 */
class ReviewWithdrawnByClockRestartTest extends CrudTestCase
{
    private function sweep(): int
    {
        return app(MaintenanceWorkflowService::class)->withdrawRequestsWhoseConditionCleared();
    }

    /** A routine request exactly as the Proactive Diagnostic Monitor leaves it, raised `$daysAgo` ago. */
    private function systemRequest(int $daysAgo = 20, ?int $vehicleId = null): Maintenance
    {
        $vehicle = Vehicle::findOrFail($vehicleId ?? $this->makeVehicle());

        $ticket = app(MaintenanceWorkflowService::class)->systemRequestInspection($vehicle);
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->workflow_status);
        $this->assertSame(Maintenance::SOURCE_SYSTEM_SCHEDULE, $ticket->request_origin);

        // Backdate the raising so a return can land after it.
        $ticket->forceFill(['created_at' => Carbon::now()->subDays($daysAgo)])->saveQuietly();

        return $ticket->refresh();
    }

    /** A legacy garage-log row that CLOSED — the car went out and came back on `$backOn`. */
    private function returnedFromLog(int $vehicleId, Carbon $backOn): Maintenance
    {
        return Maintenance::create([
            'vehicle_id'     => $vehicleId,
            'origin'         => 'sheet',
            'event_status'   => 'IN',
            'out_date'       => $backOn->copy()->subDay(),
            'actual_in_date' => $backOn,
            'garage'         => 'GPT Garage',
        ]);
    }

    /** A workshop trip that is STILL OPEN — the car went out on `$outOn` and no return is logged. */
    private function outAtGarage(int $vehicleId, Carbon $outOn): Maintenance
    {
        return Maintenance::create([
            'vehicle_id'     => $vehicleId,
            'origin'         => 'sheet',
            'event_status'   => 'OUT',
            'out_date'       => $outOn,
            'actual_in_date' => null,
            'garage'         => 'GPT Garage',
        ]);
    }

    /** An OM maintenance contract (type U) that closed — the car came back on `$backOn`. */
    private function closedMaintenanceContract(int $vehicleId, Carbon $backOn): Contract
    {
        return Contract::findOrFail($this->makeContract([
            'vehicle_id'    => $vehicleId,
            'contract_type' => 'U',
            'state'         => 'closed',
            'out_date'      => $backOn->copy()->subDays(2),
            'in_date'       => $backOn,
        ]));
    }

    // ── The rule ───────────────────────────────────────────────────────────────────────────────────

    public function test_a_return_after_the_request_withdraws_it(): void
    {
        $ticket = $this->systemRequest(20);
        $event  = $this->returnedFromLog($ticket->vehicle_id, Carbon::today()->subDays(3));

        $this->assertSame(1, $this->sweep());

        $ticket->refresh();
        $this->assertSame(Maintenance::WF_REVIEW_REJECTED, $ticket->workflow_status);
        $this->assertSame(Maintenance::REVIEW_REJECT_CONDITION_CLEARED, $ticket->review_rejection_code);
        $this->assertNull($ticket->reviewed_by); // nobody reviewed it — the count did
        $this->assertTrue(Maintenance::isSystemWithdrawal($ticket->review_rejection_code));

        // The evidence: which record says it came back, and when.
        $ctx = $ticket->review_auto_context;
        $this->assertSame('clock_restarted', $ctx['source']);
        $this->assertSame('legacy', $ctx['anchor_source']);
        $this->assertSame($event->id, $ctx['anchor_source_id']);
        $this->assertSame(
            Carbon::today()->subDays(3)->toDateString(),
            Carbon::parse($ctx['anchor_at'])->toDateString()
        );

        // Idempotent — the second sweep finds nothing.
        $this->assertSame(0, $this->sweep());
    }

    public function test_a_return_before_the_request_withdraws_nothing(): void
    {
        $ticket = $this->systemRequest(5);
        // The car came back BEFORE the system asked — that visit is why it asked, not an answer to it.
        $this->returnedFromLog($ticket->vehicle_id, Carbon::today()->subDays(30));

        $this->assertSame(0, $this->sweep());
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->refresh()->workflow_status);
    }

    public function test_a_return_on_the_same_day_withdraws_nothing(): void
    {
        $ticket = $this->systemRequest(4);
        // Same date as the request: not proof the visit came after it. Whole days only.
        $this->returnedFromLog($ticket->vehicle_id, Carbon::today()->subDays(4));

        $this->assertSame(0, $this->sweep());
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->refresh()->workflow_status);
    }

    // ── Whose request may be retired this way ──────────────────────────────────────────────────────

    public function test_a_human_request_is_never_retired_by_the_clock(): void
    {
        $vehicleId = $this->makeVehicle();

        // A Driver's request — a person asked for a reason a clock cannot see.
        $res = $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'         => $vehicleId,
            'trigger_reason'     => Maintenance::TRIGGER_TEST_DRIVE,
            'customer_complaint' => 'Knocking noise from the front left.',
        ]);
        $res->assertSuccessful();
        $ticket = Maintenance::findOrFail($this->idOf($res));
        $ticket->forceFill(['created_at' => Carbon::now()->subDays(20)])->saveQuietly();

        $this->returnedFromLog($vehicleId, Carbon::today()->subDays(3));

        $this->assertSame(0, $this->sweep());
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->refresh()->workflow_status);
    }

    public function test_a_human_decision_outranks_the_sweep(): void
    {
        $ticket = $this->systemRequest(20);
        $this->returnedFromLog($ticket->vehicle_id, Carbon::today()->subDays(3));

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/reject", [
            'rejection_code' => Maintenance::REVIEW_REJECT_NOT_NEEDED,
        ])->assertSuccessful();

        $this->assertSame(0, $this->sweep());

        $ticket->refresh();
        $this->assertSame(Maintenance::REVIEW_REJECT_NOT_NEEDED, $ticket->review_rejection_code);
        $this->assertNotNull($ticket->reviewed_by);
    }

    public function test_a_reviewer_cannot_pick_the_clock_code(): void
    {
        $ticket = $this->systemRequest(20);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/reject", [
            'rejection_code' => Maintenance::REVIEW_REJECT_CONDITION_CLEARED,
        ])->assertStatus(422);

        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->refresh()->workflow_status);
    }

    // ── "Take the new one" — the anchor conflict the rule was written to settle ─────────────────────

    public function test_the_later_return_wins_when_a_contract_is_newest(): void
    {
        $ticket = $this->systemRequest(30);
        $this->returnedFromLog($ticket->vehicle_id, Carbon::today()->subDays(10));
        $contract = $this->closedMaintenanceContract($ticket->vehicle_id, Carbon::today()->subDays(2));

        $this->assertSame(1, $this->sweep());

        $ctx = $ticket->refresh()->review_auto_context;
        $this->assertSame('om_contract', $ctx['anchor_source']);
        $this->assertSame($contract->id, $ctx['anchor_source_id']);
    }

    public function test_the_later_return_wins_when_the_log_is_newest(): void
    {
        $ticket = $this->systemRequest(30);
        $this->closedMaintenanceContract($ticket->vehicle_id, Carbon::today()->subDays(10));
        $event = $this->returnedFromLog($ticket->vehicle_id, Carbon::today()->subDays(2));

        $this->assertSame(1, $this->sweep());

        $ctx = $ticket->refresh()->review_auto_context;
        $this->assertSame('legacy', $ctx['anchor_source']);
        $this->assertSame($event->id, $ctx['anchor_source_id']);
    }

    public function test_a_closed_maintenance_contract_moves_the_ready_anchor(): void
    {
        $vehicle = Vehicle::findOrFail($this->makeVehicle());
        $back    = Carbon::today()->subDays(4);
        $this->closedMaintenanceContract($vehicle->id, $back);

        $anchor = app(DiagnosticGateService::class)->readyAnchor($vehicle->refresh());
        $this->assertSame($back->toDateString(), Carbon::parse($anchor['at'])->toDateString());
        $this->assertSame('om_contract', $anchor['source']);
        $this->assertSame('maintenance', $anchor['reason']);
    }

    // ── The clock is held while the car is still in there ──────────────────────────────────────────

    public function test_an_open_maintenance_contract_holds_the_clock(): void
    {
        $vehicle = Vehicle::findOrFail($this->makeVehicle());

        // Away far longer than the downtime limit, and still not back.
        $this->makeContract([
            'vehicle_id'    => $vehicle->id,
            'contract_type' => 'U',
            'state'         => 'open',
            'out_date'      => Carbon::today()->subDays(90),
        ]);

        $idle = app(DiagnosticGateService::class)->idleInfo($vehicle->refresh());
        $this->assertTrue($idle['held'], 'the clock must not run while the car is in maintenance');
        $this->assertFalse($idle['exceeded'], 'a held clock can never expire');
        $this->assertSame('in_maintenance_contract', $idle['anchor_reason']);

        // …and nothing is raised for a car on a lift.
        $this->assertSame([], app(DiagnosticGateService::class)->conditionsDue($vehicle));
    }

    // ── What the Controller sees ───────────────────────────────────────────────────────────────────

    /**
     * OWNER RULING 2026-08-10 (supersedes "the clock-restart card stays as news"): the review queue
     * shows a parked request only while the car is ACTUALLY IN THE SHOP — an open maintenance
     * contract or an open workshop trip. A car that has already been and come back is finished
     * business: its counter restarts from the new service anchor and the daily gate simply recounts
     * it, raising a fresh request if the car still needs one. A card would only be a backlog of
     * answers to questions nobody is asking any more (56 such rows on the live queue).
     *
     * The withdrawal itself is unchanged — the request is still closed, still attributed to the
     * system, still readable on the ticket's own history. It is only the QUEUE that stops carrying it.
     */
    public function test_a_cleared_request_leaves_the_queue_and_the_car_is_recounted(): void
    {
        $ticket = $this->systemRequest(20);
        $this->returnedFromLog($ticket->vehicle_id, Carbon::today()->subDays(3));

        $this->sweep();

        // Still withdrawn, still attributed to the system — the record keeps the whole story.
        $ticket->refresh();
        $this->assertSame(Maintenance::WF_REVIEW_REJECTED, $ticket->workflow_status);
        $this->assertSame(Maintenance::REVIEW_REJECT_CONDITION_CLEARED, $ticket->review_rejection_code);
        $this->assertSame('clock_restarted', $ticket->review_auto_context['source']);

        // …but the Controller's queue is not carrying it any more.
        $res = $this->getJson('/api/maintenance-tickets/pending-review');
        $res->assertSuccessful();
        $this->assertNull(
            collect($res->json('data'))->firstWhere('id', $ticket->id),
            'a car that has already come back must not sit in the queue — the recount is the answer',
        );
    }

    /** The other half of the rule: while the car IS in the shop, its parked request stays on screen. */
    public function test_a_request_parked_by_an_open_shop_stay_stays_in_the_queue(): void
    {
        $ticket = $this->systemRequest(20);
        // OM opened a maintenance contract yesterday — the car is on a lift right now. The OM contract
        // is the ONLY fact that parks a car; the garage log gets no vote (workshop_log_parks is off).
        $this->makeContract([
            'vehicle_id'    => $ticket->vehicle_id,
            'contract_type' => 'U',
            'state'         => 'open',
            'out_date'      => Carbon::today()->subDay(),
        ]);

        app(MaintenanceWorkflowService::class)->withdrawRequestsForMaintenanceContracts();

        $res = $this->getJson('/api/maintenance-tickets/pending-review');
        $res->assertSuccessful();
        $card = collect($res->json('data'))->firstWhere('id', $ticket->id);
        $this->assertNotNull($card, 'a request parked on a car in the shop must stay visible');
        $this->assertTrue(data_get($card, 'review.is_system_withdrawal'));
    }

    // ── The fleet countdown — the same rulebook read forwards ──────────────────────────────────────

    /** One car's row out of the fleet countdown. */
    private function countdownRow(int $vehicleId): ?array
    {
        $res = $this->getJson('/api/maintenance-tickets/test-countdown');
        $res->assertSuccessful();

        return collect($res->json('data.rows'))->firstWhere('vehicle_id', $vehicleId);
    }

    public function test_the_countdown_counts_down_from_the_last_return(): void
    {
        $vehicleId = $this->makeVehicle();
        $back      = Carbon::today()->subDays(4);
        $this->returnedFromLog($vehicleId, $back);

        $row = $this->countdownRow($vehicleId);
        $this->assertNotNull($row);
        $this->assertSame('counting', $row['state']);
        $this->assertSame(4, $row['days_since']);
        // limit 15 − 4 days sitting = 11 to go, and the date it lands on is spelled out.
        $this->assertSame($row['limit_days'] - 4, $row['days_left']);
        $this->assertSame(
            Carbon::today()->addDays($row['days_left'])->toDateString(),
            $row['due_on'],
        );
        // Never a bare number — the row carries the record it counted from.
        $this->assertSame('legacy', $row['anchor']['source']);
        $this->assertSame($back->toDateString(), Carbon::parse($row['anchor']['at'])->toDateString());
    }

    public function test_a_closed_maintenance_contract_is_what_the_countdown_counts_from(): void
    {
        $vehicleId = $this->makeVehicle();
        // The log says it came back a fortnight ago; the contract says two days ago. Later wins.
        $this->returnedFromLog($vehicleId, Carbon::today()->subDays(14));
        $contract = $this->closedMaintenanceContract($vehicleId, Carbon::today()->subDays(2));

        $row = $this->countdownRow($vehicleId);
        $this->assertSame('om_contract', $row['anchor']['source']);
        $this->assertSame($contract->id, $row['anchor']['source_id']);
        $this->assertSame($row['limit_days'] - 2, $row['days_left']);
    }

    public function test_a_car_in_the_shop_shows_no_number(): void
    {
        $vehicleId = $this->makeVehicle();
        $this->makeContract([
            'vehicle_id'    => $vehicleId,
            'contract_type' => 'U',
            'state'         => 'open',
            'out_date'      => Carbon::today()->subDays(90),
        ]);

        $row = $this->countdownRow($vehicleId);
        $this->assertSame('parked', $row['state']);
        // A held clock has no countdown — a number here would claim a date that cannot be predicted.
        $this->assertNull($row['days_left']);
        $this->assertNull($row['due_on']);
    }

    public function test_a_car_already_requested_shows_no_number(): void
    {
        $ticket = $this->systemRequest(2);

        $row = $this->countdownRow($ticket->vehicle_id);
        $this->assertSame('in_pipeline', $row['state']);
        $this->assertNull($row['days_left']);
    }

    public function test_the_countdown_and_the_queue_agree_about_what_is_due(): void
    {
        // Long past the limit and rented since — exactly what the daily scan raises.
        $vehicleId = $this->makeVehicle();
        $this->returnedFromLog($vehicleId, Carbon::today()->subDays(60));
        $this->makeContract([
            'vehicle_id'    => $vehicleId,
            'contract_type' => 'C',
            'state'         => 'closed',
            'out_date'      => Carbon::today()->subDays(20),
            'in_date'       => Carbon::today()->subDays(10),
        ]);

        $row = $this->countdownRow($vehicleId);
        $this->assertSame('due_now', $row['state']);
        $this->assertSame(0, $row['days_left']);
        $this->assertNotEmpty($row['reasons'], 'a due car must say which rule fired');

        // The countdown is not a second opinion — it is the same call the scanner makes.
        $this->assertNotEmpty(
            app(DiagnosticGateService::class)->conditionsDue(Vehicle::findOrFail($vehicleId)),
        );
    }

    // ── The parked lifecycle: open OM maintenance → parked → close → recalculated ──────────────────

    private function parkedRow(int $vehicleId): ?array
    {
        $res = $this->getJson('/api/maintenance-tickets/parked-in-shop');
        $res->assertSuccessful();

        return collect($res->json('data.rows'))->firstWhere('vehicle_id', $vehicleId);
    }

    /** (B) A car with an OPEN OM maintenance contract is parked — with or without a prior request. */
    public function test_an_open_om_contract_parks_a_car_with_no_request_at_all(): void
    {
        $vehicleId = $this->makeVehicle();
        $this->makeContract([
            'vehicle_id'    => $vehicleId,
            'contract_type' => 'U',
            'state'         => 'open',
            'out_date'      => Carbon::today()->subDays(2),
        ]);

        // THE FIX: the old tab could only show cars that happened to have a parked request, so a car
        // in the shop with nothing pending was invisible. It is driven by the shop stay now.
        $row = $this->parkedRow($vehicleId);
        $this->assertNotNull($row, 'a car in the shop belongs here even with no request on it');
        $this->assertSame('om_contract', $row['parked']['source']);
        $this->assertSame(2, $row['parked']['days_in_shop']);
        $this->assertSame(Carbon::today()->subDays(2)->toDateString(), $row['parked']['started_at']);
        $this->assertNull($row['request']);

        // …and the planning board agrees it is parked rather than counting down.
        $c = $this->countdownRow($vehicleId);
        $this->assertSame('parked', $c['bucket']);
        $this->assertNull($c['days_left']);
        $this->assertSame('maintenance', $c['operational_status'], 'a car on a lift is not "rented"');
    }

    /** (C) A recommendation that existed before the visit is preserved and shown, not lost. */
    public function test_a_request_pending_before_the_visit_is_preserved_on_the_parked_card(): void
    {
        $ticket = $this->systemRequest(6);
        $this->makeContract([
            'vehicle_id'    => $ticket->vehicle_id,
            'contract_type' => 'U',
            'state'         => 'open',
            'out_date'      => Carbon::today()->subDay(),
        ]);

        app(MaintenanceWorkflowService::class)->withdrawRequestsForMaintenanceContracts();

        $row = $this->parkedRow($ticket->vehicle_id);
        $this->assertNotNull($row);
        $this->assertNotNull($row['request'], 'the recommendation that existed before the visit must survive');
        $this->assertSame($ticket->id, $row['request']['ticket_id']);
        $this->assertTrue($row['request']['was_parked_by_this_visit']);
        // …and its lane is still tellable apart from an oil follow-up.
        $this->assertSame('test_schedule', $row['request']['source_lane']);
    }

    /** (D) Parked days must NEVER make a car look overdue — the whole point of the hold. */
    public function test_five_days_parked_does_not_make_a_car_overdue(): void
    {
        $vehicleId = $this->makeVehicle();
        // Last ready 14 days ago — one day short of the limit when it went in 5 days ago.
        $this->returnedFromLog($vehicleId, Carbon::today()->subDays(14));
        $this->makeContract([
            'vehicle_id'    => $vehicleId,
            'contract_type' => 'U',
            'state'         => 'open',
            'out_date'      => Carbon::today()->subDays(5),
        ]);

        $c = $this->countdownRow($vehicleId);
        $this->assertSame('parked', $c['bucket']);
        $this->assertNull($c['days_over'], 'a parked car can never be reported overdue');

        $idle = app(DiagnosticGateService::class)->idleInfo(Vehicle::findOrFail($vehicleId));
        $this->assertTrue($idle['held']);
        $this->assertFalse($idle['exceeded']);
        $this->assertSame([], app(DiagnosticGateService::class)->conditionsDue(Vehicle::findOrFail($vehicleId)));
    }

    /** (E) Close the contract → the car leaves Parked and its schedule is rebuilt from the END date. */
    public function test_closing_the_contract_releases_the_car_and_rebuilds_the_schedule(): void
    {
        $vehicleId = $this->makeVehicle();
        $this->returnedFromLog($vehicleId, Carbon::today()->subDays(40)); // ancient — would be overdue
        $contractId = $this->makeContract([
            'vehicle_id'    => $vehicleId,
            'contract_type' => 'U',
            'state'         => 'open',
            'out_date'      => Carbon::today()->subDays(6),
        ]);

        $this->assertSame('parked', $this->countdownRow($vehicleId)['bucket']);

        // OM closes it: the car came back two days ago.
        $back = Carbon::today()->subDays(2);
        Contract::findOrFail($contractId)->forceFill([
            'in_date' => $back,
            'state'   => 'closed',
        ])->save();

        $this->assertNull($this->parkedRow($vehicleId), 'a released car must leave the parked list');

        $c = $this->countdownRow($vehicleId);
        $this->assertSame('counting', $c['state'], 'the 40-day-old anchor must not make it instantly overdue');
        $this->assertSame('om_contract', $c['anchor']['source']);
        $this->assertSame($back->toDateString(), Carbon::parse($c['anchor']['at'])->toDateString());
        // Rebuilt from the maintenance END date: 2 days used of the limit, the rest to go.
        $this->assertSame(2, $c['days_since']);
        $this->assertSame($c['limit_days'] - 2, $c['days_left']);
    }

    /** (M) Opening and closing repeatedly must not duplicate anything or break the dates. */
    public function test_repeated_maintenance_cycles_do_not_duplicate_or_break(): void
    {
        $vehicleId = $this->makeVehicle();

        foreach ([[20, 18], [12, 10], [6, 3]] as [$out, $in]) {
            $id = $this->makeContract([
                'vehicle_id'    => $vehicleId,
                'contract_type' => 'U',
                'state'         => 'closed',
                'out_date'      => Carbon::today()->subDays($out),
                'in_date'       => Carbon::today()->subDays($in),
            ]);
            $this->assertNotNull($id);
        }

        // Three visits, one row on the board, counting from the LATEST return (3 days ago).
        $res = $this->getJson('/api/maintenance-tickets/test-countdown');
        $res->assertSuccessful();
        $mine = collect($res->json('data.rows'))->where('vehicle_id', $vehicleId);
        $this->assertCount(1, $mine, 'a car must appear exactly once however many visits it has had');

        $c = $mine->first();
        $this->assertSame(3, $c['days_since']);
        $this->assertSame($c['limit_days'] - 3, $c['days_left']);
        $this->assertNull($this->parkedRow($vehicleId), 'all visits closed — nothing is parked');
    }

    /** (F/G/H/I) The countdown wording the planning board renders, at every boundary. */
    public function test_the_board_reports_today_tomorrow_two_days_and_overdue(): void
    {
        $limit = app(DiagnosticGateService::class)->downtimeLimitDays();

        $cases = [
            // [days since last ready, expected bucket, expected days_left, expected days_over]
            [$limit - 2, 'soon',     2, null],
            [$limit - 1, 'tomorrow', 1, null],
        ];
        foreach ($cases as [$since, $bucket, $left, $over]) {
            $vid = $this->makeVehicle();
            $this->returnedFromLog($vid, Carbon::today()->subDays($since));
            $row = $this->countdownRow($vid);
            $this->assertSame($bucket, $row['bucket'], "at {$since} days since ready");
            $this->assertSame($left, $row['days_left']);
            $this->assertSame($over, $row['days_over']);
        }

        // Due today / overdue both need a rental since the anchor, or the post-downtime rule withholds.
        foreach ([[$limit, 'today', null], [$limit + 3, 'overdue', 3]] as [$since, $bucket, $over]) {
            $vid = $this->makeVehicle();
            $this->returnedFromLog($vid, Carbon::today()->subDays($since));
            $this->makeContract([
                'vehicle_id'    => $vid,
                'contract_type' => 'C',
                'state'         => 'closed',
                'out_date'      => Carbon::today()->subDays($since - 1),
                'in_date'       => Carbon::today()->subDays(1),
            ]);

            $row = $this->countdownRow($vid);
            $this->assertSame($bucket, $row['bucket'], "at {$since} days since ready");
            $this->assertSame(0, $row['days_left']);
            $this->assertSame($over, $row['days_over']);
            $this->assertNotEmpty($row['why'], 'the board must always say WHY');
        }
    }

    /** (J) A parked car must say "paused", never a misleading countdown. */
    public function test_a_parked_car_shows_paused_not_a_countdown(): void
    {
        $vehicleId = $this->makeVehicle();
        $this->returnedFromLog($vehicleId, Carbon::today()->subDays(30));
        $this->makeContract([
            'vehicle_id'    => $vehicleId,
            'contract_type' => 'U',
            'state'         => 'open',
            'out_date'      => Carbon::today()->subDays(3),
        ]);

        $row = $this->countdownRow($vehicleId);
        $this->assertSame('parked', $row['bucket']);
        $this->assertNull($row['days_left']);
        $this->assertNull($row['due_on']);
        $this->assertStringContainsString('paused', strtolower($row['why']));
        $this->assertNotEmpty($row['on_release'], 'the row must say what happens when it is released');
    }

    /** The two tabs must never disagree about who is in the shop. */
    public function test_the_parked_tab_and_the_board_agree_on_who_is_in_the_shop(): void
    {
        $this->makeContract([
            'vehicle_id'    => $this->makeVehicle(),
            'contract_type' => 'U',
            'state'         => 'open',
            'out_date'      => Carbon::today()->subDay(),
        ]);
        // …and a car the GARAGE LOG says is out. It must NOT be parked: only the OM contract parks.
        $loggedOnly = $this->makeVehicle();
        $this->outAtGarage($loggedOnly, Carbon::today()->subDays(2));

        $board  = $this->getJson('/api/maintenance-tickets/test-countdown')->json('data');
        $parked = $this->getJson('/api/maintenance-tickets/parked-in-shop')->json('data');

        $this->assertSame(
            $board['buckets']['parked'] ?? 0,
            $parked['summary']['total'],
            'the planning board and the parked tab must count the same cars',
        );

        // Every parked car got there through an OM contract, and the garage-log car is not among them.
        foreach ($parked['rows'] as $r) {
            $this->assertSame('om_contract', $r['parked']['source']);
        }
        $this->assertNull(
            collect($parked['rows'])->firstWhere('vehicle_id', $loggedOnly),
            'the garage log must not park a car',
        );
        $this->assertNotSame('parked', $this->countdownRow($loggedOnly)['bucket']);
    }

    public function test_the_countdown_summary_adds_up(): void
    {
        $this->makeVehicle();
        $res = $this->getJson('/api/maintenance-tickets/test-countdown');
        $res->assertSuccessful();

        $rows    = $res->json('data.rows');
        $summary = $res->json('data.summary');
        $this->assertSame(count($rows), $summary['total']);

        $counted = 0;
        foreach ($summary as $k => $v) {
            if ($k !== 'total') {
                $counted += $v;
            }
        }
        $this->assertSame($summary['total'], $counted, 'every car must land in exactly one state');
    }
}
