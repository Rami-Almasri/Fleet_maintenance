# Evidence Artifact Contracts

**Date:** 2026-08-01 · **Status:** architectural contract, not documentation.
**Companion to:** `Implementation-Backlog.md` (the execution blueprint) — items there cite these IDs.

Each artifact below is a **first-class architectural object** with a named owner and an acceptance
test. An artifact without a passing acceptance test is **not evidence** — it is a table.

---

## How this registry is enforced

Three mechanisms, in increasing order of teeth:

1. **Proposal gate** — no feature proceeds to implementation without declaring the artifacts it
   consumes and produces. Template in `Implementation-Backlog.md` Part I §5.
2. **`evidence:health`** — a command mirroring the existing `schema:health` exactly:
   `EvidenceHealthService::checks() → summary() → verdict()`, `ok`/`warn`/`fail` weighted
   `1.0 / 0.5 / 0.0`, one category per artifact class. **Build it by copying `SchemaHealthService`,
   not by inventing a second health mechanism.** Add to the `composer verify` script alongside
   `schema:health`.
3. **Acceptance tests** — the `Acceptance` row of each contract, expressed as an assertion
   `evidence:health` can run. These are the definition of "this artifact is valid".

### Contract field meanings

| Field | Means |
|---|---|
| **Owner** | The **one** class permitted to write it. A second writer is a defect, not a feature. |
| **Rebuild** | Can it be regenerated from other evidence if lost? |
| **Delete** | May it be deleted? `F` artifacts: never. |
| **Refresh** | When it updates, and what invalidates it. |
| **Coverage** | Measured 2026-08-01, demo rows excluded. |
| **Quality** | 🟢 trustworthy · 🟡 usable with caveats · 🔴 not fit for use |
| **Acceptance** | The assertion that proves validity. Fails ⇒ downstream consumers must degrade, not guess. |

---

# Class F — Facts *(irreplaceable; delete = permanent loss)*

## E1 · Workshop events
| | |
|---|---|
| **Class / Store** | **F** · `vehicle_log_events` |
| **Owner** | `App\Services\VehicleLogService` — **sole writer** |
| **Producers** | A-1, A-6 · `MaintenanceWorkflowService` transitions |
| **Consumers** | A-7, A-9, A-12, C-1, C-4 |
| **Rebuild** | ❌ **No.** A lost transition is unobservable after the fact. |
| **Delete** | ❌ **Never.** Tombstone only. |
| **Refresh** | Append-only on every `workflow_status` change. |
| **Coverage** | 1,979 rows · **only 397 attached to a surviving non-seed ticket** |
| **Quality** | 🔴 **78.8% orphaned** (1,559 rows, `maintenance_id` NULL) |
| **Acceptance** | ① orphan rate `< 1%` and **non-increasing** week-over-week; ② every `workflow_status` transition has ≥1 event within the same transaction; ③ `VehicleLogService` write failures raise, never swallow; ④ no hard-delete path reachable from any controller. |

> **Currently fails ①–④.** This is the single most important contract in the registry — it is the only
> artifact actively losing data, and A-1 exists to stop it.

## E2 · Ticket lifecycle & stage timestamps
| | |
|---|---|
| **Class / Store** | **F** · `maintenances` |
| **Owner** | `App\Services\MaintenanceWorkflowService` |
| **Producers** | A-6, A-18 |
| **Consumers** | A-8, A-9, A-12, B-10, C-1 |
| **Rebuild** | ❌ No |
| **Delete** | ❌ Never (tombstone; `maintenance_tombstones` exists and holds 0 rows) |
| **Refresh** | On each transition; `last_state_change_at` is the canonical clock |
| **Coverage** | 149 of 26,839 carry a `workflow_status` · 13 have `repair_started_at` · **8 have both `repair_started_at` and `returned_at`** |
| **Quality** | 🟡 correct but **very thin** — this is the number that gates Phase 3 |
| **Acceptance** | ① every non-terminal ticket has `last_state_change_at`; ② no ticket has `returned_at < repair_started_at`; ③ the count of stage-complete tickets is **published**, not inferred; ④ `is_demo = false` for every ticket in any KPI population. |

## E5 · Component history
| | |
|---|---|
| **Class / Store** | **F** · `vehicle_components`, `component_events` |
| **Owner** | `App\Services\ComponentService` — **sole writer**; `Services\Components\ComponentReadModel` **sole reader** that derives |
| **Producers** | **B-10 only** |
| **Consumers** | C-6, C-8 |
| **Rebuild** | ❌ No (a fitted part is not re-derivable from free text) |
| **Delete** | ❌ Never |
| **Refresh** | On install/replace via workflow or purchase |
| **Coverage** | **0 / 0** |
| **Quality** | 🔴 empty — `ASSET_LAYER_MODE = 'shadow'` |
| **Acceptance** | ① every row carries `evidence_channel ∈ {purchase, repair_capture}` and `acquisition`; ② **no `verb='replace'` count exists outside `ComponentService`/`ComponentReadModel`** — a review gate, not a runtime check; ③ warranty windows derive from the **original** install and are never reset. |

> **Single-producer artifact.** B-10 is the only path to E5, making it the highest-leverage unlock in
> the plan. Until it ships, C-6 must render "no component history yet" — the *normal* case, not an error.

## E6 · Odometer chain
| | |
|---|---|
| **Class / Store** | **F** · `vehicles.odometer` + ticket readings |
| **Owner** | ⚠️ **NO SINGLE OWNER — 7 known writers.** Should be `App\Services\OdometerContinuityService` |
| **Producers** | A-4 |
| **Consumers** | C-5, C-7, mileage baseline, service-due |
| **Rebuild** | ⚠️ Partially — from the reading chain, if the chain is intact |
| **Delete** | ❌ Never |
| **Refresh** | On every reading capture |
| **Coverage** | 439 vehicles · **3 with physically impossible values** |
| **Quality** | 🔴 62,769 → 6,276,888 km was accepted and propagated |
| **Acceptance** | ① no vehicle exceeds a configured absolute ceiling; ② no reading exceeds max-delta-since-last without an approval record; ③ no rollback without an approved `odometer_change_request`; ④ **exactly one writer** (currently 7 — a standing violation). |

## E7 · Repair outcomes & QC verdicts
| | |
|---|---|
| **Class / Store** | **F** · `repair_inspections`, task outcomes |
| **Owner** | `App\Services\MaintenanceTaskService`, `RepairCaptureService` |
| **Producers** | existing workflow |
| **Consumers** | A-11, C-3, C-4 |
| **Rebuild** | ❌ No |
| **Delete** | ❌ Never |
| **Refresh** | On QC verdict / re-inspection |
| **Coverage** | 9 rows (**7 real**) |
| **Quality** | 🟡 real but tiny |
| **Acceptance** | ① every verdict names its inspector and timestamp; ② `unverifiable` is never counted as a returned repair; ③ verdict vocabulary matches the shared validator. |

## E8 · Spend records
| | |
|---|---|
| **Class / Store** | **F** *(imported)* · `vehicle_expenses` |
| **Owner** | `App\Contracts\VehicleExpenseProvider` → `Services\Expenses\ExcelVehicleExpenseProvider` |
| **Producers** | Excel import only — **no API write path exists** |
| **Consumers** | C-7 |
| **Rebuild** | ✅ Re-importable from source workbook |
| **Delete** | ⚠️ Only by re-import |
| **Refresh** | Batch import; **collapsed ~99% after Mar 2026 — figures are historical** |
| **Coverage** | 28,327 rows · **<2% of repairs carry a cost** |
| **Quality** | 🟡 accurate where present, badly incomplete, and **stale** |
| **Acceptance** | ① every read goes through `VehicleExpenseProvider` — **no direct `vehicle_expenses` query outside it**; ② freshness (max `entry_date`) published with every cost figure; ③ coverage ratio published alongside any total. |

## E17 · Comeback links
| | |
|---|---|
| **Class / Store** | **F** · `comeback_of_maintenance_id` *(to build)* |
| **Owner** | `App\Services\RecurringFaultService` |
| **Producers** | A-13 |
| **Consumers** | C-3 |
| **Rebuild** | ❌ No (an inferred link is not the same fact) |
| **Delete** | ❌ Never |
| **Refresh** | On confirmed recurrence |
| **Coverage** | **0 — inferred from signature recurrence only** |
| **Quality** | 🔴 does not exist |
| **Acceptance** | ① a confirmed comeback writes an explicit FK; ② inferred comebacks are graded `ESTIMATED`, never `MEASURED`; ③ one window definition fleet-wide. |

---

# Class D — Derived *(rebuildable; must never write to an F table)*

## E3 · Canonical workshop duration
| | |
|---|---|
| **Class** | **D** · `WorkshopDuration` value object *(to build)*, returned as an extended `Kpi` |
| **Owner** | A-9 — **the single definition** |
| **Producers** | A-9 · **Consumers:** A-10, A-12, C-4, C-5 |
| **Rebuild / Delete** | ✅ Always / ✅ Freely |
| **Refresh** | On read; invalidated by E1/E2 change |
| **Coverage** | 8 tickets support stage-level duration |
| **Quality** | 🔴 **six conflicting definitions today** |
| **Acceptance** | ① exactly one class computes workshop duration — grep proves no second; ② every result carries `sampleSize` + `evidenceSource` + tier; ③ **tier-1 (sheet) inputs return a date range, never a duration**; ④ merged, not summed, across parallel stints; ⑤ `max_duration_days = 120` outlier guard applied. |

## E4 · Fault signatures
| | |
|---|---|
| **Class / Store** | **D** · `maintenance_signatures` |
| **Owner** | `App\Services\Knowledge\RepairSignatureClassifier` |
| **Producers** | A-8 · **Consumers:** A-11, C-3 |
| **Rebuild** | ✅ **By design** — and a rebuild **moves the KPIs** |
| **Delete** | ✅ Safe (rebuildable read-model) |
| **Refresh** | Should be on ticket close (A-8); today batch-only |
| **Coverage** | 49,501 rows over 26,839 tickets |
| **Quality** | 🟡 broad, but **stale on every new ticket** |
| **Acceptance** | ① every row stamps `classifier_version`; ② no metric compares signatures across classifier versions without saying so; ③ a ticket closed today has signatures within one transaction. |

## E9 · Typed concept resolutions
| | |
|---|---|
| **Class** | **D** *(derived from text + E10)* · `MatchPipeline` output |
| **Owner** | `App\Ontology\Matching\MatchPipeline` |
| **Producers** | B-1, B-3 · **Consumers:** B-11, search, complaint interpretation |
| **Rebuild / Delete** | ✅ Always / ✅ Freely (not persisted) |
| **Refresh** | On read |
| **Coverage** | reaches **105 of 1,470** ontology nodes (**7%**) |
| **Quality** | 🔴 repair **actions** resolve to **fault** concepts |
| **Acceptance** | ① every resolution carries a `node_type`; ② `replace X` resolves to a repair/action node, **never** to a fault of X — a fixture suite, including the thermostat cases; ③ semantic stage never outranks a deterministic match; ④ confidence never exceeds the top-ranked candidate's. |

> ④ exists because confidence currently **contradicts** relevance: junk `Engine noise` scores confidence
> 79 against correct `Overheating` at 72.

## E18 · Provenance & confidence envelope
| | |
|---|---|
| **Class** | **D** · `App\Kpi\Kpi` + `ConfidenceChip` / `EvidenceLink` |
| **Owner** | `App\Kpi\Kpi` (backend) · B-5 primitives (frontend) |
| **Producers** | A-7, A-9, A-11, B-5, C-1, C-2 · **Consumers:** every UI surface |
| **Rebuild / Delete** | ✅ / ✅ |
| **Refresh** | On read |
| **Coverage** | partial — `Kpi` exists; `confidence`/`evidenceSource`/`computedAt` not yet on it |
| **Quality** | 🟡 the right shape, incomplete |
| **Acceptance** | ① **no numeric metric reaches the UI outside a `Kpi`**; ② every `Kpi` exposes sample size, confidence, evidence source, freshness; ③ unavailable ⇒ `blockedReason` is non-empty and human-readable; ④ **zero is never returned for "unknown"**. |

> ④ is the failure `Kpi`'s own class doc names: *"a dashboard showing 0% capture-abandonment looks like
> a triumph and is actually an unwired frontend."*

---

# Class R — Reference *(externally authored; population-independent)*

## E10 · Reference ontology
| | |
|---|---|
| **Class / Store** | **R** · `ontology_nodes`, `ontology_edges` |
| **Owner** | `App\Services\KeywordOntologyService` |
| **Producers** | B-2, B-7, B-8, B-9 · **Consumers:** B-1, B-11, C-6 |
| **Rebuild** | ✅ Re-authorable |
| **Delete** | ⚠️ Only with a replacement — retire via `is_active`, never hard-delete |
| **Refresh** | On authoring; **versioned, not time-decayed** |
| **Coverage** | 1,470 nodes (106 fault · 92 repair · 274 procedure · 271 component · 367 cause · 360 symptom); 2,087 edges |
| **Quality** | 🟡 broad and useful, but **100% `source='seed'`**; 93% unreachable by the matcher |
| **Acceptance** | ① every node has a type and a stable key; ② no node claims a citation it does not have — seeded confidence is graded `SEED`; ③ `fleet`-sourced edges are tagged **K**, never R; ④ no concept is 100% ambiguous (currently `Door / panel misalignment` is 12/12). |

> ③ is the R/K boundary made executable: the 82 `source='fleet'` edges are **learned**, not reference,
> and must not inherit reference authority.

## E11 · Reference documents & citations
| | |
|---|---|
| **Class / Store** | **R** · `knowledge_sources`, `knowledge_documents`, `knowledge_chunks`, `evidence_links` |
| **Owner** | B-4 / B-12 ingestion *(to build)* |
| **Producers** | B-4, B-12 · **Consumers:** **none until B-12** |
| **Rebuild** | ✅ Re-ingestable |
| **Delete** | ✅ Safe |
| **Refresh** | On ingestion; versioned by publication |
| **Coverage** | 17 sources · **0 documents · 0 chunks · 0 evidence links** |
| **Quality** | 🔴 **a source registry with nothing behind it** |
| **Acceptance** | ① a source with 0 documents is **never rendered as backing a confidence figure**; ② every citation resolves to a retrievable chunk; ③ trust weight derives from the source tier, not a hardcoded constant. |

> **No downstream consumer until B-12** — which is the rational basis for choosing B-4's *downgrade*
> path (1 w) over *ingest* (4 w+) now. Nothing is blocked by deferring the corpus.

---

# Class K — Knowledge *(fleet-learned; population-gated; freshness mandatory)*

## E12 · Garage performance knowledge
| | |
|---|---|
| **Class / Store** | **K** · `garage_recommendation_decisions` (+ override learning, forecast accuracy) |
| **Owner** | `App\Services\GarageRecommendationService` |
| **Producers** | C-3, C-4, C-5 · **Consumers:** C-8, dispatch decisions |
| **Rebuild** | ⚠️ Only by replaying decision history |
| **Delete** | ⚠️ Loses accumulated learning — treat as F-adjacent for retention |
| **Refresh** | On each decision + outcome; **must expose window and freshness** |
| **Coverage** | **2 decisions** |
| **Quality** | 🔴 far below any decision threshold |
| **Acceptance** | ① every score exposes sample size, window, freshness; ② **comeback reuses `OperationalKpiService` SQL** — one definition fleet-wide; ③ below `minSampleN` the garage is **unranked**, not bottom-ranked; ④ no metric named "comeback rate" is computed from a different population than E4's. |

> ③ matters commercially: a garage with 2 jobs must not appear "worst" because it is unmeasured.

## E13 · Component reliability knowledge
| | |
|---|---|
| **Class** | **K** · *(to build — derived from E5)* |
| **Owner** | C-6, reading `ComponentReadModel` / `ComponentLifecycle` |
| **Producers** | C-6 · **Consumers:** C-8, C-9 |
| **Rebuild** | ✅ From E5 |
| **Delete** | ✅ Safe while E5 survives |
| **Refresh** | On component event; freshness mandatory |
| **Coverage** | **0 — blocked on E5** |
| **Quality** | 🔴 does not exist |
| **Acceptance** | ① never computed from `maintenance_task_actions`; ② `evidence_channel = repair_capture` rows count toward replacement counts but **never** toward cost; ③ service-life thresholds come from `ComponentLifecycle`, not restated. |

## E14 · Cost knowledge
| | |
|---|---|
| **Class** | **K** · *(to build — derived from E8)* |
| **Owner** | C-7, reading `VehicleExpenseProvider` |
| **Producers** | C-7 · **Consumers:** C-8 |
| **Rebuild** | ✅ From E8 |
| **Delete** | ✅ Safe |
| **Refresh** | On expense import; **freshness critical — the ledger is stale after Mar 2026** |
| **Coverage** | <2% of repairs costed |
| **Quality** | 🔴 not fit for totals; usable as "spend we can see" |
| **Acceptance** | ① coverage < 0.5 ⇒ confidence capped `low` **and** labelled *"based on N of M repairs with known cost"*; ② never presented as a total; ③ gated behind `SHOW_FINANCIALS`; ④ ledger freshness date shown. |

---

# Class P — Prediction *(never `MEASURED`; band-only until calibrated)*

## E15 · Recommendations & forecasts
| | |
|---|---|
| **Class / Store** | **P** · `recommendations`, `recommendation_events`, forecast calibration |
| **Owner** | B-11 (advisory) · C-9/C-10 (risk) · `Services\Garage\ForecastCalibration` |
| **Producers** | B-11, C-9, C-10 · **Consumers:** operator surfaces, ticket creation |
| **Rebuild / Delete** | ✅ / ✅ |
| **Refresh** | On evidence change; **freshness always shown** |
| **Coverage** | 0 |
| **Quality** | 🔴 does not exist |
| **Acceptance** | ① never graded `MEASURED`; ② **bands, not decimals, until a Brier reading exists**; ③ every `fact_refs` id resolves — unknown ⇒ block **dropped, not repaired**; ④ every number in generated prose matches a fact-sheet value; ⑤ obligations (`component_life`, `warranty_expiry`, `due_service`) carry `probability = null`. |

---

# Class J — Judgement *(human rulings; cannot be recomputed)*

## E16 · Demo / test classification
| | |
|---|---|
| **Class / Store** | **J** · `is_demo` *(to build)* |
| **Owner** | A-14 (schema + global scope) · A-15 (the rulings) |
| **Producers** | A-3, A-14, A-15 · **Consumers:** **every KPI population** |
| **Rebuild** | ❌ Not without re-asking a person |
| **Delete** | ❌ Losing it re-contaminates every metric |
| **Refresh** | On triage; re-review if a row is edited |
| **Coverage** | 11 seeded tickets identified · **139 manual test tickets untriaged** |
| **Quality** | 🔴 text markers in operator-editable columns |
| **Acceptance** | ① `is_demo` is a **column, not a text tag**; ② a global scope excludes it **by default**; ③ no seeder writes to the production DB without `--force`; ④ `demo:status` reports counts per table; ⑤ every ruling records who made it and when. |

> **Other J artifacts already in the system** and due the same treatment when next touched:
> `recurring_fault_reviews.decision`, severity overrides, `odometer_change_requests` approvals,
> resolved-transfer notes.

---

# Registry summary

| ID | Artifact | Cls | Quality | Producers | Consumers | Rebuild |
|---|---|:--:|:--:|:--:|:--:|:--:|
| E1 | Workshop events | F | 🔴 | 2 | 5 | ❌ |
| E2 | Ticket lifecycle | F | 🟡 | 2 | 5 | ❌ |
| E3 | Canonical duration | D | 🔴 | 1 | 4 | ✅ |
| E4 | Fault signatures | D | 🟡 | 1 | 2 | ✅ |
| E5 | Component history | F | 🔴 | **1** | 2 | ❌ |
| E6 | Odometer chain | F | 🔴 | 1 | 4 | ⚠️ |
| E7 | Repair outcomes | F | 🟡 | 1 | 3 | ❌ |
| E8 | Spend records | F | 🟡 | 1 | 1 | ✅ |
| E9 | Concept resolutions | D | 🔴 | 2 | 3 | ✅ |
| E10 | Reference ontology | R | 🟡 | 4 | 3 | ✅ |
| E11 | Reference documents | R | 🔴 | 2 | **0** | ✅ |
| E12 | Garage knowledge | K | 🔴 | 3 | 2 | ⚠️ |
| E13 | Component reliability | K | 🔴 | 1 | 2 | ✅ |
| E14 | Cost knowledge | K | 🔴 | 1 | 1 | ✅ |
| E15 | Recommendations | P | 🔴 | 3 | 2 | ✅ |
| E16 | Demo classification | J | 🔴 | 3 | **all** | ❌ |
| E17 | Comeback links | F | 🔴 | 1 | 1 | ❌ |
| E18 | Provenance envelope | D | 🟡 | 6 | **all** | ✅ |

**Scoreboard: 0 green · 5 amber · 13 red.** That is the honest starting position, and it is the number
`evidence:health` should report on day one so progress is visible.

**Four structural facts the registry makes unavoidable:**

1. **Seven artifacts cannot be rebuilt** (E1, E2, E5, E6*, E7, E16, E17). Every one is in Track A or is
   its single crossing. This is the formal justification for Track A's priority.
2. **E5 and E17 have exactly one producer each.** Single points of failure for C-6 and C-3 respectively.
3. **E11 has zero consumers** until B-12 — so deferring the corpus blocks nothing.
4. **E16 and E18 are consumed by *everything*.** They are cross-cutting, which is why A-14 and B-5 are
   worth more than their size suggests.
