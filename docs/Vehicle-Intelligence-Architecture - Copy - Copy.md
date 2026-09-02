# Vehicle Intelligence Engine — Architecture

**Status:** DESIGN ONLY. No code has been written. This document is the thing to argue with before anything is built.
**Scope:** a reusable intelligence layer, surfaced first as a `Vehicle Intelligence` section on the Vehicle Profile.
**Date:** 2026-08-01
**Amended:** 2026-08-01, after the Asset Layer (Vehicle Installed Components) shipped. The first draft was written
without it and specified component history as a Plane-A derivation from `maintenance_task_actions` — a second
calculation of something that now has an owner. Two owner rulings are folded in: the **ownership boundary** (§0.1,
§3, §4.3, §5, §14, §15, §17.3b) and the **reliability-vs-risk split** (§9.0, §10).

---

## 0. The one-paragraph version

Almost every deterministic statistic in the brief already exists somewhere in this codebase — scattered across
`OperationalKpiService`, `RepairHistoryQueryService`, `VehicleFaultRecurrenceService`, `MaintenanceAnalyticsService`,
`CostIntelligenceService` and `GarageOutcomeForecaster`. What does **not** exist is a *single addressable analytics
plane* that (a) computes a named metric for an arbitrary **scope** (this vehicle / this model / the fleet), (b) attaches
confidence and lineage to every number it emits, and (c) hands the whole structured result to a narrative layer.

So this is not "build a vehicle analytics service". It is: **extract a Metric Engine, register the existing
calculations into it as MetricSpecs, and make Vehicle Intelligence the first consumer.** The AI layer sits strictly
downstream of that engine and is given *no ability to compute* — it receives a finished, numbered fact sheet and writes
prose that cites fact IDs.

**No new source-of-truth tables.** One optional cache table for AI narratives (justified in §11.5); everything else is
computed live or memoised in the existing cache store.

---

## 0.1 Ownership boundary — Component Intelligence vs Vehicle Intelligence

**This is a standing constraint, not a preference.** It is stated before the layer map because it decides what several
sections below are allowed to contain.

| Owned by **Component Intelligence** (`vehicle_components` / `component_events`, `ComponentService`, `ComponentReadModel`, `ComponentLifecycle`) | Owned by **Vehicle Intelligence** |
|---|---|
| What is installed on a car right now | Fault rate, MTBF, recurrence |
| Replacement history and replacement chains | Downtime, turnaround |
| Lifecycle — expected service life, age vs distance, what is past due | Reliability score (§9) |
| Warranty state — window, remaining, expired | Peer comparison (§7), risk forecasts (§10), narrative (§11) |

Vehicle Intelligence **consumes** the component read model and the component event log. It **must not** compute
component history itself — not from `maintenance_task_actions`, not from `maintenance_line_items`, not from free text.

The reason is the one already given for comeback rate in §17.3, and it is the same defect: the fleet Component
Intelligence board and a vehicle's Intelligence tab would answer *"how many times was the battery replaced?"* with two
different numbers, computed by two pieces of code, and there would be no principled way to say which is right.
`ComponentService` is the sole writer of the component tables; `ComponentReadModel` is the sole reader that derives
anything from them. This engine sits downstream of both.

The dependency is one-way and must stay that way: Component Intelligence knows nothing about this engine.

---

## 1. Layer map

```
L0  SOURCES (unchanged, single source of truth)
    maintenances · maintenance_tasks · maintenance_task_assignments · maintenance_line_items
    maintenance_task_actions · repair_inspections · recurring_fault_reviews
    maintenance_signatures (rebuildable read-model) · vehicle_expenses · service_records
    vehicles · contracts
    ── reached ONLY through their owning service (§0.1), never queried directly ──
    vehicle_components / component_events   →  ComponentReadModel
    vehicle_expenses                        →  VehicleExpenseProvider
         │
L1  READ SERVICES  — App\Intelligence\Read\*
    Scope-parameterised, batched, N+1-free. Return flat immutable row sets. NO business logic.
         │
L2  METRIC ENGINE  — App\Intelligence\Metrics\*
    MetricSpec registry. Each spec: id, unit, direction, required evidence, compute(fn), confidence(fn).
    Runs any subset of metrics against any Scope. Emits MetricValue { value, confidence, sample_n, lineage }.
         │
L3  DOMAIN MODEL   — App\Intelligence\Vehicle\*
    VehicleIntelligenceProfile: the 12 sections of the brief, assembled from MetricValues + pattern detectors.
    Pure data. Serialisable. Versioned. This is the AI's input and the API's output.
         │
L4  NARRATIVE      — App\Intelligence\Narrative\*
    NarrativeProvider contract (provider-independent, mirrors App\Ontology). Consumes L3, returns
    cited prose. Cannot see L0/L1. Cannot emit a number that is not already an L3 fact id.
         │
L5  API            — IntelligenceController: GET /api/intelligence/vehicles/{v}  (+ ?sections= · ?refresh=narrative)
         │
L6  UI             — VehicleProfile → "Intelligence" tab → <VehicleIntelligence> section tree
```

**Rule of the layering:** each layer may only call the one below it. L4 may never reach L1. This is what makes
"the AI explains the data, it does not invent it" a structural property rather than a prompt instruction.

---

## 2. Data source contract, and the honest problem with it

### 2.1 What we read

| Signal | Table | Field(s) | Grade |
|---|---|---|---|
| Fault occurrence | `maintenance_tasks` | `kind='fault'`, `category_key`, `fault_catalog_id`, `symptom`, `identified_at` | Fact |
| Fault resolution | `maintenance_tasks` | `status`, `resolved_at`, `root_cause` | Fact |
| What was physically done | `maintenance_task_actions` | `action_catalog_id`, `performed_at` | Fact |
| Parts fitted | `maintenance_line_items` | `kind='part'`, `part_number`, `description` | Fact |
| Verified outcome | `repair_inspections` | `result` (fixed / still_exists) | Validated |
| Recurrence | `recurring_fault_reviews` | `previous_task_id` | Judgement |
| Workshop time | `maintenance_task_assignments` | stint start/end per garage | Fact |
| Ticket downtime | `maintenances` | `out_date`, `actual_in_date`, `expected_completion_date` | Fact |
| Money | `vehicle_expenses` | `amount`, `entry_date`, `remarks` | Imported |
| Fault text index | `maintenance_signatures` | `signature`, `classifier_version` | Derived |
| Concept normalisation | ontology (`OntologyNode` / `MatchPipeline`) | concept id from free text | Derived |

### 2.2 The two evidence planes — the central design constraint

The fleet's history is **not homogeneous**. It splits cleanly:

* **Plane A — Structured (post-capture).** Tickets created after the Repair Capture rollout carry
  `maintenance_task_actions`, catalog-linked faults, line items and `repair_inspections`. Component-level truth is
  directly readable — **from the Asset Layer, not from the action rows** (§0.1). A part reaches
  `vehicle_components` when the workflow installs it: from a part purchase, or (once Repair Capture → Component
  Ledger ships) from a `replace`/`install` action with no purchase behind it, carrying `evidence_channel` and
  `acquisition` so a technician-reported fit is never confused with a costed purchase. **This plane is small and
  young.**
* **Plane B — Derived (legacy).** ~26k historical rows whose only fault signal is free text (`symptom`,
  `maintenance_tags`, `remarks`), normalised into `maintenance_signatures` / ontology concepts. Actions are
  **never backfilled** (by deliberate design — see the `maintenance_task_actions` migration note). Costs are largely
  absent from maintenance rows.

Consequences that shape everything below, and which I would rather state now than have surface as a wrong number later:

1. **"Most replaced components" (§3 of the brief) is only truthful on Plane A.** On Plane B we can honestly say
   *"cooling-system work appeared 6 times"* (concept-level), **not** *"the water pump was replaced 3 times"*
   (component-level). The domain model therefore carries **two distinct section variants** —
   `ComponentReplacementSection` (Plane A, `grade=Fact`, **sourced entirely from `ComponentReadModel`**) and
   `ConceptRecurrenceSection` (Plane B, `grade=Derived`, sourced from ontology concepts) — and the UI renders
   whichever is supported, labelled. It never silently degrades one into the other.

   Within Plane A there is a second, finer distinction the read model already carries and this engine must preserve
   rather than flatten: a component whose `evidence_channel = purchase` has a known cost and a supplier; one whose
   `evidence_channel = repair_capture` is a technician's report with `purchase_cost = null`. Both are real
   replacements and both count toward `component.replacement_count`. Only the first may feed a cost metric — see
   §8.3 guard 3, which already forbids presenting partial cost coverage as a total.
2. **Cost intelligence must source from `VehicleExpenseProvider`, not from maintenance line items.** Under 2% of
   maintenance rows carry cost; `vehicle_expenses` is where the dirhams actually are. Anything money-shaped is gated
   behind the existing `SHOW_FINANCIALS` flag and carries `Confidence::IMPORTED`.
3. **`maintenance_signatures` is rebuildable and a rebuild moves the numbers.** Any metric derived from signatures must
   pin and report `classifier_version`. A reliability score that silently changes because a classifier was re-run is a
   trust-destroying bug, not a refinement.
4. **Per-vehicle seasonality is usually not defensible.** A vehicle with 9 lifetime faults cannot support a seasonal
   claim. Seasonality is computed at the **model cohort** level and only *attributed* to the vehicle when the vehicle's
   own events are consistent with it. See §7.4.

### 2.3 Freshness

No projection tables ⇒ **the intelligence is a pure function of the maintenance tables at read time.** When history
changes, the next read reflects it, modulo cache (§12). This satisfies "whenever maintenance history changes, the
intelligence automatically reflects the updated state" without a sync job to go stale.

---

## 3. L1 — Read services

Five thin services under `App\Intelligence\Read`. Each takes a `Scope` and returns flat arrays of value objects.
They contain **no metric logic** — that is the entire point; two different metrics reading the same rows must not
each write their own query.

```php
final class Scope {                     // the addressing primitive of the whole engine
    public ?int    $vehicleId;          // Tier 1
    public ?string $make;               // Tier 3
    public ?string $model;              // Tier 2
    public ?int    $yearFrom, $yearTo;  // optional cohort narrowing
    public ?string $category;           // fleet segment (sedan / SUV / van)
    public DateRange $window;
    public static function vehicle(Vehicle $v, DateRange $w): self;
    public static function peerCohortFor(Vehicle $v, DateRange $w): self;   // same make+model(+year band)
    public static function fleet(DateRange $w): self;
    public function cacheKey(): string;  // stable, order-independent
}
```

| Service | Returns | Notes |
|---|---|---|
| `FaultEventReader` | `FaultEvent[]` — one row per `maintenance_tasks` fault: concept, category, severity, identified/resolved, garage, outcome, recurred flag, plane | The spine. One query per scope, eager-loaded. Reuses `RepairHistoryQueryService`'s outcome/recurrence batching (`outcomesByFault`, `recurredFaultIds`) rather than re-deriving them. |
| `WorkshopStintReader` | `Stint[]` — garage-level presence intervals | From `maintenance_task_assignments`, falling back to ticket `out_date`/`actual_in_date` for Plane B. Overlap-merged (§6.3). |
| `RepairArtifactReader` | `Action[]`, `PartLine[]`, `Inspection[]` | Plane A only. Returns empty + `plane=A_absent` rather than nulls. **Actions are read as evidence of what was DONE, never as component state** — a `replace/alternator` row here is a repair fact; whether an alternator is fitted is `ComponentReader`'s answer (§0.1). |
| `ComponentReader` | `InstalledComponent[]`, `ReplacementEvent[]`, `LifecycleState[]`, `WarrantyState[]` | **Wraps `ComponentReadModel`. Never queries `vehicle_components` / `component_events` directly, and never re-implements `ComponentLifecycle`.** Exactly the `VehicleSpendReader` contract, for exactly the same reason. Carries `evidence_channel` and `acquisition` through untouched so downstream guards can tell a costed purchase from a technician report. |
| `VehicleSpendReader` | `SpendEvent[]` | Wraps `VehicleExpenseProvider`. Never queries `vehicle_expenses` directly. |

**Batching contract:** every reader exposes `forScopes(Scope[])` so the fleet-comparison pass (§7) issues **one** query
per reader across all peers, not one per peer.

**A note on `ComponentReader`'s batching, because it is the one that will hurt.** `ComponentReadModel` was built for a
fleet board and already does its counting and filtering in SQL — the commit that introduced it measured the obvious
Collection-based version at 5,256 ms / 4,957 queries / 60 MB against 206 ms / 24 queries / 12 MB for the SQL one. A
cohort pass that calls a per-vehicle method in a loop would walk straight back into the first number. If the read model
does not already expose a multi-vehicle entry point, **add one there** — do not add a query here. A second component
query living in this engine is the exact duplication §0.1 exists to prevent.

---

## 4. L2 — The Metric Engine (the reusable core)

### 4.1 MetricSpec

```php
final class MetricSpec {
    string   $id;              // 'fault.rate_per_1000_days'
    string   $label;           // i18n key
    string   $unit;            // 'count' | 'days' | 'aed' | 'ratio' | 'score'
    string   $direction;       // 'higher_is_better' | 'lower_is_better' | 'neutral'
    string[] $requires;        // ['fault_events'] | ['stints'] | ['actions','line_items'] | ['spend'] | ['components']
    int      $minSampleN;      // below this → MetricValue::insufficient()
    bool     $comparable;      // may this metric be compared across scopes? (§7)
    Closure  $compute;         // fn(MetricInput $in): float|array
    Closure  $confidence;      // fn(MetricInput $in, $value): ConfidenceVector
}
```

### 4.2 MetricValue — nothing leaves the engine bare

```php
final class MetricValue {
    string  $metricId;
    ?float  $value;              // null == genuinely unknown. NEVER 0-as-unknown.
    string  $unit;
    int     $sampleN;
    string  $grade;              // App\Services\Explainability\Confidence::* (MEASURED…MISSING)
    float   $confidence;         // 0..1
    string[] $confidenceReasons; // why it is not higher — human sentences
    string  $plane;              // 'A' | 'B' | 'mixed'
    ?string $classifierVersion;  // when signature-derived
    string[] $sourceRefs;        // ['maintenance_task:1841', …] — the traceability contract
    ?Comparison $vsPeers;        // filled by the comparison pass (§7)
}
```

`sourceRefs` is the mechanism behind *"every insight should be traceable back to historical maintenance records."*
It is capped (top-N contributing rows + a total count) so the payload stays bounded, and it is what the UI's
"show me the records behind this" drill-down reads.

### 4.3 The registry, and what goes in it

`MetricRegistry` holds the specs. Initial registrations — each one **wraps an existing calculation** where one exists:

| Metric id | Source of the calculation |
|---|---|
| `fault.count`, `fault.rate_per_1000_days` | new, from `FaultEventReader` |
| `fault.mtbf_days` | new (mean gap between fault events) |
| `fault.first_time_fix_rate` | **reuse `OperationalKpiService::firstTimeFixRate`** SQL, scope-parameterised |
| `fault.comeback_rate` | **must reuse `OperationalKpiService::comebackRate`** — this is a standing rule; a second comeback definition would put two different numbers on two screens |
| `fault.repeat_failure_rate` | reuse `OperationalKpiService::repeatFailureRate` |
| `downtime.total_days`, `downtime.avg_repair_days`, `downtime.longest_repair_days` | `WorkshopStintReader` + `RepairDurationQueryService` outlier guard (`max_duration_days = 120`) |
| `downtime.turnaround_days` | reuse `OperationalKpiService::workshopTurnaround` |
| `cost.total`, `cost.per_month`, `cost.per_1000km`, `cost.top_repairs` | `CostIntelligenceService` + `VehicleExpenseProvider` |
| `component.replacement_count`, `component.mean_interval_days` | **`ComponentReadModel` via `ComponentReader`** — the replacement chain it already derives. Plane A only. Never counted from `maintenance_task_actions`. |
| `component.past_service_life_count`, `component.oldest_service_life_pct` | **`ComponentLifecycle::serviceLife`** through the reader. Already pure and unit-tested, which is what §4.4 asks of every compute step, so it needs no adapter — call it, do not restate its thresholds. |
| `component.warranty_active_count`, `component.warranty_expiring_days` | **`ComponentLifecycle::warranty`** through the reader. Warranty windows derive from the ORIGINAL install and are never reset — that invariant belongs to the Asset Layer and is not re-derived here. |
| `concept.recurrence_count`, `concept.recurrence_interval_days` | ontology concepts + `maintenance_signatures` |
| `garage.visit_concentration` | `GaragePerformanceQueryService` |
| `reliability.score` | composite (§9) |

**Extracting `OperationalKpiService` into scope-parameterised specs is the single highest-value refactor in this
plan** — it turns fleet-only KPIs into per-vehicle and per-cohort KPIs with no new definitions, and it is what makes
the fleet comparison in §7 free.

### 4.4 The pipeline

```
MetricEngine::run(Scope $scope, string[] $metricIds): MetricSet

  1. PLAN       union of ->requires across requested specs
  2. LOAD       call only the needed readers, once, for the scope           ← the only DB access
  3. DERIVE     shared intermediates computed once and shared:
                  episodes (§6.1) · intervals · monthly buckets · concept histogram
  4. COMPUTE    each spec's compute(MetricInput) — pure, DB-free, unit-testable without a database
  5. GRADE      each spec's confidence() → ConfidenceVector; apply hard guards (§8)
  6. RETURN     MetricSet (immutable map metricId → MetricValue)
```

Steps 3–5 are **pure functions**. That is deliberate and matches the house style already used by
`ConfidenceScorer::score` and `GarageRecommendationService::scoreRows` — the maths gets locked by DB-free unit tests,
and only steps 1–2 need a database.

---

## 5. L3 — The Vehicle Intelligence domain model

```php
final class VehicleIntelligenceProfile {
    VehicleRef        $vehicle;
    Coverage          $coverage;        // §5.1 — read this before believing anything below
    HealthSummary     $health;          // brief §1  (deterministic inputs; prose from L4)
    RecurringProblem[]        $recurring;      // §2
    // §3 — plane-dependent (see §2.2). On Plane A these are ComponentReadModel's OWN DTOs, passed
    // through by ComponentReader. This engine does not define a parallel component shape (§0.1).
    ComponentReplacement[]|ConceptRecurrence[] $components;
    Pattern[]         $patterns;        // §4
    ?CostIntelligence $cost;            // §5 — null when SHOW_FINANCIALS off or no spend data
    DowntimeIntelligence $downtime;     // §6
    ReliabilityScore  $reliability;     // §7
    Observation[]     $observations;    // §8 — DETECTED deterministically, worded by AI
    RiskForecast[]    $risks;           // §9
    PeerComparison    $comparison;      // §10
    Milestone[]       $timeline;        // §11
    Recommendation[]  $recommendations; // §12
    Narrative         $narrative;       // L4 output, or Narrative::unavailable()
    Provenance        $provenance;      // engine version, classifier version, computed_at, cache age
}
```

### 5.1 `Coverage` — the section that must render first

```php
final class Coverage {
    int    $observedDays;         // from in-service anchor / first record to now
    int    $faultEvents;
    int    $planA, $planB;        // structured vs derived event counts
    float  $costCoverage;         // share of events with a known cost
    float  $outcomeCoverage;      // share with an independent verification
    bool   $sufficientForTrend;   // ≥ 2 comparable periods AND ≥ minSampleN per period
    bool   $sufficientForSeasonal;
    string[] $limitations;        // rendered verbatim in the UI and injected into the AI prompt
}
```

This is the structural answer to *"if confidence is low or data is insufficient, say so."* The AI is not asked to
*decide* whether data is sufficient — **the engine decides, and the AI is told.** Suppression is deterministic:
`sufficientForTrend=false` means the trend claim is not in the prompt at all, so it cannot be written.

---

## 6. Shared derivations (computed once, used by many metrics)

### 6.1 Episode grouping
Raw rows are not events. Three tickets in five days for the same concept are **one episode**. Reuse the episode logic
already proven in `VehicleFaultRecurrenceService::groupEpisodes` rather than writing a second grouping rule — otherwise
"repair frequency" and "repeat-fault chains" will disagree on the same screen. Config: same-concept gap ≤ N days
(default 14) collapses into one episode; the episode carries `visits`, `first_seen`, `closed_at`.

### 6.2 Recurrence vs repetition
* **Repetition** — the same concept appears again, any time. Cheap, always available.
* **Recurrence (failure)** — the same concept returns **after a repair that was supposed to fix it**, inside the
  recurrence window. Authoritative source: `recurring_fault_reviews` + `repair_inspections.result=still_exists`.

The brief's "the same issue has returned multiple times after repair" is *recurrence*, not repetition, and only
recurrence may feed the reliability penalty. Conflating them would punish a vehicle for scheduled repetition of
consumables.

### 6.3 Downtime intervals
Stints are **merged, not summed**. Two faults worked in parallel at one garage over the same week are 7 downtime days,
not 14. Merge overlapping intervals first, then measure. Apply the `max_duration_days=120` outlier guard so unclosed
legacy rows do not create fictional year-long repairs. `downtime.total_days` is reported against
`FleetUtilizationService`'s definition of maintenance days so the vehicle page and the utilisation page agree.

### 6.4 Trend
One trend primitive for the whole engine: split the window into two halves (or into 6/12-month buckets), compare
half-over-half with a **Poisson-appropriate** test for counts. Report `direction ∈ {improving, stable, worsening,
indeterminate}` plus `deltaPct`. **`indeterminate` is a first-class result** and is the default when
`Coverage::sufficientForTrend` is false — this is what stops "repair frequency increased 35%" appearing off 3 events.

---

## 7. Fleet comparison methodology

### 7.1 The cohort ladder — already designed, reuse it
`RepairHistoryQueryService` already defines the exact ladder the brief asks for, with a tested pure helper
(`assignTier`) and confidence factors per tier:

```
Tier 1 same vehicle → Tier 2 same make+model → Tier 3 same make → Tier 4 fleet
```

Peer selection walks **down** the ladder until the cohort reaches `minPeers` (default 8 vehicles / 30 events):
`make+model+year±2` → `make+model` → `make` → `body category` → `fleet`. The tier that was actually used is reported
in the payload and shown in the UI ("compared against 14 same-model vehicles").

### 7.2 Normalisation — the part that is easy to get wrong
Raw counts are meaningless across vehicles with different exposure. **Every comparable metric is normalised by
exposure before comparison**, using the strongest available denominator:

1. `per 1,000 km` (preferred — needs a trustworthy odometer chain; use `MileageBaselineService`)
2. `per 1,000 days in service` (fallback — anchored on the **In-Service anchor**, i.e. first rental, not purchase date)
3. `per rental` (last resort)

Vehicles below a minimum exposure floor are **excluded from the peer cohort** (they drag averages down and inflate
this vehicle's apparent badness) — and the exclusion count is reported.

### 7.3 The comparison statistic
Report **percentile within cohort**, not only a percentage difference from the mean. Maintenance distributions are
heavily right-skewed; "27% above average" against a mean dragged by one catastrophic peer is misleading, whereas
"this vehicle is at the 78th percentile of 14 peers" is robust. Emit both, lead with percentile:

```php
final class Comparison {
    float  $vehicleValue; float $cohortMedian; float $cohortP90;
    float  $percentile;    float $deltaPct;
    int    $cohortVehicles, $cohortEvents;
    int    $tierUsed;      // 2..4
    float  $shrunkValue;   // James–Stein style shrink toward cohort median for small n
    bool   $significant;   // false when the difference is inside noise for this sample size
}
```

**Small-sample shrinkage:** with n<10 events the reported comparison uses the shrunk value
`(n·v + k·median)/(n+k)` (k≈5). A vehicle with 2 faults must not be declared "top 5% most reliable".
`significant=false` suppresses the claim from the AI prompt entirely.

### 7.4 Seasonality
Computed at cohort level (needs ≥2 full years and ≥5 events per season bucket). Attributed to the vehicle only when
the vehicle's own events are consistent with the cohort pattern. Otherwise it is presented as a **fleet pattern that
may apply**, explicitly labelled as such. Given UAE operations, the summer/cooling correlation is the one genuinely
likely to clear this bar.

---

## 8. Confidence calculation

### 8.1 Two orthogonal things, kept separate
* **Grade** (`Confidence::MEASURED | IMPORTED | CALCULATED | ESTIMATED | CORRECTED | VALIDATED | MISSING`) —
  *what kind of knowledge is this?* Reuse the existing enum verbatim; the Explainability platform already speaks it.
* **Score** (0..1 + band) — *how much evidence is behind it?* Reuse `ConfidenceScorer`'s blend, which already
  implements exactly the right factors: evidence (√-credibility ramp), tier specificity, success, recency, completeness,
  with **hard band guards** so a thin cohort can never read "high".

### 8.2 The per-metric vector
```php
final class ConfidenceVector {
    float $evidence;      // sample size vs credibility n
    float $specificity;   // cohort tier factor (1.0 / 0.8 / 0.6 / 0.45)
    float $recency;       // fresh 90d → stale 540d ramp
    float $completeness;  // share of contributing rows with the needed fields
    float $consistency;   // agreement across sub-periods (new — a wildly unstable metric is less trustworthy)
    float $score; string $band; string[] $reasons;
}
```

`consistency` is the one addition to the existing scorer, and it exists so a "trend" claim built on a jagged series
is downgraded rather than presented as a clean slope.

### 8.3 Guards (non-negotiable, applied after the blend)
1. `sampleN < spec.minSampleN` → `value=null`, band=`insufficient`, metric excluded from the AI prompt.
2. Plane-B-derived component claims are capped at `medium` and grade `DERIVED`.
3. Any cost metric with `costCoverage < 0.5` is capped at `low` and labelled *"based on N of M repairs with known
   cost"* — never presented as a total.
4. A metric whose inputs include a signature rebuild newer than the comparison window → `stale_classifier` reason.

---

## 9. Reliability score (0–100)

### 9.0 What the score is allowed to contain — OWNER RULING

The score measures **what has actually gone wrong**. It does not measure **what might go wrong next**. Those are
different questions, they belong to different sections, and merging them makes both unreadable.

| In the reliability score (§9) — *events that happened* | In the risk layer (§10) — *state and obligations* |
|---|---|
| Actual failures (fault events) | Component age |
| Repeat faults | Service life exceeded |
| Comeback events | Warranty expiration |
| Downtime | Upcoming maintenance obligations |
| Repair-quality signals (QC verdicts, first-time fix) | |

**Component lifecycle is therefore excluded from the penalty**, deliberately. The reasoning is worth keeping because
the temptation to add it will return: penalising a car for *state* means a well-maintained vehicle whose parts are old
but working scores **worse** than one whose parts all failed last month and were replaced. That inverts the thing the
score exists to say. A part past its expected life is a real and actionable signal — it is a `component_life` risk
(§10), where it is presented as an obligation with a date rather than as a deduction from a grade.

The same rule applies to warranty: an expiring warranty is a commercial deadline, not evidence of unreliability.

### 9.1 Construction
Deliberately **boring, additive, and explainable** — no ML, no hidden weights:

```
start 100
  − w1 · normalised fault rate percentile      (exposure-normalised, §7.2)
  − w2 · recurrence rate            (§6.2 — repairs that did not hold; the heaviest single penalty)
  − w3 · downtime days percentile
  − w4 · severity mix               (critical/high faults weigh more than routine)
  − w5 · cost percentile            (ONLY when SHOW_FINANCIALS and costCoverage ≥ 0.5)
  + age/exposure adjustment         (an old high-km vehicle is not "unreliable" for wearing out)
clamp 0..100 → band {excellent, good, watch, poor}
```

Note what is absent and must stay absent (§9.0): no term for component age, service life used, or warranty state.
`w4 severity mix` is the closest neighbour and is legitimate — it weights faults that *occurred* by how bad they were,
which is still an event, not a state.

### 9.2 Rules that keep it honest
* **Every term is emitted with its own contribution**, so the UI renders a waterfall ("−12 recurrence, −8 downtime").
  This is the brief's "show the reasoning behind the score", and it is data, not prose.
* **Weights live in `config/vehicle_intelligence.php`**, never in code — same convention as
  `config/garage_recommendation.php` and `config/knowledge.php`.
* **Terms with insufficient data are dropped and the remaining weights renormalised**, with the dropped term listed.
  A vehicle is never penalised for our missing data.
* **The score is registered as an `Explainer`** on the existing `ExplanationEngine` (one-line registration, per its
  class doc). Vehicle Intelligence then inherits the platform's drill-down for free, and the score becomes auditable
  through the same UI as financials and service-due.
* **Below a coverage floor the score is not shown at all** — it renders as "Not enough history" rather than as a
  confident 82.

---

## 10. Future risk prediction — deterministic, not AI

The AI does **not** predict. Risks are computed from cohort intervals and component state, and handed to it as facts.

This is also where everything §9.0 excluded from the reliability score lands: **component age, service life exceeded,
warranty expiration and upcoming maintenance obligations are risk signals, not reliability penalties.**

```php
final class RiskForecast {
    string $conceptId; string $label;
    ?int   $vehicleComponentId;     // set on component-derived risks — the drill-down target
    int    $horizonDays;            // 90 / 180 / 365
    ?float $probability;            // empirical, from the peer cohort's interval distribution.
                                    // NULL on deterministic bases — an obligation has no probability.
    string $basis;                  // 'vehicle_interval' | 'cohort_interval' | 'component_life'
                                    // | 'warranty_expiry' | 'due_service' | 'open_deferred'
    int    $daysSinceLast; ?int $medianInterval; ?int $p90Interval;
    ConfidenceVector $confidence;
    string[] $sourceRefs;
}
```

Method, in precedence order:

1. **Vehicle's own interval** (needs ≥3 prior occurrences of that concept) — empirical hazard: of peer/self intervals
   observed, what share fall inside `daysSinceLast + horizon`.
2. **Cohort interval** (Tier-2/3) when the vehicle's own history is too thin — the common case.
3. **Component state — `basis='component_life'` and `basis='warranty_expiry'`.** Straight from
   `ComponentLifecycle` through `ComponentReader` (§3); this engine does not restate the thresholds.
   `serviceLife` already measures age and distance together and lets the harsher clock win, which is the right
   rule and is already unit-tested. A part past its expected life is an **obligation with a name and a date**
   ("front brake pads, fitted 41,000 km ago against a 40,000 km expectation"), not a probability — so
   `probability` is null and the UI renders the fact, not a band.

   This outranks the cohort estimate when both fire on the same concept: knowing *this car's* pads are past due
   beats knowing that pads on this model typically last two years. When the vehicle has no component row for the
   concept (Plane B, most of the fleet today) the cohort estimate is all there is, and that is the honest fallback
   rather than a silent zero.
4. **Deterministic due-work** — open deferred faults, overdue service reminders, expiring items. Not a prediction at
   all; it is a known obligation and should be shown as such (`basis='due_service'`), which is far more actionable than
   a probabilistic guess.
5. **Calibration.** Reuse the pattern already built in `Garage\ForecastCalibration` /
   `ForecastCalibrationCommand`: store predicted-vs-actual and report Brier score. **A probability nobody ever checks
   is decoration.** Until there is a calibration reading, risk probabilities render as bands (likely / possible /
   unlikely), not as decimals — precision we have not earned is worse than a band.

   Calibration applies to bases 1–2 only. A `component_life`, `warranty_expiry` or `due_service` risk is not a
   forecast and has nothing to calibrate; scoring it against a Brier would be a category error.

---

## 11. L4 — The AI interpretation layer

### 11.1 Provider independence
Follow `App\Ontology`'s established pattern exactly — a contract plus swappable providers, so the platform is not
tied to one vendor:

```
App\Intelligence\Narrative\Contracts\NarrativeProvider
App\Intelligence\Narrative\Providers\Anthropic\AnthropicNarrativeProvider   // reuses the wired Anthropic\Client
App\Intelligence\Narrative\Providers\NullDriver\NullNarrativeProvider       // returns Narrative::unavailable()
```

`NullNarrativeProvider` is the default. **Every section of the UI must be fully usable with the AI layer switched
off** — the deterministic analytics are the product; the narrative is the polish.

### 11.2 What the AI is given
Not raw records. A **rendered fact sheet** derived from L3, in which every fact carries an id:

```json
{
  "vehicle": {"plate":"…","make":"…","model":"…","year":2021,"in_service_days":1180,"km":142000},
  "coverage": {"fault_events":34,"plane_a":6,"plane_b":28,"cost_coverage":0.18,
               "sufficient_for_trend":true,"sufficient_for_seasonal":false,
               "limitations":["Only 18% of repairs have a recorded cost",
                              "Component-level detail exists for 6 of 34 repairs"]},
  "facts": [
    {"id":"F1","metric":"fault.rate_per_1000_days","value":9.4,"unit":"count",
     "vs_cohort":{"percentile":78,"median":6.1,"cohort_vehicles":14,"tier":2,"significant":true},
     "confidence":{"band":"medium","reasons":["cohort of 14 vehicles"]}},
    {"id":"F2","metric":"concept.recurrence_count","concept":"cooling_system","value":4,
     "confidence":{"band":"high"}}
  ],
  "detected_observations":[
    {"id":"O1","type":"repeat_component","concept":"battery","count":3,"span_days":720,"fact_refs":["F7"]}
  ],
  "risks":[{"id":"R1","concept":"cooling_system","horizon_days":180,"band":"likely","fact_refs":["F2","F9"]}]
}
```

### 11.3 What the AI returns — a strict, validated schema
```json
{
  "executive_summary": {"text":"…","fact_refs":["F1","F2"]},
  "vehicle_story":     {"text":"…","fact_refs":["F1","F4","F7"]},
  "observations":  [{"observation_id":"O1","text":"…","fact_refs":["F7"]}],
  "recommendations":[{"text":"…","priority":"high","rationale":"…","fact_refs":["F2","R1"]}],
  "insufficient_data_notes":["…"]
}
```

### 11.4 The enforcement, which is the whole point
* **Post-validation, not trust.** Every `fact_refs` id must exist in the input. Unknown id ⇒ the block is **dropped**,
  not repaired. Logged as a provider-quality metric.
* **Numeric guard.** Any number appearing in returned prose is extracted and matched against the fact sheet's values
  (with tolerance for rounding/units). An unmatched number ⇒ block dropped. This is the concrete mechanism for
  "the AI should explain the data, not invent it"; a prompt instruction alone is a suggestion.
* **Every observation must reference a `detected_observations` id.** The AI **words** observations that the
  deterministic layer already found. It does not discover new ones. Brief §8's examples ("battery replaced three times
  in two years", "cooling failures every summer") are all detector output — they are pattern-matching, not language
  work, and belong in L2.
* **Suppression by construction.** Sections the coverage gate marked insufficient are absent from the input, so no
  amount of model creativity can produce a claim about them.
* **Recommendations are constrained to a catalog** (inspect X / monitor X / bundle X into next service / escalate /
  consider replacement) so they land as actionable operations rather than free-form advice — and so they can later
  become one-click actions that mint a ticket or a service reminder.

### 11.5 Storage — the one new table, and why
Narratives are the only expensive part of the pipeline. Cache them in `vehicle_intelligence_narratives`:

```
vehicle_id · analytics_fingerprint (sha256 of the L3 fact sheet) · provider · model_version
prompt_version · payload(json) · generated_at · token_cost · dropped_blocks_count
```

This is a **cache, not a source of truth** — droppable and rebuildable, in the same category as
`maintenance_signatures`. It earns its place because: (a) fingerprinting on the fact sheet means the narrative
regenerates **exactly when the analytics change** and never otherwise; (b) it makes cost per vehicle observable;
(c) it lets us A/B a prompt version across the fleet. Without it we either pay per page view or serve stale prose with
no way to tell.

Generation is **queued and asynchronous** — the page returns deterministic analytics immediately with
`narrative.status = generating`, and the narrative arrives via the existing notification channel or a poll. Note:
`app/Jobs` does not exist yet; this introduces the first queued job in the backend, so queue worker provisioning is a
real deployment prerequisite, not a detail.

---

## 12. Caching strategy

Three tiers, matching how volatile each layer is:

| Tier | What | Key | TTL | Invalidation |
|---|---|---|---|---|
| 1 | Peer cohort aggregates (expensive, fleet-wide, slow-moving) | `vi:v{ver}:cohort:{make}:{model}:{yearBand}:{window}` | 6 h | version bump |
| 2 | Per-vehicle `MetricSet` | `vi:v{ver}:vehicle:{id}:{window}` | 30 min | version bump + targeted forget |
| 3 | Narrative | DB, keyed by `analytics_fingerprint` | none | fingerprint change |

**Invalidation uses the version-prefix pattern already established by `DashboardService::flushCache()`** — the
`database`/`file` cache stores have no tag support, so a single version bump is the only reliable way to invalidate
every parameter variant at once. `VehicleIntelligenceCache::flush()` mirrors it exactly.

Bump on: maintenance ticket close, fault resolve, repair capture submit, inspection verify, expense import, signature
rebuild. Wire via model events on `Maintenance`/`MaintenanceTask` — the same places that already call
`recalcFromTasks()`.

**Cold-read budget: < 400 ms** for a vehicle with warm cohort cache. If a cold cohort read exceeds this, the fix is a
nightly warm of Tier 1 for all active make/model combinations — **not** a projection table.

---

## 13. API surface

```
GET  /api/intelligence/vehicles/{vehicle}
       ?sections=health,recurring,downtime,reliability,comparison,risks
       &window=24m
       &narrative=cached|skip|refresh
     → { vehicle, coverage, sections{…}, narrative{status,…}, provenance }
     permission: insights.view   (existing `intelligence` prefix group)

GET  /api/intelligence/vehicles/{vehicle}/metrics/{metricId}/evidence
     → the underlying maintenance_task / expense rows behind one number (drill-down)

GET  /api/intelligence/metrics                 → the MetricSpec catalog (self-documenting)
POST /api/intelligence/vehicles/{v}/narrative  → force regeneration (rate-limited, permission:insights.manage)
```

Sections are individually requestable so the UI can lazy-load below-the-fold sections and the page's first paint is
not hostage to the slowest metric.

---

## 14. UI component hierarchy

Added as a new **`Intelligence` tab** on `VehicleProfile.js` (which already has overview / financials / visits /
timeline / journey / checkpoints / complaints / components / media). It does not replace the Timeline tab — Timeline
stays the investigation tool; Intelligence is the interpretation.

**It does not add a second components surface either.** `VehicleComponentsPanel` already occupies the Components tab
and is the per-car view of the Asset Layer. Intelligence *links* to it and reads its data; it does not restate the
installed list. Two tabs on one page both answering "what parts are on this car?" is the UI expression of the
duplication §0.1 forbids at the data layer.

```
<VehicleIntelligence vehicleId>
├── <IntelligenceHeader>            reliability dial · trend chips · coverage badge · "computed from N records"
├── <CoverageBanner>                renders Coverage.limitations — always, never collapsed away
├── <HealthSummaryCard>             AI executive summary + confidence chip + "show the facts" → fact refs
├── <SectionGrid>
│   ├── <RecurringProblemsCard>     concept chips, occurrence counts, recurrence-vs-repetition split
│   ├── <ComponentSummaryCard>      Plane A: replacement counts + mean intervals, each row deep-linking
│   │                               INTO the Components tab │ Plane B: concept recurrence (labelled).
│   │                               A summary and a doorway — never a second installed-parts list.
│   ├── <PatternsCard>              interval histogram · garage concentration · duration trend
│   ├── <CostCard>                  behind SHOW_FINANCIALS; hidden entirely when off
│   ├── <DowntimeCard>              total/avg/longest + trend sparkline
│   └── <ComparisonCard>            percentile bars vs cohort + "compared against N same-model vehicles"
├── <ReliabilityBreakdown>          waterfall of score contributions (collapsible)
├── <RiskForecastList>              horizon-grouped, band-labelled, each with basis + drill-down
├── <TimelineMilestones>            annotated milestones over the existing timeline data
└── <RecommendationList>            catalog-typed actions; later: one-click → ticket / reminder
```

Cross-cutting UI rules:

* **`<ConfidenceChip>` and `<EvidenceLink>` are shared primitives**, not per-card implementations. Every AI-authored
  string renders inside a wrapper that carries its confidence and its fact refs. This is the standing
  Traceability/Data-Origin rule — no black boxes.
* **Charts only where they beat a number.** Trend sparkline, interval histogram, percentile bar, reliability
  waterfall. A donut of fault categories is decoration; a ranked list is better and reads faster.
* Follow the Cockpit+ design system; all strings via `tf()`/`tp()` so `npm run check:i18n` passes.
* **Skeletons per section**, driven by the per-section API — the page must feel instant even when a cohort is cold.

---

## 15. Extensibility — adding the next intelligence module

The reuse claim has to be concrete, or it is marketing. Adding **Garage Intelligence**, **Model/Cohort Intelligence** or
**Driver Intelligence** later means:

1. **Nothing** in L1 — readers are already scope-parameterised (`Scope::garage(...)`, `Scope::model(...)`).
2. **Register new MetricSpecs** for anything genuinely new; **reuse existing spec ids** for everything shared. The
   comparison and confidence machinery applies unchanged.
3. **A new L3 assembler** (`GarageIntelligenceProfile`) — the only genuinely new code.
4. **A new prompt template + fact-sheet renderer**; the validation, refs enforcement and numeric guard are shared.
5. **Register one `Explainer`** on `ExplanationEngine` for drill-down.
6. **Reuse the L6 primitives** (`ConfidenceChip`, `EvidenceLink`, `SectionGrid`).

Litmus test for the design: *Garage Intelligence should be roughly one assembler plus one prompt.* If it turns out to
need new readers or a second comparison implementation, the abstraction boundary in §4 was drawn wrong and should be
fixed then rather than duplicated.

**Component Intelligence is deliberately not on that list.** It already exists — a fleet board, a per-car tab, a read
model and a lifecycle service, all upstream of this engine (§0.1). Listing it as a future module of *this* engine is
precisely how a second replacement-history calculation would get written. If Component Intelligence needs a cohort
comparison later ("do this model's alternators fail faster than the fleet's?"), the right shape is Component
Intelligence **calling** the Metric Engine with a component-scoped `Scope`, not this engine growing a component
implementation. The dependency direction never reverses.

---

## 16. Phasing

| Phase | Deliverable | Ships value alone? |
|---|---|---|
| **1** | `Scope`, L1 readers (incl. `ComponentReader` wrapping `ComponentReadModel`), `MetricEngine`, `MetricRegistry`, `MetricValue`. Extract `OperationalKpiService` into scope-parameterised specs. Pure-function unit tests, no UI. | Per-vehicle KPIs become queryable |
| **2** | L3 profile (sections 1–6, 11) + API + Intelligence tab, **no AI**. Coverage banner, evidence drill-down. | **Yes — this is most of the product** |
| **3** | Fleet comparison + reliability score + Explainer registration. | Yes |
| **4** | Deterministic observation + pattern detectors, risk forecasts as bands. | Yes |
| **5** | Narrative layer: provider, fact sheet, validation, queue, cache table. | Polish |
| **6** | Calibration loop for risk probabilities; promote bands → numbers only once Brier is measured. | Trust |

Phase 2 is deliberately a complete, shippable product with zero AI. If the narrative layer never ships, the vehicle
page is still transformed. That ordering is the risk control.

---

## 17. Open decisions and honest risks

1. **Plane-B thinness is the top risk.** If the ontology's concept coverage over 26k legacy rows is weak, the
   recurring/component sections will be sparse for most vehicles. **Recommended gate before Phase 2: measure concept
   coverage across the fleet and set `minSampleN` from the actual distribution, not from intuition.** I would rather
   size the sections to the data than build cards that render empty on 80% of vehicles.

   Component coverage is the sharper edge of the same risk. `vehicle_components` only holds parts the workflow has
   installed since the Asset Layer was switched on, so today the component sections are empty for nearly every
   vehicle, and no backfill will change that honestly. Repair Capture → Component Ledger widens the intake (an install
   no longer needs a purchase behind it), but this section grows at the rate the fleet is repaired — months, not a
   migration. **Plan the UI for "no component history yet" as the normal case, not the error case.**
2. **Cost coverage (~18% or lower).** My recommendation: ship Cost Intelligence in Phase 2 as *"spend we can see"*
   with a prominent coverage label, rather than waiting for full costing that may never arrive.
3. **Two comeback definitions would be a real defect.** The design mandates reusing `OperationalKpiService`'s SQL;
   this needs enforcing at review time, since the temptation to write a quick per-vehicle variant is high.

3b. **Two component-history definitions are the same defect, and the temptation is higher.** `maintenance_task_actions`
   contains rows literally saying `replace / alternator`, so counting replacements from them looks obvious and cheap —
   and would immediately disagree with the Component Intelligence board, which counts the Asset Layer's replacement
   chain. They differ for real reasons: an action can be recorded and the component write skipped (consumable,
   unmapped target, shadow-mode failure), and a component can be replaced with no action row (purchase install with
   no capture). §0.1 is the rule; **review gate: any new SQL touching `vehicle_components`, `component_events`, or any
   count of `verb='replace'` outside `ComponentService`/`ComponentReadModel` is rejected by default.**
4. **Queue infrastructure does not exist yet** (`app/Jobs` is empty). Phase 5 depends on a provisioned worker.
5. **Age adjustment in the reliability score is a value judgement**, not a fact. It needs your call: should a 5-year-old
   high-km vehicle be scored against its own cohort (fair) or against the whole fleet (blunt but comparable)? I lean
   cohort-relative with the absolute number also shown.

5b. ~~Should component lifecycle penalise the reliability score?~~ **DECIDED (owner, 2026-08-01): no.** The score
   measures failures that happened; lifecycle is a risk signal only. See §9.0 for the split and the reasoning.
6. **AI cost per vehicle** is bounded by the fingerprint cache, but a fleet-wide first generation is a real one-time
   spend worth estimating before Phase 5.
7. **Does Vehicle Intelligence own recommendations, or feed the existing recommendation queue?** The maintenance
   platform already has `Recommendation` / `RecommendationEvent` models. My recommendation: **feed them**, so an
   intelligence recommendation can become a real ticket rather than dying as text on a page.
