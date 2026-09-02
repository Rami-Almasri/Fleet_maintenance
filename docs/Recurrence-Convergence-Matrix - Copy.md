# Recurrence Convergence Matrix

**Updated:** 2026-08-04 (Phase 1 COMPLETE) · **Contract:** `recurrence` v2.0.0 · **Owner:** Fleet Intelligence
**Live status:** 3 of 5 consumers canonical · 0 pending · 2 quarantined for Phase 2

> One dashboard for progress toward one governed definition. Update this file in the same commit as
> any consumer migration — a matrix that lags the code is worse than none.

---

## 1. Status at a glance

```
CANONICAL   █████████░░░░░░  3 / 5    C1 OperationalKpiService · C2 GarageScorecardService · C5 ProjectionRepairHistoryQuery
PENDING     ░░░░░░░░░░░░░░░  0 / 5    —
QUARANTINED ░░░░░░░░░██░░░░  2 / 5    C3 GarageOutcomeForecaster · C4 ForecastCalibration
```

**Reporting surfaces are converged. Routing is not, by decision.**

---

## 2. The matrix

| # | Consumer | Owns | Legacy calculation | Canonical replacement | Status | Migrated | Legacy retires |
|---|---|---|---|---|---|---|---|
| **C1** | `Kpi\OperationalKpiService` | Fleet baseline: comeback, first-time-fix | `EXISTS` self-join, 90d, **no horizon** | `RecurrenceRepository::fleet()` | ✅ **CANONICAL** | `f2f1ffb` 2026-08-04 | done |
| **C2** | `Services\Garage\GarageScorecardService` | `/garages`, scorecards, matrix, leaderboards | correlated subquery, 90d, corpus-max horizon | `byGarageAndDomain()` + `medianGapsBy*()` | ✅ **CANONICAL** | `02deb23` 2026-08-04 | done |
| **C5** | `RepairIntelligence\Query\ProjectionRepairHistoryQuery` | Per-signature rates, episode lookups, comeback detection | `EXISTS` self-join + **label-level episode reads** | `bySignature()` + `episodesBefore()` + `episodesBetween()` | ✅ **CANONICAL** | `C5` 2026-08-04 | done |
| **C3** | `Services\Garage\GarageOutcomeForecaster` | Per-garage forecast → **assign step, Garage Finder** | `EXISTS` self-join, 90d, no horizon | `RecurrenceRepository::byGarage()` | 🔴 **QUARANTINED** | Phase 2 | after routing-impact proposal |
| **C4** | `Services\Garage\ForecastCalibration` | Checks C3's claims against outcomes | `EXISTS` self-join, 90d, `CURDATE()−90d` | `RecurrenceRepository::byGarage()` | 🔴 **QUARANTINED** | Phase 2 | must move **with** C3 |
| **C6** | `Intelligence\Support\RecurrencePairBuilder` | Builds the canonical dataset | — | *is* the canonical builder | ✅ **CANONICAL** | `eabad15`–`9a62d0b` | n/a |

### Why C3/C4 are quarantined rather than migrated

A deliberate decision, not an oversight. C3 feeds the assign step and Garage Finder — it changes
**where cars are physically sent**. Repointing it moves recommendations, and that must be assessed
on its own evidence rather than inside a convergence PR. C4 must move with C3 or calibration would
be checking a forecast against a different definition than the one that produced it.

The guardrail test carries an **allowlist of exactly these two files**. A third entry fails CI. When
Phase 2 lands, the allowlist must be empty and the test asserts that.

---

## 3. Surfaces verified identical

Every surface below reads the same definition and returns the same number for the same garage,
asserted per garage rather than in aggregate (`GarageScorecardConvergenceTest`, 1,999 assertions).

| Surface | Reads | Verified |
|---|---|---|
| Executive / Intelligence Center | C1 | ✅ `OperationalKpiConvergenceTest` — exact equality, no tolerance |
| `/garages` page | C2 | ✅ per-garage exact equality |
| Garage scorecards API (`GET Maintenance/garage-scorecards`) | C2 | ✅ same service, same payload |
| Garage × Fault matrix | C2 | ✅ same cells |
| Domain leaderboards | C2 | ✅ same cells |
| `kpi:snapshot` export | C1 | ✅ version-stamped |
| Repair Intelligence answers | C5 | ✅ per-signature exact equality; episodes deduplicated |
| Assign step / Garage Finder | **C3** | 🔴 **legacy, declared** |

### The one intentional numeric difference

`/garages` publishes **45.2% over n=9,960**; the Executive dashboard publishes **46.51% over
n=10,595**. Same definition, same dataset, same window — **narrower population**: 635 events carry
no garage, and a garage cannot be compared against an average including repairs no garage did.

Both figures now appear together on the page with the gap labelled, and a test asserts the
difference equals the unattributed count exactly. It reads as scope, not contradiction.

---

## 4. What moved, and why

| KPI | Before (v1.0.0) | After (v2.0.0) | Cause |
|---|---:|---:|---|
| Fleet comeback | 40.63% | **46.51%** | dedup +5.22, censoring +0.67 |
| Fleet first-time-fix | 59.37% | **53.49%** | complement |
| Fleet sample | 33,040 | **10,595** | label rows → repair events, fully observed only |
| Scored garages | 57 | **33** | floor now means 30 real repairs |
| Garage scores | — | **±2–5 points** | case-mix absorbed the absolute shift |

**No repair changed. No garage got worse.** Per-garage inflation ranged 1.3× to 38.75×, so there is
no uniform shift to reason about — every garage moved differently, and each is decomposed in
`docs/evidence/scorecard-C2-diff.json`.

---

## 5. Guardrails

| Guard | Status |
|---|---|
| Contract test — config ↔ code cannot drift | ✅ 21 tests |
| Per-garage exact equality across surfaces | ✅ 1,999 assertions |
| Source-level: no raw-signature recurrence in C1 | ✅ |
| Source-level: no raw-signature recurrence in C2 | ✅ |
| **Static CI guard — no 7th implementation anywhere** | ✅ proven by planting one |
| **Allowlist test — exactly 2 quarantined files** | ✅ |
| Version-aware cache enforcement | ✅ |
| Repository-only access to the canonical table | ✅ |
| End-to-end convergence audit | ✅ 14 canonical · 3 exempt · 0 legacy |
| Golden regression — canonical baseline | ✅ G21–G24 |
| Rebuild health / staleness | ✅ `intelligence_rebuild_runs` |

---

## 6. Remaining work

| Step | Deliverable | Blocks |
|---|---|---|
| **Phase 2** | Routing-impact proposal → repoint C3/C4 → empty the allowlist | One definition everywhere, including routing |
| *Separate PR* | Rename the 14-day `comeback` alert to `rapid_repeat_visit` | "Comeback" meaning exactly one thing |
| *Separate PR* | Fix `outcomeCompleteness()` reporting 200% | Unrelated defect surfaced during C1 |

---

## 7. Definition of done

Convergence is complete when **all** hold:

- [x] One canonical dataset — `fault_recurrence_pairs`
- [x] One reader — `RecurrenceRepository`
- [x] One contract — `config/metrics/recurrence.php`, versioned, with change history
- [x] Reporting surfaces return identical values, asserted per entity
- [x] Repair Intelligence converged (C5)
- [x] CI fails on any new recurrence implementation
- [ ] Recommendation engine converged (C3/C4, Phase 2)
- [ ] Allowlist empty

**Phase 1 complete — 6 of 8.** The two remaining are C3/C4, deferred by decision.

See `docs/Recurrence-Convergence-Definition-of-Done.md` for the final acceptance report.
