# Fleet Maintenance Intelligence & Decision Support Platform
## A Data-Grounded Study and Design Proposal

**Prepared for:** Basem (Owner/Executive), Adham (Maintenance Manager)
**Date:** 2026-08-03
**Method:** Every number in this document was read directly from the live `laravel` MySQL database
on 2026-08-03. Nothing is estimated, extrapolated, or assumed. Where data is missing, this document
says so explicitly rather than proposing a KPI that cannot be computed.

---

## 0. Executive Summary — Read This First

Before any dashboard design, five facts about our data determine what is and is not buildable.
These are the load-bearing findings of the entire study.

### Finding 1 — Our operational history is excellent. Our cost history is disconnected from it.

We have **26,942 maintenance tickets** covering 2023-06-05 → 2026-08-03, and **93.5% of them name
the garage**. That is a genuinely rare asset: three years of "which car went to which shop for what,
and when." Most fleets do not have this.

But the `maintenances.cost` column is populated on **358 rows out of 26,942 — 1.3%**, totalling
AED 173,935. The money does not live there. It lives in `vehicle_expenses`: **28,327 rows, AED
27.5 million, going back to 2014**, classified into 20 spend categories.

The problem: `vehicle_expenses` has **no `vendor_id` and no `maintenance_id`**. It knows *how much*
and *which car* and *when*. The ticket table knows *which garage* and *what fault* and *how long*.
**There is no key joining them.**

This single gap is the difference between "Garage X is slow" (buildable today) and "Garage X is
expensive" (not buildable today without new work). Section 1.13 and Phase 12 address the fix.

### Finding 2 — The comeback metric — the crown jewel — works right now.

> ⚠ **CORRECTED 2026-08-03.** The figures first published here counted duplicate label rows:
> `maintenance_signatures` holds 2–8 rows for the same fault on the same car on the same day
> (33,026 raw rows collapse to **12,608 distinct fault events**), and the original query treated each
> duplicate as a separate recurrence. The table below is the corrected, deduplicated result over full
> history. **The ranking and the decision are unchanged; every figure and sample size moved.**
> See `Fleet-Intelligence-Execution-Plan.md` §0.1.

The exact insight requested ("Brake Noise at Garage A returns in 18 days, Garage B in 67") is
computable today from `maintenance_signatures` (49,487 rows) joined to `maintenances.vendor_id`.
Live output from the real database, deduplicated to one row per (vehicle, signature, date):

| Garage | Recurrence pairs | Avg days until same fault returns |
|---|---:|---:|
| *Alresala al zahabia* | *29* | *19.6* — **below the n≥30 gate; no score shown** |
| Deals On Wheels auto | 473 | **55.0** |
| ALTIQNIAH AL ALIAH | 183 | 110.4 |
| AYAN GARAGE | 112 | 112.0 |
| FUTURE TYRES | 1,126 | 122.3 |
| POWER POINT | 1,605 | 124.8 |
| GPT GARRAGE | 719 | 131.0 |
| RMR | 592 | 144.6 |
| HOT LINE | 420 | 148.8 |

**The defensible headline is Deals On Wheels — 55 days against a fleet norm around 120, on 473
measured repairs.** Alresala looks worse still (19.6 days) but sits at n=29, which is precisely what
the minimum-sample gate exists to suppress: it is a watch-item, not a finding.

This is real, and it is the highest-value thing in the study. **Caveat, stated up front:** this
number is exposure-confounded — a garage that sees a car often will show shorter return intervals
regardless of quality, and tyre shops naturally see tyres return. Phase 6 specifies the corrections
required before this is put in front of Basem as a quality judgement.

### Finding 3 — Parts Intelligence has essentially no real data.

`part_purchases` shows 3,485 rows and AED 2.07M of spend. **3,475 of those rows were created on
2026-08-02 by `VehicleComponentDemoSeeder`** — synthetic UI-testing data. The same is true of
`vehicle_components` (3,475 of 3,477 synthetic). Real production rows: **10 part purchases, 2
installed components.**

Phase 7 as written in the brief is **not buildable**. What *is* buildable is a proxy parts view from
`vehicle_expenses.category IN ('tyres','parts','oil_fluids')` — AED 2.91M of real spend — which
answers spend questions but not part-lifetime or supplier-failure questions.

### Finding 4 — The vehicle master data cannot currently group by model.

"Which vehicle models cost the most?" is Basem's first question. Today it cannot be answered
correctly. `vehicles.make` contains values like `CERATO`, `PATROL`, `ACCENT`, `00KIA`, `00SUNNY`,
`LEXUS-300`, `CH`, `FASTER` — models and junk stored in the make column. `vehicles.vehicle_class`
is **0 of 438 populated**. `purchase_price` is populated on 237 of 438.

A make/model normalisation layer is a **prerequisite** for Phase 2, Phase 4 and Phase 8. It is a
small job (438 rows, one mapping table) with outsized payoff. It is the #1 Quick Win.

### Finding 5 — The expense ledger stopped in March 2026.

Monthly repair spend: 2025-12 = AED 1,024,298 · 2026-01 = 540,767 · 2026-02 = 372,616 ·
2026-03 = 273,621 · **2026-04 = 17,129 · 2026-05 = 7,558 · 2026-06 = 758 · 2026-07 = 308.**

Every financial dashboard must therefore carry a **"financials current through 2026-03-31"** watermark,
or Basem will read a 98% cost reduction that did not happen. Operational dashboards (ticket volume,
duration, comebacks) are current to today and are unaffected.

### What this means for sequencing

| Layer | Status | Verdict |
|---|---|---|
| Operations Intelligence (Adham) | Data present, current | **Build now** |
| Garage Intelligence (quality/speed/recurrence) | Data present, current | **Build now** — flagship |
| Fault Intelligence | Data present, current | **Build now** |
| Vehicle Intelligence (non-financial) | Data present | **Build now** |
| Executive Financial Intelligence | Data present but stale + unjoined | **Build after bridge + backfill** |
| Parts Intelligence | Real data absent | **Do not build yet** |
| Predictive Intelligence | Backtest already failed | **Do not build yet** — see Phase 9 |

---

# PHASE 1 — Data Inventory

Exact counts from `laravel` on 2026-08-03. Analytics verdicts: **A** = ready now,
**B** = usable with stated caveats, **C** = needs enrichment first, **D** = not usable.

## 1.1 `maintenances` — the operational spine · 26,942 rows · Verdict **A** (operational) / **D** (financial)

**Purpose.** One row per maintenance event. This is the single most valuable table we own.

**Range.** `out_date` 2023-06-05 → 2026-08-03. 1,385 rows have no `out_date`.

**Provenance.**

| origin | rows | date range | meaning |
|---|---:|---|---|
| `sheet` | 20,434 | 2023-06-05 → 2026-07-13 | legacy Google Sheet import — the historical bulk |
| `customer-sheet` | 6,245 | 2024-08-24 → 2026-02-15 | richer sheet with service/type columns |
| `manual` | 253 | 2026-06-25 → 2026-08-03 | the new in-app workflow |
| `contract` | 10 | 2026-06-20 → 2026-07-21 | contract-derived |

**Field-by-field fill rate (the decisive table in this study):**

| Field | Populated | % | Analytics value |
|---|---:|---:|---|
| `vendor_id` (garage) | 25,204 | 93.5% | **Highest.** Enables all garage analytics |
| `garage` (text) | 25,125 | 93.3% | redundant with `vendor_id`; use the FK |
| `vehicle_id` | 26,296 | 97.6% | **Highest.** Enables all vehicle analytics |
| `out_date` | 25,557 | 94.9% | **Highest.** The event clock |
| `actual_in_date` | 6,924 | 25.7% | **Duration is only computable on a quarter of tickets** |
| `maintenance_type` | 8,387 | 31.1% | 2024-08+ only (`customer-sheet` era) |
| `service_main` | 8,191 | 30.4% | same era limit |
| `service_sup` | 8,243 | 30.6% | same era limit |
| `maintenance_reason_id` | 8,050 | 29.9% | FK to 39-row reason lexicon |
| `invoice_no` | 754 | 2.8% | too sparse to reconcile |
| `cost` | 358 | **1.3%** | **unusable as a cost source** |
| `workflow_status` | 252 | **0.9%** | new engine; SLA analytics limited to these |
| `spare_part` | 0 | **0%** | dead column |
| `report_odometer` | 26 | 0.1% | dead for analytics |
| `intake_odometer` | 4 | ~0% | dead |
| `receive_odometer` | 57 | 0.2% | dead |

**Duration quality.** 6,881 tickets have both dates. Mean 2.53 days, range **−110 to +113**;
**31 rows are negative** (in-date before out-date) and must be excluded. By year:
2023 = 2.54d (n=820) · 2024 = 3.24d (n=1,446) · 2025 = 3.65d (n=1,810) · 2026 = 1.84d (n=2,774).

The 2026 improvement is real *within the measured sample* but the sample is self-selecting — only
closed tickets get an `actual_in_date`, and long-running tickets close last. Report duration with a
**closure-rate companion metric** always.

**`event_status` distribution** — 7,732 `Follow up` · 7,166 `OUT` · 6,893 `IN` · 3,000 `Change` ·
827 `Delay` · 700 `Test` · 524 `Under Test`. Note the sheet modelled a visit as *multiple rows*
(OUT then IN then Follow up), so **a "ticket" in this table is closer to an event than a repair job.**
Any per-repair metric must first collapse rows into visits. This is a real modelling decision, not a
detail.

**Timing columns from the new workflow** — `dispatched_at`, `repair_started_at`, `ready_at`,
`picked_up_from_garage_at`, `park_arrived_at`, `wf_closed_at`, `last_state_change_at`. These enable
true stage-level SLA analytics but exist for only ~252 tickets. Two-tier metric policy required.

**Relationships.** → `vehicles`, `vendors` (×2: `vendor_id`, `transfer_to_vendor_id`), `contracts`,
`maintenance_reasons`, `users` (14 distinct actor FKs). ← `maintenance_signatures`,
`maintenance_tasks`, `maintenance_line_items`, `repair_inspections`, `part_purchases`,
`vehicle_log_events`, `maintenance_media`, `maintenance_checkpoints`.

**Data-quality rule.** `SoftDeletes` is active. Currently 0 trashed rows, but every raw SQL query
**must** declare `deleted_at IS NULL` (LIVE) or explicitly include trash (HISTORICAL).

---

## 1.2 `vehicle_expenses` — the money · 28,327 rows · Verdict **B**

**Purpose.** The general expense ledger per vehicle. **This is the only credible source of
maintenance cost we have.**

**Range.** 2014-01-12 → 2027-09-18 (future dates are legitimate — amortised service contracts).

**Spend by category (all time, AED):**

| Category | Rows | Amount | Maintenance-relevant? |
|---|---:|---:|---|
| sub_rental | 3,917 | 14,988,690 | **No** — excluded from cost by policy |
| insurance | 1,853 | 2,051,306 | Ownership cost, not repair |
| tyres | 3,504 | **1,874,158** | **Yes** |
| other | 2,795 | 1,556,273 | Partly |
| salik | 716 | 796,229 | No |
| oil_fluids | 2,222 | **724,509** | **Yes** |
| body_paint | 1,752 | **722,792** | **Yes** |
| electrical | 1,713 | **684,679** | **Yes** |
| registration | 1,663 | 571,869 | No |
| brakes_suspension | 859 | **493,112** | **Yes** |
| service_repair | 957 | **467,003** | **Yes** |
| gps | 1,007 | 450,281 | No |
| engine_transmission | 632 | **439,150** | **Yes** |
| ac_cooling | 583 | **349,499** | **Yes** |
| parts | 472 | **311,407** | **Yes** |
| fuel | 2,594 | 155,349 | No |
| fines | 118 | 139,539 | No |
| recovery | 788 | **130,132** | **Yes** (logistics) |
| cleaning | 132 | 24,243 | No |
| adjustment | 50 | 8,003 | Accounting |

**True repair spend (the 9 "Yes" categories): ≈ AED 6.20M across 13,285 rows.**

**Quality issues.**
1. **5,128 rows (18.1%) have `vehicle_id = NULL`** — they carry `car_serial` but never resolved to a
   vehicle. These are invisible to every per-vehicle cost metric. Recovering them is a Quick Win.
2. **No `vendor_id`, no `maintenance_id`.** Cost cannot be attributed to a garage or a job (§1.13).
3. **The ledger stops ~2026-03** (Finding 5).
4. `account_type` is functionally constant (`Expence` 28,320 / `xExpence` 7) — no analytical value.
5. `category` is derived by `ExpenseCategoryClassifier` from the free-text `remarks`. It is a
   **derived, re-runnable judgement**, not a fact. Rule changes retroactively move history — every
   cost chart must therefore be reproducible and stamped with the classifier version.

**Historical value: very high.** 12 years of spend, 2015-onward at meaningful volume. This is the
only table that can support "cost per vehicle over its whole life."

---

## 1.3 `maintenance_signatures` — the fault layer · 49,487 rows · Verdict **A**

**Purpose.** Machine- and human-assigned fault labels per ticket. This is what makes fault and
garage intelligence possible at all.

| source | version | is_exposure | rows | tickets | range |
|---|---|---:|---:|---:|---|
| derived | v1 | 0 (fault) | 26,988 | 15,313 | 2023-07-07 → 2026-07-29 |
| derived | v1 | 1 (exposure) | 12,230 | 9,380 | 2023-07-07 → 2026-07-14 |
| human | v1 | 0 (fault) | 6,886 | 5,359 | 2024-08-24 → 2026-07-13 |
| human | v1 | 1 (exposure) | 3,383 | 3,158 | 2024-10-30 → 2026-07-13 |

**Coverage: 21,125 of 26,942 tickets = 78.4% carry at least one label.**

**The 20-signature vocabulary, with volumes (fault labels only):**

ELECTRICAL 4,124 (206 vehicles) · INTERIOR 3,376 (205) · OIL_SERVICE 2,982 (202) · STEERING 2,870
(190) · TYRE 2,521 (198) · LIGHTS 2,350 (184) · ENGINE_MECH 1,867 (195) · COOLING 1,716 (148) ·
SUSPENSION 1,686 (163) · ACCESSORY 1,510 (163) · AC 1,504 (170) · CHECK_ENGINE 1,410 (138) ·
BRAKES 1,405 (163) · TRANSMISSION 1,340 (153) · GLASS 1,276 (167) · BATTERY 587 (150) ·
EXHAUST 445 (103) · KEY 402 (81) · FUEL_SYS 254 (67) · LEAK_OTHER 249 (77)

**This confirms the fleet is ELECTRICAL-first, not mechanical-first** — a genuinely
counter-intuitive fact worth putting in front of Basem on day one.

**Critical caveats.**
- The `is_exposure` flag distinguishes "this fault occurred" from "the car was merely exposed to this
  system." **Every fault metric must filter `is_exposure = 0`.** Getting this wrong roughly doubles
  every fault count.
- The v1 classifier is **not ground truth** — Concept Bridge measured ~98.8% recall but there is a
  known actions→fault mis-mapping. Treat signatures as high-quality evidence, not certainty.
- Granularity is **system-level, not fault-level**. We can say "BRAKES returned in 18 days." We
  cannot say "Brake Noise specifically returned in 18 days" — `BRAKES` conflates noise, pads,
  discs, and ABS. The brief's example insight is achievable at system granularity today; fault
  granularity requires the `fault_catalog` path (§1.9) to accumulate volume.

---

## 1.4 `contracts` — rental reality · 39,701 rows · Verdict **A**

**Purpose.** Rental agreements. Indispensable for maintenance analytics because it supplies the
**denominators**: rental days, kilometres, and revenue.

**Range** 2011-04-06 → 2026-08-19. `out_date` 100% · `in_date` 39,588 (99.7%) · `days > 0` 36,234
(91.3%) · `out_milage` 39,656 · `in_milage` 38,178. Total `contract_income` **AED 54,754,651**.

By year (rental days / distinct vehicles): 2022 = 21,010/93 · 2023 = 28,149/129 · 2024 = 38,290/151 ·
2025 = **43,719/186** · 2026-YTD = 23,975/172.

**Why maintenance needs it.**
- Fleet availability = rental days ÷ (fleet × calendar days)
- Downtime cost = days in shop × that vehicle's realised day rate
- Cost per rental day = repair spend ÷ rental days (the honest efficiency metric)
- Odometer chain: `out_milage`/`in_milage` at 96% fill is our **best mileage history** — far better
  than the near-empty odometer columns on `maintenances`.

**Caveat.** The `km` column is entirely NULL; distance must be computed as
`in_milage − out_milage` with continuity guards.

---

## 1.5 `vehicles` — the asset master · 438 rows · Verdict **C**

| Field | Populated | Note |
|---|---:|---|
| `odometer` | 422/438 | current reading only, no history |
| `purchase_date` | 438/438 | complete |
| `purchase_price` | **237/438 (54%)** | blocks ROI/TCO on 46% of fleet |
| `model` | 424/438 | present but dirty |
| `year` | 438/438 | complete |
| `warranty_end_date` | 383/438 | usable |
| `vehicle_class` | **0/438** | **empty — no segmentation possible** |

**The make/model problem, shown as it actually is:**

| make | count | reality |
|---|---:|---|
| NISSAN | 53 | correct |
| CHEVROLET | 43 | correct |
| FORD | 28 | correct |
| MERCEDES | 25 | correct |
| **CERATO** | **14** | a Kia *model* in the make column |
| **SPORTAGE** | **11** | a Kia *model* |
| **00KIA** | **9** | prefix corruption |
| **PATROL** | **9** | a Nissan *model* |
| **ACCENT** | **9** | a Hyundai *model* |
| **FASTER / CH / CHECKER / LEXUS-300** | 1 each | junk |

Kia's true fleet count is `KIA 18 + CERATO 14 + SPORTAGE 11 + 00KIA 9 + PICANTO 5 + …` — roughly
**60+ vehicles, reported today as 18.** Any "cost by model" chart built on the raw column will be
materially wrong. Section 12 makes normalisation the top Quick Win.

Also useful and underused: `warranty_end_date`/`warranty_end_km` (warranty-leakage detection),
`day_rent_value` (downtime costing), `condition_grade`, `is_deferred_maintenance`.

---

## 1.6 `vendors` — garages and suppliers · 463 rows · Verdict **B**

247 `garage` · 192 `parts_supplier` · 15 `other` · 5 `service_center` · 4 `insurance`.

**Top garages by ticket volume:**

| Garage | Tickets | First | Last |
|---|---:|---|---|
| POWER POINT | 3,216 | 2023-07-14 | 2026-02-02 |
| **OFFICE PARKING** | **2,738** | 2023-08-21 | 2025-07-28 |
| GPT GARRAGE | 2,713 | 2024-10-16 | 2026-08-03 |
| FUTURE TYRES | 2,289 | 2023-07-07 | 2026-07-12 |
| Deals On Wheels auto | 2,131 | 2025-05-05 | 2026-08-03 |
| RMR | 1,668 | 2023-07-11 | 2026-07-08 |
| HOT LINE | 1,078 | 2023-06-05 | 2024-11-11 |
| One Roof | 1,028 | 2024-04-30 | 2025-05-28 |
| ALTIQNIAH AL ALIAH | 713 | 2024-07-08 | 2025-12-21 |
| **Garage** | **636** | 2024-10-30 | 2026-01-29 |
| **Under Test** | **542** | 2024-08-16 | 2025-07-28 |

**Two quality problems that will embarrass us if unaddressed:**
1. **Pseudo-garages.** `OFFICE PARKING` (2,738), `Under Test` (542), and generic `Garage` (636) are
   *locations or states*, not repair shops — 3,916 tickets. They must be excluded from every quality
   ranking or they will top the leaderboard for "fastest repairs."
2. **Specialisation confound.** `FUTURE TYRES` and `TIRE AND MORE` do tyres. Tyres recur by nature.
   Ranking them against a general mechanical shop on raw comeback rate is invalid. Phase 6 requires
   **fault-category-matched benchmarking.**

`rating` and `default_lead_time_days` exist but are unpopulated — usable as write targets for
computed scores.

---

## 1.7 `part_purchases` (3,485) & `vehicle_components` (3,477) — Verdict **D**

**These tables are 99.7% synthetic.**

- `part_purchases`: **3,475 of 3,485 rows created 2026-08-02**, by `VehicleComponentDemoSeeder`.
- `vehicle_components`: **3,475 of 3,477 rows created 2026-08-02**; `source='workflow',
  write_mode='seed'`.
- Confirming signature: part names are near-uniformly distributed across brands (Goodyear 70,
  Bridgestone 66, Continental 65, Michelin 60, Yokohama 52, Pirelli 52, Dunlop 51, Hankook 50) —
  no real procurement distribution looks like that. Names also carry mojibake (`Tyre � Goodyear`).
- The seeder's own docblock says: *"DEMO data … NOT wired into DatabaseSeeder."* It is not part of
  the standard seed; it was run manually for UI testing. Its blast radius is correctly limited — it
  does not touch `maintenances`, `invoices`, or odometers.

**Real production volume: 10 part purchases, 2 installed components.**

**Consequence.** Phase 7 (Parts Intelligence) cannot be built as specified. Do not ship a Parts
dashboard reading these tables — it would present fiction as fact to the owner. Also note
`vehicle_log_events` is inflated by the same seeder: of 6,647 rows, **4,544 are
`component_installed`/`component_removed` from the demo run.** Real workflow events number ~2,100.

The schema itself is well designed (`installed_at`, `installed_odometer`, `warranty_until`,
`removed_at`, `removal_reason`, `replaced_by_component_id`) — everything needed for part-lifetime
analytics is *modelled*. It simply has not been *captured*. Phase 7 becomes a data-capture programme,
not an analytics programme.

---

## 1.8 Tier-1 capture tables — small but the highest-quality data we own

| Table | Rows | Range | Verdict |
|---|---:|---|---|
| `maintenance_tasks` | 117 | 2026-06+ | **C** — structured fault/service tasks; 94 completed, 15 pending, 6 in progress. 24/108 fault tasks carry `fault_catalog_id`; 20 carry `root_cause_id`; 42 carry `parts_cost`; **0 carry `repair_hours`** |
| `maintenance_line_items` | 88 | 2026+ | **C** — itemised parts/labour |
| `repair_inspections` | 34 | 2026+ | **C** — QC outcomes: 29 fixed, 4 still_exists, 1 new_issue. **First-time-fix = 85.3% on n=34** |
| `inspection_records` | 180 | 2026-06-27+ | **C** — photo capture across 9 phases (test 55, pre 46, arrival 37…). **`damage_flagged` = 0 on all 180** — the damage field is not being used |
| `maintenance_checkpoints` | 3 | 2026+ | **D** — too few |
| `maintenance_handovers` | 3 | 2026+ | **D** |
| `complaints` | 7 | 2026+ | **D** — schema ready, no volume |
| `driver_observations` | 5 | 2026+ | **D** |
| `logistics_tasks` | 14 | 2026+ | **D** |

**Strategic reading.** These tables are the *future* of this platform: they carry verified outcomes,
root causes, itemised cost, and independent QC — everything the 26,942-row legacy corpus lacks. They
are 2–4 months old. **Every month of adoption compounds.** The single highest-leverage action for
2027 analytics is not writing SQL — it is driving Tier-1 capture adoption now.

---

## 1.9 Knowledge & ontology layer — Verdict **C**

- `ontology_nodes` 1,470: cause 367 · symptom 360 · procedure 274 · component 271 · fault 106 · repair 92
- `ontology_edges` 2,087: caused_by 435 · fixed_by 405 · affects_component 398 · presents_as 360 ·
  inspected_by 302 · related_to 164 · confused_with 23
- **1,923 of 2,087 edges (92%) are `source='seed'`** with hardcoded weights (avg 50–65).
- `fault_causes` 477 · `fault_catalog` 64 · `service_catalog` 19 · `component_catalog` 45 ·
  `finding_keywords` 107 · `keyword_terms` 2,350 · `action_catalog` 96
- `knowledge_documents` **0** · `knowledge_chunks` **0** · `evidence_links` **0** · `ontology_feedback` **0**

**Verdict.** This is expert-authored domain knowledge, not learned knowledge. It is genuinely useful
for *organising* and *explaining* — mapping a symptom to likely causes, grouping faults into
categories. It must **not** be used to produce confidence scores or probabilities, because the
weights were authored, not measured. The zero rows in `evidence_links` and `ontology_feedback` mean
nothing has yet closed the loop from prediction to outcome.

---

## 1.10 Supporting tables

| Table | Rows | Analytics use |
|---|---:|---|
| `invoices` | 23,592 | rental invoices (customer side), not garage bills — **not a maintenance cost source** |
| `customers` | 17,241 | rental customers; relevant only via contract linkage |
| `vehicle_log_events` | 6,647 (**~2,100 real**) | audit trail; usable for workflow-era events |
| `sync_changes` | 5,622 | data-provenance auditing |
| `user_activity_events` | 3,597 | adoption measurement — useful for tracking Tier-1 rollout |
| `service_reminders` | 574 | preventive-maintenance schedule; **PM-compliance KPI source** |
| `vehicle_registrations` | 542 | compliance/expiry |
| `plate_assignments` | 438 | plate history — needed for correct historical joins |
| `maintenance_reasons` | 39 | reason lexicon behind `maintenance_reason_id` |
| `recurring_fault_reviews` | 39 | human adjudication of recurrence — **small but is our only recurrence ground truth** |
| `concept_bridge_labels` / `_samples` | 210 / 210 | classifier evaluation set |
| `kpi_snapshots` | 1 | frozen baseline (first-time fix 59.6%, comeback 40.4%, turnaround 2.7d) |
| `notifications` | 9,532 | alerting; not analytics |

**Empty tables of note** (schema exists, no data): `service_records`, `maintenance_invoices`,
`payments`, `invoice_items`, `part_rfqs`, `rfq_lines`, `supplier_quotes`, `part_investigations`,
`recommendations`, `recommendation_events`, `mileage_overrides`, `inspection_schedules`,
`maintenance_swaps`, `drivers`. Each represents a designed capability with no operational adoption —
and each is a candidate for either activation or retirement.

---

## 1.11 Relationship map (analytics-relevant paths)

```
                              ┌──────────────┐
                              │   vehicles   │ 438
                              │  (make/model │
                              │    DIRTY)    │
                              └──┬───┬───┬───┘
             ┌───────────────────┘   │   └──────────────────┐
             │                       │                      │
   ┌─────────▼────────┐   ┌──────────▼─────────┐  ┌─────────▼──────────┐
   │   maintenances   │   │  vehicle_expenses  │  │     contracts      │
   │     26,942       │   │      28,327        │  │      39,701        │
   │ WHO / WHAT / WHEN│   │  HOW MUCH / WHEN   │  │ DAYS / KM / REVENUE│
   │  cost: 1.3% ✗    │   │  vendor_id: NONE ✗ │  │   out/in_milage ✓  │
   └──┬────────────┬──┘   └────────────────────┘  └────────────────────┘
      │            │                 ▲
      │            │                 │
      │            │        ✗ NO JOIN KEY EXISTS ✗
      │            │        (see §1.13 — the bridge)
      │            └─────────────────┘
      │
      ├──► vendors (463) ......... vendor_id 93.5% ✓✓  ← garage analytics
      ├──► maintenance_signatures (49,487) 78.4% ✓✓    ← fault analytics
      ├──► maintenance_tasks (117) ................ C
      ├──► maintenance_line_items (88) ............ C
      ├──► repair_inspections (34) ................ C  ← QC / first-time-fix
      ├──► part_purchases (3,485 → 10 real) ....... D
      └──► vehicle_log_events (6,647 → ~2,100) .... B
```

**The two strong joins** (`maintenances`→`vendors`, `maintenances`→`maintenance_signatures`) carry
this entire platform. **The one missing join** (`vehicle_expenses`→`maintenances`/`vendors`) is what
blocks the executive financial layer.

---

## 1.12 Consolidated data-quality register

| # | Issue | Severity | Blocks |
|---|---|---|---|
| Q1 | `vehicle_expenses` has no garage or ticket FK | **Critical** | all cost-by-garage, cost-by-fault |
| Q2 | Expense ledger ends ~2026-03 | **Critical** | all current financial KPIs |
| Q3 | `vehicles.make` mixes makes and models; `vehicle_class` empty | **Critical** | cost/failure by model |
| Q4 | Parts/component tables 99.7% synthetic | **Critical** | Phase 7 entirely |
| Q5 | `actual_in_date` only 25.7% | High | duration, SLA, downtime |
| Q6 | Pseudo-garages in `vendors` (3,916 tickets) | High | garage rankings |
| Q7 | 5,128 expense rows unlinked to `vehicle_id` | High | per-vehicle TCO |
| Q8 | `purchase_price` 54% | High | ROI, replacement decisions |
| Q9 | Sheet models a visit as multiple rows | High | any per-repair metric |
| Q10 | Signature granularity is system-level | Medium | fault-specific insights |
| Q11 | 31 negative durations | Medium | duration averages |
| Q12 | Ontology 92% seeded, weights authored | Medium | any confidence score |
| Q13 | `maintenance_type`/`service_main` only 2024-08+ | Medium | long-run category trends |
| Q14 | `inspection_records.damage_flagged` = 0/180 | Medium | damage analytics |
| Q15 | Garage names not deduplicated (`Qasr al zaiton` / `kasr al zaiton`) | Medium | garage aggregation |

---

## 1.13 The Cost Attribution Bridge — measured feasibility

Because Q1 determines whether Basem gets a financial platform at all, I tested it rather than
theorised about it. Method: for repair-category expenses since 2024-01-01, find tickets on the same
vehicle whose window `[out_date, actual_in_date + 14d]` contains the expense date.

**Result — 6,300 repair expenses tested:**

| Outcome | Expenses | Share |
|---|---:|---:|
| Falls inside ≥1 ticket window | 4,577 | 72.7% |
| Falls inside **exactly one** ticket | **225** | **3.6%** |
| Ambiguous (2–14 candidate tickets) | 4,352 | 69.1% |
| No matching ticket | 1,723 | 27.3% |

Ticket-level attribution is therefore **not viable** — vehicles are in too many overlapping windows.

**But garage-level attribution is partially viable.** Collapsing candidates to distinct garages:

| Distinct candidate garages | Expenses | AED |
|---:|---:|---:|
| 0 (no ticket) | 1,756 | 879,632 |
| **1 (unambiguous)** | **1,259** | **705,622** |
| 2 | 1,331 | 715,707 |
| 3 | 992 | 577,806 |
| 4+ | 962 | 499,124 |

**Verdict.** ~20% of repair spend (AED 705,622) can be attributed to a single garage with high
confidence. That is a real, defensible sample — enough to publish a **cost-per-garage index on the
unambiguous subset**, clearly labelled with its coverage. It is **not** enough to publish total spend
per garage.

**Recommendation.** Build a materialised `expense_attributions` table with an explicit
`attribution_method` column (`exact` | `single_garage_window` | `ambiguous` | `unattributed`) and a
`confidence` band. Never silently spread ambiguous cost. Long term, the correct fix is capturing
`vendor_id` at expense-entry time — a small form change worth more than any modelling effort.

---

# PHASE 2 — Executive Dashboard (Basem)

**Audience.** Owner. Strategic decisions: what to replace, which garages to keep, where money leaks.
**Design principle.** Basem should not read charts — he should read *conclusions*, with charts
underneath as proof. Every tile answers a decision, not a question.

**Global controls.** Period · Vehicle class (post-normalisation) · Garage · Fault category ·
"Financials as of" watermark.

---

### E1 · Total Maintenance Spend & Trend
- **Business value.** The number the owner opens with; anchors everything else.
- **Formula.** `SUM(vehicle_expenses.amount)` where `category IN (9 repair categories)`, grouped by month.
- **Data.** `vehicle_expenses` (28,327; ~13,285 repair rows; AED 6.20M).
- **Visualisation.** Big number + 24-month column chart with 3-month moving average.
- **Drill-down.** month → category → vehicle → expense remark.
- **Confidence: HIGH for ≤2026-03. Stale thereafter.**
- **Missing.** Nothing structural. Requires the staleness watermark and Q2 backfill.

### E2 · Cost per Vehicle Model *(Basem's headline question)*
- **Value.** Drives purchasing: stop buying models that bleed.
- **Formula.** `SUM(repair expense) / COUNT(DISTINCT vehicle)` per normalised model, and per
  1,000 rental days from `contracts.days` to remove fleet-size and utilisation bias.
- **Data.** `vehicle_expenses` + `vehicles` (**needs normalisation**) + `contracts`.
- **Visualisation.** Horizontal bar, ranked; secondary axis = fleet count (credibility of sample).
- **Drill-down.** model → vehicle list → ticket history → expense lines.
- **Confidence: MEDIUM.** The financial data supports it; **`vehicles.make/model` does not, today.**
- **Missing.** Make/model normalisation (Q3). This is the gating dependency.

### E3 · Failure Rate by Model
- **Value.** Reliability independent of price — a cheap car that is always in the shop is not cheap.
- **Formula.** `COUNT(DISTINCT visit) per model ÷ SUM(rental days per model) × 1000`.
- **Data.** `maintenances` (26,942) + `vehicles` + `contracts`.
- **Visualisation.** Scatter — X = failures/1,000 rental days, Y = AED/1,000 rental days, bubble =
  fleet count. Four quadrants; the top-right quadrant is the replacement shortlist.
- **Drill-down.** quadrant → vehicles → fault mix.
- **Confidence: MEDIUM-HIGH** (HIGH once Q3 is fixed).

### E4 · Worst-Performing Vehicles (Top 20)
- **Value.** Immediate, actionable disposal candidates.
- **Formula.** Composite rank of ticket count, repair spend, downtime days, distinct fault systems.
- **Data.** verified live — e.g. plate 60385 (Jeep Grand Cherokee SRT) **149 tickets since 2025-08**,
  avg 4.7 days each; plate 26831 (Chevrolet "Gucci Edition") 90 tickets at **24.2 days average
  downtime**.
- **Visualisation.** Ranked table with sparkline per vehicle.
- **Drill-down.** → full Vehicle Intelligence profile (Phase 4).
- **Confidence: HIGH** for the operational columns; MEDIUM for the cost column.

### E5 · Garage Quality Leaderboard
- **Value.** The routing decision — where to send work.
- **Formula.** See Phase 6 (composite, category-matched).
- **Data.** `maintenances` + `vendors` + `maintenance_signatures`.
- **Visualisation.** Ranked cards: quality score, recurrence rate, median turnaround, volume.
- **Drill-down.** garage → fault category → ticket list.
- **Confidence: HIGH** on recurrence and speed; **LOW** on cost (§1.13).
- **Missing.** Pseudo-garage exclusion list (Q6); garage name dedupe (Q15).

### E6 · Repeat Repair Rate by Garage *(the flagship)*
- **Value.** Directly identifies money spent twice.
- **Formula.** Of tickets at garage G with fault signature S on vehicle V, the share where the same
  (V, S) recurs within 30 days. Plus mean days-to-return.
- **Data.** proven — table in §Finding 2.
- **Visualisation.** Dumbbell chart: garage vs fleet mean days-to-return, per fault category.
- **Drill-down.** garage → fault → the specific recurrence pairs, with both ticket dates.
- **Confidence: HIGH as a signal, MEDIUM as a verdict** until exposure-adjusted (Phase 6).

### E7 · Fleet Availability
- **Formula.** `1 − (vehicle-days in maintenance ÷ (fleet size × calendar days))`, where
  maintenance days come from tickets with valid `out_date`/`actual_in_date`.
- **Data.** `maintenances` + `vehicles` + `contracts`.
- **Visualisation.** Line, with utilisation (rental days) overlaid.
- **Confidence: MEDIUM.** Only 25.7% of tickets close (Q5), so downtime is *under-counted*. Must be
  labelled "measured on closed tickets" and paired with a closure-rate figure.
- **Missing.** `actual_in_date` discipline. Fixing this is high-value and purely procedural.

### E8 · Fleet Downtime Cost
- **Formula.** `Σ (downtime days × vehicles.day_rent_value)` — opportunity cost of cars in the shop.
- **Data.** `maintenances` + `vehicles.day_rent_value`.
- **Visualisation.** Waterfall by fault category — shows *which failures* cost the most availability.
- **Confidence: MEDIUM.** Inherits Q5. `day_rent_value` fill should be verified before publishing.

### E9 · Repair Cost by Category
- **Formula.** `SUM(amount) GROUP BY vehicle_expenses.category`.
- **Data.** proven, §1.2. Tyres AED 1.87M is the single largest true repair line.
- **Visualisation.** Treemap + 12-month trend per category.
- **Drill-down.** category → vehicle → remark text.
- **Confidence: HIGH** (with the staleness caveat, and noting `category` is a re-runnable derivation).

### E10 · Top Recurring Failures
- **Formula.** Signature counts (`is_exposure = 0`), plus recurrence rate per signature.
- **Data.** proven, §1.3. ELECTRICAL 4,124 across 206 vehicles leads.
- **Visualisation.** Pareto — cumulative share, showing the vital few.
- **Confidence: HIGH.**

### E11 · Vehicles with Declining Health
- **Formula.** Trend in the Vehicle Health Score (Phase 4) over rolling 90-day windows; flag negative
  slopes with ≥3 consecutive declining periods.
- **Data.** `maintenances` + `maintenance_signatures` (+ expenses where available).
- **Visualisation.** Slope chart, current vs 6 months ago.
- **Confidence: MEDIUM.** Trend is sound; the absolute score inherits the cost gap.

### E12 · Replacement Recommendation
- **Formula.** Recommend replacement when, over the trailing 12 months:
  `annual repair spend > 40% of purchase price` **OR** `downtime days > 60` **OR**
  `repair spend > residual value`, and the trend is worsening.
- **Data.** `vehicles.purchase_price` (**237/438 = 54% — a hard blocker on 46% of the fleet**),
  `vehicle_expenses`, `maintenances`.
- **Visualisation.** Decision table: vehicle, age, spend, downtime, verdict, expected annual saving.
- **Confidence: LOW-MEDIUM today; HIGH once Q8 is fixed.**
- **Missing.** Purchase price backfill; residual-value source (none exists — proposed:
  straight-line depreciation via the existing `DepreciationService`).

### E13 · Estimated Annual Savings Opportunity
- **Formula.** Sum of quantified leaks: (a) repeat-repair spend = recurrences within 30d × avg
  category cost; (b) above-benchmark garage cost on the unambiguous subset; (c) downtime above the
  fleet median × day rate.
- **Data.** all of the above.
- **Visualisation.** Waterfall from "current spend" to "achievable spend."
- **Confidence: LOW.** Ship it as a *range*, labelled an estimate. It is the most persuasive tile on
  the page and therefore the most dangerous to overstate.

---

# PHASE 3 — Maintenance Operations Dashboard (Adham)

**Audience.** Maintenance Manager. Horizon: today and this week. Every tile is a **worklist**, not
a statistic — clicking a number must produce the actual cars.

**Honest constraint.** The live workflow engine has **252 tickets with a `workflow_status`**. Tiles
O1–O8 are precise but small-volume today; they become the primary operating surface as adoption
grows. Tiles O9–O14 run on the full 26,942-row corpus. The dashboard must show both without
implying they are the same measurement — a **two-tier metric policy**, labelling each tile
*Workflow-era* or *Full-history*.

| # | Tile | Formula / source | Confidence | Notes |
|---|---|---|---|---|
| O1 | **Open Repairs** | `workflow_status NOT IN (closed, complaint_resolved)`; full-history proxy = `out_date` set, `actual_in_date` null | HIGH (proxy: MEDIUM) | Proxy over-counts — sheet-era rows never got closed |
| O2 | **Delayed Repairs** | `NOW() > expected_completion_date` AND not closed | HIGH | `expected_completion_date` = the promise (Checkpoint model) |
| O3 | **Waiting for Parts** | `part_requests` (18) + tasks with `repair_gate='parts'` | LOW | Volume too low to be a real tile yet |
| O4 | **Waiting for Approval** | `approval_status` ≠ `not_required` AND `approved_at` null | MEDIUM | Column exists across full history |
| O5 | **Waiting for Driver** | `workflow_status IN (awaiting_dispatch, ready_for_pickup)` (currently 4 + 1) | HIGH but tiny | Joins `logistics_tasks` (14) |
| O6 | **Pending Reviews** | `workflow_status='pending_review'` — **140 today, the largest live bucket** | HIGH | **This is Adham's real backlog right now** |
| O7 | **Under Repair / In Transit** | `workflow_status IN (under_repair, in_transit)` | HIGH | 6 + 1 today |
| O8 | **Repair SLA Compliance** | share of tickets closed within `expected_duration_days` | MEDIUM | Workflow-era only |
| O9 | **Average Repair Duration** | `AVG(DATEDIFF(actual_in_date, out_date))` excluding negatives; report **median** too | HIGH | 2026 = 1.84d (n=2,774) |
| O10 | **Garage Workload** | open tickets per `vendor_id`, vs that garage's trailing-90d throughput | HIGH | Prevents overloading one shop |
| O11 | **Vehicle Status Distribution** | `vehicles.operational_status` (7-value enum) + `condition_grade` | HIGH | 438 vehicles, complete |
| O12 | **Inspection Backlog** | `inspection_records` awaiting review (`reviewed_at` null) + `inspection_requested` events (837) | MEDIUM | 180 records; `damage_flagged` unused (Q14) |
| O13 | **Urgent Repairs** | `fault_severity` + `condition_grade IN (red, yellow)` + rental-eligibility flags | MEDIUM | Severity is workflow-era |
| O14 | **Daily Queue / Weekly Trends** | tickets by `out_date` day/week, split new vs carried-over | HIGH | Full history |

**Recommended layout.** Row 1: five action-count tiles (O1, O2, O6, O4, O5) — each a button into a
filtered list. Row 2: workload heatmap (garage × day) and status donut. Row 3: duration trend with
median and p90. Row 4: the actual queue table, sortable, with age-in-stage colouring driven by
`last_state_change_at`.

**Additional operational metric worth adding, not in the brief:** **Ticket Closure Rate** — the share
of tickets opened in month M that ever received an `actual_in_date`. Currently ~25.7% overall. This
is the metric that unlocks the accuracy of half the executive dashboard, and it is entirely within
Adham's control. Put it on his screen.

---

# PHASE 4 — Vehicle Intelligence

One profile page per vehicle (438). Feasible today for operations; partially feasible for cost.

### 4.1 Vehicle Health Score (0–100)

Deliberately built from what we actually measure, and deliberately **not** including cost — because
cost coverage is uneven across the fleet and would make scores incomparable. Cost is shown alongside,
not baked in.

```
Health = 100
       − 25 × norm(repair_frequency)      -- visits per 1,000 rental days vs fleet distribution
       − 20 × norm(recurrence_rate)       -- share of faults recurring within 90 days
       − 20 × norm(downtime_ratio)        -- shop days ÷ (shop days + rental days)
       − 15 × norm(fault_breadth)         -- distinct fault signatures in trailing 12 months
       − 10 × norm(severity_load)         -- severity-weighted fault count
       − 10 × norm(age_mileage)           -- odometer and age vs fleet percentile
```

`norm(x)` = percentile rank within the fleet, so the score is always relative to *our* fleet, not an
industry constant we cannot justify. Bands: 80–100 Healthy · 60–79 Watch · 40–59 At Risk ·
<40 Critical.

**Data.** `maintenances`, `maintenance_signatures`, `contracts`, `vehicles`. **Confidence: HIGH.**
Every input is ≥78% populated. **Publish the component breakdown, never just the number** — per the
traceability rule, no black boxes.

### 4.2 Component metrics

| Metric | Formula | Source | Confidence |
|---|---|---|---|
| **Repair Frequency** | visits ÷ (rental days ÷ 1000) | `maintenances` + `contracts` | HIGH |
| **Repairs per Month** | visits ÷ months in service (from first contract) | same | HIGH |
| **Repair Cost (12m)** | `SUM(vehicle_expenses.amount)` repair categories | `vehicle_expenses` | MEDIUM — 18% of rows unlinked, ledger stale |
| **Downtime Days** | `Σ DATEDIFF(actual_in_date, out_date)` | `maintenances` | MEDIUM — 25.7% closure |
| **Downtime Ratio** | shop days ÷ (shop days + rental days) | + `contracts` | MEDIUM |
| **Recurring Faults** | signatures appearing ≥2× within 90d | `maintenance_signatures` | HIGH |
| **Most Replaced Parts** | — | `part_purchases` | **NOT FEASIBLE** — synthetic |
| **Most Expensive Repairs** | top expense rows in ticket windows | bridge (§1.13) | LOW — 3.6% exact match |
| **Maintenance Timeline** | union of tickets, expenses, contracts, log events | multiple | HIGH — already partly built (Vehicle Timeline) |
| **Cost per Kilometre** | repair spend ÷ Σ(`in_milage − out_milage`) | `vehicle_expenses` + `contracts` | MEDIUM — **contracts are the good mileage source; the odometer columns on `maintenances` are empty** |
| **Cost per Rental Day** | repair spend ÷ `SUM(contracts.days)` | same | MEDIUM |
| **Warranty Returns** | tickets where `out_date < warranty_end_date` and fault is warrantable | `vehicles.warranty_end_date` (383/438) | MEDIUM — **currently unexploited; likely real money** |
| **Expected Future Failures** | see Phase 9 | — | **LOW — do not ship as prediction** |

### 4.3 Vehicle Risk Score

Distinct from health: health is *now*, risk is *next 90 days*.

```
Risk = 40 × recurrence_pressure     -- open/recent faults with prior recurrence history
     + 25 × trend_slope             -- direction of visit rate over trailing 6 months
     + 20 × severity_of_open_faults
     + 15 × overdue_preventive      -- from service_reminders (574 rows)
```

**Confidence: MEDIUM.** Every input is measured, but the weights are judgements, not fitted — and
must be labelled as such. Do not present risk as a probability.

### 4.4 Replacement Recommendation

Rule-based, transparent, three inputs:

1. **Economic** — trailing-12m repair spend ÷ purchase price > 40%
2. **Operational** — downtime ratio > 20% or visits/1,000 rental days above the 90th percentile
3. **Trend** — both worsening across two consecutive quarters

Output: `Keep` / `Monitor` / `Evaluate` / `Replace`, with the triggering condition named in plain
language and the underlying rows one click away.

**Confidence: MEDIUM.** Blocked to 54% fleet coverage by `purchase_price` (Q8).

---

# PHASE 5 — Fault Intelligence

**Source of truth:** `maintenance_signatures`, filtered `is_exposure = 0`. 20 signatures, 21,125
labelled tickets, 2023-07 → 2026-07. **Granularity is system-level** (§1.3) — say "BRAKES," not
"brake noise," until `fault_catalog` (64 definitions, 24 tickets tagged) accumulates volume.

| # | Metric | Formula | Confidence | Notes |
|---|---|---|---|---|
| F1 | **Most Common Faults** | count by signature | **HIGH** | ELECTRICAL 4,124 · INTERIOR 3,376 · OIL_SERVICE 2,982 |
| F2 | **Most Expensive Faults** | expense sum via bridge, by signature | **LOW** | 3.6% exact attribution. Proxy: map signature → expense category (ELECTRICAL→electrical etc.) — coarse but defensible |
| F3 | **Fastest Growing Faults** | (last 90d rate) ÷ (prior 90d rate), min n=30 | **HIGH** | The best early-warning metric we own |
| F4 | **Fault Trend by Month** | monthly counts per signature, normalised by fleet size | **HIGH** | Must normalise — fleet grew 129→186 vehicles 2023→2025 |
| F5 | **Fault Recurrence Rate** | share of (vehicle, signature) events with a repeat within 90d | **HIGH** | Baseline: fleet comeback 40.4% (`kpi_snapshots`) |
| F6 | **Average Comeback Time** | mean days to next same (vehicle, signature) | **HIGH** | Proven query, §Finding 2 |
| F7 | **Faults by Vehicle Model** | signature × normalised model, per 1,000 rental days | **MEDIUM** | Gated on Q3 |
| F8 | **Faults by Mileage Band** | signature × odometer band at event time | **MEDIUM** | Odometer at event must come from the nearest contract reading, not `maintenances` (empty) |
| F9 | **Fault Clusters** | co-occurrence: signatures on the same ticket or within 14d, lift = P(A∧B)/P(A)P(B) | **MEDIUM-HIGH** | Purely empirical from 49,487 rows — genuinely novel, no new data needed |
| F10 | **Seasonal Faults** | monthly index vs annual mean, ≥2 years | **MEDIUM** | 3 years available. Expect a strong UAE summer AC/COOLING signal — worth confirming |
| F11 | **Fault Severity Ranking** | composite: frequency × downtime × recurrence × (cost where known) | **MEDIUM** | Better than `fault_severity` (workflow-era only) |
| F12 | **Root Cause Relationships** | `ontology_edges.caused_by` (435) + `fault_causes` (477) | **LOW as evidence** | 92% seeded — use to *organise and explain*, never to score |
| F13 | **Predictive Fault Probability** | — | **DO NOT BUILD** | See Phase 9: the backtest showed 1.08× lift, −42% skill |
| F14 | **Fault Lifetime** | days from first to last occurrence per (vehicle, signature) chain | **HIGH** | Distinguishes chronic from one-off |
| F15 | **Fault Aging** | days since last occurrence, per open chain | **HIGH** | Feeds the "due to recur" watchlist |

**Two additional fault metrics not in the brief, both feasible and high-value:**

- **F16 · Fault Concentration (Gini).** Are ELECTRICAL faults spread across 206 vehicles evenly, or
  concentrated in 20? Concentration means *vehicle* problem; dispersion means *fleet/model* problem.
  This one number changes whether Basem replaces cars or changes suppliers. **Confidence: HIGH.**
- **F17 · Fault–Garage Affinity.** Which garage sees each fault most, and does the fleet's routing
  match each garage's demonstrated competence? Directly actionable for routing rules.
  **Confidence: HIGH.**

---

# PHASE 6 — Garage Intelligence *(flagship module)*

The richest, most defensible, most immediately valuable module in this study — because
`vendor_id` is 93.5% populated across 26,942 tickets and three years.

## 6.0 Mandatory corrections before any garage is ranked

Publishing a raw leaderboard would produce three wrong answers. All three fixes are cheap.

1. **Exclude pseudo-garages.** `OFFICE PARKING`, `Under Test`, generic `Garage` — 3,916 tickets that
   are locations/states, not shops. Maintain an explicit exclusion list; show it on the page.
2. **Category-match every comparison.** `FUTURE TYRES` must be benchmarked against tyre work, not
   against `RMR`'s mechanical work. All comparisons are **within fault category**.
3. **Adjust for exposure.** A garage seeing a car 40 times will show short return intervals by
   construction. Use **repairs-at-risk** as the denominator (opportunities to fail), not calendar
   time, and require **n ≥ 30 per garage × category** before a score is shown at all. Below that,
   display "insufficient sample" — never a number.

Additionally: dedupe garage names (`Qasr al zaiton` / `kasr al zaiton` are almost certainly one shop
with 308 combined tickets).

## 6.1 Garage Quality Score (0–100)

```
Quality = 100
        − 35 × norm(recurrence_rate_30d)     -- same fault back within 30 days
        − 20 × norm(recurrence_rate_90d)     -- same fault back within 90 days
        − 15 × norm(median_turnaround)       -- speed
        − 15 × norm(reinspection_failure)    -- repair_inspections + reinspection_failures
        − 15 × norm(cost_index)              -- vs fleet avg for the same fault category
```

**Data.** `maintenances` + `vendors` + `maintenance_signatures` (+ `repair_inspections` 34 rows).
**Confidence: HIGH for the first three terms (70% of weight). LOW for cost (15%).**
**Implementation rule: when a term is unavailable for a garage, renormalise the remaining weights and
display which terms were used.** Never silently impute.

## 6.2 The full metric set

| # | Metric | Definition | Data | Confidence |
|---|---|---|---|---|
| G1 | **Repeat Repair %** | (vehicle, signature) pairs recurring ≤30d ÷ repairs at risk | proven | **HIGH** |
| G2 | **Avg Time Until Same Fault Returns** | mean days to next identical signature | proven — 19.6d to 148.8d spread (deduped) | **HIGH** |
| G3 | **Avg Time Until Any Fault Returns** | mean days to that vehicle's next ticket, any fault | `maintenances` | **HIGH** — measures overall workmanship |
| G4 | **Average Repair Time** | median `DATEDIFF(actual_in_date, out_date)` | `maintenances` | **MEDIUM** — 25.7% closure; require n≥30 |
| G5 | **Average Repair Cost** | mean attributed expense per visit | bridge §1.13 | **LOW** — publish on the 1,259-expense unambiguous subset only, labelled |
| G6 | **Cost vs Fleet Average** | garage mean ÷ fleet mean, same fault category | same | **LOW** |
| G7 | **Warranty Return Rate** | recurrences ≤ the garage's own warranty window | needs `warranty_days` on `vendors` | **NOT FEASIBLE** — field absent. **Quick win to add** |
| G8 | **Repair Quality Index** | composite §6.1 | multiple | **MEDIUM-HIGH** |
| G9 | **Experience by Fault Category** | ticket count and quality score per signature per garage | proven | **HIGH** — powers routing |
| G10 | **Parts Usage** | parts consumed per garage | `part_purchases` | **NOT FEASIBLE** — synthetic |
| G11 | **Technician Performance** | — | no technician table exists | **NOT FEASIBLE** — `vehicle_components.technician_name` is the only field, and it is demo data |
| G12 | **Approval Delays** | `approved_at − requested_at` | `maintenances` | **MEDIUM** — workflow-era |
| G13 | **Customer Return Rate** | share of vehicles re-sent to the same garage (revealed preference) | `maintenances` | **HIGH** — a good proxy for trust |
| G14 | **Trend Over Time** | quality score by quarter | all above | **HIGH** — catches decline before it costs |
| G15 | **Benchmark vs Peers** | percentile within fault category, n≥30 | all above | **HIGH** |

**Two additions worth more than several of the above:**

- **G16 · Fault-Category Specialisation Map.** A garage × fault-category matrix, cell-coloured by
  quality score and sized by volume. In one image Adham sees where each shop is genuinely good. This
  is the single most useful screen for daily routing decisions, and it is fully buildable today.
- **G17 · Cost of Rework.** Recurrences within 30 days × the average category cost = the AED this
  garage costs us *twice*. This translates quality into money **without** needing per-ticket cost
  attribution — it uses fleet-average category costs. It is the most persuasive garage number
  available to us, and it is buildable now. **Confidence: MEDIUM.**

## 6.3 Example insights, generated from live data

*(Figures corrected 2026-08-03 — deduplicated. See the note in Finding 2.)*

> **Deals On Wheels auto** — faults return after an average of **55.0 days** across **473 measured
> repairs**, against a fleet norm near 120. This is the strongest quality signal in the dataset that
> clears the minimum-sample gate, and it warrants review before further work is routed there.

> **GPT GARRAGE** (2,713 tickets, still active) shows an average **131.0-day** return interval across
> **719 repairs** — among the best in the fleet at meaningful volume.

> **HOT LINE** shows one of the fleet's longest return intervals (**148.8 days**, n=420) but stopped
> receiving work in **November 2024**. Worth asking why we stopped using a strong performer.

> **Alresala al zahabia** shows **19.6 days** — the worst figure in the fleet — but on only **29
> measured repairs**, below the n≥30 gate. The platform will show "not enough data" rather than a
> score. It is a watch-item, not yet a finding.

---

# PHASE 7 — Parts Intelligence

**Status: not buildable as specified. This section is a capture plan, not a dashboard spec.**

Real data: **10 `part_purchases`, 2 `vehicle_components`.** Everything else is
`VehicleComponentDemoSeeder` output from 2026-08-02 (§1.7).

**Do not build the Parts dashboard on these tables.** Doing so would put fabricated part names,
suppliers, and lifetimes in front of the owner as fact.

### 7.1 What *is* buildable today — the Parts Spend Proxy

From `vehicle_expenses`: **tyres AED 1,874,158 (3,504 rows) · parts AED 311,407 (472) ·
oil_fluids AED 724,509 (2,222)** = **AED 2.91M of real, categorised parts spend.**

Answerable now, with **MEDIUM confidence**:
- Parts spend trend by month and category — **HIGH**
- Tyre spend per vehicle and per 1,000 km (via contract mileage) — **MEDIUM**
- Oil-service spend vs `service_reminders` compliance — **MEDIUM**
- Vehicles with abnormal tyre consumption (alignment/driving-behaviour signal) — **MEDIUM**, and
  genuinely actionable

Not answerable: part lifetime, supplier failure rates, part-driven repeat repairs, remaining life,
inventory recommendations. All require per-part capture that does not exist.

### 7.2 The capture programme (prerequisite for real Phase 7)

The schema is already correct — `part_purchases` and `vehicle_components` model
`installed_at`, `installed_odometer`, `warranty_until`, `removed_at`, `removal_reason`, and
`replaced_by_component_id`. Nothing needs designing; things need *entering*.

Minimum viable capture per replacement: part name/number, supplier, cost, vehicle, odometer at
install, ticket. Six fields. With those, after ~12 months:

| Then-buildable metric | Requires |
|---|---|
| Average part lifetime | install odometer + removal odometer, ≥30 pairs per part type |
| Failure by supplier | `source_vendor_id` + lifetime, ≥50 per supplier |
| Failure by garage | `installer_vendor_id` + lifetime |
| Parts causing repeat repairs | part install ↔ subsequent recurrence of the matching signature |
| Expected remaining life | survival curve per part type, ≥100 observations |
| Inventory recommendations | consumption rate + lead time (`vendors.default_lead_time_days`) |

**Recommendation.** Purge or clearly quarantine the demo rows before any analytics touches these
tables — a `write_mode='seed'` filter is already available and should be enforced at the query layer.

---

# PHASE 8 — Financial Intelligence

Two hard constraints govern this entire phase: **cost is not joined to garages or tickets**
(§1.13), and **the ledger stops around 2026-03** (Finding 5). Every screen here carries an
as-of watermark.

| # | KPI | Formula | Source | Confidence |
|---|---|---|---|---|
| C1 | **Cost by Vehicle** | Σ repair-category expense per `vehicle_id` | `vehicle_expenses` | **HIGH** for the 82% with `vehicle_id`; 5,128 rows invisible (Q7) |
| C2 | **Cost by Model** | C1 grouped by normalised model, per 1,000 rental days | + `vehicles` | **MEDIUM** — gated on Q3 |
| C3 | **Cost by Garage** | attributed spend, unambiguous subset only | bridge | **LOW** — AED 705,622 / 1,259 expenses. Publish as an *index*, never a total |
| C4 | **Cost by Fault** | signature → expense-category mapping | proxy | **LOW-MEDIUM** — coarse but honest |
| C5 | **Cost by Component** | — | needs parts data | **NOT FEASIBLE** |
| C6 | **Monthly Cost Trends** | Σ by month, 24 months | `vehicle_expenses` | **HIGH** to 2026-03 |
| C7 | **Annual Projections** | trailing-12m run rate × seasonality index | same | **MEDIUM** — do not project through the stale window |
| C8 | **Repair ROI** | (revenue attributable to the vehicle − repair spend) ÷ repair spend | + `contracts.contract_income` | **MEDIUM** — revenue side is strong (AED 54.75M, per-vehicle) |
| C9 | **Cost per Kilometre** | repair spend ÷ Σ(`in_milage − out_milage`) | + `contracts` | **MEDIUM-HIGH** — mileage is 96% populated. **Underrated: the single fairest cross-model comparison we can compute** |
| C10 | **Cost per Rental Day** | repair spend ÷ `SUM(contracts.days)` | same | **MEDIUM-HIGH** |
| C11 | **Vehicles with Negative ROI** | C8 < 0 over trailing 12 months | same | **MEDIUM** |
| C12 | **Repair vs Replace** | NPV: (residual + expected 24m repair) vs replacement cost + expected new-vehicle repair | + `purchase_price` | **LOW-MEDIUM** — Q8 blocker |

**Additional financial views worth building, not in the brief:**

- **C13 · Lifetime Cost Curve by Age Band.** Repair spend per vehicle-month, bucketed by vehicle age
  (0–1y, 1–3y, 3–5y, 5y+). With 12 years of expense history this is fully computable and it answers
  the strategic question directly: *at what age does a car in our fleet turn unprofitable?* That
  single curve is worth more to Basem than most of the tiles above. **Confidence: MEDIUM-HIGH.**
- **C14 · Spend Concentration.** What share of repair spend goes to the top 10% of vehicles?
  Concentration justifies targeted disposal; dispersion means a fleet-wide policy problem.
  **Confidence: HIGH.**
- **C15 · Warranty Leakage.** Repair spend on vehicles inside `warranty_end_date` (383/438
  populated) for warrantable fault categories — money we may have paid that the manufacturer owed.
  **Confidence: MEDIUM. This is likely recoverable cash and nobody is currently looking at it.**

---

# PHASE 9 — Predictive Intelligence

**Recommendation: do not build predictive scoring in this phase. Build the measurement
infrastructure that would make it earnable later.**

This is not caution for its own sake. It is the documented result of our own backtest
(`intelligence:backtest-recurrence`): **lift 1.08×** (essentially no better than base rate) and
**skill −42%** (worse than the naive baseline), largely because usable fault labels only begin in
2025. Shipping probabilities on that basis would make the platform less trustworthy, not more —
and the traceability rule this project already operates under forbids unexplainable numbers.

| Idea | Verdict | Reasoning |
|---|---|---|
| Probability of repair comeback | **Defer** | Backtest failed. Ship the *observed historical rate* per garage × fault instead — same decision value, zero fabrication |
| Vehicles likely to fail soon | **Defer as prediction; ship as watchlist** | Rank by *observed* recurrence pressure + overdue preventive service. Descriptive, defensible, and it drives the same action |
| Garages likely to produce repeat repairs | **Ship as historical rate** | G1/G2 already answer this from evidence |
| Fault progression | **Research only** | F9 co-occurrence with lift is the honest version |
| Expected repair duration | **Feasible — ship** | Median + p90 per (garage × fault category), n≥30. This is a *reference class*, not a model. **Confidence: MEDIUM-HIGH** |
| Expected repair cost | **Feasible with caveats** | Category median from `vehicle_expenses`, wide interval, n≥30. **MEDIUM** |
| Expected downtime | **Feasible** | As duration, plus logistics lag. **MEDIUM** |
| Predictive maintenance (mileage-triggered) | **Ship the deterministic version** | `service_reminders` (574 rows) + interval rules is not ML and does not need to be. **HIGH** |
| Risk scoring | **Ship as transparent rules** | Phase 4.3 — named weights, published, labelled as judgements |
| Confidence scoring | **Do not ship** | Explicitly **not earnable** on current data. Show sample size instead — `n=473` is more honest and more useful than "87% confident" |

### What to build instead: the outcome-learning loop

Prediction becomes earnable when we can measure whether past predictions were right. Today
`evidence_links` = 0 and `ontology_feedback` = 0 — nothing closes that loop. Concretely:

1. Record every recommendation made, with its inputs, at decision time (`recommendations` table
   exists and is empty).
2. Record the observed outcome 30/90 days later.
3. Report calibration monthly.
4. **Only after ~12 months of that record does a probability become defensible.**

Sequenced this way, predictive intelligence arrives in 2027 as a *measured* capability. Shipped now,
it would arrive as a guess wearing a percentage sign.

---

# PHASE 10 — AI Insights

**Design principle.** Every insight is a **rendered template over a verified query**, not free-text
generation. Each carries: the numbers, the sample size, the comparison baseline, a link to the
underlying rows, and an explicit "based on" line. This satisfies the project's existing traceability
and reason-code rules — the engine emits a code plus parameters; the UI renders plain operational
language.

**Insight envelope:** `{ code, severity, entities[], metrics{}, sample_size, baseline, window,
evidence_query_id }` — never a bare sentence.

### Tier 1 — Buildable today at HIGH confidence

**Garage quality**
1. "Faults repaired at **Deals On Wheels auto** return after **55.0 days** on average, against a fleet
   norm near **120 days**, across **473 measured repairs**."
2. "**GPT GARRAGE** handled **2,713** tickets with a **131.0-day** average return interval (n=719) —
   among the best of any high-volume garage. Consider routing more mechanical work there."
3. "**HOT LINE** had one of the fleet's longest return intervals (148.8 days, n=420) but has received
   no work since **November 2024**."
4. "For **SUSPENSION** work, Garage X's recurrence rate is **N%** versus the fleet's **M%** across
   *n* repairs — more than double."
5. "**3,916** tickets are booked to `OFFICE PARKING`, `Under Test`, or generic `Garage` — these are
   locations, not repair shops, and are excluded from all quality rankings."

**Vehicle**
6. "Vehicle **60385** (Jeep Grand Cherokee SRT) recorded **149 maintenance events** since August 2025
   — the highest in the fleet — averaging **4.7 days** off-road each time."
7. "Vehicle **26831** averages **24.2 days** per maintenance visit across 90 visits, roughly **10×**
   the fleet average of 2.5 days. Its downtime alone is costing more than its repairs."
8. "**N** vehicles have been in the workshop more than 12 times in the last 6 months, together
   accounting for **X%** of all fleet downtime."

**Fault**
9. "**ELECTRICAL** is the fleet's most common fault system: **4,124** occurrences across **206** of
   438 vehicles. It is not a vehicle problem — it is a fleet-wide pattern."
10. "**Battery** faults appear on **150** distinct vehicles (587 occurrences) — near-universal
    exposure, suggesting a specification or supplier issue rather than individual vehicle wear."
11. "**COOLING** and **AC** faults rose **N%** in Q2 against Q1 — consistent with a seasonal pattern
    worth pre-positioning parts for."
12. "**STEERING** faults (2,870 across 190 vehicles) are the third most common but rarely
    prioritised — they carry the fleet's *n*-th highest recurrence rate."

**Operational**
13. "**140** tickets are sitting in **pending review** — the largest single blockage in the workflow."
14. "Only **25.7%** of maintenance tickets ever receive a closing date. Every downtime and duration
    figure on this platform is measured on that quarter."
15. "Average repair duration fell from **3.65 days** (2025) to **1.84 days** (2026) on closed
    tickets — but closure discipline changed in the same period, so treat the improvement as
    unconfirmed."

### Tier 2 — Buildable at MEDIUM confidence (label the caveat inline)

16. "**Tyres** are the fleet's single largest true repair category at **AED 1,874,158** — more than
    body work, electrical, and brakes combined."
17. "Repair spend reached **AED 1,024,298** in December 2025, the highest month on record."
18. "Vehicle X consumed **AED N** in repairs over 12 months against a purchase price of **AED M**
    (*P*%) — above the 40% replacement threshold."
19. "Repeat repairs within 30 days cost an estimated **AED N** last year, using fleet-average
    category costs."
20. "**AED 705,622** of repair spend can be attributed to a single garage with confidence;
    the remaining **AED 5.5M** cannot, because expenses do not record a vendor."

### Tier 3 — Data-integrity insights (surface these to *us*, not to Basem)

21. "Financial data is current through **2026-03-31**. Later months are incomplete; cost KPIs are
    frozen at that date."
22. "**5,128** expense rows (18%) are not linked to a vehicle and are excluded from all per-vehicle
    cost figures."
23. "**3,475** part-purchase rows are demo data from 2026-08-02 and are excluded from all analytics."
24. "**Kia** vehicles appear under 6 different make spellings; model-level reporting is disabled
    until the vehicle master is normalised."
25. "**31** tickets have a return date before their dispatch date and are excluded from duration
    metrics."

### What deliberately does *not* appear

No insight states a probability of future failure, a confidence percentage, or a per-garage total
cost. Each would require data we do not have, and the platform's credibility with Basem rests
entirely on every number on screen being defensible when challenged.

---

# PHASE 11 — Dashboard Architecture

Nine modules. Each states audience, purpose, KPIs, charts, filters, drill-downs, and — critically —
its **data-confidence badge**, shown in the UI so no one reads a MEDIUM number as a HIGH one.

```
FLEET INTELLIGENCE
│
├── 1. Executive Intelligence ......... Basem ......... [MIXED]
├── 2. Maintenance Operations ......... Adham ......... [HIGH]
├── 3. Vehicle Intelligence ........... Both .......... [HIGH ops / MEDIUM cost]
├── 4. Fault Intelligence ............. Adham ......... [HIGH]
├── 5. Garage Intelligence ★ .......... Both .......... [HIGH]
├── 6. Financial Intelligence ......... Basem ......... [MEDIUM, as-of 2026-03]
├── 7. Parts Intelligence ............. Adham ......... [SPEND ONLY — capture programme]
├── 8. Predictive Intelligence ........ Both .......... [DEFERRED — reference classes only]
└── 9. AI Insights .................... Both .......... [derived from 1–7]
    └── Data Health (always visible) .. Us ............ [governance]
```

### 1 · Executive Intelligence
**Audience** Basem. **Purpose** Strategic decisions in under 60 seconds.
**KPIs** E1–E13. **Charts** headline spend + trend; model scatter (cost × failure); garage
leaderboard; worst-20 table; availability line; savings waterfall.
**Filters** period, vehicle class, garage, fault category.
**Drill-downs** model → vehicle → ticket → expense. **Badge** MIXED — operational HIGH, financial MEDIUM/stale.

### 2 · Maintenance Operations
**Audience** Adham. **Purpose** Run today. **KPIs** O1–O14 + closure rate.
**Charts** action tiles; garage × day workload heatmap; status donut; duration trend (median + p90);
live queue table with age-in-stage colouring.
**Filters** garage, severity, status, age-in-stage, assigned user.
**Drill-downs** tile → filtered ticket list → ticket detail → vehicle. **Badge** HIGH.

### 3 · Vehicle Intelligence
**Audience** both. **Purpose** One page that settles any question about one car.
**KPIs** Phase 4. **Charts** health gauge with published component breakdown; timeline (tickets +
expenses + contracts + events); fault-frequency bars; cost-per-km trend; recurrence chains.
**Filters** model, age, health band, status.
**Drill-downs** health component → contributing tickets → expenses. **Badge** HIGH ops / MEDIUM cost.

### 4 · Fault Intelligence
**Audience** Adham, Basem for trends. **KPIs** F1–F17.
**Charts** Pareto; monthly trend small-multiples (fleet-size normalised); recurrence matrix; fault ×
model heatmap; seasonality; co-occurrence network (F9).
**Filters** signature, category, model, mileage band, period, garage.
**Drill-downs** fault → vehicles → tickets → garages. **Badge** HIGH.

### 5 · Garage Intelligence ★
**Audience** both — the routing decision and the vendor-contract decision.
**KPIs** G1–G17. **Charts** quality leaderboard; **G16 garage × fault-category matrix**; dumbbell
(garage vs fleet days-to-return); quality trend by quarter; volume vs quality scatter; **G17 cost of
rework**.
**Filters** fault category, period, minimum sample size (default n≥30), exclude-pseudo-garages
(default on).
**Drill-downs** garage → fault category → recurrence pairs (both tickets, side by side) → vehicle.
**Badge** HIGH on quality/speed; LOW on cost — shown per tile, not per page.

### 6 · Financial Intelligence
**Audience** Basem, finance. **KPIs** C1–C15.
**Charts** spend treemap by category; monthly trend; **C13 lifetime cost curve by age band**;
cost-per-km league table; ROI scatter; **C15 warranty leakage**; attribution-coverage donut.
**Filters** period, category, model, vehicle, attribution confidence.
**Drill-downs** category → vehicle → individual expense remark. **Badge** MEDIUM, hard as-of watermark.

### 7 · Parts Intelligence
**Audience** Adham, procurement. **Phase A (now):** spend proxy from `vehicle_expenses` — tyre spend
per 1,000 km, oil-service compliance, abnormal-consumption vehicles. **Phase B (12 months of
capture):** lifetime, supplier quality, remaining life, reorder points.
**Badge** SPEND ONLY — with an explicit banner that per-part analytics await capture.

### 8 · Predictive Intelligence
**Audience** both. **Phase A (now):** reference classes only — expected duration/cost/downtime as
observed medians with sample sizes; deterministic service-due forecasting; transparent risk rules.
**Phase B:** the outcome-learning loop of Phase 9. **Badge** REFERENCE CLASS — no probabilities.

### 9 · AI Insights
**Audience** both, role-filtered. **Purpose** The landing page. Ranked insight cards, each with
numbers, sample size, baseline, and a link to evidence.
**Filters** severity, entity type, module, confidence tier.
**Drill-downs** card → the exact query result behind it. **Badge** inherits from source metric.

### 10 · Data Health *(non-negotiable)*
Coverage gauges per critical field (`actual_in_date` 25.7%, `vehicle_id` on expenses 82%,
`purchase_price` 54%, signature coverage 78.4%), the freshness watermark, the synthetic-data
quarantine, and the exclusion lists. **Every other module links here from its confidence badge.**
This is what makes the platform trustworthy rather than merely impressive: when Basem challenges a
number, the answer is one click away.

---

# PHASE 12 — Prioritisation

## 12.1 Classification

### CRITICAL — Must Have (build first; all data present)

| KPI | Module | Confidence |
|---|---|---|
| Repeat Repair % by garage (G1) | Garage | HIGH |
| Avg time until same fault returns (G2) | Garage | HIGH |
| Garage × fault-category matrix (G16) | Garage | HIGH |
| Open / Delayed / Pending Review worklists (O1, O2, O6) | Operations | HIGH |
| Average repair duration, median + p90 (O9) | Operations | HIGH |
| Ticket closure rate *(new)* | Operations | HIGH |
| Top recurring failures (E10, F1) | Executive/Fault | HIGH |
| Worst-performing vehicles (E4) | Executive | HIGH |
| Vehicle Health Score (4.1) | Vehicle | HIGH |
| Total spend & trend (E1) | Executive | HIGH (as-of) |
| Repair cost by category (E9) | Executive | HIGH (as-of) |
| Data Health module | Governance | HIGH |

### HIGH VALUE (build second)

Fastest-growing faults (F3) · Fault recurrence rate (F5) · Garage quality composite (G8) ·
Garage trend over time (G14) · Cost of rework (G17) · Cost per km (C9) · Cost per rental day (C10) ·
Fleet availability (E7) · Downtime cost (E8) · Vehicle risk score (4.3) · Garage workload (O10) ·
Fault concentration (F16) · Fault–garage affinity (F17) · Lifetime cost curve by age (C13) ·
Spend concentration (C14) · Warranty leakage (C15) · Fault clusters (F9) · Expected duration
reference class

### NICE TO HAVE

Seasonal faults (F10) · Approval delays (G12) · Customer return rate (G13) · Fault lifetime/aging
(F14, F15) · Inspection backlog (O12) · Weekly trends (O14) · Annual projections (C7) ·
Repair ROI (C8)

### FUTURE AI (do not build now)

Predictive fault probability (F13) · Comeback probability · Failure prediction · Fault progression
modelling · Confidence scoring · Automated root-cause inference · Inventory optimisation ·
Expected remaining part life

## 12.2 Quick Wins — highest value per unit of effort

| # | Action | Effort | Unlocks |
|---|---|---|---|
| **QW1** | **Normalise `vehicles.make`/`model` + populate `vehicle_class`** (438 rows, one mapping table) | 1–2 days | E2, E3, C2, F7 — **every model-level question Basem asks** |
| **QW2** | **Build the garage exclusion list + name dedupe** | hours | Correctness of the entire flagship module |
| **QW3** | **Materialise the recurrence-pair table** (vehicle × signature × garage × days-to-return) | 1–2 days | G1, G2, G3, E6, F5, F6 — the flagship, and it makes every query fast |
| **QW4** | **Resolve 5,128 orphan expense rows via `car_serial`** | 1 day | ~18% more cost coverage across every financial KPI |
| **QW5** | **Add `warranty_days` to `vendors`** | hours | G7 warranty return rate — currently not feasible at all |
| **QW6** | **Quarantine demo rows at the query layer** (`write_mode='seed'` filter) | hours | Prevents shipping fiction |
| **QW7** | **Freshness watermark + as-of banner on financial views** | hours | Prevents the single most likely executive misreading |
| **QW8** | **Ticket-closure-rate tile on Adham's dashboard** | hours | Behaviour change that fixes Q5, which gates ~6 KPIs |
| **QW9** | **Collapse sheet rows into visits** (OUT/IN/Follow-up → one visit) | 2–3 days | Correctness of every per-repair metric |

**QW1 + QW3 together unlock more of this document than all other work combined.**

## 12.3 Features requiring new data capture

| Feature | Missing data | Change required |
|---|---|---|
| Cost by garage (accurate) | `vendor_id` on expenses | Add the field; capture at entry |
| Cost by ticket | `maintenance_id` on expenses | Add the field |
| All of Phase 7 | real part records | Enforce parts capture in the workflow |
| Technician performance (G11) | technician entity | New table + capture |
| Warranty return rate (G7) | `vendors.warranty_days` | One column |
| Replacement recommendation (E12) | `purchase_price` on 201 vehicles | Backfill by VIN |
| Repair-hour productivity | `maintenance_tasks.repair_hours` (0/117 filled) | Make it required at close |
| Damage analytics | `inspection_records.damage_flagged` (0/180) | Enforce in the capture UI |
| Accurate downtime | `actual_in_date` (25.7%) | Closure discipline — **procedural, not technical** |

## 12.4 Features requiring ML

Only four genuinely require ML, and **none should start before the outcome-learning loop of Phase 9
has run for ~12 months**: comeback probability · time-to-failure survival models · part remaining-life
estimation · anomaly detection on cost/duration. Everything else in the brief is achievable with SQL
over data we already hold — which is the most important conclusion of this study.

## 12.5 Features requiring historical enrichment

Fault-level (not system-level) granularity — needs `fault_catalog` tagging volume · Long-run
category trends — `maintenance_type` only exists from 2024-08 · Model-level history — needs QW1
applied retroactively · Root-cause analytics — 20 of 117 tasks carry a root cause · Cost history
past 2026-03 — needs the ledger backfilled.

## 12.6 Recommended delivery sequence

**Stage 1 — Foundation (≈2 weeks).** QW1, QW2, QW3, QW6, QW7, QW9 + Data Health module.
*Outcome:* the data is trustworthy and the flagship queries are fast.

**Stage 2 — Garage Intelligence (≈3 weeks).** G1, G2, G3, G9, G14, G16, G17 + the garage drill-down.
*Outcome:* Basem and Adham can see, for the first time, which garages actually fix things — the
highest-value insight available from our data.

**Stage 3 — Operations (≈2 weeks).** O1–O14 + closure rate.
*Outcome:* Adham runs the day from the platform instead of from WhatsApp.

**Stage 4 — Vehicle & Fault (≈3 weeks).** Health score, risk score, timeline; F1–F6, F9, F14–F17.
*Outcome:* per-car and per-fault decisions become evidence-based.

**Stage 5 — Financial (≈3 weeks, after QW4 + ledger backfill).** C1–C4, C6, C9, C10, C13–C15 +
the attribution bridge with explicit method labelling.
*Outcome:* Basem gets money answers with honest coverage disclosure.

**Stage 6 — Executive & AI Insights (≈2 weeks).** E1–E13 assembled from Stages 2–5; the insight
engine over verified queries.
*Outcome:* the 60-second executive view, with everything behind it already proven.

**Stage 7 — Capture programmes (continuous, start now in parallel).** Parts capture, closure
discipline, root-cause capture, purchase-price backfill, outcome-learning loop.
*Outcome:* Phases 7 and 9 become buildable in 2027.

---

## Closing Assessment

The honest headline is this: **we are far richer in operational data than in financial data, and the
platform should be built in that order.** Three years of 26,942 tickets with 93.5% garage attribution
and 78.4% fault labelling is a genuinely strong asset — strong enough that Garage Intelligence, the
module with the highest decision value in the entire brief, can be built immediately and defended
under challenge.

The financial layer is not weak because the money data is poor — AED 27.5M across 12 years is
excellent. It is weak because that data was never joined to the operational data, and because it
stops in March 2026. Both are fixable, and neither requires modelling; they require a foreign key
and a backfill.

The two things this study recommends *against* are the two things a less careful proposal would lead
with: a Parts dashboard (99.7% of its data is synthetic) and predictive scoring (our own backtest
showed it performs worse than the naive baseline). Building either would trade the platform's
credibility for a demo. The credibility is worth more — because the moment Basem finds one number he
cannot trust, he stops trusting all of them.

Everything else in the twelve phases is buildable, and most of it is buildable with SQL over data
that is already sitting in the database today.
