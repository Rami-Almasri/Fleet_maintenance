<?php

namespace App\Listeners;

use App\Events\OperationalEvent;
use App\Services\DashboardService;
use Illuminate\Support\Facades\Cache;

/**
 * Busts the cached intelligence read-models when an operational event happens, so derived boards
 * (dashboard, ops center) recompute. Pure infrastructure: it composes the EXISTING flush points
 * ({@see DashboardService::flushCache()} + the ops-center cache key) — no derivation, no writes to
 * domain state.
 *
 * Phase 1, Step 5 (blueprint B2): DORMANT until Step 6 wires the emitters.
 */
class IntelligenceCacheInvalidator
{
    public function handle(OperationalEvent $event): void
    {
        DashboardService::flushCache();
        Cache::forget('intelligence:maintenance_ops:v1');
    }
}
