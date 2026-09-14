<?php

namespace Tests\Foundation;

use App\Models\AccidentCase;
use App\Models\AccidentWorkflowStage;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleLogEvent;

/**
 * THE ACCIDENT LIVES INSIDE THE MAINTENANCE CYCLE.
 *
 * The rule this file protects, in one sentence: a crashed car is on the maintenance board from the
 * moment it is reported, and belongs to nobody in the workshop until somebody authorises a repair.
 *
 * Both halves of that matter and they pull against each other. Make the ticket real too early and every
 * crash grounds a car and wakes a supervisor for a job that may never happen; make it too late and the
 * board quietly omits the cars that are least available, for however long the insurer takes. The fenced
 * `accident_cycle` state is the answer to both, and these tests are what stop it drifting back into
 * either failure.
 *
 * @see \App\Models\Maintenance::WF_ACCIDENT_CYCLE
 */
class AccidentMaintenanceCycleTest extends FoundationTestCase
{
    /** A car on hire right now — the case where getting "is it grounded?" wrong is most expensive. */
    private function rentedVehicle(): array
    {
        $vehicle = $this->makeVehicle(['status' => 'ready', 'operational_status' => 'available']);

        $customer = Customer::create([
            'customer_no' => 'C' . random_int(100000, 999999),
            'name_en'     => 'Cycle Test Customer',
            'mobile1'     => '0500000000',
        ]);

        Contract::create([
            'contract_no'   => 'RC' . random_int(100000, 999999),
            'contract_type' => 'C',
            'state'         => 'open',
            'vehicle_id'    => $vehicle->id,
            'customer_id'   => $customer->id,
            'out_date'      => now()->subDays(5)->toDateString(),
            'out_time'      => '10:00:00',
        ]);

        return [$vehicle, $customer];
    }

    private function report(Vehicle $vehicle, array $extra = []): int
    {
        $res = $this->postJson('/api/accidents', array_merge([
            'vehicle_id'  => $vehicle->id,
            'occurred_at' => now()->subHours(2)->toIso8601String(),
            'location'    => 'Al Khail Road',
            'description' => 'Rear-ended at the lights.',
        ], $extra))->assertCreated();

        return (int) $res->json('data.id');
    }

    /** The ticket this case put on the board, whatever state it has reached. */
    private function cycleTicket(int $caseId): ?Maintenance
    {
        return Maintenance::where('accident_case_id', $caseId)->orderBy('id')->first();
    }

    /** The published row for a stage key. */
    private function stageRow(string $key): ?AccidentWorkflowStage
    {
        return AccidentWorkflowStage::whereHas('workflow', fn ($w) => $w->where('status', 'active'))
            ->where('key', $key)->first();
    }

    /**
     * Walk the case to the rung where repair is authorised, answering every gate on the way.
     *
     * Written against `requirement_key` rather than against stage names on purpose: these tests are
     * about the hand-off to the maintenance board, and they must keep working when somebody reorders
     * the accident process — which is the thing the whole feature was built to allow.
     */
    private function advanceToRepair(int $id): void
    {
        for ($i = 0; $i < 14; $i++) {
            $stage = $this->getJson("/api/accidents/$id")->json('data.stage');
            $row   = $this->stageRow($stage);
            if ($row?->requirement_key === 'repair_linked') {
                return;
            }

            $this->openTheGate($id, $row);

            $res = $this->postJson("/api/accidents/$id/advance");
            if ($res->status() !== 200) {
                $this->fail("Stuck at '$stage': " . $res->getContent());
            }
        }
        $this->fail('Never reached the repair rung.');
    }

    /** Give whatever the current rung is asking for, so the case can move on. */
    private function openTheGate(int $id, ?AccidentWorkflowStage $row): void
    {
        switch ($row?->requirement_key) {
            case 'police_report':
                $this->postJson("/api/accidents/$id/police", [
                    'police_report_no'   => 'DXB-' . random_int(10000, 99999),
                    'police_report_date' => now()->subDay()->toDateString(),
                    'police_authority'   => 'Dubai Police',
                ])->assertOk();
                $this->postJson("/api/accidents/$id/police/verify")->assertOk();
                break;

            case 'liability_decision':
                // `unknown` is a DECIDED answer, and choosing it here is deliberate: it keeps the
                // customer-charge rung out of the way (it only applies when the renter is liable), so
                // these tests stay about the maintenance hand-off rather than about money.
                $this->postJson("/api/accidents/$id/liability", [
                    'liability_status' => AccidentCase::LIABILITY_UNKNOWN,
                    'liability_source' => 'internal',
                    'note'             => 'No witnesses and no camera footage.',
                ])->assertOk();
                break;

            case 'document_uploaded':
                // Created directly rather than posted: the upload endpoint is exercised by the document
                // tests, and what this rung needs is the FACT that evidence exists.
                $case = AccidentCase::findOrFail($id);
                VehicleDocument::create([
                    'vehicle_id'       => $case->vehicle_id,
                    'accident_case_id' => $case->id,
                    'kind'             => VehicleDocument::KIND_DAMAGE_PHOTO,
                    'disk'             => 'public',
                    'file_path'        => 'test/insurer-visit.jpg',
                    'original_name'    => 'insurer-visit.jpg',
                    'mime_type'        => 'image/jpeg',
                    'uploaded_at'      => now(),
                ]);
                break;

            case 'manual_confirmation':
                $this->postJson("/api/accidents/$id/confirm-stage", [
                    'stage' => $row->key,
                    'note'  => 'Done.',
                ])->assertOk();
                break;
        }
    }

    // ══ THE CAR APPEARS, BUT NOTHING IS COMMITTED ═════════════════════════════════════════════

    /**
     * Reporting a crash puts the car on the maintenance board immediately — and in the fenced lane.
     *
     * This is the whole feature in one test. Before it, an accident was invisible to the workshop until
     * somebody remembered to raise a ticket, which on a busy week meant the cars least able to earn were
     * the cars nobody could see.
     */
    public function test_reporting_an_accident_puts_the_car_on_the_maintenance_board(): void
    {
        [$vehicle] = $this->rentedVehicle();

        $id     = $this->report($vehicle);
        $ticket = $this->cycleTicket($id);

        $this->assertNotNull($ticket, 'a reported accident belongs on the maintenance board');
        $this->assertSame(Maintenance::WF_ACCIDENT_CYCLE, $ticket->workflow_status);
        $this->assertSame($vehicle->id, (int) $ticket->vehicle_id);
        // Stamped with its real origin, because "how much of our repair work comes from crashes" is a
        // question the fleet asks and cannot answer if a crash is filed as a walk-in.
        $this->assertSame(Maintenance::SOURCE_ACCIDENT, $ticket->request_origin);
    }

    /**
     * …AND THE CAR IS NOT GROUNDED BY IT.
     *
     * "Do not ground the vehicle just because an accident case was reported" — the fenced state is how
     * that promise is kept. The ticket is deliberately outside WF_TICKET_STATES, so nothing in the
     * operational cascade counts the car as being in maintenance.
     *
     * Note what this does NOT say: the car is still un-rentable while the case is unresolved. That is a
     * different judgement, it belongs to the accident case's own stage (`blocks_rental`), and it is
     * tested where it lives. Maintenance and rental are two questions and this fence answers one.
     */
    public function test_the_fenced_ticket_does_not_ground_the_car(): void
    {
        [$vehicle] = $this->rentedVehicle();

        $id     = $this->report($vehicle);
        $ticket = $this->cycleTicket($id);

        $this->assertNotContains($ticket->workflow_status, Maintenance::WF_TICKET_STATES,
            'the accident lane is fenced — it is not a committed maintenance job');
        $this->assertContains($ticket->workflow_status, Maintenance::WF_PRE_TICKET);
        // Asserted as "not in maintenance" rather than "unchanged": the car is on hire, so the cascade
        // correctly settles it on `rented`. What must never happen is the crash claiming it for the
        // workshop while the customer still has it.
        $this->assertNotSame('maintenance', $vehicle->fresh()->operational_status,
            'reporting a crash does not move the car into maintenance');
        $this->assertNull($ticket->vendor_id, 'no garage has been picked');
    }

    /** A fenced ticket is not a repair, and the case's own payload refuses to call it one. */
    public function test_the_fenced_ticket_is_not_counted_as_a_repair(): void
    {
        [$vehicle] = $this->rentedVehicle();
        $id = $this->report($vehicle);

        $show = $this->getJson("/api/accidents/$id")->assertOk();

        $this->assertSame([], $show->json('data.repairs'),
            'nobody has authorised any work — the Repairs tab must be empty');
        $this->assertNotNull($show->json('data.cycle_ticket.id'),
            'but the car IS on the board, and the case links to it');
    }

    /** The board's own lane carries it, so the workshop sees the car where the workshop actually looks. */
    public function test_the_board_shows_the_car_in_the_accident_lane(): void
    {
        [$vehicle] = $this->rentedVehicle();
        $id = $this->report($vehicle);

        $lane = collect($this->getJson('/api/maintenance-tickets/board')->assertOk()
            ->json('data.columns.accident'));

        $this->assertTrue($lane->contains('id', $this->cycleTicket($id)->id),
            'a crashed car belongs in the accident lane of the maintenance board');
    }

    // ══ AUTHORISATION IS THE ONE DOOR OUT ═════════════════════════════════════════════════════

    /**
     * REACHING THE REPAIR RUNG RELEASES THE TICKET into the ordinary maintenance lifecycle.
     *
     * Which rung that is comes from the published configuration — the stage whose requirement is
     * `repair_linked` — not from a stage named "repair". An office that renames it, moves it, or puts
     * two approvals in front of it still gets a working hand-off, and that is the point of the whole
     * architecture change.
     */
    public function test_authorising_repair_releases_the_ticket_into_the_normal_pipeline(): void
    {
        [$vehicle] = $this->rentedVehicle();
        $id = $this->report($vehicle, ['drivable' => false, 'towing_required' => true]);

        $this->advanceToRepair($id);

        $ticket = $this->cycleTicket($id)->fresh();
        $this->assertSame(Maintenance::WF_INSPECTION_PENDING, $ticket->workflow_status,
            'it joins the Supervisor’s dispatch queue like any other ticket');
        $this->assertContains($ticket->workflow_status, Maintenance::WF_TICKET_STATES,
            'and it is now a committed job');
        $this->assertSame($id, (int) $ticket->accident_case_id,
            'still parented to the case that caused it');
    }

    /**
     * A car that still drives goes to the MOBILE lane instead. A scraped mirror does not need a
     * recovery truck, and routing every crash through a garage slot is how the dispatch queue fills up
     * with work that could have been done where the car was parked.
     */
    public function test_a_drivable_car_is_authorised_into_the_on_site_lane(): void
    {
        [$vehicle] = $this->rentedVehicle();
        $id = $this->report($vehicle, ['drivable' => true, 'towing_required' => false]);

        $this->advanceToRepair($id);

        $this->assertSame(Maintenance::WF_ON_SITE_PENDING, $this->cycleTicket($id)->fresh()->workflow_status);
    }

    /** The release is on the record, with the reason it happened and nobody's name missing. */
    public function test_the_release_is_audited(): void
    {
        [$vehicle] = $this->rentedVehicle();
        $id = $this->report($vehicle, ['drivable' => false, 'towing_required' => true]);
        $this->advanceToRepair($id);

        $rows = VehicleLogEvent::where('maintenance_id', $this->cycleTicket($id)->id)
            ->pluck('description')->implode(' | ');

        $this->assertStringContainsString('Accident repair authorised', $rows);
    }

    // ══ ENDINGS ═══════════════════════════════════════════════════════════════════════════════

    /**
     * NO GHOST ROWS. A case that ends without any repair — written off, fixed by the other party's
     * insurer, judged not worth doing — must not leave a fenced ticket sitting on the board that nobody
     * can action and nobody can clear.
     */
    public function test_closing_a_case_with_no_repair_clears_the_board(): void
    {
        [$vehicle] = $this->rentedVehicle();
        $id = $this->report($vehicle);

        // Answer the two questions closing actually asks. Neither of them is "is the car fixed?".
        $this->postJson("/api/accidents/$id/police/bypass", ['reason' => 'Yard scrape, no third party involved.'])->assertOk();
        $this->postJson("/api/accidents/$id/liability", [
            'liability_status' => AccidentCase::LIABILITY_UNKNOWN,
            'liability_source' => 'internal',
            'note'             => 'No witnesses and no camera.',
        ])->assertOk();
        $this->postJson("/api/accidents/$id/close", ['note' => 'Not worth repairing.'])->assertOk();

        $ticket = $this->cycleTicket($id)->fresh();
        $this->assertSame(Maintenance::WF_ACCIDENT_NO_REPAIR, $ticket->workflow_status);
        $this->assertContains($ticket->workflow_status, Maintenance::WF_TERMINAL,
            'the ticket is finished, not merely hidden');
    }

    /**
     * CLOSING THE FILE DOES NOT REACH INTO THE WORKSHOP.
     *
     * Once a repair has been authorised it is a real job with its own ending — a garage may already
     * hold the car. An accident file closing must not be able to cancel it, which is why
     * closeAccidentCycle refuses anything that has already left the fence.
     */
    public function test_closing_a_case_leaves_an_authorised_repair_alone(): void
    {
        [$vehicle] = $this->rentedVehicle();
        $id = $this->report($vehicle, ['drivable' => false, 'towing_required' => true]);
        $this->advanceToRepair($id);

        $this->postJson("/api/accidents/$id/liability", [
            'liability_status' => AccidentCase::LIABILITY_UNKNOWN,
            'liability_source' => 'internal',
            'note'             => 'Undetermined.',
        ])->assertOk();
        $this->postJson("/api/accidents/$id/close", ['note' => 'Closing the file.'])->assertOk();

        $this->assertSame(Maintenance::WF_INSPECTION_PENDING, $this->cycleTicket($id)->fresh()->workflow_status,
            'the repair keeps its place in the queue — closing the paperwork does not cancel the work');
    }

    /**
     * THE VALVE IS ONE-WAY. Rewinding a case behind the repair rung must NOT drag the ticket back into
     * the fence: by then a garage may hold the car, and the way to stop a repair is to stop the repair.
     */
    public function test_rewinding_the_case_does_not_recall_a_released_ticket(): void
    {
        [$vehicle] = $this->rentedVehicle();
        $id = $this->report($vehicle, ['drivable' => false, 'towing_required' => true]);
        $this->advanceToRepair($id);

        $released = $this->cycleTicket($id)->fresh()->workflow_status;

        $initial = AccidentWorkflowStage::whereHas('workflow', fn ($w) => $w->where('status', 'active'))
            ->where('is_initial', true)->firstOrFail();

        $this->postJson("/api/accidents/$id/rewind", [
            'stage'  => $initial->key,
            'reason' => 'The liability verdict was recorded against the wrong party.',
        ])->assertOk();

        $this->assertSame($released, $this->cycleTicket($id)->fresh()->workflow_status,
            'the garage keeps the car; correcting the paperwork does not recall it');
    }

    /** Reporting is idempotent against the board: one case, one ticket, however many times it moves. */
    public function test_a_case_never_grows_a_second_ticket(): void
    {
        [$vehicle] = $this->rentedVehicle();
        $id = $this->report($vehicle, ['drivable' => false, 'towing_required' => true]);

        $this->advanceToRepair($id);
        $this->postJson("/api/accidents/$id/advance");

        $this->assertSame(1, Maintenance::where('accident_case_id', $id)->count());
    }
}
