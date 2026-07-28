<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceCheckpoint;
use App\Models\MaintenanceTask;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Services\State\MaintenanceDelayResolver;
use App\Services\State\OperationalStateLoader;
use App\Services\State\RepairState;
use App\Services\State\WorkflowStateResolver;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1, Step 4 — N+1 proof (blueprint DP1-B). The loader eager-loads the operational-row graph in a
 * bounded, constant number of queries, and running BOTH resolvers over every loaded ticket adds ZERO
 * queries (the real N+1 guard). Also confirms the resolver actually traverses the loaded graph.
 */
class OperationalStateNoNPlusOneTest extends CrudTestCase
{
    private function inRepairTicketWithBlockingPart(): Maintenance
    {
        $vehicleId = $this->makeVehicle();

        $ticket = Maintenance::create([
            'vehicle_id'      => $vehicleId,
            'workflow_status' => Maintenance::WF_UNDER_REPAIR,
            'event_status'    => 'OUT',
        ]);

        $task = MaintenanceTask::create([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $vehicleId,
            'symptom'        => 'Grinding noise',
            'status'         => MaintenanceTask::STATUS_IN_PROGRESS,
        ]);

        $request = PartRequest::create([
            'maintenance_id'      => $ticket->id,
            'maintenance_task_id' => $task->id,
            'vehicle_id'          => $vehicleId,
            'source'              => PartRequest::SOURCE_GARAGE,
            'status'              => PartRequest::STATUS_PURCHASED,
            'part_name'           => 'Brake Pads',
            'category_key'        => 'brakes',
            'quantity'            => 1,
            'reason'              => 'Worn brake pads',
        ]);

        PartPurchase::create([
            'part_request_id'        => $request->id,
            'vehicle_id'             => $vehicleId,
            'part_name'              => 'Brake Pads',
            'category_key'           => 'brakes',
            'purchase_source'        => PartPurchase::SOURCE_SUPPLIER,
            'purchase_price'         => 300,
            'currency'               => 'AED',
            'quantity'               => 1,
            'purchased_at'           => now(),
            'expected_delivery_date' => now()->addDays(2)->toDateString(), // undelivered → blocking
            'source_name'            => 'ABC Parts',
        ]);

        MaintenanceCheckpoint::create([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $vehicleId,
            'status'         => 'waiting_parts',
        ]);

        return $ticket;
    }

    public function test_loader_batches_and_resolvers_add_zero_queries(): void
    {
        $this->inRepairTicketWithBlockingPart();

        $loader = app(OperationalStateLoader::class);
        $repair = app(WorkflowStateResolver::class);
        $delay  = app(MaintenanceDelayResolver::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $tickets     = $loader->openTickets();
        $loadQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        foreach ($tickets as $t) {
            $repair->resolve($t);
            $delay->resolve($t);
        }
        $resolveQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $tickets->count());
        $this->assertSame(0, $resolveQueries, 'resolvers must read loaded relations only — no lazy loading (N+1 guard)');
        $this->assertLessThanOrEqual(15, $loadQueries, 'the loader must be a bounded batch load');
    }

    public function test_loader_query_count_does_not_grow_with_ticket_count(): void
    {
        $loader = app(OperationalStateLoader::class);

        $this->inRepairTicketWithBlockingPart();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $loader->openTickets();
        $withOne = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->inRepairTicketWithBlockingPart();
        $this->inRepairTicketWithBlockingPart();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $loader->openTickets();
        $withThree = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($withOne, $withThree, 'eager-load query count must be constant regardless of ticket count');
    }

    public function test_resolver_sees_the_block_through_the_loaded_graph(): void
    {
        $this->inRepairTicketWithBlockingPart();

        $tickets = app(OperationalStateLoader::class)->openTickets();
        $state   = app(WorkflowStateResolver::class)->resolve($tickets->first());

        $this->assertSame(RepairState::BLOCKED_WAITING_PARTS, $state->state);
    }
}
