<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Services\MaintenanceWorkflowService;
use Illuminate\Support\Carbon;

/**
 * Review Gate — the garage LOG (sheet-imported / hand-entered workshop events) withdraws a pending
 * inspection request, exactly as an OM maintenance contract does.
 *
 * TRANSITIONAL behaviour: while workshop trips are still recorded on the N-Maintenance sheet rather
 * than as OM type-U contracts, the sheet is the fact that says "the car is already on a lift". Once
 * every trip opens an OM contract, withdrawRequestsForWorkshopLog() should stop finding anything —
 * these tests pin the rules it must follow until then.
 */
class ReviewWithdrawnByWorkshopLogTest extends CrudTestCase
{
    /**
     * OWNER DECISION 2026-08-11: the OfficeManager maintenance contract is the ONLY fact that parks a
     * car, so `features.diagnostic_gate.workshop_log_parks` ships OFF and this whole path is dormant in
     * production. The code and these tests stay: the switch is one config value, and if garage-log
     * parking is ever wanted back the behaviour it must return to is pinned right here.
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['features.diagnostic_gate.workshop_log_parks' => true]);
    }

    /** With the switch at its shipped default, the garage log parks nothing at all. */
    public function test_the_garage_log_parks_nothing_when_the_switch_is_off(): void
    {
        config(['features.diagnostic_gate.workshop_log_parks' => false]);

        $ticket = $this->pendingRequest();
        $this->logEvent($ticket->vehicle_id);

        $this->assertSame(0, app(MaintenanceWorkflowService::class)->withdrawRequestsForWorkshopLog());
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->refresh()->workflow_status);
    }

    /** A request sitting in the review queue, exactly as a Driver's /request leaves it. */
    private function pendingRequest(?int $vehicleId = null): Maintenance
    {
        $vehicleId = $vehicleId ?? $this->makeVehicle();

        $res = $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'         => $vehicleId,
            'trigger_reason'     => Maintenance::TRIGGER_TEST_DRIVE,
            'customer_complaint' => 'Knocking noise from the front left.',
        ]);
        $res->assertSuccessful();

        $ticket = Maintenance::findOrFail($this->idOf($res));
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->workflow_status);

        return $ticket;
    }

    /** A workshop-log event row (what the sheet import / dashboard CRUD writes). */
    private function logEvent(int $vehicleId, array $overrides = []): Maintenance
    {
        return Maintenance::create(array_merge([
            'vehicle_id'   => $vehicleId,
            'origin'       => 'sheet',
            'event_status' => 'OUT',
            'out_date'     => Carbon::today()->subDays(2),
            'garage'       => 'GPT Garage',
            'service_main' => 'AC Repair',
        ], $overrides));
    }

    public function test_an_open_log_event_withdraws_the_pending_request(): void
    {
        $ticket = $this->pendingRequest();
        $event  = $this->logEvent($ticket->vehicle_id);

        $withdrawn = app(MaintenanceWorkflowService::class)->withdrawRequestsForWorkshopLog();
        $this->assertSame(1, $withdrawn);

        $ticket->refresh();
        $this->assertSame(Maintenance::WF_REVIEW_REJECTED, $ticket->workflow_status);
        $this->assertSame(Maintenance::REVIEW_REJECT_IN_WORKSHOP_LOG, $ticket->review_rejection_code);
        $this->assertNull($ticket->reviewed_by); // nobody reviewed it — the system did
        $this->assertTrue(Maintenance::isSystemWithdrawal($ticket->review_rejection_code));

        // The evidence on the card: which row, which garage, since when.
        $this->assertSame('workshop_log', $ticket->review_auto_context['source']);
        $this->assertSame($event->id, $ticket->review_auto_context['event_id']);
        $this->assertSame('GPT Garage', $ticket->review_auto_context['garage']);

        // Idempotent — the second sweep finds nothing.
        $this->assertSame(0, app(MaintenanceWorkflowService::class)->withdrawRequestsForWorkshopLog());
    }

    public function test_the_withdrawn_card_stays_in_the_queue_as_news(): void
    {
        $ticket = $this->pendingRequest();
        $this->logEvent($ticket->vehicle_id);

        app(MaintenanceWorkflowService::class)->withdrawRequestsForWorkshopLog();

        $res = $this->getJson('/api/maintenance-tickets/pending-review');
        $res->assertSuccessful();

        $card = collect($res->json('data'))->firstWhere('id', $ticket->id);
        $this->assertNotNull($card, 'the withdrawn request must remain visible as a notice');
        $this->assertTrue(data_get($card, 'review.is_system_withdrawal'));
        $this->assertSame('workshop_log', data_get($card, 'review.auto_context.source'));
    }

    public function test_a_returned_car_withdraws_nothing(): void
    {
        $ticket = $this->pendingRequest();
        // Latest event wins: the car went OUT, then came back IN — it is not in the shop.
        $this->logEvent($ticket->vehicle_id, ['out_date' => Carbon::today()->subDays(5)]);
        $this->logEvent($ticket->vehicle_id, [
            'event_status'   => 'IN',
            'out_date'       => Carbon::today()->subDays(5),
            'actual_in_date' => Carbon::today()->subDays(1),
        ]);

        $this->assertSame(0, app(MaintenanceWorkflowService::class)->withdrawRequestsForWorkshopLog());
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->refresh()->workflow_status);
    }

    public function test_an_ancient_unclosed_out_withdraws_nothing(): void
    {
        $ticket = $this->pendingRequest();
        // ~70% of historical sheet rows are an OUT whose return was never logged — outside the
        // lookback they must not decide anything.
        $this->logEvent($ticket->vehicle_id, ['out_date' => Carbon::today()->subDays(120)]);

        $this->assertSame(0, app(MaintenanceWorkflowService::class)->withdrawRequestsForWorkshopLog());
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->refresh()->workflow_status);
    }

    public function test_a_human_decision_outranks_the_sweep(): void
    {
        $ticket = $this->pendingRequest();
        $this->logEvent($ticket->vehicle_id);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/reject", [
            'rejection_code' => Maintenance::REVIEW_REJECT_NOT_NEEDED,
        ])->assertSuccessful();

        $this->assertSame(0, app(MaintenanceWorkflowService::class)->withdrawRequestsForWorkshopLog());

        $ticket->refresh();
        $this->assertSame(Maintenance::REVIEW_REJECT_NOT_NEEDED, $ticket->review_rejection_code);
        $this->assertNotNull($ticket->reviewed_by);
    }

    public function test_a_reviewer_cannot_pick_the_system_code(): void
    {
        $ticket = $this->pendingRequest();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/reject", [
            'rejection_code' => Maintenance::REVIEW_REJECT_IN_WORKSHOP_LOG,
        ])->assertStatus(422);
    }
}
