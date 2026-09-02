# ADR: Maintenance Event Type-Driven Domain (Fault / Service / Inspection)

**Date:** 2026-07-26 · **Status:** APPROVED — Phase 0 in build · **Companions:** `docs/Service-vs-Fault-Implementation-Plan.md`, `docs/Maintenance-Workflow-Audit.md`, `docs/Asset-Layer-Architecture.md`.

> **AMENDMENT (2026-07-26):** **Recall is REMOVED from scope.** To keep the system focused and simple we ship **three** first-class types only — **Fault, Service, Inspection**. All `recall`/`recall_campaigns`/`recall_campaign_id` references below are superseded and no longer part of the design; ignore them. `kind ∈ {fault, service, inspection}`. Enforcement = MySQL/MariaDB `CHECK` **plus** application guard. Legacy backfill runs in Phase 0 (`--dry-run` then real).

**Decision in one sentence:** A maintenance event stops being one undifferentiated "maintenance" and becomes one of three first-class types — **Fault, Service, Inspection** — classified by a single stored discriminator `kind`, whose *source of truth* is the catalog the user picked from, so that every layer of the system reads type from one place and never re-guesses it from `symptom`/`category_key`/`maintenance_type` again.

---

## الخلاصة التنفيذية (Arabic summary)

النوع (`kind`) يصبح التصنيف الأساسي المخزَّن على مستوى الحدث. مصدره الحقيقي هو الكتالوج الذي يختار منه المستخدم (Service Catalog / Fault Catalog / Inspection Types / Recall Register). الحقول القديمة (`maintenance_type`, `visit_context`, `category_key`, `severity`) تبقى للوصف والسياق فقط ويُمنع استخدامها للتصنيف. الـ resolver يعمل مرة واحدة فقط للبيانات القديمة والاستيراد، لا وقت القراءة. الفصل يُفرَض بنيوياً عبر Model Scopes وعلاقات Vehicle موجّهة بالنوع، فلا يستطيع أي feature مستقبلي إعادة التخمين.

---

## 0. Governing principles (non-negotiable invariants)

1. **`kind` is the ONLY classifier.** Any code that needs to know "is this a fault or a service?" reads `maintenance_tasks.kind` — never `symptom`, `category_key`, `severity`, `maintenance_type`, or `visit_context`.
2. **The catalog is the source of type.** `kind` is not typed by hand and not inferred from text at write time in the normal path: the user selects a row from a typed catalog, and that row's `kind` is copied onto the event. Type is a *selection*, not a *guess*.
3. **Descriptive fields stay, demoted.** `maintenance_type`, `visit_context`, `category_key`, `severity`, `symptom` remain on the row to *describe* the event and its context. They are display/analytics attributes, never type inputs.
4. **The resolver is a legacy shield, not the classifier.** Text/heuristic resolution runs exactly once — during backfill of pre-existing rows and during imports that carry no catalog reference. Its output is persisted. It never runs at read time.
5. **Separation is structural, not conventional.** Access to events goes through model scopes (`faults()`, `services()`, …) and vehicle relations (`$vehicle->faults`, `$vehicle->services`, …). A new feature cannot accidentally mix types because the only doors into the data are type-aware.
6. **Additive & reversible.** Every change is additive; old columns are retained. The whole rollout sits behind a flag (`SERVICE_FAULT_SPLIT`) mirroring `ASSET_LAYER_MODE`. Rollback = flag off; data intact.
7. **No workflow change.** The maintenance lifecycle (Inspect → Decide → Dispatch → Repair → Re-inspect → Close), its states, and permissions are untouched. `kind` is metadata written at existing steps.

### The three types

| `kind` | Colour | Nature | Example | Counts in fault stats? | Counts in cost/history? |
|---|---|---|---|---|---|
| `fault` | 🔴 | Unplanned failure/defect | Engine oil leak, brake noise, gearbox failure | **Yes** | Yes |
| `service` | 🔵 | Planned preventive work (recurring is normal) | Oil change, air filter, tyre rotation | **No** | Yes |
| `inspection` | 🟨 | A check that may or may not find a fault | Pre-rental, periodic, post-repair QC | No | Neutral (cost if any) |

---

## 1. Entity-relationship model (ERD)

```
                         ┌───────────────────────┐
                         │      vehicles         │
                         └───────────┬───────────┘
                                     │ 1
                                     │
                                     │ N
                         ┌───────────┴───────────┐
                         │     maintenances      │   (ticket / container — UNCHANGED)
                         │  maintenance_type ....│   ← now DESCRIPTIVE ONLY
                         │  visit_context .......│   ← now DESCRIPTIVE ONLY
                         └───────────┬───────────┘
                                     │ 1
                                     │
                                     │ N
                         ┌───────────┴─────────────────────────────────┐
                         │            maintenance_tasks                 │   (EVENT grain)
                         │  kind  ∈ {fault|service|inspection|recall}   │   ← PRIMARY CLASSIFICATION
                         │  fault_catalog_id      (nullable FK) ────────┼──────► fault_catalog
                         │  service_catalog_id    (nullable FK) ────────┼──────► service_catalog
                         │  inspection_type_id    (nullable FK) ────────┼──────► inspection_types
                         │  recall_campaign_id    (nullable FK) ────────┼──────► recall_campaigns
                         │  classification_source ∈ {catalog|resolver|  │
                         │                            import|manual}    │
                         │  needs_review (bool)                         │
                         │  symptom, category_key, severity, root_cause │   ← DESCRIPTIVE ONLY
                         └───────────┬──────────────────────────────────┘
                                     │
        ┌────────────────────────────┼───────────────────────────────────────┐
        │ (existing, now type-aware) │                                        │
        ▼                            ▼                                        ▼
  maintenance_line_items      service_records (asset layer)          vehicle_components
  kind (part|labor)           service_type ── aligns ──► service_catalog.slug   (P4 link)
  + inherits event kind

   ┌──────────────────┐   ┌──────────────────┐   ┌──────────────────┐   ┌──────────────────┐
   │  fault_catalog   │   │ service_catalog  │   │ inspection_types │   │ recall_campaigns │
   │  kind=fault      │   │  kind=service    │   │  kind=inspection │   │  kind=recall     │
   └──────────────────┘   └──────────────────┘   └──────────────────┘   └──────────────────┘
        (evolves from        (evolves from           (evolves from          (NEW — no
         fault_causes)        ServiceTypes +           inspection_records)    predecessor)
                              routine category)
```

**Exactly-one-catalog invariant:** for a given `maintenance_tasks` row, exactly the one `*_catalog_id` that matches its `kind` is non-null; the other three are null. Enforced at the application layer (model `saving` guard) and optionally by a DB `CHECK` (MySQL 8+). Legacy/import rows that could not be matched keep all four null, `kind='fault'` (safe default) or the resolver's best guess, and `needs_review = true`.

---

## 2. The discriminator: `kind` + model constants

`kind` is a **string** column (house style — string + PHP constants, not a MySQL ENUM), indexed.

```php
// App\Models\MaintenanceTask  (and mirrored on the findings[] JSON entries)
public const KIND_FAULT      = 'fault';
public const KIND_SERVICE    = 'service';
public const KIND_INSPECTION = 'inspection';
public const KINDS = [self::KIND_FAULT, self::KIND_SERVICE, self::KIND_INSPECTION];

// classification_source — provenance of the kind value (audit + review targeting)
public const CLS_CATALOG  = 'catalog';   // user picked a catalog row (normal path — authoritative)
public const CLS_RESOLVER = 'resolver';  // legacy backfill heuristic
public const CLS_IMPORT   = 'import';    // sheet/OM import mapping
public const CLS_MANUAL   = 'manual';    // admin override
public const CLS_SOURCES  = [self::CLS_CATALOG, self::CLS_RESOLVER, self::CLS_IMPORT, self::CLS_MANUAL];

// Presentation (single source of colour/emoji for ALL surfaces — backend + exported to frontend)
public const KIND_META = [
    self::KIND_FAULT      => ['emoji' => '🔴', 'label' => 'Fault',      'tone' => 'red'],
    self::KIND_SERVICE    => ['emoji' => '🔵', 'label' => 'Service',    'tone' => 'blue'],
    self::KIND_INSPECTION => ['emoji' => '🟨', 'label' => 'Inspection', 'tone' => 'amber'],
];
```

### Columns added to `maintenance_tasks`

| Column | Type | Notes |
|---|---|---|
| `kind` | string(20), index, default `fault` | PRIMARY CLASSIFICATION. Default = current behaviour (safe). |
| `fault_catalog_id` | foreignId nullable, constrained→`fault_catalog`, nullOnDelete | set iff `kind=fault` |
| `service_catalog_id` | foreignId nullable, constrained→`service_catalog`, nullOnDelete | set iff `kind=service` |
| `inspection_type_id` | foreignId nullable, constrained→`inspection_types`, nullOnDelete | set iff `kind=inspection` |
| `recall_campaign_id` | foreignId nullable, constrained→`recall_campaigns`, nullOnDelete | set iff `kind=recall` |
| `classification_source` | string(20), default `resolver` | provenance (see constants) |
| `needs_review` | boolean, default false, index | true when resolver/import could not confidently classify |

The same `kind` + `catalog ref` keys are mirrored into each `maintenances.findings[]` JSON entry (the task's origin), so `syncFromFindings()` copies them onto the task rather than re-deriving.

---

## 3. The four catalogs (table schemas)

Conventions: `id` big-increments, `timestamps()`, string+constant enums, `slug` unique + stable, `is_active` to retire without breaking history.

### 3.1 `service_catalog` — the menu of planned/preventive work (`kind=service`)

Evolves from `App\Support\ServiceTypes` + `ServiceReminder::TYPE_LABELS` + the `routine` category in `config/maintenance_findings.php`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `slug` | string(80), **unique** | `oil_change`, `air_filter`, `cabin_filter`, `fuel_filter`, `tyre_rotation`, `wheel_alignment`, `brake_fluid`, `coolant`, `battery_check`, `spark_plugs`, `transmission_oil`, `differential_oil`, … Canonical — resolves `tire`/`tyre` & `battery`/`battery_check` drift. |
| `name` | string(120) | "Engine Oil Change" |
| `name_ar` | string(120), nullable | |
| `category_key` | string(40), index | reuse findings/component categories (`fluids`, `tyres`, `electrical`, …) for join-friendliness |
| `interval_km` | unsignedInteger, nullable | preventive cadence by mileage |
| `interval_months` | unsignedSmallInteger, nullable | preventive cadence by time |
| `service_reminder_type` | string(40), nullable | link key into `ServiceReminder::TYPE_LABELS` so completing this closes the reminder loop |
| `component_slug` | string(120), nullable | future link to `component_catalog.slug` (Asset Layer, P4) |
| `default_labor_hours` | decimal(6,2), nullable | |
| `is_active` | boolean default true | |
| `sort_order` | unsignedSmallInteger default 0 | picker ordering |

**Invariant:** every row is implicitly `kind=service`.

### 3.2 `fault_catalog` — the menu of failure/defect types (`kind=fault`)

Evolves from the non-routine categories of `config/maintenance_findings.php`; the existing `fault_causes` table (symptom → root-cause KB) is re-pointed to reference `fault_catalog_id` instead of a free `symptom_key`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `slug` | string(80), **unique** | `engine_oil_leak`, `coolant_leak`, `battery_failure`, `brake_noise`, `ac_not_cooling`, `gearbox_failure`, `suspension_noise`, `steering_vibration`, `engine_misfire`, … |
| `name` | string(120) | "Engine Oil Leak" |
| `name_ar` | string(120), nullable | |
| `category_key` | string(40), index | `engine`, `brakes`, `electrical`, `ac`, `transmission`, `suspension`, … |
| `default_severity` | string(10), nullable | suggested `fault_severity` prefill (`critical`/`moderate`/…) — descriptive, editable |
| `on_site` | boolean default false | mobile-fixable hint (migrated from catalog `on_site`) |
| `is_active` | boolean default true | |
| `sort_order` | unsignedSmallInteger default 0 | |

**Invariant:** every row is implicitly `kind=fault`. `fault_causes` gains `fault_catalog_id` (FK) and keeps its symptom→cause review-queue role.

### 3.3 `inspection_types` — the menu of checks (`kind=inspection`)

Evolves from the existing inspection workflow (`inspection_records`, pre-rental / post-repair / periodic).

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `slug` | string(80), **unique** | `pre_rental`, `post_repair_qc`, `periodic`, `dormancy_check`, `damage_walkaround` |
| `name` | string(120) | |
| `name_ar` | string(120), nullable | |
| `checklist_key` | string(60), nullable | which checklist template to render |
| `expects_measurements` | boolean default false | drives the Measurements form section |
| `may_spawn_fault` | boolean default true | an inspection can create a linked `kind=fault` task (see §4 relation) |
| `is_active` | boolean default true | |

**Invariant:** every row is implicitly `kind=inspection`. An inspection that *finds* something creates a **separate** `kind=fault` task linked via `derived_from_task_id` — the inspection itself never mutates into a fault.

*(A fourth catalog `recall_campaigns` was in an earlier draft; Recall is removed from scope per the 2026-07-26 amendment. If a manufacturer-campaign concept is ever needed, it would be added here as a separate catalog with `kind=recall`.)*

---

## 4. Scopes & Vehicle relations (code signatures — design spec, not implementation)

### 4.1 Model query scopes — the ONLY sanctioned way to filter by type

```php
// App\Models\MaintenanceTask
public function scopeFaults($q)      { return $q->where('kind', self::KIND_FAULT); }
public function scopeServices($q)    { return $q->where('kind', self::KIND_SERVICE); }
public function scopeInspections($q) { return $q->where('kind', self::KIND_INSPECTION); }
public function scopeOfKind($q, string ...$kinds) { return $q->whereIn('kind', $kinds); }

// Convenience predicates
public function isFault(): bool      { return $this->kind === self::KIND_FAULT; }
public function isService(): bool    { return $this->kind === self::KIND_SERVICE; }
public function isInspection(): bool { return $this->kind === self::KIND_INSPECTION; }

// Belongs-to catalog (typed)
public function faultCatalog()    { return $this->belongsTo(FaultCatalog::class); }
public function serviceCatalog()  { return $this->belongsTo(ServiceCatalog::class); }
public function inspectionType()  { return $this->belongsTo(InspectionType::class); }

// Resolve the active catalog row polymorphically by kind (read helper)
public function catalog(): ?Model  // returns the one matching *Catalog by $this->kind
```

**Enforcement guard (model `saving`):** rejects a row where the non-null `*_catalog_id` does not match `kind`, or where more than one catalog FK is set. This is the structural gate that makes mixing impossible.

### 4.2 Vehicle relations — "a vehicle is a container of typed events"

```php
// App\Models\Vehicle
public function maintenanceTasks() { return $this->hasMany(MaintenanceTask::class); }

public function faults()      { return $this->maintenanceTasks()->faults(); }
public function services()    { return $this->maintenanceTasks()->services(); }
public function inspections() { return $this->maintenanceTasks()->inspections(); }

// Asset-layer bridges (P4) — services are the natural home for component history
public function serviceRecords()   { return $this->hasMany(ServiceRecord::class); }     // existing
public function componentsHistory(){ return $this->hasMany(VehicleComponent::class); }  // existing (dark)
```

Every fault-oriented feature MUST start from `$vehicle->faults()` / `MaintenanceTask::faults()`; every service feature from `->services()`. No feature writes a raw `where('kind', …)` or, worse, a `where('category_key', 'routine')`.

---

## 5. Legacy resolution & imports (`resolveLegacyKind`)

The resolver runs **only** at (a) backfill of existing rows and (b) import of external data with no catalog reference. Output is persisted; never read-time.

### 5.1 Decision table (priority order, first match wins)

| # | Signal | → `kind` | `classification_source` | `needs_review` |
|---|---|---|---|---|
| 1 | Symptom text exact-matches a `service_catalog.name/slug` or a `routine_service_types` key | `service` (+ `service_catalog_id`) | resolver | false |
| 2 | Symptom text exact-matches a `fault_catalog.name/slug` | `fault` (+ `fault_catalog_id`) | resolver | false |
| 3 | `category_key = 'routine'` OR ticket `visit_context = 'routine'` OR `maintenance_type = 'routine'` OR `trigger_reason = 'periodic'` | `service` | resolver | false |
| 4 | Ticket `maintenance_type ∈ {breakdown, ins_incident, non_ins_incident}` | `fault` | resolver | false |
| 5 | `maintenance_reasons.level = 'routine'` (sheet path) | `service` | import | true |
| 6 | Fuzzy contains a routine token (`oil`, `filter`, `rotation`, `alignment`, `coolant`, `battery check`) | `service` | resolver | **true** |
| 7 | Origin is an inspection intake (`test_drive`/checklist) with no defect finding | `inspection` | resolver | true |
| 8 | Nothing matched | `fault` (safe default) | resolver | **true** |

- **Recall (`kind=recall`) is never inferred** — it has no textual signal. Legacy data has none; it is only ever set by the new recall intake (§7). No backfill produces recalls.
- `needs_review = true` rows surface in an admin review queue (reuse the `fault_causes` pending-review pattern) so humans confirm ambiguous history instead of it being silently miscounted.

### 5.2 Backfill command

Extend the existing `php artisan maintenance:backfill-tasks` (`BackfillMaintenanceTasks`) with a `--kind` pass:

- `--dry-run` — report counts per resolved `kind` + `needs_review` totals, write nothing (matches the `db:backup`-gated re-import convention).
- Runs inside a transaction; idempotent (only fills `kind IS NULL` / `classification_source IS NULL`).
- Emits a summary: `fault: N, service: N, inspection: N, recall: 0, needs_review: N`.

### 5.3 Imports

`MaintenanceSheetImporter` / OM sync call `resolveLegacyKind` with `classification_source = import`, attempt a catalog-name match, and mark unmatched rows `needs_review = true`, `kind = fault`, `catalog_id = null`. Imports never silently create a wrong-typed authoritative row.

---

## 6. Read-path conversion — who switches to `kind`

Every consumer below stops inferring type and reads `kind` (via scopes/relations). This is the list the Phase-1/2 work must cover; ✅ marks the two that already separate correctly and just re-point to `kind`.

**Fault-only (Service EXCLUDED):**
`DashboardService::topFaults()` / `faultCars()` · `MaintenanceAnalyticsService::recurringFaults()` ✅ · `RecurringFaultService` · `PartIntelligenceService::detectRecurrence()` + duplicate-spend · `CarStatusService::healthScore()` / `reliabilityScore()` / `faultAnalytics()` / `faultHistory()` / `vehicleKpis()` (fault counts) · `MaintenanceForesightService` chronic/act-now ✅ (drop the `visit_context` filter for the `kind` scope) · `MaintenanceWorkflowService::faultHistory()` chronic watchdog · `NotificationScanner::highMaintenanceCost()` + chronic.

**Service-inclusive (Service COUNTED):**
`CostIntelligenceService` (+ `ExcelVehicleExpenseProvider` split into `service_cost`/`repair_cost`) · `RealProfitService::vehicleBridge()` (numbers unchanged) · line-item roll-up (`Maintenance::recalcFromTasks`) · `ActivityFeedService` (new `service` category) · timelines.

**Presentation (colour/emoji by `kind`):**
`Dashboard.js` · `VehicleProfile.js` (+ wire in dead `ServiceHistory.js`) · `lib/vehicleTimeline.js` (read `kind` instead of hardcoding `task_identified → 'fault'`) · `MaintenanceWorkflow.js` board (add `kind` filter + card pill; rename "🟢 Routine" severity to disambiguate) · retire parallel taxonomies `DashboardService::FAULT_CATEGORIES` and `frontend/src/lib/faultCategories.js`.

**API/Resources:** expose `kind`, `kind_meta`, and `catalog` on `MaintenanceTaskResource`, `MaintenanceWorkflowResource`, `WorkshopEventResource`.

**Explainability/AI prep:** `kind` becomes a classification node in the `ExplanationEngine` DAG; fault-metric explainers filter by `kind=fault` so a figure and its explanation agree.

---

## 7. Type-specific intake (separate forms, same workflow)

The inspector selects the **type first** (like the existing `TestIntakeModal` tabs), then a type-specific form renders. Fields come from the selected catalog — NOT one form with hidden fields.

| Type | Form fields | Backed by |
|---|---|---|
| 🔴 Fault | Fault (from `fault_catalog`) · Severity · Root Cause · Symptoms · Repeat check | `fault_catalog`, `fault_causes`, `findings`, `recurrence_flagged` (all exist) |
| 🔵 Service | Service (from `service_catalog`) · Due reason · Mileage · Interval | `service_catalog`, `odometer`, `ServiceReminder` (exist) |
| 🟨 Inspection | Checklist · Measurements · Result | `inspection_types`, `inspection_records` (exist) |
| 🟩 Recall | Campaign (from `recall_campaigns`) · Manufacturer · Reference | `recall_campaigns` (new) |

Selecting Service does **not** demand fault-severity or root-cause (the current bug). Downstream workflow states are identical for all four types.

---

## 8. Edit-site list by phase (Phase 0 → Phase 4)

Each phase is independently shippable and reversible; everything behind the `SERVICE_FAULT_SPLIT` flag until Phase 3.

### Phase 0 — Catalogs + discriminator (DARK; behaviourally identical)
- **Migrations:** create `service_catalog`, `fault_catalog`, `inspection_types`, `recall_campaigns`; add `kind` + four `*_catalog_id` + `classification_source` + `needs_review` to `maintenance_tasks`; add `fault_catalog_id` to `fault_causes`.
- **Seeders:** `ServiceCatalogSeeder` (from `ServiceTypes` + routine category), `FaultCatalogSeeder` (from non-routine catalog categories), `InspectionTypeSeeder` (from existing inspection kinds). No recall seed (data-driven).
- **Models:** `ServiceCatalog`, `FaultCatalog`, `InspectionType`, `RecallCampaign`; add `KIND_*` constants + scopes + catalog relations + `saving` guard to `MaintenanceTask`.
- **Resolver + backfill:** `resolveLegacyKind()`; extend `BackfillMaintenanceTasks` with `--kind` + `--dry-run`.
- **Write path (dark):** `MaintenanceTaskService::syncFromFindings()` copies `kind`/`catalog_id` from findings; findings gain the keys. No reader consumes yet.
- **Exit check:** full app behaves byte-identically; backfill dry-run reviewed.

### Phase 1 — Backend read-path (behind flag)
- Convert every Fault-only consumer in §6 to `MaintenanceTask::faults()` / `$vehicle->faults()`.
- Convert Service-inclusive consumers; split `ExcelVehicleExpenseProvider` totals into `service_cost`/`repair_cost`.
- Add `Vehicle` typed relations.
- **Standalone fixes (ship immediately, flag-independent):** `MaintenanceWorkflowService::openServiceTicket()` `visit_context` (`:2203`); `NotificationScanner::highMaintenanceCost()` label/guard (`:575`).
- **Exit check:** with flag on, fault leaderboard/health/recurrence exclude services; cost/profit unchanged.

### Phase 2 — API contract
- Expose `kind` + `kind_meta` + `catalog` on the three resources; add `kind` to the activity-feed shape and `vehicleTimeline` mapping.
- Keep all legacy fields for backward compatibility.

### Phase 3 — UI separation (flag → on)
- Type-first intake with four forms (§7); board/dashboard/timeline/profile render by `kind` colour; wire in `ServiceHistory.js`; retire `FAULT_CATEGORIES` + `faultCategories.js`.
- Add `kind` facet to search/filters.

### Phase 4 — Recall & Inspection first-class + Asset-Layer bridge
- Recall intake + campaign register + vehicle-applicability matcher.
- Promote Inspection from workflow phase to full event type with checklist/measurement forms.
- `kind` node in `ExplanationEngine`; wire `service_catalog.component_slug` → `component_catalog` to begin the Asset-Layer link (`ASSET_LAYER_MODE`).

---

## 9. Impact, backward compatibility, and behaviour changes

### Data
- **Additive.** `kind`/catalogs are new; `maintenance_type`, `visit_context`, `category_key`, `severity`, `findings` all retained (demoted to descriptive).
- **Backfill** fills every existing task's `kind`; unresolved → `fault` + `needs_review` (worst case = today's behaviour, never worse).
- **Rollback** = `SERVICE_FAULT_SPLIT=off`; data intact.

### Workflow & permissions
- **No change** to lifecycle/states. Permissions stay action-based (`maintenance.*`); optionally add `maintenance.recall` for the recall intake in P4.

### Behaviour that WILL change (intended corrections)
- Top Faults / Fault Leaderboard counts **drop** (services removed).
- Health / Reliability / Risk **rise** for cars whose history was padded with services.
- Recurring-fault reviews, duplicate-spend/part investigations, chronic-fault flags **stop firing** on oil changes / battery / tyre services.
- "High repair cost" notification stops mislabeling service bundles.
- **Pre-migration dashboard screenshots will not match** — expected, communicate to stakeholders.

### Behaviour that will NOT change (reassurance)
- Maintenance Cost / Total Spend / Cost Intelligence / **Profitability** — identical numbers (services still counted).
- Vehicle History — nothing deleted; only re-tagged and re-coloured.
- The maintenance workflow, its permissions, and existing endpoints.

---

## 10. Open questions for review (decide before Phase 0)

1. **Exactly-one-catalog enforcement:** app-guard only, or also a MySQL 8 `CHECK` constraint? (Recommendation: app-guard now, `CHECK` as hardening later.)
2. **Catalog FK shape:** four typed nullable FKs (this doc, DB-enforceable) vs. one polymorphic `catalog_id` + `kind` as type (fewer columns, no DB FK). (Recommendation: four typed FKs — matches Asset-Layer house style.)
3. **`fault_causes` migration:** re-point to `fault_catalog_id` now (Phase 0) or keep `symptom_key` in parallel during transition? (Recommendation: parallel in P0, cut over in P1.)
4. **Inspection findings:** confirm an inspection that finds a fault creates a *separate* linked `kind=fault` task (via `derived_from_task_id`) rather than changing its own kind. (Recommendation: yes — preserves the audit that "the inspection found X".)
5. **Multi-type tickets in the UI:** how to render a single ticket containing both a service and a fault (tabs vs. grouped sections). (Recommendation: grouped sections by `kind` in the drawer.)

---

## 11. Implementation status (updated 2026-08-03)

The footer below said "PROPOSED — no code has been written" long after Phase 0 shipped. Current state:

| Phase | Status |
|---|---|
| **0 — Catalogs + discriminator** | ✅ Shipped. `service_catalog` (19), `fault_catalog` (64), `inspection_types` (5), `maintenance_tasks.kind` + 3 typed FKs + `classification_source` + `needs_review`, model guard, `creating` hook, `events:backfill-kind`, `events:purge-kind`. |
| **1 — Backend read path** | ✅ Shipped, gated. 8 call sites consult `EventKind::enforced()`; the sheet-grain readers (Top Faults source B, foresight, recurring reasons) are typed through `EventClassificationService` unconditionally. |
| **2 — API contract** | ✅ Shipped. `kind`, `kind_meta`, `catalog`, `needs_review`, `classification_source` on `MaintenanceTaskResource` (and therefore `MaintenanceWorkflowResource`); `fault_tags`/`service_tags`/`context_tags` on `WorkshopEventResource`; `task_kind` on the activity feed; `kind` on every `/finding-keywords/resolve` match. |
| **3 — UI separation** | ◐ Partial. Timeline reads `kind`; the vehicle dossier's fault donut is split; the findings picker marks planned-work categories. Type-first intake (§7) is NOT built — findings are typed from the catalog by name instead, which produces `classification_source = catalog` without a new form. |
| **4 — Recall / Inspection first-class** | Not started (Recall is out of scope per the 2026-07-26 amendment). |

**Flag rename.** `SERVICE_FAULT_SPLIT` in §0.6/§8/§9 is now `EVENT_KIND_MODE` (`off` | `shadow` | `enforced`), read via `App\Support\EventKind`. That class documents precisely what the flag governs — task-grain fault *analytics* only. Classification, writes, and label/concept typing are unconditional: a rollout flag must never decide whether a stored fact is correct.

**Amendments learned from the audit** (`docs/Service-Fault-Separation-Audit.md`):
- §0.1 said "`kind` is the ONLY classifier". True at EVENT grain. The sheet corpus (~27k rows, ~99% of history) has no tasks and therefore no `kind`, so it needs a LABEL-grain authority. That is `EventClassificationService::labelKind()/conceptKind()/isServiceCategory()/faultReasonIds()` — the same class, one owner, four grains.
- `maintenance_reasons.level` is a PRIORITY axis and must never be used as a type filter. The sheet's owners rate `Engine Oil leak` as "Maintenance / Routine" because it is cheap, not because it is planned.
- The service catalog outranks the ontology's category when typing a concept: the ontology files by SYSTEM (`Tyre Rotation` lives in `tyres`), which is a different axis from the type of work.

**Integrity in production.** The `chk_task_kind_catalog` CHECK is not installed on MySQL 8 (errno 3823 — a CHECK may not reference a column carrying an FK with a referential action). Run `php artisan events:kind-integrity` there; it verifies every invariant the constraint would and exits non-zero on violation.

---

*Status: Phases 0–2 SHIPPED, Phase 3 partial. See §11 above and `docs/Service-Fault-Separation-Audit.md` for the audit that produced this status.*
