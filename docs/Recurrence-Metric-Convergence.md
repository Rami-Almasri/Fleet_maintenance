# Recurrence Metric Convergence — One Definition, One Implementation, One Source of Truth

**Date:** 2026-08-04 · **Status:** Architectural ruling. Blocks PR #2 (Garage Intelligence).
**Scope:** every calculation in the platform that answers *"did the same fault come back?"*

---

## 0. The finding

Five implementations currently answer the same business question, and they disagree. Measured on
the live corpus:

| # | Implementation | Population | Horizon | n | Fleet comeback |
|---|---|---|---|---:|---:|
| A | `Kpi\OperationalKpiService::recurrenceStats()` | raw signature rows | none | 33,026 | **40.62%** |
| B | `Garage\ForecastCalibration` | raw signature rows | `CURDATE() − 90d` | 27,758 | **40.96%** |
| C | `Garage\GarageScorecardService::cells()` | raw signature rows | `MAX(occurred_at) − 90d` | 27,386 | **40.62%** |
| D | `fault_recurrence_pairs` (PR #1) | deduplicated events | none | 12,608 | **45.84%** |
| E | `fault_recurrence_pairs` + horizon | deduplicated events | `MAX(occurred_at) − 90d` | 10,595 | **46.51%** |

A **5.9-point spread** on the headline number and a **3.1× range** in sample size. A and C agree on
the rate by coincidence, not by design — their populations differ by 5,640 rows.

A sixth implementation, `Garage\GarageOutcomeForecaster`, uses A's exact query shape per garage.
A seventh, `RepairIntelligence\Query\ProjectionRepairHistoryQuery`, uses it per signature.

**None of these is a bug in isolation. Each is defensible. Together they are indefensible**, because
a user asking one question of the platform gets a different answer depending on which page they open.

---

## 1. Complete inventory of recurrence calculations

### 1.1 Direct calculators — query `maintenance_signatures` to derive recurrence

| # | File | Grain | Window | Horizon | Consumers |
|---|---|---|---|---|---|
| **C1** | `app/Kpi/OperationalKpiService.php` `recurrenceStats()` | fleet | 90d const | **none** | `firstTimeFixRate()`, `comebackRate()`, `KpiSnapshot` command, `kpi_snapshots` table, Intelligence Center |
| **C2** | `app/Services/Garage/GarageScorecardService.php` `cells()` | garage × domain | 90d config | `MAX(occurred_at)−90d` | `/garages` page, `GarageScorecardController` |
| **C3** | `app/Services/Garage/GarageOutcomeForecaster.php` | garage | 90d config | **none** | `GarageRecommendationService` → assign step, Garage Finder |
| **C4** | `app/Services/Garage/ForecastCalibration.php` | garage, model | 90d config | `CURDATE()−90d` | `forecast:calibrate` command, forecast tuning |
| **C5** | `app/Services/RepairIntelligence/Query/ProjectionRepairHistoryQuery.php` | signature | 90d param | **none** | Repair Intelligence answers, `RepairHistoryQuery` |
| **C6** | `app/Intelligence/Support/RecurrencePairBuilder.php` | vehicle × signature | n/a (stores gap) | none yet | `fault_recurrence_pairs` |

### 1.2 Window constants — four different numbers claiming to be "the" window

| Location | Value | Question it actually answers |
|---|---:|---|
| `OperationalKpiService::COMEBACK_WINDOW_DAYS` | 90 | quality: did the repair hold |
| `config/garage_recommendation.outcomes.comeback_window_days` | 90 | quality |
| `config/garage_scorecard.comeback_window_days` | 90 | quality |
| `config/parts_intelligence.recurrence.window_days` | 90 | parts: was this part replaced again |
| **`config/features.intelligence.comeback.window_days`** | **14** | **operational alert: is this car bouncing** |
| `config/knowledge.retrieval.window_days` | 1095 | retrieval horizon, unrelated |

The 14-day one is **not the same question** — see §3.3.

### 1.3 Adjacent, NOT in scope

These use the word "recurrence" but measure something else. Listed so the audit is complete and so
nobody folds them in by mistake:

| File | What it actually measures | Verdict |
|---|---|---|
| `VehicleFaultRecurrenceService` | repeat-fault chains from **`maintenance_tasks`**, not signatures | Different corpus, different grain (Tier-1 capture). Leave alone |
| `RecurringFaultService` | the human adjudication inbox | Workflow, not a metric |
| `PartIntelligenceService` | same **part** fitted again | Different entity |
| `ComebackCapability` | two visits in a **fortnight** → interrupt the operator | Different question, §3.3 |
| `ComebackBacktest` | scores that 14-day alert | Follows the capability |
| `Knowledge\GaragePerformanceQueryService` | speed only; explicitly excludes on-time | No recurrence calc |

---

## 2. The canonical choice

### 2.1 Canonical dataset — `fault_recurrence_pairs`

**Ruling: `fault_recurrence_pairs` is the only dataset from which recurrence may be derived.**
After this convergence, **no code may compute recurrence from raw `maintenance_signatures`.**

### 2.2 Why it is more correct — four reasons, each measured

**Reason 1 — one fault on one car on one day is one repair.**
`maintenance_signatures` holds 2–8 rows per (vehicle, signature, date): a `derived` and a `human`
label, several matched terms, several tickets sharing a date. 33,026 raw rows are **12,608 distinct
fault events**. Counting label rows as repairs counts one repair up to eight times.

**Reason 2 — the duplication is not neutral, it is biased downward.**
Duplicates inflate numerator and denominator together *only if* recurrence is independent of
duplication. It is not: a heavily-labelled event that did **not** recur contributes up to 8 "held"
rows, diluting the rate. Correcting it moves the fleet figure from **40.62% → 45.84%**. The published
baseline understates recurrence by roughly six points.

**Reason 3 — the distortion is uneven across garages, so comparisons are wrong too.**
Inflation ranges **1.33× to 6.93×** per garage (Deals On Wheels 5.07×, POWER POINT 1.51×). Any
garage-vs-garage comparison on raw rows is comparing differently-inflated numbers.

> **Note added 2026-08-04, after implementation.** The counts in this section measure *deduplication
> alone*, which is what the audit tested. The governed metric also applies the observation horizon
> (§2.3), which removes a further 3 garages: the implemented figure is **33 scored garages**, not 36.
> The audit's numbers are left as measured — this is the record of what was found, and the governed
> figures live in `docs/Metric-Specification-Recurrence.md`.

**Reason 4 — sample floors stop meaning what they say.**
`min_n.garage = 30` on 5× inflated data enforces ~6 real repairs. **63 garages are scored today;
only 36 clear the floor on honest counts.** Twenty-seven garages carry a score built on less evidence
than the platform's own stated standard.

### 2.3 What we take FROM the existing implementations

Deduplication is necessary but not sufficient. `GarageScorecardService` carries three refinements the
new table lacks, and all three are **kept**:

| Refinement | Source | Why it survives |
|---|---|---|
| **Observation horizon** — exclude repairs too recent to have recurred | C2 | A repair done last week cannot have come back within 90 days. Counting it as "held" is a free pass that flatters every garage, and flatters busy ones most |
| **Case-mix adjustment** — expected = Σ(n_domain × fleet_rate_domain) | C2 | §2.5 |
| **Exposure-domain exclusion** derived from the classifier, not declared | C2 | Adding an exposure signature cannot silently start grading it |

C2's horizon uses `MAX(occurred_at)` rather than `CURDATE()`. That is the better choice — the corpus
ends before today, and using `CURDATE()` silently discards ~370 valid rows. **`MAX(occurred_at)` is
canonical.**

### 2.4 Canonical implementation

```
                   fault_recurrence_pairs          ← the only table
                            │
                   RecurrenceRepository            ← the only code that reads it
                            │
        ┌───────────────────┼────────────────────┐
        │                   │                    │
   MetricRegistry     GarageScorecardService   RepairHistory /
   (scalar KPIs)      (garage × domain report)  Forecaster / Calibration
        │                   │                    │
   Executive, API      /garages page        assign step, Finder
```

**`app/Intelligence/Recurrence/RecurrenceRepository.php`** — the single query surface. Methods:
`fleet()`, `byGarage()`, `byGarageAndDomain()`, `bySignature()`, `byVehicle()`, `pairsFor()`.
Every one applies the same window, the same horizon, the same exposure rule, from one config block.

**On MetricRegistry — an honest boundary.** `MetricRegistry` resolves a *code* to one `Kpi`. It is
the right façade for scalar KPIs (`garage.recurrence_rate`, `fleet.comeback_rate`) and those must all
flow through it. It is the wrong shape for a 44-garage × 15-domain report, and pretending otherwise
would produce 660 registry lookups per page load. So:

> **One source of truth = one table + one repository + one config block.**
> **MetricRegistry is the mandatory façade for every scalar KPI**, and it reads the same repository.
> Reports may call the repository directly; **nothing may call the table or the raw signatures.**

This is enforceable by a test (§5.3), which a "everything is a Kpi" rule would not be.

### 2.5 Case-mix adjustment — statistically valid, with a documented limit

The method is **indirect standardisation**: `expected = Σ(n_gd × p_d)` over domains `d`, then the
garage's ratio `actual / expected`. Structurally identical to a Standardised Mortality Ratio.

**Valid**, and the right choice here: a shop doing only oil services should not be marked down for a
fault type that recurs by schedule everywhere.

**The limit that must be documented and surfaced in the UI:** indirect standardisation is rigorous
for *garage vs fleet*. Comparing two garages' ratios **to each other** is only approximate when their
case-mixes differ substantially — the classic SMR limitation.

- "This garage's repairs come back more than the fleet would on its mix" — **valid**
- "Garage A is better than Garage B" — **valid only when their mixes are similar**

The comparison page does the second thing, so it must display each garage's domain mix beside the
ratio, and `GarageScorecardService`'s docblock must state the limitation.

---

## 3. Migration plan

### 3.1 Deprecations

| # | Deprecated | Replaced by | Consumer impact |
|---|---|---|---|
| **C1** | `OperationalKpiService::recurrenceStats()` | `RecurrenceRepository::fleet()` | Baseline moves 40.4% → ~46.5%. Re-snapshot required |
| **C2** | `GarageScorecardService::cells()` recurrence pass (~25 lines) | `RecurrenceRepository::byGarageAndDomain()` | Scored garages 63 → 36 |
| **C3** | `GarageOutcomeForecaster` `$cbRows` query | `RecurrenceRepository::byGarage()` | Per-garage rates shift; forecaster re-calibrates |
| **C4** | `ForecastCalibration` recurrence query | `RecurrenceRepository::byGarage()` | Calibration re-run |
| **C5** | `ProjectionRepairHistoryQuery` recurrence subquery | `RecurrenceRepository::bySignature()` | Per-signature rates shift |

**No file is deleted.** Every deprecation is the removal of a query *inside* a class that keeps all
its other logic.

### 3.2 Files updated

**New (4)**
```
app/Intelligence/Recurrence/RecurrenceRepository.php     the single query surface
app/Intelligence/Recurrence/RecurrenceWindow.php         window + horizon value object
config/recurrence.php                                    THE definition: window, horizon, floors
database/migrations/..._add_days_observed_to_fault_recurrence_pairs.php
```

**Modified (9)**
```
app/Kpi/OperationalKpiService.php            recurrenceStats() → repository
app/Services/Garage/GarageScorecardService.php   cells() recurrence pass → repository
app/Services/Garage/GarageOutcomeForecaster.php  $cbRows → repository
app/Services/Garage/ForecastCalibration.php      recurrence query → repository
app/Services/RepairIntelligence/Query/ProjectionRepairHistoryQuery.php
app/Console/Commands/IntelligenceRebuildRecurrence.php   populate days_observed
app/Console/Commands/KpiSnapshot.php             re-snapshot with reason
config/garage_scorecard.php                      window/horizon → config/recurrence.php; re-tune floors
config/garage_recommendation.php                 window → config/recurrence.php
```

**Removed: none.**

### 3.3 The 14-day capability — renamed, not merged

`ComebackCapability` fires on two visits for the same fault inside a **fortnight**, to interrupt an
operator. That is an *operational alert about one car right now*, not a *quality measure of a garage
over history*. Merging them would be wrong.

**Ruling: keep it, rename its vocabulary.** `comeback` → `rapid_repeat_visit` in
`config/features.intelligence`, the capability class, and its user-facing strings, so the word
"comeback" has exactly one meaning platform-wide. `ComebackBacktest` follows.

### 3.4 The frozen baseline

`kpi_snapshots` holds one row: first-time fix 59.6%, comeback 40.4%, turnaround 2.7d. After
convergence the honest figures are ≈53.5% / ≈46.5%. The old row is **kept** — a baseline you delete
is a baseline you cannot argue from — and a new row is written with
`reason = 'recurrence deduplication (see docs/Recurrence-Metric-Convergence.md)'`.

Memory `operational-kpi-baseline` must be updated in the same commit.

---

## 4. Sequence

Each step ships green; no step leaves two definitions live.

| # | Step | Proves |
|---|---|---|
| 1 | `config/recurrence.php` + `RecurrenceWindow` + `days_observed` migration + rebuild update | Golden tests still pass |
| 2 | `RecurrenceRepository` + parity tests reproducing all five current outputs | The repository can express every existing definition |
| 3 | Repoint **C1** `OperationalKpiService` + re-snapshot | Baseline moves once, deliberately, documented |
| 4 | Repoint **C2** `GarageScorecardService` + re-tune floors | `/garages` matches the baseline |
| 5 | Repoint **C3**, **C4**, **C5** | Assign step and Repair Intelligence match |
| 6 | Rename the 14-day capability | "Comeback" has one meaning |
| 7 | **Cross-surface equality test** (§5.3) | One number everywhere |

Only after step 7 does PR #2 (Garage Intelligence UI) resume.

---

## 5. Regression tests

### 5.1 Parity — `tests/Unit/Intelligence/RecurrenceRepositoryTest.php`
The repository reproduces each legacy definition when given that definition's window and horizon.
Proves convergence is a *choice of parameters*, not a loss of capability.

### 5.2 Golden — extend `tests/Golden/GoldenNumbersTest.php`
```
G21  fleet comeback (deduplicated + horizon)    46.51% ±0.1   n=10,595
G22  scored garages at min_n=30                 36            exact
G23  no raw-signature recurrence query remains  0 files       (grep guard)
G24  days_observed populated on every row       0 nulls
```

### 5.3 Cross-surface equality — `tests/Golden/RecurrenceSingleSourceTest.php`
**The test the user asked for.** For a fixed set of garages, assert that every surface returns the
*same* recurrence value:

```
for each garage in [Deals On Wheels, GPT GARRAGE, POWER POINT, RMR, FUTURE TYRES]:
    a = OperationalKpiService per-garage
    b = GarageScorecardService  (garage card reliability.comeback_pct)
    c = GarageOutcomeForecaster
    d = GET /api/intelligence/garages          (leaderboard row)
    e = GET /api/intelligence/garages/{id}     (profile)
    f = GET /api/Maintenance/garage-scorecards (legacy route)
    assert a == b == c == d == e == f          exact, not tolerance
```

Plus a **static guard**: no file outside `RecurrenceRepository` and the rebuild command may contain
a `maintenance_signatures` self-join or `EXISTS` over `occurred_at`. Enforced by grep in the test —
a new duplicate implementation fails CI on the day it is written, not a year later.

---

## 6. User-visible consequences

| # | Change | Cause |
|---|---|---|
| 1 | Fleet comeback **40.4% → ≈46.5%**; first-time fix **59.6% → ≈53.5%** | Deduplication removes downward bias |
| 2 | Scored garages **63 → 36** | Floor now means 30 real repairs |
| 3 | Per-garage rates shift unevenly (1.33×–6.93× n change) | Uneven duplication |
| 4 | Assign-step and Garage Finder recommendations re-rank | C3 repointed |
| 5 | Some domain cells drop below the floor → "not enough repairs" | Honest n |
| 6 | `/garages` and Intelligence agree, exactly | The point |
| 7 | "Comeback" no longer names the 14-day alert | §3.3 |

**Items 1 and 2 will read as regressions. They are corrections**, and both must be announced on the
page — not discovered.

---

## 7. Ruling

1. `fault_recurrence_pairs` is the **only** dataset for recurrence.
2. `RecurrenceRepository` is the **only** code that reads it.
3. `config/recurrence.php` is the **only** place the window and horizon are defined.
4. `MetricRegistry` is the **mandatory façade for every scalar recurrence KPI**.
5. No file may compute recurrence from raw `maintenance_signatures` — enforced by test.
6. Case-mix adjustment is preserved, documented as indirect standardisation, with its
   garage-vs-garage limitation surfaced in the UI.
7. The 14-day alert is a different question and is renamed accordingly.
