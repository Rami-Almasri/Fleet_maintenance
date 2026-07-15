# Parts Purchase + Repair Intelligence — Design & Integration Plan

Status: **DRAFT for approval** · Date: 2026-07-15 · Branch target: `ui-overhaul-v1` (or a feature branch)

This is the pre-code design (Part 11 deliverables 1–4). No code is written until this is approved.

---

## 0. Guiding principle: layer, don't fork

The existing maintenance stack already models most of what this feature needs. The single biggest risk is creating a **parallel parts/cost system** that double-counts money or competes with the ticket as the source of truth. The design below **reuses** the existing cost chain and only adds what is genuinely missing.

### What already exists (verified in code)

| Requirement | Already in the codebase |
|---|---|
| A "part" record with SKU, price, qty, warranty, install date/odometer | `maintenance_line_items` (`kind='part'`, `part_number`, `unit_price`, `installed_on`, `installed_odometer`, `warranty_months`) |
| Fault as a first-class, routable entity | `maintenance_tasks` (per-fault container, `symptom`, `category_key`, `severity`, `root_cause_id`) |
| Part ↔ fault ↔ vehicle link | `maintenance_line_items.maintenance_task_id` + denormalised `vehicle_id` + `part_number` index |
| A "waiting for parts" hold | `workflow_status = awaiting_parts` + `orderParts()` / `partsReady()` service methods |
| Parts supplier as a party | `vendors.type = 'parts_supplier'` |
| Fault history / chronic-fault detection | `faultHistory()` (service 5201), `faultInsights` endpoint, `FaultHistoryInsight` UI |
| Cost roll-up chain | `line_item.line_total` → `task.parts_cost/labor_cost` → `ticket.parts_total/labor_total/cost` |
| Accountability/exception record pattern | `MaintenanceIncident`, `ResolvedTransferFlag` (best-effort write + `VehicleLogService` log + `notifyByPermission`) |
| Per-action user+timestamp audit | `*_by` / `*_at` column pairs + append-only `vehicle_log_events` (via `VehicleLogService`) |
| Role→permission enforcement | Spatie `permission:` middleware; `Gate::before` admin bypass; `EnsureUserActive` |
| Notifications to a role audience | `NotificationScanner::notifyByPermission(perm, payload, actorId)` |
| Money-UI gating | `SHOW_FINANCIALS` flag (frontend) |

### What is genuinely missing (the real scope)

1. A **first-class Part Request lifecycle** (Requested → Under Review → Approved → Purchased → Installed → Completed) with its own audit trail. Today `awaiting_parts` is a single coarse ticket flag — it can't answer *who requested what, why, and for which fault*.
2. A **customer-initiated** request path (vehicle + customer + part + reason). Today parts only originate garage-side, tied to a diagnosed finding.
3. A **Purchase record** with **supplier + currency + purchased_by + date**, linked to request/ticket/fault. Line items carry a price but no buyer, currency, or supplier attribution per line, and no distinct "purchase event".
4. **Duplicate-purchase detection** with a priority engine and an admin **investigation** that blocks silent acceptance.
5. **Fault-recurrence warning** surfaced at diagnosis (extends existing `faultInsights`).
6. A **part classification** (consumable / standard / major) driving alert priority.

---

## 1. Data model changes

**All changes are additive. Zero ALTERs to existing tables.** (The only touch point on `maintenance_line_items` is writing a new `entry_source` string value `'parts_purchase'`, which needs no migration since the column is a free string.)

### New table: `part_requests` (the lifecycle spine)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `source` | enum(`customer`,`garage`) | Case A vs Case B |
| `status` | string(20), indexed | `requested`,`under_review`,`approved`,`purchased`,`installed`,`completed`,`rejected`,`cancelled` |
| `vehicle_id` | FK vehicles, **required** | the asset the part is for |
| `customer_id` | FK customers, nullable | only for `source=customer` |
| `maintenance_id` | FK maintenances, nullable | garage-source ticket |
| `maintenance_task_id` | FK maintenance_tasks, nullable | the fault (Case B) |
| `part_name` | string | free text (e.g. "Engine mount") |
| `part_number` | string, nullable | SKU/OEM — primary duplicate-match key |
| `category_key` | string(60), nullable | findings-catalog category → classification |
| `part_class` | enum(`consumable`,`standard`,`major`), nullable | resolved by classifier at create |
| **`repair_location`** | **enum(`garage`,`onsite`), nullable** | **workshop repair vs technician-goes-to-vehicle; garage-source requests inherit the ticket's location** |
| `quantity` | decimal(10,2) default 1 | |
| `reason` | text | why requested |
| `estimated_price` | decimal(12,2) nullable | |
| `currency` | string(3) default `AED` | |
| `requested_by` / `requested_at` | FK users / ts | |
| `reviewed_by` / `reviewed_at` / `review_notes` | | Under Review stamp |
| `approved_by` / `approved_at` | | |
| `rejected_by` / `rejected_at` / `rejection_reason` | | |
| `notes` | text nullable | |
| timestamps | | |

Indexes: `(vehicle_id, status)`, `(part_number, vehicle_id)`, `(maintenance_id)`, `(status)`.

### New table: `part_purchases` (the money event + install bridge)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `part_request_id` | FK part_requests, nullable | usually set; nullable allows ad-hoc buy |
| `vehicle_id` | FK vehicles, required | denormalised for history/TCO |
| `maintenance_id` / `maintenance_task_id` | FK, nullable | fault linkage |
| `part_name` / `part_number` / `category_key` | | copied for immutable history |
| `part_class` | enum | snapshot |
| **`purchase_source`** | **enum(`garage`,`supplier`)** | **the system never assumes supplier — a garage can provide & sell the part** |
| `source_vendor_id` | FK vendors, nullable | the vendor the part was bought from — a `garage`-type vendor when `purchase_source=garage`, a `parts_supplier`-type when `supplier` |
| `source_name` | string, nullable | free-text fallback when no vendor row |
| **`repair_location`** | **enum(`garage`,`onsite`)** | **where the repair happens (in-workshop vs technician goes to the car)** |
| `purchase_price` | decimal(12,2), **required** | enforces "no purchase without price" |
| `currency` | string(3) default `AED` | |
| `quantity` | decimal(10,2) default 1 | |
| `purchased_by` / `purchased_at` | FK users / ts | responsible user + date (price history) |
| `installed_by` / `installed_at` / `installed_odometer` | nullable | enforces "no install before purchase" |
| **`result`** | **enum(`pending`,`success`,`failed`) default `pending`** | **repair outcome — feeds recurrence intelligence** |
| `maintenance_line_item_id` | FK maintenance_line_items, nullable | **cost bridge** — set on install |
| `requires_review` | bool default false | tripped by duplicate detection |
| `duplicate_of_purchase_id` | FK self, nullable | the earlier purchase it duplicates |
| `notes` | text nullable | |
| timestamps | | |

Indexes: `(vehicle_id, part_number)`, `(part_request_id)`, `(purchased_at)`, `(purchase_source)`.

> **Purchase source (change 1).** `purchase_source` distinguishes **Garage purchase** (the workshop provides & sells the part; `source_vendor_id` points at the `garage`-type vendor, linking the buy to that garage transaction) from **External Supplier purchase** (`source_vendor_id` points at a `parts_supplier`-type vendor). Both keep the responsible user + date + price history. The classifier and duplicate engine are **source-agnostic** — a part is the same part whether it came from the garage or a supplier.

### New table: `part_investigations` (admin duplicate/recurrence workflow)

Mirrors the `MaintenanceIncident` acknowledgement-gate pattern.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `type` | enum(`duplicate_purchase`,`fault_recurrence`) | |
| `priority` | enum(`low`,`medium`,`high`) | from the classifier rules |
| `status` | string(20) | `open`,`under_review`,`reason_provided`,`approved`,`rejected`,`closed` |
| `vehicle_id` | FK vehicles | |
| `part_purchase_id` | FK, nullable | the triggering purchase (duplicate) |
| `previous_purchase_id` | FK part_purchases, nullable | the prior one |
| `maintenance_task_id` | FK, nullable | the recurring fault |
| `previous_task_id` | FK maintenance_tasks, nullable | prior repair of that fault |
| `reason_code` | string, nullable | `previous_part_failed`,`wrong_diagnosis`,`customer_requested`,`accident_damage`,`other` |
| `reason_note` | text nullable | |
| `opened_by` / `opened_at` | | (system or user) |
| `reason_by` / `reason_at` | | who supplied the reason |
| `resolved_by` / `resolved_at` / `resolution` | | approve/reject |
| `context` | json | snapshot: prev date, prev cost, prev tech, days-between |
| timestamps | | |

Indexes: `(vehicle_id, status)`, `(status, priority)`.

### New models & relations
`PartRequest`, `PartPurchase`, `PartInvestigation` (Eloquent) with `belongsTo` vehicle/customer/maintenance/task/vendor/User(actor pairs) and `hasMany` purchases on request. **All actor FKs are nullable and we snapshot the actor's name** into `context`/notes so a later-disabled user never breaks history rendering (edge case 8).

### Audit trail
Two layers, matching existing convention:
- Per-action `*_by`/`*_at` columns on each table (above).
- Append-only events via `VehicleLogService` with new `EVENT_*` constants (`part_requested`, `part_approved`, `part_purchased`, `part_installed`, `part_duplicate_flagged`, `part_recurrence_flagged`) so the actions appear in the **vehicle timeline** alongside maintenance events. No new events table needed.

---

## 2. Workflow / business-logic changes

### 2.1 Part Request lifecycle (Part 2 rules enforced server-side)
- **Requested → Under Review → Approved → Purchased → Installed → Completed** (+ Rejected/Cancelled off-ramps).
- Guard: **cannot mark Purchased without a price** → `purchase_price` is `required|gt:0` on the purchase endpoint.
- Guard: **cannot Install a part that was never purchased** → install endpoint requires an existing `part_purchase` row for the request.
- Every transition writes `*_by/*_at` + a `vehicle_log_events` row.

### 2.2 Purchasing (Part 3) — with the cost bridge (critical)
When a purchased part is **installed** against a fault/ticket, we generate a `maintenance_line_item` (`kind='part'`, `entry_source='parts_purchase'`, `unit_price=purchase_price`, `part_number`, `installed_on`, `installed_odometer`, `category_key`, `maintenance_task_id`, `maintenance_id`) and store its id on `part_purchases.maintenance_line_item_id`. **Cost then rolls up through the existing chain** (line_item → task → ticket). This is what prevents a parallel cost ledger and double-counting. Customer-source purchases with no ticket produce **no** line item — cost lives only on the purchase.

### 2.3 Duplicate-purchase detection (Parts 4 & 7) — the priority engine
New `PartIntelligenceService` + `config/parts_intelligence.php` (admin-tunable later). On purchase creation, inside a **DB transaction with `lockForUpdate`** on prior-purchase rows (edge case 6 — no race duplicates):

1. **Classify** the part (consumable / standard / major) by `category_key` + keyword match against config lists (`engine`, `transmission`, `ecu`, `turbo`, `gearbox` → major; `oil filter`, `air filter`, `brake pads`, `wiper` → consumable; else standard).
2. **Find prior** purchase/install of the same part identity (`part_number` if present, else normalised `part_name`) on the **same vehicle**.
3. Apply the window rules → priority:

| Class | Rule | Result |
|---|---|---|
| consumable | any repeat | **suppressed** (no alert) — edge case 3 ✓ |
| any (non-consumable) | repeat **≤ 7 days** | **High** — edge case 1 ✓ |
| major | repeat **≤ 90 days** | **High/Critical** — edge case 4 (ECU twice/month) ✓ |
| standard | repeat **≤ 60 days** | **Medium** — Part 7 medium ✓ |
| any | repeat **> window** (e.g. 1 year) | none/low info — edge case 2 ✓ |

4. If an alert fires: mark `purchase.requires_review=true`, link `duplicate_of_purchase_id`, **open a `part_investigation`** (status `open`, or `reason_provided` if the caller supplied a reason), and **`notifyByPermission('parts.investigate', …, HIGH)`** to admins. The purchase is recorded but **flagged, not silently accepted** — the UI forces a reason picker (`previous_part_failed` / `wrong_diagnosis` / `customer_requested` / `accident_damage` / `other`) before the buyer can proceed, and a `GET …/duplicate-check` pre-call surfaces the prior repair (date, cost, tech) exactly as in the Part 4 example message.

### 2.4 Fault-recurrence warning (Parts 5 & 6)
Extends the **existing** `faultInsights` / `diagnosticContext`. When a new fault is created (`syncFromFindings`) or viewed at Decide, for each fault's `category_key`/`symptom` we query prior **completed** `maintenance_tasks` on the vehicle within a window (~60–90 d). If found, surface a warning with previous date, `resolved_by` (technician), parts used (that task's line items), and prior cost — reusing the `FaultHistoryInsight` component. A recurrence within a **short** window (≤ ~30 d) can additionally open a `part_investigation` of type `fault_recurrence` (edge case 5 ✓). This is read-mostly; no rebuild of fault history.

### 2.5a Repair location (change 2) — garage vs on-site
Both the request and the purchase carry `repair_location`:
- **`garage`** — vehicle is physically in the workshop (aligns with the ticket's existing `repair_location='in_shop'`).
- **`onsite`** — the technician travels to the vehicle and diagnoses/repairs there (aligns with the ticket's existing `repair_location='on_site'` / `on_site_pending` state).

For a garage-source request the value is **inherited from the parent ticket** so we don't fork the vocabulary; for a standalone/customer request it is captured directly. On-site repairs never require a transport leg, so they do **not** engage the logistics/driver flow (see 2.5c).

Example captured end-to-end: *Ford Mustang · fault: Battery issue · location: on-site · technician: Ahmed · part: Battery · purchase source: Supplier.*

### 2.5b The four intelligence cases (change 4) — one unified history
All four combinations of `purchase_source` × `repair_location` must produce the **same** intelligence record shape:
**Vehicle + Fault + Repair + Part + Purchase Source + Technician + Result.**

| Case | `repair_location` | `purchase_source` | Notes |
|---|---|---|---|
| 1 | `garage` | `garage` | Repaired in workshop, part sold by the garage → `source_vendor_id` = that garage |
| 2 | `garage` | `supplier` | Repaired in workshop, part from external supplier → `source_vendor_id` = parts_supplier |
| 3 | `onsite` | `supplier` | Technician on-site, part brought from a supplier |
| 4 | `onsite` | `garage` | Technician on-site using **garage stock** → `purchase_source=garage`, `source_vendor_id` = the garage the stock came from |

The `GET /Vehicle/{vehicle}/part-history` endpoint and the recurrence engine read the same unified projection across all four cases — the two dimensions are just tags on the record, never separate code paths.

### 2.5c Driver availability (change 3) — Busy only in transit
Driver presence must reflect that a driver is **Busy only while actually transporting a vehicle**, not while idle-assigned or while the car sits in the garage.

Mapping onto the existing logistics claim lifecycle (`dispatched → en_route → picked_up → delivered → returned`) and `TeamPresenceController`:

| Driver situation | Logistics state | Presence |
|---|---|---|
| Vehicle assigned, pickup not yet accepted | `dispatched` (pooled/assigned) | **Available** |
| Accepts pickup / driving A→B | `en_route`, `picked_up` | **Busy (transporting)** |
| Arrives / hands off | `delivered`, `returned` | **Available** |
| Vehicle inside garage / repair underway | (no active transit leg) | **Available** |

Change: `TeamPresenceController` presence derivation counts a driver as Busy **only** when they own a logistics task in an active-transit state (`en_route`/`picked_up`), not merely an assigned/`dispatched` one, and never for `under_repair`/garage-dwell time. On-site part repairs (2.5a) create no transit leg and therefore never mark a driver Busy.

### 2.5 Interplay with the existing `awaiting_parts` hold
Recommendation: a **garage-source** part request created for a ticket in `recommendation_pending` optionally moves the ticket to `awaiting_parts` (reusing `orderParts()`); when its part requests reach `purchased`/`installed`, `partsReady()` becomes available. The two coexist — the request feeds the existing hold rather than replacing it. **(Open decision — see below.)**

---

## 3. API changes (new endpoints)

Prefix groups, all `auth:sanctum`:

**`/part-requests`**
- `GET /` `parts.view` — board/list (filters: status, vehicle, source)
- `POST /` `parts.request` — create (customer or garage source)
- `GET /{id}` `parts.view`
- `POST /{id}/review` `parts.investigate|maintenance.manage`
- `POST /{id}/approve` `parts.investigate|maintenance.manage`
- `POST /{id}/reject` `parts.investigate|maintenance.manage`
- `POST /{id}/purchase` `parts.purchase` — creates purchase, runs duplicate engine (price required)
- `POST /{id}/complete` `parts.request|maintenance.manage`

**`/part-purchases`**
- `GET /` `parts.view` — purchase ledger
- `GET /duplicate-check?vehicle_id=&part_number=&part_name=` `parts.purchase` — pre-buy check
- `POST /{id}/install` `parts.purchase|maintenance.logistics` — creates the line-item cost bridge

**`/part-investigations`**
- `GET /` `parts.investigate` — admin inbox
- `POST /{id}/review|provide-reason|approve|reject|close` `parts.investigate`

**Reads for accountability**
- `GET /Vehicle/{vehicle}/part-history` `parts.view` — full repair+parts history (Part 8 admin view)
- Recurrence folds into existing `GET /maintenance-tickets/{ticket}/diagnostic-context`.

Follows the codebase's POST-for-update convention and typed-`$actor` service pattern (controllers resolve `$request->user()`, never trust a client user id).

---

## 4. Permission changes

Add four permissions to `RolesAndPermissionsSeeder` (idempotent re-sync):

| Permission | Meaning |
|---|---|
| `parts.view` | see requests / purchases / history |
| `parts.request` | create a part request + diagnosis-linked request |
| `parts.purchase` | record a purchase + install |
| `parts.investigate` | review duplicate alerts, approve exceptions (admin) |

Proposed role grants (super-admin/admin auto via `Gate::before`):
- `manager`: all four (full reports + exceptions)
- `maintenance`: view, request, purchase, investigate
- `operations`: view, request, purchase
- `inspector` / `supervisor` / `logistics`: view, request
- `finance`: view
- `viewer`: view

**(Open decision:** whether to also add a dedicated `purchasing` role, per your "Purchasing user" — see below.)

---

## 5. Frontend changes
- **`/parts`** — Parts board (lanes by status; ops components on dark surface, or a `DataTable`), with the customer-source and garage-source create modals and the purchase modal (supplier/price/currency/date, buyer auto = current user) that runs the `duplicate-check` pre-call and shows the **HIGH alert + reason picker** inline.
- **`/part-investigations`** — admin duplicate/recurrence inbox, modeled on `FindingKeywords.js` CRUD template.
- **Ticket integration** — recurrence warning via `FaultHistoryInsight` in the Decide step / detail drawer; a "Request Part" action on a fault that prefills the garage-source request.
- Nav entries under the **Maintenance** section, gated by `parts.view` / `parts.investigate`. All price displays gated behind `SHOW_FINANCIALS`; record entry stays always-on (established convention).

---

## 6. Edge-case coverage (Part 10)

| # | Scenario | Handling |
|---|---|---|
| 1 | Same part twice in 3 days | Short-window (≤7d) rule → **High** |
| 2 | Same part after 1 year | Outside window → no critical alert |
| 3 | Oil filter repeatedly | Consumable class → **suppressed** |
| 4 | ECU twice in a month | Major within 90d → **Critical** |
| 5 | Fixed fault returns in 2 weeks | Recurrence warning + optional investigation |
| 6 | Two users purchase at once | DB transaction + `lockForUpdate` on prior rows; no duplicate records |
| 7 | Old records without part history | Detection queries return empty; classifier defaults to `standard`; no crash |
| 8 | Disabled user, prior repairs exist | Actor FKs nullable + name snapshot; history renders regardless |

---

## 7. Business-logic risks (Part 11 deliverable)

1. **Part identity matching is fuzzy.** `part_number` is optional/free-text; falling back to normalised `part_name` risks false positives/negatives. Mitigation: prefer `part_number`; keep windows generous; investigations are reversible (admin rejects false alerts). A future parts catalog would harden this.
2. **Cost double-count if a purchased part is *also* billed on a garage invoice line.** Mitigation: `entry_source='parts_purchase'` tagging + one purchase→one line-item link; surface conflicts in Oversight; document "install via parts flow OR garage line item, not both."
3. **Multi-currency.** Purchases store `currency`, but the roll-up cost chain assumes AED. v1: only AED feeds the line-item cost; non-AED purchases are flagged, not auto-converted.
4. **`awaiting_parts` coexistence** could confuse two "parts waiting" concepts if not wired carefully (see open decision 1).
5. **Customer-source requests for non-fleet vehicles / no ticket** are pure records with no cost rollup — acceptable, but worth confirming they should exist at all.

---

## 8. Open decisions (need your call before I build)

1. **`awaiting_parts` interplay** — should a garage-source part request auto-drive the ticket into the existing `awaiting_parts` hold (my recommendation), or stay a separate tracker that never touches `workflow_status`?
2. **Dedicated `purchasing` role** — add one, or just grant `parts.purchase` to existing `operations`/`maintenance`/`manager`? (I lean: no new role; reuse existing.)
3. **Customer-source scope** — do customer part requests apply only to fleet vehicles already in `vehicles`, or also walk-in/non-fleet cars (which we don't currently store)? I assume fleet-only for v1.
4. **Detection windows & class lists** — confirm the default windows (7d short / 60d standard / 90d major) and the major/consumable keyword lists, or give me your own thresholds.
5. **Delivery order** — I recommend shipping in phases: (P1) migrations + models + request/purchase lifecycle + permissions; (P2) duplicate engine + investigations + notifications; (P3) recurrence wiring; (P4) frontend. Each phase build- and test-verified before the next.

---

## 9. AS-BUILT (post-implementation) — Part 11 deliverables

### 9.1 Database changes (all additive; migrated clean against live MySQL `laravel`)
- `2026_07_15_160000_create_part_requests_table.php`
- `2026_07_15_160100_create_part_purchases_table.php` (includes `purchase_source`, `source_vendor_id`, `repair_location`, `result` per changes 1/2/4; self-referencing `duplicate_of_purchase_id`)
- `2026_07_15_160200_create_part_investigations_table.php`
- No ALTERs to existing tables. Installed-part cost lands as a `maintenance_line_items` row with **`entry_source='purchase'`** (the column is `varchar(12)`, so the tag is `purchase`, not `parts_purchase`).

### 9.2 API changes (17 routes, all registered & verified)
`POST/GET /part-requests` (+ `/{id}` `/review` `/approve` `/reject` `/purchase` `/complete`) ·
`GET /part-purchases` `/duplicate-check` `/recurrence-check` `/vehicle/{vehicle}/history`, `POST /part-purchases/{id}/install` ·
`GET /part-investigations` (+ `/{id}/review` `/provide-reason` `/approve` `/reject`).

### 9.3 Permission changes
Added `parts.view`, `parts.request`, `parts.purchase`, `parts.investigate` to `RolesAndPermissionsSeeder` and granted per the matrix in §4 (no new role). Re-seeded (idempotent).

### 9.4 Change 3 (driver availability) — satisfied by concurrent work
While implementing, the tree gained a dedicated **`DriverAvailabilityService`** (+ `LogisticsTask::TRANSPORT_STATUSES` / `scopeInTransport()`), which `TeamPresenceController` now delegates to. It makes a driver Busy **only** on an active transport leg (en_route/picked_up, plus the maintenance drop-off `in_transit` and collected return leg) and Available while assigned-not-accepted, at the garage, or during repair — exactly change 3, and more thorough than a status filter. My redundant `TRANSIT_STATUSES` constant was removed. **No further work needed.**

### 9.5 Verified test scenarios (exercised live via the service layer against real data, then cleaned up)
| # | Scenario | Result |
|---|---|---|
| Classifier | Engine mount/Turbocharger/ECU → major; Oil filter/Brake pads → consumable; Battery → standard | ✓ |
| Edge 1 | Battery re-bought after 3 days | **HIGH** duplicate ✓ |
| Edge 2 | Alternator (major) after 365 days | no alert ✓ |
| Edge 3 | Oil filter repeated | suppressed (consumable) ✓ |
| Edge 4 | ECU re-bought after 20 days | **HIGH** (major window) ✓ |
| Edge 5 | "Engine noise" fault completed, re-checked | recurrence FOUND (days_ago, technician, escalate) ✓ |
| Edge 7 | Part with no prior history | no alert, no crash ✓ |
| Full flow | garage request → approve → purchase (dup HIGH → investigation opened, `requires_review`) → install → **line item created, `task.parts_cost 450 → ticket.parts_total 450` via existing roll-up**, request→installed | ✓ |
| Currency | USD purchase installed | line `unit_price=0` + "(paid 30.00 USD, not converted)"; AED total not polluted ✓ |
| Edge 6 | Concurrent purchase | `detectDuplicate(lock:true)` runs inside the purchase `DB::transaction` with `lockForUpdate` on prior rows ✓ (by construction) |
| Edge 8 | Disabled user with prior repairs | actor FKs nullable + `*_by_name` snapshots → history renders regardless ✓ (by construction) |

### 9.6 Notes / residual risks
- The `part_duplicate` bell alert lands in the catch-all `other` category (still delivered to `parts.investigate` holders) — acceptable; can be added to `NotificationCategories::MAP` later if a dedicated tab is wanted.
- Cost double-count guard (a purchased part also billed on a garage invoice line): tagged `entry_source='purchase'` + one purchase→one line-item link; recommend surfacing in Oversight later.
- Frontend (`/parts`, `/part-investigations`) + nav/route wiring is Phase 4.
