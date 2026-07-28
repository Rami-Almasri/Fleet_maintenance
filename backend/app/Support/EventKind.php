<?php

namespace App\Support;

/**
 * The Event Type layer rollout switch — the single place fault-analytics readers ask
 * "should I exclude services yet?". Backed by config('features.event_kind') (off | shadow | enforced),
 * flipped via EVENT_KIND_MODE (config flip, no deploy).
 *
 *   off / shadow — readers IGNORE `kind`; every fault figure is computed exactly as before the
 *                  separation (byte-identical). Shadow additionally means `kind` is written/backfilled
 *                  so classification can accumulate and be validated before any number moves.
 *   enforced     — fault queries apply the kind=fault scope (MaintenanceTask::faults()), so planned
 *                  services stop polluting fault stats / health / recurrence.
 *
 * Consumers gate on enforced() rather than hard-coding ->faults(), so the rollout is reversible and the
 * validation (compare old vs new) can happen in shadow first. See docs/Service-vs-Fault-Domain-Separation.md.
 */
class EventKind
{
    public static function mode(): string
    {
        return (string) config('features.event_kind', 'off');
    }

    /** True once fault analytics should exclude services (read paths apply the kind=fault scope). */
    public static function enforced(): bool
    {
        return self::mode() === 'enforced';
    }
}
