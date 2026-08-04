# Fleet Intelligence — Engineering Blueprint

**Companion to:** `Fleet-Maintenance-Intelligence-Study.md` · `Fleet-Intelligence-Implementation-Roadmap.md` · `Fleet-Intelligence-UX-Specification.md`
**Date:** 2026-08-03 · **Status:** Architecture only. No code.
**Stack:** Laravel 11 API + React (CRA) SPA · MySQL (`laravel`) · Sanctum · Spatie Permission

---

## 0. Architectural Position

### 0.1 Conventions this blueprint obeys

Read from the codebase, not assumed. Every design below complies:

| Convention | Source | Compliance requirement |
|---|---|---|
| **Strict layer flow** `Route → FormRequest → Controller → Service → Resource → ResponseHelper` | `ARCHITECTURE-CONVENTIONS.md` | No business logic in controllers. No raw `response()->json()`. Every controller method wrapped in try/catch → `ResponseHelper::fromException($e)` |
| **Response envelope** `{ data, success, message }` | `app/Helpers/ResponseHelper.php` | All new endpoints use `SuccessResponse` / `FailureResponse` |
| **Route permissions** | `routes/api.php` | Every route carries `auth:sanctum` + `permission:*`. Static routes precede `/{param}` routes |
| **KPI value object** | `app/Kpi/Kpi.php` | **Already has the right shape** — `measured()`, `unavailable()`, `insufficient()`, `sampleSize`, `direction`, `context`. We extend it, we do not replace it |
| **Cache keys** | `CostIntelligenceService`, `MaintenanceOpsCenterService` | `Cache::remember('intelligence:<domain>:v<N>', TTL, fn)` — versioned so a formula change invalidates by bump |
| **Scheduled work** | `routes/console.php` | `Schedule::command(...)->dailyAt(...)->withoutOverlapping()` |
| **Frontend data** | `src/hooks/useFetch.js` | SWR-style hook with `loading`/`validating`/`error`/`reload`/`mutate`. **No new state library** |
| **Frontend auth** | `src/hooks/usePermissions.js`, `RequirePermission.js` | UI hides what the API would refuse; API remains source of truth |
| **i18n** | `tf()` / `tp()` | Every string; `npm run check:i18n` must pass |

### 0.2 Three architectural decisions

**D1 · Intelligence gets its own namespace, not a pile of services.**
`app/Intelligence/` with sub-namespaces per module. The existing `app/Services/` is already 116 files;
adding 20 more flat files makes it unnavigable. `app/Kpi/` is absorbed as `app/Intelligence/Kpi/`
only if the team wants the move — otherwise `app/Kpi/` stays and `app/Intelligence/` depends on it.
**Recommendation: leave `app/Kpi/` where it is.** It is already correct and moving it churns imports
for no gain.

**D2 · Two materialised tables carry the platform.**
`repair_visits` (F2) and `fault_recurrence_pairs` (F4). Every heavy metric reads from these, not from
the raw 26,942 × 49,487 self-joins. They are rebuilt nightly by console commands and are **derived,
disposable, and reproducible** — never a source of truth, always rebuildable from `maintenances` and
`maintenance_signatures`.

**D3 · The metric layer is the only place a KPI is defined.**
One service resolves every KPI code to a `Kpi` object. Controllers never compute. Two pages showing
different values for "average repair duration" is the failure mode that kills analytics platforms,
and a single resolver is the structural prevention.

### 0.3 Known environment hazards (flag before sprint 1)

| Hazard | Impact on this project | Mitigation |
|---|---|---|
| **Scheduler is dead locally** | The nightly rebuild commands will not run on dev machines | Every rebuild command must be idempotent and runnable by hand. Provide `intelligence:rebuild --all`. Verify the Windows scheduler task on the server before Phase 1 sign-off |
| **Migrations cannot run from empty** | A fresh `migrate` on an empty DB fails today | New migrations must be **strictly additive** (new tables, nullable columns). Rebuild `laravel_test` by **cloning** `laravel`, never by `migrate:fresh` |
| **MariaDB local vs MySQL 8 prod** | A CHECK constraint on a FK column passes locally, fails on the server | No CHECK constraints in new migrations. Enforce enums in PHP, not in DDL |
| **SoftDeletes on `maintenances`** | Raw SQL that forgets `deleted_at IS NULL` silently includes trash | Every raw query in `app/Intelligence/` declares LIVE or HISTORICAL in a docblock. Enforced by review |

---

# 1. Backend Architecture

## 1.1 Namespace layout

```
app/
├── Kpi/                                    ← EXISTING, unchanged
│   ├── Kpi.php                             ← extended (see §1.2)
│   └── OperationalKpiService.php           ← existing baseline KPIs
│
├── Intelligence/                           ← NEW
│   ├── MetricRegistry.php                  ← code → resolver map, the single definition point
│   ├── MetricResolver.php                  ← interface: resolve(MetricContext): Kpi
│   ├── MetricContext.php                   ← immutable filter bag (period, garage, model, fault…)
│   ├── Coverage.php                         ← value object: covered/total/pct/asOf
│   ├── Evidence/
│   │   ├── EvidenceRegistry.php            ← queryId → evidence query
│   │   ├── EvidenceQuery.php               ← interface: rows(), method(), claim()
│   │   └── Queries/                        ← one class per drillable claim
│   ├── Garage/
│   │   ├── GarageIntelligenceService.php   ← leaderboard, profile, comparison
│   │   ├── GarageQualityScore.php          ← composite + renormalisation
│   │   ├── RecurrenceMetrics.php           ← G1/G2/G3 off fault_recurrence_pairs
│   │   ├── FaultMatrix.php                 ← G16
│   │   └── ReworkCost.php                  ← G17
│   ├── Vehicle/
│   │   ├── VehicleIntelligenceService.php
│   │   ├── VehicleHealthScore.php          ← V1, six weighted components
│   │   ├── VehicleRiskScore.php            ← V10
│   │   ├── VehicleTimelineBuilder.php      ← V4, multi-lane union
│   │   └── ReplacementAdvisor.php          ← V12, rule-based
│   ├── Fault/
│   │   ├── FaultIntelligenceService.php
│   │   ├── FaultConcentration.php          ← F-16 Gini/Lorenz
│   │   ├── FaultTrends.php                 ← F-3/F-4, fleet-size normalised
│   │   └── FaultCooccurrence.php           ← F-9 lift
│   ├── Financial/
│   │   ├── FinancialIntelligenceService.php
│   │   ├── ExpenseAttributionBridge.php    ← CB, method-labelled
│   │   ├── LifetimeCostCurve.php           ← C13
│   │   └── WarrantyLeakage.php             ← C15
│   ├── Operations/
│   │   └── OperationsBoardService.php      ← O1–O15 tiles
│   ├── Executive/
│   │   └── ExecutiveSummaryService.php     ← assembles, never computes
│   ├── Insights/
│   │   ├── InsightEngine.php               ← runs generators, ranks, dedupes
│   │   ├── InsightGenerator.php            ← interface
│   │   ├── Insight.php                     ← value object
│   │   └── Generators/                     ← one class per insight code
│   └── Support/
│       ├── VisitCollapser.php              ← F2 grouping rule
│       ├── FleetSizeNormalizer.php         ← F7, per-100-vehicles
│       ├── GarageRegistry.php              ← F3 exclusions + aliases
│       ├── ModelNormalizer.php             ← F1 canonical make/model
│       └── SyntheticDataFilter.php         ← F9 global seed quarantine
│
├── Http/
│   ├── Controllers/Intelligence/
│   │   ├── ExecutiveController.php
│   │   ├── OperationsBoardController.php
│   │   ├── GarageIntelligenceController.php
│   │   ├── VehicleIntelligenceController.php
│   │   ├── FaultIntelligenceController.php
│   │   ├── FinancialIntelligenceController.php
│   │   ├── InsightController.php
│   │   └── EvidenceController.php
│   ├── Requests/Intelligence/
│   │   ├── MetricFilterRequest.php         ← the shared filter contract
│   │   ├── GarageCompareRequest.php
│   │   └── InsightFeedbackRequest.php
│   └── Resources/Intelligence/
│       ├── KpiResource.php                 ← Kpi → JSON, with coverage + confidence
│       ├── GarageScoreResource.php
│       ├── FaultMatrixResource.php
│       ├── VehicleProfileResource.php
│       ├── InsightResource.php
│       └── EvidenceResource.php
│
└── Console/Commands/
    ├── IntelligenceRebuildVisits.php        ← intelligence:rebuild-visits
    ├── IntelligenceRebuildRecurrence.php    ← intelligence:rebuild-recurrence
    ├── IntelligenceRefreshMetrics.php       ← intelligence:refresh-metrics (cache warm)
    ├── IntelligenceGenerateInsights.php     ← intelligence:generate-insights
    └── IntelligenceAttributeExpenses.php    ← intelligence:attribute-expenses
```

## 1.2 The metric layer

**`Kpi` gains four fields.** The existing object already carries `value`, `unit`, `sampleSize`,
`direction`, `available`, `blockedReason`, `context` — which is most of what the UX spec needs.
Add:

| New field | Type | Purpose |
|---|---|---|
| `coverage` | `?Coverage` | covered / total / pct — drives the coverage badge and the §7.3 donut |
| `asOf` | `?CarbonImmutable` | data freshness — drives the *as of 31 Mar 2026* watermark |
| `confidence` | `string` | `verified` \| `partial` \| `estimated` — drives the badge colour |
| `evidenceQueryId` | `?string` | opens the EvidenceDrawer |

Add one factory: `Kpi::estimated(...)` for derived figures like G17 rework cost, so "estimated" is a
type in the system rather than a word in a label.

**`MetricRegistry`** maps a metric code (`garage.recurrence_days`, `vehicle.health`,
`financial.spend_total`) to a resolver class. It is the answer to "where is this number defined?" —
exactly one place, always.

**`MetricContext`** is an immutable filter bag built by `MetricFilterRequest`: period from/to,
`vendor_id[]`, `vehicle_id[]`, canonical model, fault signature, origin, `min_sample` (default 30),
`include_non_garages` (default false). It is passed down unchanged and is the **cache key input** —
serialise it deterministically (sorted keys) so identical filters hit the same cache entry.

**Sample gate is enforced in the resolver base, not per metric.** If `sampleSize < context.minSample`,
return `Kpi::insufficient(...)`. No resolver may bypass it.

## 1.3 Per-module backend design

### A · Garage Intelligence (Phase 2 — flagship)

**Routes** (`routes/api.php`, new group — static before dynamic):
```
Route::middleware(['auth:sanctum','permission:intelligence.view'])
     ->prefix('intelligence/garages')
     ->controller(GarageIntelligenceController::class)->group(function () {
    Route::get('/',            'index');      // leaderboard
    Route::get('/matrix',      'matrix');     // G16  (static — precedes /{vendor})
    Route::get('/compare',     'compare');    // G15  (static — precedes /{vendor})
    Route::get('/rework-cost', 'reworkCost'); // G17  (static — precedes /{vendor})
    Route::get('/{vendor}',            'show');
    Route::get('/{vendor}/faults',     'faults');
    Route::get('/{vendor}/recurrences','recurrences'); // the evidence table
    Route::get('/{vendor}/timeline',   'timeline');
});
```

**Controller** — 8 thin methods. Each: build `MetricContext` from `MetricFilterRequest`, call the
service, wrap in a Resource, return via `ResponseHelper`. Try/catch on every method.

**Services**
- `GarageIntelligenceService` — orchestrator; composes the four below
- `RecurrenceMetrics` — G1/G2/G3. Reads **only** `fault_recurrence_pairs`. Never touches
  `maintenance_signatures` directly at request time
- `GarageQualityScore` — G8. Computes available terms, **renormalises weights over available terms
  only**, returns `Kpi` with `context.terms_used` and `context.terms_skipped`
- `FaultMatrix` — G16. One query producing garage × signature cells; blanks below `min_sample`
- `ReworkCost` — G17. Recurrences ≤30d × category average cost. Returns `Kpi::estimated(...)`

**Support** — `GarageRegistry` resolves the exclusion list (`OFFICE PARKING`, `Under Test`,
`Garage` — 3,916 tickets) and alias merges (`Qasr al zaiton` / `kasr al zaiton`). It is consulted by
every garage query; no service filters by hand.

**Caching** — `intelligence:garage:leaderboard:v1:{ctxHash}` TTL 1h ·
`intelligence:garage:matrix:v1:{ctxHash}` TTL 1h · `intelligence:garage:{id}:profile:v1:{ctxHash}`
TTL 30m. Recurrence tables underneath are already materialised, so these are cheap; the TTL protects
against filter-permutation stampedes, not slow SQL.

**Jobs** — none. Everything reads materialised tables. Warm the top-20 garage profiles in
`intelligence:refresh-metrics`.

---

### B · Operations Intelligence (Phase 3)

**Routes**
```
Route::middleware(['auth:sanctum','permission:maintenance.view'])
     ->prefix('intelligence/operations')
     ->controller(OperationsBoardController::class)->group(function () {
    Route::get('/board',        'board');        // all tiles, one payload
    Route::get('/tile/{code}',  'tile');         // one tile's worklist (drill-down)
    Route::get('/sla',          'sla');          // O8 promise vs actual + slack
    Route::get('/workload',     'workload');     // O10
    Route::get('/aging',        'aging');        // O9 histogram
});
```

**Service** — `OperationsBoardService` returns all tiles in **one response**. The board is a live
worklist; 10 parallel requests on page load is the wrong shape. Each tile carries
`{ code, label, count, tier: 'workflow'|'history', drilldown_url }` — the `tier` field drives the
UX spec's mandatory two-tier badge.

**Caching** — **none on counts.** This is a live operational board; a 5-minute-stale "140 pending
review" is worse than a 300ms query. Cache only `sla` and `aging` (1h) which are historical.

**Kill enforcement** — the service must **not** define tiles for O3/O4 (`approval_status` has zero
variance). Register them in `MetricRegistry` as `Kpi::unavailable('ops.waiting_approval', …,
'No approval process is recorded — approval_status is not_required on all 26,942 tickets')`. This
keeps the gap visible as a work item rather than silently absent, which is exactly what
`Kpi::unavailable` exists for.

---

### C · Vehicle Intelligence (Phase 4)

**Routes**
```
Route::middleware(['auth:sanctum','permission:intelligence.view'])
     ->prefix('intelligence/vehicles')
     ->controller(VehicleIntelligenceController::class)->group(function () {
    Route::get('/',                    'index');       // health-ranked list
    Route::get('/{vehicle}',           'show');        // profile header + scores
    Route::get('/{vehicle}/timeline',  'timeline');    // V4
    Route::get('/{vehicle}/faults',    'faults');      // V5 chains
    Route::get('/{vehicle}/cost',      'cost');        // V7/V8/V9 — financial permission
    Route::get('/{vehicle}/downtime',  'downtime');    // V6
});
```

**Services**
- `VehicleHealthScore` — six components, each returning `{ raw, percentile, penalty }`.
  **`norm()` is percentile rank within the fleet**, so the score is relative to our own distribution.
  Percentile boundaries are computed once per run and cached — computing 438 percentiles per request
  is the obvious performance trap here
- `VehicleRiskScore` — four weighted rule terms, weights declared as class constants and echoed in
  the response so the UI can publish them
- `VehicleTimelineBuilder` — UNION of four lanes: contracts (rentals), `repair_visits` (repairs),
  `vehicle_expenses` (costs), `vehicle_log_events` + `component_events` (events). **Must apply
  `SyntheticDataFilter`** — 4,544 of 6,647 `vehicle_log_events` are demo rows
- `ReplacementAdvisor` — three rules; returns `insufficient_economic_data` when `purchase_price` is
  null (201 of 438 vehicles), never a verdict on partial criteria

**Caching** — `intelligence:vehicle:health:v1` for the whole-fleet score set, TTL 6h, rebuilt by
`intelligence:refresh-metrics`. Individual profiles compute live off that cached set. Timeline is
live (it is an investigation tool and must reflect a ticket closed 30 seconds ago).

---

### D · Fault Intelligence (Phase 5)

**Routes**
```
Route::middleware(['auth:sanctum','permission:intelligence.view'])
     ->prefix('intelligence/faults')
     ->controller(FaultIntelligenceController::class)->group(function () {
    Route::get('/',                     'index');         // ranking + Pareto
    Route::get('/trends',               'trends');        // static — precedes /{signature}
    Route::get('/concentration',        'concentration'); // static
    Route::get('/cooccurrence',         'cooccurrence');  // static
    Route::get('/{signature}',          'show');
    Route::get('/{signature}/garages',  'garages');
    Route::get('/{signature}/vehicles', 'vehicles');
});
```

**Services**
- `FaultTrends` — monthly counts **divided by fleet size in that month**, via `FleetSizeNormalizer`.
  Fleet went 129 → 186 vehicles; raw counts read growth as deterioration. This normalisation is not
  optional and belongs in a shared class so no module forgets it
- `FaultConcentration` — Gini + Lorenz points per signature; emits a `spread` / `concentrated` verdict
  chip
- `FaultCooccurrence` — lift over 49,487 rows, restricted to same-visit or ≤14 days. Heaviest query
  in the platform: **precompute nightly** into cache (not a table — the result set is small)

**All fault queries filter `is_exposure = 0`.** Enforce in a single query scope
(`MaintenanceSignature::faults()`) so it cannot be forgotten. Getting this wrong roughly doubles every
count.

---

### E · Financial Intelligence (Phase 6)

**Routes** — additionally gated:
```
Route::middleware(['auth:sanctum','permission:intelligence.financial'])
     ->prefix('intelligence/financial')
     ->controller(FinancialIntelligenceController::class)->group(function () {
    Route::get('/overview',      'overview');
    Route::get('/by-vehicle',    'byVehicle');
    Route::get('/by-model',      'byModel');
    Route::get('/by-garage',     'byGarage');    // returns coverage BEFORE ranking
    Route::get('/lifetime-curve','lifetimeCurve');
    Route::get('/warranty-leak', 'warrantyLeakage');
    Route::get('/cost-per-km',   'costPerKm');
});
```

**`ExpenseAttributionBridge`** — the one genuinely complex service. Writes
`expense_attributions` with `attribution_method ∈ {exact, single_garage_window, ambiguous, unattributed}`.
Run by `intelligence:attribute-expenses`, not at request time (it is a correlated subquery over
28,327 × 26,942).

**Every financial response carries `as_of`.** `FinancialIntelligenceService` computes it once as
`MAX(entry_date)` on a rolling-completeness test, and every payload includes it. The UI watermark is
driven by data, not a hardcoded string — so when CP10 backfills the ledger, the watermark moves by
itself.

**`byGarage` returns coverage first**, matching the UX contract:
```
{ coverage: { traced: 705622, ambiguous: 1_5xx_xxx, unattributed: 879632, traced_pct: 23 },
  ranking: [ … ] }
```
Never spread ambiguous cost. The response shape makes that structurally impossible.

---

### F · Insights (Phase 7)

**`InsightEngine`** runs registered `InsightGenerator`s, each of which returns zero or more
`Insight` value objects: `{ code, severity, scope, entities, metrics, sample_size, baseline, window,
evidence_query_id, template_key, params }`.

**No prose is generated.** `template_key` + `params` go to the frontend, which renders through
`tp()`. This is the only design that survives Arabic translation — a concatenated English sentence
cannot be translated grammatically.

**Generation** — `intelligence:generate-insights` nightly, writing to an `insights` table with
dedupe on `(code, entity_key, window)` so the same finding does not re-fire daily. Severity escalates
if the condition persists (UX Scenario 3 depends on this).

**Storage** — an `insights` table is required, not optional: the feed needs history, dedupe,
escalation, and per-user dismissal.

## 1.4 Caching strategy summary

| Layer | Mechanism | TTL | Invalidation |
|---|---|---|---|
| Materialised tables | `repair_visits`, `fault_recurrence_pairs`, `expense_attributions` | nightly rebuild | full rebuild, transactional swap |
| Fleet-wide score sets | `Cache::remember('intelligence:*:v1')` | 6h | version bump on formula change |
| Filtered aggregates | `intelligence:*:v1:{ctxHash}` | 1h | TTL only |
| Live operational counts | **none** | — | — |
| Drill-down / evidence | **none** | — | — |
| Insights | DB table | — | nightly regeneration |

**Version bump is the invalidation strategy for formula changes.** When `GarageQualityScore` weights
change, bump `v1` → `v2` in the key. Cheaper and safer than targeted flushing, and it leaves an audit
trail in the key name.

---

# 2. Frontend Architecture

## 2.1 Directory layout

```
src/
├── api/
│   ├── client.js                     ← EXISTING axios + Sanctum
│   └── intelligence.js               ← NEW: one function per endpoint
├── hooks/
│   ├── useFetch.js                   ← EXISTING, reused as-is
│   ├── usePermissions.js             ← EXISTING
│   ├── useMetricFilters.js           ← NEW: filter state ⇄ URL params
│   └── useEvidence.js                ← NEW: opens EvidenceDrawer by queryId
├── components/
│   ├── ui/                           ← EXISTING primitives, unchanged
│   └── intelligence/                 ← NEW
│       ├── IntelligenceMetricCard.js
│       ├── ScoreCard.js
│       ├── RankingTable.js
│       ├── ComparisonDumbbell.js
│       ├── PerformanceMatrix.js
│       ├── EvidenceDrawer.js
│       ├── InsightCard.js
│       ├── VehicleTimeline.js
│       ├── CoverageDisclosure.js
│       ├── ConfidenceBadge.js
│       ├── AsOfWatermark.js
│       └── states/
│           ├── InsufficientSample.js
│           ├── BlockedByPrerequisite.js
│           └── EmptyByDesign.js
├── pages/
│   ├── ExecutiveHome.js
│   ├── OperationsHome.js
│   └── intelligence/
│       ├── IntelligenceHub.js
│       ├── GarageOverview.js
│       ├── GarageCompare.js
│       ├── GarageProfile.js
│       ├── VehicleIntelligenceList.js
│       ├── VehicleProfile.js
│       ├── FaultOverview.js
│       ├── FaultProfile.js
│       ├── FinancialOverview.js
│       └── InsightFeed.js
└── layouts/AppLayout.js              ← EXISTING, nav section added
```

## 2.2 State management

**No new library.** The existing `useFetch` already provides loading / background-validating / error /
`reload({silent})` / optimistic `mutate` — which is what a dashboard needs. Adding React Query here
would be a parallel data layer for no capability gain.

**Three state tiers:**

| Tier | Mechanism | Example |
|---|---|---|
| **Server data** | `useFetch(fetcher, deps)` | KPI payloads, tables, matrices |
| **Filter state** | `useMetricFilters()` → URL search params | period, garage, model, fault, min sample |
| **UI state** | local `useState` | drawer open, tab, sort, expanded rows |

**`useMetricFilters` is the load-bearing new hook.** It serialises the filter bag into the URL and
parses it back, so:
- every view is shareable (a functional requirement — Basem forwards links to Adham)
- the filter object is the `useFetch` dependency array, so changing a filter refetches automatically
- back/forward navigation works

**Polling.** Operations Home uses `refreshInterval: 60_000` with `paused: () => drawerOpen` — the
hook already supports exactly this. Intelligence pages do **not** poll; they are analytical, and a
number moving under the user mid-analysis is a defect.

## 2.3 API consumption

`src/api/intelligence.js` exports one function per endpoint, each unwrapping the `{ data, success,
message }` envelope and returning `data`. Call sites never see the envelope.

```
getGarageLeaderboard(filters)   getGarageMatrix(filters)     getGarageProfile(id, filters)
getGarageCompare(ids, filters)  getGarageRecurrences(id, f)  getOperationsBoard()
getVehicleHealth(filters)       getVehicleProfile(id)        getVehicleTimeline(id, f)
getFaultRanking(filters)        getFaultProfile(sig, f)      getFinancialOverview(f)
getInsights(scope)              getEvidence(queryId)         postInsightFeedback(id, payload)
```

**Every fetcher is wrapped in `useCallback` at the call site**, per the `useFetch` docblock — an
inline arrow re-triggers a fresh skeleton load on every render.

## 2.4 The four states, as components

The UX spec requires five states on every data component. Three are new shared components so they
are consistent and cannot be improvised per page:

| State | Component | Renders |
|---|---|---|
| Loading | `Skeleton` (existing) | Final dimensions — no layout shift |
| Empty by design | `EmptyByDesign` | Icon + what would populate it + the enabling action. **Used on Vehicle → Components tab** |
| Insufficient sample | `InsufficientSample` | `Not enough data yet — 12 of 30 repairs measured` |
| Blocked by prerequisite | `BlockedByPrerequisite` | Amber panel naming the blocker + fix link. **Used on every "by model" surface until F1 ships** |
| Error | `ErrorBoundary` (existing) + inline | Message + retry + metric code |

**`IntelligenceMetricCard` selects its own state from the `Kpi` payload.** Because the backend returns
`available`, `blockedReason`, `sampleSize`, and `coverage`, the card decides — the page never
branches. This is what makes the "no number without its confidence" rule enforceable rather than
aspirational.

## 2.5 Permission gating

Routes wrapped in `<RequirePermission permission="intelligence.view">` (existing component).
Nav items filtered by `usePermissions().can()` — `AppLayout` already drops empty sections, so Basem's
three-item sidebar emerges from permissions with no special-casing.

**Financial panels are double-gated:** `intelligence.financial` **and** the `SHOW_FINANCIALS` flag.
Adham has neither.

## 2.6 Charting

Reuse `LineChart`, `MultiLineChart`, `BarChart`, `GroupedBarChart`, `RankedBar`, `Sparkline`,
`Gauge`, `CompositionDonut`, `LeaderDonut`, and `chartUtils.js`.

**Two genuinely new visuals:** `ComparisonDumbbell` and `PerformanceMatrix`. Both are SVG built on
existing `chartUtils` scales — no new charting dependency. Both must mirror under RTL and re-derive
colours from `--chart-line` / `--ink` for dark mode.

---

# 3. Data Layer

## 3.1 Live vs precomputed — the decision rule

**Precompute** when the query self-joins a large table, or is read by more than three surfaces.
**Compute live** when the user is drilling into specifics, or when staleness would mislead.

| Computation | Strategy | Why |
|---|---|---|
| Recurrence pairs | **Materialised table**, nightly | Self-join over 49,487 rows; read by 8 KPIs |
| Repair visits | **Materialised table**, nightly | Collapses 26,942 events → visits; read by everything |
| Expense attribution | **Materialised table**, nightly | Correlated subquery, 28,327 × 26,942 |
| Vehicle health (fleet) | **Cache**, 6h | Needs fleet-wide percentiles; 438 rows |
| Fault co-occurrence | **Cache**, nightly warm | Heaviest aggregate; small result |
| Garage leaderboard/matrix | **Cache**, 1h per filter | Cheap off materialised tables; TTL guards permutations |
| Operations tile counts | **Live** | Operational truth; staleness is a defect |
| All drill-downs | **Live** | Small, filtered, must match the parent number |
| Evidence rows | **Live** | The audit trail must be current |

## 3.2 Per-KPI data specification

Notation: `M` = `maintenances` (LIVE: `deleted_at IS NULL`) · `S` = `maintenance_signatures`
(`is_exposure = 0`) · `V` = `vehicles` · `VE` = `vehicle_expenses` · `C` = `contracts` ·
`RV` = `repair_visits` · `FRP` = `fault_recurrence_pairs`.

### Foundation tables

**`repair_visits`** (F2) — source `M`. Group by `(vehicle_id, vendor_id)`, chain rows sharing the same `out_date` (same-day only —
the gap distribution is flat after day 0, so any wider window is unevidenced). Columns: `visit_id`, `vehicle_id`, `vendor_id`,
`started_at`, `ended_at`, `event_row_count`, `origin_mix`, `has_close_date`.
**Perf:** one full scan, ~27k rows in, ~13k out. Index `(vehicle_id, started_at)`,
`(vendor_id, started_at)`.
**Caveat:** the grouping window is a **Derived judgement** — stored in the row
(`grouping_window_days = 0`) so a later change is auditable. See Execution Plan §0.2.

**`fault_recurrence_pairs`** (F4) — source `S` ⨝ `M`. For each fault occurrence, find the next
occurrence of the same `(vehicle_id, signature)`. Columns: `vehicle_id`, `signature`,
`first_ticket_id`, `first_vendor_id`, `first_date`, `next_ticket_id`, `next_vendor_id`, `next_date`,
`days_to_return`, `returned_30/60/90`, `label_source`.
**Perf:** window function (`LEAD`) partitioned by `(vehicle_id, signature)` ordered by `occurred_at` —
**not** a correlated subquery. One pass over 33,874 fault rows. Index
`(first_vendor_id, signature)`, `(vehicle_id, signature)`.
**Caveat:** stores raw pairs only. Exposure adjustment and category matching happen in the metric
layer — store facts, judge later.

### Garage

| KPI | Tables | Join / logic | Perf |
|---|---|---|---|
| G1 repeat % | `FRP` | `SUM(returned_30) / COUNT(*)` group by `first_vendor_id`, `signature` | index-covered |
| G2 same-fault return days | `FRP` | `AVG(days_to_return)`, `n ≥ 30` | index-covered |
| G3 any-fault return days | `RV` | `LEAD(started_at)` per vehicle, attributed to prior `vendor_id` | one pass |
| G4 repair time | `RV` | `MEDIAN(ended_at − started_at)`, exclude negatives | 13k rows |
| G8 quality | above | weighted composite, **renormalised over available terms** | in PHP |
| G9/G16 by fault | `FRP` ⨝ `vendors` | group by `(vendor, signature)`, blank below `min_sample` | one query, pivot in PHP |
| G17 rework cost | `FRP` + `VE` category means | `COUNT(returned_30) × avg_category_cost` | two small queries |
| G13 return rate | `RV` | repeat visits to same garage ÷ total | index-covered |

### Vehicle

| KPI | Tables | Logic | Perf |
|---|---|---|---|
| V1 health | `RV`, `S`, `FRP`, `C`, `V` | six components, `norm()` = fleet percentile | **compute all 438 at once**, cache 6h |
| V2/V3 frequency | `RV` ⨝ `C` | visits ÷ (rental days ÷ 1000) | grouped scan |
| V4 timeline | `C`, `RV`, `VE`, `vehicle_log_events`, `component_events` | UNION ALL, ordered | **must apply `SyntheticDataFilter`** |
| V5 recurring faults | `FRP` | chains per `(vehicle, signature)` | index-covered |
| V6 downtime | `RV` | `Σ(ended − started)`; pair with closure rate | flag 25.7% coverage |
| V8 cost/km | `VE` + `C` | spend ÷ `Σ(in_milage − out_milage)` | **mileage from `C`, never from `M`** |
| V10 risk | `FRP`, `RV`, `service_reminders` | four weighted rules | small |
| V12 replacement | `VE`, `V`, `RV` | 3 rules; `null` price → `insufficient_economic_data` | small |

**The single most important implementation note in this section:** V8 mileage comes from
`contracts.out_milage` / `in_milage` (99.9% / 96.2% populated). The odometer columns on `maintenances`
look authoritative and are effectively empty (`intake_odometer` 4 rows, `report_odometer` 26,
`receive_odometer` 57). An engineer reaching for the obvious column will ship a broken metric.

### Fault

| KPI | Tables | Logic | Perf |
|---|---|---|---|
| F-1 ranking | `S` | count + `COUNT(DISTINCT vehicle_id)`, `is_exposure=0` | index on `signature` |
| F-3/F-4 trends | `S` + fleet size per month | count ÷ fleet size × 100 | monthly rollup cached |
| F-5/F-6 recurrence | `FRP` | rate + mean days by signature | index-covered |
| F-16 concentration | `S` | Gini over per-vehicle occurrence counts | in PHP over ~200 rows/signature |
| F-9 co-occurrence | `S` self-join | lift, same visit or ≤14 days | **heaviest — nightly cache warm** |
| F-14/F-15 lifetime/aging | `FRP` | first/last per chain; days since last | index-covered |

### Financial

| KPI | Tables | Logic | Perf |
|---|---|---|---|
| C1 by vehicle | `VE` | `Σ amount` where category ∈ 9 repair cats | index `(vehicle_id, entry_date)` |
| C6 trend | `VE` | monthly rollup | index `(entry_date)` |
| C9 cost/km | `VE` + `C` | spend ÷ contract distance | two grouped queries |
| C13 lifetime curve | `VE` + `V.purchase_date` | bucket by age-at-spend, ÷ vehicle-months | one pass, 12 years |
| C14 concentration | `VE` | Lorenz over per-vehicle spend | small |
| C15 warranty leak | `VE` + `V.warranty_end_date/km` | spend inside warranty, warrantable categories | index-covered |
| C3 by garage | `expense_attributions` | **traced subset only** + coverage bands | materialised |
| E8 downtime cost | `RV` + `V.day_rent_value` | `Σ days × day_rate` (437/438 populated) | small |

---

# 4. Database Changes

**Constraint:** additive only. No CHECK constraints (MariaDB/MySQL 8 divergence). Test DB is rebuilt
by **cloning** `laravel`, never `migrate:fresh`.

## 4.1 Required now

| # | Change | Type | Phase | Rationale |
|---|---|---|---|---|
| **1** | `vehicle_model_map` — new table (`raw_make`, `raw_model`, `canonical_make`, `canonical_model`, `segment`, `body_type`, `reviewed_by`, `reviewed_at`) | new table | 1 | F1. Unblocks E2, E3, C2, F7 |
| **2** | `vehicles.vehicle_class` — populate existing empty column (0/438) | data | 1 | segmentation |
| **3** | `repair_visits` — new materialised table | new table | 1 | F2. Every per-repair metric |
| **4** | `fault_recurrence_pairs` — new materialised table | new table | 1 | F4. The flagship |
| **5** | `vendors.is_analytical` (bool, default true) + `vendors.alias_of_vendor_id` (nullable FK) | 2 columns | 1 | F3. Pseudo-garage exclusion + dedupe |
| **6** | `insights` — new table (`code`, `severity`, `scope`, `entity_type`, `entity_id`, `entity_key`, `metrics` json, `sample_size`, `window_start/end`, `evidence_query_id`, `template_key`, `params` json, `first_seen_at`, `last_seen_at`, `escalated_at`, `resolved_at`) | new table | 7 | history, dedupe, escalation |
| **7** | `insight_dismissals` — (`insight_code`, `user_id`, `reason`, `dismissed_at`) | new table | 7 | per-user feedback loop |
| **8** | `expense_attributions` — (`vehicle_expense_id`, `maintenance_id?`, `vendor_id?`, `attribution_method`, `confidence`, `computed_at`) | new table | 6 | CB. Method-labelled, never spread |
| **9** | `vehicle_expenses.vendor_id` (nullable FK) | 1 column | 6 | **CP2 — the real fix.** Capture going forward; bridge only for history |

## 4.2 Recommended later

| # | Change | Phase | Rationale |
|---|---|---|---|
| 10 | `vendors.warranty_days` (nullable smallint) | 2 | Unblocks G7 warranty return rate — currently NOT VIABLE for one missing column |
| 11 | `vehicles.residual_value` or use existing `DepreciationService` | 6 | C12 repair-vs-replace NPV |
| 12 | Indexes: `maintenances(vendor_id, out_date)`, `maintenance_signatures(signature, occurred_at)`, `vehicle_expenses(vehicle_id, entry_date, category)` | 1 | Verify with `EXPLAIN` first; add only what the rebuild jobs actually need |
| 13 | `maintenance_tasks.repair_hours` — make required at close (CP6) | 3 | Currently 0/117. Labour productivity |
| 14 | `metric_snapshots` — periodic KPI freezes for trend-of-trend | 7 | `kpi_snapshots` exists with 1 row; generalise if trend-over-trend is wanted |

## 4.3 Not needed

| Proposed | Verdict |
|---|---|
| New `garages` table | `vendors` with `type='garage'` is correct. Do not fork |
| New `faults` table | `maintenance_signatures` + `fault_catalog` cover it |
| Star schema / OLAP warehouse | 26,942 tickets and 438 vehicles. Materialised tables plus indexes are ample; a warehouse is unjustified complexity at this scale |
| Time-series database | Monthly granularity over 3 years. MySQL is fine |
| Columns on `maintenances` for cost | The 1.3% fill proves the capture never happens. Fix `vehicle_expenses.vendor_id` instead |
| Denormalised `vehicles.health_score` | Recomputed from a formula that will change. Cache it, do not persist it |
| Any change to `part_purchases` / `vehicle_components` | Schema is already correct. The gap is capture, not modelling |

---

# 5. Implementation Order

Seven phases. Each lists files, features, dependencies, and tests. Estimates assume one full-stack
engineer; Phases 1–2 parallelise across two.

---

## Phase 1 — Foundation · ~2 weeks · blocks everything

**Backend files**
```
app/Intelligence/MetricRegistry.php · MetricResolver.php · MetricContext.php · Coverage.php
app/Intelligence/Support/VisitCollapser.php · GarageRegistry.php · ModelNormalizer.php
                         FleetSizeNormalizer.php · SyntheticDataFilter.php
app/Intelligence/Evidence/EvidenceRegistry.php · EvidenceQuery.php
app/Kpi/Kpi.php                                    (extend: coverage, asOf, confidence, evidenceQueryId, estimated())
app/Http/Controllers/Intelligence/EvidenceController.php
app/Http/Requests/Intelligence/MetricFilterRequest.php
app/Http/Resources/Intelligence/KpiResource.php · EvidenceResource.php
app/Console/Commands/IntelligenceRebuildVisits.php · IntelligenceRebuildRecurrence.php
                     IntelligenceRefreshMetrics.php
app/Models/RepairVisit.php · FaultRecurrencePair.php · VehicleModelMap.php
database/migrations/  (changes 1,3,4,5 from §4.1)
routes/api.php        (intelligence group + evidence route)
routes/console.php    (2 nightly schedules)
```

**Frontend files**
```
src/api/intelligence.js
src/hooks/useMetricFilters.js · useEvidence.js
src/components/intelligence/ConfidenceBadge.js · AsOfWatermark.js · CoverageDisclosure.js
                             IntelligenceMetricCard.js · EvidenceDrawer.js
                             states/InsufficientSample.js · BlockedByPrerequisite.js · EmptyByDesign.js
src/pages/intelligence/IntelligenceHub.js
src/layouts/AppLayout.js   (nav section)
```

**Features delivered** — Data Health elevated with live coverage figures · synthetic-data quarantine
active · evidence drawer working end-to-end · both materialised tables built and scheduled · the
vehicle model map with a review UI · T3 data-integrity insights visible.

**Dependencies** — none. **Everything else depends on this.**

**Testing**
- Unit: `VisitCollapser` grouping (single-row, 2-row, 12-row tails, gap boundary at exactly 3 days)
- Unit: `Kpi` state transitions — measured / insufficient / unavailable / estimated
- Data validation: `repair_visits` rebuild is **idempotent** (run twice → identical rows)
- Data validation: `fault_recurrence_pairs` count matches the verified baseline for a known garage
- Feature: `/api/intelligence/evidence/{queryId}` returns rows + method + claim
- Feature: routes 403 without `intelligence.view`

**Exit criterion** — the golden-number test suite (§6.3) passes against the live DB clone.

---

## Phase 2 — Garage Intelligence · ~3 weeks · **the proof-of-value milestone**

**Backend**
```
app/Intelligence/Garage/GarageIntelligenceService.php · RecurrenceMetrics.php
                        GarageQualityScore.php · FaultMatrix.php · ReworkCost.php
app/Intelligence/Evidence/Queries/RecurrencePairsQuery.php · GarageTicketsQuery.php
app/Http/Controllers/Intelligence/GarageIntelligenceController.php
app/Http/Requests/Intelligence/GarageCompareRequest.php
app/Http/Resources/Intelligence/GarageScoreResource.php · FaultMatrixResource.php
routes/api.php  (8 garage routes)
```

**Frontend**
```
src/components/intelligence/ComparisonDumbbell.js · PerformanceMatrix.js · RankingTable.js · ScoreCard.js
src/pages/intelligence/GarageOverview.js · GarageCompare.js · GarageProfile.js
```

**Features** — leaderboard · **G16 matrix** · comparison (2–4) · profile with 5 tabs · **the paired
recurrence evidence table** · rework cost.

**Dependencies** — Phase 1 (F3 registry, F4 pairs, metric layer, evidence drawer).

**Testing**
- Unit: `GarageQualityScore` renormalisation when 2 of 5 terms are unavailable
- Unit: exclusion list removes `OFFICE PARKING` / `Under Test` / `Garage` (3,916 tickets)
- Unit: alias merge combines `Qasr al zaiton` + `kasr al zaiton`
- Data validation: **Deals On Wheels auto = 55.0 days ±0.1 on n=473** (golden number, deduped)
- Data validation: no garage with n<30 returns a score
- Feature: matrix blanks below `min_sample`
- UI: dumbbell mirrors under RTL; matrix keyboard-navigable

**Exit criterion** — demo to Basem. This is the milestone that funds the rest.

---

## Phase 3 — Operations Intelligence · ~2 weeks

**Backend** — `OperationsBoardService.php`, `OperationsBoardController.php`, routes.
**Frontend** — `pages/OperationsHome.js`, tile components reusing `IntelligenceMetricCard`.

**Features** — the 13 live tiles · **O15 closure rate** · O8 promise-vs-actual with slack ·
workload · aging · every tile drills to a worklist.

**Dependencies** — Phase 1. (Garage links enrich it but do not block.)

**Testing**
- Unit: tile tier labelling (`workflow` vs `history`) is correct per tile
- Unit: O4/O3 register as `Kpi::unavailable` with the reason string, and render nothing
- Data validation: **pending review = 140**, **SLA = 94.6% on n=5,169** (golden numbers)
- Data validation: `expected_return_date < out_date` (39 rows) excluded from SLA
- Feature: board returns in one request; drill-down count matches tile count exactly
- UI: polling pauses while a drawer is open

---

## Phase 4 — Vehicle Intelligence · ~2 weeks

**Backend** — `VehicleHealthScore`, `VehicleRiskScore`, `VehicleTimelineBuilder`,
`ReplacementAdvisor`, controller, resources.
**Frontend** — `VehicleIntelligenceList.js`, `VehicleProfile.js`, `VehicleTimeline.js`.

**Features** — health-ranked list · profile with 6 tabs · score with visible component breakdown ·
multi-lane timeline with recurrence markers · replacement advice · **Components tab as a deliberate
empty state**.

**Dependencies** — Phases 1, 2 (garage links in the timeline).

**Testing**
- Unit: health percentile normalisation; score bounded 0–100
- Unit: `ReplacementAdvisor` returns `insufficient_economic_data` when `purchase_price` is null
- Data validation: **vehicle 26831 = 24.2 days average across 90 visits** (golden number)
- Data validation: timeline excludes all `write_mode='seed'` rows
- Feature: `/cost` returns 403 without `intelligence.financial`
- UI: Components tab renders `EmptyByDesign`, never seeded rows

---

## Phase 5 — Fault Intelligence · ~2 weeks

**Backend** — `FaultTrends`, `FaultConcentration`, `FaultCooccurrence`, controller, resources.
**Frontend** — `FaultOverview.js`, `FaultProfile.js`.

**Features** — Pareto ranking · concentration verdicts · fleet-size-normalised trends ·
fault × garage · co-occurrence with ontology-feedback flagging.

**Dependencies** — Phases 1, 2.

**Testing**
- Unit: `is_exposure=0` filter applied (a regression here doubles every count — assert explicitly)
- Unit: trend normalisation divides by that month's fleet size
- Unit: Gini on known distributions (uniform → 0, fully concentrated → ~1)
- Data validation: **ELECTRICAL = 4,124 across 206 vehicles** (golden number)
- Data validation: total fault signatures = 20, exposure rows excluded

---

## Phase 6 — Financial Intelligence · ~3 weeks

**Backend** — `ExpenseAttributionBridge`, `LifetimeCostCurve`, `WarrantyLeakage`,
`FinancialIntelligenceService`, controller, `IntelligenceAttributeExpenses` command, migrations 8–9.
**Frontend** — `FinancialOverview.js` + lifecycle and warranty sub-pages.

**Features** — spend trend with **data-driven** as-of watermark · cost per vehicle/model/km/rental-day ·
**C13 lifetime curve** · **C15 warranty leakage** · concentration · **cost-by-garage with coverage
disclosed first**.

**Dependencies** — Phases 1, 4 · F10 orphan recovery · CP10 ledger backfill (external).

**Testing**
- Unit: attribution methods classify correctly (exact / single-garage / ambiguous / unattributed)
- Unit: **ambiguous cost is never distributed** — assert the sum of traced < total
- Data validation: **traced = 1,259 expenses / AED 705,622** (golden numbers)
- Data validation: `as_of` computes to 2026-03-31 on current data
- Feature: all financial routes 403 for Adham's role
- UI: watermark renders; post-watermark ranges hatched on charts

---

## Phase 7 — Executive Experience & Insights · ~2 weeks

**Backend** — `ExecutiveSummaryService` (assembles only), `InsightEngine`, generators, `Insight`,
`IntelligenceGenerateInsights` command, migrations 6–7, controllers, resources.
**Frontend** — `ExecutiveHome.js`, `InsightFeed.js`, `InsightCard.js`.

**Features** — the 30-second executive view · six insight surfaces · dedupe + severity escalation ·
feedback loop · PDF export.

**Dependencies** — all prior phases. Computes nothing new.

**Testing**
- Unit: insight dedupe on `(code, entity_key, window)`
- Unit: severity escalates when a condition persists across runs
- Unit: no generator emits an insight below n=30
- Unit: **no generator emits a probability or confidence percentage** (assert against the template
  catalogue — this is the guardrail for the project's central design promise)
- Feature: executive routes 403 without `intelligence.executive`
- Acceptance: UX Scenarios 1–3 pass end-to-end

---

## Timeline

| Phase | Weeks | Cumulative | Milestone |
|---|---:|---:|---|
| 1 Foundation | 2 | 2 | Data trustworthy, evidence drawer live |
| 2 Garage ★ | 3 | 5 | **Demo to Basem** |
| 3 Operations | 2 | 7 | Adham off WhatsApp |
| 4 Vehicle | 2 | 9 | Per-car decisions |
| 5 Fault | 2 | 11 | Fleet patterns |
| 6 Financial | 3 | 14 | Money answers |
| 7 Executive | 2 | **16** | Full platform |

Capture programmes (CP1–CP10) run continuously from week 1.

---

# 6. Testing Strategy

## 6.1 Layers

| Layer | Location | Runner | Scope |
|---|---|---|---|
| **Unit** | `tests/Unit/Intelligence/` | `phpunit.xml` | Pure logic: scoring, normalisation, collapsing, Gini, renormalisation. No DB |
| **Feature** | `tests/Feature/Intelligence/` | `phpunit.xml` | HTTP: routes, permissions, envelope shape, resource fields |
| **Data validation** | `tests/Crud/Intelligence/` | `phpunit.crud.xml` on `laravel_test` | **Golden numbers** against a real DB clone |
| **UI** | `src/**/*.test.js` | Jest + RTL | Component states, RTL mirroring, permission hiding |
| **Acceptance** | manual, scripted | — | The three UX scenarios |

## 6.2 Unit tests — the load-bearing set

- `VisitCollapser` — 1-row, 2-row, 12-row groups; gap exactly at the 3-day boundary; rows with null
  `actual_in_date`
- `GarageQualityScore` — renormalisation with 5, 3, and 1 available terms; all-unavailable →
  `Kpi::unavailable`
- `VehicleHealthScore` — bounds, percentile normalisation, missing-component handling
- `FaultConcentration` — Gini on uniform, on fully concentrated, on a single observation
- `FleetSizeNormalizer` — trend counts divided by the correct month's fleet size
- `ExpenseAttributionBridge` — the four method classifications
- `MetricContext` — deterministic hashing (same filters in any key order → same hash)
- `Kpi` — every factory produces the right `available` / `sampleSize` / `confidence` combination

## 6.3 Data validation — the golden-number suite

**This is the most important test file in the project.** It runs against a clone of the live database
and asserts the figures this design was built on. If any drift without an explanation, a formula
regressed or the data moved — and either way we need to know before a user does.

| Assertion | Expected | Guards |
|---|---|---|
| Live maintenance count | 26,942 | scope / SoftDelete regressions |
| `vendor_id` coverage | 93.5% | the flagship's foundation |
| Signature coverage | 21,125 tickets (78.4%) | fault layer |
| Fault signatures, `is_exposure=0` | exactly 20 | vocabulary drift |
| ELECTRICAL occurrences | 4,124 / 206 vehicles | F-1 |
| Deals On Wheels return interval | 55.0 days ±0.1, n=473 | **G2 — the flagship number** (deduped) |
| GPT GARRAGE return interval | 131.0 days ±0.1, n=719 | G2 (deduped) |
| SLA compliance | 4,888 / 5,169 = 94.6% | O8 |
| Pending review | 140 | O6 |
| Closure rate | 25.7% | O15 |
| Repair-category spend | AED 6.20M | C1/C6 |
| Traced garage spend | 1,259 expenses / AED 705,622 | CB |
| `day_rent_value` coverage | 437/438 | E8 |
| Synthetic rows excluded | 3,475 parts + 3,475 components + 4,544 log events | F9 |
| Single-row visit groups | 23% | F2 |
| `approval_status` distinct values | exactly 1 | confirms O4 stays dead |

**Tolerance policy:** exact for counts, ±0.1 for day averages, ±1% for currency. A failure is a
release blocker until explained — "the data changed" is an acceptable explanation, recorded in the
test, not a silent baseline update.

## 6.4 Feature tests

- Every route: 401 unauthenticated, 403 without permission, 200 with
- Adham's role receives 403 on all `/intelligence/financial/*`
- Response envelope is `{ data, success, message }` on success and failure
- `KpiResource` always includes `sample_size`, `confidence`, `coverage` when applicable
- Static routes resolve before `/{param}` (`/garages/matrix` must not hit `show`)
- Evidence endpoint returns claim + method + rows
- Operations board returns all tiles in one response
- Drill-down count equals its parent tile count

## 6.5 UI tests

- `IntelligenceMetricCard` renders all five states from `Kpi` payload shapes
- `ScoreCard` **refuses to render without a breakdown** (assert it throws or renders the empty state)
- `InsightCard` refuses to render without `sample_size`
- `PerformanceMatrix` renders blank cells (not pale ones) below `min_sample`
- `useMetricFilters` round-trips filters through URL params
- RTL: dumbbell and matrix mirror; numerals stay LTR
- Dark mode: charts re-derive colours from tokens
- Permission hiding: nav renders 3 items for a manager-only user

## 6.6 Acceptance scenarios

**A1 · Basem's Monday** — log in as `manager`; sidebar shows 3 items; executive page renders in <2s;
four health cards visible without scrolling; every card shows a confidence badge; clicking the
Alresala insight reaches the 209 paired tickets in ≤2 clicks; PDF export retains the badges.

**A2 · Adham's brake investigation** — log in as `maintenance`; reach BRAKES concentration verdict
from Operations Home in ≤4 clicks; by-garage dumbbell renders; evidence drawer lists paired tickets;
`[Send to Recurring Fault Review]` creates the record; **no financial figure appears anywhere**.

**A3 · Garage decline escalation** — seed a declining trend in `laravel_test`; run
`intelligence:generate-insights`; assert it appears at operational scope; re-run with the condition
persisting; assert severity escalates and it surfaces on the executive scope; assert no duplicate row
is created.

## 6.7 Non-functional gates

- Every dashboard endpoint p95 < 500ms with a warm cache; < 2s cold
- `intelligence:rebuild-recurrence` completes in < 60s on production data volume
- Both rebuild commands are idempotent and safe to run twice
- `npm run check:i18n` passes — no untranslated strings
- No new query in `app/Intelligence/` omits the LIVE/HISTORICAL docblock (review checklist item)

---

# 7. Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| **Scheduler dead on the server** → materialised tables go stale silently | Medium | **High** — every number silently ages | Verify the scheduler task before Phase 1 sign-off. Surface `last_rebuilt_at` on Data Health; alert if > 36h |
| **F1 model normalisation slips** | Medium | High — blocks E2/E3/C2/F7 | It is 438 rows of human review, not engineering. Assign it in week 1 and treat it as a deliverable, not a chore |
| **Visit-collapse window (3 days) proves wrong** | Medium | Medium — every per-repair metric shifts | Store the window on each row; make it configurable; validate against `event_status` transitions before locking it |
| **Golden numbers drift as data grows** | High | Low — expected | Tolerance policy + explicit re-baselining with a recorded reason |
| **Someone ships a Parts dashboard on seeded data** | Low | **Severe** — credibility loss | `SyntheticDataFilter` at the query layer, plus a data-validation test asserting exclusion |
| **Two pages disagree on a metric** | Medium | **Severe** | `MetricRegistry` as the single definition point; review rejects any KPI computed in a controller |
| **Ledger backfill (CP10) never happens** | Medium | High — Phase 6 ships a historical exhibit | Escalate now, not in month 3. Phase 6 is sequenced late partly to give this time |
| **Cache-key permutation explosion** | Low | Medium | Deterministic `MetricContext` hashing; whitelist filter keys; short TTLs |

---

# 8. What a developer should do on day one

1. Read `ARCHITECTURE-CONVENTIONS.md`. The layer flow is not negotiable.
2. Read `app/Kpi/Kpi.php`. It already encodes this project's philosophy — a number travels with its
   sample size, and "not measurable" is a first-class state, never zero.
3. Create `app/Intelligence/` and the `MetricContext` / `MetricRegistry` / `Coverage` trio. Nothing
   else compiles conceptually without them.
4. Build `IntelligenceRebuildVisits` and `IntelligenceRebuildRecurrence`. Run them. Compare the
   output against the golden numbers in §6.3.
5. Only then write the first endpoint.

The order matters: the platform's credibility rests on the materialised tables being correct, and
they are verifiable against numbers already measured in the study. Getting a green golden-number
suite in week 1 is what makes everything after it defensible.
