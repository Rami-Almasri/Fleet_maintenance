# Fleet Maintenance Platform — End-to-End QA Audit

> **📋 For planning and sequencing, use [`Implementation-Backlog.md`](./Implementation-Backlog.md).**
> That document consolidates every finding below — plus the repair-duration audit and the Concept
> Bridge error analysis — into one prioritised, phased backlog with effort and dependencies.
> **This report remains the evidence:** it is where each backlog item's reproduction steps, root
> cause and measurements live. Findings are cited by ID (C-1, H-3, M-6…) from the backlog.

**Date:** 2026-08-01 · **Method:** live system testing against the running stack
(backend `127.0.0.1:8000`, frontend `:3000`, MySQL `laravel`), not code reading alone.
**Branch:** `ontology/fault-taxonomy-and-repair-capture` (72 uncommitted files)

**What was actually executed:**

* 123 parameterless `GET /api/*` endpoints swept as super-admin (timings + payload sizes captured)
* 24 vehicle-scoped endpoints × 4 vehicles (heaviest 450 records / median 13 / **zero-history** / nonexistent)
* 9 roles × 17 sensitive endpoints = permission matrix
* Full CRUD write path: vehicle created → read → updated → ticket raised → propagation verified → **cleaned up**
* Concept Bridge run against 23 real maintenance phrases through the live `MatchPipeline`
* Backend test suite (384 tests), frontend i18n guardrail, DB index/EXPLAIN analysis
* A full `db:backup` (83.9 MB) was taken before any write

**Cleanup:** every artifact this audit created was removed (vehicle 1994, ticket 170931, 9 QA tokens).
Pre-existing junk was **reported, not deleted** — see H-9.

---

## Executive summary

The platform is **structurally much better than it is currently trustworthy**. The engineering is
genuinely good: 384 tests green, clean i18n, well-chosen indexes, no N+1 in the hot path, honest
data-origin labelling in the UI, and design docs that openly mark themselves unbuilt. Nothing in the
frontend fabricates intelligence it does not have.

Two things block production, and they are both about *data honesty* rather than code quality:

1. **The Car Status board is 100% dead** — a variable-shadowing bug crashes every request.
2. **A demo seeder has been run against the live database** and left fabricated repeat-failures
   attributed to *real, named garages*, plus corrupted odometers on *three real fleet vehicles*.

Beyond that, the headline for your question *"is the intelligence real?"* is:

> **The deterministic intelligence is real and traceable. The automotive *knowledge* layer is not
> grounded in anything yet, and the Concept Bridge systematically misreads repair actions as faults.**

The Vehicle Intelligence Engine you asked me to test **does not exist** — its own doc says so on line 3.

---

# Findings

## 🔴 CRITICAL

### C-1 · Car Status board returns HTTP 500 for every user

| | |
|---|---|
| **Problem** | `GET /api/car-status` throws `Attempt to read property "isDelayed" on string`. The entire Car Status command centre is unusable. |
| **Reproduce** | `curl -H "Authorization: Bearer <any token>" http://127.0.0.1:8000/api/car-status` → 500. Reproduced on every one of the 9 roles. |
| **Expected** | The ops board renders the fleet's live ticket rows. |
| **Actual** | 500. `storage/logs/laravel.log` → `CarStatusService.php:244`. |
| **Root cause** | Variable shadowing. `backend/app/Services/CarStatusService.php:171` assigns the resolver object `$delay = $this->delayResolver->resolve($t);`. Line **198** then reuses the same name for an unrelated string: `$delay = $isOverdue ? 'overdue' : (...)`. Line **244** reads `$delay->isDelayed` — now a string. Line 257 also ships that string out as `delay_status`. |
| **Fix** | Rename the line-198 string to `$delayTone` (and update line 257's `'delay_status'`). One-line class of change. **Then add a smoke test for `CarStatusService::dashboard()`** — 384 tests pass and this still shipped, which means the surface has no coverage at all. |

### C-2 · Demo data is live in operator surfaces — *corrected and re-verified 2026-08-01*

> **Two claims in the first version of this finding were wrong and are corrected below.** The seeder
> *does* tag its rows, and it did *not* touch any vehicle odometer. The re-investigation also found
> **more** contamination than originally reported, from two other sources. Severity stays high, but
> the character changes: this is **cleanly reversible**, not an unpickable mess.

**What is actually true**

| | |
|---|---|
| **Problem** | Demo data from two generators is live in the `laravel` database and visible on operator surfaces. The entire Recurring-Fault Intelligence surface is synthetic: **5 of 5 `recurring_fault_reviews` are demo**, as are **11 of 12 `maintenance_line_items`** and **12 of 39 `maintenance_tasks` (31%)**. |
| **Reproduce** | `SELECT id, garage_feedback FROM maintenances WHERE garage_feedback='RECUR_DEMO';` → 9 rows. `SELECT id FROM maintenances WHERE customer_complaint LIKE '%[PARTS-DEMO]%';` → 2 rows (170829, 170830). |
| **Expected** | Demo data lives in `laravel_test`, or is filtered out of every operator query. |
| **Actual** | It renders as real work. Two tickets (170830, 170923) sit in `under_repair` on the ops board. 11 notifications about fabricated reviews were delivered to real admins' bells. Reviews cite invented part numbers (`BP-2201`, `ACC-5521`, `RAD-7742`, `ALT-4471`) against **real** vendors — `ABDULQADER` (5 demo vs 7 real tickets), `AJMAN STICAR SHOP` (4 vs 21), `7 CYLINDER` (2 vs 412). |
| **Root cause** | Neither seeder is wired into `DatabaseSeeder` (they cannot run on deploy) and both were run deliberately against live to populate demo pages. Nothing at the write layer prevents that. |

**CORRECTION 1 — the rows *are* tagged.** I originally reported them as indistinguishable. They are not:
`RecurringFaultDemoSeeder::MARKER = 'RECUR_DEMO'` is written to `maintenances.garage_feedback` on every
row, explicitly for cleanup. The marker collides with nothing: only 11 rows fleet-wide have a non-null
`garage_feedback`, and the other two are obvious human text (*"now car is ready"*, *"sovledddd onnnn sideee"*).
I checked for an `is_demo` column, found none, and wrongly concluded no marker existed.

**CORRECTION 2 — the seeder did not corrupt any odometer.** The seeder is read-only on odometers
(`RecurringFaultDemoSeeder.php:31` says so; line 137 derives `prevOdo = currentOdo − distance`). It
*selects* the four highest-odometer vehicles (`orderByDesc('odometer')->take(4)`), which is precisely
**why it landed on the three bad ones**. I had the causality backwards. `vehicles.updated_at` for all
three is `2026-07-26 06:19:38`, four days **before** the demo ran at `2026-07-30 07:23:55`.

The bad odometers are a **real but separate defect** — see C-3.

**Fix (now straightforward — both generators ship a teardown):**

```bash
php artisan tinker --execute="Database\Seeders\RecurringFaultDemoSeeder::teardown();"
php artisan parts:demo-seed --clean
```

`teardown()` deletes by marker in FK-safe order and also removes the orphaned bell notifications.
Then add the structural guard in P-1 below.

### C-3 · The workflow accepted and persisted impossible odometer readings

Split out of C-2, where it was misattributed to the seeder.

| | |
|---|---|
| **Problem** | Three real vehicles hold physically impossible odometers, written by the maintenance workflow on 2026-07-26: `1781 / plate 32409 → 9,999,999 km` · `1882 / plate 70596 → 6,276,888 km` · `1795 / plate 15721 → 1,223,993 km`. |
| **Evidence of digit-entry error** | Vehicle 1882 has `test_odometer = 62,767` and `receive_odometer = 62,769`, then `return_odometer = reinspect_odometer = 6,276,888`. Someone typed two extra digits. Its `baseline_odometer` is **7**. Vehicle 1781's baseline is 1,903 and it now reads 9,999,999. |
| **Expected** | The odometer-continuity rules (+5 km tolerance; >10 km change needs admin approval) reject a jump from 62,769 to 6,276,888. |
| **Actual** | Accepted, persisted to `maintenances`, and propagated into `vehicles.odometer`. |
| **Impact** | These feed the mileage baseline, cost-per-km and service-due chains, so they corrupt genuine fleet calculations — and they are exactly the vehicles any "highest mileage" query surfaces first. |
| **Fix** | Add an upper-bound plausibility guard to the odometer writer (absolute ceiling + max-delta-since-last-reading), and correct the three vehicles from their last trustworthy reading. Worth auditing which of the 7 odometer writers bypassed the continuity check. |

---

## 🟠 HIGH

### H-1 · Repair actions systematically resolve to fault concepts

The exact defect you named, confirmed live. `MatchPipeline` maps *what was done* onto *what was wrong*:

| Input (an action) | Resolves to (a fault) |
|---|---|
| `replace alternator` | **Alternator / charging fault** |
| `alternator replacement` | **Alternator / charging fault** |
| `water pump replacement` | **Water pump failure** |
| `shock absorber replacement` | **Worn shock / strut** |
| `brake pad replacement` | **Worn pads / discs** |
| `replace radiator` | **Coolant leak** |

**Impact:** every legacy repair note inflates the fault count for the system it repaired. Any future
reliability score or recurrence metric built on this reads a *fixed* car as a *failing* one.

**Root cause (quantified):** the matcher resolves against `FindingKeyword` — 105 fault concepts — only.
The ontology holds **1,470 nodes**: 106 fault, **92 repair, 274 procedure, 271 component**, 367 cause,
360 symptom. **93% of the ontology is unreachable by the matcher**, so every action verb falls to the
nearest fault. The `fixed_by` relation (405 edges) that would express action→fault already exists and is unused.

**Credit where due:** your own `docs/Concept-Bridge-Error-Analysis.md` §1 Class A documents this
honestly and proposes the right fix (classify each segment as fault | action | part | procedure).
This audit confirms it live and adds the 93% figure. **Do not run the enrichment backfill until it is fixed.**

### H-2 · `thermostat` is absent from the vocabulary, and the fallback produces nonsense

| Input | Result |
|---|---|
| `thermostat` | **no match** |
| `replace thermostat` | **no match** |
| `thermostat replaced` | **Windscreen crack / chip** (56), **Headlight out** (56) |
| `change thermostat` | **Oil Change** (59), **Tire Change** (59) |

The last two are worse than a miss: they are confident-looking wrong answers, matched purely on the
tokens *"replaced"* and *"change"*. Other collisions found: `car not starting` → top hit **A/C not
cooling**; `Battery Replacement` surfaces as a false positive on *every* `"* replacement"` query
(brake pads, shock absorber, water pump).

**Root cause:** token-stage noise sits at score 56–59, well above `MatchQuery::$minScore = 25`, so
junk clears the bar. Compounded by the missing component terms already listed in your error analysis
§3 (`thermostat` ×8, `drums` ×12, `condenser`, `axles`, `fans`).

**Fix:** raise the token-only floor (the phrase-first rule in your §5 rule 3), require a domain-noun
match not just a verb match, and add the missing component terms.

**Severity note:** currently contained. `MatchPipeline` is *not* wired into the ticket write path —
its consumers are search, complaint interpretation, evidence and the review/benchmark tooling. It does
not corrupt stored fault data today. It **would** the moment the backfill runs.

### H-3 · The Knowledge Platform is an empty shell presented as an authoritative one

```
knowledge_sources    17     ← OEM Factory Service Manuals, Bosch, SAE, ALLDATA, Haynes, NHTSA…
knowledge_documents   0
knowledge_chunks      0
evidence_links        0
ontology_feedback     0
```

Every one of the 17 sources has **zero documents**. And the confidence numbers are authored constants,
not measurements:

* `ontology_nodes`: **100%** `source='seed'`; confidence clusters on 80 (1,219), 100 (106), 85 (92), 75 (53)
* `ontology_edges`: **96%** `source='seed'` (2,005 of 2,087); confidence 80 (1,442), 82 (405), 75, 70

**So: no citation in the system can be traced to a document, because there are no documents.** Any
explanation claiming source support is asserting the seed author's opinion with a number attached.

**Mitigating (verified):** none of this is exposed in the UI — no frontend file references
`knowledge_sources`, Bosch, ALLDATA or `trust_weight`. It is a **latent trap, not active deception**.
The danger is the next feature that renders "confidence 82% — Bosch Technical Documentation".

**Fix:** either ingest real documents behind those sources, or mark every seeded confidence as
`grade = SEED` and refuse to render it as evidence-backed. Do not let a UI read these until then.

### H-4 · New tickets never reach the intelligence read-model

Created ticket 170931 → `maintenance_signatures` rows for it: **0**. Propagation to the operational
surfaces was correct (vehicle log event, activity events, notifications, domain events all fired), but
the signature index that powers concept/fault intelligence only updates on a batch rebuild.

**Impact:** intelligence is silently stale for anything created since the last rebuild — and per your
own notes a rebuild *moves the KPIs*, so the numbers change under operators with no visible cause.

**Fix:** write the signature on ticket close (same hook as `recalcFromTasks()`), and stamp
`classifier_version` on every derived number so a rebuild is visible rather than mysterious.

### H-6 · "Plane A" — the structured evidence plane the roadmap is built on — is mostly demo data

Once the demo rows in C-2 are removed, what remains of the structured tables is:

| Table | Total | Demo | **Real** |
|---|---:|---:|---:|
| `recurring_fault_reviews` | 5 | 5 | **0** |
| `maintenance_line_items` | 12 | 11 | **1** |
| `maintenance_tasks` | 39 | 12 | **27** |
| `repair_inspections` | 9 | 2 | **7** |
| `maintenance_task_actions` | 0 | 0 | **0** |
| `vehicle_components` | 0 | 0 | **0** |

The Vehicle Intelligence architecture (§2.2) calls Plane A *"small and young"*. It is smaller than that
description implies: **zero** real recurring-fault reviews, **one** real line item, **zero** component
records. Every Plane-A metric in the design — component replacement counts, mean intervals, verified
outcomes, cost-per-repair — currently has no population to compute over.

**This is not an argument against the design; it is an argument about sequencing.** Plane A grows only
as the fleet is repaired through the new workflow. Building metrics on it now means building against
demo rows and getting numbers that look plausible and mean nothing — which is how the demo data came
to be mistaken for real in the first place.

**Recommendation:** after cleanup, add the real counts above to a `demo:status`-style readout and set
`minSampleN` from them, per the design's own §17.1 gate. Ship the Plane-B (concept-level) sections
first; hold the Plane-A sections until the counts justify them.

### H-5 · `GET /api/Customer` returns 11 MB in 13.4 seconds

Unpaginated dump of 17,351 customers. Other offenders: `MileageChain/audit` 1.8 MB,
`Maintenance/incidents` 1.3 MB, `maintenance-tickets` 920 KB, `Vehicle` 809 KB (returned to *every*
role including `viewer`).

**Fix:** paginate. These are the endpoints that will fall over first under real concurrency.

---

## 🟡 MEDIUM

### M-1 · Permission ladder is inconsistent — `viewer` outranks `operations` on money

| Endpoint | viewer | operations | supervisor | inspector |
|---|---|---|---|---|
| `/api/Profitability` | **200** | 403 | 403 | 403 |
| `/api/FinancialConflicts` | **200** | 403 | 403 | 403 |
| `/api/intelligence/cost` | **200** | 403 | 403 | 403 |

A read-only `viewer` sees full fleet profitability and cost intelligence. `operations` — who holds
`billing.manage` — cannot. Cause: these surfaces gate on `insights.view`, which `viewer` has and
`operations` does not. **Decide which it should be and make the gate consistent**; today the answer
looks accidental rather than chosen.

### M-2 · Roles you asked me to test do not exist

`manager` has 44 permissions and **0 users** — dead configuration, untestable. There is **no
`technician` and no `customer` role** at all. Of 10 roles, only 9 are exercised. Either populate and
test `manager` or retire it; if technicians are meant to use the system, the role is missing.

### M-3 · The Vehicle Intelligence Engine does not exist

You asked me to verify health analysis, recurring problems, most-replaced components, reliability
score, AI observations, risk prediction, fleet comparison, timeline insights and recommendations.

`docs/Vehicle-Intelligence-Architecture.md` line 3: **"Status: DESIGN ONLY. No code has been written."**
Confirmed — `App\Intelligence` does not exist; `MetricEngine`, `MetricSpec`, `MetricRegistry`,
`MetricValue`, `VehicleIntelligenceProfile`, `NarrativeProvider`, `ComponentReader`, `FaultEventReader`
are all unbuilt. `app/Jobs` is empty, so the Phase-5 queue prerequisite is also absent.

**This is not a defect** — the doc is honest and the frontend does not fake it (`VehicleProfile.js`
shows registration/insurance/service compliance LEDs, correctly labelled, with per-tab `TAB_ORIGIN`
provenance strings). It is recorded so the roadmap is not mistaken for shipped capability.

**What *does* exist and works:** `/api/intelligence/vehicle/{v}/explain` returns a real explanation DAG
whose every node carries `source_module` (e.g. `Contracts (OfficeManager)`) and `confidence: imported`,
drilling down to individual contract records. That is genuine, traceable lineage — but it is the
**financial/service** explainability platform, not vehicle health.

### M-4 · Empty history is the normal case, not the edge case

**191 of 438 vehicles (43.6%) have zero maintenance records**; 80 have zero expenses. Endpoints handle
this without crashing (verified on vehicle 1536), and nonexistent vehicles correctly 404 across all 24
routes. But any intelligence UI must treat "no history" as the default rendering path — your own
architecture doc reaches the same conclusion in §17.1.

### M-5 · No in-app path to add an expense

There is **no expense write route** — `vehicle_expenses` (28,327 rows) is Excel-import-only via
`VehicleExpenseProvider`. "Add expenses" as a user flow does not exist. Consistent with the documented
design, but it means the cost side of the ledger cannot be corrected in-app.

### M-6 · Slow endpoints

`garage-recommendations` 8.7 s · `TripDashboard` 7.2 s · `intelligence/cost` 6.3 s ·
`intelligence/maintenance-ops` 6.3 s · `maintenance-swaps/board` 2.7 s.

Note this is **not** an indexing problem — indexes are well chosen, EXPLAIN shows key usage on every
hot query (`maintenances_vehicle_id_out_date_index`, `maint_sig_unique`, etc.) and a heaviest-vehicle
profile load took 4 queries / 31 ms. The cost is in aggregation breadth, so caching or narrowing the
window is the lever, not more indexes.

### M-7 · Confidence contradicts relevance in match results

For `engine overheating`: correct hit **Overheating** score 96 / confidence **72**; junk hit
**Engine noise** score 59 / confidence **79**. Same inversion on `check engine light on` (correct 72,
junk `Engine noise` 79, `Wheel alignment` 78). Ranking uses `score × 1000 + confidence` so ordering
survives — but any UI that renders the confidence chip will show *higher* confidence on the *wrong*
answer. Also `stages` comes back as numeric indices (`0,1,2,3`) rather than stage names, which weakens
the "never a black box" guarantee.

### M-8 · `maintenances` is over-indexed

**30 indexes**, 18.8 MB of index against 11.5 MB of data on a 26.8k-row table. Every write pays for all
30. Worth pruning the single-column foreign-key indexes that no query drives.

---

## 🟢 LOW

| ID | Finding |
|---|---|
| L-0 | **139 untagged manual test tickets in live DB** (`origin='manual'`, no contract, not from any seeder) with contents like `ff123`, `333`, `check check`, `test contract`, `ااااااا`. Unlike the seeded rows these carry **no marker at all** and are mixed in with genuine manually-raised tickets, so they need review rather than bulk deletion. See P-2. |
| L-1 | **Leftover test data in live DB:** vehicle 1993 `UITEST-1` / VIN `UITEST6A6DD9D88B39E`, plus 5 `qa_*@fleet.local` users. Left in place deliberately — your call to remove. |
| L-2 | `storage/logs/laravel.log` is **38.9 MB**, unrotated. Configure daily rotation. |
| L-3 | `POST /api/Vehicle` returns **200** on create; `POST /api/maintenance-tickets/request-inspection` returns **201**. Inconsistent. |
| L-4 | `GET /api/Reconciliation` is a registered parameterless route that returns **404**. Dead or mis-registered. |
| L-5 | `FindingKeyword.category` is null across all 105 concepts, so category-scoped matching (`MatchQuery::$category`) can never filter. |
| L-6 | Create-vehicle accepts `status` values that the fleet vocabulary does not use (`available` rejected, `ready` accepted) — the enum is right, but the error surface only reported the first failing field. |

---

## What is genuinely healthy — verified, not assumed

* **384 backend tests pass** (1,255 assertions, 10.9 s), including strong pure-function suites on
  degradation, verdict handling and workflow state resolution.
* **i18n guardrail is clean** — `npm run check:i18n` green: 1,932 keys in both `en` and `ar`, zero
  orphans, zero missing routes/sections. No missing translations found.
* **118 of 123 endpoints return 200.** The remaining 5 are 1 genuine 500 (C-1), 1 dead route (L-4) and
  3 correct 422s on endpoints that require query parameters.
* **404 handling is correct** — all 24 vehicle-scoped routes return 404 for a nonexistent vehicle, none leak.
* **Validation is strict and correct** — ticket intake rejected my request until it had a valid
  `trigger_reason`, and the odometer-gated intake correctly demanded `test_odometer` + `odometer_photo`.
* **Write propagation works** — creating a ticket correctly fired `vehicle_log_events`,
  `user_activity_events`, `notifications` and `domain_events`, and `request_origin` was auto-stamped
  alongside `trigger_reason`.
* **Traceability rule is being honoured** — `VehicleProfile.js` carries per-tab data-origin strings
  naming the actual upstream system. No black boxes on the surfaces that shipped.
* **The team's own error analysis is unusually honest** — `Concept-Bridge-Error-Analysis.md` §0 opens
  by invalidating its own false-positive proxy. That is the behaviour that makes an audit like this
  cheap, and it should be protected.

---

# System Health Scores

| Area | Score | Reasoning |
|---|:--:|---|
| **Backend** | **7.0** / 10 | Clean architecture, 384 green tests, correct validation, good query plans. Loses points for one crash-level shadowing bug on an untested surface and several unpaginated endpoints. |
| **Frontend** | **7.5** / 10 | i18n fully clean, provenance labelling honoured, no fabricated intelligence anywhere. Untested here: deep interactive flows and console errors (backend-first audit). |
| **Database** | **6.5** / 10 | Well-indexed, sensible composite keys, no N+1 in the hot path. Heavily penalised for demo-seeder pollution and three corrupted real odometers. |
| **Data quality** | **5.0** / 10 | 26.8k real maintenance rows is a genuine asset, but 43.6% of vehicles have no history, cost coverage is under 2%, and the odometer chain has known-bad values. |
| **Intelligence layer** | **4.0** / 10 | Deterministic explainability (finance/service) is real and traceable. Recurrence and recommendation engines exist but are near-empty (2 decisions; 5 reviews, 4 of them fake) and the read-model goes stale on new work. |
| **AI readiness** | **3.0** / 10 | 93% of the ontology unreachable by the matcher, actions misread as faults, zero documents, zero evidence links, hardcoded confidence. The scaffolding is thoughtful; the grounding is absent. |
| **Production readiness** | **4.5** / 10 | Blocked on C-1 and C-2. Both are days of work, not months — this number moves fast once they are cleared. |

---

# Preventing recurrence

### P-1 · One convention for synthetic data, enforced at the write layer

Today there are **four** ticket-writing generators with **three** different conventions:

| Generator | Marker | Teardown |
|---|---|---|
| `RecurringFaultDemoSeeder` | `garage_feedback = 'RECUR_DEMO'` | ✅ `::teardown()` |
| `PartsDemoSeed` | `[PARTS-DEMO]` prefix in `customer_complaint` | ✅ `parts:demo-seed --clean` |
| `SeedMaintenanceWorkflow` | `garage_feedback = 'Seeded — repair complete.'` (human text, not a marker) | ⚠️ separate reset command |
| Manual QA via the UI | **none** | ❌ none — this is L-0 |

Both demo seeders are individually well-built. The failure is that there is no *shared* rule, and the
markers live in **operator-facing text columns** — `garage_feedback` is a real field a garage's reply
belongs in, so the marker is one careless edit away from being erased.

**Recommended:**

1. **`is_demo BOOLEAN NOT NULL DEFAULT false`** on `maintenances`, `maintenance_tasks`,
   `recurring_fault_reviews`, `maintenance_line_items`, `repair_inspections`. A dedicated column, not
   a text tag — it cannot be overwritten by normal use and it indexes cleanly.
2. **A global Eloquent scope that excludes `is_demo = true` by default**, with an explicit
   `->withDemo()` opt-in for the demo pages. This is the step that actually matters: it makes
   contamination *inert* rather than merely *findable*, so the next seeder run cannot reach a KPI.
3. **An environment guard in a shared base class** every demo seeder extends — refuse to run when
   `DB::getDatabaseName()` is the production database unless `--force` is passed.
4. **A `demo:status` command** that reports demo row counts per table, so contamination is one command
   away from being visible instead of requiring an audit.

### P-2 · Close the manual-testing hole (L-0)

The 139 untagged manual tickets are the larger contamination and no seeder guard will catch them,
because they were created through the real UI. Options, in order of preference: a non-production
banner plus a `demo` user flag that stamps `is_demo` on anything that account creates; or, better,
give QA a restorable snapshot of `laravel` to work against so live is never the test target.

---

# Recommended order of work

1. **C-1** — rename the shadowed variable; add a `CarStatusService` smoke test. *(hours)*
2. **C-2** — run both teardowns; verify with `demo:status`. *(≈1 hour, now that the markers are confirmed)*
3. **C-3** — plausibility guard on the odometer writer + correct the 3 vehicles. *(1 day)*
4. **P-1** — `is_demo` column + global scope + env guard. *(1–2 days — this is the durable fix)*
3. **H-4** — write signatures on ticket close so intelligence stops going stale. *(1 day)*
4. **M-1 / M-2** — decide the financial permission gate; populate or retire `manager`. *(hours)*
5. **H-1 / H-2** — node-type-aware matching + phrase-first floor + missing component terms.
   **This is the gate on the enrichment backfill — do not backfill before it.** *(1–2 weeks)*
6. **H-3** — grade seeded confidence as `SEED`; ingest documents or stop implying they exist. *(design call)*
7. **H-5 / M-6** — paginate the heavy endpoints. *(2–3 days)*

**On adding more intelligence features:** the deterministic half of this platform is in good shape and
the design documents are unusually clear-eyed. The risk is not the code — it is that the next
intelligence layer will be built on a concept bridge that reads repairs as failures and a knowledge
graph with nothing behind it. Items 5 and 6 are the real prerequisites.
