# Recurrence Convergence — Final Implementation Plan

**Date:** 2026-08-04 · **Status:** Awaiting approval. No code until approved.
**Supersedes:** the migration sections of `Recurrence-Metric-Convergence.md` (that document remains
the audit and the evidence; this one is the plan).
**Blocks:** PR #2 Garage Intelligence.

---

## 0. One conflict in the brief, surfaced before we build

Your §5 requires that after this work the **recommendation engine** produces the same recurrence
numbers as every other surface. Your §8 requires that **C3/C4 (forecasting and routing) are not
touched** until convergence is complete and separately proposed.

Both are right, and they cannot both be true at the end of this PR.

**Resolution — convergence ships in two declared phases:**

| | Surfaces converged | Recommendation engine |
|---|---|---|
| **Phase 1** *(this plan)* | `/garages`, Scorecards, Executive, Fleet Intelligence, comparison, profile, APIs, exports, `OperationalKpiService`, Repair Intelligence | **still legacy — quarantined, declared, tested as the only exception** |
| **Phase 2** *(separate proposal, per your §8)* | C3 `GarageOutcomeForecaster`, C4 `ForecastCalibration` | converged; routing impact assessed first |

The guardrail test therefore ships with an **explicit, tested allowlist of exactly two files**, not a
silent exemption. A third entry fails CI. When Phase 2 lands the allowlist becomes empty and the test
asserts that. This is the honest way to hold "no second implementation" while deliberately deferring
two files.

---

# 1. Canonical Recurrence Architecture

```
  ┌──────────────────────────────────────────────────────────────────────────┐
  │  CAPTURE LAYER                          business meaning: what was seen  │
  │  maintenance_signatures  (49,487 rows, 2–8 per fault-day)                │
  │  maintenances            (26,942 rows, events not visits)                │
  └───────────────────────────────┬──────────────────────────────────────────┘
                                  │  intelligence:rebuild-recurrence  (nightly)
                                  │  deduplicate → order → look ahead → observe
                                  ▼
  ┌──────────────────────────────────────────────────────────────────────────┐
  │  BUSINESS INTELLIGENCE LAYER            business meaning: what happened  │
  │  fault_recurrence_pairs  (12,608 events)   ← THE canonical dataset       │
  └───────────────────────────────┬──────────────────────────────────────────┘
                                  │
                    ┌─────────────▼─────────────┐
                    │   RecurrenceRepository    │  ← THE only reader
                    │   + RecurrenceWindow      │
                    │   + config/metrics/       │  ← THE only definition
                    │        recurrence.php     │
                    └─────────────┬─────────────┘
                                  │
        ┌─────────────────────────┼──────────────────────────┐
        │                         │                          │
   MetricRegistry          GarageScorecardService     ProjectionRepairHistoryQuery
   (scalar KPIs —          (garage × domain report —   (per-signature history)
    mandatory façade)       high-volume, direct)
        │                         │                          │
   Executive, APIs,        /garages, matrix,           Repair Intelligence
   Fleet Intelligence      profile, comparison
```

**The capture / intelligence boundary is the load-bearing idea.** `maintenance_signatures` records
what a classifier or a human *saw* — several rows per real event, by design. `fault_recurrence_pairs`
records what *happened* — one row per real event. Confusing the two is what produced six definitions.
The boundary is enforced by the guardrail test in §7, not by convention.

---

# 2. Repository Responsibilities

**`app/Intelligence/Recurrence/RecurrenceRepository.php`** — the single query surface.

| Method | Returns | Consumer |
|---|---|---|
| `fleet(RecurrenceWindow): RecurrenceStats` | n, returned, rate, median gap, coverage, asOf | `OperationalKpiService`, Executive |
| `byGarage(RecurrenceWindow, ?int[] $vendorIds): Collection<vendorId, RecurrenceStats>` | per garage | leaderboard, profile |
| `byGarageAndDomain(RecurrenceWindow): Collection<vendorId, domain, RecurrenceStats>` | per cell | `GarageScorecardService`, matrix |
| `bySignature(RecurrenceWindow, string $sig): RecurrenceStats` | per fault | Repair Intelligence, Fault Intelligence |
| `byVehicle(RecurrenceWindow, int $vehicleId): Collection` | per car | Vehicle Intelligence |
| `pairs(RecurrenceQuery): LazyCollection` | the raw paired rows | **Evidence Drawer only** |
| `coverage(RecurrenceWindow): Coverage` | covered / total / asOf | every `Kpi` |

**`RecurrenceStats`** — an immutable value object carrying `n`, `returned`, `held`, `back30`,
`back90`, `medianGapDays`, `coverage`, `asOf`. It never carries a *score*; scoring is the caller's
job. This keeps the repository a measurement surface, not a judgement surface.

**`RecurrenceWindow`** — value object holding `windowDays`, `horizonMode` (`corpus_max` | `none`),
`excludeExposure`, `labelSources`. Constructed from `config/metrics/recurrence.php`. Every method
takes one, so a caller cannot accidentally use a different window than the contract.

**Hard rules**
- The repository is the **only** class that names `fault_recurrence_pairs` in a query.
- It never applies case-mix, weighting, or scoring — those are domain logic and live in the caller.
- It returns measurements with coverage attached; it never returns `0` for "not measurable".

---

# 3. MetricRegistry Integration

**Scalar recurrence KPIs — mandatory through the registry:**

| Metric code | Resolver | Surfaces |
|---|---|---|
| `recurrence.fleet_comeback_rate` | `FleetComebackResolver` | Executive, Intelligence Center |
| `recurrence.fleet_first_time_fix` | `FirstTimeFixResolver` | Executive |
| `recurrence.garage_comeback_rate` | `GarageComebackResolver` | leaderboard, profile |
| `recurrence.garage_return_days` | `GarageReturnDaysResolver` | leaderboard, profile |
| `recurrence.signature_comeback_rate` | `SignatureComebackResolver` | Fault Intelligence |
| `recurrence.vehicle_recurrence_rate` | `VehicleRecurrenceResolver` | Vehicle health |

Each resolver: reads `RecurrenceRepository`, applies the §0 sample gate via `MetricResolver::resolve()`,
attaches `Coverage` + `asOf` + `evidenceQueryId`. Returns a `Kpi`.

**High-volume analytical views — repository direct, registry bypassed, definition never bypassed.**
The Garage × Fault matrix is 44 garages × 15 domains = 660 cells. Routing that through the registry
would mean 660 resolver invocations per page load. It calls `byGarageAndDomain()` once.

**The line, stated so it cannot drift:**

> A surface may bypass `MetricRegistry` for volume. **No surface may bypass `RecurrenceRepository`.**
> A single scalar shown to a user must come from the registry.

---

# 4. Migration Map — C1 to C6

| # | File | Current responsibility | Current calculation | Canonical replacement | Disposition |
|---|---|---|---|---|---|
| **C1** | `app/Kpi/OperationalKpiService.php` | fleet baseline: first-time-fix, comeback, repeat-failure | `EXISTS` self-join over raw signatures, 90d, **no horizon** | `RecurrenceRepository::fleet()` via `MetricRegistry` | **REFACTOR** — `recurrenceStats()` body replaced; the other 13 KPIs untouched |
| **C2** | `app/Services/Garage/GarageScorecardService.php` | garage × domain report, case-mix, grading, leaderboards | correlated subquery, 90d, `MAX(occurred_at)−90d` | `RecurrenceRepository::byGarageAndDomain()` | **REFACTOR** — recurrence pass (~25 lines) replaced. **Case-mix, horizon, exposure rule, weighting, floors, grading, ranks, leaderboards, provenance all preserved** |
| **C3** | `app/Services/Garage/GarageOutcomeForecaster.php` | per-garage outcome forecast for the assign step | `EXISTS` self-join, 90d, no horizon | `RecurrenceRepository::byGarage()` | **DEFERRED to Phase 2** (your §8). Quarantined + allowlisted + documented |
| **C4** | `app/Services/Garage/ForecastCalibration.php` | checks the forecaster's claims against outcomes | `EXISTS` self-join, 90d, `CURDATE()−90d` | `RecurrenceRepository::byGarage()` | **DEFERRED to Phase 2** — must move with C3 or calibration measures a different thing than it calibrates |
| **C5** | `app/Services/RepairIntelligence/Query/ProjectionRepairHistoryQuery.php` | per-signature repair history answers | `EXISTS` self-join, 90d param, no horizon | `RecurrenceRepository::bySignature()` | **REFACTOR** — recurrence subquery replaced; projection/history logic untouched |
| **C6** | `app/Intelligence/Support/RecurrencePairBuilder.php` | builds the canonical dataset | dedupe + LEAD, no horizon | **becomes canonical**, gains `days_observed` | **KEEP + EXTEND** |

**Files removed: none.** Every change is the replacement of a query *inside* a class that keeps all
its other responsibilities.

## 4.1 Preserved logic from the Scorecard — the four you named, assessed

| Concept | Where it lives now | Statistically sound? | Disposition |
|---|---|---|---|
| **Observation horizon** | `GarageScorecardService::cells()` — `MAX(occurred_at) − window` | **Yes.** Right-censoring correction: a repair younger than the window cannot have recurred, so counting it as "held" biases every rate downward and biases busy garages most | **Promote to the canonical pipeline** as `days_observed` on the table + `horizonMode` in the contract. Becomes the platform default |
| **Case-mix adjustment** | `GarageScorecardService::scorecard()` — `expected = Σ(n_d × p_d)` | **Yes, with a documented limit.** This is indirect standardisation (SMR-equivalent). Rigorous for garage-vs-fleet; only approximate for garage-vs-garage when mixes differ materially | **Keep in `GarageScorecardService`** (it is domain logic, not measurement). Docblock states the limit; comparison page shows each garage's mix |
| **Exposure exclusion** | derived from `RepairSignatureClassifier::EXPOSURE_SIGNATURES`, not declared | **Yes.** Damage recurs from customer behaviour, not workshop quality. Deriving rather than declaring means adding an exposure signature cannot silently start grading it | **Promote to the contract** — `excludeExposure: true` is the canonical default, applied at rebuild time |
| **Domain weighting** | `GarageScorecardService::scorecard()` — `\|Δ\| × min(1, √n / √(5·min_n))` orders strengths/problems | **Directionally sound, informally specified.** It is a crude empirical-Bayes shrinkage: it damps small-sample extremes so "0 comebacks in 21 jobs" does not outrank "30 points better over 300". Not a formal shrinkage estimator | **Keep as-is, document as a heuristic.** Flag as a candidate for a proper hierarchical shrinkage estimator in a later PR — but do not change it during a convergence, or we cannot tell which change moved the numbers |

**Principle applied:** measurement moves to the repository; **judgement stays with the domain
service.** The horizon and exposure rule are measurement, so they become canonical. Case-mix and
weighting are judgement about garages, so they stay in `GarageScorecardService` — one implementation,
richer, not two competing ones.

---

# 5. File-by-File Refactoring Plan

### New — 7

| File | Purpose |
|---|---|
| `config/metrics/recurrence.php` | **The KPI contract** (§6). Version, effective date, every rule |
| `app/Intelligence/Recurrence/RecurrenceRepository.php` | the only reader of the canonical table |
| `app/Intelligence/Recurrence/RecurrenceWindow.php` | window + horizon + exposure, from the contract |
| `app/Intelligence/Recurrence/RecurrenceStats.php` | immutable measurement value object |
| `app/Intelligence/Recurrence/Metrics/{Fleet,Garage,Signature}…Resolver.php` | 6 registry resolvers |
| `database/migrations/..._add_days_observed_to_fault_recurrence_pairs.php` | right-censoring column |
| `app/Providers/…` registry binding | register resolvers at boot |

### Refactored — 8

| File | Change | Preserved |
|---|---|---|
| `app/Kpi/OperationalKpiService.php` | `recurrenceStats()` → repository | 13 other KPIs, HISTORICAL policy, `MIN_SAMPLE` |
| `app/Services/Garage/GarageScorecardService.php` | recurrence pass → repository | case-mix, weighting, grading, ranks, leaderboards, floors, provenance, `ratioScore`, `composite` |
| `app/Services/RepairIntelligence/Query/ProjectionRepairHistoryQuery.php` | recurrence subquery → repository | projection logic, `HistoricalAnswer` shape |
| `app/Console/Commands/IntelligenceRebuildRecurrence.php` | populate `days_observed` | dedupe, staging→swap, validation |
| `app/Console/Commands/KpiSnapshot.php` | write a new baseline row with `reason` | append-only history |
| `config/garage_scorecard.php` | window/horizon → contract; comment corrected to real n | floors, weights, material_pts |
| `config/garage_recommendation.php` | window → contract (C3 reads it until Phase 2) | everything else |
| `tests/Unit/GarageScorecardServiceTest.php` | add corpus tests | every `ratioScore`/`composite` test kept |

### Quarantined — 2 (Phase 2)

`GarageOutcomeForecaster.php`, `ForecastCalibration.php` — unchanged, allowlisted in the guardrail
test, docblock amended to name the deferral and link this document.

### Removed — 0

---

# 6. Metric Governance — the KPI Contract

`config/metrics/recurrence.php` is the contract. Every governed KPI declares:

```
recurrence.comeback_rate
├── version              2.0.0
├── effective_date       2026-08-04
├── supersedes           1.0.0  (raw-signature, no horizon — see §8)
├── business_definition  "Of the repairs a garage completed and that have had a full
│                         observation window to fail in, the share where the same fault
│                         was recorded again on the same vehicle inside that window."
├── canonical_dataset    fault_recurrence_pairs
├── grain                (vehicle_id, signature, occurred_at)   one row = one real fault event
├── deduplication        one row per grain; MIN(maintenance_id) supplies the vendor;
│                        source_row_count records how many label rows collapsed
├── window_days          90            (quality: did the repair hold)
├── observation_horizon  corpus_max    occurred_at <= MAX(occurred_at) − window_days
│                        rationale: right-censoring. A repair too recent to have failed
│                        must not be counted as one that held.
├── required_filters     is_exposure = 0   (applied at rebuild)
│                        vehicle_id IS NOT NULL, occurred_at IS NOT NULL
├── ticket_scope         HISTORICAL — soft-deleted tickets INCLUDED
├── numerator            COUNT(returned_90)
├── denominator          COUNT(*) over fully-observed events
├── min_sample           fleet 30 · garage 30 · garage×domain 20 · duration 10
├── coverage_rule        covered = fully-observed events; total = all events.
│                        Every Kpi carries Coverage; <90% ⇒ confidence=partial
├── as_of                MAX(occurred_at) of the canonical dataset
├── owner                Fleet Intelligence
└── sql_definition       (the exact statement, inline in the config, kept in step
                          with RecurrenceRepository by a test)
```

**Governance rules**
1. A change to any contract field requires a **version bump** and an entry in the supersession log.
2. `RecurrenceRepository` reads the contract; it never hardcodes a window or a floor.
3. A test asserts the contract's `sql_definition` and the repository produce identical numbers, so
   the documentation cannot drift from the implementation.
4. `KpiSnapshot` stamps the contract version on every row, so a historical baseline always says
   which definition produced it.

---

# 7. Commit Sequence

Eight commits. Every one ships green; none leaves two live definitions of a converged surface.

| # | Commit | Content | Gate |
|---|---|---|---|
| 1 | `feat(metrics): the recurrence contract` | `config/metrics/recurrence.php`, `RecurrenceWindow`, `RecurrenceStats` | contract↔spec test |
| 2 | `feat(recurrence): right-censoring` | `days_observed` migration + rebuild update + G24 | golden green; rebuild idempotent |
| 3 | `feat(recurrence): the single reader` | `RecurrenceRepository` + **parity tests** reproducing C1–C5's current outputs | proves convergence loses no capability |
| 4 | `refactor(kpi): fleet baseline reads canonical` | C1 repointed; 6 registry resolvers; re-snapshot with reason | baseline moves once, documented |
| 5 | `refactor(garages): scorecard reads canonical` | C2 repointed; floors re-tuned; case-mix/weighting preserved | `/garages` == baseline |
| 6 | `refactor(repair-intel): history reads canonical` | C5 repointed | Repair Intelligence == baseline |
| 7 | `test(recurrence): single-source guardrails` | `RecurrenceSingleSourceTest` + static guard + allowlist-of-two | CI fails on a 7th implementation |
| 8 | `docs(metrics): governance + baseline change` | this doc, memory updates, `Cost-Source-of-Truth`-style entry | — |

PR #2 Garage Intelligence resumes only after 8.

---

# 8. Rollback Strategy

Rollback is cheap by construction: **no source data is mutated at any point.**

| Layer | Rollback | Data loss |
|---|---|---|
| `days_observed` column | `migrate:rollback` — `dropColumn` | none; recomputed from source |
| `fault_recurrence_pairs` | drop + `intelligence:rebuild-recurrence` | none; pure function of source |
| Repository / resolvers | `git revert` commits 3–6 | none |
| KPI baseline | old `kpi_snapshots` row is **retained**, never overwritten | none |
| Config contract | `git revert` commit 1 | none |

**Per-commit rollback safety**

- Commits 1–3 are additive; reverting them cannot change a displayed number.
- Commits 4–6 each repoint one consumer. Each is independently revertible: reverting commit 5 returns
  `/garages` to raw-signature numbers **while leaving commit 4 in place**, which would reintroduce a
  divergence — so a revert of 4, 5 or 6 in isolation must be paired with re-running
  `RecurrenceSingleSourceTest`, which will fail and make the divergence visible immediately.
- **Emergency full rollback:** revert commits 4–6 together. All surfaces return to the legacy
  definition, consistently. Then `php artisan kpi:snapshot --reason="rollback"`.

**Kill switch, deliberately NOT provided.** A config flag toggling between old and new definitions
would mean the platform can be in a state where two surfaces disagree depending on deploy timing.
That is the exact failure being fixed. Rollback is `git revert`, not a runtime switch.

---

# 9. Expected KPI Changes

| KPI | Before | After | Cause |
|---|---:|---:|---|
| Fleet comeback rate | 40.62% | **≈46.5%** | dedupe removes downward dilution; horizon removes right-censoring |
| Fleet first-time-fix | 59.6% | **≈53.5%** | complement of the above |
| Fleet n (recurrence) | 33,026 | **10,595** | label rows → real events, fully observed only |
| Garages with a score | 63 | **36** | `min_n=30` now means 30 real repairs |
| Per-garage n | — | ÷1.33 to ÷6.93 | uneven duplication |
| Garage × domain graded cells | 296 | fewer | same floor, honest n |
| Turnaround days | 2.7 | **unchanged** | not a recurrence metric |

**Frozen baseline.** `kpi_snapshots` currently holds one row (first-time fix 59.6%, comeback 40.4%,
turnaround 2.7d). It is **kept**. A new row is written stamped `contract_version = 2.0.0` and
`reason = 'recurrence deduplication + right-censoring — docs/Recurrence-Convergence-Implementation-Plan.md'`.
Memory `operational-kpi-baseline` updated in commit 8.

---

# 10. User-Visible Changes

| # | Surface | Change | Announced how |
|---|---|---|---|
| 1 | Executive / Intelligence Center | comeback 40% → 46%, first-time-fix 60% → 54% | banner on the KPI panel for 30 days + Data Health entry |
| 2 | `/garages` | 27 garages lose their score → "not enough repairs yet — 18 of 30" | inline on each unscored card |
| 3 | `/garages` | rates shift; some strength/problem lists reorder | `ScorecardOrigin` provenance names the change |
| 4 | Garage × Fault matrix | some cells drop below floor → blank | existing "not enough repairs" state |
| 5 | Repair Intelligence | per-signature comeback rates shift | provenance line |
| 6 | Assign step / Garage Finder | **no change** — C3 deferred | — |
| 7 | All converged surfaces | identical numbers for the same garage | the point |

**Items 1 and 2 will read as regressions to anyone who does not know why.** They are corrections, and
both must be announced on the page rather than discovered. Wording is drafted in commit 8, not left
to the implementer.

---

# 11. Risk Assessment

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| R1 | "The numbers got worse" read as a bug by Basem/Adham | **High** | High — trust | Announce on-page (§10); Data Health entry; old baseline retained for comparison |
| R2 | C3 stays legacy → assign step disagrees with `/garages` | **Certain (by decision)** | Medium | Declared in §0; allowlist of exactly two files; tested; Phase 2 proposal committed to |
| R3 | Repository becomes a god-object | Medium | Medium | It measures only; no scoring, no case-mix. Enforced by the absence of those methods |
| R4 | Case-mix comparison misread garage-vs-garage | Medium | Medium | Documented in §4.1; comparison page shows domain mix beside every ratio |
| R5 | 36 scored garages is too few to be useful | Medium | Medium | Floors are in the contract, tunable with a version bump. **Do not tune in the same commit as the convergence** — we would not know which change moved the numbers |
| R6 | Parity tests over-fit and freeze a bug | Low | High | Parity tests assert the repository *can reproduce* legacy outputs, then are marked `@group legacy-parity` and deleted in Phase 2 |
| R7 | Nightly rebuild fails → stale canonical data everywhere | Low | **High** — one table now feeds everything | staging→validate→swap already; add `built_at` age alert to Data Health; every `Kpi` carries `asOf` |
| R8 | Scheduler dead on server → R7 silently | **Medium** | High | Verify scheduler before commit 4; `asOf` visible on every surface |
| R9 | Someone writes a 7th implementation | Medium | High | Static guardrail test, CI-blocking, §12 |

**R7 is new and worth stating plainly:** convergence concentrates risk. Six implementations meant six
things could be wrong independently; one means everything is wrong together if the rebuild fails.
That is the correct trade — a consistent wrong number is detectable, six inconsistent ones are not —
but it makes `built_at` monitoring a release requirement, not a nice-to-have.

---

# 12. Verification Checklist

### Correctness
- [ ] `RecurrenceRepository` reproduces C1's output when given C1's window/horizon *(parity)*
- [ ] …C2's, C3's, C4's, C5's likewise
- [ ] `days_observed` non-null on 100% of rows
- [ ] No pair has `days_to_return < 1`
- [ ] Rebuild idempotent — double run, identical checksum

### Single source of truth
- [ ] **`RecurrenceSingleSourceTest`**: for 5 named garages, `OperationalKpiService` == `GarageScorecardService` == leaderboard API == profile API == legacy route == export. **Exact equality, no tolerance**
- [ ] Fleet rate from `MetricRegistry` == fleet rate from `RecurrenceRepository` == sum of per-garage
- [ ] **Static guard**: no `maintenance_signatures` self-join / `EXISTS`-over-`occurred_at` outside `RecurrenceRepository` + `IntelligenceRebuildRecurrence`
- [ ] **Allowlist test**: exactly two quarantined files (C3, C4), named, no more
- [ ] **Contract test**: `config/metrics/recurrence.php` `sql_definition` == repository output

### Governance
- [ ] Contract has version, effective date, supersedes, owner
- [ ] `kpi_snapshots` rows stamped with contract version
- [ ] Old baseline row retained
- [ ] Memory `operational-kpi-baseline` updated

### Golden
- [ ] G21 fleet comeback 46.51% ±0.1, n=10,595
- [ ] G22 scored garages = 36
- [ ] G23 zero raw-recurrence implementations outside the allowlist
- [ ] G24 `days_observed` complete
- [ ] G01–G20 still green

### Non-regression
- [ ] 528 unit/feature tests green
- [ ] Every `GarageScorecardServiceTest` `ratioScore`/`composite` test green **unchanged**
- [ ] `/garages` renders; every existing component works on the unchanged payload contract
- [ ] Assign step / Garage Finder behaviour **unchanged** (C3 deferred)

### Release
- [ ] Scheduler verified firing on the server
- [ ] Data Health shows `built_at` + staleness alert
- [ ] On-page announcement copy approved for Executive and `/garages`

---

## Decisions locked from your §8

✅ `min_n.garage = 30` · ✅ re-baseline on corrected metrics · ✅ retain historical snapshots with
documented reason · ✅ 14-day rename deferred to its own PR · ✅ C3/C4 deferred to Phase 2 with a
separate impact proposal before any routing change.

## The one thing I need you to confirm

**§0 — the two-phase split.** Your §5 lists the recommendation engine among the surfaces that must
agree; your §8 defers it. I have planned for *declared, tested, temporary* divergence in exactly two
files. If you would rather have full equality in one pass, say so and I will fold C3/C4 into this
plan — but that changes assign-step recommendations inside a convergence PR, which is precisely what
your §8 was protecting against.
