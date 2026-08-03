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
 *
 * ── WHAT THIS FLAG DOES AND DOES NOT GOVERN ──────────────────────────────────────────────────────────
 *
 * It governs exactly one thing: whether TASK-GRAIN FAULT ANALYTICS narrow their aggregate reads to
 * `kind = fault`. Those are figures whose value changes when services drop out (Top Faults, health,
 * reliability, recurrence chains, part recurrence), and being able to compare before/after is the point
 * of a staged rollout.
 *
 * It does NOT govern correctness. The following are UNCONDITIONAL and must never be put behind it,
 * because a rollout flag deciding whether a stored fact is right is not a rollout, it is a bug with a
 * switch (see docs/Service-Fault-Separation-Audit.md C2, C3):
 *
 *   • WRITES. A service must never be flagged as a recurring fault, never open a RecurringFaultReview,
 *     and never stamp the vehicle's service anchors on behalf of a fault. RecurringFaultService and
 *     MaintenanceWorkflowService::confirmRoutineServices enforce this always.
 *   • CLASSIFICATION. EventClassificationService decides `kind` the same way in every mode.
 *   • LABEL/CONCEPT TYPING. splitLabels(), conceptKind() and the ontology's fault-lane filter are
 *     vocabulary, not analytics — a word does not change meaning because a flag moved.
 *   • The knowledge layer's own fault filters (e.g. RepairHistoryQueryService), which are answering
 *     "what was repaired", not "how many faults were there".
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
