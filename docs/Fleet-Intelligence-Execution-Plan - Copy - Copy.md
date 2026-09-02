# Fleet Intelligence — Implementation Execution Plan

**Companion to:** Study · Roadmap · UX Spec · Engineering Blueprint
**Date:** 2026-08-03 · **Status:** Execution plan. No code.
**Author role:** Lead Engineer, delivery accountable

Architecture follows the Blueprint exactly. No new patterns. Two **algorithm** corrections are
recorded in §0 — they change numbers, not architecture, and they must be settled before a line is
written.

---

# 0. Pre-Flight Corrections

I validated the two Foundation algorithms against the live database before committing them to a
sprint. Both were wrong in the Blueprint. Neither changes the design; both change the numbers.

## 0.1 CORRECTION A — Recurrence pairs must deduplicate before the window function

**Blueprint said:** `LEAD()` partitioned by `(vehicle_id, signature)` over `maintenance_signatures`.

**Problem:** `maintenance_signatures` holds **multiple rows for the same fault on the same car on the
same day** — one ticket can carry a `derived` and a `human` label, multiple matched terms, and
several tickets can share a date. Verified distribution of rows per
`(vehicle_id, signature, occurred_at)`:

| Rows in group | Groups |
|---:|---:|
| 1 | 5,886 |
| 2 | 3,309 |
| 3 | 991 |
| 4 | 1,161 |
| 5–8 | 1,098 |

Running `LEAD()` over undeduplicated rows generates one "recurrence pair" per duplicate row, so a
single real recurrence is counted 2–8 times.

**Impact — this is why my earlier figures were inflated.** Alresala al zahabia carries **310
signature rows across only 92 distinct fault events** (3.4× duplication).

| Garage | Previously quoted | **Verified (deduped)** |
|---|---|---|
| Alresala al zahabia | 18.2 days, n=209 | **14.3 days, n=66** |
| Deals On Wheels auto | 72.0 days, n=2,430 | **41.8 days, n=904** |
| RMR | 117.6 days, n=1,693 | **100.5 days, n=735** |
| GPT GARRAGE | 131.6 days, n=2,483 | **102.4 days, n=968** |
| HOT LINE | 150.4 days, n=269 | **134.5 days, n=248** |

**The finding holds; the figures do not.** Alresala is still by far the worst and GPT/HOT LINE still
the best — the ranking and the decision are unchanged. But every sample size drops ~2.5–3×, and
Alresala's n falls to 66, only just above the n≥30 gate. The Study, Roadmap and UX Spec all quote the
inflated numbers and must be corrected (§0.3).

**Resolution:** the rebuild command deduplicates to one row per
`(vehicle_id, signature, occurred_at)` **before** the window function. Vendor attribution on a
deduplicated event uses the lowest `maintenance_id` for that date, and days where the car sat at more
than one garage are flagged (see §1.3).

## 0.2 CORRECTION B — The visit-collapse window is same-day, not 3 days

**Blueprint said:** chain rows while the gap ≤ 3 days, "tune against `event_status` transitions."

**Tuned.** Gap between consecutive tickets for the same `(vehicle_id, vendor_id)`:

| Gap (days) | 0 | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Pairs | **15,045** | 122 | 82 | 100 | 123 | 132 | 148 | 128 | 124 | 127 | 124 |

There is **no elbow**. Day 0 carries 15,045 pairs; every day after it is flat at ~120. That flat tail
is the fleet's ordinary revisit rate, not the tail of a single visit.

**A 3-day window would merge ~300 genuinely separate visits on no evidence.** Worse, it would be
indefensible — there is no shape in the data to justify choosing 3 over 5 or 10.

**Resolution:** collapse **same-day only** (`gap = 0`). The window stays configurable and is stored
on every row (`grouping_window_days = 0`) so a later change is auditable, but ships at 0.

## 0.3 Documents to correct before sprint 1

A 30-minute task, assigned to whoever opens the first PR:

| Document | Correction |
|---|---|
| `Fleet-Maintenance-Intelligence-Study.md` | §Finding 2 table, §6.3 example insights, §Phase 12 |
| `Fleet-Intelligence-Implementation-Roadmap.md` | §0.2 standing facts, G2 card verified output |
| `Fleet-Intelligence-UX-Specification.md` | §2.1 first-30-seconds, §2.2 Row 3 table, §4.4 insights, §10 Scenario 1 |
| `Fleet-Intelligence-Engineering-Blueprint.md` | §3.2 `repair_visits` (window 3 → 0), §6.3 golden numbers |

Each replaces the inflated figure with the deduped one and adds a one-line note that the earlier
figure counted duplicate label rows. **Do not silently overwrite** — the note is what stops someone
re-deriving the old number and thinking the code regressed.

## 0.4 One policy decision this forces: LIVE vs HISTORICAL

`OperationalKpiService` **deliberately includes soft-deleted tickets** — its docblock argues a
retired ticket is a repair that really happened, and dropping it would move a denominator without its
numerator. The new Intelligence layer must be consistent with the baseline it inherits.

| Surface | Policy | Reason |
|---|---|---|
| `fault_recurrence_pairs`, `repair_visits`, all quality/cost metrics | **HISTORICAL** (include trashed) | Consistency with `OperationalKpiService`; history must not rewrite itself |
| Operations board tiles, worklists, drill-downs | **LIVE** (`deleted_at IS NULL`) | A retired ticket must vanish from the board immediately |

Today `maintenances` has **0 soft-deleted rows and 0 tombstones**, so both policies return identical
numbers. Set the policy now anyway — the first deletion is the wrong time to discover it was never
decided. Every raw query in `app/Intelligence/` carries a `LIVE:` or `HISTORICAL:` docblock line;
absence is a review rejection.

---

# 1. Foundation Phase — Detailed Plan

## 1.1 MetricRegistry & the Kpi object

### Current structure (verified)

```
app/Kpi/
├── Kpi.php                    final class, immutable, private constructor
│   ├── constants:  HIGHER_BETTER · LOWER_BETTER · NEUTRAL
│   ├── properties: key, label, value, unit, sampleSize, direction,
│   │               available, blockedReason, context
│   ├── factories:  measured() · unavailable() · insufficient()
│   └── toArray():  9 keys
└── OperationalKpiService.php  MIN_SAMPLE=30, COMEBACK_WINDOW_DAYS=90, all(): Kpi[]
                               15 private KPI methods
```

**Existing consumers (4 — the full blast radius):**
`app/Console/Commands/KpiSnapshot.php` · `app/Services/Garage/GarageOutcomeForecaster.php` ·
`app/Services/Garage/GarageScorecardService.php` · `tests/Crud/MaintenanceDeletionLifecycleTest.php`

### Required changes to `Kpi.php` — additive only

**Four new constructor parameters, all with defaults**, so every existing `new self(...)` call inside
the three factories keeps working unchanged:

| Property | Type | Default | Purpose |
|---|---|---|---|
| `coverage` | `?Coverage` | `null` | covered / total / pct — drives the coverage badge |
| `asOf` | `?CarbonImmutable` | `null` | data freshness — drives the as-of watermark |
| `confidence` | `string` | `'verified'` | `verified` \| `partial` \| `estimated` |
| `evidenceQueryId` | `?string` | `null` | opens the EvidenceDrawer |

**One new factory:** `Kpi::estimated(...)` — identical to `measured()` but sets
`confidence = 'estimated'`. Used by G17 rework cost and any derived figure, so "estimated" is a type
in the system rather than a word in a label.

**`toArray()` gains four keys** — `coverage`, `as_of`, `confidence`, `evidence_query_id`.

### Backward compatibility — how existing KPIs migrate without breaking

**They don't migrate. Nothing changes for them.**

| Guarantee | Mechanism |
|---|---|
| Existing factory signatures unchanged | New params are appended with defaults |
| Existing 9 `toArray()` keys unchanged | Only additive keys |
| `KpiSnapshot` keeps working | It reads `key`/`value`/`sample_size`; new keys are ignored |
| `GarageScorecardService` / `GarageOutcomeForecaster` keep working | They construct via existing factories |
| Frontend pages reading old KPI payloads keep working | JSON is a superset |
| `MaintenanceDeletionLifecycleTest` keeps passing | Assertions target existing keys |

**Confidence handling rules — resolved centrally, never per metric:**

| Condition | `confidence` | UI badge |
|---|---|---|
| Coverage ≥ 90% and data current | `verified` | green outline |
| Coverage < 90%, or `asOf` older than 60 days | `partial` | amber, shows coverage % |
| Value derived from a proxy (fleet-average cost, category mapping) | `estimated` | grey |
| `sampleSize < context.minSample` | — | `Kpi::insufficient`, no value rendered |
| Required field has no variance or no data | — | `Kpi::unavailable` + reason |

A resolver never sets `confidence` by hand. `MetricResolver`'s base applies the table above from
`coverage` + `asOf`. This is what makes "no number without its confidence" structural rather than a
convention people remember.

### New Foundation classes

| File | Responsibility |
|---|---|
| `app/Intelligence/MetricContext.php` | Immutable filter bag. `fromRequest()`, `hash()` (deterministic: sorted keys → sha1). Fields: `from`, `to`, `vendorIds`, `vehicleIds`, `canonicalModel`, `signature`, `origin`, `minSample` (default 30), `includeNonGarages` (default false) |
| `app/Intelligence/Coverage.php` | `covered`, `total`, `pct()`, `asOf`. Value object |
| `app/Intelligence/MetricResolver.php` | Interface: `resolve(MetricContext): Kpi`. Abstract base applies the sample gate and confidence rules |
| `app/Intelligence/MetricRegistry.php` | `code → resolver` map. `resolve(string $code, MetricContext): Kpi`, `all(array $codes, MetricContext): Kpi[]`. **The single answer to "where is this number defined?"** |

`MetricRegistry` does **not** absorb `OperationalKpiService`. That service keeps its 15 baseline KPIs
and its snapshot command. The registry may *delegate* to it later; it does not swallow it.

---

## 1.2 `repair_visits` — materialised table

### Migration

**File:** `database/migrations/2026_08_04_100000_create_repair_visits_table.php`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `vehicle_id` | bigint unsigned | **yes** | null = unattributed bucket (646 tickets) |
| `vendor_id` | bigint unsigned | **yes** | null = unattributed bucket (1,738 tickets) |
| `started_at` | date | no | MIN(`out_date`) in the group |
| `ended_at` | date | **yes** | MAX(`actual_in_date`); null = still open |
| `duration_days` | smallint | yes | `DATEDIFF(ended_at, started_at)`; null when open or negative |
| `is_open` | boolean | no | `ended_at IS NULL` |
| `event_row_count` | smallint unsigned | no | how many `maintenances` rows collapsed in |
| `maintenance_ids` | json | no | the source ticket ids — the audit trail |
| `primary_maintenance_id` | bigint unsigned | no | lowest id; the representative ticket |
| `origin_mix` | varchar(60) | no | e.g. `sheet`, `sheet+manual` |
| `has_close_date` | boolean | no | drives the closure-rate metric |
| `multi_vendor_day` | boolean | no | **flag** — car recorded at >1 garage that day (2,325 cases) |
| `grouping_window_days` | tinyint unsigned | no | **0** — stored so a later change is auditable |
| `signature_set` | json | yes | distinct fault signatures on the visit |
| `built_at` | timestamp | no | rebuild stamp |

**Indexes** — `(vehicle_id, started_at)` · `(vendor_id, started_at)` · `(started_at)` ·
`(is_open)` · unique `(primary_maintenance_id)`.

No foreign keys. This is a derived table; a FK to a soft-deleted parent creates rebuild ordering
problems for zero benefit.

**Rollback:** `dropIfExists`. The table is fully reproducible from `maintenances`, so rollback is
lossless.

### The algorithm

**Input:** `maintenances`, **HISTORICAL** (includes soft-deleted, per §0.4).

```
STEP 1 · SELECT candidate rows
        id, vehicle_id, vendor_id, out_date, actual_in_date, origin, event_status, workflow_status
        WHERE out_date IS NOT NULL                       -- 1,385 rows excluded here
        ORDER BY vehicle_id, vendor_id, out_date, id

STEP 2 · PARTITION into groups
        Group key = (vehicle_id, vendor_id, out_date)     -- window = 0 days, per Correction B
        NULL vehicle_id or vendor_id → group key uses the literal NULL,
        producing "unattributed" visits that are counted but never attributed to a garage

STEP 3 · COLLAPSE each group into one visit
        started_at            = out_date
        ended_at              = MAX(actual_in_date) over the group, NULL if none
        duration_days         = DATEDIFF(ended_at, started_at), NULL if <0 or open
        event_row_count       = COUNT(*)
        maintenance_ids       = JSON array of ids
        primary_maintenance_id= MIN(id)
        origin_mix            = sorted distinct origins, joined
        has_close_date        = ended_at IS NOT NULL
        multi_vendor_day      = TRUE if this (vehicle_id, out_date) has >1 distinct vendor_id
        signature_set         = distinct signatures from maintenance_signatures
                                for those maintenance_ids, is_exposure = 0

STEP 4 · WRITE via transactional swap (see Rebuild strategy)
```

### Edge cases — all verified against live data

| Edge case | Volume | Handling |
|---|---:|---|
| **Multiple tickets same day, same vehicle+garage** | 15,045 pairs; only 23% of groups are single-row | **Collapsed into one visit.** This is the entire point of the table. `event_row_count` records how many |
| **Same vehicle, same day, different garage** | 2,325 days | **Kept as separate visits** (vendor is in the group key) and both flagged `multi_vendor_day = true`. Not silently merged — it may be a real transfer or a data error, and the flag lets us find out. Excluded from garage duration metrics by default |
| **Open tickets (no `actual_in_date`)** | 20,018 of 26,942 (74%) | Visit created with `ended_at = NULL`, `is_open = true`. **Counted in volume, excluded from duration.** This is why every duration metric ships with a closure-rate companion |
| **Missing `out_date`** | 1,385 | **Excluded from the table.** Reported by the command as `skipped_no_date` and surfaced on Data Health. Never silently dropped |
| **No `vendor_id`** | 1,738 | Visit created with `vendor_id = NULL`. Counted fleet-wide, never attributed to a garage |
| **No `vehicle_id`** | 646 | Visit created with `vehicle_id = NULL`. Counted, never attributed to a car |
| **Neither** | 36 | Created, fully unattributed |
| **Negative duration** (`actual_in_date < out_date`) | 31 | `duration_days = NULL`, `has_close_date = true`. **Not discarded** — the visit happened; only the duration is unusable. Command reports the count |
| **Cancelled repairs** | **1 row** (`review_rejected`) | Effectively does not exist today. Handled defensively: `workflow_status IN ('review_rejected','cancelled')` sets `is_cancelled` on the source row and excludes the visit from *quality* metrics while keeping it in volume. Revisit when the workflow engine has adoption |
| **Soft-deleted tickets** | 0 today | **Included** per §0.4 |

### Idempotent rebuild command

**`php artisan intelligence:rebuild-visits [--since=YYYY-MM-DD] [--dry-run] [--chunk=5000]`**

```
1. Compute into a staging table  repair_visits_rebuild
2. Validate (rules below). Abort on failure — the live table is untouched
3. Transaction: RENAME repair_visits → repair_visits_old,
                 repair_visits_rebuild → repair_visits; DROP old
4. Report: rows_in, visits_out, collapse_ratio, skipped_no_date,
           null_vendor, null_vehicle, negative_duration, multi_vendor_days, duration_ms
```

**Idempotence guarantee:** full rebuild from source every run, atomic swap. Running twice produces
byte-identical rows — asserted by a test that rebuilds twice and diffs a checksum.

`--since` is for local iteration only. **Production always runs a full rebuild** — 27k rows is
seconds, and incremental logic is a correctness risk for no measurable gain.

### Validation rules — abort the swap if any fails

| # | Rule | Expected today |
|---|---|---|
| V1 | `SUM(event_row_count)` = source rows with a non-null `out_date` | 25,557 |
| V2 | Visit count < source count (collapse actually happened) | ~13k of 25,557 |
| V3 | Single-row groups ≈ 23% ±2pp | 23% |
| V4 | No visit has `started_at` NULL | 0 |
| V5 | `duration_days` never negative | 0 |
| V6 | Every `primary_maintenance_id` exists in `maintenances` | 100% |
| V7 | `is_open` count ≈ 74% ±3pp | 74% |
| V8 | Second consecutive run produces an identical checksum | identical |

### Scheduler integration

```
routes/console.php
Schedule::command('intelligence:rebuild-visits')
    ->dailyAt('04:20')          // after om:sync 03:00 and import:vehicle-status 03:15
    ->withoutOverlapping()
    ->onFailure(fn () => /* notify + leave last-good table in place */);
```

**Sequencing matters:** visits must rebuild after the nightly sync populates `maintenances`, and
recurrence must rebuild after visits. 04:20 / 04:35 gives each a margin.

⚠ **The scheduler is dead on dev machines and unverified on the server.** Before Milestone 1
sign-off, someone runs both commands on the server by hand and confirms the scheduled task fires.
`built_at` is surfaced on Data Health with an alert past 36 hours.

---

## 1.3 `fault_recurrence_pairs` — materialised table

### Migration

**File:** `database/migrations/2026_08_04_100100_create_fault_recurrence_pairs_table.php`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint unsigned PK | no | |
| `vehicle_id` | bigint unsigned | no | |
| `signature` | varchar(24) | no | one of the 20 |
| `occurred_at` | date | no | the first occurrence |
| `first_maintenance_id` | bigint unsigned | no | representative ticket (lowest id that day) |
| `first_vendor_id` | bigint unsigned | yes | who did the first repair |
| `next_occurred_at` | date | **yes** | null = no recurrence yet (open chain) |
| `next_maintenance_id` | bigint unsigned | yes | |
| `next_vendor_id` | bigint unsigned | yes | who saw it next |
| `days_to_return` | smallint unsigned | yes | `DATEDIFF(next, first)`; always ≥ 1 |
| `returned_30` / `_60` / `_90` | boolean | no | precomputed windows |
| `same_vendor` | boolean | yes | did it go back to the same garage |
| `label_source` | varchar(12) | no | `derived` \| `human` \| `both` |
| `source_row_count` | smallint unsigned | no | **duplicates collapsed** — the Correction A audit trail |
| `multi_vendor_day` | boolean | no | first occurrence spanned >1 garage |
| `chain_position` | smallint unsigned | no | 1st, 2nd, 3rd… occurrence in the chain |
| `built_at` | timestamp | no | |

**Indexes** — `(first_vendor_id, signature)` · `(vehicle_id, signature, occurred_at)` ·
`(signature, occurred_at)` · `(days_to_return)` · unique `(vehicle_id, signature, occurred_at)`.

That unique index is the structural enforcement of Correction A: duplicates cannot enter the table.

### Calculation logic

**Input:** `maintenance_signatures` ⨝ `maintenances`, **HISTORICAL**, `is_exposure = 0`.

```
STEP 1 · DEDUPLICATE          ← Correction A. Non-negotiable.
        Collapse to one row per (vehicle_id, signature, occurred_at).
        Keep: MIN(maintenance_id) as representative → its vendor_id
              COUNT(*) as source_row_count
              label_source = 'both' if the group holds derived AND human, else the one present
        Skip rows with NULL vehicle_id or NULL occurred_at (report the count).
        Note: 1,961 fault rows belong to tickets with no vendor — kept, first_vendor_id NULL,
        excluded from garage metrics but counted fleet-wide.

STEP 2 · ORDER within each chain
        PARTITION BY (vehicle_id, signature) ORDER BY occurred_at

STEP 3 · LEAD to find the next occurrence
        next_occurred_at = LEAD(occurred_at) OVER (…)
        next_vendor_id   = LEAD(vendor_id)   OVER (…)
        chain_position   = ROW_NUMBER()      OVER (…)

STEP 4 · DERIVE
        days_to_return = DATEDIFF(next_occurred_at, occurred_at)   -- ≥1 by construction
        returned_30/60/90 = days_to_return <= 30/60/90
        same_vendor    = first_vendor_id = next_vendor_id

STEP 5 · WRITE via transactional swap
```

Rows where `next_occurred_at IS NULL` are **kept**. They are the open chains — the denominator. A
garage whose repairs never come back must be visible as exactly that, and dropping those rows would
make every recurrence rate meaningless.

### How the three questions are answered

**Same fault returning** — a row where `signature` matches within the same `(vehicle_id, signature)`
partition and `next_occurred_at IS NOT NULL`. Attributed to `first_vendor_id`.
*Limitation to state on every surface:* granularity is system-level. `BRAKES` conflates noise, pads,
discs, ABS. We can say "brake work came back," not "brake noise came back."

**Different fault after a repair** — not this table. It is `G3 any-fault return`, computed from
`repair_visits` with `LEAD(started_at)` per vehicle, attributed to the prior visit's vendor. Two
different questions, two different sources — do not conflate them.

**Time between repairs** — `days_to_return`, always ≥ 1 because same-day duplicates were removed in
STEP 1. Report **median and p90 alongside the mean**; the distribution is heavily right-skewed and a
mean alone will mislead.

### Rebuild command

**`php artisan intelligence:rebuild-recurrence [--dry-run]`**
Same staging → validate → atomic swap pattern. **Must run after `rebuild-visits`.**

**Validation rules:**

| # | Rule | Expected |
|---|---|---|
| R1 | Distinct signatures = 20 | 20 |
| R2 | No `days_to_return` < 1 | 0 |
| R3 | Unique `(vehicle_id, signature, occurred_at)` holds | no violation |
| R4 | `SUM(source_row_count)` = deduped input rows | ~33,874 |
| R5 | Golden: Alresala = 14.3 days ±0.1, n=66 (from 2024-01-01) | matches |
| R6 | Golden: GPT GARRAGE = 102.4 days ±0.1, n=968 | matches |
| R7 | No `is_exposure = 1` row leaked in | 0 |
| R8 | Second run identical checksum | identical |

**Scheduled:** `dailyAt('04:35')`, `withoutOverlapping()`.

---

# 2. Database Migration Plan

Additive only · no CHECK constraints (MariaDB local / MySQL 8 prod divergence) · test DB rebuilt by
**cloning** `laravel`, never `migrate:fresh`.

## 2.1 Required Now

| # | Filename | Purpose | Columns / Indexes | Back-compat | Rollback |
|---|---|---|---|---|---|
| M1 | `..._100000_create_repair_visits_table` | F2 visit collapse | §1.2 | New table — nothing reads it yet | `dropIfExists`, lossless |
| M2 | `..._100100_create_fault_recurrence_pairs_table` | F4 recurrence | §1.3 | New table | `dropIfExists`, lossless |
| M3 | `..._100200_create_vehicle_model_map_table` | F1 normalisation | `raw_make`, `raw_model`, `canonical_make`, `canonical_model`, `segment`, `body_type`, `reviewed_by`, `reviewed_at`; unique `(raw_make, raw_model)` | New table | `dropIfExists`; mapping data must be re-seeded — **export before rollback** |
| M4 | `..._100300_add_analytics_flags_to_vendors` | F3 registry | `is_analytical` bool default **true**, `alias_of_vendor_id` bigint null; index `(is_analytical)` | Default `true` = current behaviour preserved | `dropColumn` ×2 |
| M5 | `..._100400_add_intelligence_fields_to_vehicles` | F1 segmentation | populates existing `vehicle_class` (0/438) | Data only, no DDL | data revert script |
| M6 | `..._100500_create_expense_attributions_table` | CB bridge · **Phase 6** | `vehicle_expense_id`, `maintenance_id?`, `vendor_id?`, `attribution_method`, `confidence`, `computed_at`; index `(vendor_id, attribution_method)`, unique `(vehicle_expense_id)` | New table | `dropIfExists`, lossless |
| M7 | `..._100600_add_vendor_id_to_vehicle_expenses` | **CP2 — the real fix** · Phase 6 | `vendor_id` bigint null; index | Nullable — existing rows unaffected | `dropColumn` |
| M8 | `..._100700_create_insights_table` | Phase 7 | per Blueprint §4.1 #6; unique `(code, entity_key, window_start)` | New table | `dropIfExists` |
| M9 | `..._100800_create_insight_dismissals_table` | Phase 7 | `insight_code`, `user_id`, `reason`, `dismissed_at` | New table | `dropIfExists` |

**Ordering:** M1–M5 ship in the first PR wave (Phase 1). M6–M7 with Phase 6. M8–M9 with Phase 7.

## 2.2 Required Later

| # | Change | Trigger |
|---|---|---|
| L1 | `vendors.warranty_days` smallint null | When G7 warranty-return-rate is scheduled. One column unblocks a currently NOT-VIABLE metric |
| L2 | Indexes on `maintenances(vendor_id, out_date)`, `maintenance_signatures(signature, occurred_at)`, `vehicle_expenses(vehicle_id, entry_date, category)` | **Only after `EXPLAIN` on the real rebuild queries.** Do not add speculatively — writes pay for every index |
| L3 | `maintenance_tasks.repair_hours` required at close | CP6, when Tier-1 capture has adoption |
| L4 | `metric_snapshots` (generalise the 1-row `kpi_snapshots`) | If trend-over-trend is wanted |

## 2.3 Avoid

| Proposal | Why not |
|---|---|
| FKs on `repair_visits` / `fault_recurrence_pairs` | Derived tables referencing soft-deletable parents create rebuild-ordering failures for no integrity gain |
| CHECK constraints on `attribution_method`, `label_source` | Passes on MariaDB locally, fails on MySQL 8 in prod. Enforce in PHP |
| `migrate:fresh` anywhere | Migrations cannot run from empty on this codebase. Clone `laravel` → `laravel_test` |
| A `garages` table | `vendors.type='garage'` is correct. Forking it splits the source of truth |
| Persisting `vehicles.health_score` | Formula will change. Cache it, never store it |
| Any change to `part_purchases` / `vehicle_components` | Schema is already right. The gap is capture, not modelling |
| Dropping the 3,475 seeded rows | `SyntheticDataFilter` excludes them at the query layer. Deleting destroys the UI test fixtures |

---

# 3. Backend Implementation Plan

Layer flow per `ARCHITECTURE-CONVENTIONS.md`, without exception:
`Route → FormRequest → Controller (thin, try/catch) → Service → Resource → ResponseHelper`.

## 3.1 Controllers

| Controller | Methods | Responsibility |
|---|---|---|
| `Intelligence/EvidenceController` | `show` | Resolve `queryId` → claim + method + rows. **Phase 1** |
| `Intelligence/GarageIntelligenceController` | `index`, `matrix`, `compare`, `reworkCost`, `show`, `faults`, `recurrences`, `timeline` | Phase 2 |
| `Intelligence/OperationsBoardController` | `board`, `tile`, `sla`, `workload`, `aging` | Phase 3 |
| `Intelligence/VehicleIntelligenceController` | `index`, `show`, `timeline`, `faults`, `cost`, `downtime` | Phase 4 |
| `Intelligence/FaultIntelligenceController` | `index`, `trends`, `concentration`, `cooccurrence`, `show`, `garages`, `vehicles` | Phase 5 |
| `Intelligence/FinancialIntelligenceController` | `overview`, `byVehicle`, `byModel`, `byGarage`, `lifetimeCurve`, `warrantyLeakage`, `costPerKm` | Phase 6 |
| `Intelligence/ExecutiveController` | `summary` | Phase 7 — assembles, never computes |
| `Intelligence/InsightController` | `index`, `show`, `feedback` | Phase 7 |

Every method: build `MetricContext` from the FormRequest → call one service method → wrap in a
Resource → `ResponseHelper::SuccessResponse`. Try/catch → `ResponseHelper::fromException`.
**No conditionals, no computation, no query building.**

## 3.2 Services — business logic ownership

| Service | Owns | Never touches |
|---|---|---|
| `Garage/RecurrenceMetrics` | G1, G2, G3 | `maintenance_signatures` directly — reads `fault_recurrence_pairs` only |
| `Garage/GarageQualityScore` | G8 composite + weight renormalisation | raw SQL |
| `Garage/FaultMatrix` | G16 grid | scoring rules (delegates to `GarageQualityScore`) |
| `Garage/ReworkCost` | G17 | actual invoice amounts (uses category averages, returns `Kpi::estimated`) |
| `Vehicle/VehicleHealthScore` | V1, six components, fleet percentiles | risk rules |
| `Vehicle/VehicleRiskScore` | V10 | health |
| `Vehicle/VehicleTimelineBuilder` | V4 four-lane union | **must apply `SyntheticDataFilter`** |
| `Vehicle/ReplacementAdvisor` | V12 rules | scoring |
| `Fault/FaultTrends` | F-3, F-4 | normalisation (delegates to `FleetSizeNormalizer`) |
| `Fault/FaultConcentration` | F-16 Gini/Lorenz | trends |
| `Fault/FaultCooccurrence` | F-9 lift | ontology weights (measured only, never seeded) |
| `Financial/ExpenseAttributionBridge` | CB, four methods | **never spreads ambiguous cost** |
| `Financial/LifetimeCostCurve` | C13 | attribution |
| `Financial/WarrantyLeakage` | C15 | — |
| `Operations/OperationsBoardService` | O1–O15 tiles, single payload | history metrics |
| `Executive/ExecutiveSummaryService` | assembling module outputs | **any computation** |
| `Insights/InsightEngine` | running generators, ranking, dedupe, escalation | prose (emits `template_key` + `params`) |
| `Support/GarageRegistry` | exclusions + aliases | metrics |
| `Support/ModelNormalizer` | canonical make/model | vehicles |
| `Support/FleetSizeNormalizer` | fleet size per month | trends |
| `Support/SyntheticDataFilter` | seed quarantine | everything else |

## 3.3 Queries — where the complexity lives

| Query | Home | Complexity |
|---|---|---|
| Visit collapse | `IntelligenceRebuildVisits` command | Grouped scan, 27k rows |
| Recurrence dedupe + LEAD | `IntelligenceRebuildRecurrence` command | Window fn over 33,874 deduped rows |
| Garage × fault matrix | `FaultMatrix` | One grouped query → pivot in PHP |
| Fleet health percentiles | `VehicleHealthScore` | **All 438 computed at once**, cached 6h. Never per request |
| Fault co-occurrence lift | `FaultCooccurrence` | Self-join over 49,487 — **nightly cache warm, never live** |
| Expense attribution | `IntelligenceAttributeExpenses` command | Correlated window match, 28,327 × 26,942 — **never at request time** |
| Lifetime cost curve | `LifetimeCostCurve` | One pass over 12 years |

## 3.4 Resources — API response structures

| Resource | Shapes |
|---|---|
| `KpiResource` | `Kpi` → 13 keys (9 existing + 4 new). **Every KPI in the platform goes through this** |
| `GarageScoreResource` | garage + KPI collection + `terms_used` / `terms_skipped` |
| `FaultMatrixResource` | `{ garages[], signatures[], cells[][] }` with nulls below `min_sample` |
| `VehicleProfileResource` | header + scores + component breakdown |
| `VehicleTimelineResource` | four lanes, each `{ type, at, label, ref, meta }` |
| `RecurrencePairResource` | the evidence table row — both tickets, both dates, days between |
| `CoverageResource` | `{ covered, total, pct, as_of }` |
| `EvidenceResource` | `{ claim, method, technical_note, rows[], row_count, exportable }` |
| `InsightResource` | `{ code, severity, template_key, params, metrics, sample_size, baseline, window, evidence_query_id }` |

## 3.5 Permissions

**Three new Spatie permissions** (seeded via `RolesAndPermissionsSeeder`):

| Permission | Granted to | Guards |
|---|---|---|
| `intelligence.view` | super-admin, admin, manager, maintenance, supervisor | Hub, Garage, Vehicle, Fault |
| `intelligence.executive` | super-admin, admin, manager | Executive Home, strategic recommendations |
| `intelligence.financial` | super-admin, admin, manager, finance | Financial module (**plus** `SHOW_FINANCIALS` flag) |

**Reused:** `insights.view` (exists), `maintenance.view` / `.manage` (exist).

**Adham (`maintenance`) explicitly does NOT get `intelligence.financial`.** A feature test asserts
403 on every financial route for that role.

---

# 4. API Contract Design

**Envelope on every response:** `{ data, success, message }` via `ResponseHelper`.
**Every endpoint:** `auth:sanctum` + a `permission:` middleware.
**Static routes are declared before `/{param}` routes** — `/garages/matrix` must not resolve to `show`.

## 4.1 Shared request contract

Accepted by every intelligence endpoint, validated by `MetricFilterRequest`:

| Param | Type | Default | Notes |
|---|---|---|---|
| `from`, `to` | date | last 12 months | |
| `vendor_id[]` | int[] | — | |
| `vehicle_id[]` | int[] | — | |
| `model` | string | — | canonical, post-F1 |
| `signature` | string | — | one of 20 |
| `origin` | enum | — | `sheet`\|`customer-sheet`\|`manual`\|`contract` |
| `min_sample` | int | **30** | floor 1, ceiling 500 |
| `include_non_garages` | bool | **false** | |
| `sort`, `dir` | string | per endpoint | whitelisted columns only |
| `page`, `per_page` | int | 1, 25 | max 100 |

## 4.2 Endpoints

### Garage Intelligence — `permission:intelligence.view`

```
GET /api/intelligence/garages
    sort: quality|return_days|repeat_pct|median_days|volume   dir: asc|desc   paginated
    → data: { rows: [ { vendor_id, name, quality: <Kpi>, return_days: <Kpi>,
                        repeat_pct: <Kpi>, median_days: <Kpi>, volume, trend: [12],
                        terms_used: [], terms_skipped: [] } ],
              fleet_baseline: { return_days, repeat_pct },
              excluded_vendors: [ { id, name, ticket_count, reason } ],
              meta: { total, page, per_page } }

GET /api/intelligence/garages/matrix
    → data: { garages: [{id,name}], signatures: [...20],
              cells: [[ { quality, volume, return_days } | null ]],   // null = below min_sample
              min_sample }

GET /api/intelligence/garages/compare?ids=331,364,234
    → data: { garages: [...], metrics: [ { code, label, values: {331:…}, fleet, better: 'lower' } ] }

GET /api/intelligence/garages/rework-cost
    → data: { total: <Kpi confidence=estimated>, by_garage: [ { vendor_id, name, amount, recurrences } ],
              method_note }

GET /api/intelligence/garages/{vendor}
    → data: { vendor: {...}, kpis: [ <Kpi>… ], volume_window, currently_holding }

GET /api/intelligence/garages/{vendor}/faults
    → data: { rows: [ { signature, volume, quality, return_days, vs_fleet } ] }

GET /api/intelligence/garages/{vendor}/recurrences          ← THE EVIDENCE TABLE
    paginated
    → data: { rows: [ { vehicle_id, plate, signature,
                        first: { maintenance_id, date, vendor },
                        next:  { maintenance_id, date, vendor },
                        days_to_return, same_vendor } ],
              meta: {...}, evidence_query_id }

GET /api/intelligence/garages/{vendor}/timeline
    → data: { quarters: [ { period, quality, volume } ] }
```

### Operations — `permission:maintenance.view`

```
GET /api/intelligence/operations/board          ← ONE request, all tiles
    → data: { tiles: [ { code, label, count, tier: 'workflow'|'history',
                         severity, drilldown_url } ],
              unavailable: [ { code, label, reason } ],   // O3, O4 — visible as gaps
              as_of }

GET /api/intelligence/operations/tile/{code}    paginated worklist
GET /api/intelligence/operations/sla            → { compliance: <Kpi>, avg_promise, avg_actual, slack_days, distribution: [] }
GET /api/intelligence/operations/workload       → { rows: [ { vendor_id, name, open, throughput_90d, load_ratio } ] }
GET /api/intelligence/operations/aging          → { buckets: [ { label, count } ] }
```

### Vehicle Intelligence — `permission:intelligence.view` (`/cost` also `intelligence.financial`)

```
GET /api/intelligence/vehicles                  sort: health|visits|downtime|cost|risk   paginated
    → data: { rows: [ { vehicle_id, plate, make, model, health: <Kpi>,
                        visits_per_1000_days, downtime_days, risk, recommendation } ] }

GET /api/intelligence/vehicles/{vehicle}
    → data: { vehicle: {...},
              health: { score, band, components: [ { key, label, penalty, percentile, drilldown } ] },
              risk:   { score, band, terms: [...] },
              recommendation: { verdict, triggered_rule, plain_language, economic_data_available } }

GET /api/intelligence/vehicles/{vehicle}/timeline?from=&to=
    → data: { lanes: { rentals: [], repairs: [], costs: [], events: [] },
              recurrence_markers: [ { at, signature, days_since_prior, prior_vendor } ] }

GET /api/intelligence/vehicles/{vehicle}/faults    → { chains: [ { signature, occurrences, first, last, avg_gap, vendors[] } ] }
GET /api/intelligence/vehicles/{vehicle}/cost      → { by_category: [], cost_per_km: <Kpi>, cost_per_rental_day: <Kpi>, coverage, as_of }
GET /api/intelligence/vehicles/{vehicle}/downtime  → { monthly: [ { month, shop_days, rental_days, idle_days, rent_lost } ] }
```

### Fault Intelligence — `permission:intelligence.view`

```
GET /api/intelligence/faults                → { rows: [ { signature, occurrences, vehicles_affected,
                                                          concentration: 'spread'|'concentrated',
                                                          recurrence_rate, avg_return_days, trend } ],
                                                pareto: [ { signature, cumulative_pct } ] }
GET /api/intelligence/faults/trends         → { series: [ { signature, points: [ { month, count, per_100_vehicles } ] } ] }
GET /api/intelligence/faults/concentration  → { rows: [ { signature, gini, verdict, lorenz: [] } ] }
GET /api/intelligence/faults/cooccurrence   → { pairs: [ { a, b, lift, support, contradicts_ontology } ] }
GET /api/intelligence/faults/{signature}
GET /api/intelligence/faults/{signature}/garages   → dumbbell data
GET /api/intelligence/faults/{signature}/vehicles  → worst-affected
```

### Financial — `permission:intelligence.financial` + `SHOW_FINANCIALS`

```
GET /api/intelligence/financial/overview
    → data: { total: <Kpi>, by_category: [], trend: [], as_of: '2026-03-31',
              staleness_warning: { active: true, last_complete_month, message_key } }

GET /api/intelligence/financial/by-garage      ← COVERAGE FIRST, per UX §7.3
    → data: { coverage: { traced: 705622, ambiguous: …, unattributed: 879632, traced_pct: 23 },
              ranking: [ { vendor_id, name, avg_per_repair, traced_count } ],
              method_note }

GET /api/intelligence/financial/by-vehicle     paginated
GET /api/intelligence/financial/by-model       (BlockedByPrerequisite until F1)
GET /api/intelligence/financial/lifetime-curve → { bands: [ { label, cost_per_vehicle_month, vehicle_count } ],
                                                   fleet_age_distribution: [] }
GET /api/intelligence/financial/warranty-leak  → { total: <Kpi>, candidates: [], coverage: 383/438 }
GET /api/intelligence/financial/cost-per-km    → { rows: [], coverage }
```

### Executive — `permission:intelligence.executive`

```
GET /api/intelligence/executive/summary
    → data: { health_strip: [ <Kpi> ×4 ],
              spend: {...}, garages: {...}, faults: {...},
              lifetime_curve: {...}, warranty: {...},
              insights: [ <Insight> ×5 ],
              as_of, generated_at }
```
One request. Basem's page must not fan out to eleven.

### Insights & Evidence

```
GET  /api/intelligence/insights?scope=executive|operational|garage|vehicle|fault   permission:insights.view
POST /api/intelligence/insights/{insight}/feedback   { useful: bool, reason?: string }
GET  /api/intelligence/evidence/{queryId}?page=      permission:intelligence.view
     → data: { claim, method, technical_note, rows: [], row_count, columns: [], exportable: true }
```

---

# 5. Frontend Implementation Plan

## 5.1 Pages

| File | Route | Phase |
|---|---|---|
| `src/pages/intelligence/IntelligenceHub.js` | `/intelligence` | 1 |
| `src/pages/intelligence/GarageOverview.js` | `/intelligence/garages` | 2 |
| `src/pages/intelligence/GarageCompare.js` | `/intelligence/garages/compare` | 2 |
| `src/pages/intelligence/GarageProfile.js` | `/intelligence/garages/:id` | 2 |
| `src/pages/OperationsHome.js` | `/operations-home` | 3 |
| `src/pages/intelligence/VehicleIntelligenceList.js` | `/intelligence/vehicles` | 4 |
| `src/pages/intelligence/VehicleProfile.js` | `/intelligence/vehicles/:id` | 4 |
| `src/pages/intelligence/FaultOverview.js` | `/intelligence/faults` | 5 |
| `src/pages/intelligence/FaultProfile.js` | `/intelligence/faults/:signature` | 5 |
| `src/pages/intelligence/FinancialOverview.js` | `/intelligence/financial` | 6 |
| `src/pages/ExecutiveHome.js` | `/executive` | 7 |
| `src/pages/intelligence/InsightFeed.js` | `/intelligence/insights` | 7 |

## 5.2 Hooks

| Hook | Contract |
|---|---|
| `useFetch` | **Existing, unchanged.** `{ data, loading, validating, error, reload, mutate }` |
| `useMetricFilters()` | **New.** `{ filters, setFilter, resetFilters, queryString }` — serialises to URL params. **The `useFetch` dependency array** |
| `useEvidence()` | **New.** `{ open(queryId), close, evidence, loading }` — drives `EvidenceDrawer` |
| `usePermissions()` | **Existing.** `can('intelligence.financial')` |

## 5.3 State management

**No new library.** Three tiers, per Blueprint §2.2:

| Tier | Mechanism |
|---|---|
| Server data | `useFetch(fetcher, [filters.queryString])` |
| Filter state | `useMetricFilters()` → URL params |
| UI state | local `useState` |

**Every fetcher wrapped in `useCallback`** at the call site — an inline arrow re-triggers a skeleton
load on every render, per the `useFetch` docblock.

**Polling:** Operations Home only — `refreshInterval: 60_000`, `paused: () => drawerOpen`.
Intelligence pages never poll; a number moving mid-analysis is a defect.

## 5.4 Permissions handling

Routes wrapped in `<RequirePermission permission="intelligence.view">`. Nav items filtered by
`usePermissions().can()`; `AppLayout` already drops empty sections, so Basem's three-item sidebar
**emerges from permissions with no special-casing**. Financial panels double-gated on
`intelligence.financial` + `SHOW_FINANCIALS`.

## 5.5 The five states

Driven by the `Kpi` payload, decided by the component — the page never branches:

| State | Trigger | Component |
|---|---|---|
| Loading | `loading === true` | `Skeleton` at final dimensions |
| Insufficient | `available === false` && `sampleSize > 0` | `InsufficientSample` — "12 of 30 repairs measured" |
| Blocked | `available === false` && `blockedReason` names a prerequisite | `BlockedByPrerequisite` — amber + fix link |
| Empty by design | `data` present but intentionally zero (Components tab) | `EmptyByDesign` — what would populate it |
| Error | `error` truthy | inline message + retry + metric code; page-level `ErrorBoundary` |

---

# 6. Component Implementation Order

Build in this order — each depends only on those above it.

### 1 · `ConfidenceBadge` (Phase 1, ~0.5d)
**Props:** `confidence`, `coverage`, `asOf`, `sampleSize`, `onClick`
**Data:** any `Kpi` payload · **Usage:** inside every metric card; links to Data Health

### 2 · `IntelligenceMetricCard` (Phase 1, ~1.5d)
**Props:** `kpi`, `title`, `subtitle`, `trend[]`, `drilldown{label,to}`, `variant`
**Data:** one `Kpi`
**Usage:** `<IntelligenceMetricCard kpi={data.health} title="Fleet Health" drilldown={{label:'Fleet health', to:'/intelligence/vehicles'}} />`
**Note:** selects its own state from the payload. This is what makes "no number without confidence"
structural.

### 3 · `EvidenceDrawer` (Phase 1, ~2d) — **highest priority**
**Props:** `queryId`, `open`, `onClose`
**Data:** `GET /evidence/{queryId}` → claim, method, technical_note, rows, columns
**Usage:** opened by `useEvidence().open(id)` from any insight or KPI
**Note:** built on the existing `Drawer`. Deep-linkable. **The product's credibility mechanism** —
if this is weak, every number becomes an assertion.

### 4 · `RankingTable` (Phase 2, ~2d)
**Props:** `rows`, `columns`, `fleetBaseline`, `sort`, `onSort`, `onSelect`, `minSample`
**Data:** any ranked endpoint
**Usage:** garage leaderboard, vehicle list, fault ranking
**Note:** pinned fleet-average row; `Not enough data` row state; multi-select → compare.

### 5 · `ComparisonDumbbell` (Phase 2, ~1.5d)
**Props:** `entityValue`, `benchmarkValue`, `label`, `unit`, `sampleSize`, `betterDirection`
**Data:** `{ entity: 14.3, fleet: 100.5, n: 66 }`
**Usage:** garage vs fleet return days — the signature visual of Garage Intelligence
**Note:** SVG on existing `chartUtils`. **Must mirror under RTL.**

### 6 · `ScoreCard` (Phase 2, ~2d)
**Props:** `score`, `band`, `components[{key,label,penalty,percentile}]`, `termsUsed`, `termsSkipped`, `onComponentClick`
**Data:** health / risk / quality composites
**Usage:** Vehicle Health, Garage Quality
**Note:** **refuses to render without `components`.** Enforced in the component, asserted in test.

### 7 · `PerformanceMatrix` (Phase 2, ~3d)
**Props:** `rows`, `columns`, `cells`, `minSample`, `onCellClick`, `colorScale`
**Data:** `{ garages, signatures, cells[][] }` with nulls below sample
**Usage:** G16 garage × fault; later fault × model
**Note:** blank cells for null — **never a pale colour**, which reads as "poor." Horizontally
scrollable inside its own container. Keyboard-navigable.

### 8 · `CoverageDisclosure` (Phase 6, ~1d)
**Props:** `traced`, `ambiguous`, `unattributed`, `unit`, `methodNote`, `onFixClick`
**Usage:** above Cost by Garage
**Note:** renders **above** the ranking it qualifies. Below, it is a disclaimer; above, it is context.

### 9 · `VehicleTimeline` (Phase 4, ~4d)
**Props:** `lanes{rentals,repairs,costs,events}`, `recurrenceMarkers`, `from`, `to`, `onMarkerClick`
**Usage:** Vehicle Profile → Timeline
**Note:** the `⟲` recurrence marker is what makes it an investigation tool rather than a log.
Largest component in the set.

### 10 · `InsightCard` (Phase 7, ~2d)
**Props:** `insight`, `onEvidence`, `onFeedback`, `onDismiss`
**Data:** `{ code, severity, template_key, params, metrics, sample_size, baseline, evidence_query_id }`
**Usage:** all six insight surfaces
**Note:** renders through `tp()` from `template_key` + `params` — never a concatenated sentence,
which cannot be translated into Arabic grammatically. **Refuses to render without `sample_size`.**

### 11 · `AsOfWatermark` (Phase 6, ~0.5d)
**Props:** `asOf`, `lastCompleteMonth`, `messageKey`
**Usage:** Financial module header. Data-driven — moves by itself when CP10 backfills.

---

# 7. Caching Strategy

Extends the existing `Cache::remember('intelligence:*:v1', TTL, fn)` convention.

| Key pattern | TTL | Contents | Invalidation |
|---|---|---|---|
| `intelligence:garage:leaderboard:v1:{ctxHash}` | 1h | leaderboard rows | TTL + version bump |
| `intelligence:garage:matrix:v1:{ctxHash}` | 1h | matrix cells | TTL + version bump |
| `intelligence:garage:{id}:profile:v1:{ctxHash}` | 30m | one profile | TTL |
| `intelligence:vehicle:health_set:v1` | 6h | **all 438 scores + fleet percentiles** | rebuilt by `refresh-metrics` |
| `intelligence:fault:cooccurrence:v1` | 24h | lift pairs | nightly warm |
| `intelligence:fault:trends:v1:{ctxHash}` | 6h | monthly series | TTL |
| `intelligence:financial:overview:v1:{ctxHash}` | 2h | spend rollups | TTL |
| `intelligence:executive:summary:v1:{userId}` | 1h | assembled page | TTL |
| **Operations board counts** | **none** | — | live |
| **All drill-downs and evidence** | **none** | — | live |

**`{ctxHash}`** = sha1 of `MetricContext` serialised with sorted keys, so identical filters in any
order hit the same entry. Only whitelisted filter keys enter the hash — this is what prevents
permutation explosion.

**Invalidation is version bump, not targeted flush.** A `GarageQualityScore` weight change bumps
`v1` → `v2` in the key. Cheaper, safer, and it leaves an audit trail in the key name.

**Manual rebuild:**
```
php artisan intelligence:refresh-metrics            # warm health set, cooccurrence, top-20 garages
php artisan intelligence:refresh-metrics --flush    # drop all intelligence:* keys first
php artisan cache:forget "intelligence:vehicle:health_set:v1"
```

---

# 8. Background Jobs & Scheduling

**Commands are console commands, not queued jobs.** The queue driver is `database` and these are
nightly bulk rebuilds, not per-request work. Adding a queue layer buys nothing and adds a failure
mode.

| Command | Schedule | Runtime (est.) | Failure handling |
|---|---|---|---|
| `intelligence:rebuild-visits` | `dailyAt('04:20')` `withoutOverlapping()` | 5–15s (27k rows) | Validation failure aborts before swap; **last-good table survives**. `onFailure` notifies |
| `intelligence:rebuild-recurrence` | `dailyAt('04:35')` `withoutOverlapping()` | 10–30s (34k deduped) | Same. **Must run after visits** |
| `intelligence:refresh-metrics` | `dailyAt('04:50')` | 20–60s | Cache-only. Failure is non-fatal — pages fall back to live computation |
| `intelligence:attribute-expenses` | `dailyAt('05:10')` (Phase 6) | 60–180s | Staging + swap |
| `intelligence:generate-insights` | `dailyAt('05:30')` (Phase 7) | 30–90s | Writes to `insights` with dedupe; partial failure leaves prior insights intact |

**Sequencing is mandatory:** existing `om:sync` (03:00) → `import:vehicle-status` (03:15) → visits
(04:20) → recurrence (04:35) → metrics (04:50) → attribution (05:10) → insights (05:30).

**Monitoring:**
- Every command writes `built_at` / `completed_at` and its row counts to the log
- **Data Health surfaces `built_at` per table with an alert past 36 hours** — this is the only thing
  standing between a dead scheduler and silently ageing numbers
- Runtime is logged; a 3× regression against the trailing average warrants investigation

⚠ **Blocking item for Milestone 1:** the scheduler is dead on dev machines and unverified on the
server. Before sign-off, run both commands manually on the server and confirm the scheduled task
fires. Every command must remain runnable by hand.

---

# 9. Testing Implementation Plan

## 9.1 Unit tests — `tests/Unit/Intelligence/`

| File | Asserts |
|---|---|
| `VisitCollapserTest` | Same-day grouping; **gap=1 does NOT collapse** (Correction B); multi-vendor-day flagged not merged; open visits keep `ended_at` null; negative duration → null, row retained; missing `out_date` excluded and counted |
| `RecurrencePairBuilderTest` | **Dedupe before LEAD** (Correction A) — 8 duplicate rows → 1 event; `days_to_return` ≥ 1 always; open chains retained with null `next`; `label_source` = `both` when derived + human coexist; `is_exposure=1` never enters |
| `GarageQualityScoreTest` | Renormalisation at 5 / 3 / 1 available terms; all-unavailable → `Kpi::unavailable`; `terms_used` + `terms_skipped` populated |
| `VehicleHealthScoreTest` | Bounded 0–100; percentile normalisation; missing component handled |
| `FaultConcentrationTest` | Gini: uniform → ~0, fully concentrated → ~1, single observation → defined |
| `FleetSizeNormalizerTest` | Divides by the correct month's fleet size |
| `MetricContextTest` | `hash()` deterministic under key reordering; non-whitelisted keys ignored |
| `KpiTest` | All four factories; **`toArray()` still emits the original 9 keys** (backward compat) |
| `ExpenseAttributionTest` | Four method classifications; **ambiguous never distributed** |

## 9.2 Feature tests — `tests/Feature/Intelligence/`

| File | Asserts |
|---|---|
| `IntelligenceAuthTest` | 401 unauthenticated; 403 without permission; 200 with — every route |
| `FinancialPermissionTest` | **`maintenance` role gets 403 on every `/financial/*` route** |
| `ResponseEnvelopeTest` | `{ data, success, message }` on success and failure |
| `RouteOrderingTest` | `/garages/matrix` resolves to `matrix`, not `show` |
| `MetricFilterTest` | Filters applied; `min_sample` respected; invalid sort rejected |
| `OperationsBoardTest` | All tiles in one response; **O3/O4 appear in `unavailable[]` with reasons**; drill-down count = tile count |
| `EvidenceEndpointTest` | Returns claim + method + rows; paginates |
| `KpiResourceTest` | Always includes `sample_size`, `confidence`; `coverage` when applicable |

## 9.3 Frontend tests — `src/**/*.test.js`

| File | Asserts |
|---|---|
| `IntelligenceMetricCard.test.js` | All five states from `Kpi` payload shapes |
| `ScoreCard.test.js` | **Refuses to render without `components`** |
| `InsightCard.test.js` | **Refuses to render without `sample_size`** |
| `PerformanceMatrix.test.js` | Blank (not pale) cells below `min_sample`; keyboard navigation |
| `ComparisonDumbbell.test.js` | **RTL mirroring**; numerals stay LTR |
| `useMetricFilters.test.js` | Round-trips filters through URL params |
| `Navigation.test.js` | Manager-only user sees exactly 3 nav items |

## 9.4 Golden dataset tests — `tests/Crud/Intelligence/GoldenNumbersTest.php`

Runs on `laravel_test` (a **clone** of `laravel`). **The most important test file in the project.**

| # | Assertion | Expected | Tolerance | Guards |
|---|---|---|---|---|
| G01 | Maintenance count (HISTORICAL) | 26,942 | exact | scope regressions |
| G02 | `vendor_id` coverage | 93.5% | ±0.5pp | flagship foundation |
| G03 | Signature coverage | 21,125 tickets | exact | fault layer |
| G04 | Distinct fault signatures, `is_exposure=0` | **20** | exact | vocabulary drift |
| G05 | ELECTRICAL occurrences / vehicles | 4,124 / 206 | exact | F-1 |
| G06 | **Alresala return interval (deduped)** | **14.3 days, n=66** | ±0.1 / exact | **G2 flagship + Correction A** |
| G07 | **GPT GARRAGE return interval (deduped)** | **102.4 days, n=968** | ±0.1 / exact | G2 |
| G08 | **Deals On Wheels (deduped)** | **41.8 days, n=904** | ±0.1 / exact | G2 |
| G09 | Visit collapse — single-row groups | 23% | ±2pp | **Correction B** |
| G10 | Visit gap=1 pairs NOT collapsed | 122 remain separate | exact | Correction B |
| G11 | SLA compliance | 4,888 / 5,169 = 94.6% | ±0.1pp | O8 |
| G12 | Pending review | 140 | exact | O6 |
| G13 | Closure rate | 25.7% | ±0.5pp | O15 |
| G14 | Repair-category spend | AED 6.20M | ±1% | C1/C6 |
| G15 | Traced garage spend | 1,259 / AED 705,622 | ±1% | CB |
| G16 | `day_rent_value` coverage | 437/438 | exact | E8 |
| G17 | Synthetic rows excluded | 3,475 + 3,475 + 4,544 | exact | F9 |
| G18 | `approval_status` distinct values | **1** | exact | confirms O4 stays dead |
| G19 | Recurrence table `days_to_return` < 1 | **0 rows** | exact | Correction A |
| G20 | Double rebuild → identical checksum | identical | exact | idempotence |

**Failure behaviour:** a failed golden test is a **release blocker**. It is resolved one of two ways:

1. **A regression** — fix the code. The test was right.
2. **The data legitimately moved** — update the expected value **in the same commit as a written
   justification in the test docblock** naming what changed and why.

**Never** update a golden number without the justification line. Silent re-baselining converts the
suite from a safety net into decoration, which is worse than not having it.

**Regression protection:** the suite runs in CI on every PR touching `app/Intelligence/`,
`app/Kpi/`, or the rebuild commands.

---

# 10. Development Milestones

### Milestone 1 — Foundation Complete · end of week 2

**Developer tasks:** correct the four documents (§0.3) · extend `Kpi` + `Coverage` ·
`MetricContext` / `MetricResolver` / `MetricRegistry` · `VisitCollapser` · `GarageRegistry` ·
`ModelNormalizer` · `SyntheticDataFilter` · `FleetSizeNormalizer` · migrations M1–M5 ·
both rebuild commands + scheduler entries · `EvidenceController` + registry ·
frontend `intelligence.js`, `useMetricFilters`, `useEvidence`, `ConfidenceBadge`,
`IntelligenceMetricCard`, `EvidenceDrawer`, three state components · Data Health elevated ·
vehicle model map reviewed (438 rows, human task)

**Acceptance criteria**
- Both rebuild commands run twice → identical checksums
- All 20 golden tests pass
- Scheduler verified firing **on the server**
- `EvidenceDrawer` opens a real query end-to-end
- Data Health shows live coverage + `built_at` per table
- **Every existing page still works** — no `Kpi` consumer broke

**Demo:** "Here is what the platform knows, how complete it is, and how you check any number."

---

### Milestone 2 — Garage Intelligence MVP · end of week 5 · **the proof-of-value milestone**

**Tasks:** `RecurrenceMetrics` · `GarageQualityScore` · `FaultMatrix` · `ReworkCost` ·
`GarageIntelligenceService` · controller (8 methods) · resources · routes ·
`RankingTable` · `ComparisonDumbbell` · `ScoreCard` · `PerformanceMatrix` ·
`GarageOverview` · `GarageCompare` · `GarageProfile`

**Acceptance criteria**
- Leaderboard excludes 3,916 pseudo-garage tickets; aliases merged
- Matrix blanks every cell below n=30
- Quality score renormalises and reports `terms_used` / `terms_skipped`
- **Recurrence evidence table shows both tickets with dates**
- Golden G06–G08 pass
- Dumbbell mirrors under RTL; matrix keyboard-navigable

**Demo to Basem:** "Faults sent to Alresala come back in 14 days; the fleet takes about 100. Here are
the 66 repairs." **This is the milestone that funds the rest of the project.**

---

### Milestone 3 — Operations Live · end of week 7
**Tasks:** `OperationsBoardService`, controller, routes, `OperationsHome`, tile components.
**Acceptance:** all tiles in one request · O3/O4 render as declared gaps, not zeros · closure rate
visible · SLA shows compliance **and** slack · polling pauses on drawer open · golden G11–G13 pass.
**Demo to Adham:** "Your Monday morning, in one screen."

### Milestone 4 — Vehicle Intelligence · end of week 9
**Tasks:** health/risk scores, timeline builder, replacement advisor, list + profile pages,
`VehicleTimeline`.
**Acceptance:** score never renders without its breakdown · timeline excludes seeded rows ·
**Components tab renders `EmptyByDesign`** · replacement returns `insufficient_economic_data` for the
201 priceless vehicles · `/cost` 403s without financial permission.

### Milestone 5 — Fault Intelligence · end of week 11
**Acceptance:** `is_exposure=0` asserted explicitly · trends normalised per 100 vehicles · Gini
verdicts render · golden G04–G05 pass.

### Milestone 6 — Financial Intelligence · end of week 14
**Acceptance:** as-of watermark is **data-driven** · cost-by-garage returns coverage **before**
ranking · ambiguous cost never distributed (asserted) · Adham 403s everywhere · golden G14–G15 pass.

### Milestone 7 — Executive & Insights · end of week 16
**Acceptance:** executive summary in **one request**, renders <2s · manager sidebar = 3 items ·
insights dedupe and escalate · **no generator emits a probability** (asserted against the template
catalogue) · UX Scenarios 1–3 pass · PDF export retains confidence badges.

---

# 11. The First Pull Request

**Title:** `feat(intelligence): foundation — metric layer + materialised tables`
**Target:** ~3 days, one engineer. Reviewable in one sitting.

## Files changed

**Documentation (do this first)**
```
docs/Fleet-Maintenance-Intelligence-Study.md          corrected figures + note
docs/Fleet-Intelligence-Implementation-Roadmap.md     corrected figures + note
docs/Fleet-Intelligence-UX-Specification.md           corrected figures + note
docs/Fleet-Intelligence-Engineering-Blueprint.md      window 3 → 0, golden numbers
```

**Backend — new**
```
app/Intelligence/Coverage.php
app/Intelligence/MetricContext.php
app/Intelligence/MetricResolver.php
app/Intelligence/MetricRegistry.php
app/Intelligence/Support/VisitCollapser.php
app/Intelligence/Support/SyntheticDataFilter.php
app/Models/RepairVisit.php
app/Models/FaultRecurrencePair.php
app/Console/Commands/IntelligenceRebuildVisits.php
app/Console/Commands/IntelligenceRebuildRecurrence.php
database/migrations/..._create_repair_visits_table.php
database/migrations/..._create_fault_recurrence_pairs_table.php
```

**Backend — modified**
```
app/Kpi/Kpi.php          +4 properties, +1 factory, +4 toArray keys — ALL ADDITIVE
routes/console.php       +2 scheduled commands
```

**Tests**
```
tests/Unit/Intelligence/VisitCollapserTest.php
tests/Unit/Intelligence/RecurrencePairBuilderTest.php
tests/Unit/Intelligence/MetricContextTest.php
tests/Unit/Intelligence/KpiTest.php
tests/Crud/Intelligence/GoldenNumbersTest.php          G01–G10, G17–G20
```

## Why these files

This PR delivers **the two materialised tables and the metric contract** — the substrate every later
phase reads. It touches exactly one existing file (`Kpi.php`) and only additively, so its blast
radius on the running application is zero. It is also the only PR where the two algorithm
corrections can be validated cheaply: once Garage Intelligence is built on top, changing the
collapse window or the dedupe rule means re-verifying every number downstream.

## Tests that must pass

- All new unit tests
- **Golden G01–G10, G17–G20** against a fresh `laravel_test` clone
- **The entire existing suite** — `phpunit.xml` and `phpunit.crud.xml` — unchanged and green.
  Particularly `MaintenanceDeletionLifecycleTest`, which consumes `Kpi`
- Both rebuild commands run twice → identical checksums
- `npm run check:i18n` (no frontend strings yet, but keep the gate green)

## What must NOT be in this PR

| Excluded | Why |
|---|---|
| Any controller, route, or API endpoint | Nothing consumes the tables yet. Endpoints belong with their module |
| Any React file | Frontend follows once the contract is proven server-side |
| `vehicle_model_map` (M3) and the vendor flags (M4) | Separate PR — they carry **human-reviewed data**, and mixing a 438-row mapping review into an algorithm PR makes both harder to review |
| `MetricRegistry` resolver implementations | The registry ships empty. Resolvers arrive with their modules |
| `expense_attributions`, `insights` tables | Phases 6 and 7 |
| Index tuning (L2) | Add only after `EXPLAIN` on the real rebuild queries |
| Deleting the 3,475 seeded rows | `SyntheticDataFilter` excludes them at query level; deleting destroys UI fixtures |
| Any refactor of `OperationalKpiService` | Out of scope. It works |

## Definition of done

A reviewer can run `php artisan intelligence:rebuild-visits && php artisan intelligence:rebuild-recurrence`
on a clone, watch both report their row counts, run `GoldenNumbersTest`, and see 14 assertions pass
against numbers independently measured in the Study. At that point the foundation is not merely
written — it is **verified**, and everything built on it inherits that verification.

---

## Tomorrow Morning

1. Correct the four documents (§0.3). 30 minutes. Do it before anything else so nobody codes against
   the inflated numbers.
2. Read `ARCHITECTURE-CONVENTIONS.md` and `app/Kpi/Kpi.php`.
3. Write `VisitCollapserTest` **before** `VisitCollapser` — the edge cases in §1.2 are the test cases,
   and they are already enumerated with real volumes.
4. Build the collapser, then the command, then run it.
5. Compare against G09/G10. When they pass, the hardest correctness question in the project is
   settled and everything after it is ordinary engineering.
