# Maintenance Workflow — End-to-End Audit & Test Plan

**Date:** 2026-07-23 · **Branch audited:** `feat/mileage-investigation-center` (including uncommitted "Incorrect" merge changes) · **Status:** Audit only — no code changed.

**Goal:** Assess whether FleetView's workshop/maintenance workflow behaves like a professional fleet-maintenance platform across the full lifecycle: detection → fault → confirmation → workshop → diagnosis → parts → repair → QC test → return to fleet → re-rental → comeback.

---

## Executive summary

The workflow engine is genuinely strong: a strict state machine (26 ticket statuses, validated transitions via `assertTransition`), per-fault task model with severity/root-cause/confirmation/repair-gate, custody-gated logistics with mandatory odometer photos, dual-gate cost capture (Diagnosis-First + receipt variance), durable QC verdicts (`repair_inspections`), recurring-fault reviews with a money guard, and full audit trail (`vehicle_log_events`) + role-targeted notifications at nearly every step.

The five structural weaknesses that keep it a "ticket system" rather than a full maintenance platform:

1. **No awaiting-parts state on an active ticket.** `awaiting_parts` exists only pre-approval (recommendation queue). A part discovered mid-repair leaves the ticket stuck in `under_repair` with only free-text notes. There is no "release vehicle, repair pending part arrival" flow, no ETA, no reminder. (Scenario 1 fails today.)
2. **Warranty exists but is dead data.** `warranty_months`/`warranty_until` are captured (`warranty_until` is auto-derived by a `MaintenanceLineItem` saving hook — see correction in §3 item 9) but never consulted when the same fault returns. No warranty comeback / free re-repair / supplier-claim concept. (Scenario 4 is half-covered.)
3. **Recurrence detection is brittle and triplicated.** Three independent detectors (RecurringFaultService 90-day, RepairInspectionService 90-day, Foresight episodes) with different rules; the primary match is exact lowercase symptom string equality; hard 90-day cutoff; `days/distance_since_repair` recorded but never thresholded.
4. **Parts and workflow are two unlinked systems.** A garage part request does not drive ticket state; no order/ETA/received tracking, no inventory, no labor split on installs, no old-part disposition, no photos.
5. **Half-finished surfaces:** split-dispatch is backend-only (no UI sends `fault_ids`; Pending-Assignment queue never rendered); "the real fault was X" replacement path was deleted with the not_found→Incorrect merge; two overlapping cost paths can double-count.

---

# 1. Current Workflow Map

## 1.1 Status vocabulary

**Ticket (`maintenances.workflow_status`)** — defined `Maintenance.php:54–193`, transitions `MaintenanceWorkflowService.php:58–137`:

| Phase | Statuses |
|---|---|
| Detection / review | `pending_review` → `inspection_requested` / `review_rejected`(terminal) |
| Complaint lane | `complaint_triage` → `complaint_resolved`(terminal) / `triage_approval_pending` → `inspection_pending`/`inspection_requested` |
| Diagnostic | `inspection_diagnostic` → `recommendation_pending` / `inspection_pending` / `on_site_pending` / `diagnostic_cleared`(terminal) |
| Recommendation queue (fenced, car stays available) | `recommendation_pending` ↔ `awaiting_parts`; → `inspection_pending` / `recommendation_dismissed`(terminal) |
| Dispatch & transit | `inspection_pending` → `awaiting_dispatch` → `in_transit` → `under_repair` |
| Repair & QC | `under_repair` → `repair_review` → `ready_for_pickup` → `in_our_park` → `ready_for_reinspection` → `closed`(terminal) or `reinspection_failed` (loop-back to supervisor) |
| Side lanes | `on_site_pending` → `ready_for_reinspection`; `paused_returned_to_service` (rental pause); `awaiting_invoice` (deferred cost) |

**Fault (`maintenance_tasks.status`)**: `pending, in_progress, completed, transferred, cancelled, not_found`(legacy). Terminal = completed/cancelled/not_found. Plus:
- `confirmation_status`: only `confirmed` accepted now (legacy `not_found`/`different_cause` render-only)
- `repair_gate`: `pending/approved/rejected` (recurring-fault money guard)
- QC counters: `reinspection_failures`, `last_failed_vendor_id/at`
- Dispute: `marked_incorrect_by/at`, `incorrect_reason`

**Garage stint (`maintenance_task_assignments`)** outcomes: `transferred_out, resolved, cancelled, failed_reinspection`.

**Logistics (`logistics_tasks`)**: `dispatched → en_route → picked_up → delivered → returned` (+ cancelled; terminality keyed on `completed_at`).

**Parts (`part_requests`)**: `requested → under_review → approved → purchased → installed → completed` (+ `rejected`, `cancelled`).

**Repair inspection (`repair_inspections.result`)**: `fixed | still_exists | new_issue`; `failure_reason`: `wrong_diagnosis | part_failed | repair_incomplete | wrong_part | customer_complaint | unknown`.

**Recurring review (`recurring_fault_reviews`)**: `open → decided`; decisions `same_repair_failed | new_unrelated_failure | workshop_responsibility | customer_misuse | investigation_required`.

## 1.2 Stage-by-stage map (user action → system action → DB change → next states)

### Stage 0 — Issue detected

| Path | User action | System action | DB change | Next state |
|---|---|---|---|---|
| Controller request | POST `/maintenance-tickets/request` (`maintenance.logistics`) | Create fenced ticket, notify controllers (`maint_review_pending`) | `maintenances` row `pending_review`; `vehicle_log_events` `inspection_requested` | `inspection_requested` or `review_rejected` |
| Manager direct | POST `/request-inspection` (`maintenance.manage`) | Skip review, notify inspector | row `inspection_requested` | `inspection_diagnostic` |
| System (foresight/anomalies/cron) | — | `systemRequestInspection` with `trigger_detail` snapshot | row `pending_review` | review gate |
| Customer complaint | POST `/complaint` | Opens triage lane (severity defaults `moderate` — ops no longer grades intake) | row `complaint_triage` | call log / on-site resolve / route |
| Breakdown | POST `/breakdown` (`maintenance.initiate\|manage`) | Auto: type=breakdown, severity=critical, grounds car RED, 2 notifications | row `inspection_pending` directly; condition_grade→red | dispatch |
| Accident | UI links out to Damage & Accidents | — no ticket — | `damage/accident` records | (separate system) |

Gate: `assertActiveFleet()` — only OM Ready/Rented vehicles may enter.

### Stage 1 — Diagnostic / inspection (Decide)

- `open`/`startDiagnostic` require **test_odometer ≥ 1 + odometer photo** (strict continuity gate, heals `vehicles.odometer`), stamp `test_started_at` (downtime clock), `event_status='IN'`.
- `submitReport` (Decide): `requires_maintenance`, symptoms+root causes (`fault_causes` approved catalog; custom → pending admin review), **mandatory `fault_severity`** (critical/high/moderate/routine), `maintenance_type` (technician limited to routine/breakdown), `repair_location` (in_shop/on_site), **`deferrable_for_rental`** (one-time rental-eligibility call: default MANDATORY=grounded), report odometer.
- Routing: not required → `diagnostic_cleared`; on_site → `on_site_pending`; breakdown/no action → `inspection_pending`; in_shop+recommendation → `recommendation_pending` (findings stay JSON; **tasks are created only on approval**).
- Audit: `report_filed`/`diagnostic_cleared`; notify dispatcher/controllers + original requester.

### Stage 2 — Recommendation queue (pre-approval, car stays available)

Supervisor decides: start (`→ inspection_pending`, syncFromFindings creates tasks), dismiss (terminal, reason), schedule (date), **order-parts (`→ awaiting_parts`) / parts-ready (→ back, notifies dispatcher `maint_parts_ready`)**. This is the ONLY parts-hold in the system, and it exists only before work is approved.

### Stage 3 — Dispatch & logistics

- `assign-dispatch` (`maintenance.delegate`): vendor + optional driver (must hold `maintenance.logistics`), `fault_ids[]` subset supported **backend-only** (no UI). Re-dispatch after failed QC with a garage change → admin audit notification. → `awaiting_dispatch`.
- Driver claims/pickup via logistics round-trip (`dispatched→en_route→picked_up→delivered→returned`); **pre/post odometer photos mandatory on maintenance legs**; GPS on return; ping/status replies.
- `transfer-garage`: single-garage container — conflict check (409 + acknowledge), **mandatory odometer gate**, resolved-transfer oversight (all faults fixed → reason + flag row), planned transfer defers stint handover to arrival check-in.

### Stage 4 — Garage: arrival, diagnosis, repair

- `under-repair` check-in: custody gate (only dispatching driver), receive odometer + photo, strict increase for driven legs; transferred faults become workable (`transferred→pending`); starts repair clock.
- Garage findings: append-only, source=`garage`, root cause required, duplicates rejected, promoted to tasks.
- **Workshop confirm** per fault: `confirmed` only. Confirmed + prior fix within 90 days → recurring review opens + **repair_gate=pending blocks repair** until `maintenance.recurring.manage` approves (reject = fault cancelled).
- **Incorrect** (merged path, current working tree): any-source fault, `under_repair` only, mandatory reason → fault cancelled + `marked_incorrect_*` stamp + `EVENT_TASK_MARKED_INCORRECT`. Mis-diagnosis oversight report filters to inspector-raised faults.
- Mark fixed: requires garage arrival/on-site, **resolution note + ≥1 photo/video (fix evidence)**; blocked by pending repair_gate.
- `ready`: Fix-All gate (no open faults) → `repair_review` → (video review flag off) auto → `ready_for_pickup`.

### Stage 5 — Parts (parallel system)

`part_requests` → approve/reject (`parts.investigate|maintenance.manage`) → `part_purchases` (vendor, price>0 required, duplicate-spend intelligence, `lockForUpdate`) → install (odometer, warranty_months, result) → bridges a `kind=part` line into `maintenance_line_items` when tied to a ticket. Duplicate/recurrence flags feed `part_investigations`.

### Stage 6 — Cost capture (two coexisting models)

- **A. Ticket line items** `PUT /{ticket}/line-items`: kind part/labor, qty/price > 0, **Diagnosis-First** (every line must match a finding), **variance gate** vs receipt_total.
- **B. Per-garage invoices** (`maintenance_invoices`): ticket→many invoices, fault→one invoice FK, same gates, reconcile flow; ticket roll-up = sum. Frontend complete (`InvoicesPanel`).
- Deferred cost: close with `defer_invoice` → `awaiting_invoice` + SLA clock; `POST /{ticket}/cost` for lump-sum.

### Stage 7 — Return, QC test, close

- Pickup → `in_our_park` (transient) → `ready_for_reinspection`.
- **PASS** → `close()`: per-fault `repair_inspections` verdicts, `confirmRoutineServices` (single point service data reaches the vehicle — oil/battery/tyres reminders roll forward), `composeClosingSummary` legacy note, `event_status='IN'`, condition red/yellow → **green auto-reset**, operational cascade → available → `ContractEligibilityService` clears → rentable.
- **FAIL** → `reopen()`: per-fault `still_exists` verdict (with `failure_reason`) snapshotting the failing garage, `reinspection_failures++`, faults reset to `pending`, ticket → `reinspection_failed`, back to the **supervisor's** queue (not auto same garage); repeated failure at same garage within 90 days → critical `repair_repeated_failure` alert.
- New problem found at QC → Case C: new fault created + `repair_inspections.new_issue`.

### Stage 8 — Rented again → comeback

- Eligibility: blocking statuses, mandatory-maintenance hard block (`deferrable_for_rental=false`), condition/cleaning/damage-flag checks.
- Next time same symptom reported: report-time silent flag (`recurrence_flagged`) → on workshop confirm → `recurring_fault_reviews` case (snapshot: previous garage/result/date/odometer, occurrence_count, days/distance since repair) + repair gate. Management decides one of 5 outcomes; **garage never auto-blamed**.

### Interrupts available at most stages

- **Pause for rental** (full custody handover: odometer+photo, fuel, condition, damage list, signature; resume diffs handovers → auto `MaintenanceIncident` gate).
- **Temporary release** (road test/customer/external/storage): ticket state frozen via snapshot, car NOT rentable, odometer_out/in distance absorbed.

---

# 2. Scenario walkthroughs (what happens TODAY)

### Scenario 1 — Fault confirmed, part unavailable, car can't stay at workshop
**Today:** No supported path. The ticket is in `under_repair`; `awaiting_parts` is unreachable from any active state (only from `recommendation_pending`). Options are all wrong-shaped: (a) leave ticket `under_repair` indefinitely — downtime clock runs, car occupies "in workshop" state falsely; (b) Pause-for-rental handover — frees the car but was designed for rentals, carries no part linkage, no follow-up date, no reminder; (c) Temporary Release — car leaves shop but stays non-rentable and ticket state frozen; (d) close ticket and hope someone re-opens — loses the fault entirely. Part request can be raised (`part_requests`) but **does not touch ticket state**, has no ETA field, and nothing notifies anyone when the part arrives (that notification exists only at recommendation level). Cost: purchase is only recordable as already-bought — no "ordered, awaiting delivery" state.
**Verdict: FAILS.** Missing: active-ticket `awaiting_parts` hold, part-linked release-to-fleet with safety check (deferrable faults only), ETA + arrival notification + scheduled follow-up, auto re-dispatch when part arrives.

### Scenario 2 — Vehicle leaves workshop before repair completion
**Today: PARTIALLY works.** Temporary Release covers road test/customer/external/storage: reason + taker + odometer out/in, ticket status snapshot/frozen, car explicitly NOT rentable, distance absorbed into canonical odometer, audit events. Pause-for-rental covers the rentable variant with full custody handover + incident diffing. Open faults stay open in both.
**Gaps:** No follow-up/return-due **date** with escalation (a temp release can dangle forever — no overdue alert); no safety restriction model (a `critical` open fault doesn't block a "customer" temporary release — only the mandatory/deferrable flag gates *rentals*, not releases); no driver-facing notification of open faults/restrictions on the released car; responsibility = `taken_by` free-ish field, not an accountable user link.

### Scenario 3 — Part replacement workflow (brake pads)
**Today:** request→approve→purchase→install spine exists and is well-audited. Checklist: part number ✅, supplier ✅ (purchase only), purchase cost ✅, installed date ✅, mileage at install ✅, quantity ✅, technician ⚠️ (`installed_by` = logging actor, not physical tech), labor cost ❌ (install never creates a labor line), warranty ⚠️ (`warranty_months` on line item only; **`warranty_until` never populated by `installPurchase`** — likely bug; nothing on `part_purchases`; nothing for non-ticket buys), old-part disposition ❌, before/after photos ❌, ETA/order tracking ❌, inventory ❌.
**Verdict: ~60%.**

### Scenario 4 — Same fault returns after 10 days (AC compressor)
**Today: the best-covered scenario.** Report-time flag → workshop confirm → `recurring_fault_reviews` with previous garage/result/date/odometer/occurrence_count + `repair_gate` blocking a second spend until managerial approval; `repair_inspections.still_exists` at QC raises escalating alerts; Foresight's episode grouping catches chronic patterns.
**Gaps:** detection = `category_key` else **exact lowercase symptom string** ("AC not cooling" ≠ "AC weak") within a hard 90-day window (day 91 = silence); **warranty never consulted** — no "part still under warranty → garage/supplier owes free re-repair" outcome, no supplier-claim tracking; technician quality attribution ends at garage level (no technician entity); three detectors can disagree.

### Scenario 5 — Incorrect diagnosis (engine → actually battery)
**Today:** With the uncommitted merge: delegate marks the fault **Incorrect** (any source, `under_repair` only, mandatory reason) → cancelled + `marked_incorrect_*` audit stamp + oversight report (per-inspector tally).
**Gaps:** the old `addDifferentFault` (+`derived_from_task_id` "turned out to be" linkage) was deleted with **no replacement** — the corrected diagnosis ("actually battery") is now only recordable as an unlinked new garage finding, breaking the causal chain; Incorrect is unavailable outside `under_repair` (an incorrect diagnosis discovered at re-inspection or after close can't be recorded as such); the oversight report ignores garage-source misdiagnoses by design (fine for inspector accountability, but garage misdiagnosis quality is untracked).

### Scenario 6 — Preventive vs reactive separation
**Today: GOOD.** Distinguished across three orthogonal axes: `trigger_reason` (periodic/customer_reported/test_drive + breakdown & inspector_pickup as separate paths), `maintenance_type` (routine/breakdown/ins_incident/non_ins_incident/modification/upgrade with role-gated admin types), `visit_context` (routine excluded from foresight failure signals). Accidents route to a separate Damage & Accidents system. Foresight provides genuine preventive triggers (km intervals, battery age, chronic episodes).
**Gaps:** accident damage never becomes a workflow ticket (repair of accident damage is untracked by this engine); no unified preventive **schedule** (planned services aren't future-dated work orders — only foresight suggestions + `scheduleRecommendation` date); `high` severity is defined but no intake path ever assigns it (complaint defaults `moderate`, breakdown forces `critical`).

---

# 3. Missing business cases (consolidated)

**Parts & availability**
1. Awaiting-parts on an ACTIVE ticket (mid-repair part wait) — no state, no linkage.
2. Release-to-fleet while waiting for a part, with scheduled follow-up + auto re-dispatch on arrival.
3. Part order tracking: ordered_at / ETA / received_at — purchase only records "already bought".
4. Part-arrival notification tied to `part_request`/`part_purchase` (exists only at recommendation level).
5. Inventory/stock: no stock levels, reservation, or reuse of previously bought parts.
6. Labor cost on part install (install creates only a `kind=part` line).
7. Old-part disposition (core return / scrap / warranty return).
8. Before/after photos on parts work.
9. ~~`warranty_until` not populated by `installPurchase`~~ **CORRECTED 2026-07-23:** `MaintenanceLineItem::booted()` (model :77–84) auto-derives `warranty_until = installed_on + warranty_months` on every save, and `installPurchase` sets both inputs — so the column IS populated. Remaining true gaps: no warranty column on `part_purchases` itself, and no warranty at all for non-ticket (customer) purchases.
10. Supplier/vendor at request stage (only at purchase).

**Warranty & comeback**
11. Warranty-aware recurrence: no check of `warranty_until` when a fault returns; no warranty-repair (zero-cost, garage/supplier-liable) outcome; no supplier claim record.
12. Recurrence window is a hard 90-day cliff; `days/distance_since_repair` recorded but never thresholded.
13. Symptom matching is exact-string; no category backfill/normalization; three detectors with divergent rules.
14. No technician entity — quality attribution stops at garage.

**Workflow completeness**
15. "Real fault was X" linkage lost: `addDifferentFault`/`derived_from_task_id` deleted with no replacement in the Incorrect merge.
16. Incorrect only available in `under_repair` — mis-diagnosis discovered at QC or post-close unrecordable as such.
17. Split-dispatch frontend missing: no `fault_ids` picker, Pending-Assignment queue computed but never rendered; `assign-pending` endpoint has zero callers.
18. Temporary Release has no due-back date / overdue escalation; no safety gate vs critical open faults; no driver notification of open faults.
19. Accident damage never enters the repair workflow (no ticket, no cost, no QC).
20. No future-dated planned work orders (preventive schedule) — only recommendations + foresight hints.
21. `high` fault severity effectively unreachable at intake.
22. Legacy verdicts (`not_found`, `different_cause`) still accepted by API validation though UI offers only `confirmed`; `needs_diagnosis` referenced in a migration docblock but never defined.

**Cost integrity**
23. No guard against mixing ticket-level line items AND per-invoice line items on one ticket (`recalcLineItemTotals` sums all → double-count risk).
24. Customer part purchases (no ticket) carry cost only on `part_purchases` — invisible to vehicle cost roll-ups and warranty.
25. `maintenance_invoice_id` not in `MaintenanceTask::$fillable` — silent drop on mass-assignment paths.

**Minor**
26. Mis-diagnosis oversight page unlinked from sidebar (route alive).
27. Garage-source misdiagnoses excluded from oversight (accepted design, but garage quality untracked).
28. `recordGarageTransferOdometer` mutates `odometer_flags` in memory on the planned-transfer branch — verify persistence.

---

# 4. Recommended workflow design

## 4.1 New/changed statuses

**Ticket:** add `awaiting_parts_active` reachable from `under_repair` (and `on_site_pending`), returning to `under_repair` on parts-ready. Add optional `released_pending_parts` (car back in fleet, repair scheduled) reachable from `awaiting_parts_active` **only when every open fault is `deferrable_for_rental`-safe**; exits to `inspection_pending` (re-dispatch) when the part arrives.

**Fault:** add `awaiting_parts` task status (or a `blocked_reason` column: `parts|approval|customer`) so per-fault progress shows *why* nothing is moving. Restore a `superseded` linkage: Incorrect optionally accepts `actual_fault` payload creating the corrected task with `derived_from_task_id` (reuse the existing column).

**Part purchase:** add order sub-state: `ordered → received → installed` with `ordered_at`, `expected_at` (ETA), `received_at`. `received` fires notification to the ticket's dispatcher + any `released_pending_parts` follow-up.

## 4.2 Key transitions

```
under_repair --order part needed--> awaiting_parts_active   (requires open part_request linked to a fault)
awaiting_parts_active --parts received--> under_repair       (auto-notify garage + driver)
awaiting_parts_active --release (all faults deferrable)--> released_pending_parts  (custody handover, car rentable)
released_pending_parts --parts received--> inspection_pending (auto re-dispatch card, supervisor queue)
```

## 4.3 Permissions

Reuse existing groups: enter/exit parts-hold = `maintenance.delegate`; release-to-fleet while waiting = `maintenance.manage` (it changes rentability); warranty-claim decision = `maintenance.recurring.manage` (same authority as the money guard). New `parts.receive` (or fold into `parts.investigate`) for marking ordered parts received.

## 4.4 Required fields

- Parts hold: linked `part_request_id`(s), `expected_at` (mandatory when entering the hold), free note.
- Release-pending-parts: full custody handover payload (reuse `MaintenanceHandover`), follow-up date (default = ETA + 2 days), responsible user id.
- Part install: `labor_cost`/`labor_hours` (optional, creates `kind=labor` line), `old_part_disposition` enum (`scrapped|returned_core|warranty_return|kept`), before/after photo refs (reuse `maintenance_media`), **fix `warranty_until` derivation**.
- Warranty comeback: on `recurring_fault_reviews`, add `in_warranty` bool + `warranty_line_item_id` computed at open (match part line items on the previous task where `warranty_until >= today`); add decision `warranty_claim` (repair proceeds at zero customer cost, liable party = previous garage or supplier).
- Temporary release: `due_back_at` (mandatory), overdue notification scan; block release reasons `customer` when any open fault is `critical` unless manager override w/ reason.

## 4.5 Audit events

`EVENT_PARTS_HOLD` / `EVENT_PARTS_RECEIVED` / `EVENT_RELEASED_PENDING_PARTS` / `EVENT_PARTS_FOLLOWUP_DUE`, `EVENT_WARRANTY_CLAIM_OPENED/DECIDED`, `EVENT_TEMP_RELEASE_OVERDUE`, `EVENT_FAULT_SUPERSEDED` (Incorrect→actual fault). All via existing `VehicleLogService`.

## 4.6 Detection hardening

Unify the three recurrence detectors on one service: match on `category_key` (backfill it from the findings catalog for all tasks — the catalog already keys symptoms), keep symptom-string as fallback; replace the 90-day cliff with tiers (≤90d auto-review; 91–180d flagged-only) and add a warranty override (any return inside `warranty_until` = review regardless of window).

---

# 5. Complete test plan

Legend: **Init** = initial state, **Exp** = expected result, **DB** = database changes, **API** = API work required (N = new endpoint/field, C = change, E = exists).

### A. Parts availability (Scenario 1)

| # | Test | Init | Steps | Exp | DB | API |
|---|---|---|---|---|---|---|
| A1 | Enter parts hold mid-repair | Ticket `under_repair`, fault confirmed, part_request `approved` linked to fault | POST `/{t}/parts-hold` {part_request_ids, expected_at} | 200; ticket→`awaiting_parts_active`; downtime clock annotated; dispatcher notified | `workflow_status`, `vehicle_log_events` parts_hold | N |
| A2 | Hold rejected without linked request | Same, no part_request | Same call | 422 "link a part request first" | none | N |
| A3 | Parts received resumes repair | A1 state; purchase `ordered` | POST `/part-purchases/{p}/received` | purchase→`received`; ticket auto→`under_repair`; garage+driver notified | `received_at`; status; log event | N |
| A4 | Release to fleet while waiting — allowed | `awaiting_parts_active`, ALL open faults deferrable | POST `/{t}/release-pending-parts` {handover payload, follow_up} | ticket→`released_pending_parts`; car rentable (eligibility passes); handover row | `maintenance_handovers`, status, cascade | N |
| A5 | Release blocked by mandatory fault | Same but one fault `deferrable_for_rental=false` | Same call | 422 hard block naming the fault | none | N |
| A6 | Arrival auto re-dispatch | A4 state; part marked received | — (event-driven) | ticket→`inspection_pending`; supervisor card + notification; follow-up cleared | status, notification | N |
| A7 | ETA overdue reminder | A1/A4, `expected_at` passed | run notifications:scan | `parts_overdue` notification to delegate | notifications row | N (detector) |

### B. Temporary release & early exit (Scenario 2)

| # | Test | Init | Steps | Exp | DB | API |
|---|---|---|---|---|---|---|
| B1 | Temp release happy path (regression) | `under_repair`, releasable | POST temporary-release {reason, taken_by, odometer} | ticket frozen via snapshot; car not rentable; `active_temporary_release_id` set | E — regression | E |
| B2 | Due-back overdue escalation | Release with `due_back_at` past | scan | overdue notification + `EVENT_TEMP_RELEASE_OVERDUE` | notifications | N (field+detector) |
| B3 | Critical-fault safety gate | Open `critical` fault; reason=customer | POST temporary-release | 422 unless manager override+reason; override audited | log event | C |
| B4 | Return absorbs distance (regression) | Active release | POST return-from-release {return_odometer} | distance computed; vehicle odometer advanced; backward reading rejected | E | E |
| B5 | Rental attempt during release | Active release | ContractEligibility assess | BLOCK (not rentable) | E | E |

### C. Part replacement completeness (Scenario 3)

| # | Test | Init | Steps | Exp | DB | API |
|---|---|---|---|---|---|---|
| C1 | Full spine regression | fault confirmed | request→approve→purchase→install | line item kind=part with part_number, qty, unit_price, installed_on, installed_odometer, warranty_months | E | E |
| C2 | warranty_until derivation (regression) | Install with warranty_months=6 | install | line item `warranty_until = installed_on + 6mo` NOT NULL (auto via model saving hook) | E | E |
| C3 | Labor on install | install with labor_cost/hours | install | second line `kind=labor` linked to same fault | new columns/line | C |
| C4 | Old-part disposition | install | `old_part_disposition=warranty_return` | stored + shows in part history | new column | C |
| C5 | Before/after photos | install with 2 media | install | media rows linked (maintenance_media or new pivot) | new linkage | C |
| C6 | Non-AED purchase (regression) | currency=USD | install | cost 0 + note, no silent conversion | E | E |
| C7 | Customer purchase w/o ticket | no maintenance_id | install | no line item; cost visible in part history report | E (document) | E |

### D. Recurrence & warranty (Scenario 4)

| # | Test | Init | Steps | Exp | DB | API |
|---|---|---|---|---|---|---|
| D1 | 10-day comeback (regression) | AC fault completed 10d ago, same category | new ticket → confirm fault | `recurrence_flagged`; review opens with snapshot; `repair_gate=pending` blocks fix | E | E |
| D2 | Reworded symptom caught | Prior "AC not cooling" (category ac); new "AC weak airflow" (category ac) | confirm | review opens (category match) — verify; then remove category → document string-match miss | detector | C |
| D3 | Day-95 return | Prior fix 95d ago | confirm | today: silence (document); target: flagged-only tier | detector | C |
| D4 | Warranty comeback | Part line `warranty_until` future; same fault returns | confirm | review carries `in_warranty=true` + matched line; `warranty_claim` decision available; approving sets expected cost 0 / liable party | new columns + decision | N |
| D5 | Repeated QC failure alert (regression) | still_exists twice same garage ≤90d | reopen | critical `repair_repeated_failure` to delegate+manage | E | E |
| D6 | Gate reject cancels fault (regression) | D1 state | repair-approval reject | fault cancelled, no spend possible | E | E |

### E. Incorrect diagnosis (Scenario 5)

| # | Test | Init | Steps | Exp | DB | API |
|---|---|---|---|---|---|---|
| E1 | Incorrect any-source (regression, new merge) | garage-source fault, `under_repair` | POST /incorrect {reason} | cancelled + `marked_incorrect_*`; siblings untouched; ticket status unchanged | E (uncommitted) | E |
| E2 | Reason mandatory | same | POST without reason | 422 | none | E |
| E3 | Supersede link | Incorrect with `actual_fault:{symptom, root_cause_id}` | POST | new task created, `derived_from_task_id` = incorrect task; audit `fault_superseded` | reuse column | N |
| E4 | Incorrect outside under_repair | fault at `ready_for_reinspection` | POST /incorrect | today 422 (document); target: allowed with extended guard OR explicit post-QC dispute path | — | C (decide) |
| E5 | Oversight tally (regression) | ≥1 inspector-source incorrect | GET misdiagnoses | per-inspector counts; garage-source excluded | E | E |

### F. State machine & cost integrity

| # | Test | Init | Steps | Exp | DB | API |
|---|---|---|---|---|---|---|
| F1 | Illegal transition fuzz | each status | attempt every non-listed transition | all rejected `WorkflowTransitionException`, no partial writes | none | E |
| F2 | Mixed cost models guarded | ticket with 1 invoice | PUT ticket-level `/line-items` | target: 422 "use invoices"; today: document double-count | guard | C |
| F3 | Split-dispatch UI | ticket 3 faults | dispatch with 2 selected in UI | third shows Pending Assignment; assign-pending works | E backend | frontend |
| F4 | Fix-All gate (regression) | 1 open fault | POST /ready | 422 | E | E |
| F5 | Close→rentable chain (regression) | pass reinspection | close | condition→green, cascade→available, eligibility passes, service reminders rolled | E | E |
| F6 | Double close race (regression) | two parallel close calls | — | one wins (lockForUpdate), second 4xx | E | E |
| F7 | Legacy verdicts rejected | confirm with `not_found` | POST /confirm | target: 422 (tighten validation to `confirmed` only at API) | validation | C |

---

# 6. Priority classification

**P0 — critical for production**
- Active-ticket `awaiting_parts_active` + parts-received resume (A1–A3): today a stuck part silently corrupts downtime metrics and workshop truth.
- Mixed cost-model guard (F2) — financial double-count risk.
- Release-pending-parts safety gate = mandatory-fault hard block (A5) — never let a grounded-class fault back into the rental pool.
- Tighten confirm API to `confirmed` only (F7) + commit the Incorrect merge (already in working tree).

**P1 — important**
- Release-to-fleet-pending-parts flow + auto re-dispatch on arrival (A4, A6).
- Part order tracking: ordered/ETA/received + overdue reminder (A7, part of A3).
- Warranty-aware comeback (`in_warranty`, `warranty_claim` decision) (D4).
- Supersede link restoring "real fault was X" (E3).
- Split-dispatch frontend or removal of dormant backend (F3).
- Temporary release `due_back_at` + overdue escalation + critical-fault gate (B2, B3).
- Labor line on part install (C3).

**P2 — improvement**
- Category-key backfill + unified recurrence detector + tiered window (D2, D3).
- Old-part disposition + before/after photos on installs (C4, C5).
- Incorrect-outside-under_repair decision (E4).
- Vendor at part-request stage; `installed_by` vs physical technician distinction.
- Accident damage → workflow ticket bridge (business case 19).
- `maintenance_invoice_id` into `$fillable`; sidebar link for misdiagnoses; `odometer_flags` persistence check on planned transfer.

**P3 — future intelligence**
- Parts inventory/stock with reservation and reuse suggestions.
- Technician entity + per-technician quality analytics.
- Supplier scorecards (warranty-claim rate, part-failure rate feeding `part_investigations`).
- Preventive maintenance scheduler: future-dated work orders generated from foresight signals with capacity planning.
- Predictive parts pre-ordering from chronic-fault + interval data.

---

# PART II — Vehicle Asset Inventory / Component History layer

**Added 2026-07-23.** Architectural layer to be designed BEFORE implementing the Part I workflow fixes, because the parts-workflow P0/P1 items (awaiting-parts, install flow, warranty comeback, old-part disposition) all write into this layer once it exists.

> **Superseded as spec:** the full architecture decision document (final schema, state machines, migration phases, acceptance scenarios) now lives in `docs/Asset-Layer-Architecture.md`. This section remains as the gap analysis that motivated it.

## 7. Concept and separation of concerns

Three entities that must never be conflated:

| Concept | What it is | Lives in | Today's proxy |
|---|---|---|---|
| **Maintenance ticket** | An event/problem (something happened) | `maintenances` + `maintenance_tasks` | ✅ exists (Part I) |
| **Component** | A physical asset with identity and its own lifecycle, currently *somewhere* (on a vehicle, in storage, disposed) | **NEW: `vehicle_components` (+ catalog, events)** | ❌ only event-shaped `maintenance_line_items` / `part_purchases` rows |
| **Service** | A performed action (labor: oil change, alignment, inspection, repair work) | **NEW: `service_records`** (unifying) | ⚠️ scattered: `invoice_items` Technical Service Log, `kind=labor` line items, `ServiceReminder` anchors, `repair_hours` on findings |

The vehicle becomes a **container**: current components + component history + service history, answerable at any moment and exportable at sale.

## 7.1 What exists today (mapping, verified against code)

| Existing surface | What it gives us | Why it's not an asset ledger |
|---|---|---|
| `maintenance_line_items` (kind=part) — `part_number`, `quantity`, `unit_price`, `installed_on`, `installed_odometer`, `warranty_months`, `warranty_until` (auto-derived by saving hook :77–84), tyre fields `tire_brand/tire_dot/tire_tread_mm`, `vehicle_id` | The closest thing to a component record; already vehicle-scoped | It's a *billing line*, not an asset: no serial/identity, no removal/disposition, no current-vs-historical distinction, duplicated when re-billed |
| `part_purchases` (+ install leg: `installed_by/at/odometer`, `result`, `maintenance_line_item_id`) | Procurement provenance: supplier (`source_vendor_id`), price, quantity | Lifecycle ENDS at `installed` — no active/removed/disposed states, no cross-vehicle movement |
| `invoice_items` + `VehicleController::serviceHistory` (:117) with `last_by_category` | Money-free Technical Service Log per vehicle | Description strings only — no component linkage, no technician, no duration |
| `VehicleController::tireHistory` (:198) | A working proto-**component history** for one component class (tyres) | Read-only inference over line items; proves demand for the generalized layer |
| `ServiceReminder` + `Vehicle::recordServiceDone` (fed ONLY by `confirmRoutineServices` on re-inspection PASS) | Service anchors (oil/battery) driving due-date intelligence | Tracks *when last done*, not *what is installed* |
| `maintenance_media` | Photo evidence infra | Not linked to parts/components |
| `Vehicle` statuses `sold`/`disposed` (:28–29) | Sale/disposal exists as a vehicle state | No asset export, components silently vanish with the car |

**Conclusion:** FleetView records *that a part was bought and billed*, never *that a specific physical component is on a specific vehicle right now*. Nothing answers "where is the old battery?", "which tyres are on car B and where were they before?", or "export this car's installed assets at sale."

## 7.2 Proposed data model

Evaluation of the proposed tables — recommendation: **four tables, not five.** A separate `removed_components` table is an anti-pattern (moving rows loses FK continuity and breaks "full component history" queries); removal is a *status + event*, not a different entity.

### `component_catalog` — what kinds of components exist (the type, not the instance)
- `id`, `category_key` (aligns with the existing findings-catalog keys: `tyres`, `brakes`, `electrical/battery`, `ac`, `engine`, …), `name` ("Front brake pads"), `default_part_number`, `tracking_mode` enum: **`serialized`** (engine, battery, AC compressor, alternator — individual identity) | **`batch`** (brake pads, filters, tyres-as-set — qty tracked, no serial) | **`consumable`** (oil, coolant — service-only, never a component instance), `default_warranty_months`, `expected_life_km` / `expected_life_months` (feeds foresight later), `is_active`.
- Seeded from `config/maintenance_findings.php` categories + `PartRequest::CLASSES` (consumable/standard/major maps cleanly onto tracking_mode).

### `vehicle_components` — the physical instance (the container's content)
- Identity: `id`, `component_catalog_id`, `serial_no` (nullable; required when catalog `serialized`), `part_number`, `brand`, `quantity` (for batch), `position` (nullable: `front_left`, `rear`, … — needed for tyres/brakes).
- Location: `vehicle_id` **nullable** (null = not on a vehicle), `location` enum: `installed` | `in_storage` | `disposed` | `returned_supplier` | `sold` | `refurbishing`.
- Status: `status` enum: `active` | `removed` | `retired` (retired = disposed/sold/returned terminal).
- Install leg: `installed_at`, `installed_odometer`, `installed_by` (+ `technician_name` free text until a technician entity exists), `supplier_vendor_id`, `purchase_cost`, `warranty_months`, `warranty_until`, `source_part_purchase_id` FK (provenance), `source_line_item_id` FK (billing linkage).
- Removal leg (filled in place, row never moves): `removed_at`, `removed_odometer`, `removed_by`, `removal_reason` enum (`worn_out` | `failed` | `damaged` | `upgraded` | `recalled` | `moved_to_other_vehicle` | `vehicle_sold`), `removal_note`, `replaced_by_component_id` (self-FK → the successor), `disposition` enum (`scrapped` | `stored_spare` | `returned_supplier` | `sold` | `warranty_return` | `refurbished` | `transferred`).
- Derived (read models, not columns): `life_km = removed_odometer − installed_odometer`, `life_days`.

"Current installed components" = `WHERE vehicle_id = ? AND status = 'active'`. "Component history" = `WHERE vehicle_id = ? AND status != 'active'` **plus** rows whose `component_events` include this vehicle (for parts that moved away).

### `component_events` — the movement/audit ledger (answers Scenario 2)
Append-only: `id`, `vehicle_component_id`, `event` enum (`purchased` | `stored` | `installed` | `removed` | `transferred` | `disposed` | `returned` | `sold` | `refurbished` | `warranty_claimed`), `from_vehicle_id`, `to_vehicle_id`, `odometer`, `maintenance_id` (the ticket that caused it, nullable — storage moves have none), `maintenance_task_id` (the fault, nullable), `actor_id/name`, `at`, `note`, `meta` JSON.
A tyre moved A→B = one component row, two events (`removed` from A + `installed` on B, or a single `transferred` with both vehicle FKs) — full history is a simple event query. Mirror each event into `vehicle_log_events` (`EVENT_COMPONENT_INSTALLED` / `EVENT_COMPONENT_REMOVED` / `EVENT_COMPONENT_TRANSFERRED`) so the Vehicle Timeline shows asset movements without joining a new table.

### `service_records` — performed actions (labor), unified
- `id`, `vehicle_id`, `maintenance_id` (nullable — standalone inspections/cleaning allowed), `maintenance_task_id` (nullable), `service_type` (aligned with `ServiceReminder` types + findings categories), `description`, `performed_at`, `odometer`, `workshop_vendor_id`, `technician_name`, `labor_cost`, `duration_hours`, `result` (`completed` | `partial` | `failed`), `media` via `maintenance_media` linkage, `source` (`workflow_close` | `invoice_import` | `manual` | `legacy_backfill`).
- **Write path stays ticket-governed:** `confirmRoutineServices` (the single re-inspection-PASS point) becomes the writer of workflow-sourced `service_records`, alongside its existing `recordServiceDone` anchor updates. `invoice_items` Technical Service Log rows are backfilled as `source=invoice_import`. `serviceHistory`/`last_by_category` endpoints re-point to this table (keeping response shape).

**No `removed_components` table** — covered by `vehicle_components.status='removed'` + `disposition` + events, preserving one immutable identity per physical asset.

## 7.3 Lifecycle & integration with the Part I workflow

```
part_request → approved → part_purchase (ordered → received)          [procurement — existing + Part I §4.1]
                                   │ installPurchase (extended)
                                   ▼
                    vehicle_components row created (status=active, location=installed)
                    + component_events: installed
                    + predecessor auto-closed: status=removed, removed_* stamped,
                      MANDATORY disposition prompt ("where is the old one?"),
                      replaced_by_component_id linked
                    + maintenance_line_items part line (unchanged — billing)
                    + optional kind=labor line → service_records row
                                   │
                            active on vehicle
                    ┌──────────────┼───────────────────┐
              removed (repair)  transferred (A→B)   vehicle sold
                    │              │                   │
             disposition       events on both      asset export +
             required          vehicle timelines   components → location=sold OR
                                                   detached to in_storage (choice at sale)
```

Integration points (all extend existing code, no parallel flow):
1. **`PartWorkflowService::installPurchase`** becomes the single component write-point for workflow installs: creates the `vehicle_components` row, closes the predecessor (matched by catalog + position on the same vehicle), links `source_part_purchase_id`/`source_line_item_id`. The Part I gaps (labor line, old-part disposition, before/after photos) land HERE — disposition is the predecessor's `disposition` field; photos attach via `maintenance_media` to the component event.
2. **Warranty comeback (Part I D4)** reads `vehicle_components.warranty_until` of the ACTIVE component matching the recurring fault's category — a cleaner join than scanning line items.
3. **Recurrence/foresight**: `expected_life_km` on the catalog + `life_km` actuals per component feed part-durability and supplier-quality intelligence (`part_investigations` gets real denominators).
4. **Ticket independence preserved:** components/services reference tickets (nullable FKs) but never drive `workflow_status`; a storage-to-storage move or a spare-part sale involves no ticket at all.

## 7.4 Scenario coverage

**Scenario 1 — battery replaced, where is the old battery?** Removal cannot complete without `disposition` ∈ scrapped / stored_spare / returned_supplier / sold / warranty_return. `stored_spare` sets `vehicle_id=null, location=in_storage` — it appears in a Spares inventory view and can be re-installed later (new `installed` event, full provenance kept). `returned_supplier` + warranty_until still valid → candidate `warranty_claimed` event (bridges to Part I warranty-claim design).

**Scenario 2 — tyre moved from Vehicle A to B.** One `vehicle_components` row (identity: brand+DOT, position), `transferred` event with `from_vehicle_id=A, to_vehicle_id=B`, odometer captured on both sides. Both vehicles' timelines show it; the tyre's own event list is its full biography. Existing `tireHistory` endpoint re-points to components and finally becomes accurate across swaps.

**Scenario 3 — vehicle sold: asset export.** New `GET /Vehicle/{id}/asset-export`: current installed components (with warranty remaining), component history (installed/removed/why/disposition), service history, open faults — one JSON/PDF pack. On marking the vehicle `sold`: prompt per active component — sell WITH car (`location=sold`, retired) or strip to storage first. Components never silently vanish.

## 7.5 Backfill strategy (day one isn't empty)

- `component_catalog` ← findings categories + part classes.
- `vehicle_components` ← replay `part_purchases` with an install leg (best data: supplier, cost, odometer, warranty) then `maintenance_line_items` `kind=part` rows not already covered (`source=legacy_backfill`; tyre lines carry brand/DOT). Only the LATEST line per vehicle+catalog(+position) becomes `active`; earlier ones become `removed` with `removal_reason=worn_out(assumed)`, `disposition=unknown_legacy` (an explicit enum value — honest about pre-layer data, per the treat-data-as-source-of-truth rule).
- `service_records` ← `invoice_items` (source=invoice_import) + closed tickets' completed routine tasks (source=legacy_backfill).

## 7.6 Additional test cases (extends §5)

| # | Test | Init | Steps | Exp | DB | API |
|---|---|---|---|---|---|---|
| G1 | Install creates component + closes predecessor | Active "front brake pads" component; new pads purchased on ticket | installPurchase w/ position=front | old row status=removed + removed_at/odometer + `replaced_by_component_id`; new row active; 2 component_events; timeline events | vehicle_components ×2, events | N |
| G2 | Disposition mandatory on removal | G1 without disposition | install | 422 "where is the old part?" | none | N |
| G3 | Serialized catalog requires serial | Battery (serialized) install without serial_no | install | 422 | none | N |
| G4 | Stored spare re-installed | Battery in_storage | install on Vehicle B from spares | same component row → active on B; events chain purchased→installed→removed→stored→installed | events | N |
| G5 | Tyre transfer A→B | Active tyre on A | POST component transfer {to_vehicle, odometers} | transferred event both timelines; A history + B current both correct | events + row update | N |
| G6 | Transfer needs no ticket | same | transfer without maintenance_id | allowed; maintenance_id null | — | N |
| G7 | Asset export on sale | Vehicle with 4 components + services | GET asset-export | complete pack: current, history, services, warranties remaining | read-only | N |
| G8 | Sale disposition prompt | Mark vehicle sold | — | per-component choice sell-with-car vs strip-to-storage; none left dangling `active` on a sold car | rows updated | N |
| G9 | Service record on close (regression+) | Routine oil ticket passes re-inspection | close | `service_records` row (source=workflow_close) AND ServiceReminder rolled (existing) | service_records | N |
| G10 | Warranty comeback via component | Active component warranty_until future; same-category fault confirmed | confirm | recurring review carries component + in_warranty (replaces line-item scan of D4) | join change | C |
| G11 | Backfill idempotent & honest | Legacy line items | run backfill twice | no dupes; legacy rows flagged unknown_legacy; latest-per-slot active | backfill cmd | N |
| G12 | Consumables never become components | Oil change line | install/close | service_record only, no vehicle_components row | guard by tracking_mode | N |

## 7.7 Priority integration (revised sequencing)

The asset layer changes the Part I build order — parts-related P0/P1 items should write into `vehicle_components` from day one rather than being retrofitted:

- **P0 (unchanged):** active-ticket `awaiting_parts_active` (A1–A3), mixed cost-model guard (F2), mandatory-fault release block (A5), confirm-API tightening (F7). None depend on the asset layer — ship first.
- **P1 (new, before other parts P1s):** asset-layer foundation = 4 tables + `installPurchase` integration + disposition prompt + backfill command (G1–G3, G11, G12). Then the Part I parts P1s (labor line C3, disposition C4, photos C5, warranty comeback D4) land ON this layer instead of on line items.
- **P1:** component transfer + spares inventory (G4–G6); service_records unification with serviceHistory re-point (G9).
- **P2:** asset export + sale disposition flow (G7, G8); warranty comeback re-based on components (G10); tireHistory re-point.
- **P3:** expected-life analytics (catalog life vs actual life_km), supplier scorecards from component failures, spares-aware purchase suggestions ("a compatible battery is in storage"), technician entity attribution.

**Explainability note:** register a `ComponentExplainer` in the shared DAG engine (app/Services/Explainability) so any component figure (life_km, warranty state) traces to its source events — consistent with the platform rule that every Intelligence figure explains to the original record.

---

*Sources: `Maintenance.php`, `MaintenanceTask.php`, `MaintenanceWorkflowService.php`, `MaintenanceWorkflowController.php`, `MaintenanceTaskService.php`, `RecurringFaultService.php`, `RepairInspectionService.php`, `PartWorkflowService.php`, `MaintenanceInvoiceService.php`, `LogisticsDispatchService.php`, `ContractEligibilityService.php`, `MaintenanceForesightService.php`, migrations 2026-06/07, `routes/api.php`, `TaskRoutingModal.js`, `InvoicesPanel.js`, `MaintenanceRecommendations.js`.*
