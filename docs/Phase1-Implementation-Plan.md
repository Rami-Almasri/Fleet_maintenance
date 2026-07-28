# Phase 1 — Implementation Plan (file-by-file)

**Companion to** `Maintenance-Procurement-Platform-Architecture.md` (frozen blueprint).
**Status:** plan for approval — no code yet.
**Governs:** Addendum E. Every step below cites the Scenario/Invariant it advances.

## Phase 1 scope (from D5)

The connected foundation on the **existing single-supplier purchase** (no RFQ yet):
`WorkflowStateResolver` · `MaintenanceDelayResolver` · event layer + `OperationalStateProjector`
· delivery tracking on `part_purchases` · derived **"Waiting for Parts"** · Timeline /
Notifications / Dashboard / Ops Center / CarStatus synchronized · Checkpoint form derives delay.

**Explicitly deferred (Phase 2/3, additive, no structural change):** `part_rfqs` / `rfq_lines`
/ `supplier_quotes`, the `sourcing` status + `SourcingPolicy` / `SupplierSelectionPolicy`,
`repair_hold_mode` + `RepairContinuationPolicy`, partial-delivery qty pair, `decision_log`,
extraction of `EscalationPolicy` / `ServiceEntryPolicy` into standalone Policy classes.

**Execution-mapping note (not an architecture change):** Phase 1 keeps the existing
`part_requests` statuses (`requested → under_review → approved → purchased → installed →
completed`). The blueprint's `sourcing`/`ordered` names arrive with RFQ in Phase 2. The
derived fulfillment sub-state (**awaiting-delivery / received**) is computed from
`part_purchases` timestamps, so no status rename is needed now. `ordered_at ≡` existing
`purchased_at`.

---

## Step 0 — Event infrastructure (inert scaffolding)

- **New:** `backend/app/Events/` — Phase-1 event classes: `FaultIdentified`,
  `PartRequirementRaised`, `PartRequestApproved`, `PartRequestRejected`, `PurchaseOrderIssued`,
  `PartDelivered`, `PartDeliveryDelayed`, `PartInstalled`, `MaintenanceLaneChanged`,
  `RepairBlockedOnParts`, `RepairResumed`, `VehicleOperationalStateChanged`.
  Each is a plain payload object (subject ids + before/after where relevant).
- **New:** `backend/app/Providers/EventServiceProvider.php`; register it (Laravel bootstrap
  providers). No listeners bound yet.
- **Changed:** none behaviourally.
- **Events/Listeners added:** the events above; zero listeners.
- **Services created:** none.
- **Validation:** `php artisan event:list` shows them; a unit test dispatches each event and
  asserts it constructs; full suite green; **no behaviour change** anywhere.
- **Commit maps to:** foundation for all Scenarios. Upholds Invariant 3 (single event contract).

## Step 1 — Migration: delivery tracking

- **New migration:** add to `part_purchases`: `expected_delivery_date` (date, null),
  `delivered_at` (datetime, null). Both nullable → no backfill.
- **`operational_status` (per P1-D1):** Phase 1 does **not** touch `vehicles.operational_status`
  — no new column, and no write. It is neither read as SoT nor written by the projector. The
  legacy multi-writer column is left exactly as-is (recorded as migration debt).
- **Changed:** `backend/app/Models/PartPurchase.php` — add the two to `$fillable`/`$casts`.
- **Validation:** migrate up/down clean; existing part/purchase feature tests still green
  (columns unused so far).
- **Commit maps to:** Scenarios 1,2,7 (delivery timestamps). Invariant 6 (cost untouched).

## Step 2 — `WorkflowStateResolver` (pure derivation, read-only)

- **New:** `backend/app/Services/State/WorkflowStateResolver.php`.
  `effectiveState(Maintenance $ticket): OperationalState` → `{ service_axis ∈
  {healthy, in_transit, in_workshop_active, blocked_waiting_parts, partially_blocked,
  awaiting_return}, blocking_parts[], is_blocked, is_partial }`. Reads active `tasks` →
  `tasks.partRequests` → their `part_purchases` (`installed_at` null; `delivered_at` null ⇒
  still awaiting). **No writes, no events.**
- **Changed:** `backend/app/Models/Maintenance.php` — add read-only `partRequests()`
  (hasManyThrough tasks) convenience (review noted it's missing).
- **Services created:** `WorkflowStateResolver`.
- **Validation (unit):** fixtures → (a) fault + un-delivered part ⇒ `blocked_waiting_parts`;
  (b) delivered-not-installed ⇒ not blocked; (c) 2 faults, 1 blocked ⇒ `partially_blocked`;
  (d) no parts ⇒ `active`. Deterministic: same inputs → same output.
- **Commit maps to:** Scenarios 1,2,3 derivation. Invariant 2 (one derived state).

## Step 3 — `MaintenanceDelayResolver` (pure derivation)

- **New:** `backend/app/Services/State/MaintenanceDelayResolver.php`.
  `delay(Maintenance $ticket)` → `{ reason, headline, supplier, eta, days_waiting,
  blocking_parts[] }`, using `WorkflowStateResolver` + `part_purchases` timestamps; falls back
  to the latest checkpoint's **manual** reason only when not parts-blocked.
- **Services created:** `MaintenanceDelayResolver`.
- **Validation (unit):** blocked ⇒ "Waiting for {part} · {supplier} · ETA · N days"; not
  blocked ⇒ manual fallback; `days_waiting = now − ordered_at`.
- **Commit maps to:** Scenarios 2,9. Invariant 1 (delay never stored).

## Step 4 — `CarStatusService` exposes canonical `effective_state`

- **Changed:** `backend/app/Services/CarStatusService.php` — inject the two resolvers; add
  `effective_state` (both axes), the **B1 headline reduction**, and `delay` to the
  `/car-status` list rows and `/car-status/vehicle/{id}` payload. This is the **sole exposure**.
- **Changed (frontend, read-only consume):** `frontend/src/pages/CarStatus.js`,
  `frontend/src/components/vehicle-status/CarStatusDrawer.js` — render `effective_state` +
  derived `delay`; delete any local status inference.
- **Validation (feature):** endpoint returns derived fields; a grep/boundary test asserts the
  frontend no longer computes a maintenance status locally.
- **Commit maps to:** Scenario 10. Invariants 2,3.

## Step 5 — `OperationalStateProjector` + projection listeners

- **New:** `backend/app/Services/State/OperationalStateProjector.php` — subscribes to
  input-changing events; recomputes `effectiveState` **before vs after within the triggering
  transaction**; on boundary cross emits `RepairBlockedOnParts`/`RepairResumed` +
  `VehicleOperationalStateChanged`. **Per P1-D1 it does NOT write `vehicles.operational_status`
  in Phase 1** (writing it would create a partial second writer → drift). `effective_state` is a
  **live derived read** via `CarStatusService`; no denormalized cache is written. The projector's
  Phase-1 job is event emission + boundary detection only.
- **New listeners:** `backend/app/Listeners/TimelineProjector.php` (→ `VehicleLogService`),
  `NotificationDispatcher.php` (→ `NotificationScanner::notifyBy*`),
  `IntelligenceCacheInvalidator.php` (flush `intelligence:*` + dashboard caches).
- **Changed:** `EventServiceProvider` — bind listeners.
- **Validation (feature):** dispatch `PurchaseOrderIssued` on a ticket with an un-delivered
  part ⇒ exactly one `RepairBlockedOnParts`, `operational_status` updated, one timeline row,
  one notification; re-dispatch ⇒ **no duplicate** (idempotent). `PartDelivered` ⇒
  `RepairResumed` once.
- **Commit maps to:** Scenarios 2,3,5,6,10. Invariants 3,8.

## Step 6 — Wire emitters into existing workflow services (+ delivery action)

- **Changed:** `backend/app/Services/PartWorkflowService.php` — emit `PartRequirementRaised`
  (createRequest), `PartRequestApproved`/`Rejected`, `PurchaseOrderIssued` (purchase; capture
  `expected_delivery_date`, and set `delivered_at = now` when the part is on-hand),
  `PartInstalled` (install). **In the same commit, remove the now-superseded direct
  `VehicleLogService` calls** for these events (TimelineProjector is now the sole writer) —
  verify timeline parity, no double rows.
- **New action:** `markDelivered` in `PartWorkflowService` + endpoint
  (`POST /part-purchases/{id}/delivered`) in `PartPurchaseController` + route in
  `routes/api.php` (perm `parts.purchase`) → sets `delivered_at`, emits `PartDelivered`.
- **Changed:** `backend/app/Services/MaintenanceWorkflowService.php` — one central
  `MaintenanceLaneChanged` emit in the status-transition method (replacing scattered direct
  log calls for lane changes, same parity check).
- **Changed (frontend):** `Parts.js` PurchaseModal — capture `expected_delivery_date` +
  "on-hand now" toggle; add a "Mark delivered" row action.
- **Validation (feature, end-to-end):** **Scenario 1** (on-hand → no blocked alarm) and
  **Scenario 2 happy path** driven through the real endpoints; assert derived state, single
  timeline trail, notifications, and cost appears only at install.
- **Commit maps to:** Scenarios 1,2. Invariants 1,3,6,8.

## Step 7 — Delivery-lateness detector

- **Changed:** `backend/app/Console/Commands/NotificationsScan.php` (or new `parts:scan`) +
  `NotificationScanner` — detector: `part_purchases` `delivered_at` null &
  `expected_delivery_date < today` ⇒ emit `PartDeliveryDelayed`; escalation level **reuses the
  existing `MaintenanceCheckpointService` escalation computation** (one source; extraction into
  `EscalationPolicy` is Phase 3 — no new scattered rule). Add `ALERT_PERMISSIONS` entry.
- **Validation (feature):** overdue PO ⇒ `PartDeliveryDelayed` + notification, idempotent key;
  on-time PO ⇒ nothing.
- **Commit maps to:** Scenarios 2 (delay), 9. Invariant 8.

## Step 8 — Checkpoint form: derived delay + part-advancing question

- **Changed (backend):** `MaintenanceCheckpointController` / `MaintenanceCheckpointService` —
  when the ticket is parts-blocked (resolver), the checkpoint payload carries the **read-only
  derived** delay; the form accepts "part arrived? / new ETA?" which calls
  `PartWorkflowService::markDelivered` / updates `expected_delivery_date` (emitting
  `PartDelivered` / `PartDeliveryDelayed`). Remove `waiting_parts`, `vendor_delay`,
  `additional_damage` from the **manual** `DELAY_REASONS` (now derived); keep `workshop_busy`,
  `customer_approval`, `insurance_approval`, `other`.
- **Changed (frontend):** `frontend/src/components/maintenance/CheckpointModal.js`,
  `frontend/src/lib/maintenanceCheckpoints.js` — read-only derived delay when blocked;
  part-advancing fields; shrink the manual enum.
- **Validation (feature):** on a blocked ticket, manual `waiting_parts` is **rejected**;
  answering "arrived" sets `delivered_at` ⇒ `RepairResumed`.
- **Commit maps to:** Scenario 9. Invariants 1,4.

## Step 9 — Read surfaces: Dashboard, Ops Center, Timeline, Notifications

- **Changed:** `backend/app/Services/DashboardService.php` — derived, clickable KPIs: *Cars
  Waiting for Parts*, *Purchase Requests Pending*, *Supplier Delays*, *Parts Ordered*, *Ready
  once parts arrive* (each a one-shot derived query; drill-down lists).
- **Changed:** `backend/app/Services/MaintenanceOpsCenterService.php` — extend the
  `awaiting_parts` handling to read **active-ticket** block via `CarStatusService`/resolver;
  `recommended_action = waiting_parts` with derived reason/ETA/days.
- **Changed:** `backend/app/Services/ActivityFeedService.php` — labels/categories for
  `repair_blocked`, `repair_resumed`, `part_delivered`, `delivery_delayed`, `po_issued`;
  promote parts events to first-class category/stage.
- **Changed (frontend, consume-only):** `Dashboard.js` (KPI tiles + drill-downs),
  `ServiceDueBoard.js` (derived delay column).
- **Validation (feature):** KPI counts equal the derived queries; Ops Center shows derived
  waiting; timeline renders the new events; no surface recomputes state.
- **Commit maps to:** Scenario 10. Invariants 2,3,8.

## Step 10 — Explainability (optional tail of Phase 1)

- **New:** `PartDelayExplainer` registered with the Explainability engine so every "Waiting
  for Parts" figure traces to its PO (Phase 2 will extend it to RFQ/quote).
- **Validation:** explain endpoint returns the dependency chain for a blocked ticket.
- **Commit maps to:** Invariant 1 (provenance).

---

## Cross-cutting validation (runs on every step)

- **Layer-boundary guard test (per P1-D1):** an automated test asserting Phase 1 introduces
  **no new writer** of `vehicles.operational_status` (the projector does not write it), and that
  no new surface computes operational truth outside the Resolvers / `CarStatusService`
  (static scan). Enforces Addendum E rule 3 and P1-D1. (The legacy writers remain; the test
  pins the set so nothing new is added mid-Phase-1.)
- **Determinism tests:** resolvers are pure — identical fixtures yield identical output.
- **Idempotency tests:** re-dispatching an event or re-running a scan produces no duplicate
  timeline rows or notifications.
- **Regression gate:** existing maintenance/parts/checkpoint suites stay green at every commit.

## Commit → Scenario coverage summary

| Step | Advances Scenarios | Upholds Invariants |
|---|---|---|
| 0 event scaffolding | foundation | 3 |
| 1 delivery cols | 1,2,7 | 6 |
| 2 WorkflowStateResolver | 1,2,3 | 2 |
| 3 MaintenanceDelayResolver | 2,9 | 1 |
| 4 CarStatusService exposure | 10 | 2,3 |
| 5 Projector + listeners | 2,3,5,6,10 | 3,8 |
| 6 emitters + delivery action | 1,2 | 1,3,6,8 |
| 7 delay detector | 2,9 | 8 |
| 8 checkpoint derived delay | 9 | 1,4 |
| 9 read surfaces | 10 | 2,3,8 |
| 10 explainability | — | 1 |

Scenarios **4 (ServiceEntryPolicy)** and **8 (AlternativePartPolicy)** are **advisory/policy**
scenarios that land fully in **Phase 3** (their Policy owners); Phase 1 leaves today's
eligibility/alternative behaviour untouched rather than half-building a policy — consistent
with Addendum E rule 3 (a rule enters as a Policy, not scattered logic).
