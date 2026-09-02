# The Maintenance Deletion Model

**Status:** implemented 2026-08-01 · **Scope:** `maintenances` and everything that hangs off it

A maintenance ticket is not a record. It is the anchor of an evidence graph — faults, garage stints,
repair inspections, line items, invoices, repair signatures and the vehicle timeline all hang off one
row. This document states what happens when somebody removes one, and why the answer is "nothing is
destroyed".

---

## 1. Why this exists

Deleting a ticket used to fire two mechanisms at once, neither of them visible in the code that called
`delete()`:

- **eleven tables cascaded.** `maintenance_tasks` (and, through them, the per-garage stints),
  `maintenance_line_items`, `maintenance_invoices`, `repair_inspections`, `recurring_fault_reviews`,
  `garage_recommendation_decisions`, `maintenance_required_parts`, `garage_invoice_submissions`,
  `resolved_transfer_flags`, `maintenance_watchers`, and — largest of all —
  `maintenance_signatures`, the projection the entire intelligence layer reads.
- **ten more were nulled.** `vehicle_log_events` above all: the rows survived, but their
  `maintenance_id` was set to NULL.

The second is the more dangerous of the two, because it leaves no gap. The timeline still lists every
event, so the history *reads* as complete while no longer being groupable into the tickets it
describes. Measured on 2026-08-01: **1,559 of 1,979 vehicle log events (79%) were orphaned this way**,
and nothing in the data could distinguish "this ticket had no history" from "its history was detached".

A third property made it worse: nothing recorded that a deletion had happened at all.

## 2. The model

> **A ticket is RETIRED, never destroyed.**

`Maintenance` uses `SoftDeletes`. `delete()` sets `deleted_at` and stops there. No `ON DELETE CASCADE`
fires and no `nullOnDelete` link is rewritten, because from the database's point of view nothing was
deleted. Every fault, stint, inspection, line item, signature and timeline event stays attached.

Restoring is therefore a genuine undo: `deleted_at` is cleared and the whole graph is intact, under the
same primary key. That is the difference between *recoverable* and *re-created*.

### The one deletion path

Everything routes through `WorkshopEventService::tombstone()`, which does two things answering two
different questions:

| Action | Question it answers |
|---|---|
| soft-delete the row | "stop showing this" |
| write a `MaintenanceTombstone` | "and stop re-importing it" (sheet rows only) |

`WorkshopEventService::destroy()` is deprecated and delegates here. It used to hard-delete
hand-entered events on the reasoning that they were "app-owned" — which had it exactly backwards: a
sheet event can be re-imported from Google Sheets, a hand-entered one exists nowhere else.

### The deletion is always recorded

Every retirement writes a `VehicleLogEvent::EVENT_TICKET_DELETED` to the vehicle trail, carrying the
ticket's identity, which path retired it (`hard_delete` / `tombstone` / `bulk_reset`), and a count of
the child rows involved.

This event has a self-reference problem worth knowing about: its own `maintenance_id` would be nulled
by the very cascade it records. So **the ticket id lives in `meta.ticket_id`**, which no constraint can
reach. Always read it from there.

Unlike every other audit write in the codebase, this one is *not* best-effort. A failure aborts the
transaction and the delete does not happen — losing the record silently would recreate the exact blind
spot the event exists to close.

## 3. Live vs historical — the rule for queries

Eloquent reads are scoped automatically: a retired ticket disappears from every board, list, count and
relation with no change at the call site. **Raw SQL is not scoped**, and there are 56 such sites
against this table. Each one had to make a choice, and the choice is written at the site.

> **LIVE reads ask "what is true now" → exclude retired tickets.**
> **HISTORICAL reads ask "what happened" → include them.**

A retired ticket is still a repair that occurred: the car really was off the road and the garage really
did the work. Dropping it from analytics would let history change every time somebody tidied the board,
and — worse — would move a denominator without its numerator, so a comeback rate could "improve"
because a ticket was deleted.

**LIVE (filter `deleted_at` explicitly):**

| Site | Why |
|---|---|
| `OperationsService` — latest manual event | drives `vehicles.operational_status`; a retired ticket must release the car at once |
| `FleetUtilizationService` — "still in the shop today" | current-state question |
| `MaintenanceSwapController` — why a car is in the shop | labels a live board |

**HISTORICAL (deliberately include; declared in each class docblock):**
`OperationalKpiService`, `ForecastCalibration`, `GarageOutcomeForecaster`, `RepairCostEstimator`,
`RepairDurationQueryService`, `VehicleFaultRecurrenceService`, `FleetEvidenceService`,
`EvidenceLedger`, `IntelligenceSnapshot`, plus the day-by-day reconstructions in
`FleetUtilizationService`.

**Never add a raw query against `maintenances` without stating which of the two it is.**

## 4. The unique-index constraint this creates

`maintenances_row_hash_unique` and `maintenances_contract_id_unique` still apply to retired rows: a
trashed row keeps occupying its slot while being invisible to a scoped read. Anything that inserts by
either key **must look through the trash first**:

```php
// MaintenanceSheetImporter — withTrashed() is load-bearing, not tidiness.
$existing = Maintenance::withTrashed()->where('row_hash', $hash)->first();
if ($existing) {
    if ($existing->trashed()) { $existing->restore(); }   // the sheet still publishes it
    $existing->fill($data)->save();
}
```

Without it, the scoped lookup misses the trashed row, falls through to `create()`, and violates the
constraint — in a job that runs unattended at 02:30.

> **Do not "fix" this with a composite unique on `(row_hash, deleted_at)`.** MySQL treats NULLs as
> distinct in a unique index, so two *live* rows with the same hash would both be accepted and live-row
> uniqueness would silently disappear. That trades a loud failure for a quiet one.

## 5. The one sanctioned hard delete

`maintenance:reload` genuinely hard-deletes, because its entire purpose is to make the table an exact
mirror of the sheet, and a soft-deleted row would block the re-import by holding its `row_hash`.

It is gated. The command counts the cascade, prints the blast radius, and **refuses** unless
`--accept-cascade-loss` is passed — a flag deliberately separate from `--force`, because `--force` is
what ends up in a script and "don't prompt me" must never silently mean "wipe the intelligence corpus".
As of 2026-08-01 that cascade is **49,453 `maintenance_signatures` rows**. Re-importing does not bring
them back (rows return with new primary keys); rebuild with `intelligence:rebuild-signatures`.

`maintenance:snapshot-ticket --restore` also hard-deletes its header before re-inserting. It now
refuses when the ticket has live rows in any table outside its `CHILD_TABLES` list, rather than
cascading them away with no copy to restore from.

## 6. What was not recovered

The 1,559 already-orphaned log events **cannot be repaired**. Their ticket id is gone from the row, and
re-deriving it by matching vehicle and time window is reconstruction, not recovery — explicitly ruled
out under the evidence-quality policy. Those rows remain vehicle-level history permanently and must
never be counted as ticket-level evidence.

`vehicle_log_events.maintenance_ref` (FK-free, un-nullable by any constraint) now shadows
`maintenance_id` so this cannot recur even if a hard delete happens somewhere unforeseen.

## 7. Evidence tiers

Three tiers, never blended:

1. **Legacy sheet** — day-granularity operational events. Visit counts, re-entry, downtime days, date
   ranges. Never stage durations, repair effort, waiting hours or cycle timing.
2. **Workflow, orphaned** — events exist, ticket linkage destroyed. Vehicle-level history only,
   permanently.
3. **Workflow, intact** — the only tier that may produce stage-level timing.

Any metric crossing tiers must expose its completeness rather than averaging them into one number. See
`App\Kpi\Kpi` (`measured` / `insufficient` / `unavailable`) for the established pattern.

---

**Related:** `App\Models\Maintenance` (rules for contributors) ·
`App\Services\WorkshopEventService` (the deletion path) ·
`tests/Crud/MaintenanceDeletionLifecycleTest` (regression cover) ·
memory `[[repair-duration-audit-gaps]]`, `[[workshop-timing-corpus-reality]]`
