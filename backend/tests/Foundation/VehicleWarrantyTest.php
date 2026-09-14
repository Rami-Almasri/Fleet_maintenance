<?php

namespace Tests\Foundation;

use App\Models\Maintenance;
use App\Models\MaintenanceInvoice;
use App\Models\MaintenanceLineItem;
use App\Models\MaintenanceTask;
use App\Models\ServiceRecord;
use App\Models\Vehicle;
use App\Models\Warranty;
use App\Services\Warranty\WarrantyStatusService;
use Illuminate\Support\Facades\DB;

/**
 * Warranty as it is actually meant to work: a FACT ABOUT A CAR, and a yes/no on the work done.
 *
 * There is no coverage engine to test, no case ladder and no gate, because there are none. What
 * these tests pin is the small set of promises the feature makes:
 *
 *   · a warranty is recorded against a car, from the car's own page;
 *   · a car under warranty says so when it enters the maintenance cycle;
 *   · that message NEVER blocks anybody — the most important assertion in the file;
 *   · a piece of work can be marked done under warranty, and reads back that way;
 *   · an expired warranty simply stops being active, with no workflow attached;
 *   · none of it changes a cost, and none of it changes the existing maintenance flow.
 *
 * DatabaseTransactions via FoundationTestCase — nothing here survives the test.
 */
class VehicleWarrantyTest extends FoundationTestCase
{
    private function coverFor(Vehicle $vehicle, array $overrides = []): Warranty
    {
        return app(\App\Services\WarrantyService::class)->create(array_merge([
            'kind'            => Warranty::KIND_VEHICLE,
            'vehicle_id'      => $vehicle->id,
            'subject'         => 'Manufacturer warranty',
            'provider_kind'   => Warranty::PROVIDER_DEALER,
            'provider_name'   => 'BMW',
            'starts_on'       => now()->subMonths(6)->toDateString(),
            'duration_months' => 36,
        ], $overrides), $this->admin);
    }

    private function ticketFor(Vehicle $vehicle): Maintenance
    {
        return Maintenance::create([
            'vehicle_id'      => $vehicle->id,
            'workflow_status' => Maintenance::WF_UNDER_REPAIR ?? 'under_repair',
            'maintenance_type' => 'repair',
        ]);
    }

    // ── 1 · Add a warranty from the vehicle profile ──────────────────────────────────────────────

    /**
     * The car's page is the source of truth, so the endpoint it posts to is the one under test.
     * A whole-car warranty needs NO part and NO repair to point at — that is what lets it be
     * recorded on a car that has never been to a garage.
     */
    public function test_a_warranty_is_recorded_against_a_car_with_no_other_anchor(): void
    {
        $vehicle = $this->makeVehicle();

        $res = $this->postJson('/api/warranties', [
            'kind'            => Warranty::KIND_VEHICLE,
            'vehicle_id'      => $vehicle->id,
            'subject'         => 'Manufacturer warranty',
            'provider_name'   => 'BMW',
            'provider_kind'   => Warranty::PROVIDER_DEALER,
            'starts_on'       => now()->toDateString(),
            'duration_months' => 36,
        ]);

        $res->assertSuccessful();

        $w = Warranty::where('vehicle_id', $vehicle->id)->firstOrFail();
        $this->assertSame(Warranty::KIND_VEHICLE, $w->kind);
        $this->assertNull($w->part_purchase_id);
        $this->assertNull($w->maintenance_task_id);
        $this->assertSame('BMW', $w->provider_name);
        // The expiry is derived, never typed.
        $this->assertSame(now()->addMonths(36)->toDateString(), $w->expires_on->toDateString());
    }

    /** The car's page reads it back as a live state, which is what the card renders. */
    public function test_the_vehicle_page_reports_the_car_as_under_warranty(): void
    {
        $vehicle = $this->makeVehicle();
        $this->coverFor($vehicle);

        $state = app(WarrantyStatusService::class)->vehicleState($vehicle->fresh());

        $this->assertSame(WarrantyStatusService::STATE_UNDER_WARRANTY, $state['state']);
        $this->assertSame(1, $state['live_count']);
        $this->assertNotNull($state['days_remaining']);
    }

    // ── 2 · The active warranty shows inside the maintenance cycle ──────────────────────────────

    /**
     * The ticket payload carries the recommendation, so the banner needs no second request.
     * `activeCoverFor` is the exact call the resource makes.
     */
    public function test_a_car_under_warranty_announces_itself_in_the_maintenance_cycle(): void
    {
        $vehicle = $this->makeVehicle();
        $this->coverFor($vehicle, ['provider_name' => 'BMW', 'contact_phone' => '04 000 0000']);

        $cover = app(WarrantyStatusService::class)->activeCoverFor($vehicle->fresh());

        $this->assertNotNull($cover, 'a covered car must surface its cover to the cycle');
        $this->assertSame('BMW', $cover['provider']);
        $this->assertSame('04 000 0000', $cover['contact_phone']);
        $this->assertNotNull($cover['expires_on'], 'the banner quotes a date — it must have one');
    }

    /** A car with no warranty surfaces nothing, so the banner never renders. Most of the fleet. */
    public function test_a_car_without_warranty_surfaces_nothing(): void
    {
        $vehicle = $this->makeVehicle();

        $this->assertNull(app(WarrantyStatusService::class)->activeCoverFor($vehicle));
    }

    // ── 3 · THE WARNING NEVER BLOCKS ────────────────────────────────────────────────────────────

    /**
     * THE MOST IMPORTANT TEST IN THIS FILE.
     *
     * The previous cut of this feature refused purchase requests on a covered car and demanded an
     * adjudication before anybody could proceed. That was wrong: routing a car to the dealer or to
     * the usual garage is a judgement the system cannot make. So the warranty now only INFORMS.
     *
     * This asserts the whole maintenance chain still runs untouched on a car under warranty: a part
     * request is created, a line item is written, and nothing anywhere refuses.
     */
    public function test_the_warranty_warning_does_not_block_any_maintenance_action(): void
    {
        $vehicle = $this->makeVehicle();
        $this->coverFor($vehicle);
        $ticket = $this->ticketFor($vehicle);

        // A part request on a covered car — created exactly as on any other car.
        $request = app(\App\Services\PartWorkflowService::class)->createRequest([
            'source'         => \App\Models\PartRequest::SOURCE_GARAGE,
            'vehicle_id'     => $vehicle->id,
            'maintenance_id' => $ticket->id,
            'part_name'      => 'Brake sensor',
            'quantity'       => 1,
            'reason'         => 'Reported fault',
        ], $this->admin);

        $this->assertSame(\App\Models\PartRequest::STATUS_REQUESTED, $request->status);
        $this->assertNotNull($request->id, 'a covered car must not stop a purchase request');

        // And a line item can be written against the same ticket, warranty or not.
        $line = MaintenanceLineItem::create([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $vehicle->id,
            'kind'           => MaintenanceLineItem::KIND_PART,
            'description'    => 'Brake sensor',
            'quantity'       => 1,
            'unit_price'     => 400,
        ]);

        $this->assertNotNull($line->id);
    }

    // ── 4 & 5 · Work with and without warranty ──────────────────────────────────────────────────

    /** #4 A line marked under warranty reads back that way, with the provider. */
    public function test_work_can_be_recorded_as_done_under_warranty(): void
    {
        $vehicle = $this->makeVehicle();
        $this->coverFor($vehicle);
        $ticket = $this->ticketFor($vehicle);

        $line = MaintenanceLineItem::create([
            'maintenance_id'    => $ticket->id,
            'vehicle_id'        => $vehicle->id,
            'kind'              => MaintenanceLineItem::KIND_PART,
            'description'       => 'Brake sensor',
            'quantity'          => 1,
            'unit_price'        => 400,
            'under_warranty'    => true,
            'warranty_provider' => 'BMW',
        ]);

        $line->refresh();

        $this->assertTrue($line->under_warranty);
        $this->assertSame('BMW', $line->warranty_provider);

        /**
         * AND THE MONEY IS UNTOUCHED. This is the constraint the whole design rests on: the flag
         * classifies, it does not account. A warranty line that still carries 400 AED keeps it, so
         * either the invoice is right or the discrepancy stays findable.
         */
        $this->assertEqualsWithDelta(400.0, (float) $line->line_total, 0.01);
    }

    /** #5 The ordinary case: no warranty, and the columns say so without ambiguity. */
    public function test_work_not_under_warranty_is_recorded_plainly(): void
    {
        $vehicle = $this->makeVehicle();
        $ticket  = $this->ticketFor($vehicle);

        $line = MaintenanceLineItem::create([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $vehicle->id,
            'kind'           => MaintenanceLineItem::KIND_LABOR,
            'description'    => 'Diagnostic hour',
            'quantity'       => 1,
            'unit_price'     => 150,
        ]);

        $line->refresh();

        // Default FALSE, not null — see the migration for why there is no tri-state here.
        $this->assertFalse($line->under_warranty);
        $this->assertNull($line->warranty_provider);
    }

    /** The same question is answerable on a FAULT and on a SERVICE, not only on a billed line. */
    public function test_a_fault_and_a_service_can_also_be_marked_under_warranty(): void
    {
        $vehicle = $this->makeVehicle();
        $ticket  = $this->ticketFor($vehicle);

        $task = MaintenanceTask::create([
            'maintenance_id'    => $ticket->id,
            'vehicle_id'        => $vehicle->id,
            'kind'              => MaintenanceTask::KIND_FAULT,
            'symptom'           => 'Brake warning light',
            'status'            => MaintenanceTask::STATUS_COMPLETED,
            'under_warranty'    => true,
            'warranty_provider' => 'BMW',
        ]);

        $service = ServiceRecord::create([
            'vehicle_id'        => $vehicle->id,
            'maintenance_id'    => $ticket->id,
            'service_type'      => 'oil_change',
            'description'       => 'Engine oil + filter',
            'performed_at'      => now()->toDateString(),
            'result'            => ServiceRecord::RESULT_COMPLETED,
            'source'            => ServiceRecord::SOURCE_MANUAL,
            'under_warranty'    => true,
            'warranty_provider' => 'BMW',
        ]);

        $this->assertTrue($task->fresh()->under_warranty);
        $this->assertTrue($service->fresh()->under_warranty);
    }

    // ── 6 · It reads back on the timeline ───────────────────────────────────────────────────────

    /**
     * The timeline renders from the line-item resource, so what the resource ships IS what the
     * timeline can say. Asserting the payload is asserting the sentence.
     */
    public function test_under_warranty_work_is_visible_in_the_line_item_payload(): void
    {
        $vehicle = $this->makeVehicle();
        $ticket  = $this->ticketFor($vehicle);

        $line = MaintenanceLineItem::create([
            'maintenance_id'    => $ticket->id,
            'vehicle_id'        => $vehicle->id,
            'kind'              => MaintenanceLineItem::KIND_PART,
            'description'       => 'Brake sensor',
            'quantity'          => 1,
            'unit_price'        => 400,
            'under_warranty'    => true,
            'warranty_provider' => 'BMW',
        ]);

        $payload = (new \App\Http\Resources\MaintenanceLineItemResource($line))->toArray(request());

        $this->assertTrue($payload['under_warranty']);
        $this->assertSame('BMW', $payload['warranty_provider']);
        $this->assertSame('Brake sensor', $payload['description']);
    }

    // ── 7 · Expired warranty ────────────────────────────────────────────────────────────────────

    /**
     * An expired warranty simply stops being active. No workflow, no alert, no case — it drops out
     * of the active state and out of the cycle banner, and that is the whole behaviour.
     */
    public function test_an_expired_warranty_is_simply_not_active(): void
    {
        $vehicle = $this->makeVehicle();
        $this->coverFor($vehicle, [
            'starts_on'       => now()->subYears(5)->toDateString(),
            'duration_months' => 36,
        ]);

        $state = app(WarrantyStatusService::class)->vehicleState($vehicle->fresh());

        $this->assertSame(WarrantyStatusService::STATE_EXPIRED, $state['state']);
        $this->assertSame(0, $state['live_count']);
        $this->assertNull(
            app(WarrantyStatusService::class)->activeCoverFor($vehicle->fresh()),
            'an expired warranty must not reach the maintenance cycle',
        );
    }

    /**
     * Cover ends on months OR kilometres, whichever comes first — the one rule worth keeping from
     * the bigger design, because in a rental fleet the distance leg usually gets there first.
     */
    public function test_cover_ends_on_whichever_leg_runs_out_first(): void
    {
        $vehicle = $this->makeVehicle(['odometer' => 61000]);
        $this->coverFor($vehicle, [
            'starts_on'       => now()->subMonths(6)->toDateString(),
            'duration_months' => 36,
            'start_odometer'  => 0,
            'duration_km'     => 60000,
        ]);

        // Years of calendar left, but the kilometres are gone.
        $this->assertSame(
            WarrantyStatusService::STATE_EXPIRED,
            app(WarrantyStatusService::class)->vehicleState($vehicle->fresh())['state'],
        );
    }

    // ── 8 · Regression on the existing maintenance flow ─────────────────────────────────────────

    /**
     * A car with NO warranty behaves exactly as it did before this feature existed — no extra rows,
     * no extra columns set, nothing refused. Almost the entire fleet is in this state, so if this
     * fails the feature is a regression wearing a feature's clothes.
     */
    public function test_the_existing_maintenance_flow_is_unchanged_without_a_warranty(): void
    {
        $vehicle = $this->makeVehicle();
        $ticket  = $this->ticketFor($vehicle);

        $before = DB::table('warranties')->count();

        $request = app(\App\Services\PartWorkflowService::class)->createRequest([
            'source'         => \App\Models\PartRequest::SOURCE_GARAGE,
            'vehicle_id'     => $vehicle->id,
            'maintenance_id' => $ticket->id,
            'part_name'      => 'Air filter',
            'quantity'       => 1,
            'reason'         => 'Routine',
        ], $this->admin);

        $line = MaintenanceLineItem::create([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $vehicle->id,
            'kind'           => MaintenanceLineItem::KIND_PART,
            'description'    => 'Air filter',
            'quantity'       => 1,
            'unit_price'     => 90,
        ]);

        $this->assertSame(\App\Models\PartRequest::STATUS_REQUESTED, $request->status);
        $this->assertFalse($line->fresh()->under_warranty);
        $this->assertEqualsWithDelta(90.0, (float) $line->fresh()->line_total, 0.01);
        // The feature invented nothing on a car it has no business touching.
        $this->assertSame($before, DB::table('warranties')->count());
        $this->assertSame(
            WarrantyStatusService::STATE_NONE,
            app(WarrantyStatusService::class)->vehicleState($vehicle->fresh())['state'],
        );
    }

    /**
     * Ending a warranty is a void with a reason, not a delete — and after it the car reads as
     * uncovered with no further machinery involved.
     */
    public function test_ending_a_warranty_leaves_the_car_uncovered_and_keeps_the_record(): void
    {
        $vehicle = $this->makeVehicle();
        $w = $this->coverFor($vehicle);

        app(\App\Services\WarrantyService::class)->void($w, 'Car sold out of warranty terms', $this->admin);

        $this->assertNull(app(WarrantyStatusService::class)->activeCoverFor($vehicle->fresh()));
        $this->assertDatabaseHas('warranties', ['id' => $w->id, 'status' => Warranty::STATUS_VOID]);
    }
}
