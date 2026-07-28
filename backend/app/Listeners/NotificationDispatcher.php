<?php

namespace App\Listeners;

use App\Events\OperationalEvent;
use App\Services\NotificationScanner;

/**
 * Fans operational events out to the EXISTING notification delivery ({@see NotificationScanner}).
 *
 * Phase 1, Step 5: the fan-out STRUCTURE is in place and bound, but the concrete event → alert map is
 * intentionally EMPTY here. Deciding which events notify whom (audience/permission/severity) is a
 * notification-design concern that belongs with the delivery-lateness detector and read-surface work
 * (Step 7 / Step 9), not generic Step-5 infrastructure — so those alerts are wired there, composing
 * NotificationScanner's existing notifyBy* helpers. Until then this listener is a no-op (DORMANT).
 */
class NotificationDispatcher
{
    /** Event → alert wiring is populated in Step 7/9. Empty by design in Step 5. */
    private const EVENT_ALERTS = [];

    public function __construct(private readonly NotificationScanner $notifier) {}

    public function handle(OperationalEvent $event): void
    {
        if (! array_key_exists($event::class, self::EVENT_ALERTS)) {
            return; // alerts wired in Step 7/9
        }
    }
}
