<?php

namespace Tests\Foundation;

use App\Models\AccidentCase;
use App\Models\AccidentFinancialEntry;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Services\ContractEligibilityService;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * THE ACCIDENT CASE, end to end.
 *
 * The tests are written around the rules that are expensive to get wrong rather than around the
 * endpoints. Each one names the business claim it is protecting, because a year from now the useful
 * question about a red test is "what did this stop?" and a method called `test_update_works` cannot
 * answer it.
 *
 * Runs on `fleet_test` under DatabaseTransactions — no DDL, everything rolled back. See
 * phpunit.foundation.xml and FoundationTestCase.
 */
class AccidentCaseWorkflowTest extends FoundationTestCase
{

    /**
     * Any live damage type. Damage is named from the catalog rather than typed, so a test that
     * hard-coded "Front bumper" would be asserting the old free-text contract.
     */
    protected function aDamageType(): int
    {
        return (int) \App\Models\DamageCatalog::where('is_active', true)->value('id');
    }
    /** A car on hire right now, with a real customer and a real open type-C contract behind it. */
    private function rentedVehicle(): array
    {
        $vehicle = $this->makeVehicle();

        $customer = Customer::create([
            'customer_no' => 'C' . random_int(100000, 999999),
            'name_en'     => 'Accident Test Customer',
            'mobile1'     => '0500000000',
        ]);

        $contract = Contract::create([
            'contract_no'   => 'RC' . random_int(100000, 999999),
            'contract_type' => 'C',
            'state'         => 'open',
            'vehicle_id'    => $vehicle->id,
            'customer_id'   => $customer->id,
            'out_date'      => now()->subDays(5)->toDateString(),
            'out_time'      => '10:00:00',
        ]);

        return [$vehicle, $customer, $contract];
    }

    /**
     * Walk a case forward to a named rung, opening each gate on the way.
     *
     * Exists so that a test about the POLICE gate does not have to know what comes before it — which
     * is exactly the coupling a configurable ladder is supposed to remove. A test that hard-codes the
     * route to a stage is the old problem wearing a different hat.
     */
    protected function advanceTo(int $id, string $stageKey): void
    {
        for ($i = 0; $i < 12; $i++) {
            $stage = $this->getJson("/api/accidents/$id")->json('data.stage');
            if ($stage === $stageKey) {
                return;
            }
            $res = $this->postJson("/api/accidents/$id/advance");
            if ($res->status() !== 200) {
                $this->fail("Could not reach '$stageKey' — stuck at '$stage': " . $res->getContent());
            }
        }
        $this->fail("Never reached '$stageKey'.");
    }

    private function report(Vehicle $vehicle, array $extra = [])
    {
        return $this->postJson('/api/accidents', array_merge([
            'vehicle_id'    => $vehicle->id,
            'occurred_at'   => now()->subHours(3)->toIso8601String(),
            'location'      => 'Sheikh Zayed Road, exit 43',
            'description'   => 'Rear-ended at the lights.',
            'accident_type' => 'rear_end',
        ], $extra));
    }

    // ── 1 · reported while the car was with an active customer ────────────────────────────────

    /**
     * The headline requirement: a crash on a live rental must capture WHO had the car, and it must
     * capture it as a frozen fact rather than as a link that will later answer for somebody else.
     */
    public function test_accident_on_an_active_rental_captures_the_customer_and_contract(): void
    {
        [$vehicle, $customer, $contract] = $this->rentedVehicle();

        $res = $this->report($vehicle)->assertCreated();

        $ctx = $res->json('data.context');
        $this->assertSame(AccidentCase::PARTY_RENTAL_CUSTOMER, $ctx['responsible_party_type']);
        $this->assertTrue($ctx['detected'], 'the contract was found by the resolver, not typed in');
        $this->assertTrue($ctx['was_with_customer']);
        $this->assertSame($customer->id, $ctx['customer']['ref']);
        $this->assertSame('Accident Test Customer', $ctx['customer']['name']);
        $this->assertSame($contract->contract_no, $ctx['contract']['contract_no']);
        $this->assertSame($contract->out_date->toDateString(), $ctx['contract']['out_date']);
        $this->assertMatchesRegularExpression('/^ACC-\d{4}-\d{4}$/', $res->json('data.reference'));
    }

    // ── 2 · reported with no rental at all ────────────────────────────────────────────────────

    /**
     * The other half of the same rule. A car that was NOT on hire must not acquire a customer, and
     * the honest answer is `unknown` rather than a nearest-rental guess.
     */
    public function test_accident_with_no_open_contract_records_no_customer(): void
    {
        $vehicle = $this->makeVehicle();

        $res = $this->report($vehicle)->assertCreated();

        $ctx = $res->json('data.context');
        $this->assertSame(AccidentCase::PARTY_UNKNOWN, $ctx['responsible_party_type']);
        $this->assertFalse($ctx['was_with_customer']);
        $this->assertNull($ctx['customer']['ref']);
        $this->assertNull($ctx['contract']['contract_no']);
    }

    /** A stated party overrules detection — the reporter was there and the database was not. */
    public function test_a_stated_responsible_party_overrules_detection(): void
    {
        $vehicle = $this->makeVehicle();

        $res = $this->report($vehicle, [
            'responsible_party_type' => AccidentCase::PARTY_EMPLOYEE,
            'driver_name'            => 'Yusuf',
        ])->assertCreated();

        $this->assertSame(AccidentCase::PARTY_EMPLOYEE, $res->json('data.context.responsible_party_type'));
        $this->assertSame('Yusuf', $res->json('data.context.driver_name'));
    }

    // ── 3 · the rental contract is NOT touched ────────────────────────────────────────────────

    /**
     * THE MOST EXPENSIVE MISTAKE THIS FEATURE COULD MAKE. Reporting an accident must leave the hire
     * exactly as it was: still open, still unreturned, still billing. The car being off the road and
     * the customer still being on contract are two true facts at once.
     */
    public function test_reporting_an_accident_does_not_close_the_rental_contract(): void
    {
        [$vehicle, , $contract] = $this->rentedVehicle();

        $this->report($vehicle)->assertCreated();

        $contract->refresh();
        $this->assertSame('open', $contract->state);
        $this->assertNull($contract->in_date, 'the car has not been returned just because it crashed');
        $this->assertTrue(
            Contract::currentlyOpen()->where('vehicle_id', $vehicle->id)->exists(),
            'the contract must still read as currently open',
        );
    }

    // ── 4 · the car cannot quietly be re-let ──────────────────────────────────────────────────

    /**
     * A vehicle with an unresolved accident must never come back as available. The authority is
     * ContractEligibilityService — the one place that answers "may this car go on a contract?".
     */
    public function test_a_vehicle_with_an_open_accident_cannot_be_rented(): void
    {
        $vehicle = $this->makeVehicle(['status' => 'ready', 'operational_status' => 'available']);
        $this->report($vehicle)->assertCreated();

        $result = app(ContractEligibilityService::class)->evaluate($vehicle->fresh());

        $this->assertFalse($result['eligible']);
        $this->assertContains('accident', array_column($result['blocks'], 'key'));
        $this->assertFalse(app(ContractEligibilityService::class)->canRent($vehicle->fresh()));
    }

    /** …and it comes back once the case is closed. A block that never lifts is a grounded fleet. */
    public function test_a_closed_accident_stops_blocking_the_rental(): void
    {
        $vehicle = $this->makeVehicle(['status' => 'ready', 'operational_status' => 'available']);
        $id = $this->idOf($this->report($vehicle));

        $this->postJson("/api/accidents/$id/police/bypass", ['reason' => 'Damaged in our own yard, no report exists.'])->assertOk();
        $this->postJson("/api/accidents/$id/liability", [
            'liability_status' => AccidentCase::LIABILITY_COMPANY,
            'liability_source' => 'internal',
        ])->assertOk();
        $this->postJson("/api/accidents/$id/close")->assertOk();

        $result = app(ContractEligibilityService::class)->evaluate($vehicle->fresh());
        $this->assertNotContains('accident', array_column($result['blocks'], 'key'));
    }

    // ── 5 · the police report gate ────────────────────────────────────────────────────────────

    /**
     * A new case lands on the CONFIGURED first rung — whatever the office named it — with its
     * paperwork visibly missing rather than silently lacking.
     *
     * Asserted against the workflow table rather than a constant. A test naming 'reported' would just
     * be the old hard-coding moved into the suite, and would go green on a workflow nobody can use.
     */
    public function test_a_new_case_lands_on_the_configured_initial_stage(): void
    {
        [$vehicle] = $this->rentedVehicle();

        $res = $this->report($vehicle)->assertCreated();

        $initial = \App\Models\AccidentWorkflowStage::whereHas('workflow', fn ($w) => $w->where('status', 'active'))
            ->where('is_initial', true)->firstOrFail();

        $this->assertSame($initial->key, $res->json('data.stage'));
        $this->assertSame(AccidentCase::POLICE_MISSING, $res->json('data.police.status'));
        $this->assertContains('police', array_column($res->json('data.gates'), 'key'));
    }

    /** Recording is not verifying, and the case says which of the two has happened. */
    public function test_recording_then_verifying_the_police_report_opens_the_gate(): void
    {
        [$vehicle] = $this->rentedVehicle();
        $id = $this->idOf($this->report($vehicle));
        $this->advanceTo($id, 'police_report');

        $recorded = $this->postJson("/api/accidents/$id/police", [
            'police_report_no'   => 'DXB-2026-99887',
            'police_report_date' => now()->subDay()->toDateString(),
            'police_authority'   => 'Dubai Police',
        ])->assertOk();

        $this->assertSame(AccidentCase::POLICE_RECORDED, $recorded->json('data.police.status'));
        $this->assertFalse($recorded->json('data.police.satisfied'), 'recorded is not verified');
        $this->assertSame('police_report', $recorded->json('data.stage'), 'and it has not moved');

        $verified = $this->postJson("/api/accidents/$id/police/verify")->assertOk();

        $this->assertSame(AccidentCase::POLICE_VERIFIED, $verified->json('data.police.status'));
        $this->assertTrue($verified->json('data.police.satisfied'));
        $this->assertNotNull($verified->json('data.police.verified_by_name'), 'a verification carries a name');
        // STILL ON THE SAME RUNG — but now free to leave it. Verifying used to teleport the case to a
        // hard-coded next stage, which was a statement about one arrangement and would be a lie the
        // moment somebody reordered the list. It opens the gate; moving is a separate, deliberate act.
        $this->assertSame('police_report', $verified->json('data.stage'));
        $this->assertTrue($verified->json('data.workflow.can_advance'));
    }

    /**
     * THE GATE STILL HOLDS — and it now belongs to the stage rather than to its position.
     *
     * The single most important test of the refactor. The protection that used to be an inline check
     * comparing a stage NAME is now a row in the configuration, and it has to refuse just as hard.
     */
    public function test_a_case_cannot_leave_the_police_stage_undocumented(): void
    {
        [$vehicle] = $this->rentedVehicle();
        $id = $this->idOf($this->report($vehicle));
        $this->advanceTo($id, 'police_report');

        $res = $this->postJson("/api/accidents/$id/advance")->assertStatus(422);
        $this->assertStringContainsString('police report',
            strtolower($res->json('message') . json_encode($res->json('errors'))));

        $this->assertSame('police_report', $this->getJson("/api/accidents/$id")->json('data.stage'),
            'and the case has not moved');
    }

    /** …and the exception is allowed, attributed, and permanently visible. */
    public function test_the_police_report_can_be_waived_with_a_recorded_reason(): void
    {
        [$vehicle] = $this->rentedVehicle();
        $id = $this->idOf($this->report($vehicle));

        // No reason → refused. A silent bypass is the thing this whole gate exists to prevent.
        $this->postJson("/api/accidents/$id/police/bypass", ['reason' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $res = $this->postJson("/api/accidents/$id/police/bypass", [
            'reason' => 'Minor scrape inside our own compound — the police do not issue a report for this.',
        ])->assertOk();

        $this->assertSame(AccidentCase::POLICE_BYPASSED, $res->json('data.police.status'));
        $this->assertTrue($res->json('data.police.satisfied'));
        $this->assertStringContainsString('compound', $res->json('data.police.bypass_reason'));
        $this->assertNotNull($res->json('data.police.bypassed_by_name'));

        // The waiver has its OWN event type — findable, not buried in a generic "updated".
        $this->assertDatabaseHas('vehicle_log_events', [
            'accident_case_id' => $id,
            'event_type'       => VehicleLogEvent::EVENT_POLICE_REPORT_BYPASSED,
        ]);
    }

    // ── 6 · liability ─────────────────────────────────────────────────────────────────────────

    /** Liability is never assumed from who was driving. A fresh case is `pending`, full stop. */
    public function test_liability_starts_pending_even_when_the_customer_was_driving(): void
    {
        [$vehicle] = $this->rentedVehicle();

        $res = $this->report($vehicle)->assertCreated();

        $this->assertSame(AccidentCase::LIABILITY_PENDING, $res->json('data.liability.status'));
        $this->assertFalse($res->json('data.liability.decided'));
    }

    /** A verdict carries its author and its basis, and a share only means something when shared. */
    public function test_a_liability_verdict_is_attributed_and_sourced(): void
    {
        [$vehicle] = $this->rentedVehicle();
        $id = $this->idOf($this->report($vehicle));
        $this->postJson("/api/accidents/$id/police/bypass", ['reason' => 'Not a reportable incident.'])->assertOk();

        // A percentage on a non-shared verdict is two contradictory statements.
        $this->postJson("/api/accidents/$id/liability", [
            'liability_status'    => AccidentCase::LIABILITY_OTHER_PARTY,
            'liability_source'    => 'police_report',
            'liability_share_pct' => 40,
        ])->assertStatus(422)->assertJsonValidationErrors('liability_share_pct');

        $res = $this->postJson("/api/accidents/$id/liability", [
            'liability_status' => AccidentCase::LIABILITY_OTHER_PARTY,
            'liability_source' => 'police_report',
            'liability_note'   => 'The other driver ran the light.',
        ])->assertOk();

        $this->assertSame(AccidentCase::LIABILITY_OTHER_PARTY, $res->json('data.liability.status'));
        $this->assertSame('police_report', $res->json('data.liability.source'));
        $this->assertNotNull($res->json('data.liability.decided_by_name'));
        $this->assertNotNull($res->json('data.liability.decided_at'));
    }

    // ── 7 · the money ─────────────────────────────────────────────────────────────────────────

    /**
     * Estimate, approval and payment are three different certainties and must never be added
     * together — and a revision must supersede rather than erase.
     */
    public function test_financials_keep_phases_apart_and_supersede_rather_than_overwrite(): void
    {
        [$vehicle] = $this->rentedVehicle();
        $id = $this->idOf($this->report($vehicle));

        $this->postJson("/api/accidents/$id/financials", [
            'phase' => AccidentFinancialEntry::PHASE_ESTIMATE,
            'party' => AccidentFinancialEntry::PARTY_INSURANCE, 'amount' => 12000,
        ])->assertCreated();

        $this->postJson("/api/accidents/$id/financials", [
            'phase' => AccidentFinancialEntry::PHASE_APPROVED,
            'party' => AccidentFinancialEntry::PARTY_INSURANCE, 'amount' => 7400,
        ])->assertCreated();

        $this->postJson("/api/accidents/$id/financials", [
            'phase' => AccidentFinancialEntry::PHASE_PAID,
            'party' => AccidentFinancialEntry::PARTY_INSURANCE, 'amount' => 6900,
        ])->assertCreated();

        $this->postJson("/api/accidents/$id/financials", [
            'phase' => AccidentFinancialEntry::PHASE_APPROVED,
            'party' => AccidentFinancialEntry::PARTY_DEDUCTIBLE, 'amount' => 1000,
        ])->assertCreated();

        // The revision. The 12,000 must still exist; it must simply no longer be the estimate.
        $revised = $this->postJson("/api/accidents/$id/financials", [
            'phase' => AccidentFinancialEntry::PHASE_ESTIMATE,
            'party' => AccidentFinancialEntry::PARTY_INSURANCE, 'amount' => 9400,
        ])->assertCreated();

        $money = $revised->json('data.financials');
        $this->assertEquals(9400, $money['estimated']);
        $this->assertEquals(8400, $money['approved'], 'insurer 7,400 + deductible 1,000');
        $this->assertEquals(6900, $money['paid']);

        $case = AccidentCase::find($id);
        $this->assertSame(5, $case->financialEntries()->count(), 'nothing is deleted');
        $this->assertSame(4, $case->liveFinancials()->count(), 'the 12,000 estimate is superseded, not gone');
        $this->assertDatabaseHas('accident_financial_entries', [
            'accident_case_id' => $id, 'amount' => '12000.00',
        ]);
    }

    /** An amount nobody has taken responsibility for is carried, never quietly dropped from a total. */
    public function test_an_unresolved_amount_is_reported_as_its_own_figure(): void
    {
        [$vehicle] = $this->rentedVehicle();
        $id = $this->idOf($this->report($vehicle));

        $this->postJson("/api/accidents/$id/financials", [
            'phase' => AccidentFinancialEntry::PHASE_ESTIMATE,
            'party' => AccidentFinancialEntry::PARTY_UNRESOLVED, 'amount' => 3200,
        ])->assertCreated();

        $money = $this->getJson("/api/accidents/$id")->assertOk()->json('data.financials');
        $this->assertEquals(3200, $money['unresolved']);
    }

    // ── 8 · the repair is a child, not a copy ─────────────────────────────────────────────────

    /** A repair raised from a case is an ordinary ticket, parented — the workflow is not reimplemented. */
    public function test_a_repair_ticket_can_be_linked_back_to_the_accident(): void
    {
        $vehicle = $this->makeVehicle();
        $id = $this->idOf($this->report($vehicle));

        $ticket = Maintenance::create([
            'vehicle_id'      => $vehicle->id,
            'workflow_status' => Maintenance::WF_INSPECTION_PENDING,
            'origin'          => Maintenance::ORIGIN_MANUAL,
        ]);

        $this->postJson("/api/accidents/$id/repair", ['maintenance_id' => $ticket->id])->assertCreated();

        $this->assertSame($id, (int) $ticket->fresh()->accident_case_id);

        $show = $this->getJson("/api/accidents/$id")->assertOk();

        // The case now carries TWO tickets and they mean different things: the fenced accident-cycle
        // ticket that put the car on the maintenance board when it was reported, and this one — a real
        // repair somebody authorised. Only the second is a repair, and the payload says so.
        $repairs = collect($show->json('data.repairs'));
        $this->assertSame([$ticket->id], $repairs->pluck('id')->all(),
            'the fenced cycle ticket is not a repair');
        $this->assertSame('/maintenance-workflow/' . $ticket->id, $repairs->first()['url']);

        $cycle = $show->json('data.cycle_ticket');
        $this->assertNotNull($cycle, 'the car is on the maintenance board');
        $this->assertNotSame($ticket->id, $cycle['id']);
        $this->assertSame(Maintenance::WF_ACCIDENT_CYCLE,
            Maintenance::find($cycle['id'])->workflow_status);
    }

    /**
     * Raising the repair goes through the SAME door as "straight to the garage" — the workflow is
     * called, not reimplemented — and the ticket comes back parented to the case and countable under
     * its own reason code.
     */
    public function test_raising_a_repair_opens_an_ordinary_dispatch_ticket_parented_to_the_case(): void
    {
        $vehicle = $this->makeVehicle(['status' => 'ready', 'operational_status' => 'available']);
        $id = $this->idOf($this->report($vehicle));
        $this->postJson("/api/accidents/$id/damage", ['damage_catalog_id' => $this->aDamageType()])->assertCreated();

        $res = $this->postJson("/api/accidents/$id/repair", ['note' => 'Body shop work.'])->assertCreated();

        $ticket = Maintenance::findOrFail($res->json('data.maintenance_id'));
        $this->assertSame($id, (int) $ticket->accident_case_id);
        $this->assertSame(Maintenance::WF_INSPECTION_PENDING, $ticket->workflow_status, 'born at Needs Dispatch');
        $this->assertSame('accident_damage', $ticket->request_reason_code, 'accident-driven spend is countable at the source');
        $this->assertSame($id, (int) $ticket->accident_case_id);
    }

    /** A ticket for a different car is refused — an accident must not adopt another vehicle's repair. */
    public function test_a_repair_for_a_different_car_is_refused(): void
    {
        $vehicle = $this->makeVehicle();
        $other   = $this->makeVehicle();
        $id = $this->idOf($this->report($vehicle));

        $ticket = Maintenance::create([
            'vehicle_id'      => $other->id,
            'workflow_status' => Maintenance::WF_INSPECTION_PENDING,
            'origin'          => Maintenance::ORIGIN_MANUAL,
        ]);

        $this->postJson("/api/accidents/$id/repair", ['maintenance_id' => $ticket->id])
            ->assertStatus(422)->assertJsonValidationErrors('maintenance_id');
    }

    // ── 9 · the timeline ──────────────────────────────────────────────────────────────────────

    /** Every accident event lands on the CAR's own append-only trail, not in a parallel store. */
    public function test_accident_events_appear_on_the_vehicle_timeline(): void
    {
        [$vehicle] = $this->rentedVehicle();
        $id = $this->idOf($this->report($vehicle));

        $rows = VehicleLogEvent::where('vehicle_id', $vehicle->id)->pluck('event_type')->all();

        $this->assertContains(VehicleLogEvent::EVENT_ACCIDENT_REPORTED, $rows);
        $this->assertContains(VehicleLogEvent::EVENT_ACCIDENT_CONTEXT_CAPTURED, $rows);
        // No stage-change row at birth any more: a case is BORN on the first rung rather than being
        // reported and then immediately shunted, which is one fewer fictional event in the history.

        // The car's timeline endpoint carries them, with the link that opens the case.
        $feed = $this->getJson("/api/Vehicle/{$vehicle->id}/activity")->assertOk();
        $accidentRows = collect($feed->json('data.events') ?: $feed->json('data'))
            ->filter(fn ($e) => ($e['category'] ?? null) === 'accident');
        $this->assertTrue($accidentRows->isNotEmpty(), 'the vehicle feed carries the accident');
        $this->assertSame('/accidents/' . $id, $accidentRows->first()['link']);
    }

    /** The case's own timeline is the same rows, read forwards, with the doors attached. */
    public function test_the_case_timeline_links_to_related_records(): void
    {
        $vehicle = $this->makeVehicle();
        $id = $this->idOf($this->report($vehicle));

        $ticket = Maintenance::create([
            'vehicle_id'      => $vehicle->id,
            'workflow_status' => Maintenance::WF_INSPECTION_PENDING,
            'origin'          => Maintenance::ORIGIN_MANUAL,
        ]);
        $this->postJson("/api/accidents/$id/repair", ['maintenance_id' => $ticket->id])->assertCreated();

        $events = $this->getJson("/api/accidents/$id/timeline")->assertOk()->json('data.events');

        $linked = collect($events)->firstWhere('event_type', VehicleLogEvent::EVENT_ACCIDENT_REPAIR_LINKED);
        $this->assertNotNull($linked);
        $this->assertSame('/maintenance-workflow/' . $ticket->id, $linked['link']);
        $this->assertSame('Open repair ticket', $linked['link_label']);

        // Forwards: a case reads as a story, oldest first.
        $this->assertSame(VehicleLogEvent::EVENT_ACCIDENT_REPORTED, $events[0]['event_type']);
    }

    // ── 10 · closure ──────────────────────────────────────────────────────────────────────────

    /** A case is not closeable with the two questions that matter still unanswered. */
    public function test_a_case_cannot_be_closed_with_liability_undecided(): void
    {
        $vehicle = $this->makeVehicle();
        $id = $this->idOf($this->report($vehicle));
        $this->postJson("/api/accidents/$id/police/bypass", ['reason' => 'No report obtainable.'])->assertOk();

        $this->postJson("/api/accidents/$id/close")
            ->assertStatus(422)->assertJsonValidationErrors('liability_status');
    }

    /** A closed case is frozen; reopening is authorised, reasoned and audited. */
    public function test_a_closed_case_is_frozen_and_reopening_is_audited(): void
    {
        $vehicle = $this->makeVehicle();
        $id = $this->idOf($this->report($vehicle));
        $this->postJson("/api/accidents/$id/police/bypass", ['reason' => 'No report obtainable.'])->assertOk();
        $this->postJson("/api/accidents/$id/liability", [
            'liability_status' => AccidentCase::LIABILITY_UNKNOWN, 'liability_source' => 'internal',
        ])->assertOk();
        $this->postJson("/api/accidents/$id/close", ['note' => 'Written off internally.'])->assertOk();

        // Frozen.
        $this->postJson("/api/accidents/$id", ['location' => 'somewhere else'])
            ->assertStatus(422)->assertJsonValidationErrors('stage');

        $this->postJson("/api/accidents/$id/reopen", ['reason' => ''])->assertStatus(422);

        $res = $this->postJson("/api/accidents/$id/reopen", [
            'reason' => 'The insurer came back six months later and disputed the settlement.',
        ])->assertOk();

        // Back one rung, whatever the office called it — not to a hard-coded "settlement".
        $this->assertFalse($res->json('data.is_closed'), 'the case is open again');
        $this->assertNotSame('closed', $res->json('data.stage'));
        $this->assertDatabaseHas('vehicle_log_events', [
            'accident_case_id' => $id, 'event_type' => VehicleLogEvent::EVENT_ACCIDENT_REOPENED,
        ]);
    }

    // ── 11 · history survives the world moving on ─────────────────────────────────────────────

    /**
     * THE POINT OF THE SNAPSHOT COLUMNS. Close the rental, hand the car to somebody else, and the
     * accident must still name the person who was actually driving.
     */
    public function test_the_customer_context_survives_the_contract_closing_and_the_car_being_re_let(): void
    {
        [$vehicle, $customer, $contract] = $this->rentedVehicle();
        $id = $this->idOf($this->report($vehicle));

        // The world moves on: the hire ends, and the car goes out again to somebody else.
        $contract->update(['state' => 'closed', 'in_date' => now()->toDateString(), 'in_time' => '18:00:00']);
        $next = Customer::create([
            'customer_no' => 'C' . random_int(100000, 999999), 'name_en' => 'Somebody Else',
        ]);
        Contract::create([
            'contract_no' => 'RC' . random_int(100000, 999999), 'contract_type' => 'C', 'state' => 'open',
            'vehicle_id' => $vehicle->id, 'customer_id' => $next->id,
            'out_date' => now()->addDay()->toDateString(),
        ]);

        $ctx = $this->getJson("/api/accidents/$id")->assertOk()->json('data.context');

        $this->assertSame('Accident Test Customer', $ctx['customer']['name'], 'the accident still names the right person');
        $this->assertSame($customer->id, $ctx['customer']['ref']);
        $this->assertSame($contract->contract_no, $ctx['contract']['contract_no']);
        $this->assertSame('open', $ctx['contract']['state_at_accident'], 'frozen as it was, not as it is');
        $this->assertSame('closed', $ctx['contract_now']['state'], 'and the live state is served separately');
    }

    // ── 12 · permissions ──────────────────────────────────────────────────────────────────────

    /**
     * The permission split is the feature, not decoration. A logistics driver can REPORT a crash —
     * deliberately the lowest bar here — and cannot decide who pays for it.
     */
    public function test_the_permission_split_holds(): void
    {
        [$vehicle] = $this->rentedVehicle();
        $id = $this->idOf($this->report($vehicle));

        $driver = User::create([
            'name' => 'Field Driver', 'email' => 'driver.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'), 'status' => 'active',
        ]);
        $driver->assignRole('logistics');
        Sanctum::actingAs($driver, ['*']);

        // May report — the whole point of setting that bar low.
        $this->postJson('/api/accidents', [
            'vehicle_id' => $vehicle->id, 'description' => 'Kerbed it on the way back.',
        ])->assertCreated();

        // May not decide liability, waive the police report, touch the money, or close the case.
        $this->postJson("/api/accidents/$id/liability", [
            'liability_status' => AccidentCase::LIABILITY_CUSTOMER, 'liability_source' => 'internal',
        ])->assertForbidden();
        $this->postJson("/api/accidents/$id/police/bypass", ['reason' => 'because'])->assertForbidden();
        $this->postJson("/api/accidents/$id/financials", [
            'phase' => AccidentFinancialEntry::PHASE_PAID,
            'party' => AccidentFinancialEntry::PARTY_INSURANCE, 'amount' => 1,
        ])->assertForbidden();
        $this->postJson("/api/accidents/$id/close")->assertForbidden();
    }

    // ── 13 · damage assessment ────────────────────────────────────────────────────────────────

    /** "Assessment complete, nothing recorded" is a button press, not an assessment. */
    public function test_an_assessment_needs_something_recorded(): void
    {
        $vehicle = $this->makeVehicle();
        $id = $this->idOf($this->report($vehicle));

        $this->postJson("/api/accidents/$id/assess")
            ->assertStatus(422)->assertJsonValidationErrors('damage_items');

        $this->postJson("/api/accidents/$id/damage", [
            'damage_catalog_id' => $this->aDamageType(), 'severity' => 'moderate', 'estimated_cost' => 1800,
        ])->assertCreated();

        $res = $this->postJson("/api/accidents/$id/assess", ['drivable' => true])->assertOk();

        $this->assertNotNull($res->json('data.assessed_at'));
        $this->assertNotNull($res->json('data.assessed_by_name'));
        // The assessment records a FACT. It no longer teleports the case to a hard-coded rung — where
        // it goes next is the configuration's answer, given when somebody advances it.
    }
}
