# Repair Intelligence & ETA Prediction — Architecture Blueprint

> Status: **DESIGN — decisions LOCKED 2026-07-28, ready for implementation** (no code yet). Author perspective: product architecture.
> Companion to and a concrete module of [[fleet-knowledge-engine-arch]]
> (`docs/Fleet-Knowledge-Engine-Architecture.md`). Reuses [[explainability-platform]],
> [[garage-recommendation-engine]], and the maintenance-reason categorization already in the sheet importer.
> Grounded in a read-only analysis of the live `maintenances` (`origin='sheet'`) history run 2026-07-28.

---

## 0. Where this sits

The Fleet Knowledge Engine (FKE) blueprint already locked the four-layer contract (L1 source → L2 query
services → L3 optional read models → L4 intelligence) and named a `CostDurationQueryService` and a
`GaragePerformanceQueryService` as future modules. **This document specifies the *duration half* of that
engine concretely**, because we now have the data analysis to design it against real numbers rather than
in the abstract. Nothing here contradicts the FKE contract; it fills it in.

The question this module answers, at the moment a fault is logged:

> *"Given this fault category, this garage, and this vehicle — based on our own repair history, how long
> will this probably take, when will it likely be ready, and how confident are we?"*

Plus a standing **Garage Intelligence** profile: how each workshop actually performs, per fault category,
over time.

---

## 1. Business goal and use cases

### Goal
Replace the current static `DEFAULT_REPAIR_DAYS = 4` placeholder and the manual, gut-feel
`expected_completion_date` promise with a **data-derived turnaround estimate** learned from ~5,600 real
historical repair cycles — and make each garage's real performance visible.

### Primary use cases
1. **ETA at fault creation / ticket open.** When a fault is logged (or a garage is assigned), pre-fill a
   *suggested* `expected_completion_date` with a range and confidence, instead of the flat 4-day default.
   The human still confirms — this is decision support, not automation.
2. **Garage selection support.** Alongside the existing rules-based `GarageRoutingService` and data-driven
   `GarageRecommendationService` (which optimize for *who to send it to*), show *how fast* each candidate
   garage historically closes **this** fault category.
3. **Garage Intelligence profiles.** A per-workshop scorecard: volume, average/median turnaround, per-category
   breakdown, fastest/slowest categories, and trend — for management review and vendor accountability.
4. **Checkpoint / delay context.** When a ticket is running late, compare elapsed time against the historical
   distribution for its cohort ("this repair is already at p90 for this category+garage").

### Explicit non-goals (V1)
- No ML model (see §11 for the evolution path — the interface is designed so ML slots in later).
- No hour-level precision — the source data is **day-granular** (see §3).
- No cost prediction here — that is `CostDurationQueryService`'s other half, out of scope for this doc.
- No new source-of-truth table. All read models are rebuildable projections (FKE principle #2).

---

## 2. Historical data source and quality assumptions

### Source
`maintenances` table, `origin='sheet'` rows — the imported **N-Maintenance & Repair log** (gid 400222171),
the project's established primary maintenance history ([[maintenance-data-architecture]]).
**Customer Cases** (`origin='customer-sheet'`) is **excluded**: it has no OUT date, so it cannot yield a
duration.

### Measured quality (read-only analysis, 2026-07-28)

| Field | Meaning | Coverage | Verdict |
|---|---|---|---|
| `out_date` | Repair start / "Fixed OUT Date" | 19,691 / 20,434 (96%) | ✅ Reliable start anchor |
| `actual_in_date` | Completion / "Actual IN Date" | 5,793 (28%) | ⚠️ The learning ceiling |
| `vehicle_id` | Plate → PlateResolver link | 19,949 (98%) | ✅ Reliable |
| `vendor_id` | Matched garage (clean key) | 5,444 / 5,663 of cycles | ✅ Use this, not text |
| `garage` (text) | Free-text workshop name | 5,445 | ⚠️ Polluted ("OFFICE PARKING", "Under Test") |
| `maintenance_reason_id` | 14-category fault classification | 2,812 (~38% of cycles) | ⚠️ Sparse — backfillable |

### The clean training set
Rows with **both** `out_date` and `actual_in_date`, a linked `vehicle_id`, and a non-negative,
≤120-day duration: **~5,638 repair cycles.** Only 25 rows had a negative duration (completion before
start — dropped). This is the substrate.

### Locked quality assumptions
- **A1 — Learn only from closed cycles.** The model trains on returned cars (those with `actual_in_date`).
  This is correct (they are the only *measured* repairs) but must be stated honestly: the engine models
  *completed* repair turnaround, not open-ended stalls.
- **A2 — Day granularity.** No time-of-day. Same-day fixes collapse to `0` days (~39% of cycles). Predictions
  and profiles are expressed in **days**, never hours.
- **A3 — `vendor_id` is the garage key**, not the free-text `garage` field. The text field contains
  non-garage holding states and must never be a grouping key.
- **A4 — Heavy right-skew is the defining property.** Across every dimension, p90 ≫ median (overall
  median 1d, mean 2.6d, p90 5d). **Therefore the engine reports median + upper percentile, never a bare
  mean.** A mean-only estimate would systematically over-promise on the common case and under-warn on the tail.
- **A5 — Outlier guard.** Durations > 120 days are treated as data artifacts (unclosed rows finally stamped)
  and excluded from aggregates.

---

## 3. Time-To-Fix calculation logic

```
time_to_fix_days( cycle ) = date_diff_days( out_date , actual_in_date )
```

Valid iff `out_date` and `actual_in_date` both present, `vehicle_id` present, and
`0 ≤ diff ≤ 120`. Sub-day repairs = `0` days (A2).

**Cohort statistics** (the unit the engine reasons about — never single rows):
- `n` — sample size (drives confidence)
- `median` — the headline estimate (skew-robust; A4)
- `p90` — the upper bound of the reported range
- `p25` (optional) — lower bound for a tighter "typical range"
- `mean`, `min`, `max` — diagnostic / profile display only, **not** used as the estimate

A cohort is any grouping: `(reason × vendor)`, `(reason)`, `(vendor)`, or fleet-wide.

---

## 4. Prediction cascade strategy

Because the specific two-factor cohort is **often too small to trust** — measured: of 188 distinct
`reason × garage` combinations, only **46 have n ≥ 10** and **21 have n ≥ 20** — the engine **cannot**
depend on the exact cell existing. It cascades to the most specific cohort that clears a minimum sample bar,
and records which level fired.

```
Level 1  reason × vendor          if n ≥ N1   (N1 = 8)     ← most specific, highest value
Level 2  reason (fleet-wide)      if n ≥ N2   (N2 = 15)    ← or vendor-wide, see tie rule
   2a    vendor (fleet-wide)      if n ≥ N2                 (used when the fault category is unknown)
Level 3  fleet baseline           always available          ← last resort, lowest confidence
```

**Level selection rules**
- Start at Level 1. If its cohort `n ≥ N1`, use it.
- Else fall to Level 2. Prefer **reason-only** over vendor-only when a fault category is known — the analysis
  shows fault category is the stronger, more stable factor (§6). Use vendor-only only when no category is
  resolvable.
- Else Level 3 (fleet baseline: median 1d / p90 5d today).

**Thresholds are configuration**, not magic numbers — `config/repair_intelligence.php` (`min_n.level1`,
`min_n.level2`), tunable as data grows without code changes. Initial values (N1=8, N2=15) are chosen from the
measured cohort-density table so Level 1 fires for the ~46 dense combinations and everything else degrades
gracefully.

**Output of a prediction** (uniform contract, mirrors FKE L4 shape):

```
{
  expected_days_median,          // e.g. 2
  range: { low, high },          // e.g. p25→p90  →  "usually 1–5 days"
  expected_completion_date,      // today + high (and/or + median), business-day aware later
  confidence,                    // high | medium | low   (§5)
  basis: {
     level,                      // 1 | 2 | 3
     cohort,                     // "Engine mechanical issue @ Deals On Wheels"
     sample_size,                // n behind the estimate
  },
  evidence,                      // sample of contributing past repairs (Explainability DAG)
}
```

---

## 5. Confidence scoring approach

Confidence is **not** a model probability — it is an honest statement of *how much history backs this
estimate and how tight that history is*. Two inputs, reusing the FKE `ConfidenceScorer` banding
(high / medium / low):

1. **Sample size of the firing cohort** (`n`). More real repairs → more trust.
2. **Dispersion** of that cohort — relative spread, e.g. `(p90 − median) / max(median, 1)` or IQR. A cohort
   that is tight (most repairs cluster) is more trustworthy than one with the same `n` but a fat tail.

Banding (initial, config-driven):

| Band | Condition |
|---|---|
| **High** | Level 1 fired **and** n ≥ 20 **and** low dispersion |
| **Medium** | Level 1 with 8 ≤ n < 20, **or** Level 2 with n ≥ 15 |
| **Low** | Level 3 fleet baseline, **or** any cohort with high dispersion |

The band is always shown *with its reason* ("Medium — based on 32 similar repairs at this garage"), never as
a bare label. This is the FKE non-negotiable: no point estimate is ever presented as fact.

---

## 6. Factor strength — what actually drives duration (evidence)

From the analysis, ranked by real predictive signal:

1. **Fault category — STRONG.** Median cleanly and stably ordered: Tires ~0d, Braking/Body/Mechanical/
   Electrical ~1d, Airbag/Steering/Interior/Engine-mech ~2–3d, Transmission ~4d, Oil/Coolant-mixing ~3d,
   Seatbelts ~16d (small n). This is the **primary factor**.
2. **Garage (`vendor_id`) — STRONG.** Median turnaround spans 0 → 8 days across workshops. **Secondary
   factor**, and clean via `vendor_id`.
3. **Vehicle make/model — WEAK. Deliberately excluded as a predictor.** Medians cluster at 1–3d regardless
   of model; the apparent spread is entirely outlier-driven (e.g. one edition showed mean 14.8d but
   **median 2d, p90 98d** — two stuck cars, not a pattern). Including it adds variance, not signal. Make/model
   is retained only as **display context** on the prediction card, never as a cohort key in V1.

This ranking is *why* the cascade is `reason × garage → reason → fleet`, and why reason-only beats
vendor-only at Level 2.

---

## 7. Garage Intelligence profile design

A per-`vendor_id` scorecard, computed from the same clean training set. Every field is a derived aggregate —
no manual data entry, fully rebuildable.

**Profile contents**
- **Volume** — total completed repair cycles (n).
- **Turnaround** — median, mean, p90 days overall.
- **Per-category breakdown** — for each fault category with n ≥ (min, config): median / p90 / n. This is the
  `reason × vendor` matrix, surfaced.
- **Fastest / slowest categories** — the category rows with lowest / highest median (with n guard so a single
  slow job doesn't crown a "slowest").
- **Trend** — turnaround grouped by `out_date` month/quarter, to show improving/degrading performance over
  time. Requires enough temporal spread per garage; shown only when n per period clears a floor.
- **Reliability caveat** — profiles for low-volume garages are labelled low-confidence, same banding as §5.

**Interpretation guardrails (documented on the page, not just in code)**
- A garage's overall average is **mix-dependent** — a shop that only does tires will look "fast" versus one
  that does transmissions. The **per-category** view is the fair comparison; the headline average is context.
- "OFFICE PARKING" / holding states are **not** garages and are excluded (A3).

---

## 8. Required backend services and data models

Following the FKE L1–L4 contract — **no new source-of-truth tables.**

### L2 — Query services (this is where the engine lives; read-only over L1)
- **`RepairDurationQueryService`** — computes cohort statistics (`n/median/p25/p90/mean`) for any grouping
  `(reason?, vendor?)` over the clean training set. Pure, unit-testable, the single place the time-to-fix
  definition (§3) lives. The FKE doc's `CostDurationQueryService` is this service's sibling / superset.
- **`GaragePerformanceQueryService`** (named in FKE) — builds the §7 profile from `RepairDurationQueryService`
  outputs.

### L4 — Intelligence
- **`RepairEtaPredictor`** implementing a `DurationEstimator` interface (V1 = `HistoricalEstimator`, the
  cascade of §4). ML `ModelEstimator` slots behind the same interface later (§12).
- Reuse **`ConfidenceScorer`** (FKE) for §5 banding.
- Reuse the **Explainability DAG** so a prediction traces to the exact past repairs it learned from.

### Categorization widening (data lever) — **auditable** backfill
> **CORRECTION (2026-07-28, verified by dry-run):** the anticipated widening does **not** exist. Measured:
> of 4,340 uncategorized rows carrying a MAIN value, **4,157 are `origin='customer-sheet'`** — the Customer
> Cases tab, which Time-To-Fix **excludes** (no OUT date, §2). `origin='sheet'` cycles are already categorized
> wherever their MAIN column is filled; the ~2,163 categorized-computable-cycle ceiling is set by **MAIN
> completeness on the N-Maintenance sheet**, not by an un-run matcher. Under the owner-locked matcher rules
> (PRIMARY = MAIN area vocab; REFINE = SUP exact only) the backfill matches **0** ETA-relevant rows.
> **Therefore the backfill is NOT part of the ETA build** — the engine relies on the cascade (Level-2/3 cover
> uncategorized cycles) instead. The command below is retained as a general, auditable categorizer, not a
> substrate widener.

The intended lever was: reuse the deterministic **`MaintenanceReasonMatcher`** to backfill
`maintenance_reason_id` on uncategorized cycles. Implemented as `repair-intel:backfill-reasons` with full
auditability (below). It remains available, but see the correction — it does not grow the ETA training set.

**Auditability constraint (LOCKED).** The backfill must **never silently overwrite historical truth.** Rules:
- **Only fill blanks.** Rows that already carry a `maintenance_reason_id` are left untouched — the backfill
  populates NULLs only, never re-categorizes an existing value.
- **Mark provenance.** Add a small `reason_source` marker column (values: `sheet` = came from the import,
  `backfill` = system-matched by this command, `manual` = human-set). Backfilled rows are stamped
  `reason_source='backfill'` with a `reason_matched_at` timestamp, so a system-derived category is always
  distinguishable from an original one. This is a **provenance flag on a derived field, not a new source of
  truth** — consistent with the FKE read-model doctrine.
- **Reversible & idempotent.** Because backfilled rows are tagged, the command can be re-run safely (skips
  already-tagged rows) and a `--rollback` can null only `reason_source='backfill'` rows — original data is
  never at risk.
- **Reported, not silent.** The command prints a summary (matched / unmatched / skipped-existing) and never
  guesses: a `service_main` the matcher can't map stays NULL rather than being forced into a bucket.

### L3 — Optional read model (only if latency demands it)
- **`garage_fault_duration_stats`** — a materialized projection keyed `(vendor_id, maintenance_reason_id)`
  holding `n/median/p90/updated_at`. **Introduce only when a measured query is too slow**, per FKE principle:
  a `truncate + rebuild` (an artisan command, e.g. `repair-intel:rebuild`) loses nothing. Litmus: fully
  regenerable ⇒ it's an index, not a business table. **Not built in V1** unless profiling requires it.

### New business facts that *would* earn real tables (later, per FKE)
Only human decisions not derivable from history: **`eta_prediction_feedback`** (was the suggested ETA
accepted / overridden, and the actual outcome) — the substrate for the §11 feedback loop. Built as part of R5/§11.

---

## 9. API design suggestions

Read-only, additive endpoints (align with existing Intelligence route conventions):

| Endpoint | Purpose |
|---|---|
| `GET /intelligence/repair-eta?reason_id=&vendor_id=&vehicle_id=` | The §4 prediction contract for a prospective fault. Drives the ETA suggestion at ticket open / garage assign. |
| `GET /intelligence/garage-profiles` | List of garage scorecards (volume, median, p90, confidence) for the intelligence dashboard. |
| `GET /intelligence/garage-profiles/{vendor_id}` | Full §7 profile: per-category matrix, fastest/slowest, trend. |
| `GET /intelligence/repair-duration/distribution?reason_id=&vendor_id=` | The raw cohort distribution (for charts / the checkpoint "already at p90" context). |

Gating: read endpoints under the existing maintenance/intelligence view permissions; nothing here mutates,
so no new write permission in V1. The prediction object is fully self-describing (`basis`, `confidence`,
`evidence`) so the frontend renders explanation without extra round-trips.

---

## 10. Integration with the FleetView maintenance workflow

- **At fault creation / ticket open** ([[maintenance-checkpoint-feature]] establishes
  `expected_completion_date` as the canonical promise): call `RepairEtaPredictor` to **suggest** the ETA and
  pre-fill the promise date with a range + confidence. The human confirms or overrides — the flat
  `DEFAULT_REPAIR_DAYS = 4` becomes the Level-3 fallback only.
- **At garage assignment** (alongside `GarageRecommendationService`): show each candidate garage's historical
  turnaround for *this* fault category, so "who" and "how fast" are decided together.
- **On the checkpoint / operations control center** ([[maintenance-operations-control-center]]): compare a
  running ticket's elapsed days against its cohort distribution to flag "running late vs. history."
- **Garage Intelligence page** under the Intelligence section: the §7 profiles, one per workshop.
- **Explainability**: every suggested ETA is a first-class explainable figure — clicking it shows the cohort,
  n, and sample past repairs, via the existing DAG. No number appears without a "why."
- **Honesty rule**: the suggestion is decision *support*. The workflow never auto-sets a promise the team
  didn't confirm, and every estimate carries its confidence band visibly.

### ETA recalculation points (when the prediction is (re)computed)
The ETA is not a one-shot value — it is refreshed at defined lifecycle events, because the inputs
(fault category, garage, state) change as the ticket moves. Each recalculation is **suggestive**: it
updates the *suggested* ETA and flags drift, but never silently rewrites a human-confirmed promise.

| # | Trigger | What changes / why recompute |
|---|---|---|
| **R1** | **Initial ticket creation** | First estimate. Garage may be unknown → typically Level-2 (reason-only) or Level-3 fleet baseline. Establishes the opening `expected_completion_date` suggestion. |
| **R2** | **Garage assignment / change** | The strongest refinement — now `vendor_id` is known, so the cascade can reach Level-1 (`reason × vendor`). Re-predict and re-suggest. If the garage changes mid-repair, re-anchor. |
| **R3** | **Major checkpoint change** | On a checkpoint that shifts status or `next_expected_date` ([[maintenance-checkpoint-feature]]): recompute *remaining* time vs. cohort and flag if the ticket has crossed p90 ("running late vs. history"). Informs, does not overwrite the human ETA. |
| **R4** | **Parts delay / waiting state** | Entering an awaiting-parts / paused state ([[maintenance-procurement-platform-arch]]) means historical repair-time no longer applies cleanly. **Pause the repair-duration clock**, mark the ETA as *blocked/parts-wait* rather than showing a falsely optimistic date, and resume the estimate when parts arrive. Never count parts-wait as repair speed. |
| **R5** | **Final completion** | No longer a prediction — capture the **actual** duration and feed the §11 feedback loop (predicted vs. actual). This closes the learning loop and, over time, sharpens every future R1–R4. |

**Invariant across R1–R5:** each recompute writes only the *suggested* ETA + its `basis`/`confidence`; the
canonical human-confirmed promise changes only when a person confirms it. Recalculations are logged so the
ETA's evolution is itself explainable.

---

## 11. Prediction feedback loop

The engine must be able to **grade itself**. At every completion (R5) we record what we predicted against
what actually happened, so prediction quality becomes measurable and the system is ready to improve — with or
without ML.

### What is captured (per completed ticket that received a prediction)
- `predicted_median_days`, `predicted_range_low`, `predicted_range_high` — what the engine said
- `predicted_level` (1/2/3) and `predicted_confidence` (high/med/low) — how it said it
- `cohort_key` + `sample_size` — the exact cohort the estimate came from
- `actual_duration_days` — measured `out/started → completion` (same §3 definition, calendar days per D2)
- `prediction_error_days` = `actual − predicted_median` (signed: positive = took longer than predicted)
- `within_range` (bool) — did actual land inside `[low, high]`? The honest headline metric for a range-based
  forecast (better than raw MAE alone, given the skew)
- `parts_wait_days` (if any) — so error attributable to parts-blocking is separable from repair-speed error

### Where it lives
A dedicated table **`eta_prediction_feedback`** — this is one of the few *genuine new business facts* (a
recorded prediction-vs-outcome event, not derivable from repair history), so per the FKE contract it **earns
a real table** (unlike the read models). One row per predicted ticket, written at completion.

### What it enables (progressively, no ML required to start)
1. **Accuracy dashboard** — MAE (mean abs error in days), `within_range` %, and bias (mean signed error)
   broken down **per level, per garage, per fault category**. Immediately answers "is the engine trustworthy,
   and where is it weakest?"
2. **Self-tuning thresholds & bands** — replace the hand-set N1/N2 (D4) and confidence cut-offs with values
   derived from measured error: if Level-1 at n=8 is no more accurate than Level-2, raise N1 from evidence.
3. **Model-ready substrate** — this table *is* the labelled training/eval set for a future `ModelEstimator`
   (§12), and the A/B yardstick it must beat. Building it now means the ML step later has history to learn from
   instead of starting cold.

### Non-negotiables
- **Read-only to the workflow.** Feedback is recorded and reported; it never mutates the original ticket.
- **Every prediction that is shown gets logged**, including Level-3 fallbacks — otherwise the accuracy picture
  is biased toward the easy cases.
- Feedback capture is **additive**: absence of the feedback table must not break prediction (the predictor
  works standalone; feedback observes it).

---

## 12. Future evolution path toward predictive ETA models

Staged, each step strictly additive and behind the `DurationEstimator` interface:

1. **V1 — HistoricalEstimator (this doc).** Cascade over cohort medians. Ship value now with zero ML.
2. **Widen the substrate.** Backfill categorization (§8), and as `actual_in_date` completeness grows with
   live workshop use, Level 1 coverage and confidence rise automatically — the aggregates *are* the learning
   loop, no retraining.
3. **Feedback capture (§11).** The `eta_prediction_feedback` loop turns the engine into a measurable system:
   track prediction error (MAE / within-range %) per level and per garage, and auto-tune the cascade
   thresholds and confidence bands from real accuracy instead of hand-set constants.
4. **Richer features, still structured.** Once volume supports it, extend cohorts with additional factors that
   prove predictive (severity, parts-required flag, season/load) — but only factors that *earn* their place by
   measured signal, the way make/model was *rejected* here.
5. **ModelEstimator (ML).** Swap a regression/quantile model behind the same interface, **A/B'd against the
   HistoricalEstimator baseline**. It ships only if it beats the transparent baseline on measured error, and it
   still emits the same `{prediction, range, confidence, evidence}` contract so nothing downstream changes.
6. **Live signals.** Eventually fold in real-time factors (garage current queue depth, parts-wait state from
   the procurement platform) to move from "historical average" toward "situational ETA."

The through-line: **transparent structured baseline first, ML only when it provably beats it, confidence and
explainability mandatory at every stage.**

---

## 13. LOCKED decisions (2026-07-28)

Confirmed with product owner. These are now the binding contract for V1 implementation. (The `Dn` labels
below unify the doc's original numbering with the review numbering to avoid ambiguity.)

| # | Decision | LOCKED resolution | Rationale |
|---|---|---|---|
| **D1** | Estimate & range shape | **Headline = `median`; range = `p25 → p90`.** Phrased as *"Typical: N days · usually finished before: M days."* | Data is heavily right-skewed (A4); mean over-promises. A single long stall (e.g. 60d) must not move the headline. |
| **D2** | Completion-date arithmetic (calendar vs. business days) | **Calendar days, including weekends & holidays.** No business-day exclusion. | The asset is physically off-road on Fridays/holidays too — downtime is downtime. Also matches the day-granular source. Resolves the old D2 **and** D5-business-calendar together. |
| **D3** | Fault-category backfill | **REVISED after implementation (2026-07-28) — backfill does NOT widen the ETA substrate; do not run it for ETA.** The auditable command (`repair-intel:backfill-reasons`) is built and kept, but see the correction below. | Dry-run finding: of 4,340 uncategorized rows with a MAIN value, 4,157 are `origin='customer-sheet'` (excluded from Time-To-Fix — no OUT date). `origin='sheet'` cycles are already categorized wherever MAIN exists; the ~2,163 ceiling is set by **MAIN completeness on the sheet**, which a matcher run cannot fix. Under the owner-locked matcher rules the command matches 0 ETA-relevant rows. |
| **D4** | Cascade thresholds N1 / N2 | **N1 = 8** (Level-1 `reason × vendor`), **N2 = 15** (Level-2 single-factor). Stored in `config/repair_intelligence.php`, tunable without code. | Chosen from the measured cohort-density table so Level-1 fires for the ~46 dense combos and everything else degrades gracefully. Re-tune from real prediction error once feedback (§11-3) exists. |
| **D5** | L3 read model (`garage_fault_duration_stats`) | **DEFER.** V1 computes live in `RepairDurationQueryService`. Introduce the materialized projection **later**, only when a profiled query is measurably slow. | FKE principle: read models are optional indexes added on demand, never upfront. Owner agrees: "yes, but later." |

**Weekend/holiday note (D2/D5):** because downtime counts calendar days, no business calendar is wired in
V1. If a future need arises to separate "shop working days" from "calendar downtime," it becomes a *second*
metric, not a change to this one.

### Implementation is now unblocked
With the above locked, the V1 build order is:
1. ~~Backfill command~~ — **DONE but de-scoped from ETA** (D3 correction): the `repair-intel:backfill-reasons`
   command + `reason_source`/`reason_matched_at` provenance columns are implemented and auditable, but the
   dry-run proved the backfill adds no ETA-relevant categorization. Substrate stays at the measured
   ~2,163 categorized cycles; the cascade covers the rest. **No blocker.**
2. `RepairDurationQueryService` (§3, §8) — the pure cohort-stats engine, unit-tested (D1, D2). ✅ **DONE
   2026-07-28** in `app/Services/Knowledge/` (reuses `RepairCohortStats::percentile`); `config/repair_intelligence.php`
   holds N1/N2 (D4); 6 unit tests pass; live-validated against the analysis (fleet median 1 / p90 5).
3. `RepairEtaPredictor` cascade (§4) + `ConfidenceScorer` reuse (§5) with N1/N2 from config (D4).
4. `GaragePerformanceQueryService` → garage profiles (§7).
5. Read-only API endpoints (§9).
6. Workflow integration: ETA suggestion at ticket-open / garage-assign (§10), plus the R1–R5 recalculation
   points and the `eta_prediction_feedback` loop (§11).

L3 read model (D5) is explicitly **out of V1** unless profiling forces it.
```
