<?php

namespace Tests\Crud;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Lifecycle flows that span more than a single CRUD row:
 *   - Operations: start a vehicle movement (→ open contract) then close it
 *   - Logistics: raise a move → claim → pickup → deliver (round-trip end to end)
 *   - Maintenance workflow: a complaint births a ticket that lands on the board
 */
class OperationsWorkflowTest extends CrudTestCase
{
    // ── Operations: start & close a movement ─────────────────────────────────
    public function test_start_and_close_a_rental_movement(): void
    {
        $vehicle  = $this->makeVehicle(['status' => 'ready']);
        $customer = $this->makeCustomer();

        $start = $this->postJson("/api/Vehicle/$vehicle/operation", [
            'category'    => 'rent',
            'customer_id' => $customer,
            'out_milage'  => 40000,
            'day_price'   => 120,
        ]);
        $start->assertSuccessful();
        $contractId = $this->idOf($start);
        $this->assertGreaterThan(0, $contractId);

        // The car now has a current open movement.
        $this->getJson("/api/Vehicle/$vehicle/operation")->assertSuccessful();

        // Close it out.
        $this->postJson("/api/Contract/$contractId/close", [
            'in_milage' => 40500,
            'in_fuel'   => 'full',
        ])->assertSuccessful();
    }

    // ── Logistics: full one-way move (no odometer gate) ──────────────────────
    public function test_logistics_one_way_lifecycle(): void
    {
        $vehicle = $this->makeVehicle();

        // Coordinator raises a pooled one-way move (no odometer photos required).
        $raise = $this->postJson('/api/logistics', [
            'vehicle_id'  => $vehicle,
            'destination' => 'Downtown Showroom',
            'round_trip'  => false,
        ]);
        $raise->assertSuccessful();
        $taskId = $this->idOf($raise);

        $this->getJson('/api/logistics')->assertSuccessful();
        $this->getJson('/api/logistics/pool')->assertSuccessful();

        $this->postJson("/api/logistics/$taskId/claim")->assertSuccessful();
        $this->getJson('/api/logistics/my-queue')->assertSuccessful();
        $this->postJson("/api/logistics/$taskId/pickup")->assertSuccessful();
        // One-way move terminates at deliver.
        $this->postJson("/api/logistics/$taskId/deliver")->assertSuccessful();
    }

    // ── Logistics: round trip WITH the mandatory odometer + photo gate ───────
    public function test_logistics_round_trip_with_odometer_photos(): void
    {
        Storage::fake('public');
        Storage::fake('s3');
        $vehicle = $this->makeVehicle(['odometer' => 40000]);

        $raise = $this->postJson('/api/logistics', [
            'vehicle_id'  => $vehicle,
            'destination' => 'Al Quoz Garage',
            'round_trip'  => true,
        ]);
        $raise->assertSuccessful();
        $taskId = $this->idOf($raise);

        $this->postJson("/api/logistics/$taskId/claim")->assertSuccessful();

        // Pre-trip odometer + photo are mandatory on a round trip.
        $this->postJson("/api/logistics/$taskId/pickup", [
            'odometer'       => 40010,
            'odometer_photo' => UploadedFile::fake()->image('pre.jpg'),
        ])->assertSuccessful();

        $this->postJson("/api/logistics/$taskId/deliver")->assertSuccessful();

        // Post reading + GPS + photo at return (terminal).
        $this->postJson("/api/logistics/$taskId/return", [
            'lat' => 25.2, 'lng' => 55.3,
            'odometer'       => 40120,
            'odometer_photo' => UploadedFile::fake()->image('post.jpg'),
        ])->assertSuccessful();
    }

    public function test_logistics_round_trip_pickup_requires_odometer(): void
    {
        $vehicle = $this->makeVehicle();
        $raise = $this->postJson('/api/logistics', [
            'vehicle_id' => $vehicle, 'destination' => 'Garage', 'round_trip' => true,
        ]);
        $taskId = $this->idOf($raise);
        $this->postJson("/api/logistics/$taskId/claim")->assertSuccessful();
        // No odometer/photo → must be rejected.
        $this->postJson("/api/logistics/$taskId/pickup")->assertStatus(422);
    }

    public function test_logistics_dispatch_requires_destination(): void
    {
        $vehicle = $this->makeVehicle();
        $this->postJson('/api/logistics', ['vehicle_id' => $vehicle])->assertStatus(422);
    }

    // ── Maintenance workflow: complaint intake → board ───────────────────────
    public function test_complaint_intake_creates_a_ticket_on_the_board(): void
    {
        $vehicle = $this->makeVehicle();

        // Ops no longer grades urgency at intake — the Inspector assigns severity later, so the
        // complaint carries only the car + what the customer reported.
        $res = $this->postJson('/api/maintenance-tickets/complaint', [
            'vehicle_id'        => $vehicle,
            'fault_description' => 'AC not cooling, blows warm air',
        ]);
        $res->assertSuccessful();
        $ticketId = $this->idOf($res);
        $this->assertGreaterThan(0, $ticketId);

        // It shows up on the live pipeline + is individually fetchable.
        $this->getJson('/api/maintenance-tickets/board')->assertSuccessful();
        $this->getJson("/api/maintenance-tickets/$ticketId")->assertSuccessful();
    }

    public function test_complaint_intake_requires_a_fault_description(): void
    {
        $vehicle = $this->makeVehicle();
        $this->postJson('/api/maintenance-tickets/complaint', [
            'vehicle_id' => $vehicle,
        ])->assertStatus(422);
    }
}
