# Fleet Knowledge Engine — P0 Implementation Plan

> Feature: **"Previous Similar Repairs + Recommendation Explanation"**
> Constraint: deliver a working intelligence feature with **NO new tables** and no over-engineering.
> Architecture: L2 query services over L1 (see [[fleet-knowledge-engine-arch]], the locked 4-layer contract).

## 0. What the user gets (the value)

While logging a fault (e.g. *Engine Noise* on a Jetour T2), the inspector immediately sees:

> **Previous Similar Repairs** — this model had this fault **7×**.
> **Recommendation:** likely cause *timing chain wear*; best garage **APEX** (92% success, avg AED 820,
> ~5.8 days); usually replaced: *timing chain + tensioner*; recurrence risk **low**.
> **Confidence: medium** · based on 7 repairs · **why →** (expand to the 7 actual past repairs).

Read-only. No workflow depends on it. Degrades to "not enough history yet" gracefully.

---

## 1. Scope

**In:** tiered similar-repair retrieval (Layer 2), a composed recommendation with cost/duration/parts/cause/
recurrence, a uniform confidence score, and a lightweight evidence/why payload. Two read endpoints + one UI panel.

**Reuses (does not rebuild):** `RecurringFaultService::detectPriorFix` (retrieval snapshot logic),
`GarageRecommendationService::forTicket` (best-garage sub-answer), `Maintenance::categoryForKeyword` +
`config/fault_extraction` (match-key resolution), `GarageRecommendationServiceTest` (pure-core test pattern).

---

## 2. Services / classes

All new code under `App\Services\Knowledge\` (Layer 2/4). **No migrations.**

### 2.1 `RepairHistoryQueryService` (L2 — the retrieval engine)
Generalizes `detectPriorFix` from "same-vehicle recurrence blame" to "tiered advisory retrieval, all outcomes".

```
similarRepairs(SimilarRepairQuery $q): SimilarRepairResult
```
- **Input** `SimilarRepairQuery` (a value object, works BEFORE a task row exists):
  `{ vehicle_id, make, model, category_key?, fault_catalog_id?, symptom?, exclude_task_id?, exclude_maintenance_id? }`.
- **Match-key resolution** (`resolveMatchKeys`): `fault_catalog_id` → `category_key` → normalized
  `symptom_key` → keyword expansion. Reuses `Maintenance::categoryForKeyword` + `config/fault_extraction`.
- **Tiering** — runs the same base query at four widening scopes, tagging each hit with its tier and
  de-duplicating (a repair keeps its strongest tier):
  - T1 same `vehicle_id` · T2 same `model` · T3 same `make` · T4 fleet.
- **Base query** over `maintenance_tasks` (status `completed`, `resolved_at` not null, within window) with
  `->with(['lineItems','currentVendor:id,name','maintenance','vehicle:id,make,model'])`, ordered by
  `resolved_at desc`. **Reads L1 live** (no projection).
- **Per-repair projection** (reusing detectPriorFix's snapshot builder): `garage`, `total_cost`,
  `duration_days`, `parts[]`, `outcome` (verified_fixed/fixed/failed via `repair_inspections`),
  `recurred` bool, `resolved_at`, `odometer`, `tier`, `match_basis`.
- **Output** `SimilarRepairResult`: `{ tiers: {t1,t2,t3,t4}, flat[], sample_size, has_history }`.

### 2.2 `ConfidenceScorer` (L4 — shared, PURE, no DB)
```
score(int $sampleSize, int $bestTier, ?float $dispersion = null): Confidence   // {score, band, sample_size}
```
- `cred = min(1, sqrt(n)/sqrt(N_full))` (same √-ramp as `GarageRecommendationService::cr`).
- `tier_coverage` = {1:1.0, 2:0.7, 3:0.5, 4:0.4}[bestTier].
- optional `agreement = 1 − dispersion` (variance of cost/duration; null in P0 first cut → treated as neutral).
- `score = w_cred·cred + w_tier·tier_coverage + w_agree·agreement` (weights in config).
- `band`: `high` if `n ≥ high_n` AND `bestTier ≤ 2`; `medium` if `n ≥ med_n`; else `low`.
- **Pure** → unit-tested with no DB, mirroring `scoreRows`.

### 2.3 `RepairRecommendationService` (L4 — the composer)
```
forFault(SimilarRepairQuery $q): RepairIntelligence
```
Composes the `SimilarRepairResult` cohort + `GarageRecommendationService` into one answer:
- `likely_cause` — modal `root_cause` among **confirmed** cohort repairs (+ share %).
- `suggested_garage` — from `GarageRecommendationService::recommend(['model'=>…,'faults'=>[cat]])` primary[0];
  enriched with the cohort's success/cost/duration for that garage.
- `expected_parts` — top part_numbers by frequency in the cohort (simple frequency; **no affinity in P0**).
- `expected_cost` — `{ p25, median, p75, currency }` percentiles over cohort `total_cost` (nulls excluded);
  gated behind `SHOW_FINANCIALS`.
- `expected_duration` — `{ p25, median, p75 } days` over cohort `duration_days`.
- `recurrence_risk` — cohort `recurred` rate → `low|medium|high` band.
- `confidence` — `ConfidenceScorer` over the cohort; composite floored at the weakest populated component.
- `evidence[]` — the top N contributing repairs (id, plate, garage, cost, days, outcome, date).
- `why[]` — human strings: `"7 matching repairs on Jetour T2"`, `"APEX fixed 6/7 without recurrence"`,
  `"timing chain replaced in 5 of 7"`.

**The percentile / modal / rate maths is a PURE helper** (`RepairCohortStats`) → unit-tested without DB.

### 2.4 `RepairIntelligenceController` (HTTP)
Two read actions. Thin — validates, builds `SimilarRepairQuery`, returns a `RepairIntelligenceResource`.

### 2.5 Config `config/knowledge.php`
`window_days`, tier weights, confidence weights + `high_n`/`med_n` thresholds, `evidence_limit`,
`expected_parts_limit`. All tunable, no code change to retune.

---

## 3. API contracts

Gated `permission:maintenance.view` (read). Prefix reuses the maintenance surface.

### 3.1 Existing fault
```
GET /api/maintenance-tasks/{task}/repair-intelligence
```
### 3.2 During registration (before the task row exists)
```
POST /api/repair-intelligence/preview
Body: { vehicle_id: int, symptom?: string, category_key?: string, fault_catalog_id?: int }
```

Both return the **FROZEN public contract v1** (produced by `RepairIntelligencePresenter`, decoupled from the
engine's internal shape; cost fields are redacted SERVER-SIDE for users without `billing.view`):

```jsonc
{
  "state": "ready | low_confidence | no_history",   // drives the UI state; always present
  "message": "Based on 6 similar repairs.",          // human line for the state
  "recommendation": {
    "action":   "Recommend APEX — 100% success on 4 similar repairs · ~AED 770 · ~6d",  // null when no history
    "summary":  "Likely timing chain wear; usually timing chain replaced.",              // null when no history
    "confidence": { "score": 91, "band": "medium", "reasons": ["Includes the same vehicle"] },
    "likely_cause": { "value": "Timing chain wear", "share": 1.0 },   // or null
    "suggested_garage": { "vendor_id": 12, "name": "APEX", "jobs": 4, "success_rate": 1.0,
                          "avg_cost": 782.5, "avg_duration_days": 5.8, "recurrences": 0 },  // avg_cost dropped if redacted; or null
    "expected_parts": [ { "part_number": "TC-1", "name": "Timing chain", "freq": 6 } ],
    "expected_cost": { "p25": 745, "median": 770, "p75": 795, "n": 6, "currency": "AED" },  // null if redacted OR unknown
    "expected_duration": { "p25": 5.25, "median": 6, "p75": 6.75, "n": 6 },                 // or null
    "recurrence_risk": "low | medium | high"                                               // or null
  },
  "statistics": {
    "sample_size": 6, "success_rate": 0.92, "average_duration": 5.8,
    "average_cost": 782.5,        // null if redacted OR unknown
    "recurrence_rate": 0.0
  },
  "similar_repairs": [            // FLAT, tier-ordered (vehicle→model→make→fleet), recency within tier
    { "tier": "vehicle", "vehicle": "Jetour T2", "plate": "J-7", "fault": "Engine Noise",
      "garage": "APEX", "duration_days": 6, "cost": 850,           // cost null if redacted
      "parts": [ { "part_number": "TC-1", "name": "Timing chain" } ],
      "outcome": "verified_fixed | fixed | failed", "repaired_at": "2026-01-14" }
  ],
  "explanation": {
    "why": [ "6 comparable repairs on this vehicle", "No recurrence detected after these repairs" ],
    "evidence": [ { "maintenance_task_id": 903, "vehicle": "Jetour T2", "plate": "J-7", "garage": "APEX",
                    "cost": 850, "duration_days": 6, "outcome": "verified_fixed", "recurred": false,
                    "repaired_at": "2026-01-14", "tier": "vehicle" } ]
  },
  "financials_visible": true      // false ⇒ every cost field above is null by design, not "unknown"
}
```

The top-level keys and the `recommendation` sub-shape are INVARIANT across all three states (a `no_history`
response still returns the full skeleton with nulls), so the frontend binds one shape. Locked by
`RepairIntelligencePresenterTest`. The raw internal envelope (below) is what the SERVICE returns — the UI
never sees it:

```jsonc
{
  "query": { "vehicle_id": 41, "make": "Jetour", "model": "T2", "category_key": "engine",
             "matched_on": "category_key" },
  "has_history": true,
  "sample_size": 7,
  "confidence": { "score": 0.61, "band": "medium" },
  "recommendation": {
    "likely_cause": { "value": "Timing chain wear", "share": 0.57 },
    "suggested_garage": { "vendor_id": 12, "name": "APEX",
                          "success_rate": 0.92, "avg_cost": 820, "avg_duration_days": 5.8, "jobs": 6 },
    "expected_parts": [ { "part_number": "13028-XXXX", "name": "Timing chain", "freq": 5 },
                        { "part_number": null, "name": "Tensioner", "freq": 4 } ],
    "expected_cost": { "p25": 700, "median": 820, "p75": 980, "currency": "AED" },   // omitted if !SHOW_FINANCIALS
    "expected_duration": { "p25": 4, "median": 6, "p75": 8 },
    "recurrence_risk": "low"
  },
  "similar_repairs": {
    "tiers": {
      "vehicle": [ /* SimilarRepair */ ],
      "model":   [ /* … */ ],
      "make":    [],
      "fleet":   []
    }
  },
  "evidence": [
    { "maintenance_task_id": 903, "plate": "Jetour 41", "garage": "APEX",
      "total_cost": 850, "duration_days": 6, "outcome": "verified_fixed",
      "resolved_at": "2026-01-14", "tier": "vehicle" }
  ],
  "why": [ "7 matching repairs on Jetour T2",
           "APEX fixed 6 of 7 with no recurrence",
           "Timing chain replaced in 5 of 7 repairs" ]
}
```
Empty-history response: `has_history:false`, `confidence.band:"low"`, empty tiers/evidence, `why:["No comparable repairs yet"]` — panel shows a neutral empty state, never an error.

---

## 4. UI integration points

- **New component** `frontend/src/components/knowledge/RepairIntelligencePanel.js` — renders confidence
  badge, recommendation summary, tier-grouped similar repairs (collapsible), and a "Why this?" expander over `evidence[]`.
- **New lib** `frontend/src/lib/repairIntelligence.js` — the two fetches + tier grouping/formatting.
- **Mount points (read-only, additive):**
  1. `CheckpointModal.js` — when a fault is opened/reviewed (existing task → `GET …/repair-intelligence`).
  2. Fault-registration step in `MaintenanceWorkflow.js` / the FindingsPicker/test-drive report — live
     **preview** as the inspector picks a symptom (`POST …/preview` with vehicle + symptom, debounced).
  3. `TicketDetailDrawer.js` — per-fault intelligence tab/section.
- **i18n** — add labels to `frontend/src/i18n/labels.js` (EN + AR), matching the existing bilingual pattern.
- Money fields honor the existing `SHOW_FINANCIALS` flag.

---

## 5. Tests

Mirror the codebase's "pure core + thin feature test" style (`GarageRecommendationServiceTest`).

- **Unit (no DB):**
  - `ConfidenceScorerTest` — √-ramp, tier weighting, band thresholds, boundary N.
  - `RepairCohortStatsTest` — percentiles (incl. null-cost exclusion), modal cause/part, recurrence rate on hand-built arrays.
- **Unit (DB factories):**
  - `RepairHistoryQueryServiceTest` — seed vehicles across make/model + completed tasks; assert **tier
    assignment**, de-dup to strongest tier, recency ordering, window exclusion, `exclude_task_id`.
  - Match-key resolution: `fault_catalog_id` > `category_key` > symptom fallback + keyword expansion.
- **Feature (HTTP):**
  - `permission:maintenance.view` enforced (403 without).
  - Contract shape of both endpoints; `preview` works with **no existing task**.
  - Empty-history → `has_history:false`, 200, neutral payload.
  - `SHOW_FINANCIALS=false` → cost fields omitted.
- **Regression / invariant:**
  - **Read-only guarantee** — assert row counts of all L1 tables unchanged after a call (no accidental writes).

---

## 6. Deliberately postponed

- ❌ **All L3 tables** — `repair_signatures`, `garage_fault_stats`, `part_affinity`, `failure_patterns`.
  Live L2 queries only, at current fleet scale (~25k rows). Promote a projection **only on measured latency**.
- ❌ **Full Explainability DAG** (`RepairRecommendationExplainer` into `ExplanationGraph`). P0 ships the
  lightweight `evidence[]` + `why[]`. Wire into the DAG in V2.
- ❌ **`recommendation_feedback`** capture (accepted/overridden). The response envelope is shaped so a
  `POST …/feedback` slots in later without breaking the contract — but no table/logging in P0.
- ❌ **Parts affinity** (co-occurrence / market-basket) — combinatorial; P0 uses plain part frequency.
- ❌ **Failure-pattern detection** (M6), **playbook curation/overrides**, cost/duration **regression models**,
  embeddings/NLP — all V2/V3.
- ❌ **Caching hardening** — start with live queries; add the proven gzip-blob cache pattern only if a
  profiled endpoint exceeds its latency budget.

---

## 7. Build order (within P0)

1. `config/knowledge.php` + `ConfidenceScorer` + `RepairCohortStats` (pure, fully unit-tested first).
2. `RepairHistoryQueryService` (+ tests) — the retrieval spine.
3. `RepairRecommendationService` composing retrieval + `GarageRecommendationService` (+ tests).
4. `RepairIntelligenceController` + routes + Resource (+ feature tests).
5. `RepairIntelligencePanel` + `lib/repairIntelligence.js` + i18n; mount in CheckpointModal, then the
   registration preview, then TicketDetailDrawer.

Each step is independently shippable; value lands at step 4 (API) and is visible at step 5 (UI).

---

## 9. P0 ACCEPTANCE — SHIPPED ✅ (2026-07-27)

### Entry points (one component, one contract, no duplicate logic)
The **same** `RepairIntelligencePanel` + `lib/repairIntelligence.js` back all three surfaces — they only
differ by which of the two endpoints they call:

| Surface | Mode | Source |
|---|---|---|
| Fault creation (`TicketActionModal`, after `FindingsPicker`/`FaultHistoryInsight`) | `preview` (no task yet) | `POST /repair-intelligence/preview` |
| `TicketDetailDrawer` (per fault, Findings section) | `taskId` | `GET /maintenance-tasks/{task}/repair-intelligence` |
| `CheckpointModal` (per fault; ticket faults added to the checkpoint payload) | `taskId` | same GET |

Confirmed: identical presenter contract everywhere · zero duplicated frontend logic (one panel, one client)
· identical permission behaviour (both endpoints `maintenance.view`; cost gated by `billing.view` server-side)
· identical empty/low/ready states (all rendered by the one panel).

### Operator journey — "Engine Noise" (verified)
Technician picks *Engine Noise* → the panel shows, in plain language: **confidence badge** (honest band) ·
**recommendation headline** ("Recommend APEX — 100% success on 4 similar repairs · ~6d") · **likely cause** ·
**expected parts** · **duration** (and **cost** only with `billing.view`) · **recurrence risk** · the
**tiered similar repairs** (same vehicle → model → make → fleet) · and **"Why this recommendation"** with the
actual past repairs as evidence. No technical knowledge required to read it.

### Tests
- **Unit (DB-free), 30 green:** `ConfidenceScorerTest`, `RepairCohortStatsTest`, `RepairRetrievalTest`,
  `RepairIntelligencePresenterTest` (locks the frozen contract + redaction).
- **Feature (MySQL `laravel_test`), 7 green** — `tests/Crud/RepairIntelligenceApiTest`: frozen contract on
  both endpoints, preview-before-task, empty→`no_history`, permission 403, cost redaction without
  `billing.view`, cost visible with it, and a **bounded query count** (< 25, independent of cohort size).

### Manual QA checklist
- [ ] Open a ticket with an engine fault → drawer shows the panel with a confidence badge + similar repairs.
- [ ] Same fault in the Checkpoint modal shows the same panel/numbers.
- [ ] Start a new test-drive ticket, pick "Engine Noise" → live preview appears (no task saved yet).
- [ ] A car/fault with no history → neutral "No comparable repairs yet", never an error/blank.
- [ ] Log in as a user WITHOUT `billing.view` → no cost anywhere in the panel; duration/parts/success still show.
- [ ] Toggle Arabic → all panel labels localise (RTL intact).

### Performance
The endpoint is a **bounded scan**: 1 retrieval query + 2 batched signal queries (inspections, recurrence),
independent of cohort size — proven by `test_endpoint_query_count_is_bounded`. Caching intentionally deferred
(add the proven gzip-blob pattern only if a profiled endpoint exceeds budget at scale).

**Verdict: P0 is shipped.** Source of truth preserved, no new tables, contract frozen, confidence +
explainability honest, financial visibility enforced server-side. Next discussion = V1 (garage intelligence
cards, repair patterns, parts affinity, fleet-wide learning) — NOT an expansion of P0.
