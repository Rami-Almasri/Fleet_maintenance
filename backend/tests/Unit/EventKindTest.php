<?php

namespace Tests\Unit;

use App\Models\MaintenanceTask;
use App\Support\EventKind;
use Tests\TestCase;

/**
 * Locks the Event Type layer rollout gate: EventKind tracks the config flag, and the fault scope the
 * consumers apply (only when enforced) actually filters kind=fault. Boots the app for config()/model
 * scopes; no DB.
 */
class EventKindTest extends TestCase
{
    public function test_enforced_tracks_the_config_mode(): void
    {
        config(['features.event_kind' => 'off']);
        $this->assertSame('off', EventKind::mode());
        $this->assertFalse(EventKind::enforced());

        config(['features.event_kind' => 'shadow']);
        $this->assertFalse(EventKind::enforced(), 'shadow must NOT change reads');

        config(['features.event_kind' => 'enforced']);
        $this->assertTrue(EventKind::enforced());
    }

    public function test_faults_scope_filters_kind_fault(): void
    {
        $sql = MaintenanceTask::query()->faults()->toSql();
        $this->assertStringContainsString('kind', $sql);

        // the canonical filter is kind = 'fault'
        $binding = MaintenanceTask::query()->faults()->getBindings();
        $this->assertContains(MaintenanceTask::KIND_FAULT, $binding);
    }
}
