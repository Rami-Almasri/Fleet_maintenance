# Recurrence Convergence — Definition of Done

**Completed:** 2026-08-04 · **Contract:** `recurrence` v2.0.0 · **Owner:** Fleet Intelligence
**Status:** Phase 1 COMPLETE. Phase 2 (routing) declared and scheduled.

> Read this before touching anything that answers *"did the same fault come back?"*

---

## 1. What changed

The platform carried **six implementations** of one business question. Measured together on the live
corpus they returned **40.62%, 40.96% and 46.51%** for the same question, over sample sizes from
**10,595 to 33,026** — and one of those implementations routes cars to garages.

They are now one definition, one dataset, one reader.

### The two structural faults corrected

**Deduplication (+5.22 points).** `maintenance_signatures` holds 2–8 rows for one fault on one car
on one day — a `derived` label and a `human` one, several matched terms, several tickets sharing a
date. Every legacy implementation counted **labels, not repairs**. And the distortion ran one way: an
event labelled eight times that did *not* recur contributed eight "held" rows, so duplication had
been **diluting every rate downward**. 33,026 raw rows are 12,608 real events.

**Right-censoring (+0.67 points).** A repair completed last week cannot have come back within 90 days
yet. Counting it as one that *held* is a free pass that flatters every garage, and flatters the
busiest ones most because they have the most recent work.

### Published figures

| Metric | v1.0.0 | v2.0.0 |
|---|---:|---:|
| Fleet comeback | 40.63% | **46.51%** |
| Fleet first-time-fix | 59.37% | **53.49%** |
| Sample | 33,040 | **10,595** |
| Garages scored (n≥30) | 57 | **33** |

**No repair changed. No garage got worse.** Twenty-four garages did not lose a score they had
earned — they lost one they had never earned. Per-garage inflation ranged **1.3× to 38.75×**.

### The bug that was not a rate

`ProjectionRepairHistoryQuery` counted **episodes** from label rows. One episode reaches 54 rows
(vehicle 1743, ENGINE_MECH, 2025-04-10), so a Repair Intelligence card reported **fifty-four prior
episodes for a fault that happened once** — to the person deciding what to do about the car. The same
fault in `occurrencesBetween()` meant outcome learning could judge one comeback as many, teaching the
recommendation engine from a number nobody measured.

---

## 2. Legacy implementations retired

| # | Implementation | Was | Now |
|---|---|---|---|
| **C1** | `Kpi\OperationalKpiService::recurrenceStats()` | `EXISTS` self-join, no horizon | `RecurrenceRepository::fleet()` |
| **C2** | `Garage\GarageScorecardService::cells()` | correlated subquery, corpus-max horizon | `byGarageAndDomain()` + `medianGapsBy*()` |
| **C5** | `ProjectionRepairHistoryQuery` ×3 methods | `EXISTS` self-join + label-level episode reads | `bySignature()`, `episodesBefore()`, `episodesBetween()` |

**No file was deleted.** Each change replaced a query *inside* a class that kept every other
responsibility — which is why all ten pre-existing `GarageScorecardServiceTest` cases pass
unmodified.

---

## 3. Intentionally quarantined

| # | File | Why | Exit |
|---|---|---|---|
| **C3** | `Garage\GarageOutcomeForecaster` | Feeds the assign step and Garage Finder. Repointing changes **where cars are physically sent** | Phase 2, after a routing-impact proposal |
| **C4** | `Garage\ForecastCalibration` | Checks C3's claims. Must move **with** C3, or calibration checks a forecast against a different definition than produced it | Phase 2, with C3 |

This is a **declared, tested, temporary** exemption — not a carve-out. `RecurrenceArchitectureGuardTest`
asserts the quarantine contains **exactly two** files. A third fails CI. When Phase 2 lands, the count
drops to zero and the test asserts that.

### Not quarantined — legitimately exempt

`ProjectionRepairHistoryQuery::vehicleHistory()` reads raw signatures **by design**: it returns a
car's full timeline *including exposure damage*, which the canonical dataset excludes by contract,
because chronic-vehicle and repair-vs-replace questions need accident history. It is a **retrieval,
not a measurement** — it computes no rate.

> **The distinction that keeps the guardrails usable: retrieving what was recorded is fine; deriving
> a rate from it is not.** A guard that flagged legitimate retrieval would teach people it cries
> wolf, and a guard nobody reads is worse than none.

---

## 4. Evidence that convergence happened

| Evidence | Where |
|---|---|
| **Audit — 14 surfaces canonical, 3 exempt, 0 legacy** | `php artisan intelligence:convergence-audit` · `docs/evidence/convergence-audit-2026-08-04.json` |
| Legacy-vs-canonical decomposition, per garage | `docs/evidence/recurrence-comparison-2026-08-04.json` |
| Post-repoint drift check (byte-identical) | `docs/evidence/recurrence-comparison-post-C1-2026-08-04.json` |
| Scorecard before/after, per garage | `docs/evidence/scorecard-{before,after}-C2.json`, `scorecard-C2-diff.json` |
| Per-garage exact equality | `GarageScorecardConvergenceTest` — 1,999 assertions |
| Fleet baseline exact equality | `OperationalKpiConvergenceTest` — no tolerance |
| Per-signature exact equality + episode dedup | `RepairIntelligenceConvergenceTest` |
| Contract ↔ code cannot drift | `RecurrenceContractTest` — 21 tests |
| Golden regression | `GoldenNumbersTest` G21–G24 |
| **Guard proven to bite** | A planted 7th implementation was caught by all four patterns |

**Totals: 63 golden tests (2,314 assertions), 559 unit/feature.**

The audit **exercises** each surface rather than inspecting source, because the failure being guarded
against is a surface that *looks* converged and answers differently — a stale cache, a second
rounding, a scope narrowed by a join.

---

## 5. The scope difference — both numbers are correct

`/garages` publishes **45.2% over n=9,960**. The Executive dashboard publishes **46.51% over
n=10,595**.

Same definition, same dataset, same window. **Different population:** 635 repairs name no garage, and
a garage cannot be compared against an average that includes work no garage did.

- Both figures are published together on `/garages`, in EN and AR
- A test asserts the gap **equals the unattributed count exactly**
- The audit reports it as a first-class row, not a footnote

A one-point disagreement between two dashboards is precisely what makes someone stop trusting both —
and they will notice it long before they find the document explaining it.

---

## 6. What future developers must never reintroduce

### Never compute recurrence from `maintenance_signatures`

It is the **capture layer**: it records what was *seen*, several rows per event, by design.
`fault_recurrence_pairs` records what *happened*, one row per event. Confusing the two produced six
definitions.

```
❌  SELECT ... FROM maintenance_signatures a
    WHERE EXISTS (SELECT 1 FROM maintenance_signatures b
                  WHERE b.occurred_at > a.occurred_at ...)

✅  $repository->fleet($window)         byGarage()  byGarageAndDomain()
                                        bySignature()  byVehicle()
                                        episodesBefore()  episodesBetween()
```

**If no method fits your grain, add one to the repository.** Adding your file to the allowlist is
not the fix.

### Never query the canonical table directly

Bypassing `RecurrenceRepository` skips the window, the horizon and the coverage reporting —
producing a number that looks canonical and is not.

### Never cache a recurrence answer without the metric version

A deploy would keep serving answers from the retired definition for exactly one TTL. That is the
hardest divergence to diagnose, because it repairs itself before anyone investigates.

### Never lower a sample floor to restore a count

`min_n.garage = 30` means **30 real repairs**. Before deduplication it enforced about six. Lowering
it to bring 57 garages back would re-import the bug as a setting.

### Never put judgement in the measurement layer

The repository answers *"what happened?"*. The domain services answer *"what does it mean?"*.
Case-mix and domain weighting are governed in the contract but **applied in
`GarageScorecardService`**, because Fault Intelligence and Vehicle Intelligence also read recurrence
and neither wants a garage's work-mix expectation folded in.

### Never re-baseline silently

Every `kpi_snapshots` row carries `metric_version`, `reason` and `supersedes_snapshot_id`. The
pre-correction baseline is **kept, never rewritten** — a baseline you delete is one you cannot argue
from.

> A migration writing that history learned this the hard way: `captured_at` carries
> `ON UPDATE CURRENT_TIMESTAMP`, so a backfill that did not name the column rewrote the very capture
> time it existed to protect. Caught, restored from `created_at`, and the reason is in the migration
> where the next person will hit it.

---

## 7. Definition of done

- [x] One canonical dataset — `fault_recurrence_pairs`
- [x] One reader — `RecurrenceRepository`
- [x] One contract — `config/metrics/recurrence.php`, versioned, append-only change history
- [x] Reporting surfaces return identical values, asserted **per entity**
- [x] Repair Intelligence converged (C5)
- [x] **CI fails on any new recurrence implementation** — proven by planting one
- [x] Version-aware caching enforced by test
- [x] Scope difference documented in the UI, EN + AR
- [ ] Recommendation engine converged (C3/C4) — **Phase 2**
- [ ] Allowlist empty — **Phase 2**

**Phase 1: 8 of 8. Overall: 8 of 10.**

---

## 8. Next

1. **Phase 2** — routing-impact proposal for C3/C4, then repoint, then empty the allowlist.
2. **Separate PR** — rename the 14-day `comeback` alert to `rapid_repeat_visit`, so "comeback" means
   exactly one thing platform-wide.
3. **Separate PR** — `outcomeCompleteness()` reports 200%: it divides `OutcomeClaimed` events by
   `VehicleReleased` events and one release can claim several outcomes. Surfaced during C1, left
   alone deliberately so it could not muddy the before/after.
4. **Watch** — `intelligence_rebuild_runs` staleness. Convergence concentrates risk: six
   implementations meant six things could break independently; one means every recurrence figure is
   wrong together if the nightly rebuild stops. That is the correct trade — a consistently wrong
   number is detectable, six inconsistent ones are not — but it makes `built_at` monitoring a
   production requirement, and the scheduler is still unverified on the server.
