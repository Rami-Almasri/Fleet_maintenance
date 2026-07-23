# Asset Layer — Phase 2 Workflow Design

**Date:** 2026-07-23 · **Status:** FOR APPROVAL (design only — no code) · **Parents:** `Asset-Layer-Implementation-Plan.md` (approved roadmap), `Asset-Layer-Database-Design.md` (approved schema, Phase 1 shipped & verified).

**Scope:** exactly how the Asset Layer connects to the LIVE maintenance workflow. Phase 1 shipped the tables/models/permissions dark (`features.asset_layer = off`). Phase 2 wires the writes. The goal stays: **FleetView knows the physical truth of every vehicle at any moment** — and the constraint stays: the ticket state machine is never driven by asset writes.

Code anchors referenced below (verified in the current tree): `PartWorkflowService::installPurchase` (the existing install write-point), `PartRequestController` install validation (`installed_odometer`, `warranty_months`, `result`, `notes`), `MaintenanceWorkflowService::confirmRoutineServices` (the single re-inspection-PASS service sync), `RecurringFaultService::onFaultConfirmed` (comeback review), `VehicleLogService` (timeline mirror), frontend `TicketParts.js` (ticket parts panel), `VehicleComponent` Phase 1 guards.

---

## 1. Scenario: Part replacement (battery — the canonical flow)

**Cast:** ticket T `under_repair` on vehicle V; confirmed fault "battery dead"; part request → approved → `part_purchases` row P (supplier, price); V currently has active component OLD (battery, installed 14 months ago).

**Trigger:** the existing install action — `POST /part-purchases/{P}/install` — with a Phase 2-extended payload:

```json
{
  "installed_odometer": 150300,
  "warranty_months": 18,
  "result": "success",
  "notes": "…",

  "component": {                        // NEW — asset identity (required in enforced mode
    "serial_no": "AM-102",              //        when the resolved catalog is serialized)
    "brand": "Amaron",
    "model": "80Ah",
    "position": null,
    "technician_name": "Salim"
  },
  "predecessor": {                      // NEW — REQUIRED whenever an active component
    "removal_reason": "failed",         //        occupies the slot (422 otherwise)
    "disposition": "warranty_return",
    "removed_odometer": 150300,
    "removal_note": "5 months into 12m warranty",
    "photo_media_ids": [881, 882]
  }
}
```

**System sequence (one flow, §7 defines the transaction):**

1. **Catalog resolve** — map `P.category_key` → `component_catalog` (curated mapping + fallback picker in the modal). Consumable catalog → the component path is REFUSED (Phase 1 model guard); the modal offers "record as service" instead.
2. **Predecessor check** — `VehicleComponent::forSlot(catalog, V, position)` under `lockForUpdate`. OLD found → the payload's `predecessor` block becomes mandatory. Missing → **422 "What happened to the old battery?"** with the disposition options. This is the never-disappear gate.
3. **Close OLD in place** — removal leg written atomically: `removed_at/odometer/by(+name)`, `removal_reason`, `disposition`, `removal_note`, `removal_maintenance_id = T`, `replaced_by_component_id = NEW`; status/location per disposition (`warranty_return` → `retired/supplier`; `stored` → `in_stock/warehouse` + vehicle_id NULL; `scrapped` → `retired/scrapped`). Photos attach via `maintenance_media.component_event_id`.
4. **Create NEW** — `status=active, location=on_vehicle, vehicle_id=V`; install leg from P + payload: `installed_at=now`, `installed_odometer`, `installed_by(+name)` = acting user, `technician_name`, `installer_vendor_id = T->vendor_id` (the garage doing the work), `supplier_vendor_id = P.source_vendor_id`, `purchase_cost = P.purchase_price`, `currency`, `warranty_months` (→ `warranty_until` auto-derived by the Phase 1 hook), `source_part_purchase_id = P`, `source=workflow`.
5. **Events** — `removed` on OLD (from_vehicle=V, ticket, fault, odometer) + `installed` on NEW (to_vehicle=V, ticket, fault, odometer); `warranty_return` disposition ALSO writes `warranty_claimed` on OLD with claim context in `meta`. Each event mirrors one `vehicle_log_events` row (`EVENT_COMPONENT_REMOVED` / `EVENT_COMPONENT_INSTALLED`).
6. **Billing unchanged** — the existing `installPurchase` body still writes the `kind=part` line item and bridges cost to the ticket exactly as today; NEW links to it via `source_line_item_id`.
7. **Optional labor** — `labor_cost`/`labor_hours` in the payload → existing `kind=labor` line + a `service_records` row (`repair_labor`, `related_component_id = NEW`).

**History outcome:** V's Components tab shows Amaron (installed date/odometer/technician/supplier/warranty countdown) under CURRENT and Bosch under HISTORY ("failed → warranty return, lived 41,900 km · replaced by ↑"); the tyre-style biography of each battery is its event list; the Timeline shows both movements.

## 2. Scenario: Wrong diagnosis (alternator bought, battery was the problem)

Rule: **purchase ≠ installation.** A `part_purchases` row only becomes a component at the install action — never automatically.

- Fault "alternator" is marked **Incorrect** (existing merged path) → the fault is cancelled. The alternator purchase P stays `result=pending`, never installed, **zero component writes**.
- The ticket parts panel (TicketParts.js) gains a "Move to stock" action on any purchased-but-uninstalled part of a terminal fault: `POST /components/intake` referencing P → creates a component `in_stock/warehouse` (identity from P, provenance `source_part_purchase_id=P`, `source=workflow`) + `purchased`/`stored` events. The part is now visible in the warehouse and installable on any vehicle later (Scenario 3 mechanics).
- If nobody acts, the nightly `components:integrity` scan flags "purchased ≥ N days ago, never installed, fault terminal" so bought parts can't silently evaporate either.
- The real battery fault proceeds as Scenario 1 on its own purchase.

## 3. Scenario: Component transfer (tyre A → B, no ticket)

`POST /components/{id}/transfer` (perm `components.manage`) — asset operation, `maintenance_id` NULL by design:

```json
{ "to_vehicle_id": 482, "position": "front_left",
  "from_odometer": 88200, "to_odometer": 61050, "note": "…" }
```

One transaction: (a) guard — component must be `active` or `in_stock`; target vehicle not sold/disposed; target slot free (else 409 with the occupying component, offering a chained replacement); odometer continuity per vehicle; (b) same row updates — `vehicle_id A→B`, position set, status stays/becomes `active`; identity, warranty (**original `warranty_until`, never reset**), and cost basis untouched; (c) ONE `transferred` event with `from_vehicle_id=A, to_vehicle_id=B`, both odometers (`odometer` = to-side, from-side in `meta`); (d) two timeline mirrors (one per car).

History: A shows "tyre transferred to [B] on {date} @88,200 km" in component history; B shows the tyre under CURRENT with its full pre-B biography one click away.

## 4. Scenario: Vehicle sale — component settlement

Trigger: the **manual** sale/status action in FleetView. (OM sync can also flip a car to sold — deliberately NOT hooked inline; the integrity scan is the net.)

1. Sale flow calls `GET /vehicles/{id}/components?status=active`. If >0, the **settlement list** blocks completion: one decision per component — *Keep with vehicle* (removal leg: reason `vehicle_sold`, disposition `sold_with_vehicle` → `retired/sold`, vehicle_id KEPT for history) or *Remove to stock* (disposition `stored` → `in_stock/warehouse`, vehicle_id NULL).
2. `POST /vehicles/{id}/component-settlement` applies all decisions in one transaction + events + mirrors, then returns the **asset export** link (`GET /vehicles/{id}/asset-export`).
3. Invariant: zero `active` components on a sold/disposed vehicle. `components:integrity` (nightly) flags violations (covers the OM-sync path) → notification to `maintenance.manage`.

Nothing disappears: sold-with-car components stay in the car's frozen history; stripped ones live on in the warehouse with provenance.

## 5. Scenario: Warranty comeback (battery fault 3 months after install)

Read-side context, served BEFORE money is spent:

- **`GET /maintenance-tickets/{id}/component-context`** — for each fault category on the ticket, the active component in that slot: identity, `installed_at/odometer`, `warranty_until` + `in_warranty` flag, `supplier`, `installer` garage, predecessor chain, and prior `repair_inspections`/`recurring_fault_reviews` touching that slot. Surfaced as a card in the Decide step and the workshop confirm panel: *"This slot: Amaron battery, installed 92 days ago at Garage Y from Supplier X — warranty active until 2027-01. Possible warranty comeback."*
- **`RecurringFaultService::onFaultConfirmed`** (existing) additionally stores that context into the review's `context` JSON and the repair-gate card — so the manager deciding the money guard sees warranty/supplier/installer without leaving the page. The claim itself is executed via Scenario 1's `warranty_return` disposition (+ `warranty_claimed` event). No auto-blame, no auto-claim — human decision, better information.
- Lookup logic lives in one place: `ComponentWarrantyService` (§7), also used by the missed-recovery detector.

## 6. Final rules: Service vs Component (the law)

| Work | Recorded as | Why |
|---|---|---|
| Oil change (+ oil filter) | **ServiceRecord only** (`oil_change`, materials_cost) | consumables — Phase 1 guard makes the component path impossible |
| Brake pad/disc replacement | **Component** (batch, per-axle set) + optional labor ServiceRecord | physical asset with lifetime |
| Battery replacement | **Component** (serialized) | identity + warranty |
| Inspection / diagnostics / cleaning / programming | **ServiceRecord only** | actions, not things |
| Repair labor on any fault | **ServiceRecord** (`repair_labor`, linked to the component when one is involved) + `kind=labor` line | labor is never an asset |
| Tyres | **Component** (batch, axle_corner, DOT in serial_no) | tracked asset |
| Any consumable (coolant, bulbs, wipers, AdBlue) | **ServiceRecord** | never a component — enforced at model level |

Precedence when one job does both (battery replaced AND terminals serviced): the part = component, the labor = service record, the billing lines = unchanged money model. Three ledgers, one job, no double-entry.

## 7. Phase 2 technical design

### ComponentService (single write choke point — no other code writes these tables)

| Method | Does | Guards |
|---|---|---|
| `installFromPurchase(PartPurchase, array $payload, User)` | Scenario 1 steps 1–5; called BY `installPurchase` | slot lock; predecessor+disposition; serial for serialized; consumable refusal; odometer continuity |
| `install(VehicleComponent $stock, Vehicle, array, User)` | fit an in_stock spare (± ticket) | same guards; warranty NOT reset |
| `remove(VehicleComponent, array $removal, User)` | standalone removal (strip before sale, damaged part) | atomic reason+disposition+odometer; photo policy (reason failed/accident or disposition warranty_return → ≥1 photo) |
| `transfer(VehicleComponent, array, User)` | Scenario 3 | slot-free or chained; both odometers |
| `intake(array identity/provenance, User)` | stock intake (Scenario 2, direct warehouse buys) | consumable refusal; duplicate serial 409 |
| `dispose(VehicleComponent $stock, array, User)` | shelf disposal | terminal dispositions only |
| `settleForVehicleSale(Vehicle, array $decisions, User)` | Scenario 4 | every active component decided |

All methods: write `component_events` + mirror via `VehicleLogService::record` + return the fresh component. **ComponentQueryService** (reads): current/history/biography/warehouse/component-context. **ComponentWarrantyService**: `activeComponentForSlot()`, `warrantyStateFor(fault|component)`, `missedRecoveryCandidates()` — used by context endpoint, recurring review enrichment, and the notification detector.

### installPurchase integration & failure handling (the flag contract)

```php
// inside PartWorkflowService::installPurchase, after the existing line-item write
if (config('features.asset_layer') !== 'off') {
    if (config('features.asset_layer') === 'shadow') {
        try { $components->installFromPurchase($purchase, $payload, $actor); }
        catch (\Throwable $e) { report($e); }         // NEVER blocks or rolls back billing
    } else { // enforced
        $components->installFromPurchase($purchase, $payload, $actor); // same DB txn — throws roll back everything
    }
}
```

- **off** — byte-identical behavior (Phase 1 state).
- **shadow** — 2 weeks of real workshop activity: asset writes attempt with best-available data (`predecessor` optional, serial optional), failures `report()` only. Weekly diff vs the workshop's actual jobs.
- **enforced** — one transaction: billing line + component writes commit or roll back together; `predecessor`/serial validation becomes blocking 422s; the disposition modal becomes mandatory UI.

### Transaction boundaries

- One DB transaction per ComponentService method, opened by the OUTERMOST caller (`installPurchase` already runs one — service methods join it via Laravel's nested-transaction handling). Lock order: predecessor slot row first (`lockForUpdate`), then writes — same discipline as `PartWorkflowService::purchase`.
- Timeline mirror (`vehicle_log_events`) writes INSIDE the transaction (audit consistency beats decoupling). Notifications fire AFTER commit only.
- `component_events` are append-only: no update/delete method exists on any service; corrections are compensating events.

### Event creation rules (exhaustive)

| Operation | Events written |
|---|---|
| install from purchase (no predecessor) | `installed` |
| install replacing OLD | `removed`(OLD) + `installed`(NEW); + `warranty_claimed`(OLD) when disposition=warranty_return |
| stock intake | `purchased` + `stored` |
| spare install | `installed` |
| standalone removal | `removed`; + `disposed`/`returned_supplier`/`sold` per terminal disposition; + `warranty_claimed` when applicable |
| transfer | `transferred` (single event, both vehicle FKs) |
| shelf disposal | `disposed` (or `returned_supplier`/`sold`) |
| sale settlement | per component: `removed`+`sold` (kept) or `removed`+`stored` (stripped) |

Every event → exactly one `vehicle_log_events` mirror per affected vehicle (transfer = two mirrors).

## 8. APIs & UI (defined before coding)

New routes under `components.view`/`components.manage` (+ the extended existing install endpoint). Representative examples:

**`GET /vehicles/{id}/components`** → `{ current: [ { id, catalog:{slug,name,category_key}, brand, serial_no, position, installed_at, installed_odometer, technician_name, installer:{id,name}, supplier:{id,name}, purchase_cost, warranty:{months, until, remaining_months, in_warranty}, source } ], history: [ { …, removed_at, removed_odometer, removal_reason, disposition, life_km, replaced_by_component_id, is_legacy } ] }` — money client-gated by SHOW_FINANCIALS.

**`GET /components/{id}`** → instance + `events[] { event, at, from_vehicle:{id,plate}, to_vehicle, odometer, ticket_id, actor_name, note }` + media + related service records (the biography drawer).

**`POST /components/{id}/transfer`** → §3 payload → `200 { component, event_id }`; `409 { occupying_component }` when the slot is taken; `422` on odometer regression.

**`POST /components/{id}/remove`** → `{ removal_reason, disposition, removed_odometer, removal_note?, photo_media_ids? }` → `200`; `422 missing_disposition` — the message literally asks "What happened to this part?".

**`POST /components/intake`** → `{ component_catalog_id | part_purchase_id, serial_no?, brand?, quantity?, supplier_vendor_id?, purchase_cost? }` → in_stock component (Scenario 2's "move to stock" passes `part_purchase_id`).

**`GET /components?status=in_stock&catalog=&q=`** → warehouse inventory. **`POST /components/{id}/install`** → fit a spare `{ vehicle_id, position?, odometer, maintenance_id? }`. **`POST /components/{id}/dispose`** → shelf disposal.

**`GET /maintenance-tickets/{id}/component-context`** → §5 card payload. **`GET /vehicles/{id}/component-settlement`** + **`POST …/component-settlement`** → §4. **`GET /vehicles/{id}/asset-export?format=json|print`**.

**Screens (Phase 2 ships the workshop-critical ones; the rest complete in the D5/UI step):**
1. **Vehicle Components tab** (Car-Status page): CURRENT cards (warranty chip green/amber/red) + HISTORY list (reason/disposition badges, `unknown_legacy` tag, replaced-by links) + biography drawer.
2. **Install component modal** (extends the existing install dialog in TicketParts.js): identity fields (serial enforced per tracking_mode), technician, warranty — with the predecessor panel embedded.
3. **Remove component modal** — the blocking disposition dialog (reason → disposition → odometer → conditional photo requirement).
4. **Transfer component modal** — target vehicle picker + slot preview (shows/handles the occupying component) + both odometers.
5. **Warehouse spare inventory** (`/components-inventory`): filters, biography drawer, install/dispose actions, intake button.
6. **Warranty history view** — Components-tab strip: soonest-expiring warranties, open `warranty_claimed` items, missed-recovery flags; plus the §5 context card on Decide/confirm.

### Phase 2 acceptance gate (before flag→enforced)

The 15 design-doc scenarios that touch writes become integration tests over ComponentService + endpoints; shadow-mode runs 2 weeks on real activity; the Crud regression suite stays green with the flag `off` AND `shadow`; a dedicated test proves `off` writes zero asset rows through a full ticket lifecycle.

---

*On approval: Phase 2a implementation = ComponentService + Query/Warranty services + endpoints + installPurchase hook (shadow), per deploy step D2 of the implementation plan.*
