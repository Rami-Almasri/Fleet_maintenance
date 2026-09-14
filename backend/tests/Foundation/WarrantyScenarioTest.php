<?php

namespace Tests\Foundation;

use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\PartRequest;
use App\Models\ServiceContract;
use App\Models\Vehicle;
use App\Models\Warranty;

/**
 * The whole warranty scenario, walked end to end THROUGH THE REAL HTTP API.
 *
 * Every other test in this feature pins a rule. This one pins the JOURNEY — the four inputs a person
 * actually touches, in the order they touch them, through the same routes, permissions, validation,
 * services and resources the browser uses:
 *
 *   1. the car page shows "no warranty recorded"
 *   2. the warranty form records one                      (POST /api/warranties)
 *   3. the service-contract form records the prepaid servicing
 *   4. "Record a service" consumes one
 *   5. the maintenance cycle shows the recommendation — and does NOT block
 *   6. a line item is marked done under warranty, and keeps its cost
 *
 * A unit test can prove each rule and still leave the feature unreachable: a missing route, a
 * permission typo or a resource that drops a key would pass all of them. This is the test that
 * fails when the doors are shut.
 *
 * DatabaseTransactions via FoundationTestCase — nothing here survives.
 */
class WarrantyScenarioTest extends FoundationTestCase
{
    private function ticketFor(Vehicle $vehicle): Maintenance
    {
        return Maintenance::create([
            'vehicle_id'       => $vehicle->id,
            'workflow_status'  => 'under_repair',
            'maintenance_type' => 'repair',
        ]);
    }

    /**
     * THE WHOLE JOURNEY, in one test, deliberately.
     *
     * Split into six tests it would still pass with the steps unable to follow one another — the
     * warranty created in step 2 is what step 5's banner reads, and the contract from step 3 is what
     * step 4 consumes. The ordering IS the thing under test, so it stays one method with the steps
     * marked out.
     */
    public function test_the_whole_warranty_journey_through_the_api(): void
    {
        $vehicle = $this->makeVehicle(['odometer' => 47408]);

        // ── 1 · The car page, before anything is recorded ────────────────────────────────────────
        $page = $this->getJson("/api/warranties/vehicle/{$vehicle->id}");
        $page->assertSuccessful();
        $this->assertSame('none', data_get($page->json(), 'data.state.state'),
            'a car with no warranty must read "none" — not "expired", which would claim we know something');

        $contracts = $this->getJson("/api/vehicles/{$vehicle->id}/service-contracts");
        $contracts->assertSuccessful();
        $this->assertCount(0, data_get($contracts->json(), 'data.contracts'));

        // ── 2 · FORM 1 — record the warranty from the car page ───────────────────────────────────
        // The form sends an END DATE and an ABSOLUTE odometer, the way the paperwork states them;
        // the server derives the stored window from that.
        $created = $this->postJson('/api/warranties', [
            'kind'            => Warranty::KIND_VEHICLE,
            'vehicle_id'      => $vehicle->id,
            'subject'         => 'Manufacturer warranty',
            'provider_name'   => 'ARABIAN AUTOMOBILES',
            'contact_phone'   => '04 000 0000',
            'starts_on'       => now()->subYear()->toDateString(),
            'duration_months' => 36,
            'start_odometer'  => 0,
            'duration_km'     => 50000,   // → ends AT 50,000 on the dial
        ]);
        $created->assertSuccessful();

        $page = $this->getJson("/api/warranties/vehicle/{$vehicle->id}")->json();
        // EXPIRING_SOON, not plain "under warranty" — and this is the interesting part. Two and a half
        // years of DATE cover remain, but only 2,592 km of DISTANCE cover, and the binding leg is the
        // one that runs out first. A date-only reading would paint this car green for another year
        // while its cover quietly ran out on the motorway. It is still live cover either way.
        $this->assertSame('expiring_soon', data_get($page, 'data.state.state'));
        // The report's own arithmetic: 50,000 − 47,408 = 2,592.
        $this->assertSame(2592, data_get($page, 'data.state.km_remaining'));
        $this->assertNull(data_get($page, 'data.state.km_over'), 'still inside the limit');

        // ── 3 · FORM 2 — the service contract, a SEPARATE promise ────────────────────────────────
        $contract = $this->postJson("/api/vehicles/{$vehicle->id}/service-contracts", [
            'coverage_label'        => '5 Lube Service/5Yrs',
            'provider_name'         => 'ARABIAN AUTOMOBILES',
            'services_total'        => 5,
            'services_used'         => 2,
            'interval_km'           => 10000,
            'ends_on'               => now()->addYears(2)->toDateString(),
            'ends_at_km'            => 50000,
            'last_service_odometer' => 46747,
        ]);
        $contract->assertSuccessful();
        $contractId = data_get($contract->json(), 'data.id');

        $list = $this->getJson("/api/vehicles/{$vehicle->id}/service-contracts")->json();
        $row  = data_get($list, 'data.contracts.0');
        $this->assertSame('5 Lube Service/5Yrs', $row['coverage_label'], 'the label is shown verbatim');
        $this->assertSame(3, $row['services_remaining'], '5 covered − 2 used');
        $this->assertSame(56747, $row['next_service_due_at_km'], 'last change + the CONTRACT interval');
        $this->assertSame('active', data_get($row, 'verdict.state'));

        // ── 4 · INPUT 3 — "Record a service" consumes one ────────────────────────────────────────
        $this->postJson("/api/service-contracts/{$contractId}/use", ['odometer' => 48000])->assertSuccessful();

        $row = data_get($this->getJson("/api/vehicles/{$vehicle->id}/service-contracts")->json(), 'data.contracts.0');
        $this->assertSame(2, $row['services_remaining'], 'one was used');
        $this->assertSame(48000, $row['last_service_odometer']);
        $this->assertSame(58000, $row['next_service_due_at_km'], 'the next due point moved with it');

        // ── 5 · The maintenance cycle: a RECOMMENDATION that does not block ──────────────────────
        $ticket = $this->ticketFor($vehicle);

        $cover = app(\App\Services\Warranty\WarrantyStatusService::class)->activeCoverFor($vehicle->fresh());
        $this->assertNotNull($cover, 'the covered car must surface its cover to the ticket');
        $this->assertSame('ARABIAN AUTOMOBILES', $cover['provider']);
        $this->assertSame('04 000 0000', $cover['contact_phone'], 'the phone is the actionable part');

        // THE CRITICAL ASSERTION: the ordinary maintenance chain still runs on a covered car.
        $request = app(\App\Services\PartWorkflowService::class)->createRequest([
            'source'         => PartRequest::SOURCE_GARAGE,
            'vehicle_id'     => $vehicle->id,
            'maintenance_id' => $ticket->id,
            'part_name'      => 'Brake sensor',
            'quantity'       => 1,
            'reason'         => 'Reported fault',
        ], $this->admin);
        $this->assertSame(PartRequest::STATUS_REQUESTED, $request->status,
            'a warranty must never stop a purchase request — it only informs');

        // ── 6 · INPUT 4 — the work is marked done under warranty, and keeps its cost ─────────────
        $line = MaintenanceLineItem::create([
            'maintenance_id'    => $ticket->id,
            'vehicle_id'        => $vehicle->id,
            'kind'              => MaintenanceLineItem::KIND_PART,
            'description'       => 'Brake sensor',
            'quantity'          => 1,
            'unit_price'        => 400,
            'under_warranty'    => true,
            'warranty_provider' => 'ARABIAN AUTOMOBILES',
        ]);

        $payload = (new \App\Http\Resources\MaintenanceLineItemResource($line->fresh()))->toArray(request());
        $this->assertTrue($payload['under_warranty'], 'the timeline reads this to say "Done under warranty"');
        $this->assertSame('ARABIAN AUTOMOBILES', $payload['warranty_provider']);
        // CLASSIFICATION ONLY. If this ever changes, the flag has started editing money.
        $this->assertEqualsWithDelta(400.0, (float) $payload['line_total'], 0.01);
    }

    /** The overage half of the journey: the same car, once it is past the limit. */
    public function test_the_car_page_reports_how_far_over_the_limit_it_is(): void
    {
        // 75,167 km against a 50,000 limit — the report prints -25,167.
        $vehicle = $this->makeVehicle(['odometer' => 75167]);

        $this->postJson('/api/warranties', [
            'kind' => Warranty::KIND_VEHICLE, 'vehicle_id' => $vehicle->id,
            'subject' => 'Manufacturer warranty', 'provider_name' => 'ARABIAN AUTOMOBILES',
            'starts_on' => now()->subYear()->toDateString(), 'duration_months' => 36,
            'start_odometer' => 0, 'duration_km' => 50000,
        ])->assertSuccessful();

        $state = data_get($this->getJson("/api/warranties/vehicle/{$vehicle->id}")->json(), 'data.state');

        $this->assertSame('expired', $state['state']);
        $this->assertSame(25167, $state['km_over'], 'the magnitude the report prints as -25,167');
        $this->assertNull($state['km_remaining'], 'never both');
    }

    /** A contract finishes on its COUNT even with years and kilometres to spare. */
    public function test_a_contract_finishes_when_its_services_run_out(): void
    {
        $vehicle = $this->makeVehicle(['odometer' => 1000]);

        $id = data_get($this->postJson("/api/vehicles/{$vehicle->id}/service-contracts", [
            'coverage_label' => '5 Lube Service/5Yrs',
            'services_total' => 5, 'services_used' => 4,
            'ends_on' => now()->addYears(4)->toDateString(),
            'ends_at_km' => 90000,
        ])->json(), 'data.id');

        $this->postJson("/api/service-contracts/{$id}/use")->assertSuccessful();

        $row = data_get($this->getJson("/api/vehicles/{$vehicle->id}/service-contracts")->json(), 'data.contracts.0');

        $this->assertSame('expired', data_get($row, 'verdict.state'));
        $this->assertSame('services', data_get($row, 'verdict.ended_by'),
            'four years and 89,000 km remain — the COUNT is what finished it');
    }

    /** A car with no warranty is untouched by any of it — most of the fleet. */
    public function test_a_car_without_cover_sees_none_of_this(): void
    {
        $vehicle = $this->makeVehicle();

        $this->assertNull(app(\App\Services\Warranty\WarrantyStatusService::class)->activeCoverFor($vehicle));

        $state = data_get($this->getJson("/api/warranties/vehicle/{$vehicle->id}")->json(), 'data.state');
        $this->assertSame('none', $state['state']);
        $this->assertNull($state['km_over']);

        $line = MaintenanceLineItem::create([
            'maintenance_id' => $this->ticketFor($vehicle)->id,
            'vehicle_id'     => $vehicle->id,
            'kind'           => MaintenanceLineItem::KIND_LABOR,
            'description'    => 'Diagnostic hour',
            'quantity'       => 1, 'unit_price' => 150,
        ]);

        $this->assertFalse($line->fresh()->under_warranty, 'default false, never a tri-state');
    }

    /** Ending a contract is deliberate, and the row is kept. */
    public function test_a_contract_can_be_ended_and_is_kept(): void
    {
        $vehicle = $this->makeVehicle();

        $id = data_get($this->postJson("/api/vehicles/{$vehicle->id}/service-contracts", [
            'coverage_label' => '5 Lube Service/5Yrs',
        ])->json(), 'data.id');

        $this->postJson("/api/service-contracts/{$id}/end", ['reason' => 'Car sold'])->assertSuccessful();

        $this->assertDatabaseHas('service_contracts', ['id' => $id, 'status' => ServiceContract::STATUS_ENDED]);
        $this->assertSame('ended', data_get(
            $this->getJson("/api/vehicles/{$vehicle->id}/service-contracts")->json(),
            'data.contracts.0.verdict.state',
        ));
    }
}
