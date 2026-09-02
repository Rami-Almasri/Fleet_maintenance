# Fleet Knowledge Engine — Architecture Blueprint

> Status: DESIGN (no code yet). Author perspective: product architecture.
> Companion to [[maintenance-procurement-platform-arch]], [[garage-recommendation-engine]],
> [[explainability-platform]], and the Service-vs-Fault domain docs.

## 0. The shift in the question

Today FleetView answers **"what happened last time?"**. The Fleet Knowledge Engine (FKE) turns
maintenance history into a system that answers, at the moment a fault is being logged:

- What is **most likely** happening? (root cause)
- What repair **strategy** has historically worked best? (playbook)
- Which **garage** has the highest success rate for *this* fault? (performance)
- Which **parts** are usually replaced — and replaced *together*? (parts)
- What is the expected **duration** and **cost**? (prediction)
- Is this likely to **come back**? (repeat-failure risk)
- **How confident** are we — and **why** this recommendation? (confidence + explainability)

And it must get **better every time a repair closes**, with **zero AI in V1**.

---

## 1. Design principles (non-negotiable)

1. **Structured-data-first, AI-as-enhancement.** Every predictor is an *interface*. V1 ships a
   `HistoricalEstimator` (pure aggregation over past repairs). A `ModelEstimator` (ML) can be swapped
   in later behind the same interface, A/B'd against the rule-based baseline — no caller changes.
2. **Derived read-models, never a parallel ledger.** FKE computes from the existing event tables. Its
   new tables are *materialized caches* (rebuildable from source at any time), not new sources of truth.
   This mirrors the house rule already used by `DashboardService`, the Explainability platform, and the
   "derived operational truth" doctrine.
3. **Everything is explainable to a source record.** Reuse the Explainability DAG
   (`app/Services/Explainability`) — a recommendation traces back to the exact past repairs it learned from.
4. **Confidence is a first-class, uniform citizen.** One `ConfidenceScorer`, one banding (high/med/low),
   attached to *every* number the engine emits. No point-estimate is ever shown as if it were fact.
5. **"Learning" without ML = the aggregates move as repairs close.** As new repairs land, the derived
   read models recompute and the next recommendation is already smarter. ML later only sharpens what this
   loop already does.

### LOCKED architecture decision (2026-07-27)

After review, the substrate is **not** a domain entity. The four-layer contract is fixed:

- **L1 Source of truth** — the existing maintenance tables. Authoritative. No new columns without a real
  business requirement.
- **L2 Knowledge Query Layer** — read-only query services over L1 (`RepairHistoryQueryService`,
  `GaragePerformanceQueryService`, `PartsPatternQueryService`, `FailurePatternQueryService`,
  `CostDurationQueryService`). **This is where the engine lives.** They query L1 directly.
- **L3 Optional read models** — `repair_signatures`, `garage_fault_stats`, `part_affinity`,
  `failure_patterns`. Introduced **only when a measured query latency demands it**, per-module. They are
  projections: **rebuildable, disposable, no manual writes, no business ownership.** A `truncate + rebuild`
  loses nothing. Litmus test: if it's fully regenerable from history, it is an **index**, not a business table.
- **L4 Intelligence** — `ConfidenceScorer`, `RepairRecommendationService`, Explainability. Every
  recommendation returns `{ recommendation, confidence, sample_size, evidence, why_this_was_recommended }`.

The only genuinely **new business facts** (which DO earn real tables, later) are those that capture a human
decision not derivable from repair history: `recommendation_feedback` (accepted/overridden) and any curated
`fault_repair_playbook` overrides. Everything else is a read model.

**No user-facing workflow may depend on a derived table being permanently correct** — a workflow reads L1;
intelligence panels read L2/L3 and degrade gracefully (lower confidence / "not enough history") if a
projection is stale or absent.

---

## 2. Layered architecture

```
L4  SURFACE          APIs  ·  UI panels (fault-registration card, garage cards, playbook, patterns board)
                     └─ every payload: { value, confidence, band, sample_size, why[] }

L3  COMPOSITION      Repair Recommendation Engine (assembler)
                     ConfidenceScorer (shared)      RepairRecommendationExplainer (Explainability DAG)

L2  INTELLIGENCE     (1)HRI (3)Garage (4)Parts (5)RootCause (6)Patterns (7)Cost (8)Duration (9)Repeat
     MODULES         each: HistoricalEstimator now → ModelEstimator later (same interface)

L1  KNOWLEDGE        repair_signatures  (the one "training row" per repair — the substrate)
    SUBSTRATE        materialized aggregates: garage_fault_stats · part_affinity · fault_repair_playbook
                     · failure_patterns · knowledge_edges · recommendation_feedback

L0  EVENT / FACT     maintenances · maintenance_tasks · maintenance_task_assignments · maintenance_line_items
    (EXISTING)       · maintenance_invoices · part_requests · part_purchases · repair_inspections
                     · recurring_fault_reviews · fault_causes · fault_catalog · vehicles · vendors
```

### The substrate: `repair_signatures` — L3, OPTIONAL, deferred

> Per the locked decision above, this is **not** built in P0. The query services (L2) compute the same
> shape live from L1. `repair_signatures` is promoted only if/when a measured latency requires it, and even
> then it is a pure projection (rebuildable/disposable), never a source of truth.

Were it materialized, it would be **one denormalized row per completed repair (per fault)** — the
"fingerprint" a module reads and the future ML training row, fully rebuildable from L1.

| field | source |
|---|---|
| `maintenance_task_id`, `maintenance_id`, `vehicle_id` | task |
| `make`, `model` | vehicles |
| `category_key`, `fault_catalog_id`, `symptom_key` | task |
| `root_cause`, `root_cause_id`, `confirmation_status` | task |
| `garage_vendor_id` (the stint that resolved it) | task_assignments `outcome=resolved` |
| `parts` (json: [{part_number, name}]) | line_items kind=part |
| `parts_cost`, `labor_cost`, `total_cost` | task roll-up / invoices |
| `duration_days` | resolved_at − started_at (fallback: returned_at − test_started_at) |
| `opened_at`, `resolved_at`, `odometer_at_repair` | task / maintenance |
| `outcome` (verified_fixed / fixed / failed) | repair_inspections |
| `recurred` (bool), `recurrence_days`, `occurrence_index` | repair_inspections.is_recurrence / recurring_fault_reviews |

**Materializer trigger:** on `close()` / `repair_inspection` write, and a nightly full rebuild command
(`fke:rebuild-signatures`) for safety + backfill of the workflow-era tickets. Free-text legacy rows are
folded in via the existing `GarageRecommendationService::extractCategories()` extractor (precision-first).

---

## 3. Confidence model (unified — used by all 10 modules)

Every emitted figure carries `C ∈ [0,1]` and a band. One formula, tuned in config:

```
C = w_cred·cred(n) + w_recency·recency + w_agree·agreement + w_tier·tier_coverage

cred(n)       = min(1, √n / √N_full)          # the √-credibility ramp already in GarageRecommendationService::cr()
recency       = decay on median age of the evidence (fresh repairs weigh more)
agreement     = 1 − normalized dispersion      # low variance in cost/duration/outcome ⇒ high agreement
tier_coverage = 1.0 same-vehicle → 0.7 model → 0.5 make → 0.4 fleet   # how specific the matched cohort is
```

Banding (`high / medium / low`) reuses the existing `confidence_high_matches / confidence_med_matches`
thresholds pattern from `config/garage_recommendation.php`. **Never emit a `high` band on N < threshold**,
regardless of how clean the numbers look.

---

## 4. The ten modules

Each module below: **Purpose · Sources · Algorithm (rule→AI) · DB objects · APIs · UI · Confidence · KPIs · AI later · Reuse.**

### Module 1 — Historical Repair Intelligence (retrieval)
- **Purpose.** Given a fault, return the most relevant past repairs, tiered by confidence. The engine's memory.
- **Sources.** `repair_signatures` (⇐ all L0).
- **Algorithm (rule).** Tiered retrieval — Tier1 same vehicle → Tier2 same model → Tier3 same make → Tier4 fleet. Match key precedence: `fault_catalog_id` → `category_key` → normalized `symptom_key` → keyword expansion (`config/maintenance_findings` + `fault_extraction`). Rank = recency_decay × outcome_quality × tier_weight.
- **AI later.** Sentence-embedding similarity of free-text symptom/notes to catch cross-category near-matches ("knocking" ≈ "ticking").
- **DB.** `repair_signatures` (read model). No source change.
- **APIs.** `GET /faults/{task}/similar-repairs?scope=auto|vehicle|model|make|fleet`.
- **UI.** "Previous Similar Repairs" panel on the fault-registration / checkpoint modal, with tier chips.
- **Confidence.** tier_coverage + sample + recency.
- **KPIs.** retrieval coverage %, panel click-through, "was this helpful?" rate.
- **Reuse.** `RecurringFaultService::detectPriorFix()` — generalize its snapshot builder from same-vehicle to tiered.

### Module 2 — Repair Recommendation Engine (composer)
- **Purpose.** The single actionable answer: *likely cause + strategy + garage + parts + cost + duration + confidence + why*.
- **Sources.** Outputs of modules 3,4,5,7,8,9 (it composes, it does not re-derive).
- **Algorithm (rule).** Weighted assembly into a **Repair Playbook** object: top root_cause (M5), best garage (M3), expected parts bundle (M4), cost/duration bands (M7/M8), recurrence risk (M9). Composite confidence = evidence-weighted blend of component confidences (weakest-link floored).
- **AI later.** Learned ranking (gradient boosting over signatures + feedback); LLM to write the human narrative.
- **DB.** `fault_repair_playbook` (materialized, with a curatable override layer — engineers can pin a canonical strategy).
- **APIs.** `GET /faults/{task}/recommendation` · `POST /faults/{task}/recommendation/feedback` (accepted | overridden + chosen alternative).
- **UI.** Recommendation card at fault registration **and** at the assign-dispatch step.
- **Confidence.** composite.
- **KPIs.** acceptance rate, override rate, **prediction-vs-realized accuracy** (the north star).
- **Reuse.** `GarageRecommendationService::forTicket()` as the garage sub-answer; `MaintenanceOpsCenterService` scoring pattern as the composition precedent.

### Module 3 — Garage Performance Intelligence
- **Purpose.** Which garage is best for *this* fault: success rate, cost, duration, recurrence — per garage per fault.
- **Sources.** `maintenance_task_assignments` (stints, outcome, duration), `repair_inspections` (result, is_recurrence, `previous_vendor_id`), `maintenance_invoices` (cost), `recurring_fault_reviews`.
- **Algorithm (rule).** Per (vendor × category [× model]): `success_rate = resolved / (resolved + failed_reinspection)`, `avg_cost`, `avg_duration`, `recurrence_rate`. Rank by **Wilson lower-bound** of success (small-N garages can't top the list) × √-credibility × lift-vs-fleet.
- **AI later.** Contextual bandit — balance exploiting the proven garage vs exploring to keep stats fresh.
- **DB.** `garage_fault_stats` (materialized).
- **APIs.** `GET /garages/{vendor}/fault-stats` · `GET /faults/{task}/recommended-garages`.
- **UI.** Garage **Success Score** cards (`APEX · Engine Noise · 18 jobs · 94% · avg 820 · 5.8d · 1 recurrence`); comparison table.
- **Confidence.** Wilson interval width / sample size.
- **KPIs.** recommended-garage adoption; realized success of recommended vs non-recommended assignments.
- **Reuse.** `GarageRecommendationService` (the whole engine!) + its `qualitySignal()` (already computes per-vendor per-category attempts/failures — just surface it as a score instead of a hidden penalty); `GarageRoutingService` as the policy overlay.

### Module 4 — Parts Intelligence
- **Purpose.** Which parts are likely needed, which are replaced **together**, how long parts last, and duplicate-spend.
- **Sources.** `maintenance_line_items` (part_number, installed_on/odometer, warranty), `part_purchases` (price, `result` success/failed), `part_requests`.
- **Algorithm (rule).** (a) expected-parts = part frequency per category/model; (b) **affinity** = co-occurrence pair counts + lift (market-basket / Apriori-lite) → "usually replaced together"; (c) **lifespan** = km/time between successive installs of the same part_number on a car; (d) part failure rate from `part_purchases.result`.
- **AI later.** Association-rule mining at scale; parts **demand forecasting** for stock.
- **DB.** `part_affinity` (pair table); optional `part_lifespan_stats`.
- **APIs.** `GET /faults/{task}/expected-parts` · `GET /parts/{number}/affinity`.
- **UI.** "Likely parts" checklist at the part-request step; "commonly bought together" hint; lifespan on part history.
- **Confidence.** pair support + lift + sample.
- **KPIs.** predicted-vs-actual parts precision/recall; duplicate-spend caught.
- **Reuse.** `part_purchases` duplicate detection already exists; `maintenance_line_items` already indexed by `(part_number, vehicle_id)` for lifespan.

### Module 5 — Root Cause Intelligence
- **Purpose.** For a symptom, the most probable root cause(s), ranked and conditioned on the model.
- **Sources.** `fault_causes` (KB + `usage_count`), `maintenance_tasks` (symptom→chosen root_cause), `confirmation_status` (only `confirmed` = ground truth).
- **Algorithm (rule).** Frequency-Bayesian: `P(cause | symptom, model)` from historically **confirmed** tasks; KB supplies the candidate set; `different_cause` verdicts refine. Rank causes by conditioned frequency.
- **AI later.** NLP over `customer_complaint` + `notes` free-text → cause classifier.
- **DB.** Reuse `fault_causes`; optional `symptom_cause_stats` (per model).
- **APIs.** `GET /symptoms/{key}/probable-causes?model=`.
- **UI.** The existing root-cause picker, now **pre-ranked with probabilities** ("Coolant leak — 62% on this model").
- **Confidence.** sample among confirmed tasks.
- **KPIs.** top-cause hit-rate (predicted == later-confirmed cause).
- **Reuse.** `fault_causes` table + picker already built; the workshop confirmation gate already captures the ground-truth label for free.

### Module 6 — Failure Pattern Detection
- **Purpose.** Catch systemic/emerging patterns across the fleet: model-wide defects, garage quality drops, part **batch** failures, seasonal spikes.
- **Sources.** `repair_signatures` over time; `recurring_fault_reviews`; `repair_inspections`.
- **Algorithm (rule).** Threshold triggers: (a) a model's category rate exceeds fleet baseline × k (√-credibility gated) ⇒ *model defect*; (b) a part_number failure cluster ⇒ *batch alert*; (c) a garage's success drops vs its **own trailing baseline** (control-chart) ⇒ *quality regression*; (d) temporal clustering ⇒ *seasonal*.
- **AI later.** Changepoint detection, clustering, survival analysis.
- **DB.** `failure_patterns` (detected-pattern records, same shape convention as `/anomalies`).
- **APIs.** `GET /intelligence/failure-patterns`.
- **UI.** Pattern-alert board — reuse the generic group renderer from `/anomalies`.
- **Confidence.** rate-vs-baseline significance + sample.
- **KPIs.** patterns actioned; lead-time bought before a mass failure.
- **Reuse.** `/anomalies` generic frontend renderer; the recurring-fault infrastructure.

### Module 7 — Cost Prediction
- **Purpose.** Expected repair **cost range** before authorizing spend.
- **Sources.** `maintenance_line_items` / `maintenance_invoices` / task `parts_cost+labor_cost`, joined to signatures.
- **Algorithm (rule).** Percentile bands (p25–median–p75) over the matched cohort (tiered like retrieval), adjusted by the chosen garage's `avg_cost` (M3). Always a **range**, widened when N is low. Never a false-precision point.
- **AI later.** Regression (parts + model + garage + severity → cost).
- **DB.** Reuse signatures; optional `cost_stats`.
- **APIs.** `GET /faults/{task}/expected-cost`.
- **UI.** Cost-range chip; variance-vs-prediction surfaced at close (feeds reconciliation).
- **Confidence.** N + IQR width.
- **KPIs.** prediction-interval coverage (% actuals inside the band); MAPE.
- **Reuse.** `RealProfitService` / cost roll-ups; `SHOW_FINANCIALS` gating; the invoice `variance_explanation` machinery.

### Module 8 — Repair Duration Prediction
- **Purpose.** Expected downtime for planning, swaps, and customer ETAs.
- **Sources.** `maintenances` stage timing (`test_started_at → dispatched_at → returned_at`), task `started_at → resolved_at`, assignment stints, `part_requests` (parts-wait).
- **Algorithm (rule).** Percentile duration bands per category/model/garage; **add expected parts-wait** when a part must be ordered (M4 signal) — the "Waiting for Parts" state is already modeled.
- **AI later.** Survival/hazard model; per-garage queueing model that accounts for current workload.
- **DB.** Reuse signatures; optional `duration_stats`.
- **APIs.** `GET /faults/{task}/expected-duration`.
- **UI.** ETA chip; feeds the `expected_completion_date` promise and the swap board.
- **Confidence.** N + variance.
- **KPIs.** ETA accuracy (actual vs predicted days); on-time closure rate.
- **Reuse.** the stage-timing columns; `expected_completion_date` (checkpoint feature); `MaintenanceCheckpointService`; the maintenance-swap board.

### Module 9 — Repeat Failure Detection (largely built)
- **Purpose.** Same fault returns after a signed-off repair → accountability + reliability signal.
- **Sources.** `RecurringFaultService::detectPriorFix()`, `repair_inspections.is_recurrence`, `recurring_fault_reviews`.
- **Algorithm (rule).** Already implemented: previous FIXED + same category within window; `occurrence_count`; `distance_since_repair`; verified-vs-fixed. **Extend:** feed `recurrence_rate` into M3 (garage score) and M2 (risk).
- **AI later.** Reliability curves (Weibull / MTBF) per component.
- **DB.** Reuse `recurring_fault_reviews`, `repair_inspections`.
- **APIs.** exists (`/recurring-fault-reviews`); add a `recurrence-risk` read for a *newly reported* fault.
- **UI.** recurrence badge at registration (`recurrence_flagged` already exists on tasks).
- **Confidence.** match strength.
- **KPIs.** recurrence-rate trend; MTBF per fault/model; % recurrences caught before close.
- **Reuse.** the entire Recurring-Fault Intelligence feature is already shipped.

### Module 10 — Fleet Knowledge Graph
- **Purpose.** The connective substrate that lets modules reason across each other and powers "why" traversal + the future RAG assistant. Entities: **Vehicle · Model · Make · Fault Category · Root Cause · Part · Garage · Repair**. Weighted edges: fault→cause, cause→part, fault→garage-success, part↔part affinity, model→fault-rate.
- **Sources.** The union of `repair_signatures` + all L1 aggregates. Edge weights *are* the module outputs.
- **Algorithm (rule).** Materialized typed edge list; queries = graph traversal / joins. Start **virtual** (a view over the aggregates); materialize `knowledge_edges` only if traversal latency demands it.
- **AI later.** node2vec / GNN embeddings; **link prediction** (guess a likely cause/part before we have direct history); retrieval-augmented **"Ask the Fleet"** assistant.
- **DB.** `knowledge_edges` (`from_type/from_id/to_type/to_id/relation/weight/support/confidence`) — optional at first.
- **APIs.** `GET /knowledge/graph?node=` + traversal endpoints; backs the Explainer.
- **UI.** Inline "why" chips now; a knowledge-explorer view later.
- **Confidence.** edge support / weight.
- **KPIs.** entity/edge coverage; query latency; explanation completeness.
- **Reuse.** **The Explainability platform is already a knowledge-graph-of-figures** (`ExplanationGraph`, `NodeType`, `Confidence`, pluggable `Explainer`). Register a `RepairRecommendationExplainer` and extend the node types — do not build a second graph engine.

---

## 5. Continuous learning loop (structured now, ML later)

```
repair closes ──▶ materializer upserts repair_signature ──▶ aggregates recompute (incremental + nightly)
     ▲                                                                    │
     │                                                                    ▼
recommendation_feedback ◀── user accepts/overrides ◀── next fault reads a smarter recommendation
     │
     └──▶ realized outcome (cost/duration/success) stored as a LABEL ──▶ calibration dashboards
                                                                          + training set for the ML layer
```

- **V1 "learning" = the aggregates move.** No model, no training — the stat tables drift toward truth as
  repairs land. This satisfies the "must work entirely from structured data" constraint.
- **The AI seam.** Each predictor is `interface RepairEstimator { estimate(context): {value, confidence, why} }`.
  V1 = `HistoricalEstimator`. Later = `ModelEstimator` trained on `repair_signatures` + `recommendation_feedback`,
  shadow-run and A/B'd against the baseline. Callers never change.

---

## 6. Reuse map — what already exists (build on, don't rebuild)

| FKE need | Already in the codebase |
|---|---|
| Data-driven garage ranking (M2/M3 core) | **`GarageRecommendationService`** (655 lines, unit-tested; experience/concentration/lift/combo, √-credibility, confidence bands, `forTicket()`) |
| Per-garage per-fault success signal (M3) | `GarageRecommendationService::qualitySignal()` (already aggregates resolved vs failed_reinspection) |
| Policy overlay on garage choice (M3) | `GarageRoutingService` (hand-curated rules) |
| Prior-fix retrieval snapshot (M1/M9) | `RecurringFaultService::detectPriorFix()` |
| Repeat-failure feature (M9) | Recurring-Fault Intelligence — `recurring_fault_reviews`, `repair_inspections`, report-time `recurrence_flagged` |
| Free-text → fault category (backfill) | `GarageRecommendationService::extractCategories()` + `config/fault_extraction.php` |
| Fault taxonomy (M1/M5) | `fault_catalog`, `fault_causes` (Symptom→Root-Cause KB), `config/maintenance_findings.php` |
| Per-garage duration + outcome (M3/M8) | `maintenance_task_assignments` stints (`outcome`, `assigned_at`/`released_at`) |
| Cost roll-up chain (M7) | `maintenance_line_items` → `maintenance_task` → `maintenance_invoices`; `RealProfitService` |
| Stage timing (M8) | `maintenances.test_started_at / dispatched_at / returned_at`; `expected_completion_date`; checkpoints |
| "Why" + confidence engine (L3/M10) | **`app/Services/Explainability`** — `ExplanationGraph`, `Confidence`, `NodeType`, pluggable `Explainer` |
| Score composition precedent (M2) | `MaintenanceOpsCenterService` (Maintenance Priority Score 0–100) |
| Pattern-board UI convention (M6) | `/anomalies` generic group renderer |
| Money gating | `SHOW_FINANCIALS` flag (`config/features.js`) |

**New DB objects (all rebuildable read-models/caches):** `repair_signatures` (substrate),
`garage_fault_stats`, `part_affinity`, `fault_repair_playbook` (curatable), `failure_patterns`,
`recommendation_feedback`, and optionally `knowledge_edges`. Everything else is reused.

---

## 7. Delivery phases

- **P0 — Query layer, NO tables.** `RepairHistoryQueryService` (tiered retrieval over L1) +
  `ConfidenceScorer` + `RepairRecommendationService`, surfaced as the "Previous Similar Repairs +
  Recommendation Explanation" panel. See `docs/Fleet-Knowledge-Engine-P0-Plan.md`. Deliberately no L3 tables.
- **V1 — Structured intelligence (no AI).** M1, M3, M5, M7, M8, M9 as read models + the M2 recommendation
  card (rule-based), reusing `GarageRecommendationService`. Playbook derived. `RepairRecommendationExplainer`
  wired into the Explainability graph. **This is a fully working Knowledge Engine.**
- **V2 — Fleet-wide learning.** M4 (part affinity), M6 (pattern detection), M10 (materialized graph),
  the feedback loop + calibration dashboards (prediction-vs-realized).
- **V3 — AI enhancement.** Swap `ModelEstimator`s behind the interfaces; embeddings for retrieval + a cause
  classifier over free text; the RAG "Ask the Fleet" assistant on the knowledge graph.

---

## 8. Engine-level KPIs

- **Recommendation acceptance rate** (accepted / shown).
- **Prediction accuracy:** cost interval coverage, duration MAPE, top-cause hit-rate, recommended-garage realized success.
- **Coverage:** % of new faults that receive a `medium+` confidence recommendation.
- **Value:** recurrence-rate trend, average repair cost/duration trend, duplicate-spend avoided.
- **Trust:** override rate and its correlation with realized outcomes (is the engine right when users disagree?).
