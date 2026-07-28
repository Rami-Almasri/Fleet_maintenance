<?php

namespace Tests\Crud;

use App\Events\PartRequirementRaised;
use App\Models\VehicleLogEvent;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 1, Step 5/6 — the event layer's Phase-1 fan-out effect is CACHE INVALIDATION (blueprint B2).
 * Under Option B / DEBT-6 the timeline stays owned by PartWorkflowService::logVehicle(), so the event
 * layer itself writes no timeline row. In production the layer is DORMANT — no emitter fires until the
 * Step-6 workflow calls dispatch.
 */
class OperationalEventFanoutTest extends CrudTestCase
{
    public function test_operational_event_invalidates_intelligence_cache(): void
    {
        Cache::put('intelligence:maintenance_ops:v1', 'stale', 600);

        PartRequirementRaised::dispatch(1, $this->makeVehicle());

        $this->assertTrue(Cache::missing('intelligence:maintenance_ops:v1'));
    }

    public function test_event_layer_writes_no_timeline_in_phase1(): void
    {
        // Option B / DEBT-6: TimelineProjector projects nothing; the rich timeline is written by
        // PartWorkflowService, not by the bare event.
        $vehicleId = $this->makeVehicle();

        $before = VehicleLogEvent::where('vehicle_id', $vehicleId)->count();
        PartRequirementRaised::dispatch(1, $vehicleId);
        $after = VehicleLogEvent::where('vehicle_id', $vehicleId)->count();

        $this->assertSame($before, $after);
    }
}
