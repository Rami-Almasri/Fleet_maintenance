<?php

namespace App\Providers;

use App\Events\FaultIdentified;
use App\Events\MaintenanceLaneChanged;
use App\Events\PartDelivered;
use App\Events\PartDeliveryDelayed;
use App\Events\PartInstalled;
use App\Events\PartRequestApproved;
use App\Events\PartRequestRejected;
use App\Events\PartRequirementRaised;
use App\Events\PurchaseOrderIssued;
use App\Listeners\IntelligenceCacheInvalidator;
use App\Listeners\NotificationDispatcher;
use App\Listeners\TimelineProjector;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The platform event contract wiring (blueprint B2 / D2). Binds every Phase-1 operational event to the
 * three projection listeners (timeline / notifications / cache), all of which compose EXISTING
 * components. Event auto-discovery stays off — bindings are explicit and reviewable.
 *
 * Phase 1, Step 5: the listeners are bound but DORMANT — no code emits these events until Step 6 wires
 * the emitters. The state-CROSSING events + top-level VehicleOperationalStateChanged are absent by
 * design (DEBT-5, deferred to the DEBT-1 cutover).
 */
class EventServiceProvider extends ServiceProvider
{
    /** @var array<int, class-string> */
    private const OPERATIONAL_EVENTS = [
        FaultIdentified::class,
        PartRequirementRaised::class,
        PartRequestApproved::class,
        PartRequestRejected::class,
        PurchaseOrderIssued::class,
        PartDelivered::class,
        PartDeliveryDelayed::class,
        PartInstalled::class,
        MaintenanceLaneChanged::class,
    ];

    /** @var array<int, class-string> */
    private const LISTENERS = [
        TimelineProjector::class,
        NotificationDispatcher::class,
        IntelligenceCacheInvalidator::class,
    ];

    public function boot(): void
    {
        foreach (self::OPERATIONAL_EVENTS as $event) {
            foreach (self::LISTENERS as $listener) {
                Event::listen($event, [$listener, 'handle']);
            }
        }
    }
}
