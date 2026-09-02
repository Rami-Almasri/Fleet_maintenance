# Fleet Platform — Execution Blueprint

**Date:** 2026-08-01 · **Status:** the project's governing execution document.
Supersedes the audit reports *for sequencing*; they remain the evidence behind each item, cited by ID.

**Sources consolidated:** `QA-System-Validation-2026-08-01.md` · repair-duration audit ·
`Concept-Bridge-Error-Analysis.md` · `Vehicle-Intelligence-Architecture.md`.

**Effort notation:** `h` hours · `d` dev-days · `w` dev-weeks. Estimates from observed complexity and
blast radius, not commitments. **⚠️ soft** marks genuine unknowns, explained in the notes.

---

# Part I — Governing principles

Binding on every item. An item that cannot satisfy them is mis-scoped, not exempt.

### 1 · Every phase ends in something an operator can see
No milestone is "internal refactoring". Each names its **demonstrable outcome**. Where a change is
invisible alone (event-stream integrity, a duration value object), it is **paired in the same
milestone with the surface it unlocks**.

### 2 · Track A and Track B run in parallel
Evidence and Knowledge share almost no code — only **B-9** crosses. The platform gets more useful
every week while the evidence corpus fills.

### 3 · Phase 3 is evidence-gated, and refusal is a feature
Nothing renders without **sample size · confidence · evidence source · freshness**. When evidence is
insufficient the UI **explains why** — never a weak estimate.

> **Reuse, don't rebuild.** `App\Kpi\Kpi` already carries nullable `value`, `sampleSize`, `available`
> and `blockedReason`, with `measured()` / `insufficient()` / `unavailable()` constructors — and a
> class doc naming the exact failure mode ("a dashboard showing 0% capture-abandonment looks like a
> triumph and is actually an unwired frontend"). **`MetricValue` extends `Kpi`** (C-1), adding
> `confidence`, `evidenceSource`, `computedAt`.

### 4 · Every new service or read model is classified — and declares its evidence flow

### 5 · The evidence declaration gate

> **No feature proceeds to implementation without declaring which Evidence Artifacts it consumes and
> which (if any) it produces.**

This turns the lineage map from documentation into an architectural gate. Its real value is diagnostic:
**if the artifacts cannot be named, the feature is not yet designed.** Four failure modes it catches
before code is written:

| Answer given | What it means |
|---|---|
| *"It produces a new artifact"* | Needs a contract in `Evidence-Artifacts.md` — owner, rebuild policy, acceptance test — **before** the first migration. |
| *"It consumes E-something that is 🔴"* | It inherits that quality. Either fix the artifact first or ship a degraded mode; do not launder a red input into a confident output. |
| *"It produces a Fact"* | Rare and serious — only ~8 items in this entire plan do. Expect design scrutiny proportional to irreversibility. |
| *"I'm not sure"* | Not ready. This is the gate working. |

**Proposal template — four lines, required before implementation:**

```
Feature:     <name>
Class:       F | D | R | K | P | J
Consumes:    E<n>, E<n>…      (and the quality of each: 🟢/🟡/🔴)
Produces:    E<n> | none      (if new → contract required first)
Gate:        if any consumed artifact is 🔴, state the degraded behaviour
```

**Enforcement:** `evidence:health` (see `Evidence-Artifacts.md`), built by mirroring the existing
`SchemaHealthService::checks()/summary()/verdict()` shape and added to the `composer verify` script
next to `schema:health`. **Do not invent a second health mechanism.**

---

# Part II — The classification model

## II.a Six classes

| Tag | Class | Means | Rebuildable? | Provenance must cite | Freshness matters? | `Confidence::*` |
|:--:|---|---|---|---|:--:|---|
| **F** | **Fact** | What happened, when it happened. | ❌ **Never** — loss is permanent | the capturing event | at capture | `MEASURED` / `IMPORTED` |
| **D** | **Derived** | Computed from facts on read. Adds no new truth. | ✅ Always | its inputs + formula version | inherits inputs | `CALCULATED` |
| **R** | **Reference** | Externally-authored truth. **True regardless of our fleet.** | ✅ Re-ingestable | an external document | ❌ rarely (versioned) | `VALIDATED` (cited) / `ESTIMATED` (seeded) |
| **K** | **Knowledge** | Learned from **our** operational history. A retained belief that outlives the request. | ⚠️ Only by replaying history | fleet records + window | ✅ **always — it drifts** | `CALCULATED` / `VALIDATED` |
| **P** | **Prediction** | A statement about the future or an unobserved case. | ✅ Recomputable | its basis + calibration | ✅ always | `ESTIMATED` — **never** `MEASURED` |
| **J** | **Judgement** | A human ruling. No formula, no observation. | ❌ Not without re-asking a person | the person + timestamp | ✅ decisions go stale | `VALIDATED` |

**J is adopted** per the previous recommendation, so this scheme and the existing Evidence Layer
governance (`Fact | Judgement | Derived`) now agree rather than contradicting each other.

## II.b Why the R/K split earns its place — and what it changes

Your distinction is right, and it has a consequence beyond provenance tidiness:

> **R is available at any population. K is population-gated.**

That is precisely the Track B / Phase 3 boundary — which means the split does not just describe the
plan, it **explains** it. Re-tagging Track B under the new scheme shows that almost every item in it
is **R**: reference vocabulary, taxonomy, citations. That is *why* Track B delivers value now at
n=8 tickets, and it is the strongest formal argument for running it in parallel.

Conversely, everything you listed as fleet-learned — garage performance, repair patterns, component
reliability, supplier behaviour, cost trends — lands in **Phase 3**, where the coverage gate already
governs it. The taxonomy and the sequencing now agree.

Three rules follow from the split:

1. **R may cite an external source; K must cite fleet records.** A confidence number sourced from
   "Bosch" is R; one sourced from "34 repairs at this garage" is K. **Never let a K claim borrow R's
   authority** — that is exactly the trap in B-4 today, where seeded numbers sit under prestigious
   source names.
2. **R is versioned; K drifts.** Freshness is mandatory on K and P, near-meaningless on R. This is
   what makes principle 3's freshness requirement precise instead of blanket.
3. **R is safe to ship early; K is not.** R is wrong only if mis-authored. K is wrong if
   under-evidenced — which is the failure this whole plan exists to prevent.

## II.c The D/K boundary — a test, because this will be argued

Both compute from fleet data, so the line needs to be operational rather than intuitive:

> **Is it recomputed on read and thrown away (D), or retained and accumulated to influence future
> decisions (K)?**

| Example | Class | Why |
|---|:--:|---|
| Cost-per-km for one vehicle, computed on request | **D** | Recomputed each read; nothing persists |
| `maintenance_signatures` | **D** | Explicitly a rebuildable read-model |
| Canonical workshop duration | **D** | A formula over events |
| `garage_recommendation_decisions` + override learning | **K** | Persists a belief that changes *future* recommendations |
| `ontology_edges` where `source='fleet'` (82 rows) | **K** | Co-occurrence learned from our history, retained |
| Component reliability intervals | **K** | Accumulates; used as a prior for the next vehicle |
| `kpi_snapshots` (frozen baseline) | **K** | Retained deliberately so drift is detectable |

**Rule of thumb: if deleting it loses something you would have to re-earn over months, it is K, not D.**

---

# Part III — Evidence artifact registry

The named artifacts used in the **Produces / Consumes** columns. `▲` produces · `▼` consumes.

> **📐 Full contracts: [`Evidence-Artifacts.md`](./Evidence-Artifacts.md).** Each artifact there is a
> first-class architectural object — canonical owner (the *one* class permitted to write it),
> producers, consumers, quality, coverage, refresh strategy, rebuild/delete policy, and the
> **acceptance test that defines validity**. An artifact without a passing acceptance test is not
> evidence; it is a table. The summary below is the index.

| ID | Artifact | Class | Owner / store | State today |
|---|---|:--:|---|---|
| **E1** | Workshop events (workflow transitions) | F | `vehicle_log_events` | ⚠️ 1,979 rows, **78.8% orphaned** |
| **E2** | Ticket lifecycle & stage timestamps | F | `maintenances` | 149 with `workflow_status`; **8 complete** |
| **E3** | Canonical workshop duration | D | `WorkshopDuration` *(to build)* | ❌ six conflicting definitions |
| **E4** | Fault signatures | D | `maintenance_signatures` | 49,501; stale on new tickets |
| **E5** | Component history | F | `vehicle_components` / `component_events` | ❌ **0 / 0** |
| **E6** | Odometer chain | F | `vehicles.odometer` + ticket readings | ⚠️ 3 corrupted vehicles |
| **E7** | Repair outcomes / QC verdicts | F | `repair_inspections`, task outcomes | 9 (7 real) |
| **E8** | Spend records | F *(imported)* | `vehicle_expenses` | 28,327; <2% of repairs costed |
| **E9** | Typed concept resolutions | D | `MatchPipeline` output | ⚠️ actions resolve to faults |
| **E10** | Reference ontology (fault/repair/part/procedure) | R | `ontology_nodes` / `ontology_edges` | 1,470 nodes; **93% unreachable** |
| **E11** | Reference documents & citations | R | `knowledge_documents`, `evidence_links` | ❌ **0 / 0** |
| **E12** | Garage performance knowledge | K | `garage_recommendation_decisions` | 2 decisions |
| **E13** | Component reliability knowledge | K | *(to build — from E5)* | ❌ blocked on E5 |
| **E14** | Cost knowledge | K | *(to build — from E8)* | coverage-limited |
| **E15** | Recommendations & forecasts | P | `recommendations`, forecast calibration | 0 |
| **E16** | Demo / test classification | J | `is_demo` *(to build)* | ❌ text markers only |
| **E17** | Comeback links | F | `comeback_of_maintenance_id` *(to build)* | ❌ inferred only |
| **E18** | Provenance & confidence envelope | D | `Kpi` + `ConfidenceChip` / `EvidenceLink` | partial |

---

# Part IV — Phase 0 · ✅ COMPLETE (executed 2026-08-01)

**Demonstrable:** *an operator opens the ops board and it works, loads clean data, and shows no
fabricated repairs.* — **verified: 123-endpoint sweep returns zero 500s.**

| ID | Item | Cls | Evidence | Pri | Effort | Status |
|---|---|:--:|---|:--:|---|---|
| **A-1** | **Stop the hard-delete.** Manual workshop-event deletes now tombstone (synthetic `manual:{id}` key); immutable `maintenance_ref` added to `vehicle_log_events`. | **F** | ▲E1 | 🔴 | 1 d | ✅ **done** |
| **A-2** | **Fix the Car Status 500.** Shadowed `$delay` → `$delayTone`; `delay_status` corrected. | — | ▼E2 | 🔴 | 2 h | ✅ **done** |
| **A-3** | **Purge demo data.** Both teardowns run, then force-purged (see note). | — | ▲E16 | 🔴 | 1 h | ✅ **done** |
| **A-4** | **Odometer plausibility guard** + corrected vehicles 1781/1882/1795 and 2 tickets. | **F** | ▲E6 | 🔴 | 1 d | ✅ **done** |
| **A-5** | **Fix the permission ladder** — granted `insights.view` to `operations`. | — | — | 🟠 | 3 h | ✅ **done** |

**What was actually done**

* **A-1** — `WorkshopEventController::destroy` no longer branches: every delete tombstones.
  `tombstone()` now accepts rows with no `row_hash` via a synthetic `manual:{id}` key (a sheet event can
  be re-imported; a hand-entered one exists nowhere else, so destroying it was exactly backwards).
  Migration `2026_08_01_130000` adds `maintenance_ref` — a plain integer with **no FK**, because a
  constraint is what gives the database permission to rewrite the column. Backfilled: **420 links
  preserved, 0 mismatches.** The 1,559 already-orphaned rows are **not** recoverable and were not
  touched. Locked by `WorkshopEventDeleteKeepsTimelineTest` (5 tests).
* **A-2** — locked by `ResolvedObjectShadowingTest`, which **fails on the old code** (verified by
  reintroducing the bug: 2 of 3 tests fail) and guards the same shape in two sibling services.
* **A-3** — **11 tickets** (9 `RECUR_DEMO` + 2 `[PARTS-DEMO]`) purged, reconciling the 10-vs-11 count
  discrepancy in favour of 11. Also removed: 12 tasks, 11 line items, 2 inspections, 5 reviews, 14
  signatures. Orphan count held at 1,559 — **no new orphans created**.
* **A-4** — `OdometerContinuityService::MAX_JUMP_KM = 20,000` + `STATUS_IMPLAUSIBLE`, enforced as a hard
  block in `recordOdometerFlag` (every reading passes through it, unlike the strict-match gate which
  covers only 3 stages). Mirrored in `odometerContinuity.js`. `MAX_ODOMETER` lowered 9,999,999 →
  1,000,000. **All four corrupted values corrected from OfficeManager contracts** — an independent
  source — each with an `EVENT_ODOMETER_CORRECTED` audit row naming old value, new value and citation.
  Fleet now has **0 vehicles and 0 ticket readings above 1,000,000 km**.
* **A-5** — `operations` was the only senior role without `insights.view`; `maintenance`, `finance` and
  even read-only `viewer` all had it, so an ops manager holding `billing.manage` could not open a board
  a viewer could see. Granted in the seeder and on the live DB. Verified live: operations now 200 on
  Profitability / utilization / FinancialConflicts / intelligence-center.

**Verification:** 391 unit+feature tests pass (up from 384; +7 new). 123-endpoint sweep: **zero 500s**
(was 1). Full role matrix re-checked live.

---

## Findings discovered *during* execution — new work items

### A-21 · SoftDeletes vs raw SQL — ✅ **RESOLVED by the concurrent deletion-model work**

I raised this as Critical after proving during A-3 that soft-deleted demo tickets stayed fully visible
to raw SQL and had to be force-purged. **The observation was right; my proposed fix was wrong.**

I recommended adding `->whereNull('deleted_at')` to every raw site. That would have been a defect: a KPI
over *history* should still count a retired ticket, because the work genuinely happened.
`docs/Maintenance-Deletion-Model.md` instead audits all **56** raw sites and makes a deliberate,
per-site, documented choice:

* **LIVE** — filters `deleted_at` explicitly. Current-state questions: `OperationsService` (drives
  `vehicles.operational_status`, so a retired ticket must release the car at once),
  `FleetUtilizationService` ("still in the shop today"). **Verified applied.**
* **HISTORICAL** — deliberately includes trashed rows, declared in each class docblock:
  `OperationalKpiService`, `ForecastCalibration`, `GarageOutcomeForecaster`, `RepairCostEstimator`,
  `RepairDurationQueryService`, `VehicleFaultRecurrenceService`, `FleetEvidenceService`.
  **Verified declared.**

**Standing rule:** never add a raw query against `maintenances` without stating which of the two it is.

Two traps that survive and are worth carrying forward: the `row_hash` / `contract_id` unique indexes
still bind *retired* rows, so any insert by either key must `withTrashed()` first (and must **not** be
"fixed" with a composite unique on `(row_hash, deleted_at)` — MySQL treats NULLs as distinct); and a
soft delete is **not** enough to remove demo/test rows from KPIs, since the HISTORICAL sites keep
counting them — they must be force-deleted, exactly as A-3 had to.

### A-22 · i18n guardrail 🟡 **Medium — half fixed, half in-flight**

* ✅ **`orphan Arabic item keys: components`** — fixed. `/components` is deliberately held back as
  "Coming Soon" (see the note above the route in `App.js`), which stranded its Arabic nav entry. The
  translation is **commented, not deleted** — it is the expensive part and should return with the page.
* ⏳ **`routes missing Arabic: garage-finder`** — appeared *during* this work; `AppLayout.js` and
  `labels.js` were both being written as I checked. The Garage Finder page is mid-build. **Left to
  whoever is adding it** — inventing Arabic UI copy for someone else's in-flight page would only
  conflict. It resolves when they add the label.

### A-23 · 10 CRUD-suite failures 🟡 **Medium — still open, not ours**

`php artisan test -c phpunit.crud.xml` → **10 failed, 270 passed** (re-confirmed after Phase 0, count
unchanged). Not caused by Phase 0: the failing path (`PauseResumeMaintenanceTest` → contract create with
`pull_from_maintenance` → 422) lives in `ContractController` / `ContractService` /
`ContractEligibilityService`, none of which Phase 0 touched, all last modified **before** these changes.
**Needs triage by whoever owns the deletion-model / contract work.**

---

# Part V — TRACK A · Evidence Layer

**Exit criterion:** a ticket closed today produces a complete linked event stream and exactly one
duration number — and the count of such tickets is on screen.

### Milestone A-I · Complete history
**Demonstrable:** *the vehicle timeline stops losing events, and every page shows "history complete:
397 of 1,979 events linked" instead of a silent lie.*

| ID | Item | Cls | Evidence | Pri | Effort | Blocked by |
|---|---|:--:|---|:--:|---|---|
| **A-6** | **Complete the event stream.** `rollbackForGarageTransfer` (~:437) changes status with zero `log->record()`; `arriveAtPark`/`close`/`assignDispatch`/`dispatchRecovery` write 2 status changes but log 1. Stop `VehicleLogService` swallowing failures. Unknown-state policy (`pending_qa`: 126 rows, absent from code). | **F** | ▲E1 ▲E2 | 🔴 | **3–4 d** | A-1 |
| **A-7** | **Honest `history_complete`** — report numerator **and** denominator; today it scores 100% over survivors while 79% sits detached. | **D** | ▲E18 ▼E1 | 🟠 | **1 d** | A-6 |
| **A-8** | **Signature write on ticket close** via `recalcFromTasks()`; stamp `classifier_version`. *(H-4)* | **D** | ▲E4 ▼E2 | 🟠 | **2 d** | — |
| **A-20** | **`evidence:health` command.** Runs the acceptance test of every E1–E18 contract. **Build by mirroring `SchemaHealthService::checks()/summary()/verdict()`** — same ok/warn/fail weighting, one category per class. Add to `composer verify` beside `schema:health`. | **D** | ▲E18 ▼all | 🟠 | **3 d** | — |

**Impact:** **any reducer over the stream is wrong until A-6 lands** — it is the hardest prerequisite
for all timing analytics. A-7 makes the corpus problem legible instead of hidden. **A-20 makes the
whole evidence model enforceable** and publishes the starting scoreboard (**0 green · 5 amber ·
13 red**) so progress is measurable from week one — it is deliberately early for that reason.

### Milestone A-II · One duration, everywhere
**Demonstrable:** *every screen quotes the same repair duration for the same ticket.*

| ID | Item | Cls | Evidence | Pri | Effort | Blocked by |
|---|---|:--:|---|:--:|---|---|
| **A-9** | **One `WorkshopDuration`**, returned as an extended `Kpi`. Collapse six definitions (`MaintenanceWorkflowResource:465`, `MaintenanceOperationsService:151`, `CarStatusService:185`, `CarStatusService:554`, `OperationalKpiService:207`, `FleetUtilizationService`). **Extend `App\Kpi\Kpi`; do not create a parallel type.** | **D** | ▲E3 ▲E18 ▼E1 ▼E2 | 🟠 | **3 d** | A-6 |
| **A-10** | **Timezone correctness.** App is UTC, fleet is Dubai (UTC+4) — days roll at 04:00 local; `maintenanceSessions.js` mixes UTC `dayIndex()` with local `todayIndex()`. | **D** | ▼E3 | 🟡 | **1–2 d** | — |
| **A-11** | **Unify "comeback".** 90 d in four places vs 14 d in `features.intelligence.comeback`. `GarageRecommendationService:988–1023` computes a "comeback rate" from re-inspection failures (n=9) — a different **population** from the signature proxy (n=49,501), same label, on the vendor-ranking screen. **Rename `reinspection_pass_rate`.** | **D** | ▲E18 ▼E4 ▼E7 | 🟠 | **2 d** | — |

**Impact:** six screens quoting six durations destroys trust in every timing number. A-11 removes two
different numbers sharing one label on the screen that ranks real vendors.

### Milestone A-III · WorkshopTimeline
**Demonstrable:** *open any ticket and see its full in-shop timeline — stages, waiting, and time
punched out for rentals.*

| ID | Item | Cls | Evidence | Pri | Effort | Blocked by |
|---|---|:--:|---|:--:|---|---|
| **A-12** | **Port the dead reducer.** `maintenanceSessions.js` already models in-shop sessions with rentals punched out; `ShopTimer.js` is rendered by nothing. Port and extend (day→second, rentals→all out-of-workshop intervals). **Do not rewrite.** | **D** | ▼E1 ▼E3 | 🟡 | **3–4 d** | A-6, A-9 |
| **A-13** | **Add `comeback_of_maintenance_id`** — comeback is inferred from signature recurrence only, the documented confidence ceiling. | **F** | ▲E17 | 🟡 | **1 d** | A-11 |

**Impact:** the most visible Track A deliverable. **Scope discipline: a live per-ticket display (n=1),
not an analytics engine.** A-13 turns comeback from inference into fact.

### Milestone A-IV · Clean, fast, trustworthy
**Demonstrable:** *pages load in under a second; no test data appears in any operator view.*

| ID | Item | Cls | Evidence | Pri | Effort | Blocked by |
|---|---|:--:|---|:--:|---|---|
| **A-14** | **`is_demo` + global scope + env guard** on the five affected tables; `->withDemo()` opt-in; seeder base class refusing the production DB; `demo:status`. *(P-1)* | **J** | ▲E16 | 🟠 | **2 d** | A-3 |
| **A-15** | **Triage 139 untagged manual test tickets** (`ff123`, `check check`) mixed with genuine ones. Export with creator + timestamp, human triage, **stamp `is_demo` rather than delete**. *(L-0)* | **J** | ▲E16 | 🟡 | **2 d** ⚠️ soft | A-14 |
| **A-16** | **Paginate heavy endpoints.** `Customer` 11 MB/13.4 s · `MileageChain/audit` 1.8 MB · `incidents` 1.3 MB · `maintenance-tickets` 920 KB. *(H-5)* | — | — | 🟠 | **3 d** | — |
| **A-17** | **Cache/narrow slow aggregates.** `garage-recommendations` 8.7 s · `TripDashboard` 7.2 s · `intelligence/cost` 6.3 s. **Not an indexing problem** — EXPLAIN shows key usage throughout. *(M-6)* | **D** | — | 🟡 | **3 d** | — |
| **A-18** | **Per-fault effort capture.** `maintenance_tasks.repair_hours` is exposed but only written by `BackfillMaintenanceTasks` — a dead field pretending to be data. | **F** | ▲E2 | 🟢 | **2 d** | A-6 |
| **A-19** | **Housekeeping** — log rotation (38.9 MB unrotated); `POST /api/Vehicle` returns 200 vs 201 elsewhere. *(L-2, L-3)* | — | — | 🟢 | **3 h** | — |

**Impact:** A-14 makes contamination **inert, not merely findable** — markers currently live in
operator-editable text columns. Both A-14/15 are **J**: "is this row test data?" is a human ruling.

**Track A total: ≈5–6 weeks.** A-6 → A-9 → A-12 is the chain; A-14/16/17 parallelise.

### The three-tier evidence policy — binding on Track A and Phase 3
Never blended:
1. **Legacy sheet** (20,434 + 6,245 rows, day granularity, one row per *event*) — coarse off-road days
   **only**. A historical visit renders as a **date range**, never a duration (2,767 of 6,880 closing
   rows are same-day).
2. **Workflow, orphaned** (1,559 events) — vehicle-level history **only, permanently**. Re-linking by
   vehicle + time window is reconstruction and was forbidden.
3. **Workflow, intact** — the only tier that may produce stage timing.

---

# Part VI — TRACK B · Reference & Knowledge *(parallel to Track A)*

**Note the re-tagging:** almost every item here is now **R**, not K. That is the formal reason this
track delivers value at today's population — reference truth does not depend on fleet size.

### Milestone B-I · The matcher understands what kind of thing it read
**Demonstrable:** *type "replace alternator" into fault search and get the alternator replacement
action — not "Alternator charging fault".*

| ID | Item | Cls | Evidence | Pri | Effort | Blocked by |
|---|---|:--:|---|:--:|---|---|
| **B-1** | **Node-type-aware matching — fault \| action \| part \| procedure.** The matcher resolves against `FindingKeyword` (105 fault concepts) only, so **93% of the ontology is unreachable** — all 92 `repair`, 274 `procedure`, 271 `component` nodes. The `fixed_by` relation (405 edges) already models this and is unused. *(H-1 / CB Class A)* | **D** | ▲E9 ▼E10 | 🔴 | **2 w** | — |
| **B-2** | **Phrase-first + token floor + vocabulary gaps.** Token noise at 56–59 clears `minScore=25`: `thermostat replaced` → *Windscreen crack/chip*; `change thermostat` → *Oil Change*; `car not starting` → *A/C not cooling*. Add `thermostat` (×8), `drums` (×12), `condenser`, `axles`, `fans`, `periodic maintenance` (×43 against 2,982 OIL_SERVICE tickets). | **R** | ▲E10 | 🔴 | **1–2 w** | B-1 |
| **B-3** | **Text preprocessing.** Strip person names (`waleed` ×31), Arabic segments, encoding garbage; route operational language (`maintenance` ×206) to `non_fault`. | **D** | ▲E9 | 🟠 | **3–4 d** | — |

**Impact:** B-1 is **the hard gate on the enrichment backfill** — running it first bakes "every repair
is a failure" into 26,839 rows. It also unlocks the first real Outcome-world dataset from legacy text.

### Milestone B-II · Honest confidence
**Demonstrable:** *every confidence chip can be clicked to show its evidence — and anything without
evidence says so rather than showing 82%.*

| ID | Item | Cls | Evidence | Pri | Effort | Blocked by |
|---|---|:--:|---|:--:|---|---|
| **B-4** | **Ground or downgrade the knowledge platform.** 17 authoritative sources (OEM, Bosch, SAE, ALLDATA) with **0 documents**; `evidence_links` 0; 100% of nodes and 96% of edges `source='seed'`; confidence hardcoded on 80/82/75/70. Either ingest real documents **or** grade seeded confidence as `SEED` and forbid rendering it as evidence-backed. *(H-3)* | **R** | ▲E11 | 🟠 | **1 w** downgrade / **4 w+** ingest ⚠️ soft | — |
| **B-5** | **Provenance primitives.** `ConfidenceChip` + `EvidenceLink` as shared primitives; every engine/AI string wrapped with its confidence and fact refs. Fix: confidence currently *contradicts* relevance (junk `Engine noise` 79 vs correct `Overheating` 72); `stages` returns numeric indices not names. *(M-7)* | **D** | ▲E18 | 🟠 | **1 w** | — |
| **B-6** | **`MetricUnavailable` component** rendering *why*, from `Kpi::blockedReason`. The visible expression of principle 3. | **D** | ▼E18 | 🟠 | **3 d** | B-5 |

**Impact:** B-4 is currently a **latent trap, not active deception** — no UI reads these yet. **This is
where rule 1 of the R/K split bites: seeded numbers must never borrow Bosch's authority.**

### Milestone B-III · Clean vocabulary
**Demonstrable:** *ontology search returns one right answer instead of three overlapping ones.*

| ID | Item | Cls | Evidence | Pri | Effort | Blocked by |
|---|---|:--:|---|:--:|---|---|
| **B-7** | **Ontology consolidation.** Retire `Door / panel misalignment` (100% ambiguous, 12/12). Record `Steering vibration ↔ Wheel balancing` as a `confused_with` edge — the type exists. Accept `Oil Change ↔ Oil Filter` as bundling, not error. | **R** | ▲E10 | 🟠 | **1 w** | B-1 |
| **B-8** | **Fix `FindingKeyword.category`** — null across all 105 concepts, so `MatchQuery::$category` can never filter. *(L-5)* | **R** | ▲E10 | 🟢 | **1 d** | B-1 |

### Milestone B-IV · Component catalog
**Demonstrable:** *open a vehicle's Components tab and see what is fitted, with where each fact came from.*

| ID | Item | Cls | Evidence | Pri | Effort | Blocked by |
|---|---|:--:|---|:--:|---|---|
| **B-9** | **Component taxonomy** — the catalog of component types and expected service lives. | **R** | ▲E10 | 🟠 | **4 d** | — |
| **B-10** | **Asset Layer intake.** `vehicle_components`/`component_events` are **0/0**. Widen intake via Repair Capture → Component Ledger so an install needs no purchase behind it. Preserve `evidence_channel` (`purchase` vs `repair_capture`) — a technician-reported fit must never be confused with a costed purchase. | **F** | ▲E5 ▼E2 ▼E10 | 🟠 | **2 w** | A-6, B-9 |

**Impact:** note the split — **B-9 is R and ships now; B-10 is F and depends on Track A.** This is the
only Track A/B crossing, and the classification makes it obvious why.

### Milestone B-V · Assistance
**Demonstrable:** *a technician opens a ticket and sees cited recommendations that become a real task
in one click.*

| ID | Item | Cls | Evidence | Pri | Effort | Blocked by |
|---|---|:--:|---|:--:|---|---|
| **B-11** | **AI recommendations (constrained).** Catalog-typed actions feeding the existing `Recommendation`/`RecommendationEvent` models. **Post-validation, not trust:** every `fact_refs` id must exist; every number in prose must match a fact-sheet value, or the block is **dropped, not repaired**. | **P** | ▲E15 ▼E9 ▼E10 ▼E18 | 🟡 | **2–3 w** | B-1, B-5 |
| **B-12** | **Historical document retrieval.** Corpus ingestion + `CorpusRetriever` wired to real content. | **R** | ▲E11 | 🟡 | **3 w** ⚠️ soft | B-4 |

**Impact:** the validation rules are what make "explains, does not invent" structural rather than a
prompt instruction. B-12 is **unscoped** until B-4's direction is chosen — effort depends on licensing.

**Track B total: ≈8–12 weeks**, dominated by the B-4 / B-12 decisions.

---

# Part VII — Phase 3 · Evidence-Driven Intelligence

**Gate:** nothing ships without **sample size · confidence · evidence source · freshness**, and an
explanation when unavailable. **Everything here is K or P — so freshness is mandatory throughout.**

### Population reality (2026-08-01, excluding demo rows)

| Evidence | Count |
|---|---:|
| `maintenances` with `workflow_status` | **149** of 26,839 |
| …with `repair_started_at` | **13** |
| …with `repair_started_at` **and** `returned_at` | **8** |
| `repair_inspections` | 9 (2 demo → **7 real**) |
| `maintenance_line_items` | 12 (11 demo → **1 real**) |
| `recurring_fault_reviews` | 5 (5 demo → **0 real**) |
| `maintenance_task_actions` · `vehicle_components` | **0** · **0** |
| Vehicles with zero maintenance history | **191 of 438 (43.6%)** |

**Nothing here is scheduled by date.** Build the engine, then let the gate decide what renders.

**Demonstrable:** *an operator opens a vehicle and sees either a real benchmark with its sample size
and source, or a clear sentence explaining what is missing — never a weak guess.*

| ID | Item | Cls | Evidence | Pri | Effort | Blocked by |
|---|---|:--:|---|:--:|---|---|
| **C-1** | **Metric Engine core.** `Scope`, L1 readers, `MetricRegistry`, `MetricSpec`, `MetricValue` **extending `App\Kpi\Kpi`** (+`confidence`, `evidenceSource`, `computedAt`, `plane`, `classifierVersion`, `sourceRefs`). Extract `OperationalKpiService` into scope-parameterised specs — **the highest-value refactor in the plan.** | **D** | ▲E18 ▼E1…E8 | 🟠 | **3 w** | A-6, A-9 |
| **C-2** | **Coverage gate.** `Coverage` renders first, always. `sufficientForTrend=false` ⇒ the claim is **absent from the payload**, not merely flagged. Shrinkage `(n·v + k·median)/(n+k)`, k≈5; `significant=false` suppresses. | **D** | ▲E18 | 🔴 | **1 w** | C-1, B-6 |
| **C-3** | **Comeback rate.** Must reuse `OperationalKpiService` SQL — a second definition is a standing-rule violation. | **K** | ▲E12 ▼E4 ▼E7 ▼E17 | 🟠 | **1 w** | C-2, A-11, A-13 |
| **C-4** | **Garage quality.** Explainable weighted score + criticality + expected outcomes. | **K** | ▲E12 ▼E1 ▼E3 ▼E7 | 🟠 | **2 w** | C-2, A-11 |
| **C-5** | **Repair duration benchmarks.** Tier-3 evidence only. | **K** | ▲E12 ▼E3 | 🟡 | **1 w** | C-2, A-9, A-12 |
| **C-6** | **Component reliability.** Sourced entirely from `ComponentReadModel` — **never** counted from `maintenance_task_actions`. | **K** | ▲E13 ▼E5 | 🟡 | **2 w** | C-2, B-10 |
| **C-7** | **Cost per repair.** From `VehicleExpenseProvider`, never line items. Capped at `low` while coverage < 0.5, labelled *"based on N of M repairs with known cost"*. | **K** | ▲E14 ▼E8 | 🟡 | **1 w** | C-2 |
| **C-8** | **Reliability score + peer comparison.** Additive, config weights, per-term contributions for a waterfall. Excludes component lifecycle by owner ruling — the score measures *events that happened*, not *state*. Percentile within cohort, not delta-from-mean. | **D** | ▼E12 ▼E13 ▼E14 | 🟡 | **2 w** | C-2, C-3, C-6 |
| **C-9** | **Risk forecasts as bands.** `likely/possible/unlikely`, never decimals, until a Brier reading exists. Component-life and warranty risks carry `probability = null` — an obligation has no probability. | **P** | ▲E15 ▼E13 | 🟢 | **2 w** | C-8 |
| **C-10** | **Calibration loop.** Predicted-vs-actual + Brier, reusing `ForecastCalibration`. Promote bands → numbers only once measured. | **P** | ▲E15 ▼E15 | 🟢 | **1 w** | C-9 |

**Impact:** **C-2 is the item that makes Phase 3 safe** — without it everything renders confidently on
n=8. C-4 ranks real vendors, the highest-consequence surface in the system; do not ship it before A-11
renames the n=9 "comeback rate". C-5 unlocks at n≈30 complete tickets — **currently 8**. C-6 unlocks
only as B-10 populates — **currently 0 rows**, so design the UI for "no component history yet" as the
normal case.

---

# Part VIII — Lineage & sequencing

## VIII.a Evidence lineage map

```
  TRACK A  (Fact capture — irreplaceable)          TRACK B  (Reference — fleet-independent)
  ────────────────────────────────────────         ──────────────────────────────────────────
  A-1 ▲E1 ──┐                                      B-2/B-7/B-8/B-9 ▲E10 ──┐
  A-6 ▲E1E2 ┼──> A-9  ▲E3 ──> A-12                 B-4/B-12        ▲E11 ──┤
  A-4 ▲E6   │    A-7  ▲E18                         B-1/B-3         ▲E9  <─┘
  A-13▲E17  │    A-8  ▲E4                          B-5             ▲E18 ──> B-6
  A-18▲E2   │                                      B-11            ▲E15 <── E9,E10,E18
            │
  B-10 ▲E5 <┘  (the one crossing: F, needs E2)
                              │
                              ▼
        ┌──────────── C-1 ▲E18  ◄── consumes E1…E8
        │             C-2 (gate) ◄── needs B-6
        ▼
   PHASE 3 (Knowledge + Prediction — population-gated)
   C-3 ▲E12 ◄ E4,E7,E17      C-6 ▲E13 ◄ E5        C-9  ▲E15 ◄ E13
   C-4 ▲E12 ◄ E1,E3,E7       C-7 ▲E14 ◄ E8        C-10 ▲E15 ◄ E15
   C-5 ▲E12 ◄ E3             C-8      ◄ E12,E13,E14
```

**What the map makes obvious:**

* **Only 8 items produce Facts** (A-1, A-4, A-6, A-13, A-18, B-10, plus J-producers A-14/15). Everything
  else consumes. **Those 8 are the irreplaceable ones** — and they are exactly where Track A's priority
  comes from.
* **Every Phase 3 item is a pure consumer.** Not one produces a Fact. That is the formal statement of
  "Phase 3 cannot outrun its evidence".
* **E5 (component history) has exactly one producer, B-10** — and B-10 is the only Track A/B crossing.
  It is therefore the single highest-leverage item for unlocking C-6.
* **E11 (reference documents) has no consumer until B-12.** That is why B-4 can safely be a *downgrade*
  rather than an ingest — nothing depends on it yet.

## VIII.b Critical path

```
A-1 ─> A-6 ─┬─> A-9 ─> A-12
            ├─> B-10 ──────────────> C-6
            └─> C-1 ─> C-2 ─┬─> C-3 ─> C-4
                            ├─> C-5 / C-7
                            └─> C-8 ─> C-9 ─> C-10
B-5 ─> B-6 ────────────────> C-2        (Track B gates the Phase 3 gate)
B-1 ─┬─> B-2 / B-7 / B-8
     └─> B-11  (also needs B-5)
B-4 ─> B-12
```

**Long pole: `A-1 → A-6 → C-1 → C-2`.** Note `B-5 → B-6 → C-2`: Track B's provenance primitives are a
**prerequisite for the Phase 3 gate** — a second, independent reason not to defer Track B.

## VIII.c Class roll-up

| Class | Items |
|:--:|---|
| **F** — Fact | A-1, A-4, A-6, A-13, A-18, B-10 |
| **D** — Derived | A-7, A-8, A-9, A-10, A-11, A-12, A-17, A-20, B-1, B-3, B-5, B-6, C-1, C-2, C-8 |
| **R** — Reference | B-2, B-4, B-7, B-8, B-9, B-12 |
| **K** — Knowledge | C-3, C-4, C-5, C-6, C-7 |
| **P** — Prediction | B-11, C-9, C-10 |
| **J** — Judgement | A-14, A-15 |

**Sanity check the roll-up gives you for free:** every **K** item sits in Phase 3 behind the coverage
gate, and every **R** item sits in Track B with no population dependency. If a future proposal puts a
K item in Track B, that is the signal it has been mis-scoped.

## VIII.d Deliberately not doing

| Item | Reason |
|---|---|
| Backfilling 149 legacy tickets with stage timing | Judgement under evidence-layer governance → must not feed any metric. Owner ruling. |
| Re-linking 1,559 orphaned events by vehicle + time window | Reconstruction, not recovery. Forbidden. Vehicle-level only, permanently. |
| Running the concept enrichment backfill | Blocked on B-1/B-2. Would bake "repairs are failures" into 26,839 rows. |
| Building the narrative layer (L4) | Needs a queue worker (`app/Jobs` is empty) and a populated L3 first. |
| Bulk-deleting the 139 manual test tickets | Mixed with genuine hand-raised tickets. Triage and flag (A-15). |

## VIII.e Effort summary

| Phase | Effort | Calendar driver |
|---|---:|---|
| Phase 0 | **≈2 d** | This week |
| Track A | **≈5–6 w** | Developer availability |
| Track B | **≈8–12 w** | The B-4 / B-12 scope decisions |
| Phase 3 | **≈14–16 w** | **Population growth, not effort** |

Sequential ≈7 months. **Two developers on Track A ‖ Track B ≈4 months**, improving visibly every
milestone rather than only at the end.
