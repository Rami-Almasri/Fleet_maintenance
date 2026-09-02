# Asset Layer — Final Database Design Package

**Date:** 2026-07-23 · **Status:** FOR APPROVAL (design only — no migrations, no code changes) · **Parents:** `docs/Asset-Layer-Architecture.md` (ADR), `docs/Maintenance-Workflow-Audit.md` (gap analysis).

Scope: the complete, buildable database design for the Vehicle Asset Inventory / Component History layer. Backward compatibility rule: **no existing table is modified structurally except two nullable columns added to `maintenance_media`; no existing write path changes behavior until Phase 2.**

---

# 1. Complete ERD Design

> Note on naming: the existing audit table is `vehicle_log_events` (there is no `vehicle_logs` table); the existing invoice tables are `maintenance_invoices` (per-garage workshop invoices) and `invoices`/`invoice_items` (contract-side Technical Service Log). All three appear below.

```
                                    ┌──────────────────┐
                                    │ component_catalog │  (the TYPE)
                                    └────────┬─────────┘
                                             │ 1
                                             │ N
┌──────────┐  1        N  ┌─────────────────▼──────────┐   1      N  ┌──────────────────┐
│ vehicles ├──────────────┤     vehicle_components      ├─────────────┤ component_events │
└────┬─────┘ (nullable FK │        (the INSTANCE)       │             │   (the LEDGER)   │
     │        = off-car)  └──┬────────┬────────┬────┬───┘             └───┬───────┬──────┘
     │                       │self-FK │        │    │                     │       │
     │              replaced_by_component_id   │    │           from_vehicle_id   │
     │                       │        │        │    │           to_vehicle_id ────┼──► vehicles
     │                       │        │        │    │                             │
     │                  ┌────▼───┐ ┌──▼─────┐ ┌▼────▼─────────────┐   maintenance_id / task_id
     │                  │vendors │ │ users  │ │  part_purchases    │◄──────────┐   │
     │                  │×2 roles│ │(actors)│ │  (provenance)      │           │   │
     │                  └────────┘ └────────┘ └──────┬─────────────┘           │   │
     │                                               │ maintenance_line_item_id│   │
     │   1         N   ┌───────────────────┐         ▼                         │   │
     ├─────────────────┤  service_records  │  ┌──────────────────────┐         │   │
     │                 │  (the ACTIONS)    │  │ maintenance_line_items│─────────┤   │
     │                 └──┬──────┬─────┬───┘  │ (BILLING — untouched) │         │   │
     │                    │      │     │      └──────┬───────────────┘         │   │
     │          related_component_id   │             │ maintenance_invoice_id  │   │
     │                    │      │     │             ▼                         │   │
     │             maintenance_id│  source_invoice_item_id ┌────────────────┐  │   │
     │                    │      │     └──────────────────►│ invoice_items  │  │   │
     │   1        N  ┌────▼──────▼──┐                      │ (svc log, ro)  │  │   │
     ├───────────────┤ maintenances ├──1───N─► maintenance_tasks ────────────┘  │   │
     │               └──────┬───────┘          (faults)                         │   │
     │                      │ 1                                                 │   │
     │                      └──N─► maintenance_invoices (per-garage money)      │   │
     │                                                                          │   │
     │   1        N  ┌────────────────────┐    display mirror of component      │   │
     └───────────────┤ vehicle_log_events │◄───events (new EVENT_COMPONENT_*) ──┘───┘
                     └────────────────────┘
     maintenance_media ──(2 NEW nullable FKs)──► vehicle_components / component_events
```

**Cardinality statements (the contract):**

| Relationship | Cardinality | Meaning |
|---|---|---|
| vehicle → vehicle_components | 1 → N (FK nullable) | a car holds many components; a component may be off-car (warehouse/disposed) |
| component_catalog → vehicle_components | 1 → N | one type, many physical instances |
| vehicle_component → component_events | 1 → N (append-only) | the component's biography |
| maintenance ticket → vehicle_components | 1 → N **indirect** | via `component_events.maintenance_id` (install/remove caused by the ticket) and `vehicle_components.removal_maintenance_id`; a ticket can install many components |
| maintenance_task (fault) → component_events | 1 → N | which fault caused the install/removal |
| service_record → vehicle | N → 1 (required) | every action happened to one car |
| service_record → maintenance | N → 1 **optional** | workflow-born services carry the ticket; standalone cleaning/inspection do not |
| service_record → vehicle_component | N → 1 optional | "brake service ON these pads" |
| vehicle_component → part_purchase / line item | N → 1 optional each | provenance + billing link; billing model unchanged |
| vehicle_component → vendors | N → 1 ×2 | `supplier_vendor_id` (sold it) ≠ `installer_vendor_id` (fitted it) — quality attribution needs both |
| vehicle_component → vehicle_component | 1 → 1 self | `replaced_by_component_id` successor chain |
| component_event → vehicle_log_events | 1 → 1 mirror | display only; `component_events` is source of truth |

---

# 2. Final Table Definitions

Conventions (house style, verified in codebase): `id` = BIGINT UNSIGNED auto-increment; timestamps on every table; status columns = `VARCHAR` + PHP class constants (NOT MySQL ENUM — consistent with `PartRequest`, `Maintenance`, `LogisticsTask`); actor pattern = FK + denormalized `*_name` snapshot (survives user deletion); FK behaviors chosen per column below.

## 2.1 `component_catalog`

**Purpose:** the dictionary of component TYPES the fleet tracks — what kind of thing it is, how it is tracked, and what lifetime to expect. Never stores a physical part.

| Column | Type | Null | Index / FK | Why it exists |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED PK | no | PK | |
| `slug` | VARCHAR(120) | no | **UNIQUE** | stable machine key for seeds/config; survives renames |
| `name` | VARCHAR(120) | no | — | display: "Battery 12V", "Front brake pads" |
| `category_key` | VARCHAR(40) | no | INDEX | joins faults↔components: same keys as `config/maintenance_findings.php` (`tyres`, `brakes`, `electrical`, `ac`, `engine`, …). This one column is what makes "same fault → which component?" a join instead of string matching |
| `tracking_mode` | VARCHAR(20) | no | INDEX | `serialized` \| `batch` \| `consumable` — drives all validation (§4) |
| `default_part_number` | VARCHAR(80) | yes | — | pre-fill convenience |
| `default_warranty_months` | SMALLINT UNSIGNED | yes | — | pre-fill; per-instance value always wins |
| `expected_life_km` | INT UNSIGNED | yes | — | foresight input; auto-tunable later from fleet actuals |
| `expected_life_months` | SMALLINT UNSIGNED | yes | — | time-based twin (batteries age by time) |
| `position_scheme` | VARCHAR(20) | yes | — | `axle_corner` (FL/FR/RL/RR), `axle` (front/rear), NULL = positionless. Validates `vehicle_components.position` |
| `is_active` | BOOLEAN default 1 | no | — | retire catalog entries without breaking historical FK rows |
| `notes` | TEXT | yes | — | curation notes (e.g. "pads tracked as set of 4, qty=1") |
| `created_at`/`updated_at` | TIMESTAMP | — | — | |

FK behavior: none outgoing. Incoming FKs use **restrictOnDelete** — a catalog entry with instances can never be deleted, only `is_active=false`.

## 2.2 `vehicle_components`

**Purpose:** ONE physical asset instance. Created once when the part enters our world; never deleted, never moved to another table. Install/removal legs are filled in place; `status`+`location` say where it is now.

| Column | Type | Null | Index / FK | Why it exists |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED PK | no | PK | |
| `component_catalog_id` | BIGINT UNSIGNED | no | FK → component_catalog **restrictOnDelete**; part of composite idx | what kind of thing this is |
| `vehicle_id` | BIGINT UNSIGNED | **yes** | FK → vehicles **nullOnDelete**; composite idx `(vehicle_id, status)` | which car it is on NOW. NULL = warehouse/disposed. nullOnDelete: destroying a vehicle record must not destroy asset history |
| `serial_no` | VARCHAR(80) | yes* | INDEX | individual identity; *required by service validation when catalog is `serialized` |
| `part_number` | VARCHAR(80) | yes | INDEX | SKU/OEM lookup, duplicate-spend intelligence |
| `brand` | VARCHAR(80) | yes | INDEX | brand-level failure analytics ("which battery brand fails faster") |
| `model` | VARCHAR(120) | yes | — | "S4 008", "Pilot Sport 4" — finer than brand |
| `label` | VARCHAR(160) | yes | — | assembled display string, denormalized for lists |
| `quantity` | DECIMAL(8,2) default 1 | no | — | batch mode (4 pads as a set: catalog decides set-vs-each; serialized always 1) |
| `position` | VARCHAR(20) | yes | part of composite idx | slot on the car (front_left…); validated against catalog `position_scheme`. Without it, two front tyres would fight over "the active tyre" |
| `status` | VARCHAR(20) | no | composite idx `(vehicle_id,status)`, `(status,location)` | `in_stock` \| `active` \| `retired` (§3) |
| `location` | VARCHAR(30) | no | composite idx | `on_vehicle` \| `warehouse` \| `refurb` \| `supplier` \| `scrapped` \| `sold` (§3) |
| **Install leg** | | | | |
| `installed_at` | DATETIME | yes | — | when fitted (business time) |
| `installed_odometer` | INT UNSIGNED | yes | — | mileage anchor — life_km numerator start; consistent with the platform's odometer-anchoring discipline |
| `installed_by` | BIGINT UNSIGNED | yes | FK → users **nullOnDelete** | actor who logged the install |
| `installed_by_name` | VARCHAR(120) | yes | — | snapshot (house pattern) |
| `technician_name` | VARCHAR(120) | yes | — | PHYSICAL fitter, free text until a technician entity exists (P3). Kept separate from `installed_by` deliberately — the audit flagged actor≠technician |
| `installer_vendor_id` | BIGINT UNSIGNED | yes | FK → vendors **nullOnDelete**, INDEX | which workshop fitted it — "which workshop installs parts that fail again" needs this |
| `supplier_vendor_id` | BIGINT UNSIGNED | yes | FK → vendors **nullOnDelete**, INDEX | who sold it — supplier scorecards |
| `purchase_cost` | DECIMAL(12,2) | yes | — | asset cost basis (AED); UI behind SHOW_FINANCIALS |
| `currency` | VARCHAR(3) default 'AED' | no | — | non-AED recorded honestly, excluded from AED roll-ups (existing parts rule) |
| `warranty_months` | SMALLINT UNSIGNED | yes | — | contractual coverage |
| `warranty_until` | DATE | yes | **INDEX** | derived in model `booted()` = installed_at + months (same hook pattern as `MaintenanceLineItem:77-84`); indexed for warranty-exposure scans and the missed-recovery detector |
| **Provenance** | | | | |
| `source_part_purchase_id` | BIGINT UNSIGNED | yes | FK → part_purchases **nullOnDelete**, INDEX | procurement chain; also backfill idempotency key |
| `source_line_item_id` | BIGINT UNSIGNED | yes | FK → maintenance_line_items **nullOnDelete**, INDEX | billing linkage; backfill idempotency key |
| `source` | VARCHAR(20) | no | — | `workflow` \| `manual` \| `legacy_backfill` — trust label on every row |
| **Removal leg** | | | | |
| `removed_at` | DATETIME | yes | — | when it came off |
| `removed_odometer` | INT UNSIGNED | yes | — | life_km numerator end |
| `removed_by` / `removed_by_name` | FK users nullOnDelete / VARCHAR(120) | yes | — | accountability |
| `removal_reason` | VARCHAR(30) | yes | INDEX | WHY it came off (§3) — failure analytics dimension |
| `removal_note` | TEXT | yes | — | free detail |
| `disposition` | VARCHAR(30) | yes | INDEX | WHERE it went (§3). **Write-guarded: removal leg is atomic — reason + disposition + odometer set together or 422** |
| `replaced_by_component_id` | BIGINT UNSIGNED | yes | self-FK **nullOnDelete** | successor chain — comeback intelligence walks this |
| `removal_maintenance_id` | BIGINT UNSIGNED | yes | FK → maintenances **nullOnDelete** | which ticket caused removal |
| `created_at`/`updated_at` | TIMESTAMP | — | — | |

**Composite indexes:** `(vehicle_id, status)` — the profile "current components" read; `(component_catalog_id, vehicle_id, position, status)` — predecessor lookup at install time (the hot path); `(status, location)` — warehouse inventory; `(warranty_until)`; `(brand)`, `(part_number)`, `(serial_no)`.

**Uniqueness invariant (service-enforced, not DB):** at most one `active` row per (vehicle_id, component_catalog_id, position). Enforced with `lockForUpdate` on predecessor select inside the install transaction (house pattern from `PartWorkflowService::purchase`). Not a DB unique key because NULL positions and historical rows would fight it.

## 2.3 `component_events`

**Purpose:** append-only ledger of everything that ever happened to a component. The biography. Never updated, never deleted (cascade only if the component row itself is destroyed, which policy forbids).

| Column | Type | Null | Index / FK | Why it exists |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED PK | no | PK | |
| `vehicle_component_id` | BIGINT UNSIGNED | no | FK → vehicle_components **cascadeOnDelete**; composite idx `(vehicle_component_id, at)` | whose biography |
| `event` | VARCHAR(30) | no | INDEX | what happened (§3 event enum) |
| `from_vehicle_id` | BIGINT UNSIGNED | yes | FK → vehicles **nullOnDelete**; idx `(from_vehicle_id, at)` | transfers/removals: which car it left |
| `to_vehicle_id` | BIGINT UNSIGNED | yes | FK → vehicles **nullOnDelete**; idx `(to_vehicle_id, at)` | installs/transfers: which car it joined |
| `odometer` | INT UNSIGNED | yes | — | reading of the vehicle involved at event time |
| `maintenance_id` | BIGINT UNSIGNED | yes | FK → maintenances **nullOnDelete** | causing ticket (nullable — warehouse moves have none) |
| `maintenance_task_id` | BIGINT UNSIGNED | yes | FK → maintenance_tasks **nullOnDelete** | causing fault |
| `actor_id` / `actor_name` | FK users nullOnDelete / VARCHAR(120) | yes | — | who did it |
| `at` | DATETIME | no | in composite idxs | business time (≠ created_at: backfill writes historical `at`) |
| `note` | TEXT | yes | — | |
| `meta` | JSON | yes | — | disposition detail, warranty-claim reference, photo ids, transfer counterpart |
| `created_at`/`updated_at` | TIMESTAMP | — | — | |

Every event also mirrors one `vehicle_log_events` row (new constants `EVENT_COMPONENT_INSTALLED/REMOVED/TRANSFERRED/DISPOSED`) via the existing `VehicleLogService` — the Vehicle Timeline shows asset movement with zero new frontend joins. Mirror is display-only.

## 2.4 `service_records`

**Purpose:** immutable facts about performed ACTIONS (labor): what was done, when, by whom, at what mileage, with what result. Not a workflow (§3.4) — the ticket carries the pre-life.

| Column | Type | Null | Index / FK | Why it exists |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED PK | no | PK | |
| `vehicle_id` | BIGINT UNSIGNED | no | FK → vehicles **cascadeOnDelete**; composite idx `(vehicle_id, performed_at)`, `(vehicle_id, service_type, performed_at)` | every action happened to one car; second index answers "last oil change?" in one seek (replaces `last_by_category` scan) |
| `maintenance_id` | BIGINT UNSIGNED | yes | FK → maintenances **nullOnDelete** | workflow-born records carry their ticket; standalone cleaning/inspection = NULL |
| `maintenance_task_id` | BIGINT UNSIGNED | yes | FK → maintenance_tasks **nullOnDelete** | which fault's labor |
| `service_type` | VARCHAR(40) | no | in composite idx | canonical action key aligned with `ServiceReminder` types + findings categories (`oil_change`, `alignment`, `inspection`, `diagnostics`, `cleaning`, `programming`, `repair_labor`, `tyre_rotation`, …) |
| `description` | VARCHAR(255) | no | — | human line ("Engine oil + filter 5W-30") |
| `performed_at` | DATE | no | in composite idx | business date |
| `odometer` | INT UNSIGNED | yes | — | mileage anchor |
| `workshop_vendor_id` | BIGINT UNSIGNED | yes | FK → vendors **nullOnDelete**, INDEX | where done — workshop quality stats |
| `technician_name` | VARCHAR(120) | yes | — | who physically did it |
| `labor_cost` | DECIMAL(12,2) | yes | — | behind SHOW_FINANCIALS |
| `materials_cost` | DECIMAL(12,2) | yes | — | consumables used (the oil itself) — how consumables carry cost WITHOUT becoming components |
| `duration_hours` | DECIMAL(6,2) | yes | — | from existing `repair_hours` capture |
| `result` | VARCHAR(20) | no | — | `completed` \| `partial` \| `failed` |
| `related_component_id` | BIGINT UNSIGNED | yes | FK → vehicle_components **nullOnDelete** | "brake service ON these pads" — links action to asset when meaningful |
| `source` | VARCHAR(20) | no | INDEX | `workflow_close` \| `invoice_import` \| `manual` \| `legacy_backfill` — trust label |
| `source_line_item_id` | BIGINT UNSIGNED | yes | FK → maintenance_line_items **nullOnDelete**, INDEX | idempotency key (labor lines) |
| `source_invoice_item_id` | BIGINT UNSIGNED | yes | FK → invoice_items **nullOnDelete**, INDEX | idempotency key (service-log import) |
| `performed_by` / `performed_by_name` | FK users nullOnDelete / VARCHAR(120) | yes | — | actor |
| `created_at`/`updated_at` | TIMESTAMP | — | — | |

## 2.5 Existing-table touch (the ONLY one)

`maintenance_media`: add `vehicle_component_id` (FK → vehicle_components, nullOnDelete, INDEX, nullable) and `component_event_id` (FK → component_events, nullOnDelete, INDEX, nullable). Before/after photos ride the existing upload pipeline and viewers. Nothing else in any existing table changes.

---

# 3. Status Design (final enums — deliberately small)

Reconciliation with the proposed lists: `TRANSFERRED` and `REMOVED` are **events, not statuses** — a transferred component is simply `active` on another vehicle; a removed component is either back in stock or gone. Making them statuses would create rows that are simultaneously "removed" and "on a shelf", and a state machine with unreachable corners. The disposition column carries the "where did it go" answer the `REMOVED` status was trying to encode.

### 3.1 Component `status` — 3 values

| Constant | Value | Meaning |
|---|---|---|
| `STATUS_IN_STOCK` | `in_stock` | exists, installable, not on any vehicle |
| `STATUS_ACTIVE` | `active` | installed on exactly one vehicle |
| `STATUS_RETIRED` | `retired` | permanently out (scrapped/sold/returned) — terminal |

### 3.2 Component `location` — 6 values

| Constant | Value | Valid with status |
|---|---|---|
| `LOC_ON_VEHICLE` | `on_vehicle` | active only |
| `LOC_WAREHOUSE` | `warehouse` | in_stock |
| `LOC_REFURB` | `refurb` | in_stock (being reconditioned, not installable until flipped to warehouse) |
| `LOC_SUPPLIER` | `supplier` | retired (returned/warranty-returned) |
| `LOC_SCRAPPED` | `scrapped` | retired |
| `LOC_SOLD` | `sold` | retired (standalone sale AND sold-with-vehicle; `meta`/disposition distinguishes) |

Valid (status, location) pairs — everything else is rejected by the model guard:
`active+on_vehicle` · `in_stock+warehouse` · `in_stock+refurb` · `retired+supplier|scrapped|sold`.

### 3.3 `removal_reason` — 8 values (WHY it came off)

`failed` · `worn_out` · `accident` · `upgrade` · `recall` · `transfer` (moved to another vehicle) · `vehicle_sold` · `unknown_legacy` (backfill only — never selectable in UI).

Note: `warranty_return` from the draft list is a **disposition**, not a reason — the part came off because it `failed`; warranty return is where it went.

### 3.4 `disposition` — 7 values (WHERE it went)

`stored` (→ in_stock/warehouse) · `scrapped` · `returned_supplier` · `warranty_return` (subtype of supplier return that opens a `warranty_claimed` event + recovery tracking) · `sold` · `transferred` (→ stays active, new vehicle) · `sold_with_vehicle` · `unknown_legacy` (backfill only).

*(8 with the legacy value; UI shows 6 — transfer has its own flow.)*

### 3.5 `component_events.event` — 9 values

`purchased` · `stored` · `installed` · `removed` · `transferred` · `disposed` · `returned_supplier` · `warranty_claimed` · `sold`.

### 3.6 State machine (complete)

```
                 (created by purchase/backfill/manual intake)
                          │
                          ▼
                     IN_STOCK ──────────── dispose from shelf ────────► RETIRED
                     │      ▲                                          (supplier /
             install │      │ remove + disposition=stored               scrapped /
                     ▼      │                                           sold)
                    ACTIVE ─┴───── remove + terminal disposition ─────► RETIRED
                     │  ▲
                     └──┘ transfer (remove from A + install on B, one transaction,
                           status never leaves ACTIVE, vehicle_id changes)
```

No other transitions exist. `RETIRED` is absorbing. Removal leg (reason + disposition + odometer + actor) is written atomically or not at all.

### 3.7 Service `result` — 3 values, no workflow

`completed` · `partial` · `failed`. Pre-life (requested/approved/in-progress) is the maintenance ticket's `workflow_status` — duplicating it here would create a second competing workflow and violate the Ticket-single-source-of-truth rule. A failed service's retry is a new ticket, not a state change.

---

# 4. Component Tracking Rules

| Mode | Examples | Identity | Quantity | Validation rules |
|---|---|---|---|---|
| **`serialized`** | engine, gearbox, battery, AC compressor, alternator, starter motor, ECU/expensive sensors, GPS tracker | `serial_no` **required** at creation (422 without). Individual history, individual warranty | always 1 | duplicate `serial_no` within same catalog entry + non-retired status → 409 (same physical part can't exist twice); transfer allowed; position usually NULL |
| **`batch`** | brake pads, brake discs, filters (tracked ones), tyres | serial optional (tyres: DOT code goes in `serial_no` when known, brand/model always) | `quantity` meaningful (set-of-4 vs each — catalog `notes` fixes the convention per entry, seeded explicitly) | position required when catalog has `position_scheme`; predecessor matching uses (catalog, vehicle, position) |
| **`consumable`** | engine oil, coolant, brake fluid, bulbs, wipers, AdBlue | none — **NEVER creates a `vehicle_components` row** | n/a | hard guard in the component service: catalog `tracking_mode=consumable` → refuse instantiation with explicit error; the action is recorded as a `service_records` row (`materials_cost` carries the fluid cost). Guard is a P0 test (§11.12) |

Validation summary (service layer, single choke point `ComponentService`):
1. serialized → serial required; batch+`position_scheme` → position required and ∈ scheme; consumable → refuse.
2. (status, location) pair must be valid (§3.2).
3. Install target vehicle must exist and not be `sold`/`disposed`.
4. Odometer readings on install/remove must be ≥ the component's previous event odometer for the same vehicle (no time travel; consistent with the platform's odometer-continuity discipline).
5. Removal is atomic: reason + disposition + odometer (+ photos when policy requires, §6) in one transaction.

---

# 5. Installation Workflow Design

Exact sequence when a part is installed via the maintenance workflow (Phase 2 wiring; single write-point = `PartWorkflowService::installPurchase`, extended):

```
Part request approved                        [existing — part_requests]
   ↓
Purchase recorded / part received            [existing — part_purchases; + ordered/received sub-state from audit Part I]
   ↓
INSTALL action (ticket under_repair, actor has parts perms)
   ↓
1. RESOLVE CATALOG      map purchase.category_key → component_catalog (fallback: manual pick in modal)
                        consumable? → STOP component path, offer service-record path instead
   ↓
2. PREDECESSOR CHECK    SELECT ... FOR UPDATE active row (vehicle, catalog, position)
   ├─ exists → REMOVAL DECISION REQUIRED (blocking modal, §6):
   │            reason + disposition + removed_odometer (+ photo per policy)
   │            → predecessor removal leg written + replaced_by linked
   │            → event: removed (from_vehicle, ticket, fault)
   └─ none  → continue (first component of this type on this slot)
   ↓
3. CREATE COMPONENT     status=active, location=on_vehicle, install leg from purchase
                        (supplier, cost, warranty_months → warranty_until auto-derived),
                        technician_name, installer_vendor_id = ticket's garage,
                        source_part_purchase_id, source=workflow
   ↓
4. EVENT + MIRROR       event: installed (to_vehicle, odometer, ticket, fault, actor)
                        → vehicle_log_events EVENT_COMPONENT_INSTALLED (+ REMOVED for predecessor)
   ↓
5. BILLING (unchanged)  kind=part maintenance_line_item exactly as today; component links to it
                        via source_line_item_id. Money model untouched.
   ↓
6. LABOR (optional)     labor_cost/hours input → kind=labor line item + service_records
                        row (repair_labor, related_component_id = new component)
   ↓
7. TIMELINE / UI        vehicle profile Components tab and Timeline reflect immediately;
                        warranty countdown starts
```

All of steps 2–6 run in **one DB transaction**. Failure anywhere = nothing written (matches the atomicity discipline of `purchase()`'s existing `lockForUpdate` transaction).

Manual (non-workflow) install — e.g. fitting a stored spare in our own yard — uses the same `ComponentService::install()` with `maintenance_id=NULL`, permission-gated (§9).

---

# 6. Removal / Replacement Workflow

Standalone removal (no immediate successor — e.g. stripping a part before sale, taking a damaged part off):

**Required fields (all mandatory, atomic):**

| Field | Rule |
|---|---|
| `removal_reason` | ∈ §3.3 (UI never offers `unknown_legacy`) |
| `disposition` | ∈ §3.4 — the "where did it go?" question that can NEVER be skipped |
| `removed_odometer` | ≥ installed_odometer; heals vehicle odometer context |
| `removed_by` | from auth; `technician_name` optional free text |
| photos | **required when** removal_reason ∈ (`failed`, `accident`) OR disposition = `warranty_return` (evidence for claims/liability); optional otherwise. Via `maintenance_media.component_event_id` |
| `removal_note` | required when reason = `accident` or disposition = `sold` (price/buyer in note + meta) |

**Consequences by disposition:** `stored` → status in_stock, location warehouse, vehicle_id NULL — appears in warehouse inventory. `warranty_return` → retired/supplier + `warranty_claimed` event opened with claim reference in meta; feeds recovery tracking (missed-recovery detector: failed+scrapped+warranty-still-valid = alert). `scrapped`/`sold`/`returned_supplier` → retired. Replacement flow = §5 step 2 (removal embedded in install, same fields, same modal).

---

# 7. Vehicle Sale Workflow

Trigger: vehicle status change → `sold` (or `disposed`), wherever that write happens (OM sync or manual).

1. **Gate:** count `active` components on the vehicle. If > 0, the sale-side flow presents the **component settlement list** — every active component with one decision each:
   - **Keep with vehicle** → removal leg: reason `vehicle_sold`, disposition `sold_with_vehicle`, status retired, location sold. Stays in the car's history forever.
   - **Remove to inventory** → reason `vehicle_sold`, disposition `stored`, status in_stock, location warehouse (GPS trackers, nearly-new tyres, batteries).
2. **Invariant:** ZERO components may remain `active` on a sold/disposed vehicle. A nightly integrity check (`anomalies` family) flags violations — because OM sync can flip a car to sold without UI interaction, the check is the safety net; the UI flow is the happy path.
3. **Asset export pack** (`GET /vehicles/{id}/asset-export`): components at sale (with remaining warranty), full component history, service history, open faults — JSON + printable. Attached to the sale decision; nothing disappears.

---

# 8. Backfill Strategy

Sources reviewed and what each can honestly become:

| Source | Volume/quality | Becomes | Cannot become |
|---|---|---|---|
| `part_purchases` **with install leg** (`installed_at` set) | best rows: supplier, price, qty, `installed_odometer`, `installed_by`, ticket link, `warranty_months` via linked line item | **real components** (`source=legacy_backfill`, full install leg) | dispositions of their predecessors (unknown) |
| `maintenance_line_items` kind=part not covered by a purchase | good: part_number, qty, unit_price, installed_on/odometer, warranty, tyre brand/DOT, vehicle_id, garage via ticket | **real components**; tyre lines → batch components with brand/DOT and position NULL (positions weren't recorded) | serials (absent), technician (absent), supplier (garage ≠ supplier — leave supplier NULL rather than guess) |
| `maintenance_line_items` kind=labor | labor cost + finding text | `service_records` (`repair_labor`, source=legacy_backfill) | — |
| `invoice_items` (Technical Service Log) | description + category + date + garage, money-free | `service_records` (source=invoice_import) | components (no part identity) |
| anything older/unmatched | — | **remains unknown — honestly.** No row invented | — |

**Rules:**
- Per (vehicle, catalog, position-or-NULL): the **latest** install becomes `active`; every earlier one gets removal leg `removal_reason=unknown_legacy`, `disposition=unknown_legacy`, `removed_at` = successor's install date, `removed_odometer` = successor's installed_odometer, status retired. `replaced_by` chain linked oldest→newest. This is inference of *sequence* (safe — a slot holds one part at a time), never inference of *facts* (dispositions stay unknown).
- **Idempotency:** `source_part_purchase_id` / `source_line_item_id` / `source_invoice_item_id` are the dedupe keys — re-running skips existing. Command shape: `php artisan components:backfill --dry-run|--vehicle=|--from=` with a reconciliation report (created/skipped/conflicts/vehicles-with-zero-data).
- **Safety:** backup-gated (`php artisan db:backup` first — house rule); purely additive (writes ONLY new tables); `--dry-run` default in the first release; PlateResolver-safe because all sources join by `vehicle_id`, never plate.

---

# 9. API Design (definition only — no implementation)

New permissions (RBAC `resource.action` convention): `components.view`, `components.manage` (install/remove/transfer/dispose), `components.backfill` (super-admin). Warehouse reads under `components.view`. Money fields in responses obey SHOW_FINANCIALS on the client as everywhere else.

**Vehicle-scoped reads**

| Endpoint | Purpose |
|---|---|
| `GET /vehicles/{id}/components` | CURRENT truth: active components with install data, warranty remaining, provenance links. Powers the Components tab |
| `GET /vehicles/{id}/component-history` | non-active rows + events touching this vehicle (including parts that later moved away); filters `?catalog=&from=&to=` |
| `GET /vehicles/{id}/service-records` | unified service history; `?type=&q=`; response includes `last_by_type` map (replaces `serviceHistory`'s `last_by_category` — old endpoint re-points here in Phase 4, same shape) |
| `GET /vehicles/{id}/asset-export` | sale pack (§7): components + history + services + warranties + open faults; `?format=json\|print` |

**Component-scoped actions**

| Endpoint | Purpose |
|---|---|
| `GET /components/{id}` | full biography: instance + events + media + linked services |
| `POST /components/{id}/remove` | standalone removal (§6): reason, disposition, odometer, note, photos. 422 on missing disposition |
| `POST /components/{id}/transfer` | A→B: `to_vehicle_id`, `position`, `from_odometer`, `to_odometer`, note. No ticket required. Atomic remove+install, one `transferred` event |
| `POST /components/{id}/install` | fit an `in_stock` spare onto a vehicle: `vehicle_id`, `position`, `odometer`, optional `maintenance_id`. Original warranty preserved |
| `POST /components/{id}/dispose` | shelf disposal of in_stock item: disposition (terminal set), note |

**Workflow-integrated**

| Endpoint | Purpose |
|---|---|
| `POST /maintenance-tickets/{id}/install-component` | the §5 flow when the part didn't come through a part_purchase (garage-supplied part): catalog, identity fields, cost, warranty, predecessor-removal payload embedded. (When the part DID come through the purchase flow, the existing `POST /part-purchases/{id}/install` gains the same predecessor/removal payload — one modal, two entry points) |
| `GET /maintenance-tickets/{id}/component-context` | for the Decide/confirm steps: active components matching the ticket's fault categories + warranty state + predecessor chain — the "is this failure related to a previous component?" answer, server-computed |

**Warehouse & catalog**

| Endpoint | Purpose |
|---|---|
| `GET /components?status=in_stock` | spares inventory (filter catalog/brand/location) |
| `POST /components` | manual intake (bought for stock, no vehicle yet): catalog, identity, supplier, cost → in_stock/warehouse + `purchased`/`stored` events |
| `GET /component-catalog` / `POST /component-catalog` (manage-gated) | catalog list/curation |

---

# 10. UI Design (screens, Phase 4)

**Vehicle Profile / Car-Status page (the tabbed enterprise page):**
- **Components tab** (replaces inference-based Parts view): two sections — *Current Components* (card per component: name/brand/serial/position, installed date+odometer, installer garage, warranty chip with remaining months — green/amber/red, cost behind SHOW_FINANCIALS) and *Component History* (removed/retired rows: reason badge + disposition badge + "replaced by ↓" chain links; `unknown_legacy` shown with an explicit "legacy record" tag).
- **Component timeline**: component events already appear in the existing Timeline tab via the `vehicle_log_events` mirror — new event-type filter chips only.
- **Service history**: existing view re-pointed to `service_records`; adds technician, duration, result badge; "last done per type" summary strip.
- **Warranty view**: strip on the Components tab — soonest-expiring warranties + "failed under warranty" flags.

**Workshop (ticket drawer / TaskRoutingModal area):**
- **Install component** step on the parts panel: catalog pick (pre-resolved from category), identity fields (serial enforced per tracking_mode), warranty, technician.
- **Old-part disposition modal** — the blocking "what happened to the old one?" dialog: reason + disposition + odometer + conditional photo requirement. Appears automatically when a predecessor exists (§5 step 2).
- **Component context card** on Decide/confirm: "This slot: Amaron battery, installed 41 days ago at Garage Y, warranty active until 2027-01 — this may be a warranty comeback."

**Warehouse:**
- **Spares inventory page** (`/components-inventory`): in_stock list with catalog/brand filters, per-item biography drawer, actions install-on-vehicle / dispose; intake button for stock purchases.

**Sale flow:**
- **Component settlement list** (§7): active components with keep-with-vehicle / strip-to-stock toggle per row; blocks completion while undecided; asset-export download.

---

# 11. Business Acceptance Tests (15 scenarios)

Format: Given / When / Then / DB changes / Expected UI.

**11.1 New battery installation (no predecessor)**
- **Given** vehicle 63249 has no battery component (fresh post-backfill slot); catalog "Battery 12V" = serialized.
- **When** workshop installs Amaron 80Ah serial AM-102 @150,300 km, warranty 18m, supplier Vendor-S, via ticket T.
- **Then** component active/on_vehicle; `warranty_until` = install+18m; no removal modal (no predecessor).
- **DB:** +1 `vehicle_components` (install leg full, source=workflow, source_part_purchase_id set), +1 `component_events` (installed, to_vehicle, ticket, fault), +1 mirrored `vehicle_log_events`, billing line unchanged.
- **UI:** Components tab shows battery with green warranty chip "18m remaining"; Timeline shows "Component installed".

**11.2 Brake pad replacement (batch + position + predecessor)**
- **Given** active batch component "Front brake pads" position `front` on vehicle A.
- **When** new pads installed same position; actor completes disposition modal: reason `worn_out`, disposition `scrapped`, removed_odometer.
- **Then** old row retired/scrapped with atomic removal leg + `replaced_by` → new row; new row active.
- **DB:** old row updated in place (never moved/deleted); +1 component row; +2 events (removed, installed); +2 log mirrors; optional labor → +1 `service_records` + kind=labor line.
- **UI:** History section shows old pads "worn out → scrapped, lived 38,000 km"; current shows new pads.

**11.3 Tyre rotation between vehicles**
- **Given** active tyre (Michelin, DOT 2325, front_left) on A.
- **When** `POST /components/{id}/transfer` to B front_left with both odometers; no ticket.
- **Then** SAME row now active on B; status never left `active`; identity/warranty/cost basis intact.
- **DB:** row `vehicle_id` A→B; +1 event (`transferred`, from=A, to=B, both odometers in meta); +2 log mirrors (one per vehicle timeline); `maintenance_id` NULL.
- **UI:** A's history shows "transferred to [B]"; B's current components shows the tyre with original install provenance; tyre biography shows full chain.

**11.4 Engine replacement (serialized, high value)**
- **Given** active engine ENG-123 on A, 210,000 km.
- **When** replacement engine ENG-987 installed via ticket; old engine: reason `failed`, disposition `stored` (candidate refurb).
- **Then** ENG-123 → in_stock/warehouse (NOT retired — it still exists as an asset), vehicle_id NULL; ENG-987 active; photos required (reason=failed).
- **DB:** removal+install legs, 2 events, media rows with `component_event_id`.
- **UI:** warehouse inventory lists ENG-123 with full biography; A shows new engine.

**11.5 Warranty return**
- **Given** battery failed after 5 months of a 12-month warranty.
- **When** removed with reason `failed`, disposition `warranty_return`, photo attached.
- **Then** retired/supplier; `warranty_claimed` event with claim ref in meta; recovery tracked.
- **DB:** removal leg, 2 events (removed + warranty_claimed), media row.
- **UI:** history badge "Warranty return"; warranty view counts one open claim; NO missed-recovery alert (correct path taken).

**11.6 Vehicle sold WITH components**
- **Given** car marked sold; 5 active components; settlement list: all "keep with vehicle".
- **Then** all 5 → retired/sold, reason `vehicle_sold`, disposition `sold_with_vehicle`; zero active on the car; export pack generated.
- **DB:** 5 removal legs + 5 events; no rows deleted.
- **UI:** sale flow blocks until all decided; asset-export downloadable; car's profile still shows full frozen history.

**11.7 Vehicle sold, components stripped**
- **Given** same, but GPS tracker + 4 nearly-new tyres → "remove to inventory".
- **Then** those 5 → in_stock/warehouse (vehicle_id NULL); rest sold_with_vehicle; integrity check finds zero active components on the sold car.
- **UI:** warehouse gains 5 items, each with provenance from the sold car.

**11.8 Spare component reused**
- **Given** in_stock battery (was stored 2026-03; original warranty until 2026-11).
- **When** installed on vehicle C.
- **Then** same row active on C; `warranty_until` UNCHANGED (no reset — original coverage); biography shows purchase → A → stored → C.
- **DB:** row update + `installed` event; install leg fields for the NEW install recorded on the event (row keeps original purchase provenance).
- **UI:** C shows battery with amber warranty chip reflecting original expiry.

**11.9 Same fault after repair (component-aware comeback)**
- **Given** AC compressor replaced 10 days ago (active, warranty 12m, supplier X, installer garage Y).
- **When** same-category fault confirmed on a new ticket.
- **Then** `component-context` returns the component, `in_warranty=true`, supplier X, installer Y, predecessor chain; recurring review context enriched; `warranty_claim`-style decision available per the audit Part I design.
- **DB:** no component writes at detection; review row carries component reference.
- **UI:** Decide/confirm step shows the component context card; recurring review page shows "component under warranty — claim candidate".

**11.10 Wrong diagnosis (component NOT consumed)**
- **Given** fault "engine problem" confirmed, engine mount part purchased; before install, delegate marks fault **Incorrect** (real issue: battery).
- **When** fault cancelled via the Incorrect path.
- **Then** NO component row was created (install never happened); the purchased part goes to stock via manual intake (in_stock/warehouse) instead of being force-installed; battery fault proceeds separately.
- **DB:** part_purchase stays `purchased`; +1 component row only via the stock-intake action; zero writes from the cancelled fault.
- **UI:** ticket parts panel shows purchase "not installed — moved to stock"; warehouse gains the mount.

**11.11 Missing serial for serialized part (negative)**
- **Given** catalog battery = serialized.
- **When** install submitted without `serial_no`.
- **Then** 422 "serial number required for serialized components"; nothing written (atomic).
- **DB:** zero changes. **UI:** field-level error in install modal.

**11.12 Consumable installation (negative guard)**
- **Given** catalog "Engine oil" = consumable.
- **When** any path attempts to create a component for it (workflow install or manual intake).
- **Then** hard refusal; the flow offers the service-record path; oil change on ticket close creates `service_records` (oil_change, materials_cost) + rolls ServiceReminder (existing behavior untouched).
- **DB:** zero `vehicle_components` rows for consumables — ever. **UI:** Components tab never shows oil; Service history does.

**11.13 Legacy data import**
- **Given** vehicle with 3 historical battery lines (2024, 2025, 2026) across line items/purchases.
- **When** `components:backfill` runs (after `db:backup`; then a second time).
- **Then** newest = active; two older = retired with `unknown_legacy` reason+disposition, removal dates = successor install dates; `replaced_by` chain linked; second run creates ZERO new rows (idempotency keys).
- **DB:** 3 component rows, events with historical `at`, sources = legacy_backfill.
- **UI:** history rows carry the explicit "legacy record" tag; no invented dispositions shown.

**11.14 Transfer without maintenance ticket**
- **Given** in_stock GPS tracker; vehicle D needs one today; no fault, no ticket.
- **When** `POST /components/{id}/install` with `maintenance_id` NULL (perm `components.manage`).
- **Then** allowed; component active on D; event has NULL ticket refs.
- **DB:** row update + event + log mirror. **UI:** D's Components tab and Timeline updated; no maintenance ticket anywhere — asset ops are ticket-independent by design.

**11.15 Component cost reporting**
- **Given** vehicle with 2 years of component installs + service records (+ legacy backfill).
- **When** TCO/cost view queried.
- **Then** per-vehicle totals = Σ component `purchase_cost` + Σ service `labor_cost`+`materials_cost`, each figure traceable (Explainability: `ComponentExplainer` walks to the install event/source line); brand-lifetime report returns AVG(life_km) per brand; non-AED components excluded from AED totals with a completeness note (financial source-of-truth rule: label perspective + completeness).
- **DB:** read-only. **UI:** money behind SHOW_FINANCIALS; every number clickable to its evidence.

---

## Approval checklist

- [ ] Enum sets (§3) — especially the 3-status model vs the draft 4-status list
- [ ] Set-vs-each quantity convention for batch parts (fixed per catalog entry at seed time)
- [ ] Photo-required policy matrix (§6)
- [ ] Sale-flow blocking behavior (§7) + nightly integrity check
- [ ] Backfill honesty rules (§8) — `unknown_legacy` visible in UI

*On approval: Phase 1 = 4 create-table migrations + `maintenance_media` extension + catalog seed config + model constants. No existing behavior changes until Phase 2.*
