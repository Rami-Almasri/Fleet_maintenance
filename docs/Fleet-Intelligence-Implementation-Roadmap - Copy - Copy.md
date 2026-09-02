# Fleet Intelligence Platform — Implementation Roadmap

**Companion to:** `docs/Fleet-Maintenance-Intelligence-Study.md`
**Date:** 2026-08-03
**Audience:** Development team
**Status:** Ready to implement. No code written yet.

Every readiness verdict below was re-verified against the live `laravel` database on 2026-08-03.
Where verification contradicted the study, the correction is stated explicitly in §0.1 rather than
quietly fixed — the team should know which numbers moved and why.

---

## 0. Verification Pass — What Changed Since the Study

I re-queried the fields the study rated on schema presence rather than measured content. Four
ratings were wrong. Three KPIs die; one gets significantly stronger.

### 0.1 Corrections

| # | Item | Study said | **Verified truth** | Impact |
|---|---|---|---|---|
| **C-1** | **Waiting for Approval (O4)** | MEDIUM — "column exists across full history" | **`approval_status` is `not_required` on all 26,942 rows. Zero variance. `approved_at` = 0 rows.** | **KILLED.** The column has no information content. See §9 kill list |
| **C-2** | **Delayed Repairs (O2) / SLA (O8)** | HIGH / MEDIUM via `expected_completion_date` | **`expected_completion_date` = 4 rows. `expected_duration_days` = 0 rows.** | Original formulation **KILLED**… |
| **C-3** | **…but `expected_return_date` rescues it** | not considered | **14,213 rows (2023-07 → 2026-07), avg promise 6.83 days, 5,169 with an actual return, 4,888 met = 94.6% SLA compliance.** 39 rows precede `out_date` and must be excluded | **UPGRADED to P0.** SLA is *more* buildable than the study claimed — across 3 years, not just the workflow era |
| **C-4** | **Downtime Cost (E8)** | MEDIUM — "verify `day_rent_value` before publishing" | **`day_rent_value` populated on 437/438 vehicles, mean AED 385/day** | **UPGRADED to HIGH readiness** |
| **C-5** | **Visit collapse (QW9)** | flagged as a risk | **Quantified: of 8,523 (vehicle, garage, out_date) groups only 1,970 (23%) are single-row.** 4,030 groups have 2 rows; the tail reaches 12+ | Confirms QW9 is a **hard prerequisite**, not a nicety |
| **C-6** | **`fault_severity`** | "workflow-era" | **68 rows of 26,942** | Urgent-repair triage must use `condition_grade` instead |
| **C-7** | **`linked_contract_id`** | assumed usable | **2 rows** | Maintenance↔contract joins must use vehicle + date-window logic, never the FK |
| **C-8** | **`follow_date`** | not considered | **26,666 rows (99%)** | New delay signal available across full history — feeds O2 |

### 0.2 Standing data facts (verified, cite these in code comments)

```
maintenances          26,942 live   out_date 2023-06-05 → 2026-08-03
  vendor_id  93.5%    vehicle_id 97.6%   out_date 94.9%
  actual_in_date 25.7%   cost 1.3%   workflow_status 0.9%
  expected_return_date 52.8%   follow_date 99.0%
vehicle_expenses      28,327        2014 → 2027 (ledger effectively ends 2026-03-31)
  repair categories ≈ 13,285 rows / AED 6.20M   vehicle_id NULL on 5,128 (18.1%)
maintenance_signatures 49,487       21,125 tickets labelled (78.4%)   20 signatures
contracts             39,701        out_milage 99.9% / in_milage 96.2% / days 91.3%
vehicles                 438        day_rent_value 437 · purchase_price 237 · vehicle_class 0
vendors                  463        247 garages (incl. 3,916 tickets on pseudo-garages)
part_purchases         3,485        → 10 real (3,475 = VehicleComponentDemoSeeder, 2026-08-02)
vehicle_components     3,477        → 2 real
maintenance_tasks        117    line_items 88    repair_inspections 34    inspection_records 180
```

---

## 0.3 Roadmap Conventions

**Data Readiness** — the honest answer to "can we build this today?"

| Grade | Meaning |
|---|---|
| **READY** | All required fields ≥90% populated on the relevant scope. Ship with no caveat. |
| **READY\*** | Buildable now, but a stated caveat must appear in the UI (sample size, staleness, coverage). |
| **PARTIAL** | Buildable on a subset only. Coverage must be displayed alongside the number. |
| **BLOCKED** | Requires a listed prerequisite (normalisation, backfill, new column) before it is correct. |
| **NOT VIABLE** | The data does not exist or has no variance. Do not build. See §9. |

**Priority** — P0 must-have · P1 high value · P2 nice to have · P3 future/needs more data.

**Complexity** — Small ≤2 days · Medium 3–8 days · Large >8 days (one engineer, incl. tests + UI).

**Work type** — SQL · Backend (service/aggregation/caching) · Capture (new data entry) · ML.

**Governing rules this roadmap inherits from the project:**
- Every raw `maintenances` query declares LIVE (`deleted_at IS NULL`) or HISTORICAL.
- Every fault query filters `is_exposure = 0`.
- Every page shows Data Origin — no black boxes.
- Every field is labelled Fact | Judgement | Derived.
- No field ships without a consumer.
- Engine emits reason **codes** + params; the UI renders plain operational language.

---

# PHASE 1 — Foundation

**Goal.** Nothing user-facing ships in this phase. It exists so that everything after it is correct,
fast, and consistent. Skipping it means every later phase re-implements the same joins slightly
differently and produces numbers that disagree with each other — which is how analytics platforms
lose executive trust permanently.

**Duration:** ~2 weeks · **Blocks:** Phases 2–8 entirely.

---

### F1 · Vehicle Master Normalisation
**Priority P0 · Small · Backend + Capture · READY (manual mapping)**

- **Required:** `vehicles.make`, `.model`, `.year`, `.category`, `.sheet_category`, `.vehicle_class`
- **Problem:** `make` holds models and junk — `CERATO` (14), `SPORTAGE` (11), `00KIA` (9),
  `PATROL` (9), `ACCENT` (9), `00SUNNY`, `LEXUS-300`, `CH`, `FASTER`. `vehicle_class` is 0/438.
  Kia's true count is ~60 across 6 spellings, reported as 18.
- **Deliverable:** a `vehicle_model_map` reference table (raw_make, raw_model → canonical_make,
  canonical_model, segment, body_type) plus `vehicles.vehicle_class` populated. 438 rows; a person
  can do this in a day with a spreadsheet and a review.
- **Assumption:** the mapping is a **Judgement**, human-authored and versioned. It must be editable
  in the UI, not hardcoded.
- **Dependencies:** none. **This is the single highest-leverage task in the roadmap.**
- **Unblocks:** E2, E3, C2, F7, and every "by model" filter in the platform.

### F2 · Visit Collapse (Event → Repair Visit)
**Priority P0 · Medium · Backend · READY**

- **Required:** `maintenances.vehicle_id`, `.vendor_id`, `.out_date`, `.actual_in_date`,
  `.event_status`, `.origin`
- **Problem:** the legacy sheet modelled one workshop visit as several rows —
  `OUT` (7,166) → `Follow up` (7,732) → `IN` (6,893) → `Change` (3,000). Verified: only **23%** of
  (vehicle, garage, out_date) groups are single-row; 4,030 groups have exactly 2 rows and the tail
  reaches 12+. Counting rows as repairs **overstates repair volume by roughly 2×**.
- **Deliverable:** a materialised `repair_visits` table — one row per visit with `visit_id`,
  `vehicle_id`, `vendor_id`, `started_at`, `ended_at`, `event_row_count`, `signature_set`,
  `origin_mix`. Grouping rule: same vehicle + same garage, rows chained while gaps ≤ N days
  (start with N=3, tune against `event_status` transitions).
- **Assumption:** the grouping window is a **Derived** judgement. Publish it; make it configurable.
- **Dependencies:** none. **Every per-repair metric in the platform depends on this.**

### F3 · Garage Registry Hygiene
**Priority P0 · Small · Backend + Capture · READY**

- **Required:** `vendors.id`, `.name`, `.type`, `.active`
- **Problem 1 — pseudo-garages:** `OFFICE PARKING` (2,738 tickets), `Under Test` (542), generic
  `Garage` (636) = **3,916 tickets** on entities that are locations or states, not repair shops.
  Left in, they top the "fastest repairs" leaderboard.
- **Problem 2 — duplicates:** `Qasr al zaiton` (146) and `kasr al zaiton` (162) are near-certainly
  one shop with 308 combined tickets. A full-name review of 247 garages is required.
- **Deliverable:** `vendors.is_analytical` flag + a `vendor_aliases` merge table; an exclusion list
  visible on the Garage Intelligence page (not hidden in code).
- **Dependencies:** none. **Blocks all of Phase 4.**

### F4 · Recurrence Pair Materialisation
**Priority P0 · Medium · Backend · READY**

- **Required:** `maintenance_signatures.vehicle_id`, `.signature`, `.occurred_at`, `.is_exposure`,
  `.source`; `maintenances.vendor_id`, `.deleted_at`
- **Deliverable:** `fault_recurrence_pairs` — one row per (vehicle, signature) occurrence with
  `first_ticket_id`, `first_vendor_id`, `first_date`, `next_date`, `days_to_return`,
  `returned_within_30/60/90`, `label_source` (derived|human).
- **Why materialise:** the live query is a self-join over 49,487 rows with a correlated MIN — it is
  the hottest query in the platform and it powers 8 KPIs. Refresh nightly.
- **Caveat to carry forward:** this measure is **exposure-confounded**. The table stores raw pairs;
  the *adjustment* (repairs-at-risk denominator, category matching) belongs in the metric layer, not
  here. Store facts; judge later.
- **Dependencies:** F3 (so pseudo-garages are flagged, not deleted).
- **Unblocks:** G1, G2, G3, E6, F5, F6, 4.3, and most of Phase 8.

### F5 · Shared Metric Layer & KPI Framework
**Priority P0 · Medium · Backend · READY**

- **Deliverable:** one service that every dashboard calls, exposing each KPI as
  `{ code, label, value, unit, direction, sample_size, coverage_pct, as_of, confidence_grade,
  method_note, evidence_query_id }`.
- **Non-negotiable behaviours:**
  - **Minimum sample gate.** Below n=30, return `insufficient_sample`, never a number. The existing
    `OperationalKpiService` already does this — extend it, do not duplicate it.
  - **No silent imputation.** If a composite term is unavailable, renormalise the remaining weights
    and report which terms were used.
  - **Median + p90 alongside every mean.** Turnaround is heavily skewed (range −110 to +113 days).
- **Dependencies:** none. **Everything downstream calls this.**
- **Opinion:** the biggest risk to this project is not a missing KPI, it is two pages showing
  different values for "average repair duration." This layer is the insurance policy.

### F6 · Shared Filter Contract
**Priority P0 · Small · Backend + Frontend · READY**

- **Canonical filters:** date range (with named presets), garage (excl. pseudo by default),
  vehicle, make/model (post-F1), fault signature/category, vehicle status, condition grade,
  origin (`sheet` | `customer-sheet` | `manual`), minimum sample size.
- **Rule:** filters serialise into the URL so any view is shareable — an executive forwarding a link
  to Adham must land on the identical numbers.
- **Dependencies:** F1, F3.

### F7 · Date & Period Handling
**Priority P0 · Small · Backend · READY**

- **Rules to encode once:**
  - Event clock is `out_date` (94.9%). Never `created_at` — that is import time, not event time.
  - **Exclude 31 tickets where `actual_in_date < out_date`**, and 39 where
    `expected_return_date < out_date`. Log exclusions; never silently drop.
  - 1,385 rows have no `out_date` → an explicit "undated" bucket, never dropped from totals.
  - Future dates in `vehicle_expenses` (to 2027-09) are **legitimate** — amortised service
    contracts. Do not filter them out as errors.
  - Fleet size changed 129 → 186 vehicles (2023→2025). **Every volume trend must normalise per
    vehicle or per 1,000 rental days,** or growth reads as deterioration.

### F8 · Data Quality & Freshness Warnings
**Priority P0 · Small · Backend + Frontend · READY**

- **Deliverable:** a reusable confidence badge + the Data Health page (study §Phase 11.10).
- **Must surface:** financial as-of `2026-03-31`; `actual_in_date` coverage 25.7%; expense
  `vehicle_id` coverage 81.9%; `purchase_price` coverage 54%; signature coverage 78.4%; synthetic-row
  quarantine (3,475 part rows, 3,475 components, 4,544 log events); the garage exclusion list.
- **Opinion:** ship this **first**, before any dashboard. The moment Basem finds one number he
  cannot trust, he stops trusting all of them. A visible limitation is a credibility asset; a hidden
  one is a time bomb.

### F9 · Synthetic Data Quarantine
**Priority P0 · Small · Backend · READY**

- **Required:** `vehicle_components.write_mode='seed'`, `part_purchases.notes` MARKER,
  `component_events.meta`
- **Deliverable:** a global query-layer filter excluding demo rows from all analytics, plus a
  one-line banner wherever the affected tables appear.
- **Dependencies:** none. **Blocks Phase 7 and corrupts `vehicle_log_events` counts until done.**

### F10 · Expense Orphan Recovery
**Priority P1 · Small · Backend · READY**

- **Required:** `vehicle_expenses.car_serial` → `vehicles.car_serial`
- **5,128 rows (18.1%)** carry `car_serial` but never resolved to `vehicle_id`. Recovering them adds
  ~18% coverage to every per-vehicle cost figure at trivial cost.
- **Dependencies:** none. Run before Phase 7 (financial).

---

# PHASE 2 — Executive Dashboard (Basem)

**Duration:** ~2 weeks, but **scheduled last** in build order (see §11) because it assembles metrics
produced by Phases 3–7. Specified here to match the study's numbering.

| ID | KPI | Readiness | Priority | Complexity | Work | Depends on |
|---|---|---|---|---|---|---|
| E1 | Total Spend & Trend | READY* (as-of 2026-03) | P0 | Small | SQL | F7, F8 |
| E2 | Cost per Vehicle Model | BLOCKED → READY* | P0 | Medium | SQL+Backend | **F1**, F10 |
| E3 | Failure Rate by Model | BLOCKED → READY | P0 | Medium | SQL+Backend | **F1**, F2 |
| E4 | Worst-Performing Vehicles | READY | P0 | Small | SQL | F2 |
| E5 | Garage Quality Leaderboard | READY | P0 | Medium | Backend | Phase 4 |
| E6 | Repeat Repair Rate by Garage | READY | P0 | Small | SQL | **F4** |
| E7 | Fleet Availability | PARTIAL (25.7%) | P1 | Medium | Backend | F2, F5 |
| E8 | Fleet Downtime Cost | PARTIAL → **upgraded** | P1 | Medium | Backend | F2, C-4 |
| E9 | Repair Cost by Category | READY* | P0 | Small | SQL | F7, F8 |
| E10 | Top Recurring Failures | READY | P0 | Small | SQL | — |
| E11 | Declining Health Vehicles | READY | P1 | Medium | Backend | Phase 5 |
| E12 | Replacement Recommendation | PARTIAL (54%) | P1 | Medium | Backend | Phase 5, purchase-price backfill |
| E13 | Annual Savings Opportunity | PARTIAL | P2 | Medium | Backend | E6, G17, E8 |

### Selected cards

**E2 · Cost per Vehicle Model** — P0 · Medium · BLOCKED on F1
- **Tables/columns:** `vehicle_expenses(vehicle_id, amount, category, entry_date)` ·
  `vehicles(id, make, model, year)` + `vehicle_model_map` · `contracts(vehicle_id, days)`
- **Formula:** `Σ repair-category spend per canonical model ÷ Σ rental days × 1000`
- **Assumptions/limits:** normalise per 1,000 rental days, never per vehicle — fleet counts per model
  range 1 to 53 and raw averages will be dominated by singletons. Financials stale after 2026-03.
  18% of expense rows unlinked until F10.
- **Visualisation:** horizontal bar ranked by AED per 1,000 rental days, with fleet count as a
  secondary bar (sample credibility). Grey out models with <3 vehicles.
- **Filters:** period, canonical make, model, year band, expense category, min fleet count
- **Drill-down:** model → vehicle list (cost, visits, downtime, health) → vehicle profile → ticket →
  the individual expense rows with their `remarks` text

**E4 · Worst-Performing Vehicles** — P0 · Small · READY
- **Tables/columns:** `repair_visits` (from F2) · `maintenances(vehicle_id, out_date,
  actual_in_date)` · `vehicles(plate_no, make, model)` · `maintenance_signatures(signature)`
- **Formula:** composite rank over visits per 1,000 rental days, downtime days, distinct fault
  signatures, and spend where available.
- **Verified examples:** plate 60385 (Jeep Grand Cherokee SRT) — 149 events since 2025-08, 4.7 days
  average; plate 26831 — 90 events at **24.2 days** average downtime.
- **Note:** post-F2 these event counts will roughly halve. That is the correct number, and the team
  should expect the change rather than treat it as a regression.
- **Visualisation:** ranked table with per-vehicle sparkline; severity colour on the composite.
- **Drill-down:** → Vehicle Intelligence profile (Phase 5).

**E13 · Annual Savings Opportunity** — P2 · Medium · PARTIAL
- **Opinion: build this last and label it an estimate with a range.** It is the most persuasive tile
  on the page and therefore the one most likely to be quoted back at us. Composed of G17 (cost of
  rework), above-benchmark garage cost on the unambiguous 20% subset, and excess downtime × day rate.
  Every component carries a caveat; the composite carries all of them.

**Layout.** Row 1 — spend, availability, open repairs, savings estimate. Row 2 — model scatter
(failures/1,000 rental days × AED/1,000 rental days, bubble = fleet count; the top-right quadrant is
the disposal shortlist). Row 3 — garage leaderboard + worst-20 table. Row 4 — AI Insight cards.

---

# PHASE 3 — Operations Dashboard (Adham)

**Duration:** ~2 weeks · **Build order: 3rd.**

**Two-tier labelling is mandatory.** Only 252 tickets carry `workflow_status`; 26,942 carry
`out_date`. Every tile is badged *Workflow-era* or *Full-history*. Mixing them silently is the
fastest way to make Adham distrust the page.

| ID | Tile | Readiness | Priority | Complexity | Work | Note |
|---|---|---|---|---|---|---|
| O1 | Open Repairs | READY* | P0 | Small | SQL | Full-history proxy over-counts (sheet rows never closed) |
| O2 | Delayed Repairs | **READY** (revised) | P0 | Small | SQL | **Use `expected_return_date` (14,213) + `follow_date` (26,666)** |
| O3 | Waiting for Parts | NOT VIABLE | P3 | — | Capture | 18 `part_requests`. §9 |
| **O4** | **Waiting for Approval** | **NOT VIABLE** | — | — | Capture | **`approval_status` 100% constant. §9** |
| O5 | Waiting for Driver | READY* (n=5) | P2 | Small | SQL | Real but tiny |
| O6 | Pending Reviews | READY | P0 | Small | SQL | **140 tickets — the largest live blockage** |
| O7 | Under Repair / In Transit | READY* (n=7) | P1 | Small | SQL | |
| O8 | Repair SLA Compliance | **READY** (revised) | P0 | Medium | Backend | **94.6% on n=5,169 — see below** |
| O9 | Average Repair Duration | READY* (25.7%) | P0 | Small | SQL | Median + p90 mandatory |
| O10 | Garage Workload | READY | P1 | Medium | Backend | |
| O11 | Vehicle Status Distribution | READY | P0 | Small | SQL | 438 rows, complete |
| O12 | Inspection Backlog | PARTIAL | P2 | Medium | Backend | `damage_flagged` 0/180 |
| O13 | Urgent Repairs | PARTIAL (revised) | P1 | Medium | Backend | **Use `condition_grade`, not `fault_severity` (68 rows)** |
| O14 | Daily Queue / Weekly Trends | READY | P1 | Medium | Backend | |
| **O15** | **Ticket Closure Rate** *(new)* | READY | **P0** | Small | SQL | **The highest-leverage tile on the page** |

### Selected cards

**O2 · Delayed Repairs** — P0 · Small · READY *(rebuilt after C-2/C-3)*
- **Tables/columns:** `maintenances(out_date, expected_return_date, actual_in_date, follow_date,
  workflow_status, vendor_id, vehicle_id)`
- **Formula:** open **and** `CURDATE() > expected_return_date`. Secondary signal: `follow_date`
  passed without a status change (99% coverage — works across the full history).
- **Limits:** `expected_return_date` covers 52.8% of tickets; exclude the 39 rows preceding
  `out_date`. Tickets without a promise date cannot be judged late — bucket them as
  "no commitment recorded," which is itself a finding worth showing Adham.
- **Visualisation:** table sorted by days-overdue, colour-ramped; count tile on top.
- **Drill-down:** ticket → vehicle → garage history → that garage's on-time rate.

**O8 · Repair SLA Compliance** — P0 · Medium · READY *(upgraded from the study)*
- **Formula:** `COUNT(actual_in_date <= expected_return_date) ÷ COUNT(both dates present)`
- **Verified baseline: 4,888 / 5,169 = 94.6%, average promise 6.83 days, spanning 2023-07 → 2026-07.**
- **Opinion:** this is a far better metric than the study proposed, and it is available across three
  years rather than 252 workflow tickets. But 94.6% is suspiciously high — it almost certainly
  reflects that promise dates were set generously (6.83 days against a 2.53-day actual mean). Ship
  it **paired with the promise-vs-actual gap**, otherwise it is a vanity metric. The interesting
  number is not "did we hit the date" but "how much slack did we build in."
- **Visualisation:** gauge + trend, with a promise-vs-actual distribution histogram beside it.
- **Filters:** garage, fault category, period, origin.

**O15 · Ticket Closure Rate** *(new — not in the original brief)* — P0 · Small · READY
- **Formula:** share of tickets opened in month M that ever received an `actual_in_date`.
- **Current value: 25.7% overall.**
- **Why P0:** this single number gates the accuracy of E7, E8, O9, G4, and the vehicle downtime
  metrics. It is entirely within Adham's control and requires no engineering to improve — only
  discipline. **Putting it on his screen is the cheapest accuracy improvement available to this
  project.**
- **Visualisation:** trend line with a target band; drill-down to the list of never-closed tickets.

---

# PHASE 4 — Garage Intelligence ★

**Duration:** ~3 weeks · **Build order: 2nd — immediately after Foundation.**

**This is the flagship.** It has the best data (`vendor_id` 93.5% across 26,942 tickets and 3 years),
the highest decision value, and it is the module that will convince Basem the platform is worth
funding. Build it before anything else user-facing.

**Three corrections are mandatory before any garage is ranked** (from F3 and the study §6.0):
exclude pseudo-garages (3,916 tickets), compare **within fault category only**, and require
**n ≥ 30 per garage × category** before showing a score.

| ID | Metric | Readiness | Priority | Complexity | Work | Depends |
|---|---|---|---|---|---|---|
| G1 | Repeat Repair % | READY | P0 | Small | SQL | F4 |
| G2 | Avg Time Until Same Fault Returns | READY | P0 | Small | SQL | F4 |
| G3 | Avg Time Until Any Fault Returns | READY | P0 | Small | SQL | F2, F4 |
| G4 | Average Repair Time | PARTIAL (25.7%) | P1 | Small | SQL | F2, F7 |
| G5 | Average Repair Cost | PARTIAL (20%) | P2 | Large | Backend | Phase 7 bridge |
| G6 | Cost vs Fleet Average | PARTIAL (20%) | P2 | Large | Backend | Phase 7 bridge |
| G7 | Warranty Return Rate | BLOCKED | P2 | Small | Capture | `vendors.warranty_days` — one column |
| G8 | Repair Quality Index | READY* | P0 | Medium | Backend | G1–G4, F5 |
| G9 | Experience by Fault Category | READY | P0 | Medium | Backend | F4 |
| G10 | Parts Usage | NOT VIABLE | P3 | — | Capture | §9 |
| G11 | Technician Performance | NOT VIABLE | P3 | — | Capture | §9 |
| G12 | Approval Delays | NOT VIABLE | — | — | Capture | §9 — dies with O4 |
| G13 | Customer Return Rate | READY | P1 | Small | SQL | F2 |
| G14 | Trend Over Time | READY | P1 | Medium | Backend | G8 |
| G15 | Benchmark vs Peers | READY | P1 | Medium | Backend | G8, F5 |
| **G16** | **Garage × Fault Matrix** | READY | **P0** | Medium | Backend | G9 |
| **G17** | **Cost of Rework** | READY* | **P0** | Medium | Backend | G1, E9 |

### Selected cards

**G2 · Average Time Until Same Fault Returns** — P0 · Small · READY
- **Tables/columns:** `fault_recurrence_pairs` (F4) ← `maintenance_signatures(vehicle_id, signature,
  occurred_at, is_exposure, source)` · `maintenances(vendor_id, deleted_at)` · `vendors(id, name)`
- **Formula:** mean `days_to_return` per garage, per signature, `is_exposure = 0`, n ≥ 30.
- **Verified live output** (⚠ corrected 2026-08-03 — deduplicated; the earlier figures counted
  duplicate label rows, see Execution Plan §0.1): Deals On Wheels **55.0d** (n=473) · FUTURE TYRES
  **122.3d** (n=1,126) · POWER POINT **124.8d** (n=1,605) · GPT GARRAGE **131.0d** (n=719) · RMR
  **144.6d** (n=592) · HOT LINE **148.8d** (n=420). Alresala al zahabia shows 19.6d but at **n=29 —
  below the n≥30 gate**, so it renders as "not enough data", not as a score.
- **Assumptions and limits — state these in the UI:**
  1. **Exposure confound.** A garage seeing a car often shows shorter intervals by construction. Use
     repairs-at-risk as the denominator, not calendar time.
  2. **Specialisation confound.** Tyre shops see tyres return naturally. Category-matched comparison
     only.
  3. **System-level granularity.** `BRAKES` conflates noise, pads, discs, ABS. We can say "brake work
     returned in 18 days," not "brake noise returned in 18 days," until `fault_catalog` tagging
     accumulates volume.
  4. **Attribution.** Recurrence is attributed to the garage that did the *first* repair. A car sent
     elsewhere in between is not excluded — a known limitation of the current model.
- **Visualisation:** dumbbell chart — garage marker vs fleet-mean marker, one row per garage, faceted
  by fault category. Sample size printed on every row.
- **Filters:** fault category, period, min sample (default 30), exclude pseudo-garages (default on),
  label source (derived | human | both).
- **Drill-down:** garage → fault category → **the actual recurrence pairs shown side by side** (first
  ticket date, garage, fault | return ticket date, garage, fault | days between) → vehicle profile.
  This side-by-side pair view is the evidence that makes the metric defensible when challenged.

**G16 · Garage × Fault-Category Matrix** — P0 · Medium · READY
- **What it is:** garages down the rows, the 20 fault signatures across the columns; cell colour =
  quality score, cell size = volume, blank = insufficient sample.
- **Opinion: this is the single most useful screen in the entire platform for daily decisions.** It
  answers "where do I send a car with an AC fault?" in one glance, with evidence. Prioritise it over
  the composite quality score — a manager acts on the matrix, not on a leaderboard number.
- **Drill-down:** cell → ticket list for that garage × fault → recurrence pairs.

**G17 · Cost of Rework** — P0 · Medium · READY*
- **Formula:** `recurrences within 30 days × fleet-average cost for that fault category`
- **Why it matters:** it converts quality into money **without** needing per-ticket cost attribution
  — it uses category averages from `vehicle_expenses`, sidestepping the missing join entirely. It is
  the most persuasive garage number available to us today.
- **Limit:** category averages, not actual costs. Label it "estimated rework cost." Do not present it
  as billed spend.

**G8 · Repair Quality Index** — P0 · Medium · READY*
```
Quality = 100 − 35×norm(recurrence_30d) − 20×norm(recurrence_90d)
              − 15×norm(median_turnaround) − 15×norm(reinspection_failure)
              − 15×norm(cost_index)
```
- **Readiness by term:** recurrence 30d/90d **READY** (55% of weight) · turnaround **PARTIAL**
  (15%) · reinspection failure **PARTIAL** — only 34 `repair_inspections` rows (15%) · cost index
  **PARTIAL, 20% coverage** (15%).
- **Rule:** when a term is unavailable, **renormalise the remaining weights and display which terms
  were used.** For most garages today the score will be built from the recurrence terms alone —
  which is honest and still valuable.
- **Opinion:** ship G1, G2 and G16 first as raw metrics. Add the composite only once the team is
  confident in the exposure adjustment. A wrong composite score is harder to walk back than a raw
  number, because it looks authoritative.

---

# PHASE 5 — Vehicle Intelligence

**Duration:** ~2 weeks · **Build order: 4th.**

| ID | Metric | Readiness | Priority | Complexity | Work | Depends |
|---|---|---|---|---|---|---|
| V1 | Vehicle Health Score | READY | P0 | Medium | Backend | F1, F2, F4, F5 |
| V2 | Repair Frequency | READY | P0 | Small | SQL | F2 |
| V3 | Repairs per Month | READY | P0 | Small | SQL | F2 |
| V4 | Maintenance Timeline | READY | P0 | Medium | Backend | F2, F9 |
| V5 | Recurring Faults | READY | P0 | Small | SQL | F4 |
| V6 | Downtime Days / Ratio | PARTIAL (25.7%) | P1 | Medium | Backend | F2 |
| V7 | Repair Cost (12m) | PARTIAL | P1 | Small | SQL | F10, Phase 7 |
| V8 | Cost per Kilometre | READY* | P1 | Medium | Backend | **`contracts` mileage, not `maintenances`** |
| V9 | Cost per Rental Day | READY* | P1 | Small | SQL | Phase 7 |
| V10 | Vehicle Risk Score | READY* | P1 | Medium | Backend | V1, F4 |
| V11 | Warranty Returns | READY* (383/438) | P1 | Medium | Backend | — |
| V12 | Replacement Recommendation | PARTIAL (54%) | P1 | Medium | Backend | V1, V7, price backfill |
| V13 | Most Replaced Parts | NOT VIABLE | P3 | — | Capture | §9 |
| V14 | Most Expensive Repairs | PARTIAL (3.6%) | P2 | Large | Backend | Phase 7 bridge |
| V15 | Expected Future Failures | NOT VIABLE | P3 | — | ML | §9 |

**V1 · Vehicle Health Score** — P0 · Medium · READY
- **Tables/columns:** `repair_visits` · `maintenance_signatures(vehicle_id, signature, occurred_at,
  is_exposure)` · `fault_recurrence_pairs` · `contracts(vehicle_id, days, out_date, in_date)` ·
  `vehicles(odometer, year, purchase_date)`
```
Health = 100 − 25×norm(repair_frequency)   -- visits per 1,000 rental days
             − 20×norm(recurrence_rate)     -- faults recurring within 90d
             − 20×norm(downtime_ratio)      -- shop days ÷ (shop + rental days)
             − 15×norm(fault_breadth)       -- distinct signatures, trailing 12m
             − 10×norm(severity_load)
             − 10×norm(age_mileage)
```
- **`norm(x)` = percentile rank within our own fleet**, so the score is relative to our reality, not
  to an industry constant we cannot defend.
- **Deliberate design choice: cost is NOT in the score.** Cost coverage varies by vehicle (18% of
  expense rows unlinked, ledger stale), so including it would make scores incomparable between
  vehicles. Show cost *beside* the score, not inside it.
- **Limits:** `downtime_ratio` inherits the 25.7% closure rate; `severity_load` is thin
  (`fault_severity` 68 rows) — derive severity from the signature's fleet-wide downtime/recurrence
  profile instead of the column.
- **Traceability requirement: publish the six components, always.** A gauge with no breakdown
  violates the project's no-black-boxes rule and will not survive a challenge from Basem.
- **Visualisation:** gauge + horizontal component-contribution bars + 12-month trend.
- **Filters:** model, age band, health band, status, condition grade.
- **Drill-down:** component → the tickets that produced it → the fault pairs → the garages involved.

**V8 · Cost per Kilometre** — P1 · Medium · READY*
- **Critical implementation note:** mileage must come from `contracts(out_milage, in_milage)` —
  99.9% / 96.2% populated. The odometer columns on `maintenances` are **effectively empty**
  (`intake_odometer` 4 rows, `report_odometer` 26, `receive_odometer` 57). Any engineer reaching for
  the obvious-looking column will build a broken metric.
- **Opinion:** this is the fairest cross-model comparison we can compute and it is underrated in the
  original brief. A Patrol and a Picanto are not comparable on absolute spend; they are comparable on
  AED per kilometre.

**V12 · Replacement Recommendation** — P1 · Medium · PARTIAL
- **Rules:** trailing-12m spend > 40% of purchase price **OR** downtime ratio > 20% **OR** visits per
  1,000 rental days above the 90th percentile — and worsening across two consecutive quarters.
- **Hard limit: `purchase_price` on 237/438 vehicles.** For the other 201 the economic rule cannot
  fire. **Show "insufficient data for economic assessment" rather than judging on the operational
  rules alone** — a replacement recommendation built on half the criteria is worse than none.
- **Output:** Keep / Monitor / Evaluate / Replace, with the triggering condition named in plain
  language.

---

# PHASE 6 — Fault Intelligence

**Duration:** ~2 weeks · **Build order: 5th.**

Source of truth: `maintenance_signatures`, `is_exposure = 0`. 20 signatures, 21,125 labelled tickets
(78.4%), 2023-07 → 2026-07.

| ID | Metric | Readiness | Priority | Complexity | Work | Note |
|---|---|---|---|---|---|---|
| F-1 | Most Common Faults | READY | P0 | Small | SQL | ELECTRICAL 4,124 / 206 vehicles |
| F-3 | Fastest Growing Faults | READY | P0 | Medium | Backend | Best early-warning metric we own |
| F-4 | Fault Trend by Month | READY | P0 | Medium | Backend | **Must normalise by fleet size** |
| F-5 | Fault Recurrence Rate | READY | P0 | Small | SQL | F4 dependency |
| F-6 | Average Comeback Time | READY | P0 | Small | SQL | F4 dependency |
| F-11 | Fault Severity Ranking | READY* | P1 | Medium | Backend | Derived, not from `fault_severity` |
| F-14 | Fault Lifetime | READY | P1 | Small | SQL | Chronic vs one-off |
| F-15 | Fault Aging | READY | P1 | Small | SQL | Feeds the watchlist |
| **F-16** | **Fault Concentration (Gini)** | READY | **P1** | Medium | Backend | *New — see below* |
| **F-17** | **Fault–Garage Affinity** | READY | **P1** | Medium | Backend | *New — routing input* |
| F-9 | Fault Clusters (co-occurrence) | READY* | P1 | Large | Backend | Empirical lift, no new data |
| F-7 | Faults by Vehicle Model | BLOCKED | P1 | Medium | SQL | **F1** |
| F-8 | Faults by Mileage Band | PARTIAL | P2 | Large | Backend | Odometer-at-event from `contracts` |
| F-10 | Seasonal Faults | READY* (3 yrs) | P2 | Medium | Backend | Expect a summer AC/COOLING signal |
| F-2 | Most Expensive Faults | PARTIAL | P2 | Large | Backend | Proxy via category mapping |
| F-12 | Root Cause Relationships | READY as *structure* | P2 | Medium | Backend | **92% seeded — organise/explain only, never score** |
| F-13 | Predictive Fault Probability | NOT VIABLE | P3 | — | ML | §9 |

**F-16 · Fault Concentration** *(new)* — P1 · Medium · READY
- **Formula:** Gini coefficient of occurrences per vehicle, per signature.
- **Why it matters:** ELECTRICAL appears on 206 of 438 vehicles. If those 4,124 occurrences are
  spread evenly, it is a **fleet or specification problem** (change supplier/spec). If they are
  concentrated in 20 cars, it is a **vehicle problem** (replace those cars). Same raw count, opposite
  decision. No other metric distinguishes these.
- **Visualisation:** Lorenz curve per signature + a concentration league table.

**F-9 · Fault Clusters** — P1 · Large · READY*
- **Formula:** lift = `P(A∧B) / (P(A)·P(B))` for signatures co-occurring on a visit or within 14
  days, over 49,487 rows.
- **Opinion:** genuinely novel, entirely empirical, requires no new data — and unlike the seeded
  ontology it is *measured*. Where a discovered cluster contradicts an `ontology_edges` relation,
  **trust the data and flag the edge for review** via `ontology_feedback` (currently 0 rows). This is
  how the knowledge graph starts earning its weights instead of asserting them.

**F-12 · Root Cause Relationships** — P2 · READY as structure only
- **Hard rule:** `ontology_edges` is **92% `source='seed'`** with authored weights (avg 50–65).
  Use it to *organise* faults and *explain* relationships in the UI. **Never derive a confidence
  score, probability, or ranking from those weights.** They were written by a person, not measured
  from outcomes, and presenting them as evidence would violate the project's evidence-class rules.

---

# PHASE 7 — Financial Intelligence

**Duration:** ~3 weeks · **Build order: 6th** — deliberately after the operational phases, because
its prerequisites (F10, the attribution bridge, the ledger backfill) take time to land and its
outputs are stale until they do.

**Two constraints govern every screen here:** cost is not joined to garages or tickets, and the
ledger effectively ends **2026-03-31**. Both must be visible on the page, not buried in a footnote.

| ID | KPI | Readiness | Priority | Complexity | Work | Depends |
|---|---|---|---|---|---|---|
| C1 | Cost by Vehicle | READY* (82%→100% after F10) | P0 | Small | SQL | F10 |
| C6 | Monthly Cost Trends | READY* | P0 | Small | SQL | F8 |
| C9 | Cost per Kilometre | READY* | P0 | Medium | Backend | `contracts` mileage |
| C10 | Cost per Rental Day | READY* | P0 | Small | SQL | — |
| **C14** | **Spend Concentration** | READY | **P1** | Small | SQL | *New* |
| **C13** | **Lifetime Cost Curve by Age** | READY* | **P1** | Medium | Backend | *New — see below* |
| **C15** | **Warranty Leakage** | READY* (383/438) | **P1** | Medium | Backend | *New — recoverable cash* |
| C2 | Cost by Model | BLOCKED | P1 | Medium | SQL | **F1** |
| C8 | Repair ROI | PARTIAL | P1 | Medium | Backend | C1, `contracts.contract_income` |
| C11 | Negative-ROI Vehicles | PARTIAL | P1 | Medium | Backend | C8 |
| **CB** | **Cost Attribution Bridge** | PARTIAL (20%) | **P1** | **Large** | Backend | *Infrastructure — see below* |
| C3 | Cost by Garage | PARTIAL (20%) | P2 | Medium | Backend | CB |
| C4 | Cost by Fault | PARTIAL | P2 | Medium | Backend | CB or category proxy |
| C7 | Annual Projections | PARTIAL | P2 | Medium | Backend | C6 — **do not project through the stale window** |
| C12 | Repair vs Replace NPV | PARTIAL (54%) | P2 | Large | Backend | Price backfill, residual model |
| C5 | Cost by Component | NOT VIABLE | P3 | — | Capture | §9 |

**CB · Cost Attribution Bridge** — P1 · **Large** · PARTIAL · *infrastructure, not a KPI*
- **Measured feasibility (re-verified):** of 6,300 repair expenses since 2024-01-01 — 72.7% fall
  inside ≥1 ticket window, but only **225 (3.6%) match exactly one ticket**. Collapsing to garages:
  **1,259 expenses / AED 705,622 (~20%) resolve to a single garage**; 1,756 match no ticket at all.
- **Deliverable:** an `expense_attributions` table with an explicit
  `attribution_method` ∈ {`exact`, `single_garage_window`, `ambiguous`, `unattributed`} and a
  confidence band.
- **Absolute rule: never spread ambiguous cost proportionally.** Publish the unambiguous subset as
  an *index* with its coverage displayed, and leave the rest visibly unattributed. A garage cost
  table that silently allocates 80% of spend by heuristic is worse than no table — it will be wrong
  in ways nobody can audit.
- **Opinion:** the real fix is not this bridge. It is **adding `vendor_id` to the expense entry
  form** — a small form change worth more than any amount of modelling. Build the bridge for
  history; fix the capture for the future. Do both.

**C13 · Lifetime Cost Curve by Age Band** *(new)* — P1 · Medium · READY*
- **Formula:** repair spend per vehicle-month, bucketed by vehicle age at time of spend (0–1y, 1–3y,
  3–5y, 5y+), using `vehicles.purchase_date` (438/438 populated) and 12 years of expense history.
- **Opinion: this single curve is worth more to Basem than most of the executive tiles.** It answers
  the actual strategic question — *at what age does a car in our fleet stop being profitable?* — and
  it converts the replacement decision from case-by-case argument into policy. It is fully computable
  today and nobody has built it.

**C15 · Warranty Leakage** *(new)* — P1 · Medium · READY*
- **Formula:** repair spend on vehicles inside `warranty_end_date` (383/438) / `warranty_end_km`,
  restricted to warrantable fault categories.
- **Opinion:** likely recoverable cash that nobody is currently looking for. Even a modest hit rate
  pays for this phase.

---

# PHASE 8 — AI Insights

**Duration:** ~2 weeks · **Build order: 7th (last).**

**Architecture rule — non-negotiable.** Every insight is a **rendered template over a verified
query**, never free-text generation. The engine emits a reason **code plus parameters**; the UI
renders operational language. This is already the project's standard and it is what makes the
insights auditable.

**Insight envelope:**
```
{ code, severity, entities[], metrics{}, sample_size, baseline, window,
  confidence_grade, evidence_query_id }
```
No insight ships without `sample_size`, `baseline`, and a link to the rows behind it.

| Tier | Content | Readiness | Priority | Complexity |
|---|---|---|---|---|
| **T1** | Garage quality, vehicle outliers, fault patterns, operational blockages (study insights 1–15) | READY | **P0** | Medium |
| **T2** | Cost insights with staleness/coverage caveats inline (16–20) | READY* | P1 | Medium |
| **T3** | Data-integrity insights — surfaced to **us**, not Basem (21–25) | READY | **P0** | Small |
| **T4** | Predictive/probabilistic insights | NOT VIABLE | P3 | — |

**Ranking:** severity × business impact × recency × sample size. Suppress any insight below n=30.

**Opinion:** ship T3 (data-integrity insights) **in Phase 1**, not Phase 8. "Financial data is
current through 2026-03-31" and "3,475 part rows are demo data" are the most important sentences on
the platform, and they should be visible from the first day anyone logs in.

**Explicitly out of scope:** no insight states a probability of future failure, a confidence
percentage, or a per-garage total cost. Each would require data we do not have.

---

# 9. Kill List — Attractive KPIs We Should NOT Build

Being explicit here is the point of the exercise. Each of these would look good in a demo and would
be indefensible in front of Basem.

| KPI | Why it dies | What to build instead |
|---|---|---|
| **Waiting for Approval (O4)** | **`approval_status` = `not_required` on all 26,942 rows; `approved_at` = 0.** The column has zero variance — there is no approval process recorded in this system | Nothing today. If approvals matter, they must first be *captured*. Add the workflow, then the KPI |
| **Approval Delays (G12)** | Same root cause | Same |
| **Waiting for Parts (O3)** | 18 `part_requests`, 6 `maintenance_required_parts` | Revisit after 6 months of parts capture |
| **Most Replaced Parts (V13)** | **3,475 of 3,485 `part_purchases` are `VehicleComponentDemoSeeder` output from 2026-08-02.** Real: 10 rows | Parts *spend* proxy from `vehicle_expenses` (tyres AED 1.87M, parts 311k, oil_fluids 725k) |
| **Parts Usage by Garage (G10)** | Same synthetic data | Same |
| **Average Part Lifetime / Supplier Failure** | Requires install + removal odometer pairs. Real components: 2 | Capture programme (§10) |
| **Technician Performance (G11)** | No technician entity exists. The only field, `vehicle_components.technician_name`, is demo data | Capture programme. Do not fake it with `installed_by_name` |
| **Cost by Component (C5)** | Depends on parts data | — |
| **Predictive Fault Probability (F-13, V15)** | **Our own backtest returned 1.08× lift and −42% skill — worse than the naive baseline.** Fault labels only begin in 2025 | Reference classes: observed median duration/cost per (garage × fault), with sample size |
| **Comeback Probability** | Same | Observed historical recurrence rate (G1) — same decision, zero fabrication |
| **Confidence Scores anywhere** | Not earnable on current data. The ontology's weights are authored, not measured | **Show `n=473` instead.** More honest and more useful than "87% confident" |
| **Per-garage total cost (C3 as a total)** | Only 20% of repair spend attributes unambiguously | A cost *index* on the unambiguous subset, coverage displayed |
| **Damage analytics from inspections** | `inspection_records.damage_flagged` = 0 on all 180 rows | Fix capture first (§10) |
| **Urgent repairs via `fault_severity`** | 68 rows of 26,942 | Use `condition_grade` (438/438) + rental-eligibility flags |

**A note on the temptation.** Several of these — parts intelligence especially — would produce
beautiful dashboards *today*, because the synthetic data is realistic-looking and complete. That is
exactly what makes it dangerous. Shipping it would trade the platform's credibility for a demo, and
credibility is the entire product here.

---

# 10. Capture Programmes — Start Now, Pay Off in 2027

These are not engineering tasks; they are process changes. **Every one of them starts in Phase 1 and
runs continuously**, because their value is proportional to elapsed time.

| # | Change | Effort | Unlocks | Start |
|---|---|---|---|---|
| CP1 | **Closure discipline** — require `actual_in_date` at ticket close | Process | E7, E8, O9, G4, V6 — currently gated at 25.7% | **Immediately** |
| CP2 | **`vendor_id` on the expense entry form** | Small | Accurate cost-by-garage. Removes the need for the bridge going forward | **Immediately** |
| CP3 | **Parts capture** — 6 fields per replacement (part, supplier, cost, vehicle, odometer, ticket) | Medium | All of real Phase 7, from ~12 months out | **Immediately** |
| CP4 | **`purchase_price` backfill by VIN** (201 vehicles) | Small | E12, V12, C12 | Phase 1 |
| CP5 | **`vendors.warranty_days`** — one column + data entry | Small | G7 warranty return rate | Phase 4 |
| CP6 | **`repair_hours` at task close** (currently 0/117) | Process | Labour productivity, capacity planning | Phase 3 |
| CP7 | **Root cause at task close** (currently 20/117) | Process | Real root-cause analytics | Phase 3 |
| CP8 | **`damage_flagged` enforcement** in inspection capture (0/180) | Small | Damage analytics, liability | Phase 3 |
| CP9 | **Outcome-learning loop** — record every recommendation + its 30/90-day outcome | Medium | Makes prediction *earnable* in 2027 | Phase 4 |
| CP10 | **Expense ledger backfill** past 2026-03 | External | Un-stales every financial KPI | **Immediately — this is a blocker, not a task** |

**Opinion:** CP1 and CP10 are worth more than any three KPIs in this roadmap. CP1 costs nothing but
discipline and doubles the accuracy of six metrics. CP10 is the difference between a financial
dashboard and a historical exhibit.

---

# 11. Recommended Build Order

The phase *numbering* follows the study; the *build order* does not. Garage Intelligence ships
second because it has the best data and the highest decision value — it is what proves the platform's
worth while the financial prerequisites are still landing.

| Order | Phase | Weeks | Rationale |
|---:|---|---:|---|
| 1 | **Foundation** (Phase 1) + T3 insights + CP1/CP2/CP10 kickoff | 2 | Everything depends on it. Ship Data Health first |
| 2 | **Garage Intelligence** (Phase 4) | 3 | Best data, highest value. **G1, G2, G16, G17 are the proof-of-value milestone** |
| 3 | **Operations** (Phase 3) | 2 | Adham moves off WhatsApp. O15 starts fixing the closure-rate problem |
| 4 | **Vehicle Intelligence** (Phase 5) | 2 | Per-car decisions become evidence-based |
| 5 | **Fault Intelligence** (Phase 6) | 2 | Fleet-wide patterns; feeds routing rules back into Phase 4 |
| 6 | **Financial** (Phase 7) | 3 | Requires F10 + bridge + ledger backfill to be honest |
| 7 | **Executive + AI Insights** (Phases 2, 8) | 2 | Assembles proven metrics. Nothing new is computed here |
| — | **Capture programmes** (§10) | continuous | Start in week 1; compound monthly |

**Total: ~16 weeks** for one engineer, less with two working across Foundation and Garage in
parallel after week 1.

**Milestone to demo to Basem: end of week 5** (Foundation + Garage Intelligence). The 18-vs-150-day
garage comeback spread is the single most compelling fact in our data, and it will be on screen with
evidence behind every number.

---

# 12. Master Matrix

Business Value: ★★★★★ = changes a decision worth six figures · ★ = informational.
Data Readiness uses the §0.3 grades.

| Feature | Business Value | Technical Complexity | Data Readiness | Priority | Recommended Phase |
|---|---|---|---|---|---|
| **FOUNDATION** |
| F1 Vehicle Master Normalisation | ★★★★★ | Small | READY (manual) | P0 | 1 |
| F2 Visit Collapse | ★★★★★ | Medium | READY | P0 | 1 |
| F3 Garage Registry Hygiene | ★★★★★ | Small | READY | P0 | 1 |
| F4 Recurrence Pair Table | ★★★★★ | Medium | READY | P0 | 1 |
| F5 Shared Metric Layer | ★★★★★ | Medium | READY | P0 | 1 |
| F6 Shared Filter Contract | ★★★☆☆ | Small | READY | P0 | 1 |
| F7 Date & Period Handling | ★★★★☆ | Small | READY | P0 | 1 |
| F8 Data Quality Warnings | ★★★★★ | Small | READY | P0 | 1 |
| F9 Synthetic Data Quarantine | ★★★★★ | Small | READY | P0 | 1 |
| F10 Expense Orphan Recovery | ★★★★☆ | Small | READY | P1 | 1 |
| **GARAGE INTELLIGENCE** |
| G1 Repeat Repair % | ★★★★★ | Small | READY | P0 | 4 |
| G2 Avg Time Until Same Fault Returns | ★★★★★ | Small | READY | P0 | 4 |
| G3 Avg Time Until Any Fault Returns | ★★★★☆ | Small | READY | P0 | 4 |
| G16 Garage × Fault Matrix | ★★★★★ | Medium | READY | P0 | 4 |
| G17 Cost of Rework | ★★★★★ | Medium | READY* | P0 | 4 |
| G8 Repair Quality Index | ★★★★☆ | Medium | READY* | P0 | 4 |
| G9 Experience by Fault Category | ★★★★☆ | Medium | READY | P0 | 4 |
| G4 Average Repair Time | ★★★☆☆ | Small | PARTIAL 25.7% | P1 | 4 |
| G13 Customer Return Rate | ★★★☆☆ | Small | READY | P1 | 4 |
| G14 Quality Trend Over Time | ★★★★☆ | Medium | READY | P1 | 4 |
| G15 Benchmark vs Peers | ★★★☆☆ | Medium | READY | P1 | 4 |
| G5/G6 Garage Cost + vs Average | ★★★★☆ | Large | PARTIAL 20% | P2 | 7 |
| G7 Warranty Return Rate | ★★★☆☆ | Small | BLOCKED (1 column) | P2 | 4 |
| G10 Parts Usage | ★★☆☆☆ | — | **NOT VIABLE** | P3 | — |
| G11 Technician Performance | ★★★☆☆ | — | **NOT VIABLE** | P3 | — |
| G12 Approval Delays | ★★☆☆☆ | — | **NOT VIABLE** | — | — |
| **OPERATIONS** |
| O15 Ticket Closure Rate | ★★★★★ | Small | READY | P0 | 3 |
| O6 Pending Reviews | ★★★★★ | Small | READY | P0 | 3 |
| O2 Delayed Repairs | ★★★★★ | Small | READY | P0 | 3 |
| O8 Repair SLA Compliance | ★★★★☆ | Medium | READY | P0 | 3 |
| O1 Open Repairs | ★★★★☆ | Small | READY* | P0 | 3 |
| O9 Average Repair Duration | ★★★★☆ | Small | PARTIAL 25.7% | P0 | 3 |
| O11 Vehicle Status Distribution | ★★★☆☆ | Small | READY | P0 | 3 |
| O10 Garage Workload | ★★★★☆ | Medium | READY | P1 | 3 |
| O14 Daily Queue / Weekly Trends | ★★★☆☆ | Medium | READY | P1 | 3 |
| O13 Urgent Repairs | ★★★★☆ | Medium | PARTIAL | P1 | 3 |
| O7 Under Repair / In Transit | ★★★☆☆ | Small | READY* n=7 | P1 | 3 |
| O5 Waiting for Driver | ★★☆☆☆ | Small | READY* n=5 | P2 | 3 |
| O12 Inspection Backlog | ★★☆☆☆ | Medium | PARTIAL | P2 | 3 |
| O3 Waiting for Parts | ★★★☆☆ | — | **NOT VIABLE** | P3 | — |
| O4 Waiting for Approval | ★★★☆☆ | — | **NOT VIABLE** | — | — |
| **VEHICLE** |
| V1 Vehicle Health Score | ★★★★★ | Medium | READY | P0 | 5 |
| V4 Maintenance Timeline | ★★★★☆ | Medium | READY | P0 | 5 |
| V5 Recurring Faults | ★★★★☆ | Small | READY | P0 | 5 |
| V2/V3 Repair Frequency & per Month | ★★★★☆ | Small | READY | P0 | 5 |
| V8 Cost per Kilometre | ★★★★★ | Medium | READY* | P1 | 5 |
| V10 Vehicle Risk Score | ★★★★☆ | Medium | READY* | P1 | 5 |
| V11 Warranty Returns | ★★★★☆ | Medium | READY* 87% | P1 | 5 |
| V12 Replacement Recommendation | ★★★★★ | Medium | PARTIAL 54% | P1 | 5 |
| V6 Downtime Days / Ratio | ★★★★☆ | Medium | PARTIAL 25.7% | P1 | 5 |
| V7/V9 Repair Cost & per Rental Day | ★★★★☆ | Small | PARTIAL | P1 | 7 |
| V14 Most Expensive Repairs | ★★★☆☆ | Large | PARTIAL 3.6% | P2 | 7 |
| V13 Most Replaced Parts | ★★★★☆ | — | **NOT VIABLE** | P3 | — |
| V15 Expected Future Failures | ★★★★☆ | — | **NOT VIABLE** | P3 | — |
| **FAULT** |
| F-1 Most Common Faults | ★★★★☆ | Small | READY | P0 | 6 |
| F-3 Fastest Growing Faults | ★★★★★ | Medium | READY | P0 | 6 |
| F-4 Fault Trend by Month | ★★★★☆ | Medium | READY | P0 | 6 |
| F-5/F-6 Recurrence Rate & Comeback Time | ★★★★★ | Small | READY | P0 | 6 |
| F-16 Fault Concentration (Gini) | ★★★★★ | Medium | READY | P1 | 6 |
| F-17 Fault–Garage Affinity | ★★★★☆ | Medium | READY | P1 | 6 |
| F-11 Fault Severity Ranking | ★★★★☆ | Medium | READY* | P1 | 6 |
| F-14/F-15 Fault Lifetime & Aging | ★★★☆☆ | Small | READY | P1 | 6 |
| F-9 Fault Clusters | ★★★★☆ | Large | READY* | P1 | 6 |
| F-7 Faults by Model | ★★★★☆ | Medium | BLOCKED on F1 | P1 | 6 |
| F-10 Seasonal Faults | ★★★☆☆ | Medium | READY* | P2 | 6 |
| F-8 Faults by Mileage Band | ★★★☆☆ | Large | PARTIAL | P2 | 6 |
| F-2 Most Expensive Faults | ★★★★☆ | Large | PARTIAL | P2 | 7 |
| F-12 Root Cause Relationships | ★★☆☆☆ | Medium | READY as structure | P2 | 6 |
| F-13 Predictive Fault Probability | ★★★★☆ | — | **NOT VIABLE** | P3 | — |
| **FINANCIAL** |
| C1 Cost by Vehicle | ★★★★★ | Small | READY* | P0 | 7 |
| C6 Monthly Cost Trends | ★★★★★ | Small | READY* | P0 | 7 |
| C9 Cost per Kilometre | ★★★★★ | Medium | READY* | P0 | 7 |
| C10 Cost per Rental Day | ★★★★☆ | Small | READY* | P0 | 7 |
| C13 Lifetime Cost Curve by Age | ★★★★★ | Medium | READY* | P1 | 7 |
| C15 Warranty Leakage | ★★★★★ | Medium | READY* 87% | P1 | 7 |
| C14 Spend Concentration | ★★★★☆ | Small | READY | P1 | 7 |
| CB Cost Attribution Bridge | ★★★★☆ | Large | PARTIAL 20% | P1 | 7 |
| C2 Cost by Model | ★★★★★ | Medium | BLOCKED on F1 | P1 | 7 |
| C8/C11 Repair ROI & Negative ROI | ★★★★☆ | Medium | PARTIAL | P1 | 7 |
| C3 Cost by Garage | ★★★★★ | Medium | PARTIAL 20% | P2 | 7 |
| C4 Cost by Fault | ★★★★☆ | Medium | PARTIAL | P2 | 7 |
| C7 Annual Projections | ★★★☆☆ | Medium | PARTIAL | P2 | 7 |
| C12 Repair vs Replace NPV | ★★★★★ | Large | PARTIAL 54% | P2 | 7 |
| C5 Cost by Component | ★★★☆☆ | — | **NOT VIABLE** | P3 | — |
| **EXECUTIVE & INSIGHTS** |
| E1/E9 Spend & Category | ★★★★☆ | Small | READY* | P0 | 2 |
| E4 Worst-Performing Vehicles | ★★★★★ | Small | READY | P0 | 2 |
| E6 Repeat Repair by Garage | ★★★★★ | Small | READY | P0 | 2 |
| E10 Top Recurring Failures | ★★★★☆ | Small | READY | P0 | 2 |
| E5 Garage Leaderboard | ★★★★★ | Medium | READY | P0 | 2 |
| E2 Cost per Model | ★★★★★ | Medium | BLOCKED on F1 | P0 | 2 |
| E3 Failure Rate by Model | ★★★★★ | Medium | BLOCKED on F1 | P0 | 2 |
| E7 Fleet Availability | ★★★★☆ | Medium | PARTIAL 25.7% | P1 | 2 |
| E8 Fleet Downtime Cost | ★★★★★ | Medium | READY* (upgraded) | P1 | 2 |
| E11 Declining Health Vehicles | ★★★★☆ | Medium | READY | P1 | 2 |
| E12 Replacement Recommendation | ★★★★★ | Medium | PARTIAL 54% | P1 | 2 |
| E13 Annual Savings Opportunity | ★★★☆☆ | Medium | PARTIAL | P2 | 2 |
| T1 Insight Engine — operational | ★★★★★ | Medium | READY | P0 | 8 |
| T3 Insight Engine — data integrity | ★★★★★ | Small | READY | P0 | **1** |
| T2 Insight Engine — financial | ★★★★☆ | Medium | READY* | P1 | 8 |
| T4 Insight Engine — predictive | ★★★★☆ | — | **NOT VIABLE** | P3 | — |
| **CAPTURE** |
| CP1 Closure discipline | ★★★★★ | Process | — | P0 | 1 (continuous) |
| CP10 Expense ledger backfill | ★★★★★ | External | — | P0 | 1 (blocker) |
| CP2 `vendor_id` on expense form | ★★★★★ | Small | — | P0 | 1 |
| CP4 `purchase_price` backfill | ★★★★☆ | Small | — | P1 | 1 |
| CP3 Parts capture | ★★★★★ | Medium | — | P1 | 1 (continuous) |
| CP9 Outcome-learning loop | ★★★★★ | Medium | — | P1 | 4 |
| CP5–CP8 (warranty days, hours, root cause, damage) | ★★★☆☆ | Small each | — | P2 | 3–4 |

---

# 13. The Opinionated Summary

**Build these six things and 80% of the value is delivered:** F1 (model normalisation), F2 (visit
collapse), F4 (recurrence pairs), G2 (days-until-same-fault-returns), G16 (garage × fault matrix),
O15 (closure rate). That is roughly five weeks of work and it changes how two people make decisions
every day.

**The three most under-appreciated items in this roadmap** are not in the original brief at all:
C13 (lifetime cost curve by age — converts replacement from argument to policy), F-16 (fault
concentration — distinguishes a vehicle problem from a fleet problem using data we already have),
and C15 (warranty leakage — likely recoverable cash nobody is looking for).

**The three things most likely to derail this project:** building Parts Intelligence on synthetic
data; shipping predictive scores our own backtest says don't work; and letting two pages disagree
about "average repair duration" because F5 was skipped.

**The one thing that is not an engineering problem:** the expense ledger stopped in March 2026 and
only 25.7% of tickets ever get closed. No amount of SQL fixes either. Both need a person to change a
habit, and both should start this week — because every week they don't, the analytics we ship in
month four get measurably worse.
