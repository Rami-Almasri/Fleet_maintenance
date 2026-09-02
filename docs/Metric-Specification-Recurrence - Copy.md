# Metric Specification — Recurrence

**Metric family:** `recurrence` · **Version:** 2.0.0 · **Effective:** 2026-08-04
**Owner:** Fleet Intelligence · **Status:** Governed
**Machine-readable contract:** `backend/config/metrics/recurrence.php`
**Enforced by:** `tests/Unit/Intelligence/RecurrenceContractTest.php`

> This document exists so that no engineer ever has to reverse-engineer recurrence from SQL again.
> If the code and this document disagree, one of them is a bug — and the contract test is what tells
> you which.

---

## 1. Business definition

> **Of the repairs a garage completed that have since had a full observation window in which to
> fail, the share where the same fault was recorded again on the same vehicle inside that window.**

In plain operational language, as it appears in the UI: *"how often does work sent here come back?"*

### What this metric is NOT

| Not this | Why it matters |
|---|---|
| A count of maintenance tickets | A ticket is an event; the sheet wrote one visit as several rows |
| A count of fault labels | One fault on one car on one day carries 2–8 labels |
| A prediction | It reports what happened. Our backtest showed prediction is not earnable on this data |
| A verdict on a garage | It is one input to a judgement made elsewhere (§7) |
| The 14-day "is this car bouncing" alert | Different question — an operator interrupt, not a quality measure (§9) |

---

## 2. Canonical data source

```
maintenance_signatures ──(intelligence:rebuild-recurrence, nightly)──▶ fault_recurrence_pairs
   CAPTURE LAYER                                                        INTELLIGENCE LAYER
   what was seen                                                        what happened
   2–8 rows per fault-day                                               1 row per fault event
   49,487 rows                                                          12,608 events
```

**`fault_recurrence_pairs` is the only dataset from which recurrence may be derived.**
**`App\Intelligence\Recurrence\RecurrenceRepository` is the only code that may read it.**

No page, service, report, export, job or API may compute recurrence from `maintenance_signatures`.
This is enforced by a static CI guard, not by convention.

---

## 3. Grain and deduplication

**Grain:** one row = one real fault event = `(vehicle_id, signature, occurred_at)`

`maintenance_signatures` holds several rows for the same fault on the same car on the same day: a
`derived` label and a `human` one, several matched terms, and several tickets sharing a date.
Measured on the live corpus, **33,026 raw fault rows collapse to 12,608 distinct events — a 2.62×
duplication factor**, ranging from 1.33× to 6.93× per garage.

| Rule | Value |
|---|---|
| Deduplication key | `(vehicle_id, signature, occurred_at)` |
| Vendor attribution on a deduplicated event | lowest `maintenance_id` of that day |
| Multi-garage days | flagged `multi_vendor_day`, never silently assigned |
| Audit column | `source_row_count` — how many label rows collapsed |
| Enforcement | `UNIQUE (vehicle_id, signature, occurred_at)` — a build that forgets to deduplicate fails on insert |

### Why deduplication is not merely tidier

Duplicates would be harmless if they inflated numerator and denominator equally. They do not. An
event that did **not** recur contributes up to eight "held" rows, so duplication **dilutes the rate
downward**. Correcting it moves the fleet figure from **40.62% → 45.84%**.

The previously published baseline understated recurrence by roughly six percentage points.

---

## 4. Observation horizon (right-censoring)

> A repair completed last week cannot have come back within 90 days yet. Counting it as a repair that
> *held* is a free pass that flatters every garage — and flatters the busiest ones most, because they
> have the most recent work.

| Rule | Value |
|---|---|
| Mode | `corpus_max` |
| Predicate | `days_observed >= window_days` |
| `days_observed` | `DATEDIFF(MAX(occurred_at) across the corpus, this event's occurred_at)`, stamped at build time |

**Why `corpus_max` and not `CURDATE()`.** The corpus ends before today — signatures are rebuilt from
a sheet that lags. Anchoring on the clock silently discards several hundred fully-observed rows.
`ForecastCalibration` used `CURDATE()`, `GarageScorecardService` used `MAX(occurred_at)`, and
`OperationalKpiService` applied no horizon at all — three answers to one question.

Applying the horizon moves the deduplicated fleet rate from **45.84% → 46.51%** (n 12,608 → 10,595).

---

## 5. Windows

| Window | Purpose |
|---|---|
| **90 days** | **The quality window.** The only one a garage is judged on |
| 30, 60 days | Reported alongside, because *"12 of 90 came back within a month"* is actionable and *"13.3%"* is not |

All three use the same horizon and the same exposure rule. Narrowing the window never drops the
censoring rule.

---

## 6. Filters

| Filter | Value | Rationale |
|---|---|---|
| `exclude_exposure` | `true` | Body and rim damage recur because customers scrape cars, not because repairs fail. Grading a body shop on it would mark it down for something no workshop controls |
| Exposure source | **Derived** from `RepairSignatureClassifier::EXPOSURE_SIGNATURES` | Declaring it separately would let someone add an exposure signature and silently start grading it |
| `require_vehicle`, `require_date` | `true` | An event that cannot be placed on a car or in time cannot join a chain |
| **`ticket_scope`** | **`historical`** | Soft-deleted tickets are **included**. A retired ticket is a repair that really happened — the car really was off the road. Excluding it would let history rewrite itself whenever somebody tidied the board, and would move a denominator without its numerator |

---

## 7. Measurement vs judgement — the architectural boundary

> **The repository answers "what happened?".**
> **The domain services answer "what does it mean?".**

`RecurrenceStats` carries no score, no grade, no expectation and no verdict — asserted by a test.
This keeps case-mix from leaking into the measurement layer where every future consumer would
inherit it whether or not it applied.

### 7.1 Case-mix adjustment — governed here, applied in `GarageScorecardService`

**Method:** indirect standardisation.

```
expected_rate = Σ(n_domain × fleet_rate_domain) / Σ(n_domain)
garage ratio  = actual_rate / expected_rate
```

Structurally a Standardised Mortality Ratio. It exists because a garage's raw rate is mostly a
description of its **work mix**: oil services recur by schedule, so a shop that does nothing else
looks unreliable and a brake specialist looks excellent, for reasons neither controls.

**⚠ Documented limitation.** Indirect standardisation is rigorous for **garage vs fleet**. Comparing
two garages' ratios **to each other** is only approximate when their case-mixes differ materially —
the classic SMR limitation.

| Claim | Valid? |
|---|---|
| "This garage's repairs come back more than the fleet would on its mix" | **Yes** |
| "Garage A is better than Garage B" | **Only when their mixes are similar** |

**Consequence for the UI:** any surface comparing garages must display each garage's domain mix
beside its ratio.

### 7.2 Domain weighting — heuristic, deliberately unchanged

```
weight = |vs_fleet_pts| × min(1, √n / √(5 × min_sample.garage_domain))
```

Orders a garage's strengths and problems so that "0 comebacks in 21 jobs" does not outrank "30 points
better over 300 repairs". A crude empirical-Bayes shrinkage.

It is **not** a formal shrinkage estimator. Upgrading it is a candidate for a later PR, and it was
deliberately left untouched during convergence: changing an estimator at the same time as changing
the corpus would make it impossible to say which change moved the numbers.

---

## 8. Sample and coverage rules

### Minimum samples — stated in REAL repairs

| Scope | Floor |
|---|---:|
| Fleet | 30 |
| Garage | **30** |
| Garage × domain | 20 |
| Signature | 30 |
| Vehicle | 5 |
| Duration comparison | 10 |

Below the floor a surface reads **"not enough repairs"** — never a percentage, never a zero.

> **⚠ Do not lower a floor to restore the old count of scored garages.**
> Before deduplication, a garage floor of 30 was enforcing roughly 6 real repairs. That is why 63
> garages carried a score and only **33** clear the honest bar. Lowering the floor would re-import
> the bug as a setting.

### Coverage

Sample size answers *"is this enough?"*. Coverage answers *"enough **of what**?"*

| Term | Definition |
|---|---|
| `covered` | fully-observed events (`days_observed >= window_days`) |
| `total` | all deduplicated fault events |
| Confidence downgrade | coverage < 90% ⇒ `Kpi::confidence = partial` |

Every `Kpi` built from this metric carries coverage and `as_of`.

### Freshness

| Rule | Value |
|---|---|
| `as_of` | `MAX(occurred_at)` of the canonical dataset |
| Rebuild | `intelligence:rebuild-recurrence`, daily 04:35 |
| Stale after | 36 hours |
| On stale | **Surface the condition in the UI.** Never render stale analytics as current |

Convergence concentrates risk: one table now feeds every recurrence figure in the platform, so a dead
rebuild is a platform-wide silent failure rather than one stale page. That is the correct trade — a
consistently wrong number is detectable, six inconsistent ones are not — but it makes staleness
monitoring a release requirement.

---

## 9. Adjacent metrics that are NOT this metric

| Metric | Question | Why separate |
|---|---|---|
| `features.intelligence.comeback` (14 days) | "Is this car bouncing right now?" | An operator interrupt about one car, not a quality measure of a garage over history. **Being renamed to `rapid_repeat_visit` in its own PR** so "comeback" has exactly one meaning |
| `VehicleFaultRecurrenceService` | Repeat-fault chains from `maintenance_tasks` | Different corpus (Tier-1 capture), different grain |
| `parts_intelligence.recurrence` | "Was this same part fitted again?" | Different entity |
| `RecurringFaultService` | The human adjudication inbox | Workflow, not a metric |

---

## 10. Version history

### 2.0.0 — 2026-08-04 *(current)*

| | |
|---|---|
| Dataset | `fault_recurrence_pairs` |
| Deduplication | Yes — `(vehicle, signature, date)` |
| Horizon | `corpus_max − 90d` |
| **Fleet comeback** | **46.51%** (n = 10,595) |
| First-time-fix proxy | ≈53.5% |
| Garages scored at n≥30 | 33 |

**Reason for change:** one repair must count once, and a repair too recent to have failed must not
count as one that held. Six implementations had drifted to a 5.9-point spread on the same question.

### 1.0.0 — 2025-11-01 *(retired)*

| | |
|---|---|
| Dataset | `maintenance_signatures` (self-join) |
| Deduplication | No |
| Horizon | none — and varied by implementation |
| **Fleet comeback** | **40.63%** (n = 33,040) |
| Garages scored at n≥30 | 63 |

**Retired because:** it counted label rows as repairs (2.62× inflation), applied no right-censoring,
and had been reimplemented six times with three different horizons.

> Historical snapshots taken under 1.0.0 are **retained, not rewritten**. A baseline you delete is a
> baseline you cannot argue from. Every `kpi_snapshots` row is stamped with the metric version that
> produced it.

---

## 11. Changing this metric

1. Edit `backend/config/metrics/recurrence.php`.
2. **Bump `version`** and append to `change_history` with the reason and the before/after figures.
3. Run `php artisan intelligence:recurrence-compare` and attach the report to the PR.
4. Re-run `RecurrenceContractTest`, `RecurrenceSingleSourceTest` and the golden suite.
5. Re-snapshot: `php artisan kpi:snapshot --save --label=… --reason=…`
6. Update §10 of this document.

There is no such thing as a quiet tuning here. Every number in the contract moves a number somebody
makes a decision on.
