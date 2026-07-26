# Maintenance Event Type-Driven Domain — Implementation Plan (Roadmap)

**Date:** 2026-07-26 · **Status:** FOR FINAL REVIEW — still no code · **Parents:** `Service-vs-Fault-Domain-Separation.md` (approved ADR + schema), `Maintenance-Workflow-Audit.md`, `Asset-Layer-Architecture.md`.

**Prime directive:** the current Maintenance workflow must keep working unchanged at every deploy point. Every step below is independently deployable and independently revertible. The type layer is **additive-only** (no existing column dropped or repurposed), **write-only** until Phase 1 reads go live, and dark-launchable behind one flag `features.event_kind` (`off | shadow | enforced`, default **off** in prod), mirroring `features.asset_layer`.

> **AMENDMENT (2026-07-26):** **Recall is REMOVED from scope** — three types only: **Fault, Service, Inspection**. `recall_campaigns`, `recall_campaign_id`, `RecallCampaign`, and the `maintenance.recall` permission are dropped everywhere below. `kind ∈ {fault, service, inspection}`; `maintenance_tasks` gains **three** `*_catalog_id` FKs. Migrations reduce from 6 to **5**. Confirmed DB engine = **MariaDB 10.4.32**, which enforces `CHECK` (≥10.2.1) — the `CHECK` half is live, no fallback needed.

**Review decisions locked (2026-07-26):** (1) Typed FKs — three separate nullable `*_catalog_id` columns. (2) Single-catalog enforcement — `CHECK` constraint (MariaDB 10.4) **and** application `saving` guard. (3) Multi-type tickets render as a separated, ordered Timeline; a fault and a service are never merged into one ambiguous entry.

---

## 1. Exact migration order

All Phase 0, one PR, sequential timestamps (no FK can precede its target). House rule: **migrations are schema-only; every data move is a backup-gated command, never a migration.**

| # | Migration file | Creates / alters | Depends on |
|---|---|---|---|
| 1 | `2026_07_27_100000_create_service_catalog_table.php` | `service_catalog` | — |
| 2 | `2026_07_27_100100_create_fault_catalog_table.php` | `fault_catalog` | — |
| 3 | `2026_07_27_100200_create_inspection_types_table.php` | `inspection_types` | — |
| 4 | `2026_07_27_100300_add_kind_to_maintenance_tasks.php` | `maintenance_tasks`: `kind` string(20) idx default `fault`; `fault_catalog_id`/`service_catalog_id`/`inspection_type_id` (nullable FK, `nullOnDelete`); `classification_source` string(20) default `resolver`; `needs_review` bool idx default false; **`CHECK` constraint** (via `DB::statement`) enforcing exactly-one-catalog-matches-kind OR all-null (legacy) | 1,2,3 |
| 5 | `2026_07_27_100400_add_fault_catalog_id_to_fault_causes.php` | `fault_causes`: nullable FK `fault_catalog_id` (`nullOnDelete`); keep `symptom_key` in parallel (cut over in P1) | 2 |

Rules: composite indexes exactly as the design doc §2/§3; every `down()` drops in reverse order (6→1); migration 5's `down()` drops the `CHECK` constraint before the columns. **No data migration** in this PR — `kind` backfill is a command (§5).

The `CHECK` (migration 4, MariaDB 10.4 — enforced):

```sql
ALTER TABLE maintenance_tasks ADD CONSTRAINT chk_task_kind_catalog CHECK (
     (kind='fault'      AND fault_catalog_id   IS NOT NULL AND service_catalog_id IS NULL AND inspection_type_id IS NULL)
  OR (kind='service'    AND service_catalog_id IS NOT NULL AND fault_catalog_id   IS NULL AND inspection_type_id IS NULL)
  OR (kind='inspection' AND inspection_type_id IS NOT NULL AND fault_catalog_id   IS NULL AND service_catalog_id IS NULL)
  OR (fault_catalog_id IS NULL AND service_catalog_id IS NULL AND inspection_type_id IS NULL)  -- legacy/unmatched, needs_review
);
```

**Precondition VERIFIED:** prod & local DB engine = MariaDB 10.4.32, which enforces `CHECK` constraints (support since 10.2.1). The `CHECK` half is live; no app-guard-only fallback required.

Config (same PR, no migration): `config/service_catalog.php`, `config/fault_catalog.php`, `config/inspection_types.php` seed definitions (split out of `config/maintenance_findings.php`, which stays for backward compatibility during transition); flag `features.event_kind` (default `off`).

---

## 2. Laravel model design

Four new models in `app/Models`, house conventions (string-constant enums, `$fillable`, `booted()` guards, `slug` upsert idempotency):

**`ServiceCatalog`**
- Casts: ints/booleans. Scope: `active()`, `ordered()`.
- Helper: `dueBy(): array` (km/months presence), `reminderType(): ?string`.
- Implicit `kind = service` (constant `KIND = MaintenanceTask::KIND_SERVICE`).

**`FaultCatalog`**
- Scope: `active()`, `ofCategory($key)`. Constant `KIND = KIND_FAULT`.
- Accessor: `suggestedSeverity()` (descriptive prefill only).

**`InspectionType`**
- Scope: `active()`. Constant `KIND = KIND_INSPECTION`.
- Helper: `expectsMeasurements(): bool`, `maySpawnFault(): bool`.

**`RecallCampaign`**
- Scope: `active()`, `appliesTo(Vehicle $v)` (make/model/year window matcher). Constant `KIND = KIND_RECALL`.

**`MaintenanceTask`** (existing, extended — the core change):
- Constants: `KIND_FAULT/SERVICE/INSPECTION/RECALL` + `KINDS`; `CLS_CATALOG/RESOLVER/IMPORT/MANUAL` + `CLS_SOURCES`; `KIND_META` (emoji/label/tone — single source of colour for all surfaces).
- Casts: `needs_review` bool.
- `$fillable`: add `kind`, the four `*_catalog_id`, `classification_source`, `needs_review`. **`kind` is NOT settable to an arbitrary value from request input** — only via the classification helper (§5), same discipline the Asset-Layer plan uses for `status`/`location`.
- `booted()` **saving guard** (the app-layer half of the enforcement pair): throw `DomainException` if more than one `*_catalog_id` is non-null, or if the non-null one does not match `kind`. All-null is allowed only when `needs_review = true` (legacy/unmatched). This is the first line of defense; the DB `CHECK` is the last.
- Scopes: `faults()`, `services()`, `inspections()`, `recalls()`, `ofKind(...$k)`, `needsReview()`.
- Predicates: `isFault()/isService()/isInspection()/isRecall()`.
- Relations: `faultCatalog()`, `serviceCatalog()`, `inspectionType()`, `recallCampaign()`, plus `catalog(): ?Model` (returns the one matching `kind`).

**`FaultCause`** (existing, extended): add `belongsTo(FaultCatalog)`; scope reads prefer `fault_catalog_id`, fall back to `symptom_key` during transition.

**`VehicleLogEvent`** (existing, extended): add constants `EVENT_SERVICE_LOGGED` already exists; add `EVENT_INSPECTION_LOGGED`, `EVENT_RECALL_LOGGED` (constants only, no schema change) so the timeline can label by kind.

---

## 3. Relationships

| Model | Relations |
|---|---|
| `ServiceCatalog` | `hasMany(MaintenanceTask, service_catalog_id)` |
| `FaultCatalog` | `hasMany(MaintenanceTask, fault_catalog_id)`, `hasMany(FaultCause)` |
| `InspectionType` | `hasMany(MaintenanceTask, inspection_type_id)` |
| `RecallCampaign` | `hasMany(MaintenanceTask, recall_campaign_id)` |
| `MaintenanceTask` (existing, add) | `belongsTo(ServiceCatalog)`, `belongsTo(FaultCatalog)`, `belongsTo(InspectionType)`, `belongsTo(RecallCampaign)`; existing `belongsTo(Maintenance)`, `belongsTo(Vehicle)` unchanged |
| `Vehicle` (existing, add) | `hasMany(MaintenanceTask)` + typed convenience: `faults()`, `services()`, `inspections()`, `recalls()` (each = `maintenanceTasks()->{scope}()`). Keeps existing `serviceRecords()`/component relations for the P4 Asset-Layer bridge |
| `Maintenance` (existing, add) | typed read convenience: `faultTasks()`, `serviceTasks()`, … (drawer renders grouped sections by kind — decision #3) |
| `FaultCause` (existing, add) | `belongsTo(FaultCatalog)` |

Enforcement rule (code-review invariant): **no feature may query `maintenance_tasks` by type using `category_key`, `symptom`, `severity`, `maintenance_type`, or `visit_context`.** Type filtering goes through the scopes/relations above only.

---

## 4. Policies and permissions

House pattern = Spatie permissions on route middleware (`resource.action`). The type split is **classification metadata on existing actions**, so it needs almost no new grants.

| Permission | Grants | Default roles |
|---|---|---|
| *(existing)* `maintenance.initiate` / `maintenance.inspector` | now also carry the type selection on the finding (Fault/Service/Inspection form) | unchanged |
| *(existing)* `maintenance.manage` | curate the four catalogs (add/retire rows), set/override `kind` (`classification_source=manual`), clear `needs_review` | unchanged (manager tier) |
| `maintenance.recall` *(new, optional)* | create recall campaigns + apply a recall to a vehicle | super-admin/admin + manager |

Route mapping (`routes/api.php`, same style as the `/maintenance-tickets` block):
- Catalog list endpoints (`GET /catalogs/{service|fault|inspection}`) → `maintenance.view`.
- Catalog curation (`POST/PATCH`) → `maintenance.manage`.
- Recall register + apply → `maintenance.recall` (or `maintenance.manage` if we defer the new grant — decide at review).
- `needs_review` queue → `maintenance.manage`.

Guard notes: super-admin bypass already global; no per-model policy classes (fleet-wide, role-scoped like everything else). The inspector's type selection needs **no** new permission — it is part of the existing Decide step he already owns.

---

## 5. New services / classes

| Class | Responsibility |
|---|---|
| `app/Services/EventClassificationService.php` | **The single classification choke point.** Two methods: `classifyFromCatalog(array $selection): array` — the NORMAL path: given the user's catalog pick, returns `[kind, {catalog}_id, classification_source=catalog]`; and `resolveLegacyKind(MaintenanceTask|array $row): array` — the legacy/import shield (§5 decision table of the ADR), returns `[kind, catalog_id?, source, needs_review]`. Never called at read time. Every write path (workflow, API, backfill, import) goes through this — no direct `kind` assignment elsewhere |
| `app/Console/Commands/EventKindBackfill.php` (`events:backfill-kind --dry-run --from= --to=`) | Phase 0 backfill: sweeps `maintenance_tasks` where `kind IS NULL`/`classification_source IS NULL`, calls `resolveLegacyKind`, persists, marks ambiguous rows `needs_review`. `--dry-run` prints per-kind + needs_review counts, writes nothing. Idempotent (run-twice = zero delta). Refuses to run without a fresh `db:backup` unless `--force` (house rule). Chunked with progress output (single-threaded XAMPP prod) |
| `app/Console/Commands/EventKindPurge.php` (`events:purge-kind`) | rollback aid: nulls `kind`/catalog FKs only for rows with `classification_source IN (resolver,import)` — never touches `catalog`-sourced (user-picked) rows. Shipped WITH the backfill command, tested before first prod run |
| Seeders: `ServiceCatalogSeeder`, `FaultCatalogSeeder`, `InspectionTypeSeeder` | idempotent `slug` upsert from the three config files. No recall seed (data-driven). `ServiceCatalogSeeder` reconciles the `tire`/`tyre` + `battery`/`battery_check` slug drift into one canonical set |
| `app/Http/Controllers/CatalogController.php` | thin: list/curate the three catalogs; `ResponseHelper` convention |
| `app/Http/Controllers/RecallController.php` | recall register + apply-to-vehicle (P4) |
| Resource updates: `MaintenanceTaskResource`, `MaintenanceWorkflowResource`, `WorkshopEventResource` | expose `kind`, `kind_meta` (emoji/label/tone), and `catalog` ({id,slug,name}); keep all legacy fields for backward compatibility |
| `app/Services/Explainability/Explainers/*` (P4) | `kind` becomes a classification node in the DAG; fault-metric explainers filter `kind=fault` so figure ↔ explanation agree |

---

## 6. Existing services modified (the complete blast-radius list)

| Service / file | Change | Risk containment |
|---|---|---|
| `MaintenanceTaskService::syncFromFindings` (`:71–98`) | copy `kind` + `{catalog}_id` + `classification_source` from each finding onto the task (findings carry them from the type-first intake). During `shadow`, if a finding lacks them, call `resolveLegacyKind`. Stop hardcoding `"Fault identified: …"` — use `KIND_META` label | dark write in shadow; readers untouched until enforced |
| `MaintenanceWorkflowService::openServiceTicket` (`:2203`) | **standalone fix** + stamp `kind=service`, `service_catalog_id` from the reminder's `service_type`. Also fixes the `visit_context='standard'` mistag → `CONTEXT_ROUTINE` | one line + one stamp; ship-early candidate |
| `NotificationScanner::highMaintenanceCost` (`:575`) | **standalone fix**: guard so service-`kind` spend is not titled "repair cost" (in shadow it can already read `kind`) | additive guard |
| **Fault-only read consumers** (enforced mode): `DashboardService::topFaults()/faultCars()`; `RecurringFaultService`; `PartIntelligenceService::detectRecurrence()`+dup-spend; `CarStatusService::healthScore()/reliabilityScore()/faultAnalytics()/faultHistory()/vehicleKpis()`; `MaintenanceWorkflowService::faultHistory()` chronic watchdog; `MaintenanceForesightService` chronic/act-now | re-point to `MaintenanceTask::faults()` / `$vehicle->faults()`; drop ad-hoc `category_key='routine'` / `visit_context` filters (`recurringFaults()` and Foresight already exclude routine — they just switch to the canonical scope) | flag-gated; each is a 1–3 line filter change |
| **Service-inclusive consumers**: `CostIntelligenceService` + `ExcelVehicleExpenseProvider` (split `service_cost`/`repair_cost`); `RealProfitService` (numbers unchanged); line-item roll-up; `ActivityFeedService` (new `service`/`inspection`/`recall` categories) | additive split; totals preserved | numbers must reconcile pre/post — asserted in tests |
| Frontend (Phase 3): `Dashboard.js`, `VehicleProfile.js` (+ wire dead `ServiceHistory.js`), `lib/vehicleTimeline.js` (read `kind`, stop hardcoding `task_identified→fault`), `MaintenanceWorkflow.js` (kind filter + card pill), retire `DashboardService::FAULT_CATEGORIES` + `frontend/src/lib/faultCategories.js` | render colour/emoji by `kind_meta` | last phase, presentation only |

**Explicitly NOT touched:** ticket state machine / `TRANSITIONS`, `MaintenanceInvoiceService`, `ContractEligibilityService`, logistics, permissions engine, OM sync classification (imports call `resolveLegacyKind`, they do not gain type logic inline). `kind` never drives `workflow_status`.

---

## 7. Events / listeners / jobs

House architecture is **direct service calls + NotificationScanner** — keep it, no event bus.

- **Synchronous (in-transaction):** classification is written inside the same transaction as the task (`syncFromFindings`) — no listeners.
- **Laravel events:** none.
- **Scheduled (Kernel):** none new. `notifications:scan` behaviour changes only via the `highMaintenanceCost` guard.
- **Queued jobs:** none. `events:backfill-kind` runs as a foreground artisan command (single-threaded XAMPP prod, consistent with `om:sync`/`mileage:scan`), chunked with progress.

---

## 8. Rollback strategy

Layered — each deploy point reverts independently:

1. **Feature flag first:** `features.event_kind` = `off | shadow | enforced`. `shadow` = `kind` written + backfilled, **nothing reads it** (analytics/health/dashboard behave exactly as today). `enforced` = readers use the scopes + UI reads live. **Rollback = flip to `off`** (config + cache clear, no deploy).
2. **Schema:** all-additive; `php artisan migrate:rollback --step=6` drops the six migrations (reverse order; `CHECK` dropped before columns). Safe before backfill; after backfill prefer flag-off over schema rollback (don't destroy captured classification).
3. **Backfill:** additive + idempotent; "rollback" = `events:purge-kind` (nulls only resolver/import-sourced rows, never user-picked). `db:backup` mandatory precondition.
4. **Data safety invariant:** no existing column is dropped or repurposed at any phase (`maintenance_type`, `visit_context`, `category_key`, `severity`, `findings` all retained), so no rollback can corrupt current maintenance/billing/analytics data.

---

## 9. Deployment strategy

Target: VFZDubai prod (`ali@81.85.92.150:8087`) — server quirks: umask 0027 → post-deploy `chown -R www-data /var/www/html` + `chmod 0644` php configs (COPY --chmod ignored by its BuildKit).

| Step | Ships | Flag state | Verify before next step |
|---|---|---|---|
| D0 (parallel micro-PR) | 2 standalone fixes: `openServiceTicket` visit_context, `highMaintenanceCost` guard | n/a | fixes verified; no shared files with D1 except trivial |
| D1 (Phase 0) | 6 migrations, 4 models + `MaintenanceTask` extension, 3 catalog seeds, guard, `CHECK`, flag=off | off | migrations green; `CHECK` verified on prod engine; app byte-identical; catalogs seeded; guard unit-tested |
| D2 (Phase 0 backfill) | `events:backfill-kind` + `events:purge-kind` | off→**shadow** | `db:backup` → `--dry-run` report reviewed with you → real run → per-kind + needs_review counts; second run = zero delta |
| D3 (Phase 1 backend reads) | re-point fault-only + service-inclusive consumers; Vehicle relations | **shadow** → A/B compare | with flag on: fault leaderboard/health/recurrence exclude services; **cost/profit numbers identical** to pre-flag (regression assert) |
| D4 (Phase 2 API) | expose `kind`/`kind_meta`/`catalog` on 3 resources; activity-feed + timeline mapping | shadow | legacy fields still present; frontend unaffected |
| D5 (Phase 3 UI) | type-first intake (4 forms), colour/emoji everywhere, wire `ServiceHistory.js`, retire parallel taxonomies, kind filter | **enforced** | Playwright pass; inspector sees 🔴/🔵/🟨/🟩 at intake; oil change absent from Fault Leaderboard end-to-end |
| D6 (Phase 4) | Recall register + Inspection first-class + `kind` in Explainability + Asset-Layer `service_catalog.component_slug` bridge | enforced | one recall applied end-to-end; explainer figure↔explanation agree |

---

## 10. Testing strategy

**Unit (PHPUnit, new `tests/Feature/EventKind`):**
- `EventClassificationService::classifyFromCatalog`: each catalog pick → correct `kind` + single matching FK + `source=catalog`.
- `resolveLegacyKind`: every row of the §5 decision table (service exact-match, fault exact-match, `category_key='routine'`, breakdown ticket, `level='routine'`, fuzzy routine token → needs_review, inspection origin, nothing-matched → fault+needs_review). Recall NEVER inferred.
- **Enforcement pair:** `saving` guard rejects (a) two non-null catalog FKs, (b) FK not matching `kind`; and the DB `CHECK` rejects the same via a raw insert (proves both halves).
- Scopes: `faults()/services()/…` return only their kind.

**Feature/API:** catalog endpoints × permission (viewer 403 on curate, manage 200); resource snapshot includes `kind`+`kind_meta`+`catalog`; legacy fields still present (byte-shape backward-compat contract).

**Integration (acceptance scenarios, one test each):** oil-change logged → `kind=service`, absent from `topFaults()`, absent from `recurringFaults()`, does NOT dock `healthScore()`, DOES appear in cost total + timeline as 🔵; brake-noise → `kind=fault`, present in all fault stats; two oil changes 60d apart → NOT a recurring fault, NOT a duplicate-spend investigation; inspection that finds a leak → separate linked `kind=fault` task via `derived_from_task_id` (inspection stays inspection); recall applied → `kind=recall`, independent of severity; backfill idempotency (run-twice, zero delta); legacy unmatched row → `kind=fault`+`needs_review`.

**Backfill rehearsal:** restore latest prod backup into a scratch DB, run `events:backfill-kind --dry-run` then real + `events:purge-kind`, review reconciliation counts with you before any prod run.

**Regression fence:** existing maintenance workflow + analytics test suites pass untouched at every deploy point; plus one dedicated test asserting that with `features.event_kind=off`, a full ticket lifecycle produces the **exact same** `topFaults()` / `healthScore()` / cost outputs as before the migration (behavioural identity in `off` mode).

**Numbers-reconciliation test (the business-trust check):** on a fixed fixture, assert Total Maintenance Spend + Profitability are **identical** pre/post separation, while Fault Leaderboard count **drops by exactly the service-task count** — proving "services still cost, just aren't faults."

---

## Decisions confirmed 2026-07-26 (review point 1)

1. **Typed FKs** — four separate nullable `*_catalog_id` columns (DB-enforceable), not polymorphic. ✔
2. **Single-catalog enforcement** — MySQL 8 `CHECK` constraint **and** application `saving` guard (both halves). ✔
3. **Multi-type tickets** — rendered as a separated, ordered Timeline; fault and service never merged into one ambiguous entry (drawer = grouped sections by `kind`). ✔

**Open items still to confirm at THIS review (before D1):**
- `maintenance.recall` as a new permission vs. folding recall into `maintenance.manage` (§4).
- Prod DB engine `CHECK` support verified (§1 precondition) — if unsupported, app-guard-only fallback.
- `fault_causes` cut-over timing: parallel `symptom_key` + `fault_catalog_id` in P0, drop `symptom_key` in a later PR (recommended) vs. immediate.

**Standing build invariants (restated):** `kind` is the only classifier; catalog is its source; descriptive fields never decide type; resolver runs only at backfill/import, never read-time; separation enforced structurally via scopes/relations + guard + `CHECK`; no existing column dropped or repurposed; no `workflow_status` write added.

*Phase 0 scope locked: 6 migrations + 4 catalog models + `MaintenanceTask`/`FaultCause` extension + constants + 3 seeds + classification service + backfill/purge commands, all behind `features.event_kind=off`. No UI, no read-path change, no workflow behaviour change.*
