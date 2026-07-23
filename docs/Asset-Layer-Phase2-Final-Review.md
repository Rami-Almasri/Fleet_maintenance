# Asset Layer — Phase 2 Final Review (edge cases closed)

**Date:** 2026-07-23 · **Status:** decisions locked — Phase 2a implementation starts after this document · **Parents:** `Asset-Layer-Phase2-Workflow-Design.md` (approved), Phase 1 (shipped, verified, flag off).

---

## 1. Component slot matching (the replacement decision)

**A slot is `(vehicle_id, component_catalog_id, position)`.** The new part replaces whatever single ACTIVE component occupies that exact slot — nothing fuzzier, ever. `category_key` is never used for slot matching (it's for fault↔component context only); two different catalog entries never replace each other.

| Catalog | position_scheme | Slot behavior |
|---|---|---|
| battery, engine, gearbox, AC compressor, alternator, starter, radiator, ECU, GPS (serialized) | null | one active per (vehicle, catalog); position always NULL |
| tyre, shock absorber | `axle_corner` | one active per corner (FL/FR/RL/RR) |
| brake pads, brake discs | `axle` | one active per axle (front/rear) — pads are a per-axle SET |
| air filter (batch, positionless) | null | one active per (vehicle, catalog) |

**Install without position when the scheme requires one** (e.g. a tyre with no corner):
- `shadow` mode → accepted with `position=NULL`, logged loudly (`asset_layer.position_missing`) — shadow's job is to measure how often this happens before we make it blocking.
- `enforced` mode → **422** "position required (front_left / front_right / rear_left / rear_right)". A NULL-position row for a positioned catalog is treated by the integrity scan as a defect.

**Two active components found in the same slot** (possible only from pre-fix data or a future bug — the install path locks the slot):
- The install/transfer **refuses with 409 `slot_conflict`**, listing the conflicting component ids. We never guess which one is real and never silently close one. Resolution is manual: `remove()` the wrong row (its disposition honestly recorded, e.g. `scrapped`/`stored`), then retry. `components:integrity` reports every multi-active slot nightly.

## 2. Warehouse / spare inventory rules

**The four states, precisely:**

| State | Data shape | Meaning |
|---|---|---|
| **Purchased part** | `part_purchases` row only — NO component | money spent; physical thing not yet in the asset ledger |
| **Stored spare** | component `in_stock` / `warehouse` (or `refurb`), `vehicle_id NULL` | in our custody, installable |
| **Installed component** | component `active` / `on_vehicle` | on exactly one car |
| **Disposed component** | component `retired` / `supplier|scrapped|sold` | story over; row + biography immutable forever |

**When does a purchased part enter inventory?** Never automatically. Exactly three doors into the component ledger:
1. **Install** against a vehicle (workflow or spare-fit) → born `active`.
2. **Explicit intake** (`intake()`): the Scenario-2 "diagnosis changed — move it to stock" action referencing the purchase, or a direct warehouse buy → born `in_stock` with `purchased` + `stored` events.
3. **Backfill** (Phase 3, not now).

Safety net: `components:integrity` flags purchases `installed_at IS NULL`, older than 7 days, whose fault/ticket is terminal — "bought but neither installed nor in stock" — so an unused purchase can't evaporate by inaction.

## 3. Batch components — final conventions

- **Tyres: one component row per physical tyre** (`quantity=1`, one corner each, DOT in `serial_no` when known). Replacing 2 of 4 → exactly those two corner-slots close (each with its own reason/disposition) and two new rows go active; the other two corners keep their original install dates and lifetimes. Per-corner history is the entire point — a 4-row install is 4 slot operations in one modal.
- **Brake pads / discs: one row per AXLE SET** (`quantity=1`, position `front`|`rear`). We never track individual pads. Replacing front pads only closes the `front` slot.
- **Filters (tracked ones): one row, positionless, `quantity=1`.** Oil filter stays a consumable (service record with the oil change) — only the air filter is currently catalog-tracked.
- `quantity > 1` is reserved for genuinely uncountable batch buys recorded as one unit (rare; discouraged — the catalog `notes` must say so). Identity of an unserialized batch part = its slot + install date; that is honest and sufficient.

## 4. Technician workflow (who does what)

UI flow at the garage step (ticket `under_repair`): parts panel shows the approved/purchased part with its **component-context card** (current slot occupant, warranty state) → **Install** opens the modal (identity + technician name + warranty; serial field enforced per tracking_mode) → if the slot is occupied the modal embeds the **disposition step** (reason → where did the old one go → odometer → photo when policy requires) → submit = one atomic action.

| Action | Permission | Who in practice |
|---|---|---|
| See components / context / history | `components.view` | everyone incl. technicians (inspector/logistics roles) |
| Request part, record purchase | existing `parts.request` / `parts.purchase` | unchanged |
| **Install + old-part disposition** (one action) | `components.manage` | supervisor / workshop manager / manager — the same people who press Install today on the ticket. A trusted senior technician gets an explicit per-user grant (`php artisan user:role`), never by role default |
| Standalone remove, transfer, spare install, intake | `components.manage` | supervisor+ |
| Shelf disposal, sale settlement | `components.manage` | supervisor+ |
| Repair-gate / warranty-claim decision on a comeback | existing `maintenance.recurring.manage` | management (unchanged) |
| Backfill | `components.backfill` | admin tier |

No new approval step is added to the install itself — the part was already approved at the part-request stage; blocking installs twice would stall the workshop. The disposition prompt is a data gate, not an approval gate.

## 5. Vehicle sale + OM sync — final behavior

- **Manual sale in FleetView:** the settlement list blocks the action until every active component is decided (keep-with-vehicle / strip-to-stock). One transaction, then asset export offered. (Phase 2 ships the service + endpoint; the sale-flow UI hook lands with the UI step.)
- **OM sync flips a car to sold/disposed with active components:** the sync is NEVER blocked or slowed (sync must not fail on asset questions). Instead the car enters a **pending-settlement** condition: `components:integrity` (nightly, and cheap enough to run in `om:sync`'s post-phase later) raises one `component_settlement_pending` notification to `maintenance.manage` listing the car + its active components; the settlement endpoint works identically after the fact. The invariant is therefore: *a sold vehicle has no active components OR an open settlement flag* — silence is impossible, data is never invented.
- Components on a sold car that nobody settles stay visible in the report every night until settled. Nothing auto-defaults to `sold_with_vehicle`; that is a human statement about physical reality.

## 6. Data integrity invariants (final, enforced list)

1. At most ONE `active` component per slot `(vehicle, catalog, position)` — install/transfer lock the slot; violations are 409s; pre-existing violations surface in the integrity scan. *(A vehicle cannot have two active batteries.)*
2. `active` ⇔ `location=on_vehicle` ∧ `vehicle_id` set · `in_stock` ⇔ warehouse/refurb ∧ `vehicle_id NULL` · `retired` ⇔ terminal location (model guard, Phase 1 — shipped).
3. A removal is atomic: `removed_at` + `removal_reason` + `disposition` (+odometer) together or not at all. **No disposition, no removal.**
4. A sold/disposed vehicle has zero `active` components — or an open `component_settlement_pending` flag (§5).
5. A `retired` component never becomes anything else (absorbing). A removed-to-stock component becomes `active` again ONLY through a new install (which writes a new `installed` event) — there is no "un-remove".
6. `component_events` is immutable: no update/delete code path exists; corrections are compensating events. Component history (removal legs) is never edited after write — a wrong disposition is corrected by a compensating event pair recorded with a note, keeping the audit honest.
7. Consumable catalogs never instantiate components (model-level guard — shipped).
8. Warranty is a property of the physical part: `warranty_until` derives from the ORIGINAL install and survives storage and transfer; it is never reset by re-install.
9. Asset writes never transition `workflow_status` (code-review rule + no such call site).
10. Purchase ≠ installation: no component row is ever created as a side effect of recording a purchase.

## 7. Implementation readiness checklist

- [x] Database ready (Phase 1 shipped: 5 migrations verified fresh/prod-copy/rollback)
- [x] Models + guards ready (invariants 2, 7 already enforced and tested)
- [x] Workflow design approved (`Asset-Layer-Phase2-Workflow-Design.md`)
- [x] Edge cases closed (this document, §1–6)
- [x] Tests defined: Phase 2a suite = slot matching (incl. 409 conflict + position rules per mode), the four inventory states + intake doors, batch/tyre per-corner behavior, disposition atomicity, transfer with warranty preservation, sale settlement, shadow-vs-enforced failure contracts, off-mode zero-write regression — implemented as `tests/Crud/AssetLayerPhase2Test.php` alongside the code
- [ ] Phase 2a code: `ComponentService` + `installPurchase` hook (shadow) — **starts now**
- [ ] 2-week shadow validation on real workshop activity → then `enforced` (D4)

Out of scope for 2a (unchanged): UI polish, backfill, read endpoints beyond what the lifecycle needs.
