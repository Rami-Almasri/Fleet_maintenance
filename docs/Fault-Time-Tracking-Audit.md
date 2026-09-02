# Fault Time Tracking — End-to-End Audit

**Date:** 2026-08-29 · **Branch:** `feat/vehicle-report-attention` · **Live DB:** `laravel` (MySQL via XAMPP)

This audit answers: *can the system reliably say how many hours a specific fault took to repair?*
Short answer today: **no.** The mechanism is well-designed but three quarters of faults never enter it,
and the two labor values that do exist both contradict their own work timeline.

---

## 1. Authoritative source for each time value

| Value | Authoritative home | Class | Notes |
|---|---|---|---|
| Dispatch instant | `maintenance_task_assignments.assigned_at` | **Fact** | Shared by every fault on the ticket. Never per-fault. |
| Garage arrival | `maintenance_task_assignments.arrived_at` | **Fact** | Per stint. Added 2026-08-17. |
| Work start (per fault) | `maintenance_task_assignments.work_started_at` | **Fact** | Stamped by per-fault signals only. |
| Stint end | `maintenance_task_assignments.released_at` | **Fact** | |
| Attempt labor | `maintenance_task_assignments.labor_hours` | **Fact (human)** | Write-once per attempt. |
| Custody seconds | *derived* (`FaultRepairTimeService`) | **Derived** | `assigned_at → released_at`, summed over attempt. |
| Work seconds | *derived* (`FaultRepairTimeService`) | **Derived** | `work_started_at → released_at`, summed over attempt. |
| `maintenance_tasks.repair_hours` | **cache** of Σ(stint `labor_hours`) | **Derived cache** | Sole exception: stint-less on-site fault. |
| `findings[].repair_hours` (JSON) | **legacy display cache** | **Legacy** | Superseded; still written and still read. |
| `maintenances.repair_started_at` | ticket-level arrival | **Fact** | Re-stamped per check-in — garage A's arrival is lost. |

`FaultRepairTimeService` is the single home of the calculation. `assigned_at` and `arrived_at` are
declared to defeat MySQL/MariaDB's implicit `ON UPDATE CURRENT_TIMESTAMP`
(`2026_06_30_150000_fix_task_assignment_assigned_at_on_update`, and `dateTime()` on the later columns).
That hazard is correctly handled.

## 2. Every WRITE path

**`assigned_at`** — `MaintenanceTaskService::assign()` (`:245`), `::transfer()` (`:300`). Both `Carbon::now()`.
**`arrived_at`** — `MaintenanceWorkflowService:5687-5689` only, fill-if-null on arrival check-in.
**`released_at`** — five writers:
- `MaintenanceTaskService::transfer()` `:293` → `transferred_out`
- `MaintenanceTaskService::setStatus()` `:545` → `resolved` / `cancelled`
- `MaintenanceTaskService::failReinspection()` `:642` → `failed_reinspection`
- `MaintenanceTaskService::markIncorrect()` `:716` → `cancelled`
- `MaintenanceSwapController:225`, `MaintenanceWorkflowService:7079`

**`work_started_at`** — exactly one writer, `MaintenanceTaskService::markWorkStarted()` (`:751`), atomic
`UPDATE … WHERE work_started_at IS NULL`. Called from `confirmFault()` (`:440`) and `setStatus(in_progress)` (`:573`).

**`labor_hours`** — three writers:
- `FaultRepairTimeService::recordAttemptLabor()` — atomic fill-if-null (Make Ready batch)
- `FaultRepairTimeService::overwriteAttemptLabor()` — correction, audited, **no HTTP route**
- `MaintenanceTaskService::setStatus()` `:547-551` — inline array-merge on the closing update, **not fill-if-null**

**`maintenance_tasks.repair_hours`** — `FaultRepairTimeService::refreshLaborCache()`, plus a direct write
inside `setStatus()` (`:560-563`), plus the legacy `BackfillMaintenanceTasks` command.

## 3. Every READ path

- `MaintenanceTaskResource:218` → `repair_time` (whole `forTask()` payload), only when `assignments` eager-loaded.
- `MaintenanceVisitJourneyService:245` → contract visit journey panel.
- `CarStatusService:1001` → per-category `hours` from the `repair_hours` cache.
- `CarStatusService:566`, `:972` → its own ticket-level wall-clock, also called `repair_hours` / `avg_repair_hours`.
- Frontend: `FindingsList.js:154` (⏱/🚗 chips), `TaskRoutingModal.js:669-685`, `VisitJourneyPanel.js:130`.

## 4. Calculated vs persisted

**Persisted:** `assigned_at`, `arrived_at`, `work_started_at`, `released_at`, `outcome`, `labor_hours`,
`labor_recorded_by/at`, task lifecycle stamps (`confirmed_at`, `started_at`, `resolved_at`).
**Calculated on read:** attempt segmentation, `work_seconds`, `custody_seconds`, cumulative sums, `basis`.
**Cache:** `maintenance_tasks.repair_hours`, `findings[].repair_hours`.

## 5. Legacy / duplicate / cache values

1. `findings[].repair_hours` — legacy JSON stamp, still dual-written by Make Ready. **2 tickets** carry it.
2. `maintenance_tasks.repair_hours` — legitimate cache, but `setStatus()` writes it directly, bypassing `refreshLaborCache()`.
3. `CarStatusService:566` `repair_hours` — a *different metric* under the *same name*. Name collision.
4. `maintenances.repair_started_at` — superseded per-stint by `arrived_at`, still the ticket-level source.

## 6. Paths where the clock can FAIL to start

| # | Path | Effect |
|---|---|---|
| F1 | `assign()` sets `status = in_progress` **directly**, not via `setStatus()` — `markWorkStarted()` never runs | Dispatch opens a stint with no work clock (arguably correct: dispatch ≠ work) |
| F2 | `confirmFault()` runs when the fault has **no open stint** — `markWorkStarted()` is a silent no-op | **The dominant real-world failure. 80 of 90 confirmed faults have no stint at all.** |
| F3 | On-site (mobile) faults never get a stint | Honest `basis: onsite_no_timeline`, but unmeasurable |
| F4 | `MaintenanceWorkflowController:3398` and `:3659` bulk-resolve faults through `setStatus(completed)` — resolving does not stamp work start | Stint closes with `work_started_at` NULL |
| F5 | `VehicleStatusController:152` does raw `$task->update(['status' => completed])` | **Bypasses `setStatus()` entirely: no stint close, no `released_at`, no outcome. Orphans the open stint.** |
| F6 | `failReinspection()` → new attempt dispatched, but the new stint gets a work clock only if the fault is re-confirmed | Attempt #2 commonly unmeasured |

**Live consequence:** 26 of 30 closed stints (87%) closed with `work_started_at` NULL.

## 7. Paths where the clock can RESTART incorrectly

**None found.** `markWorkStarted()` is `WHERE work_started_at IS NULL` — atomic, first-signal-wins.
Re-confirming, toggling `in_progress`, and concurrent requests are all safe. This part is correct today.

The one nuance: a **transfer** opens a fresh stint whose `work_started_at` is NULL and which is *within the
same attempt*. `forTask()` sums per-stint work windows, so the transfer correctly adds a second interval
rather than restarting — provided the fault is re-confirmed at garage B. If it is not, garage B's work is
invisible.

## 8. Paths releasing a fault with NO valid work timeline

All of F4/F5/F6 above, plus `markIncorrect()` and `transfer()` — both legitimately close a stint that may
never have had a work start. Today **nothing anywhere** requires `work_started_at` before `released_at`.

Live: **26 closed stints with no work start** (21 `resolved`, 3 `cancelled`, 4 `transferred_out`).

## 9. Paths entering labor with NO valid work timeline

1. `setStatus(status: completed, labor_hours: 4)` — accepted with zero reference to `work_started_at`.
2. Make Ready `repair_times[]` → `recordAttemptLabor()` — same, no work-window check.
3. On-site fault with no stint — writes straight to `repair_hours`.
4. `overwriteAttemptLabor()` — validates only the 0–24 h range.

**There is currently no relationship enforced between `labor_hours` and the work window. At all.**

## 10. Validation currently present

**Backend:** `labor_hours` numeric, `min:0`, `max:24` (`MaintenanceWorkflowController:4328`);
`FaultRepairTimeService::assertLaborValue()` (same range); `assertParentWritable()` blocks writes once the
ticket is `closed`/`awaiting_invoice`; write-once via atomic fill-if-null.
**Frontend:** `TicketActionModal.js:2506-2512` — `min=0 max=24 step=0.25`, and a **soft amber warning**
when Σ labor exceeds the *ticket's* workshop hours. It never blocks, and it compares against the wrong
denominator (ticket custody, not the fault's own work window).

## 11. Conflicting definitions of "repair time"

Six, unchanged from the 2026-08-01 audit:

1. `MaintenanceWorkflowResource:545` — `returned_at − repair_started_at`
2. `MaintenanceOperationsService:151` — `repair_started_at ?? created_at`
3. `CarStatusService:185` — `created_at → now` (days in maintenance)
4. `CarStatusService:566` — `repair_started_at → closed`, **named `repair_hours`**
5. `OperationalKpiService:207` — sheet dates
6. `FleetUtilizationService` — type-U contract span

Plus the new pair: `CarStatusService:972` `avg_repair_hours` (wall-clock) vs `:1001` category `hours`
(labor sum) — two different quantities on the same dashboard, both called hours.

## 12. Live data coverage (re-queried 2026-08-29)

```
maintenance_task_assignments — 40 rows
  assigned_at            40  (100%)
  arrived_at              0  (0%)   ← column added 2026-08-17; newest stint is 2026-08-11
  work_started_at         4  (10%)
  released_at            30  (75%)   open: 10
  labor_hours             2  (5%)

by outcome:            open  resolved  transferred_out  cancelled
  count                  10        23                4          3
  with work_started_at    2         2                0          0
  closed w/o work start   —        21                4          3

maintenance_tasks — 162 rows
  with any stint         33  (20%)
  confirmed_at           90
  started_at             74
  resolved_at            99
  repair_hours            2  (1.2%)
  status: completed 97 · pending 53 · in_progress 9 · cancelled 2 · transferred 1

completed faults with NO stint at all: 78
confirmed faults with NO stint at all: 80  (of 90 confirmed)
confirmed AFTER dispatch: 11 · confirmed BEFORE dispatch: 2
tickets whose findings JSON still carries repair_hours: 2 of 66
```

### Inconsistent records — every labor value on the system violates the rule

```
stint 99  task 302  work 13:21:47 → 13:21:58 (11 s)  labor_hours = 3.00
stint 107 task 341  work 13:18:21 → 13:18:33 (12 s)  labor_hours = 1.00
```

**2 of 2 (100%)** of live labor entries claim more hours than their entire recorded work window.
No `work_started_at` precedes `assigned_at` or follows `released_at` (0 violations), and the
`repair_hours` cache matches Σ(stints) on both rows — so ordering and the cache are sound; only the
labor/work relationship is unguarded.

---

## 13. Pause / resume semantics — EXPLICIT FINDING

**The system has NO representation of paused or blocked work at the fault level.** Verified absent:
no `paused_at`, `resumed_at`, `blocked_at`, `block_reason`, or work-interval table on
`maintenance_tasks` or `maintenance_task_assignments`.

What exists and is *not* a substitute:
- `maintenances.paused_at` / `paused_by` / `paused_reason` — the **custody handover** (a person hands the
  car to another person). Ticket-level, about custody, not about whether a technician is working.
- `MaintenanceTask::GATE_PENDING` — the recurring-fault **repair approval gate**. A true blocked state,
  but boolean: it records *that* approval is pending, never the interval it consumed.
- `PartRequest` statuses (`requested → approved → purchased → installed`) — timestamps exist on the part
  request, but they are never joined to the fault's work clock.

**Consequence:** `work_started_at → released_at` is *elapsed shop time for this fault*, not active labor.
The worked example (10:00 in, 11:00 start, 12:00–16:00 waiting for a part, 17:00 finish) is today
recorded as a single 6-hour work window. The system cannot distinguish 3 h of labor from 6 h of presence.

This is why the labor rule must be implemented as a **ceiling against active work**, and why active work
needs to become an interval ledger rather than a single start stamp.

---

## 14. Verdict

**Correct today:** the three-metric separation and the refusal to present custody as fault time; the
atomic fill-if-null on `work_started_at` (no restart bug exists); the write-once labor ledger on stints;
the attempt-segmentation rules (transfer continues, failed re-inspection continues, recurrence never sums);
the `ON UPDATE CURRENT_TIMESTAMP` defences; `assertParentWritable`.

**Broken today:**
1. No relationship enforced between `labor_hours` and the work window — 100% of live labor is contradictory.
2. No pause/blocked model — active work is unmeasurable, waiting time is unanswerable.
3. `VehicleStatusController:152` releases faults by raw update, orphaning stints.
4. `setStatus()` writes `labor_hours` and `repair_hours` outside `FaultRepairTimeService`, non-atomically.
5. `overwriteAttemptLabor()` has no route — corrections are impossible from the UI.
6. Authorization is a single blunt permission (`maintenance.delegate`) for dispatch, status, confirmation
   and labor entry alike. No separation between recording own labor, correcting another's, and overriding.
7. 80% of faults never open a stint, so the whole ledger sits empty.

**Why:** the timing layer was added (2026-08-10) *after* the workflow, and was wired only into the two
newest doors (Make Ready, confirmation). Every older door still writes fault status directly.

---

# PART 2 — What was implemented (2026-08-29)

## Data model added

`maintenance_task_work_sessions` — the ACTIVE-WORK LEDGER. One row = one interval of one fault:
`kind` = `work` (hands-on labor) or `blocked` (+ `block_reason`: parts / approval / customer /
other_workshop / other). Carries `maintenance_task_assignment_id`, so per-garage active hours stay
separable after a transfer. `source` = `live` | `backfill` — a reconstructed interval is never mistaken
for a clocked one. `started_at`/`ended_at` are `dateTime()`, defeating MariaDB's implicit
`ON UPDATE CURRENT_TIMESTAMP`.

`maintenance_task_assignments` gains `labor_basis` (`measured` | `legacy_window` | `declared` |
`override`) and `labor_override_reason`.

**Invariant:** at most one open session per fault. MySQL has no partial unique index for this, so
`FaultWorkSessionService` takes `lockForUpdate()` on the fault's open sessions inside every mutating
transaction. The row lock IS the concurrency guard — a double-tapped Start is a no-op, not a second clock.

## The three metrics, now four

| Metric | Definition | Use |
|---|---|---|
| **Active** | Σ of the fault's `work` sessions | **THE labor number.** Excludes waiting. |
| **Elapsed** | `work_started_at → released_at` | Pre-ledger fallback only |
| **Custody** | `assigned_at → released_at` | The CAR's time. Never the fault's. |
| **Blocked** | Σ `blocked` sessions, by reason | Waiting-for-parts/approval analytics |

## The ceiling rule

`FaultRepairTimeService::laborCeiling()` returns a bound and the basis it came from:
- **measured** — the attempt has sessions → ceiling is its ACTIVE total (waiting excluded).
- **legacy_window** — no sessions but `work_started_at` exists → ceiling is that span (weaker, still real).
- **declared** — no timeline at all → `null`, no ceiling. Value recorded and flagged, never presented as measured.

`assertLaborWithinCeiling()` **rejects**, never clamps. 60-second tolerance absorbs hour-to-second
rounding only. Enforced in `recordAttemptLabor`, `overwriteAttemptLabor`, and the `setStatus` labor path —
which used to write the column inline with no check at all.

Write-once is checked BEFORE the ceiling: a duplicate submit changes nothing, so there is nothing to validate.

## Authorization

Three permissions replace one blunt `maintenance.delegate`:

| Permission | Can | Roles |
|---|---|---|
| `maintenance.labor.record` | clock start/pause/resume, enter this attempt's hours | supervisor, maintenance, manager, admin |
| `maintenance.labor.correct` | change a recorded value (audited, still bound by ceiling) | maintenance, manager, admin |
| `maintenance.labor.override` | book above the ceiling (reason ≥10 chars, flags row, own audit event) | manager, admin |

The supervisor who enters time deliberately cannot silently change it afterwards. The override does not
disable the check — it records that a human overruled it.

## Bugs fixed beyond the ceiling

1. **`$task->assignments()` mis-selected the stint.** The relation carries `orderBy('assigned_at')`;
   appending `orderByDesc('released_at')` does not re-sort, so `recordAttemptLabor` silently picked the
   **oldest** stint. A second attempt's hours were landing on (and being measured against) attempt #1.
   Fixed via `currentAttemptStint()`, queried on the model with an explicit sort.
2. **`VehicleStatusController:152`** released faults with a raw `$task->update(['status' => ...])`,
   leaving the stint open forever and the clock running. Now routes through `setStatus()`.
3. **`setStatus()`** wrote `labor_hours` and `repair_hours` inline, outside the one owning service.
4. Open work sessions now close on resolve, cancel, transfer, failed re-inspection and mark-incorrect.

## Live results

Backfill (`faults:backfill-work-sessions`, idempotent): 2 sessions reconstructed, 2 legacy labor values
stamped `legacy_window`, and **both live labor values reported as ceiling violations** (3.00h on an 11-second
window; 1.00h on 12 seconds). They were **not** rewritten — silently correcting someone's time record is the
exact failure being prevented. They await a supervisor correction or an audited override.

End-to-end on the live schema (rolled back, live data verified untouched at 162 tasks / 40 stints / 2 sessions):

```
work 10:00–12:00, blocked(parts) 12:00–16:00, work 16:00–17:00
→ active=3h  blocked=4h (parts=4h)  custody=7h  basis=sessions
→ ceiling served to the form: 3h (measured)
→ 4.0h REJECTED: "Labor time cannot exceed the recorded fault work duration of 3h 00m…"
→ 3.0h ACCEPTED, basis=measured, repair_hours=3.00
```

## Tests

`tests/Crud/FaultRepairTimeTest.php` — 16 tests, 111 assertions, all green. Nine new:
ceiling rejection (not clamping) · waiting excluded from ceiling · double-start idempotency ·
release closes the session · correction bound by ceiling · override audited and flagged ·
declared basis when no timeline · attempt #2 bounded by its own work · plus the pre-existing four.

Full CRUD suite: **17 failures before my changes, the identical 17 after** (verified by stashing).
All pre-existing at HEAD, all in unrelated areas (financial bands, part history, odometer, delegate driver).

## Remaining gaps

- **80% of faults still never open a stint** — the ledger cannot measure what never enters it. The
  mechanism is now correct and enforced; adoption is a workflow question, not a code one.
- `arrived_at` remains 0% populated (the writer exists; no arrival has occurred since the column shipped).
- The six conflicting ticket-level "repair time" definitions are untouched — out of scope here, and
  changing them would move numbers on dashboards this task was not asked to touch.
- `findings[].repair_hours` legacy dual-write retained; 2 tickets carry it.
