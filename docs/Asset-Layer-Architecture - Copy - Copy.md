# ADR: Vehicle Asset Inventory & Component History Layer

**Date:** 2026-07-23 · **Status:** PROPOSED (design phase — no code yet) · **Companion:** `docs/Maintenance-Workflow-Audit.md` (Part II sketch; this document supersedes it as the buildable spec).

**Decision in one sentence:** FleetView becomes a Fleet **Asset** Management System by adding a physical-truth ledger — every vehicle is a container of identifiable components with full install/remove/disposition history, strictly separated from maintenance tickets (events) and service records (actions).

---

## 0. The governing question: "Can FleetView know the physical truth of a vehicle?"

**Today: NO.** Verified against current schema:

| Question | Today's answer |
|---|---|
| What parts are installed right now? | Unknown — only billing lines (`maintenance_line_items`) and purchases (`part_purchases`) exist; neither has "currently on vehicle" semantics |
| When / who / at what mileage installed? | Partially, per billed line (`installed_on/odometer`, actor-not-technician) — but no current/previous distinction |
| What was installed before? | Inferable only for tyres (`tireHistory` heuristic over lines); nothing else |
| Where did the removed part go? | **Nowhere recorded — removed parts do not exist in the data model** |
| Is the installed part under warranty? | `warranty_until` exists per line, but "the installed part" is not a concept, so the question can't be asked precisely |
| Is this failure related to a previous component? | Recurrence matches symptom strings, never a physical component |

**After this layer: YES**, with one honest caveat — pre-layer history is backfilled with `disposition = unknown_legacy` rather than invented facts (per the treat-data-as-source-of-truth rule).

### The three-entity separation (non-negotiable invariants)

| Entity | Nature | Table(s) | May drive `workflow_status`? |
|---|---|---|---|
| **Maintenance ticket** | Event / problem | `maintenances`, `maintenance_tasks` | yes (existing engine, untouched) |
| **Component** | Physical asset with identity | `component_catalog`, `vehicle_components`, `component_events` | **never** |
| **Service** | Performed action (labor) | `service_records` | **never** |

- A component row is **immutable identity**: it is created once when the physical part enters our world and NEVER deleted or moved to another table. Location and status change; the row persists.
- Removal without disposition is impossible (DB-level: removal fields written atomically with disposition; API-level: 422).
- Components and services *reference* tickets (nullable FKs) but no component/service write ever transitions a ticket. The reverse direction (ticket close → service record) goes through the one existing gate (`confirmRoutineServices`).
- Consumables (oil, coolant, filters below tracking threshold) NEVER create component rows — they are services with materials cost.

---

## 1. Final database schema

Conventions follow the codebase: `id` big-increments, `timestamps()`, status/enum columns as **strings with PHP model constants** (not MySQL ENUMs — consistent with `PartRequest`, `LogisticsTask`, `Maintenance`), FKs via `foreignId()->constrained()` with explicit on-delete behavior, denormalized `*_name` actor stamps alongside user FKs (house style).

### 1.1 `component_catalog` — the TYPE of component (not the instance)

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `category_key` | string(40), index | Aligns with findings-catalog keys (`tyres`, `brakes`, `electrical`, `ac`, `engine`, `transmission`, `suspension`, …) so faults ↔ components join naturally |
| `name` | string(120) | "Front brake pads", "Battery 80Ah class" |
| `slug` | string(120), **unique** | stable key for config/seeds |
| `tracking_mode` | string(20) | `serialized` \| `batch` \| `consumable` |
| `default_part_number` | string(80), nullable | |
| `default_warranty_months` | unsignedSmallInteger, nullable | |
| `expected_life_km` | unsignedInteger, nullable | feeds foresight (P3) |
| `expected_life_months` | unsignedSmallInteger, nullable | |
| `position_scheme` | string(20), nullable | `axle_corner` (tyres/brakes: FL/FR/RL/RR), `axle` (front/rear), null = positionless |
| `is_active` | boolean default true | retire catalog entries without breaking history |
| `notes` | text, nullable | |

**Model constants:** `TRACKING_SERIALIZED='serialized'`, `TRACKING_BATCH='batch'`, `TRACKING_CONSUMABLE='consumable'`; `POSITIONS = ['front_left','front_right','rear_left','rear_right','front','rear']`.

**Rules:** `serialized` (engine, gearbox, battery, AC compressor, alternator, starter, ECU/sensors worth tracing) → `serial_no` required at install. `batch` (brake pads, discs, tyre sets when DOT unknown, filters worth tracking) → quantity, serial optional. `consumable` → **guard: never instantiates a `vehicle_components` row** (oil, coolant, bulbs, wipers).

**Seed:** from `config/maintenance_findings.php` categories × `PartRequest::CLASSES` mapping (`major→serialized`, `standard→batch`, `consumable→consumable`), curated in a new `config/component_catalog.php`.

### 1.2 `vehicle_components` — the PHYSICAL INSTANCE (heart of the layer)

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `component_catalog_id` | FK → component_catalog, restrictOnDelete | |
| `vehicle_id` | FK → vehicles, **nullable**, nullOnDelete | null = not on any vehicle (storage/disposed) |
| `serial_no` | string(80), nullable | required when catalog serialized (service-level guard) |
| `part_number` | string(80), nullable, index | |
| `brand` | string(80), nullable | "Bosch", "Michelin" |
| `label` | string(160), nullable | human display ("Bosch S4 80Ah") |
| `quantity` | decimal(8,2) default 1 | batch mode (e.g. 4 pads = 1 set qty 1, or qty 4 — catalog decides via notes) |
| `position` | string(20), nullable | from catalog `position_scheme` |
| `status` | string(20), index | `active` \| `removed` \| `retired` |
| `location` | string(30), index | `installed` \| `in_storage` \| `refurbishing` \| `returned_supplier` \| `scrapped` \| `sold` \| `transferred_out`(transient) |
| **Install leg** | | |
| `installed_at` | dateTime, nullable | |
| `installed_odometer` | unsignedInteger, nullable | |
| `installed_by` / `installed_by_name` | FK users nullOnDelete / string(120) | actor who logged |
| `technician_name` | string(120), nullable | PHYSICAL installer (free text until technician entity exists — P3) |
| `installer_vendor_id` | FK → vendors, nullable, nullOnDelete | which workshop installed it (quality attribution) |
| `supplier_vendor_id` | FK → vendors, nullable, nullOnDelete | who sold it to us |
| `purchase_cost` | decimal(12,2), nullable | AED |
| `currency` | string(3) default 'AED' | |
| `warranty_months` | unsignedSmallInteger, nullable | |
| `warranty_until` | date, nullable | **derived in `booted()` saving hook** = install date + months (same pattern as `MaintenanceLineItem:77-84`) |
| **Provenance** | | |
| `source_part_purchase_id` | FK → part_purchases, nullable, nullOnDelete | |
| `source_line_item_id` | FK → maintenance_line_items, nullable, nullOnDelete | billing linkage |
| `source` | string(20) | `workflow` \| `manual` \| `legacy_backfill` |
| **Removal leg** (filled in place — row never moves) | | |
| `removed_at` | dateTime, nullable | |
| `removed_odometer` | unsignedInteger, nullable | |
| `removed_by` / `removed_by_name` | FK users nullOnDelete / string(120) | |
| `removal_reason` | string(30), nullable | `worn_out` \| `failed` \| `damaged` \| `upgraded` \| `recalled` \| `moved_to_other_vehicle` \| `vehicle_sold` \| `unknown_legacy` |
| `removal_note` | text, nullable | |
| `disposition` | string(30), nullable | `scrapped` \| `stored_spare` \| `returned_supplier` \| `warranty_return` \| `sold` \| `refurbished` \| `transferred` \| `sold_with_vehicle` \| `unknown_legacy` |
| `replaced_by_component_id` | self-FK, nullable, nullOnDelete | successor chain |
| `removal_maintenance_id` | FK → maintenances, nullable, nullOnDelete | ticket that caused removal |

**Indexes:** `(vehicle_id, status)` — the "current components" query; `(component_catalog_id, vehicle_id, position, status)` — predecessor lookup at install; `(status, location)` — spares inventory; `(warranty_until)` — warranty scans; `serial_no` index.

**Status × location invariants (enforced in service layer):**
- `active` ⇔ `location=installed` ∧ `vehicle_id NOT NULL`
- `removed` + `location=in_storage/refurbishing` ⇒ `vehicle_id NULL` (a spare belongs to the fleet, not a car)
- `retired` ⇔ terminal locations (`scrapped`, `returned_supplier`, `sold`, `sold_with_vehicle`)
- At most ONE `active` row per (vehicle, catalog, position) — enforced with `lockForUpdate` on the predecessor at install (house pattern, cf. `PartWorkflowService::purchase`).

### 1.3 `component_events` — append-only movement ledger

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `vehicle_component_id` | FK → vehicle_components, cascadeOnDelete | |
| `event` | string(30), index | `purchased` \| `stored` \| `installed` \| `removed` \| `transferred` \| `disposed` \| `returned_supplier` \| `warranty_claimed` \| `sold` \| `refurbished` \| `inspected` |
| `from_vehicle_id` / `to_vehicle_id` | FK vehicles, nullable, nullOnDelete | transfers carry both |
| `odometer` | unsignedInteger, nullable | reading of the vehicle involved |
| `maintenance_id` | FK → maintenances, nullable, nullOnDelete | causing ticket (nullable: storage moves need none) |
| `maintenance_task_id` | FK → maintenance_tasks, nullable, nullOnDelete | causing fault |
| `actor_id` / `actor_name` | FK users nullOnDelete / string(120) | |
| `at` | dateTime, index | business time (≠ created_at) |
| `note` | text, nullable | |
| `meta` | json, nullable | disposition detail, warranty-claim ref, photos refs |

**Indexes:** `(vehicle_component_id, at)`; `(from_vehicle_id, at)` + `(to_vehicle_id, at)` — per-vehicle timeline joins.

**Mirroring:** every event also writes a `vehicle_log_events` row via the existing `VehicleLogService` — new constants `EVENT_COMPONENT_INSTALLED`, `EVENT_COMPONENT_REMOVED`, `EVENT_COMPONENT_TRANSFERRED`, `EVENT_COMPONENT_DISPOSED` — so the Vehicle Timeline (`tl.*` investigation tool) shows asset movements with zero frontend joins. `component_events` remains the asset-side source of truth; `vehicle_log_events` is the display mirror (same relationship maintenances↔vehicle_log_events already has).

### 1.4 `service_records` — performed actions (labor), unified

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `vehicle_id` | FK → vehicles, cascadeOnDelete, index | |
| `maintenance_id` | FK → maintenances, nullable, nullOnDelete | nullable: standalone cleaning/inspection allowed |
| `maintenance_task_id` | FK → maintenance_tasks, nullable, nullOnDelete | |
| `service_type` | string(40), index | aligned with `ServiceReminder` types + findings categories (`oil_change`, `alignment`, `inspection`, `diagnostics`, `cleaning`, `programming`, `repair_labor`, …) |
| `description` | string(255) | |
| `performed_at` | date, index | |
| `odometer` | unsignedInteger, nullable | |
| `workshop_vendor_id` | FK → vendors, nullable, nullOnDelete | |
| `technician_name` | string(120), nullable | |
| `labor_cost` | decimal(12,2), nullable | money gated behind SHOW_FINANCIALS like everything else |
| `materials_cost` | decimal(12,2), nullable | consumables used (oil itself) |
| `duration_hours` | decimal(6,2), nullable | from existing `repair_hours` capture |
| `result` | string(20) | `completed` \| `partial` \| `failed` |
| `related_component_id` | FK → vehicle_components, nullable, nullOnDelete | "brake service ON these pads" |
| `source` | string(20), index | `workflow_close` \| `invoice_import` \| `manual` \| `legacy_backfill` |
| `source_line_item_id` / `source_invoice_item_id` | FKs, nullable | idempotent backfill keys |
| `performed_by` / `performed_by_name` | FK users nullOnDelete / string(120) | |

**Indexes:** `(vehicle_id, performed_at)`; `(vehicle_id, service_type, performed_at)` — "last done per type" (replaces `last_by_category` scan).

### 1.5 Media

No new media table. Extend `maintenance_media` with nullable `vehicle_component_id` + `component_event_id` (indexed) — before/after photos attach to the install/removal events; reuses existing upload pipeline and viewers.

### 1.6 Relationship map

```
component_catalog 1──N vehicle_components N──1 vehicles (nullable)
vehicle_components 1──N component_events (append-only)
vehicle_components 1──1 vehicle_components (replaced_by — successor chain)
vehicle_components N──1 part_purchases / maintenance_line_items / vendors ×2 (provenance)
service_records N──1 vehicles · N──1 maintenances/tasks (nullable) · N──1 vehicle_components (nullable)
maintenance_media N──1 vehicle_components / component_events (new nullable FKs)
vehicle_log_events ← mirror of component_events (display)
```

---

## 2. Lifecycle state machines

### 2.1 Component (status + location combined view)

```
                       PURCHASED (event; row may pre-exist purchase for legacy)
                            │ received into our world
              ┌─────────────┴─────────────┐
              ▼                           ▼
        IN_STORAGE  ◄──────────────  INSTALLED/ACTIVE
     (status=removed*, loc=in_storage)   (status=active, loc=installed, on vehicle V)
              │  install (any vehicle)    │
              │─────────────────────────► │  ← transfer = removed(A) + installed(B), one row, two events
              │                           │ remove (MANDATORY: reason + disposition)
              │                           ▼
              │                        REMOVED (removal leg stamped, replaced_by linked)
              │                           │ disposition (atomic with removal)
   ┌──────────┼───────────┬──────────────┼──────────────┬─────────────┐
   ▼          ▼           ▼              ▼              ▼             ▼
STORED_SPARE REFURBISHING RETURNED_SUPPLIER WARRANTY_RETURN SCRAPPED  SOLD / SOLD_WITH_VEHICLE
(loc=in_storage,          (retired)       (retired; may   (retired)  (retired)
 re-installable ──►INSTALLED)             spawn warranty_claimed event)
```

*A fresh spare bought into stock is `status=removed`? No — special case: a never-installed part in stock uses `status=removed` is wrong semantically; use `status` value **`in_stock`** for never/currently-uninstalled-and-reusable rows. Final status enum: **`in_stock` | `active` | `removed` | `retired`** (`removed` = came off a vehicle and disposition decided but still exists as spare → immediately becomes `in_stock` if disposition=stored_spare/refurbished; kept as a distinct value only transiently). Simplified rule set:*

- `in_stock` (loc `in_storage`/`refurbishing`) — installable
- `active` (loc `installed`) — on a vehicle
- `retired` (loc `scrapped`/`returned_supplier`/`sold`/`sold_with_vehicle`) — terminal
- Transitions: `in_stock → active` (install) · `active → in_stock` (removed + disposition stored_spare/refurbished) · `active → retired` (removed + terminal disposition) · `in_stock → retired` (dispose from shelf) · `active(A) → active(B)` (transfer, atomic)

Illegal by construction: `retired → anything`; `active` on two vehicles; removal without disposition; disposition without removal (except shelf disposal).

### 2.2 Service

Deliberately **thin**. The request/approval/progress workflow ALREADY exists — it is the maintenance ticket (`recommendation_pending → approved → under_repair → …`). Duplicating a REQUESTED→APPROVED→IN_PROGRESS machine on `service_records` would create a second competing workflow and violate "Ticket = single source of truth". Therefore:

```
(pre-life lives on the TICKET: requested/approved/in-progress = workflow_status)
                     │ re-inspection PASS (confirmRoutineServices) or manual/import entry
                     ▼
             service_records row:  COMPLETED | PARTIAL | FAILED   (fact, immutable)
```

A `failed` service (e.g. programming attempt unsuccessful) is a recordable fact and feeds workshop quality stats; the *retry* is a new ticket/task, not a state change on the record.

---

## 3. How the maintenance workflow connects (end-to-end chain)

```
FAULT       maintenance_task created (Decide step / garage finding)      [existing]
  ↓
DIAGNOSIS   confirm fault → recurring check NOW ALSO component-aware:    [changed]
            active component matching category(+position) is loaded →
            is it under warranty? same supplier as last failure? → review context
  ↓
REPAIR      part_request → approve → part_purchase (ordered→received)    [existing + audit Part I]
  ↓
COMPONENT   PartWorkflowService::installPurchase — THE single write-point [new]
REPLACEMENT   1. find predecessor: active row (vehicle, catalog, position) with lockForUpdate
              2. close it: removed_at/odometer/by + removal_reason + MANDATORY disposition
                 (422 without it) + removal_maintenance_id + replaced_by link
              3. create successor: active, install leg from purchase (supplier, cost,
                 warranty → warranty_until auto-derived), technician_name, position
              4. events: removed(old) + installed(new), mirrored to vehicle_log_events
              5. billing unchanged: kind=part line item still written (money model untouched)
              6. optional labor input → kind=labor line + service_records(repair_labor)
  ↓
SERVICE     ticket passes re-inspection → close() → confirmRoutineServices [changed]
RECORD        writes service_records (source=workflow_close) for each completed
              routine task — SAME gate that already rolls ServiceReminder anchors;
              on-site markServiced path flows through the same re-inspection gate
  ↓
VEHICLE     Vehicle Profile "physical truth":                              [new reads]
HISTORY       CURRENT COMPONENTS  = vehicle_components WHERE vehicle_id=? AND status=active
              COMPONENT HISTORY   = non-active rows + events touching this vehicle
              SERVICE HISTORY     = service_records WHERE vehicle_id=? (re-points the
                                    existing serviceHistory endpoint, same response shape)
              TIMELINE            = vehicle_log_events (component events auto-appear)
```

Direction of writes is one-way: **workflow → asset layer**. The asset layer never transitions a ticket. Manual asset operations (transfer between cars, shelf disposal, spare intake) need no ticket and use their own endpoints under `parts.*`/new `components.*` permissions.

---

## 4. How reporting & intelligence use this later

All of these become simple queries over `vehicle_components` + `component_events` — no reconstruction from billing lines:

| Question | Query shape |
|---|---|
| **Which battery brand fails faster?** | components where catalog=battery, removal_reason∈(failed,worn_out): AVG(life_km), AVG(life_days) GROUP BY brand; survival curve per brand |
| **Which workshop installs parts that fail again?** | components grouped by `installer_vendor_id` where successor exists within X km/days OR removal_reason=failed → failure-rate per workshop; joins cleanly with existing `repair_inspections.still_exists` garage-quality numerator |
| **Average component lifetime** | AVG(removed_odometer − installed_odometer), AVG(removed_at − installed_at) per catalog entry; compare against `expected_life_km` to auto-tune the catalog |
| **Cost per vehicle / Total ownership cost** | Σ purchase_cost of components ever installed on V + Σ service_records labor/materials + existing VehicleExpenseProvider figures → true TCO alongside depreciation (PR1) and economic profit (PR2); every number explainable to its component/service row |
| **Predict next replacement date** | active component: installed_odometer + expected_life_km (catalog, auto-tuned by fleet actuals) vs current odometer + daily-km run-rate → feeds `MaintenanceForesightService` as a new signal class (`component_due`) next to oil/battery/chronic |
| **Warranty exposure & recovery** | active components with warranty_until ≥ today = coverage map; removal_reason=failed AND warranty valid AND disposition≠warranty_return = **missed warranty recovery** (money left on the table — flag it) |
| **Supplier scorecards** | failure rate + warranty-claim rate per supplier_vendor_id → feeds `part_investigations` with real denominators |
| **Is this failure related to a previous component?** | recurring review context: previous component on same slot, its brand/supplier/installer, whether current failure is within predecessor's or successor's warranty |

**Explainability:** register a `ComponentExplainer` in the shared DAG engine (`app/Services/Explainability`) — `life_km`, warranty state, TCO contributions each trace to install/remove events. Consistent with the platform rule: explainers never recalculate business logic.

---

## 5. Migration order (phased; nothing coded yet)

**Phase 1 — Tables (pure additive, zero behavior change)**
1. `component_catalog` (+ seed command from `config/component_catalog.php`)
2. `vehicle_components`
3. `component_events`
4. `service_records`
5. `maintenance_media`: add nullable `vehicle_component_id`, `component_event_id`
6. `vehicle_log_events`: new event constants (code, no migration)
   *Deployable alone; app behaves identically.*

**Phase 2 — Integration (write paths)**
1. `installPurchase` extension: predecessor close + disposition prompt + successor create + events (the ONLY workflow-coupled write) — includes the Part I C3/C4/C5 items (labor line, disposition, photos)
2. `confirmRoutineServices` → also writes `service_records`
3. Manual asset endpoints: transfer, shelf intake, shelf disposal, spare re-install (`components.manage` + `components.view` perms, RBAC convention)
4. Recurring-fault review: attach matched component + `in_warranty` context (Part I D4 lands here)
5. Vehicle-sale hook: disposition prompt per active component (sell-with-car vs strip-to-stock)
   *Each step independently deployable; guarded by nothing new being read yet.*

**Phase 3 — Backfill (idempotent command, e.g. `components:backfill --dry-run`)**
1. Catalog seed → 2. replay `part_purchases` install legs (best provenance) → 3. sweep `maintenance_line_items` kind=part not already covered (`source_line_item_id` as idempotency key; tyre lines carry brand/DOT) → 4. latest per (vehicle, catalog, position) = `active`, earlier = retired with `removal_reason=unknown_legacy`, `disposition=unknown_legacy` → 5. `service_records` from `invoice_items` (source=invoice_import, `source_invoice_item_id` key) + closed routine tasks → 6. reconciliation report (counts, conflicts, vehicles with zero data). Backup-gated per house rule (`db:backup` first).

**Phase 4 — UI (reads last)**
1. Vehicle profile / Car-Status: **Components tab** = Current Components + Component History (replaces inferred Parts view)
2. `serviceHistory` + `tireHistory` endpoints re-point to new tables (response shapes preserved — zero frontend break, then enrich)
3. Spares inventory page (in_stock components) + disposition/transfer modals
4. Asset export (`GET /Vehicle/{id}/asset-export`, JSON + printable) — sale pack
5. Timeline chips for component events (auto via `vehicle_log_events` mirror)
6. Money fields behind `SHOW_FINANCIALS` as everywhere else

Sequencing vs the workflow audit: Part I **P0s** (awaiting-parts state, cost-model guard, release block, API tightening) ship before or parallel to Phase 1 — they touch none of these tables. Parts-related P1s wait for Phase 2 and land on the layer.

---

## 6. Business test scenarios (acceptance level)

**S1 — Battery replacement (the canonical flow)**
Given vehicle A has active component Battery "Bosch S4, serial BX-991", installed 2026-01-10 @120,000 km, warranty 12m.
When a confirmed battery fault leads to purchase + install of "Amaron 80Ah, serial AM-102" @150,300 km with 18m warranty, and the actor selects disposition `warranty_return` reason `failed`.
Then: Bosch row → `retired/returned_supplier` (removed leg complete, life 30,300 km & 5m computed), `replaced_by` → Amaron row; Amaron row `active` with `warranty_until = install+18m`; 2 component events + 2 timeline entries on A; billing line unchanged; **flag raised**: failed inside warranty → warranty-claim candidate (money recovery).
And asking "where is the old battery?" returns `returned_supplier` with date, actor, and the causing ticket.

**S2 — Old part must not disappear (negative)**
Given S1 mid-install. When the actor submits install without disposition for the Bosch battery. Then 422 "what happened to the old battery?" listing the disposition options; no rows written (atomic).

**S3 — Tyre transferred A → B**
Given active tyre component (Michelin, DOT 2325, position front_left) on A. When transferred to B (position front_left) with both odometers. Then ONE component row now active on B; `transferred` event with from=A, to=B; both vehicles' timelines show it; A's component history and B's current components both correct; no maintenance ticket required; tyre's event list = full biography (purchase → A → B).

**S4 — Vehicle sold**
Given vehicle with 6 active components. When status → `sold`. Then system prompts per component: sell with vehicle (`retired/sold_with_vehicle`) or strip to stock (`in_stock/in_storage`, vehicle_id null); zero components left `active` on a sold car; asset-export pack available (current components at sale, full history, service history, warranties remaining) — attached to the sale record.

**S5 — Same fault after repair (component-aware comeback)**
Given AC compressor replaced 10 days ago (component active, warranty 12m, supplier X, installer garage Y). When the same-category fault is confirmed on a new ticket. Then the recurring review context contains: the component (same slot), `in_warranty=true`, previous supplier X, previous installer Y, predecessor chain; decision `warranty_claim` available → repair proceeds at zero customer cost, `warranty_claimed` event on the component, liability attributed to Y or X by the human decision (garage never auto-blamed — existing rule).

**S6 — Spare re-used**
Given battery in stock (disposition was stored_spare). When installed on vehicle C via a ticket. Then same row → active on C; provenance chain intact (original purchase cost/supplier); warranty logic uses ORIGINAL warranty_until (no reset); event chain shows both installations.

**S7 — Oil change is a service, not a component**
Given catalog "engine oil" = consumable. When a routine oil ticket closes on re-inspection PASS @150,000 km. Then a `service_records` row (oil_change, completed, odometer, workshop, cost) is created AND ServiceReminder rolls forward (existing behavior); **no** vehicle_components row exists or was attempted; vehicle profile shows it under Service History only.

**S8 — Physical-truth query**
Given any vehicle after Phase 3. When opening the profile Components tab. Then: current components with install date/mileage/installer/supplier/cost/warranty-remaining; component history with removal reason + destination; legacy rows honestly labeled `unknown_legacy`; every figure clickable to its evidence (explainer).

**S9 — Backfill idempotency**
Given legacy data. When `components:backfill` runs twice. Then row counts identical (source FK idempotency keys); exactly one active per slot; dry-run mode reports without writing.

**S10 — Missed warranty recovery intelligence**
Given a component removed as `failed`, `scrapped`, with warranty_until still in the future. Then the finance-leak detector (existing alert family) raises "scrapped a part under warranty — recovery missed" to maintenance.manage.

---

## 7. Decisions & rejected alternatives

| Decision | Rejected alternative | Why |
|---|---|---|
| 4 tables; removal = status+disposition on the same row | separate `removed_components` table | moving rows breaks FK continuity, kills "full biography" queries, invites drift |
| String statuses + PHP constants | MySQL ENUMs | house convention; enum ALTERs are painful |
| Billing (`maintenance_line_items`) untouched, components reference it | replace line items with components | money model is audited & reconciled; asset ≠ invoice; keeps SHOW_FINANCIALS separation |
| Service pre-life lives on the ticket | full REQUESTED→…→COMPLETED machine on service_records | would duplicate the workflow engine and violate Ticket-single-source-of-truth |
| `installPurchase` = single workflow write-point | writes scattered across close/markFixed/invoice | one seam to test; matches where install data is already captured |
| Mirror events into `vehicle_log_events` | frontend joins a new table | Timeline/investigation tooling works day one |
| `unknown_legacy` explicit enum values | inferring dispositions for old data | treat-data-as-source-of-truth rule; no invented facts |
| Transfer = one row, two-sided event | new row per vehicle | identity (serial, warranty, cost basis) must survive the move |

---

*Next step when approved: Phase 1 migrations + catalog seed config, then the Part I P0 workflow fixes in parallel.*
