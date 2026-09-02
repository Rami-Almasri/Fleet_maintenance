# FleetView Asset Layer & Maintenance System — Engineering Handoff

**Date:** 2026-07-23 · **Author context:** end of the design-and-build session that produced the Asset Layer (audit → ADR → DB design → implementation plan → Phase 1 → Phase 2a → shadow launch).
**Read this first, then the document chain in order:**
1. `Maintenance-Workflow-Audit.md` — the full workflow audit + gap analysis that motivated everything
2. `Asset-Layer-Architecture.md` — the ADR (decisions + rejected alternatives)
3. `Asset-Layer-Database-Design.md` — column-level schema, enums, ERD (approved)
4. `Asset-Layer-Implementation-Plan.md` — phases, deploy strategy, confirmed decisions
5. `Asset-Layer-Phase2-Workflow-Design.md` + `Asset-Layer-Phase2-Final-Review.md` — workflow wiring + locked edge cases
6. `Asset-Layer-Shadow-Launch-Plan.md` (+ its §7 recovery strategy) and `Asset-Layer-Shadow-Log.md` — the live validation window
7. `Asset-Layer-Test-Strategy.md` — how everything is verified

---

## 1. Architecture summary

### The four-entity separation (the load-bearing idea)

| Entity | Nature | Tables | May drive ticket `workflow_status`? |
|---|---|---|---|
| **Maintenance Ticket** | an EVENT/problem ("something happened to the car") | `maintenances` + `maintenance_tasks` (faults) | yes — the existing engine, untouched |
| **Component** | a PHYSICAL ASSET with identity and its own lifecycle | `component_catalog` (type) + `vehicle_components` (instance) + `component_events` (biography) | **never** |
| **Service Record** | a PERFORMED ACTION (labor: oil change, inspection, repair work) | `service_records` | **never** |
| **Inventory** | not a separate system — components with `status=in_stock` (warehouse/refurb, `vehicle_id NULL`) | same `vehicle_components` | never |

They interlock but never merge: a battery replacement is ONE job producing entries in THREE ledgers — a billing line (`maintenance_line_items`, money — unchanged pre-existing system), a component pair (old closed + new active), optionally a labor service record. No double-entry, no ledger reads another's meaning.

### Data ownership rules

- Ticket lifecycle → `MaintenanceWorkflowService` (only writer of `workflow_status`).
- Money → `maintenance_line_items` / `maintenance_invoices` (Diagnosis-First + variance gates; asset layer only *references* via `source_line_item_id`).
- Physical truth → **`ComponentService` is the ONLY writer** of `vehicle_components`/`component_events`; the state fields are not mass-assignable.
- Service anchors (oil/battery reminders) → `Vehicle::recordServiceDone` fed solely by `confirmRoutineServices` on re-inspection PASS ("Ticket = single source of truth"); `service_records` will be written at the same gate (Phase 2b — NOT yet wired).
- Vehicles/contracts → OfficeManager API (sync never blocked by asset questions).
- Trust → `write_mode` + `validation_status` markers (§7.1 of the shadow plan): shadow rows are provisional until promoted by `components:shadow-audit --promote`.

### Workflow boundary (one seam)

Exactly ONE workflow-coupled asset write exists: `PartWorkflowService::installPurchase` → flag-gated call to `ComponentService::installFromPurchase`. Flag contract (`config('features.asset_layer')`, env `ASSET_LAYER_MODE`): `off` = byte-identical legacy behavior · `shadow` = best-effort write, failures `report()`ed and swallowed, billing untouchable · `enforced` = same transaction, all-or-nothing, disposition/serial/position validation blocking. Everything else (transfer, intake, dispose, settlement) is ticket-independent by design.

### Current state (2026-07-23, end of session)

- **Implemented & verified:** Phase 1 (6 migrations, 4 models + support class, catalog seed 21 entries, 3 permissions) and Phase 2a (`ComponentService`, the install hook, extended install validation, trust markers, `components:shadow-audit`). 31 asset tests + full Crud regression green (191 tests; the only 2 failures pre-date this work — see Risks R-C1).
- **LIVE:** shadow mode on the **local live instance** (XAMPP `laravel` DB) since 2026-07-23 10:25; gate 2026-08-06; evidence in `Asset-Layer-Shadow-Log.md`.
- **Pending:** service_records writes (confirmRoutineServices hook), read APIs/UI, `components:integrity` scheduled command, notifications, backfill, intelligence — see §3 roadmap.
- **⚠️ Not committed:** ALL asset-layer code sits uncommitted in the working tree of branch `feat/mileage-investigation-center`, alongside unrelated uncommitted "Incorrect-merge" workflow changes. **First action for the next engineer: commit the asset layer on its own branch** (the file lists below are the exact scope; no file overlaps the Incorrect-merge set except `routes/api.php` — which the asset layer did NOT touch — and `PartPurchaseController.php`/`PartWorkflowService.php` which are asset-only edits).
- **⚠️ VFZDubai production** (`ali@81.85.92.150:8087`, Docker) has NONE of this — deploying there needs commit + image build + the §6 checklist of the shadow plan re-run on that server.

### The final business rules (locked across the doc chain)

1. The vehicle always knows its current installed components (`status=active` on the row).
2. Every removal carries reason + disposition, atomically — **no disposition, no removal**; old parts never disappear.
3. Components ≠ services, always; consumables never become components (model-level guard).
4. Purchase ≠ installation — no component is ever created as a purchase side effect; three doors only: install, explicit intake, backfill.
5. One active component per slot `(vehicle, catalog, position)`; two = 409, never a guess.
6. Retired is absorbing; re-activation only via a new install event; history and events are immutable (corrections = compensating events).
7. Warranty belongs to the physical part — derived from the ORIGINAL install, survives storage/transfer, never reset.
8. A sold vehicle has zero active components or a pending-settlement flag; OM sync is never blocked.
9. Asset writes never transition tickets.
10. Legacy/unknown facts are labeled (`unknown_legacy`, `provisional`), never invented.

---

## 2. Implementation inventory (everything created/modified this session)

### Migrations (all additive; rollback of all 6 verified on a prod copy)

| File | Purpose | Risk / extension notes |
|---|---|---|
| `2026_07_24_100000_create_component_catalog_table` | type dictionary | restrictOnDelete incoming — retire via `is_active`, never delete |
| `2026_07_24_100100_create_vehicle_components_table` | the instance ledger; self-FK added post-create for clean `down()` | hot composite indexes documented in DB-design §2.2; add columns only, never repurpose |
| `2026_07_24_100200_create_component_events_table` | append-only biography | no update path may ever be added |
| `2026_07_24_100300_create_service_records_table` | performed actions | **table exists but NOTHING writes it yet** (Phase 2b) |
| `2026_07_24_100400_add_component_refs_to_maintenance_media` | photo linkage (2 nullable FKs) | only existing-table touch; upload paths don't set these yet (Phase 3) |
| `2026_07_24_100500_add_shadow_validation_to_vehicle_components` | `write_mode`, `validation_status`, `validated_at/by` trust markers | added pre-production so no row ever lacks the marker |

### Models

| File | Purpose / key content |
|---|---|
| `app/Models/ComponentCatalog.php` | tracking modes, position schemes, `positionsFor()`, `active()` scope; `$table='component_catalog'` (non-plural!) |
| `app/Models/VehicleComponent.php` | ALL enums (status/location/reasons/dispositions/sources/validation); `booted()` guards: consumable-refusal on create, (status,location) matrix, active⇔vehicle, in_stock⇏vehicle, `warranty_until` derivation; **status/location/removal-leg NOT fillable**; scopes `activeOn/inStock/retired/forSlot/underWarranty/warrantyMissedRecovery/trusted`; accessors `life_km/life_days/warranty_remaining_months/is_legacy`. Note: `retired` rows MAY keep `vehicle_id` (last car — makes per-vehicle history a trivial query) |
| `app/Models/ComponentEvent.php` | 9 event constants; append-only by convention (no service updates it) |
| `app/Models/ServiceRecord.php` | results/sources/`lastPerType()`; validates types against `App\Support\ServiceTypes` (the ONE type list, aligned with ServiceReminder) |
| `app/Support/ServiceTypes.php` | canonical service-type vocabulary |
| Modified: `Vehicle` (+`components/activeComponents/serviceRecords`), `Maintenance` (+`componentEvents/serviceRecordEntries`), `PartPurchase` (+`component` hasOne), `MaintenanceMedia` (+2 relations & fillable), `VehicleLogEvent` (+4 `EVENT_COMPONENT_*` constants) | all read-only conveniences; zero behavior |

### Services / commands / controllers

| File | Purpose | Dependencies | Extension points |
|---|---|---|---|
| `app/Services/ComponentService.php` | THE write choke point: `installFromPurchase / install / intake / remove / transfer / dispose / settleForVehicleSale`; private `closeOut()` = the only removal-leg writer; `lockSlot()` (409 on 2-active); guards (serial+duplicate, consumable, position-per-mode, odometer explicit-vs-fallback rule); `recordEvent()` mirrors to `vehicle_log_events` (source_tag `components`) | `VehicleLogService` | Phase 3 endpoints call these methods; `ComponentQueryService`/`ComponentWarrantyService` (designed, not built) will sit beside it — reads never go here |
| `app/Console/Commands/ComponentsShadowAudit.php` | shadow scorecard: M-checks scoped to shadow rows; `--promote` (clean→validated), `--quarantine=ids --reason=` | models only; read-only except the two marker transitions | grows into/feeds `components:integrity` (Phase 2b) |
| Modified `app/Services/PartWorkflowService.php` | +`ComponentService` in constructor; flag-gated hook inside `installPurchase`'s transaction (after request-status update, before the log line) | — | the ONLY workflow seam; do not add more call sites without a design doc |
| Modified `app/Http/Controllers/PartPurchaseController.php::install` | accepts optional `component` + `predecessor` validation blocks | — | ⚠️ route perm is `parts.purchase` — see Risk R-M3 |

### Config / seeders / permissions / tests

- `config/component_catalog.php` — 21 seed entries (9 serialized / 5 batch / 7 consumable) with the conventions in the notes (pads = set per axle qty 1; tyre = row per corner, DOT in serial_no). `config/features.php` — `asset_layer` flag (env `ASSET_LAYER_MODE`, default off).
- `database/seeders/ComponentCatalogSeeder.php` — idempotent upsert by slug; **deliberately does not sync `is_active`** (DB-side retirement survives re-seed); registered in `DatabaseSeeder`.
- `RolesAndPermissionsSeeder` — `components.view` (all roles), `components.manage` (manager, maintenance, supervisor + admin tier ONLY — technicians by explicit grant), `components.backfill` (admin tier).
- Tests: `tests/Crud/AssetLayerPhase1Test.php` (12) + `tests/Crud/AssetLayerPhase2Test.php` (19) — **must run via `vendor/bin/phpunit -c phpunit.crud.xml`** (MySQL `laravel_test`; the sqlite Feature suite cannot run the full migration chain — a pre-existing `MODIFY COLUMN … ENUM` migration is MySQL-only).

---

## 3. Roadmap from here

### Phase 2b — shadow validation & hardening (NOW → gate 2026-08-06)
**Goal:** prove the ledger matches physical truth; ship the monitoring that outlives shadow.
**Work:** daily M1–M4 + shadow-log discipline (already specified); build `components:integrity` scheduled command (nightly: multi-active slots, invalid states, active-on-sold → `component_settlement_pending` notification, purchased-never-installed) feeding the existing `/anomalies` page (frontend renders groups generically — zero UI work); wire `confirmRoutineServices` → `service_records` (flag-gated, same best-effort discipline); commit the code; decide the Part I workflow-P0 track status (separate PR track, still open: `awaiting_parts_active`, mixed cost-model guard, release safety rules).
**Accept:** the 7 gate criteria of the launch plan §5; integrity command green nightly for a week; service_records rows appearing on re-inspection PASS in shadow.
**Tests:** integrity-command unit tests per check; a confirmRoutineServices test asserting a `service_records` row + rolled ServiceReminder + zero rows with flag off.

### Phase 3 — read APIs + UI (after gate passes, flag → enforced)
**Goal:** the physical truth becomes visible and operable.
**Work:** `ComponentQueryService` + `ComponentWarrantyService`; endpoints from Phase2-Workflow-Design §8 (`GET /vehicles/{id}/components`, `/component-history`, `GET/POST /components/*`, `/maintenance-tickets/{id}/component-context`, settlement, asset-export); screens: Components tab (Car-Status page), install/remove/transfer modals (TicketParts.js area), warehouse `/components-inventory`, warranty strip + context card; re-point `serviceHistory`/`tireHistory` (response shapes preserved); recurring-review context enrichment (audit Part I D4 lands here).
**Accept:** every read excludes `quarantined`; SHOW_FINANCIALS gates money; response-shape snapshot tests on the re-pointed endpoints; Playwright pass; enforced-mode UX validated with the workshop (the two new blocking prompts: position + disposition).
**Files:** new controllers/resources + routes (gate `components.view/manage` — resolve R-M3 here), frontend pages/components.

### Phase 4 — backfill (after Phase 3 stabilizes)
**Goal:** history isn't empty. `components:backfill --dry-run` per DB-design §8: part_purchases-with-install-leg → line-items sweep → invoice_items → slot-sequencing (latest per slot = active; earlier = retired `unknown_legacy`); idempotency via the three source FKs; `write_mode='backfill'`; backup-gated; ship `components:backfill-purge --source=legacy_backfill` WITH it; rehearse on a restored backup FIRST.
**Accept:** run-twice = zero delta; reconciliation report reviewed by owner; integrity scan clean after.

### Phase 5 — intelligence (§6 below)
Foresight `component_due` signal, supplier/workshop scorecards, TCO, warranty-recovery detector, `ComponentExplainer` in the Explainability DAG.

---

## 4. Hidden risks (focused code review of the new code)

**CRITICAL — none known.** The two failing Crud tests are NOT asset-layer (see R-C1).

**HIGH**
- **R-H1 · Settlement/integrity gap until Phase 2b:** an OM-sync sale with active components is currently detected by NOTHING automated (the integrity command doesn't exist yet; M7 is a manual query). During shadow this is acceptable; it must exist before `enforced`. Mitigation: M7 in the daily routine.
- **R-H2 · Shadow catch swallows programming errors too:** `catch (\Throwable)` hides a TypeError in ComponentService just as quietly as a 422. Deliberate (billing safety) — but it means log review is not optional; the M1 gap query is the true detector (a gap with no matching log line = the hook itself broke — treat as P1).
- **R-H3 · Serial duplicate check is not race-safe:** `guardSerial` uses `exists()` without a lock or unique index; two concurrent installs of the same serial can both pass. Low likelihood (human-paced installs), real consequence (duplicate identity). Fix in Phase 2b: unique composite index `(component_catalog_id, serial_no)` filtered to non-retired via application-level lock, or accept + integrity check. Decide before enforced.

**MEDIUM**
- **R-M1 · Nested-transaction deadlock semantics in shadow:** the inner `DB::transaction` is a savepoint; a MySQL deadlock/lock-timeout inside it can invalidate the OUTER transaction while the shadow catch lets execution continue → the later commit fails, and billing rolls back after all. Extremely unlikely at current concurrency (single-threaded artisan serve), but re-evaluate before high-concurrency deploys. Detection: such an event surfaces as a 500 on install + log pair.
- **R-M2 · Quarantining an ACTIVE row leaves it occupying its slot:** future installs then demand a predecessor decision on a quarantined part. Procedure (shadow plan §7.2): `remove()` first (honest disposition), quarantine after. Consider a command guard in Phase 2b (refuse `--quarantine` on active rows).
- **R-M3 · Permission asymmetry on the install path:** `POST /part-purchases/{id}/install` is gated `parts.purchase`, but the embedded `predecessor` block performs a removal+disposition — which the final review assigns to `components.manage`. In shadow this is fine (data collection). Before enforced: either add `components.manage` to the route, or formally accept parts.purchase-on-ticket-path as equivalent (final-review §4 left this open).
- **R-M4 · Catalog auto-resolution requires explicit picks for multi-catalog categories** (electrical → 5 catalogs): every electrical install without `component.component_catalog_id` will 422→swallow in shadow (an M1 gap). Expect this to dominate early gap counts; the Phase 3 modal removes it. Track in the shadow log rather than "fixing" blindly.
- **R-M5 · `validated_by` NULL on CLI promotion** — the human decision lives only in the shadow log. Acceptable; Phase 3 UI promotion should stamp the user.
- **R-M6 · Warranty derivation truncates to date + `startOfDay`** — a part installed at 23:00 gets warranty from that DATE; consistent with `MaintenanceLineItem`. Not a bug; documented so nobody "fixes" one and not the other.

**LOW / pre-existing (not asset-layer, but you'll hit them)**
- **R-C1 · 2 failing Crud tests** (`PauseResumeMaintenanceTest`, `OdometerDiscrepancyNotifyTest`) belong to the branch's UNCOMMITTED Incorrect-merge changes (proven by stash test 2026-07-23). Resolve before merging that work; don't attribute them to the asset layer.
- **R-L1 · `installPurchase` double-submit race** (pre-existing): `isInstalled()` check is unlocked; two simultaneous installs of one purchase could double the line item. Predates the asset layer; worth a `lockForUpdate` when someone touches that method next.
- **R-L2 · `odoo_backup.dump` + `shot_bootstrap.php`** sit untracked in the repo root — not this project's artifacts; ask the owner before any cleanup.

---

## 5. Testing strategy

See `docs/Asset-Layer-Test-Strategy.md` (written alongside this handoff).

---

## 6. AI / Intelligence preparation (what this layer unlocks)

The asset ledger turns intelligence from string-reconstruction into plain queries. All figures must register Explainers in the existing DAG engine (`app/Services/Explainability`) — explainers trace, never recalculate.

| Capability | Data needed | Where it comes from |
|---|---|---|
| **Component failure prediction** | per-catalog actual lifetimes (`life_km`/`life_days`), removal reasons, vehicle usage rate | `vehicle_components` removal legs vs `expected_life_km/months` on the catalog (auto-tune the catalog from fleet actuals); daily km run-rate from contracts/odometer |
| **Remaining useful life** | active components: installed_odometer + tuned expected life − current odometer; battery: `expected_life_months` age curve | same + `vehicles.odometer`; surfaces as a Foresight `component_due` signal next to oil/battery/chronic |
| **Supplier quality scoring** | failure rate + mean lifetime + warranty-claim rate per `supplier_vendor_id` | removal reasons + `warranty_claimed` events grouped by supplier; feeds `part_investigations` with real denominators |
| **Workshop quality scoring** | re-failure rate per `installer_vendor_id` (successor within X km/days, or removal_reason=failed) | components + `replaced_by` chains; joins the existing `repair_inspections.still_exists` garage-quality numerator |
| **True vehicle TCO** | Σ component `purchase_cost` + Σ service `labor/materials_cost` + existing VehicleExpenseProvider + depreciation (PR1) | components + service_records per vehicle; label completeness per the financial source-of-truth contract (non-AED excluded with a note) |
| **Warranty recovery** | failed-in-warranty parts and their dispositions | `warrantyMissedRecovery()` scope (already on the model) → finance-leak alert family; open claims = `warranty_claimed` events without resolution |
| **Fleet health score** | per-vehicle composite: overdue components (RUL), open faults, chronic episodes, warranty exposure | all of the above + existing Foresight; a read model, no new tables |

Prerequisites before any of it: gate passed (data trusted), Phase 4 backfill done (history depth), catalog `expected_life_*` reviewed against first real actuals.

## 7. DO NOT BREAK THESE RULES (for every future contributor)

1. **Never write `vehicle_components`/`component_events` outside `ComponentService`.** No raw UPDATE/DELETE, no mass-assignment workarounds, no "quick fix" in a controller. The state fields are non-fillable on purpose.
2. **Never delete component history.** Rows are closed/retired/quarantined, never removed. Corrections are compensating events with notes.
3. **Never confuse service with component.** Physical thing → component; performed action → service record; consumable → service record ONLY (the model will throw if you try — do not "fix" that guard).
4. **Never create a component without its lifecycle** — every row enters through install / intake / backfill, with events. No component may ever exist as a side effect of a purchase.
5. **Never remove without disposition.** If a UI or API path lets a part vanish without "where did it go?", it is a bug, not a shortcut.
6. **Never let asset code touch `workflow_status`** — and never add a second workflow-coupled write seam without a design document.
7. **Never reset warranty on re-install/transfer** — the clock belongs to the physical part.
8. **Never guess.** Two active parts in a slot = 409 for a human; unknown legacy facts = `unknown_legacy`; a conflicting guessed odometer = NULL, not an error, but an EXPLICIT wrong reading = 422. (This explicit-vs-fallback distinction is implemented in `closeOut` — preserve it.)
9. **Never block OfficeManager sync on asset questions** — detection is the integrity scan's job.
10. **Never consume `quarantined` rows in any read surface**, and treat `provisional` as unconfirmed until the shadow gate has passed.
11. **Run the asset tests with the CRUD config** (`phpunit.crud.xml`, MySQL) — the sqlite suite cannot migrate this schema; a green sqlite run proves nothing here.
12. **Keep the flag semantics sacred:** `off` must remain byte-identical forever — there is a regression test asserting zero asset writes through a full install with the flag off; keep it passing.
