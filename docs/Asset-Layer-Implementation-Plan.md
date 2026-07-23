# Asset Layer — Implementation Plan (Roadmap)

**Date:** 2026-07-23 · **Status:** FOR FINAL REVIEW — still no code · **Parents:** `Asset-Layer-Database-Design.md` (approved schema), `Asset-Layer-Architecture.md` (ADR), `Maintenance-Workflow-Audit.md`.

**Prime directive:** the current Maintenance workflow must keep working unchanged at every deploy point. Every step below is independently deployable and independently revertible. The asset layer is **additive-only** until Phase 2, **write-only** until Phase 4 reads go live, and dark-launchable behind one flag.

---

## 1. Exact migration order

All Phase 1, one PR, sequential timestamps (no FK can precede its target):

| # | Migration file | Creates | Depends on |
|---|---|---|---|
| 1 | `2026_07_24_100000_create_component_catalog_table.php` | `component_catalog` | — |
| 2 | `2026_07_24_100100_create_vehicle_components_table.php` | `vehicle_components` (FKs → component_catalog restrict, vehicles/users/vendors nullOnDelete, part_purchases/maintenance_line_items/maintenances nullOnDelete, self-FK `replaced_by_component_id` added in same migration AFTER create via `Schema::table` — self-FK on create is fine in MySQL but the two-step keeps `down()` clean) | 1 |
| 3 | `2026_07_24_100200_create_component_events_table.php` | `component_events` (FK → vehicle_components cascade, others nullOnDelete) | 2 |
| 4 | `2026_07_24_100300_create_service_records_table.php` | `service_records` (FK → vehicles cascade, others nullOnDelete) | 2 (via `related_component_id`) |
| 5 | `2026_07_24_100400_add_component_refs_to_maintenance_media.php` | 2 nullable indexed FKs on `maintenance_media` (nullOnDelete) | 2, 3 |

Rules: composite indexes exactly as specified in the design doc §2; every `down()` drops in reverse order (5→1), migration 2's `down()` drops the self-FK before the table. **No data migrations in Phase 1** — backfill is a command (Phase 3), never a migration (house rule: migrations are schema-only; data moves are backup-gated commands).

Config (same PR, no migration): `config/component_catalog.php` seed definitions; `config/features.php`-style flag `features.asset_layer` (default **false** in prod).

---

## 2. Laravel model design

Five new models in `app/Models`, following house conventions (string-constant enums, `$fillable`, `booted()` hooks, denormalized `*_name` stamps):

**`ComponentCatalog`**
- Constants: `TRACKING_SERIALIZED/BATCH/CONSUMABLE`, `TRACKING_MODES`, `POSITION_SCHEMES = ['axle_corner','axle']`, `POSITIONS_BY_SCHEME` map.
- Casts: booleans/ints. Scope: `active()`.
- Helper: `positionsFor(): array`, `isConsumable(): bool`.

**`VehicleComponent`**
- Constants: `STATUS_IN_STOCK/ACTIVE/RETIRED` + `STATUSES`; `LOC_ON_VEHICLE/WAREHOUSE/REFURB/SUPPLIER/SCRAPPED/SOLD` + `LOCATIONS`; `REMOVAL_REASONS` (8); `DISPOSITIONS` (8 incl. `unknown_legacy`); `VALID_STATUS_LOCATIONS` pair map (§3.2 of design doc).
- Casts: dates, decimals, ints.
- `booted()`: (a) derive `warranty_until = installed_at + warranty_months` on saving (mirror of `MaintenanceLineItem:77-84`); (b) **guard**: throw `DomainException` if (status, location) pair invalid — model-level last line of defense, service is the first.
- Scopes: `activeOn($vehicleId)`, `inStock()`, `retired()`, `forSlot($catalogId, $vehicleId, $position)` (the predecessor query), `underWarranty()` (`warranty_until >= today`), `warrantyMissedRecovery()` (failed + scrapped + warranty valid — feeds the detector).
- Accessors: `life_km` (removed−installed odometer), `life_days`, `warranty_remaining_months`, `is_legacy`.
- **NOT fillable:** `status`, `location`, removal-leg fields — settable only through `ComponentService` methods (prevents the `$fillable` silent-drop class of bug flagged in the audit; explicit assignment inside the service).

**`ComponentEvent`**
- Constants: `EVENT_PURCHASED/STORED/INSTALLED/REMOVED/TRANSFERRED/DISPOSED/RETURNED_SUPPLIER/WARRANTY_CLAIMED/SOLD` + `EVENTS`.
- Casts: `meta` array, `at` datetime. **No `updated_at` semantics honored** — append-only; no update/delete paths in any service.

**`ServiceRecord`**
- Constants: `RESULT_COMPLETED/PARTIAL/FAILED`; `SOURCE_WORKFLOW_CLOSE/INVOICE_IMPORT/MANUAL/LEGACY_BACKFILL`; `SERVICE_TYPES` (aligned with `ServiceReminder` types + findings categories — single source: a `ServiceTypes` support class reused by both).
- Scopes: `forVehicle()`, `ofType()`, `lastPerType($vehicleId)` (the `last_by_type` map).

**`VehicleLogEvent`** (existing, extended): add constants `EVENT_COMPONENT_INSTALLED/REMOVED/TRANSFERRED/DISPOSED` — constants only, no schema change.

## 3. Relationships

| Model | Relations |
|---|---|
| `ComponentCatalog` | `hasMany(VehicleComponent)` |
| `VehicleComponent` | `belongsTo(ComponentCatalog)`, `belongsTo(Vehicle)`, `belongsTo(Vendor as supplier)`, `belongsTo(Vendor as installer)`, `belongsTo(User as installedBy/removedBy)`, `belongsTo(PartPurchase as sourcePurchase)`, `belongsTo(MaintenanceLineItem as sourceLineItem)`, `belongsTo(Maintenance as removalTicket)`, `belongsTo(self as replacedBy)` / `hasOne(self as replaces)` (inverse), `hasMany(ComponentEvent)`, `hasMany(ServiceRecord, related_component_id)`, `hasMany(MaintenanceMedia)` |
| `ComponentEvent` | `belongsTo(VehicleComponent)`, `belongsTo(Vehicle as fromVehicle/toVehicle)`, `belongsTo(Maintenance)`, `belongsTo(MaintenanceTask)`, `hasMany(MaintenanceMedia)` |
| `ServiceRecord` | `belongsTo(Vehicle)`, `belongsTo(Maintenance)`, `belongsTo(MaintenanceTask)`, `belongsTo(Vendor as workshop)`, `belongsTo(VehicleComponent as relatedComponent)` |
| `Vehicle` (existing, add) | `hasMany(VehicleComponent)` + convenience `activeComponents()`, `hasMany(ServiceRecord)` |
| `Maintenance` (existing, add) | `hasMany(ComponentEvent)`, `hasMany(ServiceRecord)` — read-only convenience, no behavior |
| `PartPurchase` (existing, add) | `hasOne(VehicleComponent, source_part_purchase_id)` |
| `MaintenanceMedia` (existing, add) | `belongsTo(VehicleComponent)`, `belongsTo(ComponentEvent)` |

## 4. Policies and permissions

House pattern = Spatie permissions on route middleware (`resource.action`), not Laravel Policy classes — stay consistent.

New permissions (seeder update, `RolesAndPermissionsSeeder` or the existing permission sync path):

| Permission | Grants | Default roles |
|---|---|---|
| `components.view` | all reads: vehicle components/history, biography, warehouse inventory, asset export, catalog list | everyone with `maintenance.view` (viewer+) |
| `components.manage` | install / remove / transfer / dispose / stock intake / catalog curation | supervisor-tier (same holders as `maintenance.delegate` + `maintenance.manage`) |
| `components.backfill` | run `components:backfill` from any UI trigger (CLI is ops-gated anyway) | super-admin |

Route-level mapping (in `routes/api.php`, same middleware style as `/part-requests` block):
- All `GET` component/service endpoints → `components.view`
- All mutating component endpoints → `components.manage`
- `POST /maintenance-tickets/{id}/install-component` → `components.manage` **OR** existing `parts.investigate|maintenance.manage` (workshop actors already trusted with installs must not need a new grant — zero-friction migration; decide final at review)
- Service-record manual create → `maintenance.manage`

Guard notes: super-admin bypass already global (Spatie `Gate::before`); no per-model ownership policies needed (fleet-wide data, role-scoped like everything else).

## 5. New services / classes

| Class | Responsibility |
|---|---|
| `app/Services/ComponentService.php` | **The single write choke point.** `install()`, `remove()`, `transfer()`, `dispose()`, `intakeToStock()`, `settleForVehicleSale()`. Owns: validation matrix (§4 of design doc), predecessor `lockForUpdate` + atomic removal leg, (status,location) invariants, event writes, `VehicleLogService` mirroring, odometer-continuity checks. Every other path (workflow, API, backfill) calls this — no direct model writes elsewhere |
| `app/Services/ComponentQueryService.php` | reads: current components, history (rows + events touching a vehicle), biography, warehouse inventory, warranty exposure, `component-context` for tickets (active components matching fault categories + warranty + chain). Keeps controllers thin (house style) |
| `app/Services/ServiceRecordWriter.php` | small: create from workflow close / manual / import; enforces `ServiceTypes` alignment; no workflow coupling |
| `app/Support/ServiceTypes.php` | canonical service-type list shared by `ServiceRecord`, `ServiceReminder`, findings categories |
| `app/Services/ComponentBackfillService.php` + `app/Console/Commands/ComponentsBackfill.php` (`components:backfill --dry-run --vehicle= --from=`) | Phase 3: source sweeps (part_purchases → line items → invoice_items), slot-sequencing, idempotency via source FKs, reconciliation report. Refuses to run without a fresh `db:backup` unless `--force` |
| `app/Console/Commands/ComponentsIntegrityScan.php` (`components:integrity`) | nightly: active components on sold/disposed vehicles; >1 active per slot; retired rows with vehicle_id; missed-warranty-recovery candidates. Emits notifications + feeds the existing `/anomalies` page (new check group — frontend renders groups generically, zero UI work) |
| `app/Http/Controllers/ComponentController.php`, `ComponentCatalogController.php`, `VehicleAssetController.php` (vehicle-scoped reads + asset-export) | thin controllers over the services; `ResponseHelper` error convention |
| `app/Http/Resources/VehicleComponentResource.php`, `ComponentEventResource.php`, `ServiceRecordResource.php` | response shaping; money fields always present (client gates via SHOW_FINANCIALS, house pattern) |
| `app/Services/Explainability/Explainers/ComponentExplainer.php` | Phase 4/P3: registers component figures (life_km, warranty state, TCO contribution) in the DAG engine; never recalculates |
| Seeder: `ComponentCatalogSeeder` reading `config/component_catalog.php` | idempotent by `slug` upsert |

## 6. Existing services modified (the complete blast-radius list)

| Service | Change | Risk containment |
|---|---|---|
| `PartWorkflowService::installPurchase` | after existing line-item write, call `ComponentService::install()` (catalog resolve from `category_key`; predecessor payload passed through from the request). **Wrapped in the feature flag + try/catch during dark-launch**: component failure logs loudly but does not roll back the billing write while the flag is in `shadow` mode; in `enforced` mode it is one transaction | the ONLY workflow-coupled write; flag-gated |
| `MaintenanceWorkflowService::confirmRoutineServices` | additionally write `ServiceRecord` rows (source=workflow_close) alongside existing `recordServiceDone` anchors; same best-effort try/catch discipline the method already uses (never sinks a close) | additive inside an already best-effort block |
| `VehicleLogService` | 4 new event constants + one `recordComponentEvent()` convenience | constants + one method |
| `RecurringFaultService::openReview` | enrich review `context` JSON with `component-context` (component id, in_warranty, supplier, installer) when a matching active component exists | read-only enrichment of an existing JSON field — no schema change |
| `PartRequestController` / `PartPurchaseController` | install endpoint accepts the optional predecessor-removal payload + component identity fields (serial, brand, technician); validation only — logic lives in `ComponentService` | request-shape addition, backward compatible (all new fields optional until flag `enforced`) |
| `NotificationScanner` | 2 new detectors: `component_warranty_expiring` (active, expiring ≤30d, only if a related open fault exists — avoid noise), `component_missed_warranty_recovery` | additive detectors, existing scan loop |
| `CarStatusService` (Phase 4) | Parts/Costs tabs read from components where available, fall back to line-item inference | read-side only, last phase |
| Vehicle-sale write path (`OfficeManagerSync` status mapping + any manual status change) | NO inline change — the **nightly integrity scan** owns detection; the UI settlement flow is attached to the manual sale action only | deliberately avoids touching OM sync (highest-risk file) |

**Explicitly NOT touched:** `MaintenanceTaskService`, ticket state machine/`TRANSITIONS`, `MaintenanceInvoiceService`, billing/`recalcFromTasks`, `ContractEligibilityService`, logistics. The asset layer never drives `workflow_status` — enforced by code review rule: no `workflow_status` writes outside `MaintenanceWorkflowService`, unchanged.

## 7. Events / listeners / jobs

House architecture is **direct service calls + NotificationScanner**, not Laravel event broadcasting — keep it. Do NOT introduce an event-bus layer for this.

- **Synchronous (in-transaction):** component writes + `component_events` + `vehicle_log_events` mirror — one transaction, no listeners (audit consistency > decoupling).
- **Laravel events:** none required. (If a future consumer needs hooks, `ComponentInstalled`/`ComponentRemoved` events can be added then — YAGNI now.)
- **Scheduled (Kernel):** `components:integrity` nightly; `notifications:scan` picks up the two new detectors automatically (existing schedule).
- **Queued jobs:** none. Backfill runs as a foreground artisan command (single-threaded XAMPP prod — no queue worker guarantees; consistent with existing `om:sync`/`mileage:scan` pattern). Chunked with progress output for large sweeps.

## 8. Rollback strategy

Layered — each deploy point reverts independently:

1. **Feature flag first:** `features.asset_layer` = `off | shadow | enforced`. `shadow` = writes happen, failures only log, nothing reads. `enforced` = atomic with workflow writes + UI reads on. **Rollback = flip to `off`** — workflow behaves exactly as today (the flag guards every integration call site). No deploy needed for the flip (config + cache clear).
2. **Schema:** all-additive; `php artisan migrate:rollback --step=5` cleanly drops the five migrations (reverse order, self-FK handled). Safe at any time before Phase 3 data exists; after backfill, prefer flag-off over schema rollback (don't destroy captured truth).
3. **Backfill:** command is additive + idempotent; "rollback" = `components:backfill-purge --source=legacy_backfill` (deletes only rows with legacy source markers, never workflow-born rows) — shipped WITH the backfill command, tested before first prod run. `db:backup` mandatory precondition (house rule).
4. **Data safety invariant:** no existing table loses or changes a column at any phase, so no rollback can corrupt current maintenance/billing data.

## 9. Deployment strategy

Target: VFZDubai prod (`ali@81.85.92.150:8087`) — remember the server quirks: umask 0027 → post-deploy `chown -R www-data /var/www/html` + `chmod 0644` on php configs (COPY --chmod ignored by its BuildKit).

| Step | Ships | Flag state | Verify before next step |
|---|---|---|---|
| D1 (Phase 1) | 5 migrations, models, catalog seed, permissions seed, flag=off | off | migrations green; app behavior byte-identical; catalog seeded; `/anomalies` unaffected |
| D2 (Phase 2a) | `ComponentService` + endpoints + `installPurchase`/`confirmRoutineServices` hooks | **shadow** (1–2 weeks) | shadow writes accumulate from real workshop activity; error log clean; spot-check rows vs physical reality with the workshop |
| D3 (Phase 3) | backfill + purge + integrity commands | shadow | `db:backup` → `--dry-run` report reviewed → real run → reconciliation report; second run = zero creates; integrity scan clean |
| D4 (Phase 2b) | flag → **enforced** (atomic transactions, disposition modal becomes blocking, serial validation live) | enforced | one real replacement job end-to-end with the workshop team; watch for 422 friction, tune catalog |
| D5 (Phase 4) | UI reads: Components tab, warehouse page, endpoint re-points (`serviceHistory`/`tireHistory` — same response shapes), sale settlement, export | enforced | Playwright pass on new screens (house verification style); old surfaces byte-compatible |

Sequencing vs audit Part I: the workflow P0s (awaiting-parts state, cost-model guard, release block, confirm-API tightening) are a **separate PR track**, deployable before or between D1–D2 — zero shared files except `routes/api.php` (merge-trivial).

## 10. Testing strategy

**Unit (PHPUnit, new `tests/Feature/Components`):**
- `ComponentService` matrix: every (status,location) invalid pair rejected; serialized-without-serial 422; consumable-instantiation refusal; duplicate-serial 409; predecessor lock (two concurrent installs on one slot → one wins, house race-test pattern like double-close); removal atomicity (missing disposition → zero writes); odometer regression rejected; transfer atomicity.
- Model hooks: `warranty_until` derivation incl. null cases; guard exception.

**Feature/API:** every endpoint × permission (viewer 403 on mutate, manage-role 200), response-shape snapshots for the re-pointed `serviceHistory`/`tireHistory` (byte-shape compatibility is the contract).

**Integration (the 15 acceptance scenarios from the design doc §11, one test each):** battery install, pad replacement with predecessor, cross-vehicle transfer, engine to-stock, warranty return, both sale variants, spare reuse (warranty NOT reset), comeback context, incorrect-diagnosis (no component created), serial 422, consumable guard, backfill idempotency (run-twice, zero delta), ticketless transfer, cost report totals.

**Backfill rehearsal:** restore latest prod backup into a scratch DB (`db:backup` artifacts exist), run backfill + integrity scan there FIRST; review reconciliation counts with you before any prod run.

**Shadow-mode validation (the real test):** during D2's 1–2 weeks, weekly diff of shadow component rows vs workshop's actual jobs — the physical-truth check no test suite can fake.

**Regression fence:** existing maintenance workflow test suite (CRUD-green convention) must pass untouched at every deploy point; plus one dedicated test asserting a full ticket lifecycle with flag=off writes **zero** rows to the four new tables.

---

## Decisions confirmed 2026-07-23 (review closed)

1. Single `features.asset_layer` flag with 3 modes (off/shadow/enforced). ✔
2. **Permission grants (explicit, no implicit overlap):** `components.manage` → `super-admin`/`admin` (full set), `manager`, `maintenance` (workshop manager role), `supervisor` (authorized maintenance delegates). NOT granted to `inspector`, `logistics` (technician-tier), `operations`, `finance`, `viewer` — they receive `components.view` only; technicians gain manage only by explicit per-user grant. `components.backfill` → super-admin/admin only. ✔
3. Shadow period: **2 weeks** of real workshop activity before `enforced`. ✔
4. Audit Part I P0 track (`awaiting_parts_active`, mixed cost-model guard, safety release rules) runs **in parallel** with Phase 1. ✔

**Standing business rules (restated as build invariants):** vehicle always knows current installed components; every removal requires a disposition; components ≠ services, always; consumables never instantiate components; old parts never disappear from history; no existing ticket state is modified except the explicitly planned Part I P0 additions.

*Phase 1 scope locked: migrations + models + constants + permissions + catalog seed. No backfill, no UI, no workflow behavior change.*
