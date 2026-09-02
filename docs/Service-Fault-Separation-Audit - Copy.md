# Pre-Release Architecture Audit — Service / Fault Domain Separation

**Date:** 2026-08-02 · **Auditor:** automated code + data audit · **Branch:** `ontology/fault-taxonomy-and-repair-capture`
**Source of truth:** `docs/Service-vs-Fault-Domain-Separation.md` (ADR, APPROVED) + `docs/Service-vs-Fault-Implementation-Plan.md`
**Runtime state at audit:** `EVENT_KIND_MODE=shadow` (`backend/.env:119`), MariaDB 10.4.32 local / MySQL 8 production, 108 `maintenance_tasks`, 147 distinct sheet labels, 2,813 reason-classified workshop rows.

---

## 1. Areas audited

| # | Layer | Coverage | Verdict |
|---|---|---|---|
| 1 | Database schema & migrations | `2026_07_27_1003_add_kind_to_maintenance_tasks`, 3 catalog migrations, live `information_schema` check | ✅ with caveat (L2) |
| 2 | Eloquent models & relationships | `MaintenanceTask`, `Vehicle`, `Maintenance`, `FaultCatalog`, `ServiceCatalog`, `InspectionType`, `FindingKeyword`, `MaintenanceLineItem` | ✅ with caveats (H4, L1) |
| 3 | Enums & constants | `MaintenanceTask::KINDS/KIND_META/CLS_*`, `Maintenance::TYPE_*/CONTEXT_*/TRIGGER_*`, `FaultVocabulary::NON_FAILURE_CATEGORIES`, `config/sheet_label_kinds.php`, `config/maintenance_findings.php` | ❌ fragmented (H7) |
| 4 | Ontology / taxonomy | `database/seeders/ontology/*` (11 files), `FaultOntologySeeder`, `finding_keywords`, `keyword_terms`, graph edges | ✅ modelling / ❌ API (H6) |
| 5 | AI matching & classification | `KeywordOntologyService`, `MatchPipeline`, 5 matching stages, `KeywordAiEnrichmentService` prompts | ❌ (H6) |
| 6 | Repair Capture | `Evidence/Capture/CaptureTranslator` | ✅ |
| 7 | Maintenance workflow | `MaintenanceWorkflowService` (6.2k lines), `MaintenanceTaskService`, `MaintenanceWorkflowController` | ❌ (C2, C3, H5, M2) |
| 8 | Component replacement | `Components/ComponentReadModel`, `service_records`, `component_events` | ✅ |
| 9 | Vehicle Health | `CarStatusService` | ✅ exemplary |
| 10 | Recommendation engine | `GarageRecommendationService`, `Garage/*` (11 classes) | ✅ (fault-scoped by construction) |
| 11 | Recurring-fault logic | `RecurringFaultService`, `VehicleFaultRecurrenceService`, `VehicleSuggestedChecksService` | ❌ (C3) / ✅ (the latter two) |
| 12 | Search & autocomplete | `/finding-keywords/resolve`, `FindingsPicker.js` | ❌ (H6, M9) |
| 13 | APIs & Resources | `MaintenanceTaskResource`, `MaintenanceWorkflowResource`, `WorkshopEventResource`, `FindingKeywordResource`, `VehicleResource` | ❌ (H3, H4) |
| 14 | Controllers | `VehicleController`, `MaintenanceController`, `DashboardController`, `EventClassificationReviewController`, `FindingKeywordController` | Mixed (M8) |
| 15 | Policies | `VehiclePolicy` — zero type logic | ✅ N/A |
| 16 | Jobs / Listeners | no `app/Jobs`; `TimelineProjector`, `NotificationDispatcher`, `IntelligenceCacheInvalidator` — zero type logic | ✅ N/A |
| 17 | Reports / Dashboards / Analytics | `DashboardService`, `MaintenanceAnalyticsService`, `MaintenanceForesightService`, `NotificationScanner`, `PartIntelligenceService` | ❌ (H2, M1, M3, M6, M7) |
| 18 | Seeders & Factories | `ServiceCatalogSeeder`, `FaultCatalogSeeder`, `InspectionTypeSeeder`, `FaultOntologySeeder`; **no factories exist** | ✅ / ⚠️ (tests) |
| 19 | Importers / Exporters | `MaintenanceSheetImporter`, `OdooExportService`, `VehicleLogSheetExporter` | ⚠️ (no type at ingest) |
| 20 | Frontend | `faultCategories.js`, `vehicleTimeline.js`, `VehicleProfile.js`, `FindingsPicker.js`, `EventClassificationReview.js`, workflow board | Mixed (M4, M9) |
| 21 | Tests | `EventClassificationTest`, `EventKindTest`, `FindingsVocabularyTest` | ❌ (§4) |
| 22 | Documentation | ADR + implementation plan | ⚠️ ADR still says "PROPOSED — no code written" |

---

## 2. Findings

Severity key — **Critical**: writes wrong data or corrupts vehicle master records · **High**: architectural invariant unimplemented, wrong figures fleet-wide · **Medium**: incorrect analytics/UI in a bounded surface · **Low**: hardening / consistency.

---

### 🔴 C1 — A fault can be silently stored as a Service, with no review flag

**Severity:** Critical · **Corruption:** YES — persisted `kind`, no review path, no UI to discover it.

**Files**
- `backend/app/Services/EventClassificationService.php:95-100` (rule 3)
- `backend/app/Services/EventClassificationService.php:258-266` (`hasFaultEvidence`)
- `backend/config/maintenance_findings.php:339` (`fault_evidence_keywords`)

**Why it is wrong.** The shield's rule 2.5 exists precisely so "a clear fault symptom beats routine ticket context". It tests the symptom against `fault_evidence_keywords`, which contains **English words only**. Any non-English symptom therefore falls through to rule 3, which types the task `service` **and returns `needs_review = false`** — the resolver asserts confidence in an answer it never actually tested.

**Observed incorrect behaviour (live data, not hypothetical).**
```
task #144  symptom = "صوت باب امامي"  (front door noise — a fault)
           kind = service   service_catalog_id = NULL   needs_review = 0
ticket #170875  maintenance_type = routine  trigger_reason = test_drive  visit_context = standard
```
An inspector's test-drive report of a door noise is stored as planned maintenance. Once `EVENT_KIND_MODE=enforced`, this fault disappears from Top Faults, health score, reliability, recurrence chains and the chronic watchdog — and because `needs_review=0` it never reaches `EventClassificationReview`.

**Recommended fix**
1. Rule 3 must return `needs_review = true` whenever the symptom produced *no* positive service signal (it only matched ticket context). Ticket context is weak evidence about an individual finding.
2. Add Arabic terms to `fault_evidence_keywords`, or better: replace the keyword list with a call into the ontology (`KeywordOntologyService::resolve`, which already handles 289 Arabic terms) and treat a confident non-`routine` category match as fault evidence.
3. Backfill: re-run `events:backfill-kind` after the fix and diff.

---

### 🔴 C2 — Vehicle service anchors are written from symptom TEXT, ignoring `kind`

**Severity:** Critical · **Corruption:** YES — writes `vehicles` service columns + rolls `service_reminders`.

**Files**
- `backend/app/Services/MaintenanceWorkflowService.php:5965` (`hasRoutineServices`)
- `backend/app/Services/MaintenanceWorkflowService.php:5990, 5999, 6006` (`confirmRoutineServices` → `Vehicle::recordServiceDone`)
- `backend/app/Models/Maintenance.php:508-559` (`routineServiceTypeFor`, `serviceTypeForSymptom`)

**Why it is wrong.** This is the single place a maintenance action reaches the vehicle master record, and it decides "is this a service?" by exact-matching `$task->symptom` against a config keyword map — **while `$task->kind` and `$task->service_catalog_id` sit unread on the same row**. It violates governing principles #1 ("`kind` is the ONLY classifier") and #4 ("the resolver never runs at read time") at the most consequential write in the system.

**Observed incorrect behaviour.** Two symmetric failures:
- *False positive:* a task with `kind = fault` whose symptom happens to read `"Oil Change"` rolls the oil reminder forward and stamps the vehicle's service odometer — the car is then believed serviced when it was not.
- *False negative (live today):* task `#81 "Wheel alignment"`, `kind = service`, `service_catalog_id = 14` → `serviceTypeForSymptom("wheel alignment")` misses (`ServiceReminder::TYPE_LABELS` uses a different label), so a correctly-classified, catalog-linked service **never rolls its reminder**. The catalog row that exists to answer this question is ignored.

**Recommended fix.** Read `kind` first and resolve the reminder type from `service_catalog.service_reminder_type` (the column exists for exactly this, ADR §3.1). Keep the text matcher only as the legacy fallback for `service_catalog_id IS NULL`, and gate the whole branch on `kind === KIND_SERVICE`.

---

### 🔴 C3 — Recurring-fault detection is not type-guarded on the subject task

**Severity:** Critical · **Corruption:** YES — writes `maintenance_tasks.recurrence_flagged`, `recurrence_previous_task_id`, and creates `recurring_fault_reviews` rows.

**Files**
- `backend/app/Services/RecurringFaultService.php:50` (`flagPossibleRecurrence`)
- `backend/app/Services/RecurringFaultService.php:69` (`onFaultConfirmed`)
- `backend/app/Services/RecurringFaultService.php:110` (`detectPriorFix` — the only `EventKind::enforced()` gate)
- Called for **every** new task: `backend/app/Services/MaintenanceTaskService.php:98`

**Why it is wrong.** `detectPriorFix` applies `->faults()` to the query for the *previous* occurrence, and only when the flag is enforced. The **subject** task is never checked at all, in either mode. `syncFromFindings` calls `flagPossibleRecurrence($task)` unconditionally for every promoted finding, services included. ADR §9 explicitly lists "recurring-fault reviews stop firing on oil changes" as intended behaviour.

**Observed incorrect behaviour.** A car receiving its second oil change gets `recurrence_flagged = true` and, on confirmation, an open `RecurringFaultReview` — "this car keeps coming back for the same problem". Recurrence is *normal and desirable* for a service; that is the definitional difference between the two types (ADR §0, table row `service`: "recurring is normal").

Not yet manifest in the current 108-row dataset — the 8 `kind=service` tasks are spread across 8 distinct vehicles (1954, 1925, 1882, 1888, 1864, 1962, 1967, 1947), so no prior match exists. The code path is unguarded; the data has simply not exercised it. All 39 existing `recurring_fault_reviews` are genuine faults.

**Recommended fix.** First line of both public entry points: `if (! $fault->isFault()) return;` — unconditional, not flag-gated. A service must never enter the recurrence engine regardless of rollout mode.

---

### 🟠 H1 — The separation is switched OFF; every gated reader still mixes

**Severity:** High · **Corruption:** No — analytics only.

**Files:** `backend/.env:119` (`EVENT_KIND_MODE=shadow`), `backend/app/Support/EventKind.php:27`

8 call sites across 6 services consult `EventKind::enforced()`, which returns **false** in `shadow`. Every fault figure in the product today — Top Faults, fault leaderboard, health score, reliability, recurrence chains, part-recurrence, duplicate spend — is computed exactly as it was before the separation existed. The work is real but dark.

**Gated call sites (correct implementations, currently inert):**
`CarStatusService.php:427,647` · `DashboardService.php:1331,1432` · `RecurringFaultService.php:110` · `VehicleFaultRecurrenceService.php:256` · `PartIntelligenceService.php:170,286`

**Recommended fix.** Do not flip until C1/C3 and H2 are closed — flipping today would *hide* the C1-misclassified faults while leaving H2's sheet half mixed, producing a worse and less explicable set of numbers than shadow.

---

### 🟠 H2 — Top Faults counts routine sheet visits as faults (ungated source)

**Severity:** High · **Corruption:** No — dashboard analytics.

**Files**
- `backend/app/Services/DashboardService.php:1341-1352` (`topFaults`, source B)
- `backend/app/Services/DashboardService.php:1445-1453` (`faultCars`, source B)

**Why it is wrong.** Both methods merge two sources. Source A (`maintenance_tasks`) is correctly gated on `kind`. Source B (the sheet, joined to `maintenance_reasons`) counts **every** reason regardless of `level`, with no `kind` equivalent and no routine exclusion. Flipping H1 would fix only the smaller half: 108 tasks vs ~27k sheet rows.

**Observed incorrect behaviour (measured).** 86 of 2,813 reason-classified workshop rows carry `level = 'routine'` — reasons `Periodic Maintenance`, `Cleaning`, `Oil & Fillter Change`, `Testing` — and are counted as faults in both the fleet Top Faults chart and the per-fault car drill-down.

**Recommended fix.** Exclude `r.level = 'routine'` from source B, *and* apply `EventClassificationService::labelKind()` to the sheet labels so the exclusion follows the same vocabulary as source A. Gate on `EventKind::enforced()` for symmetry with source A.

---

### 🟠 H3 — The API never exposes the type

**Severity:** High · **Corruption:** No — but it blocks every UI fix.

**Files**
- `backend/app/Http/Resources/MaintenanceTaskResource.php` — no `kind` key (the `kind` at `:123` is *media* kind)
- `backend/app/Http/Resources/MaintenanceWorkflowResource.php` — no task `kind` (`:200` is `test_kind`, `:438` media, `:637` follow-up tag)
- `backend/app/Http/Resources/WorkshopEventResource.php:25` — ships raw unsplit `sheetIssueTags`

ADR §6 "API/Resources: expose `kind`, `kind_meta`, and `catalog` on `MaintenanceTaskResource`, `MaintenanceWorkflowResource`, `WorkshopEventResource`" is **entirely unimplemented**. `MaintenanceTask::KIND_META` exists (`MaintenanceTask.php:93`) specifically to be the single source of colour/emoji for backend *and* frontend, and nothing ever serialises it. Consequently no frontend surface can render or filter by type — M4 and M9 are downstream of this.

**Recommended fix.** Add `kind`, `kind_meta` (from `kindMeta()`), and `catalog` (`{id, slug, name}` from `catalog()`) to all three resources. Additive, no breaking change.

---

### 🟠 H4 — The API re-derives type from symptom text at read time

**Severity:** High · **Corruption:** No directly — but it is the read-time twin of C2.

**Files**
- `backend/app/Http/Resources/MaintenanceTaskResource.php:33` — `'routine_service_type' => Maintenance::routineServiceTypeFor($t->symptom)`
- `backend/app/Http/Resources/MaintenanceTaskResource.php:163` — `serviceConfirmation()` gates on `Maintenance::serviceTypeForSymptom($t->symptom)`

Governing principle #4: *"The resolver is a legacy shield, not the classifier. Text/heuristic resolution runs exactly once … It never runs at read time."* This runs on **every serialisation of every task**, and it is the field the UI uses to decide whether a task is routine. The stored `kind` on the same row is not consulted.

**Observed incorrect behaviour.** Task `#144` (`kind = service`) serialises `routine_service_type: null` because its Arabic symptom is not in the keyword map — the API says "not a service" about a row the database says *is* a service. Two answers, same request.

**Recommended fix.** Derive from `kind` + `serviceCatalog->service_reminder_type`; keep the text path only when `service_catalog_id IS NULL`.

---

### 🟠 H5 — The catalog is not the source of type: 0 of 108 tasks are catalog-classified

**Severity:** High · **Corruption:** No — but the ADR's central invariant is unimplemented.

**Files**
- `backend/app/Services/MaintenanceTaskService.php:71-84` (`syncFromFindings`) — constructs `MaintenanceTask` with no `kind`, no `*_catalog_id`, no `classification_source`
- `backend/app/Models/MaintenanceTask.php:152-166` — the `creating` hook then runs the **shield** on every row
- `backend/app/Services/EventClassificationService.php:45` — `classifyFromCatalog()` has exactly **one** caller: `EventClassificationReviewController.php:95` (the admin fix-up screen)

Governing principle #2: *"The catalog is the source of type … Type is a selection, not a guess."* ADR Phase 0 required `syncFromFindings()` to copy `kind`/`catalog_id` from the findings JSON, and the findings entries to gain those keys. Neither happened. ADR §7's type-first intake (Phase 3) does not exist.

**Measured state:**
```
kind     source     needs_review  count
fault    resolver   0              80
fault    resolver   1              20
service  resolver   0               8
```
100% `resolver`, 0% `catalog`. Every type in the system is a guess; 20 rows are self-declared unreliable.

Secondary defect, same file: `MaintenanceTaskService.php:91` logs `'Fault identified: ' . $text` for every promoted finding — the audit trail records "Fault identified: Oil Change" for a service.

**Recommended fix.** Have the findings picker send `{kind, catalog_id}` with each chip (`finding_keywords` already carries `category_key`, and all 64 `fault_catalog` names are present in `finding_keywords` — the join is trivial); `syncFromFindings` passes them to `classifyFromCatalog()`; the shield then only ever runs on legacy rows, as designed. Make the log verb read from `kindMeta()['label']`.

---

### 🟠 H6 — AI matching cannot be asked for faults only; services rank inside fault results

**Severity:** High · **Corruption:** No — but it feeds diagnosis, complaint triage and recommendations.

**Files**
- `backend/app/Services/KeywordOntologyService.php:40-50` — options are `{limit, category, min_score, scope}`; **no kind/type filter and no exclusion**
- Callers that treat the result as a fault, none filtering: `Ontology/Reasoning/ComplaintInterpreter.php:93` · `Ontology/Reasoning/CausalReasoner.php:122` · `Ontology/Retrieval/FleetRetriever.php:68` · `Services/Knowledge/RepairRecommendationService.php:51` · `Ontology/Matching/Stages/SemanticStage.php:125`
- `backend/app/Http/Controllers/FindingKeywordController.php:205-271` — the `/resolve` payload has `selectable` but **no `kind`**; the response message is hardcoded `'Matched N fault(s)'`

**Observed incorrect behaviour (live matcher run during this audit).**
```
"car does not start battery dead" → Battery / won't start (electrical 78)
                                    Battery Replacement    (routine    76)  ← a SERVICE, 2 pts behind
"radiator leaking coolant"       → Coolant leak (fluids 71), Oil leak (fluids 68),
                                    Coolant service (routine 58)            ← a SERVICE in top-3
"periodic maintenance"           → (no match at all)
```
Top-1 ranking is correct in every case tested — the vocabulary work in `database/seeders/ontology/routine.php` is doing its job. But callers that take top-N or a `min_score` threshold (`FleetRetriever` uses `min_score:55`; `RepairRecommendationService` takes top-1 with no floor) can and will receive a routine service as a diagnosis. `ComplaintInterpreter` will interpret a customer complaint as a scheduled service.

Asymmetry worth noting: `service_catalog` has "Brake Pads (service)" but the ontology has no routine brake-pad concept, so a genuine brake-pad *service* matches the *fault* "Worn pads / discs" — the reverse leak.

**Recommended fix.** Add `exclude_categories` / `kinds` to the resolve options and a `kind` (or at minimum `category_key`) field on every match in the `/resolve` payload; have the five fault-lane callers exclude `routine`. Fix the response message. Add "periodic maintenance" wording to `routine.php`.

---

### 🟠 H7 — Seven independent implementations of "is this a service or a fault?"

**Severity:** High · **Corruption:** No — the meta-finding behind H2, C2, M5, M7.

| # | Mechanism | File | Grain | Live? | Consumers |
|---|---|---|---|---|---|
| 1 | `maintenance_tasks.kind` + 3 catalogs | `MaintenanceTask.php:76-108` | event | flag-gated | 6 services |
| 2 | `config/sheet_label_kinds.php` via `labelKind()` | `EventClassificationService.php:137` | label | **yes** | 1 (VehicleController) |
| 3 | `FaultVocabulary::NON_FAILURE_CATEGORIES` + `ALIASES` | `Support/FaultVocabulary.php:70,80` | label | **yes** | recurrence + foresight |
| 4 | `maintenance_reasons.level` | DB table | visit | **yes** | `recurringFaults`, Dashboard source B |
| 5 | `Maintenance::routineServiceTypeFor` / `serviceTypeForSymptom` | `Maintenance.php:508,537` | symptom | **yes** | `confirmRoutineServices` (writes!), API resource |
| 6 | `finding_keywords.category_key = 'routine'` | DB / ontology | concept | **yes** | picker, matcher |
| 7 | `routineMap` in the picker | `frontend/.../FindingsPicker.js:42` | symptom | **yes** | inspector UI |

They disagree on real labels:

| Label | `service_catalog` | `sheet_label_kinds` | `FaultVocabulary` | `maintenance_reasons` |
|---|---|---|---|---|
| Wheel Alignment | **service** | fault (unmapped) | fault (`→tyres`) | — |
| Tire Issues (215 rows) | ~ Tyre Change = service | fault | fault | **routine** |
| Rim Scratch (891 rows) | — | fault | **excluded** (cosmetic) | — |
| Engine Oil leak | — | fault | fault | **routine** |

**Recommended fix.** `EventClassificationService` must become the only answer at label grain: fold `FaultVocabulary`'s routine aliases and `Maintenance::routineServiceTypeFor` into it, have `FaultVocabulary` delegate, and correct `maintenance_reasons.level` for the two mislabelled leak reasons (M7). Export the map to the frontend rather than maintaining copy #7 by hand.

---

### 🟡 M1 — Foresight filters at ticket grain, not event grain
**Severity:** Medium · **Corruption:** No.
`backend/app/Services/MaintenanceForesightService.php:161-163, 704-705` still use `m.visit_context <> 'routine'`. ADR §6 requires dropping this for the `kind` scope. A genuine fault discovered during a routine visit is invisible to chronic/act-now detection. Same pattern in `VehicleFaultRecurrenceService.php:261-264` (there it is *additional* to a correct `->faults()` gate, so it is belt-and-braces rather than a defect).

### 🟡 M2 — Chronic Fault Watchdog matches findings text with no type filter
**Severity:** Medium · **Corruption:** No.
`backend/app/Services/MaintenanceWorkflowService.php:5486-5518`. `faultHistory()` scans closed tickets' `findings[]` JSON by lowercase text equality. Select "Oil Change" at the Decide step and the inspector is warned "repaired 4 times before" for routine servicing. Fix: skip findings whose `kind` (once findings carry it, H5) is not `fault`; interim, filter through `labelKind()`.

### 🟡 M3 — "High repair cost" fires on service bundles
**Severity:** Medium · **Corruption:** No.
`backend/app/Services/NotificationScanner.php:635-665`. Any `WORKSHOP_LOG_ORIGINS` row over the threshold is titled "High repair cost" with body `$m->service_main` — so a periodic service bundle is escalated as an expensive repair, critical at 3×. ADR §8 Phase 1 listed this as a **standalone, ship-immediately, flag-independent fix**; not done. Fix: split the label by reason level / `labelKind($m->service_main)`, or title it "High maintenance cost".

### 🟡 M4 — The vehicle timeline hardcodes every task event as a fault
**Severity:** Medium · **Corruption:** No.
`frontend/src/lib/vehicleTimeline.js:48-50` maps `task_identified`, `task_resolved`, `task_transferred`, `severity_upgraded` … → `'fault'` unconditionally; `:105` filters `types: ['fault']` under the label "Faults"; `:503` renders a "Faults" counter from it. ADR §6 requires reading `kind`. Blocked on H3. Minor related smell at `:159`: `workshopEventType()` tests `t.includes('service')||t.includes('oil')` against `maintenance_type` — safe today only because that column is a closed enum (`routine|breakdown|ins_incident|non_ins_incident|modification|upgrade`).

### 🟡 M5 — The sheet label map covers 22 of 147 labels
**Severity:** Medium · **Corruption:** No.
`backend/config/sheet_label_kinds.php` lists 11 service + 11 context labels; the other **125 default to `fault`**. The default is deliberate and safe (`EventClassificationService.php:151`), but two high-volume labels are wrong against `service_catalog`: `Tire Issues` (215 rows) and `Wheel Alignment Issue` (210 rows). `Periodic Maintenance`, `Oil & Fillter Change`, `Cleaning`, `Freon Recharge Needed` etc. *are* correctly mapped.

### 🟡 M6 — Category labels are counted as peer faults (grain mixing)
**Severity:** Medium · **Corruption:** No.
`sheetIssueTags()` (`MaintenanceAnalyticsService.php:326-341`) flattens `service_main` (system category) and `service_sup` (specific finding) into one list. The fault distribution therefore ranks categories against their own children and counts one visit twice. Measured top of the list: `Body & Exterior 1747`, `Engine 897`, `Electrical 735`, `Interior 586` — all categories, not faults. Known and documented as STILL OPEN; unchanged. Fix: return `{main[], sup[]}` and let each consumer choose a grain.

### 🟡 M7 — `maintenance_reasons.level` files two leak faults as routine
**Severity:** Medium · **Corruption:** No (data is human-curated reference data, but it is wrong).
The 7 `level='routine'` reasons include **`Engine Oil leak`** and **`Fluid Leaks`**. `MaintenanceAnalyticsService::recurringFaults():536` filters `level IN ('critical','minor')` — so genuine, recurring leak faults are **dropped from recurring-fault analysis entirely**. This is the separation failing in the *opposite* direction to everything else in this report. Fix: re-level both to `minor`/`critical`.

### 🟡 M8 — `sheetIssueTags()` unsplit in 9 consumers
**Severity:** Medium (mostly Low) · **Corruption:** No.
`DashboardService.php:1038,1044` (`sheetProblemsForContracts` — labels a car's oil change as its "problem") · `WorkshopEventService.php:388,489` · `WorkshopEventResource.php:25` · `MaintenanceController.php:211,272,322,362` · `VehicleController.php:624,656`. Most render a neutral "Issues" column and are defensible. The exception is `classifyPriority($tags)` (`WorkshopEventService.php:391`, `VehicleController.php:584`), which derives ticket **priority** from a mixed bag of fault, service and bookkeeping words. Only `VehicleController.php:573` splits correctly.

### 🟡 M9 — The findings picker offers 7 service chips from a fault picker
**Severity:** Medium · **Corruption:** No.
`finding_keywords` holds 5 rows with `category_key='routine'`, and 7 of its keywords are also `service_catalog` rows (`air filter`, `battery replacement`, `oil change`, `oil filter`, `tyre rotation`, `wheel alignment`, `wheel balancing`). They render in the same chip grid as faults, in a UI whose API calls them faults (H6). `FindingsPicker.js:216-240` does warn when a monitored routine is picked against the car's live service state — good, but that is a data-quality guard, not type separation. Fix (post-H3): render routine chips in a visually distinct Service group and send `kind: 'service'`.

### 🟡 M10 — "periodic maintenance" is unmatchable by the ontology
**Severity:** Medium · **Corruption:** No.
Verified: `resolve("periodic maintenance")` → **zero matches**, despite `Periodic Maintenance` being both a mapped sheet service label and a `maintenance_reasons` row. Add the wording to `database/seeders/ontology/routine.php`.

### 🔵 L1 — The model guard does not validate `kind` itself
**Severity:** Low · **Corruption:** Theoretical.
`backend/app/Models/MaintenanceTask.php:198-215` returns early when all three catalog FKs are null — which is **100% of current rows**. In that path `kind` is never checked against `MaintenanceTask::KINDS`, and the DB CHECK (`…100300_add_kind…php:78-83`) also permits any string when the FKs are null. `kind = 'banana'` would save. Fix: one `in_array($t->kind, self::KINDS, true)` assertion before the early return.

### 🔵 L2 — No DB-level enforcement in production
**Severity:** Low · **Corruption:** No (app guard covers it).
The `chk_task_kind_catalog` CHECK is present locally (verified on MariaDB 10.4.32) but the migration deliberately swallows the failure on MySQL 8, which refuses a CHECK on a column carrying an FK with a referential action (errno 3823). Production is MySQL 8 → **the constraint does not exist there**. This is a documented, reasoned trade-off (`…100300…php:22-28`), not a defect, but it means the model guard is the *only* enforcement in prod: raw SQL, `DB::table()` updates and `saveQuietly` bypass it. Worth an integrity command in `evidence:health`.

### 🔵 L3 — `kind` is overloaded across six unrelated axes
**Severity:** Low · **Corruption:** No.
`maintenance_tasks.kind` (fault/service/inspection) · `maintenance_line_items.kind` (part/labor) · `maintenance_media.kind` · `keyword_terms.kind` (synonym/misspelling/…) · `maintenances.test_kind` · timeline row `kind` (workshop/workflow) · component read-model `kind` (component/consumable). Every grep for the domain discriminator returns mostly noise, which is itself a maintenance hazard for exactly this audit. No rename recommended now; document it in the ADR.

### 🔵 L4 — One reader enforces unconditionally while all others are flag-gated
**Severity:** Low · **Corruption:** No.
`backend/app/Services/Knowledge/RepairHistoryQueryService.php:193` applies `whereNull('kind')->orWhere('kind', KIND_FAULT)` with **no** `EventKind::enforced()` gate. The direction is correct and null-tolerant, but it means "Previous Similar Repairs" already excludes services while the dashboard beside it does not — the rollout flag no longer describes the whole system. Harmless; align for consistency.

---

## 3. Files affected

**Critical / High (11 files)**
```
backend/.env:119
backend/app/Services/EventClassificationService.php:95-100, 258-266
backend/app/Services/MaintenanceWorkflowService.php:5965, 5990-6006
backend/app/Models/Maintenance.php:508-559
backend/app/Services/RecurringFaultService.php:50, 69, 110
backend/app/Services/MaintenanceTaskService.php:71-84, 91, 98
backend/app/Services/DashboardService.php:1341-1352, 1445-1453
backend/app/Http/Resources/MaintenanceTaskResource.php:33, 163  (+ missing kind)
backend/app/Http/Resources/MaintenanceWorkflowResource.php     (missing kind)
backend/app/Http/Resources/WorkshopEventResource.php:25
backend/app/Services/KeywordOntologyService.php:40-50
backend/app/Http/Controllers/FindingKeywordController.php:205-271
```

**Medium (12 files)**
```
backend/app/Services/MaintenanceForesightService.php:161-163, 704-705
backend/app/Services/MaintenanceWorkflowService.php:5486-5518
backend/app/Services/NotificationScanner.php:635-665
backend/app/Services/MaintenanceAnalyticsService.php:326-341, 536
backend/app/Services/WorkshopEventService.php:388, 391, 489
backend/app/Http/Controllers/MaintenanceController.php:211, 272, 322, 362
backend/app/Http/Controllers/VehicleController.php:584, 624, 656
backend/app/Support/FaultVocabulary.php:70, 80-90
backend/config/sheet_label_kinds.php
database/seeders/ontology/routine.php
frontend/src/lib/vehicleTimeline.js:48-50, 105, 159, 503
frontend/src/components/workflow/FindingsPicker.js:42, 216-240
DB reference data: maintenance_reasons (2 rows)
```

**Low (3 files)**
```
backend/app/Models/MaintenanceTask.php:198-215
backend/database/migrations/2026_07_27_100300_add_kind_to_maintenance_tasks.php:76-89
backend/app/Services/Knowledge/RepairHistoryQueryService.php:193
```

---

## 4. Missing tests

Current coverage is **4 test methods** for the entire separation:
`tests/Feature/EventClassificationTest.php` (2: fault-beats-routine-context, routine-stays-service) · `tests/Unit/EventKindTest.php` (2: flag tracking, `faults()` scope SQL) · `tests/Unit/FindingsVocabularyTest.php` (catalog↔ontology parity — good, but a different invariant).

There are **no model factories at all** (`database/factories/` is empty), which is why classification tests build rows by hand and why coverage stalled.

Missing, in priority order:

| # | Test | Guards |
|---|---|---|
| 1 | A `kind=service` task never enters `RecurringFaultService::flagPossibleRecurrence` / `onFaultConfirmed` | C3 |
| 2 | `resolveLegacyKind` on a non-English fault symptom under a routine ticket → `needs_review = true` | C1 |
| 3 | `confirmRoutineServices` rolls the reminder for a catalog-linked service whose symptom text differs from the reminder label; and does **not** roll for a `kind=fault` task named "Oil Change" | C2 |
| 4 | `topFaults()` excludes `level='routine'` sheet rows (both sources), asserted on seeded data | H2 |
| 5 | `MaintenanceTaskResource` / `MaintenanceWorkflowResource` expose `kind` + `kind_meta` | H3 |
| 6 | Resource `routine_service_type` agrees with the stored `kind` for every fixture | H4 |
| 7 | `syncFromFindings` with a catalog-bearing finding produces `classification_source = 'catalog'` | H5 |
| 8 | `KeywordOntologyService::resolve` with the fault lane excludes `routine` concepts — table-driven over the 10 phrases used in this audit | H6 |
| 9 | **Cross-vocabulary consistency:** every label in `sheet_label_kinds.service` resolves to a non-failure category in `FaultVocabulary`, and every `service_catalog.name` resolves to `service` via `labelKind()` — a file-only test, no DB | H7, M5 |
| 10 | `labelKind()` / `splitLabels()` unit coverage (currently **zero** — no test file references either method) | M5 |
| 11 | Model guard rejects `kind` outside `KINDS`, and rejects two catalog FKs set at once | L1 |
| 12 | Frontend: `visitFaults` / `isServiceOnlyVisit` (`faultCategories.js`) — no test file exists | M4 |

---

## 5. Architectural inconsistencies

1. **Two grains, one discriminator.** `kind` lives on `maintenance_tasks` (event grain). The sheet — 27k rows, ~99% of history — has no task rows, so it can never carry `kind`. Every sheet consumer must fall back to a label-grain or visit-grain classifier, which is the structural cause of H7. The ADR does not name a label-grain owner; `EventClassificationService::labelKind()` became one de facto, with a single consumer.

2. **Structural separation was specified but not delivered.** ADR §0.5: *"Separation is structural, not conventional … the only doors into the data are type-aware."* `Vehicle::faults()/services()/inspections()` exist (`Vehicle.php:246-260`) and are used by **zero** production callers — every gated consumer instead writes an inline `when(EventKind::enforced(), …)`. The doors were built; nobody walks through them.

3. **The rollout flag has become a third state.** `off`/`shadow`/`enforced` was meant to gate *readers*. In practice some readers enforce unconditionally (L4), some are gated (H1), some never learned about `kind` (H2, M1–M3), and two **writers** classify by text with no gate at all (C2, C3). "What does the system currently believe?" has no single answer.

4. **Classification is a guess everywhere it matters.** 100% `classification_source = 'resolver'`. The catalogs — the ADR's designated source of truth — are populated (19 services, 64 faults, 5 inspection types, cleanly disjoint: **zero** name collisions between `service_catalog` and `fault_catalog`) and referenced by 4 task rows total.

5. **The ontology models the distinction better than the application does.** `database/seeders/ontology/routine.php:12-18` is the clearest statement of the boundary anywhere in the codebase — *"SERVICE WORDING IS NOT FAULT WORDING… A single symptom phrase leaking into this file would route real breakdowns into the routine lane"* — and the vocabulary is built accordingly from completed-job wording. Yet the service that queries it offers no way to ask for one lane (H6).

6. **Documentation lags reality.** `docs/Service-vs-Fault-Domain-Separation.md:375` still reads *"Status: PROPOSED — awaiting review. No code has been written."* while §3 of the header says "APPROVED — Phase 0 in build". Phase 0 is substantially built. The flag was also renamed `SERVICE_FAULT_SPLIT` → `EVENT_KIND_MODE` without updating §0.6, §8 or §9.

---

## 6. Recommendations

**Gate 1 — stop the writes (before any flag flip).**
1. C3: type-guard both `RecurringFaultService` entry points. One line, unconditional.
2. C1: rule 3 returns `needs_review = true`; extend fault evidence to Arabic (or delegate to the ontology). Re-run `events:backfill-kind`, then review the queue.
3. C2: `confirmRoutineServices` reads `kind` + `service_catalog.service_reminder_type`; text matching becomes the `service_catalog_id IS NULL` fallback.
4. L1: assert `kind ∈ KINDS` in the saving guard.

**Gate 2 — one vocabulary.**
5. H7: `EventClassificationService` becomes the sole label-grain authority; `FaultVocabulary` and `Maintenance::routineServiceTypeFor` delegate to it. Export the map to the frontend, deleting the hand-maintained copy in `FindingsPicker.js`.
6. M5 + M7: add `Tire Issues` / `Wheel Alignment Issue` to `sheet_label_kinds.service`; re-level `Engine Oil leak` and `Fluid Leaks` off `routine`.
7. H2: exclude routine from Dashboard source B via that vocabulary.

**Gate 3 — make it visible.**
8. H3: ship `kind` / `kind_meta` / `catalog` on the three resources.
9. H4: resource reads `kind`, not text.
10. M4 + M9: timeline reads `kind`; picker groups services distinctly.
11. M3: standalone notification-label fix (independent of everything else — ship any time).

**Gate 4 — make it true.**
12. H5: findings carry `{kind, catalog_id}`; `syncFromFindings` calls `classifyFromCatalog()`. This is the change that converts the ADR from aspiration to fact.
13. H6: `kinds` / `exclude_categories` option on `resolve()`; five fault-lane callers adopt it; `kind` on every match in the payload.

**Gate 5 — then, and only then, flip.**
14. H1: `EVENT_KIND_MODE=enforced`, after capturing before/after figures for the numbers ADR §9 predicts will move (Top Faults down, health/reliability up).
15. Backfill tests 1–12 from §4, plus model factories so they can be written at all.
16. M1, M2, M6, M8, L4, and the ADR status/flag-name correction as follow-up.

**Do not flip the flag first.** Today, in shadow, a misclassified fault (C1) is still *counted* as a fault, so the figures are wrong-but-complete. Enforced, that same row becomes invisible — with no review queue entry to find it by. C1 and C3 must land first.

---

## 7. Overall verdict

**The Service/Fault separation is correctly designed, partially built, structurally sound where built — and not yet true of the system as a whole.**

What is genuinely right, and why:

- **The schema and the model are correct.** `kind` + three mutually-exclusive typed FKs, a `saving` guard (`MaintenanceTask.php:198-215`) that rejects any FK/kind mismatch, a DB CHECK where the platform allows it, and a `creating` hook (`:152`) ensuring no row is ever born unclassified. The catalogs are clean and disjoint — 19 services, 64 faults, **zero** name collisions, **zero** `routine` rows in `fault_catalog`.
- **Vehicle Health is exemplary and should be the template.** `CarStatusService::vehicleIntelligence()` derives `$faultTasks` once (`:647`) and threads it into health, KPIs, fault analytics, reliability and fault history — while deliberately passing the *unfiltered* `$tasks` to `costBreakdown()` (`:695`), because a service costs money even though it is not a fault. That is precisely the ADR §0 distinction, implemented with the reasoning written down beside it.
- **The ontology models the boundary with real rigour** — `routine.php` builds service vocabulary exclusively from completed-job phrasing so symptoms cannot leak into the routine lane, and the matcher ranks correctly on all ten probes run during this audit.
- **The vehicle-profile chart is honest end to end** — backend splits (`VehicleController.php:573`), API ships `fault_tags`/`service_tags`/`context_tags`, frontend counts only faults and excludes service-only visits without inflating "Unspecified" (`faultCategories.js:64-88`).
- **Recurrence chains and suggested checks are type-aware by construction** (`VehicleFaultRecurrenceService.php:256`, `VehicleSuggestedChecksService` sourcing repeat-faults and scheduled-service independently).

What is not true yet: the catalog is not the source of type (0/108 rows), the API does not expose the type, two write paths classify by text and can corrupt vehicle service anchors and recurrence records, seven vocabularies answer the same question differently, and the whole read-side rollout is switched off.

**Release recommendation: NOT READY.** The three Critical findings are all *write* paths — they persist wrong classifications, wrong service anchors and wrong recurrence records, and no read-side flag suppresses them. C1 has already produced one confirmed misclassification in a 108-row dataset. Fix Gates 1–2, then this is defensible as a shadow-mode ship; Gates 3–5 are required before the separation can be described to users as real.

---
---

# Part II — Remediation & Re-Audit (2026-08-03)

Every finding above was worked. This part records what changed, what it was verified against, and what the second pass through the same checklist found.

## 8. Fixes, by finding

### 🔴 C1 — a fault stored as a Service, unflagged · FIXED

**Root cause.** Two holes that only bite together. `fault_evidence_keywords` was English-only, so no rule ever tested a non-English symptom; and rule 3 (ticket context) returned `needs_review = false` — asserting confidence in an answer derived entirely from the VISIT, never from the finding.

**Fix.** (a) Arabic failure vocabulary added to `fault_evidence_keywords`. (b) A new rule 2.6 asks the ontology — the platform's real automotive vocabulary, 289 Arabic terms — which lane the wording belongs to, at a ≥70 confidence floor, wrapped so an unseeded knowledge platform simply means "no opinion". (c) Rule 3 now returns `needs_review = true` unless the routine signal came from the finding itself (`category_key = 'routine'`, i.e. the inspector picked a routine chip).

**Verified.** `صوت باب امامي` → `fault` (was `service`). `الموتر يحما`, `اهتزاز عند الفرملة` → `fault`. `Oil Change` on a routine ticket → `service`, confident. Unknown wording → `service` + `needs_review`. Live row #144 repaired by re-running `events:backfill-kind` (21 rows changed; second run 0).

### 🔴 C2 — vehicle service anchors written from symptom text · FIXED

**Root cause.** `confirmRoutineServices()` — the single place a maintenance action reaches the vehicle master record — asked `Maintenance::serviceTypeForSymptom($task->symptom)` while `kind` and `service_catalog_id` sat unread on the same row.

**Fix.** New `MaintenanceTask::serviceReminderType()` is the one answer to "does completing this roll a service forward?": `kind` must be `service`, then `service_catalog.service_reminder_type`, with the text matcher surviving only as the legacy shim for a service with no catalog id. `confirmRoutineServices()`, `needsServiceReinspection()` and the API resource all read it, so the badge shown and the write performed can no longer disagree.

**Verified.** A `kind=fault` task named "Oil Change" → `null` (was `oil_change` — it would have stamped the car as serviced). A catalog-linked "Brake Pads (service)" → `brake_pads` (was `null` — the reminder never rolled). All 7 existing service rows unchanged.

### 🔴 C3 — recurrence engine not type-guarded on its subject · FIXED

**Root cause.** `detectPriorFix()` filtered the PRIOR task by kind, and only when the rollout flag was enforced; the SUBJECT was never checked, and `syncFromFindings()` calls `flagPossibleRecurrence()` for every promoted finding.

**Fix.** `isRecurrenceEligible()` gates all three public entry points, and the prior-task filter is now unconditional. Both are deliberately NOT behind `EventKind::enforced()` — this path writes (`recurrence_flagged`, `recurrence_previous_task_id`, `recurring_fault_reviews`), and a rollout flag must never decide whether a stored fact is correct.

**Verified.** Second oil change on the same car, 3 weeks apart: `detectPriorFix` → null, nothing written, no review opened. A genuine repeat A/C fault is still detected. A prior *service* no longer "confirms" a returning fault.

### 🟠 H1 — flag still `shadow` · UNCHANGED, DELIBERATELY

Not flipped. See §15 R1 — flipping is now safe but is a business decision with visible number movement.

### 🟠 H2 — Top Faults' sheet half ungated · FIXED (and my impact figure corrected)

**Fix.** Both `topFaults()` and `faultCars()` now restrict the sheet source to `EventClassificationService::faultReasonIds()`.

**⚠️ Correction to Part I.** The audit claimed "86 of 2,813 reason-classified rows are routine and count as faults". That number was wrong, and wrong for exactly the reason finding M7 names: I used `maintenance_reasons.level` as a type proxy. Those 86 rows are 85 × `Tire Issues` + 1 × `Fluid Leaks` — **both genuine faults** that the sheet merely rates low-priority. The seven truly non-fault reasons have **zero** linked rows today. So the gate was a real structural hole (any future service-reason row would have leaked in) with **zero current data impact**: Top Faults numbers are unchanged, verified.

### 🟠 H3 — API never exposed the type · FIXED

`MaintenanceTaskResource` now ships `kind`, `kind_meta` (from the single `KIND_META` source), `catalog` (`{id, slug, name, kind}`, serialised only when eager-loaded so there is no N+1), `needs_review` and `classification_source`. `MaintenanceWorkflowResource` inherits all of it through the task collection. `WorkshopEventResource` and the workshop-event snapshot ship `fault_tags` / `service_tags` / `context_tags` beside the untouched raw `issues`. The activity feed ships `task_kind`. Both eager-load sets in the workflow controller were extended so the catalog costs one query for a whole board.

### 🟠 H4 — API re-derived type from text at read time · FIXED

`routine_service_type` and `service_confirmation` now read `serviceReminderType()`. The resource no longer runs a classifier at serialisation time.

### 🟠 H5 — the catalog was not the source of type · FIXED

**Fix.** New `EventClassificationService::classifyFromFinding()`: an explicit `kind` (+ catalog ref) wins; otherwise an EXACT match of the finding text against `service_catalog` or `fault_catalog` **is** a catalog pick — the picker already sends catalog wording, only the plumbing was missing. `syncFromFindings()` merges the result, so recognised findings are born `classification_source = catalog` with the catalog id set. The audit trail now names the type it actually created ("Service identified: Oil Change"). `addFindings` validates `kind`/`catalog_id`/`catalog_slug` and rejects an incoherent pair with a 422 naming the finding.

**Verified.** A report of `Oil Change` + `Brake noise (squeal / grind)` + unknown wording produces `service/catalog/id=1`, `fault/catalog/id=9`, and `resolver + needs_review` respectively.

### 🟠 H6 — AI matching could not be asked for faults only · FIXED

**Fix.** `KeywordOntologyService::resolve()` gains a `kinds` option (over-fetch → filter → trim, so a caller asking for 1 fault gets the best FAULT, not "the best match if it happens to be one"), and every result carries its own `kind`. Five fault-lane callers adopted it: `ComplaintInterpreter`, `CausalReasoner`, `FleetRetriever`, `RepairRecommendationService` (which also gained the score floor it never had) and `FleetEvidenceService`. `SemanticStage` reads the lane off the result instead of re-querying, to avoid over-fetching inside an already-recursive path. `/finding-keywords/resolve` ships `kind` per match and no longer announces every hit as a "fault".

**Additional defect found while fixing this.** Typing a concept by CATEGORY alone was not enough: the ontology files by SYSTEM, so `Tyre Rotation`, `Wheel Alignment` and `Wheel Balancing` live in `tyres` next to punctures and were eligible as diagnoses. `conceptKind()` now consults the service catalog first — the ADR's own "catalog is the source of type" applied to the knowledge layer. 10 concepts now type as service.

**Verified.** `"car does not start battery dead"` fault lane → `Battery / won't start` only (the service at 76 is gone). `"tyre rotation"`, `"wheel balancing"`, `"oil change"` return no planned work. `"brakes squealing"` → `Brake noise` 84, unaffected.

### 🟠 H7 — seven parallel vocabularies · FIXED (consolidated to one)

`EventClassificationService` is now the single owner, answering at four grains: `labelKind()` (sheet label) · `conceptKind()` (ontology concept) · `isServiceCategory()` (category) · `faultReasonIds()` (workshop reason). `FaultVocabulary` delegates the service half of `isFailureCategory()` and keeps only the COSMETIC rule, which is a genuinely different axis (a scratch is an unplanned defect that carries no mechanical-failure signal). `NON_FAILURE_CATEGORIES` is deprecated in place. `Maintenance::routineServiceTypeFor()` is demoted to what it actually is — a reminder bridge, not a type classifier — reached only through `serviceReminderType()`.

**A latent bug the consolidation exposed.** `labelKind()` fell back to `service_catalog` for names not in the config list, so on any database where that table was unseeded — including every test run — `"oil change"` typed as a **fault**. The routine-service keywords are now declared in the label map directly, so the vocabulary answers from config alone with no hidden dependency on database state. Caught by a new test, not by inspection.

### 🟡 M1–M10, 🔵 L1–L4 · all FIXED

| | Fix |
|---|---|
| **M1** | Foresight excludes planned work at LABEL grain (`splitIssues()` now returns fault labels only) and the coarse `visit_context <> routine` visit filter is gone — so a real fault found on a routine visit is no longer dropped, and a service label on a null-context visit no longer counts. |
| **M2** | Chronic Fault Watchdog filters its tags through `labelKind()`; picking "Oil Change" no longer warns "repaired 4 times before". |
| **M3** | `costHeadline()` types the visit: "High repair cost" / "High service cost" / "High maintenance cost". |
| **M4** | `eventKind()` reads `task_kind` for task-scoped events; a logged service files under `routine`, not `fault`. Falls back to the legacy map for older payloads. |
| **M5** | **Revised.** Part I called `Tire Issues` and `Wheel Alignment Issue` mis-bucketed. Re-reading all 125 fault-defaulted labels, they are fault REPORTS ("Issue") and were already correct — I over-called it. What was genuinely missing were the tyre/wheel SERVICE names in both spellings (`Tyre`/`Tire` — `TextNormalizer::key` does not fold them), plus `Brake Pads (service)`, `A/C Service`, `General Service`. Added. Exact matching means the fault wordings that contain them are untouched, which a test now pins. |
| **M6** | `sheetIssueTagsByGrain()` returns `{main, sup}` with cross-column duplicates dropped to the finer grain, so a consumer that counts can pick one. `sheetIssueTags()` keeps the flat shape for display. |
| **M7** | **Revised.** `level` faithfully mirrors the sheet's own column — the data is not wrong, the READER was. Nothing was rewritten; `recurringFaults()` and the dashboard now type the reason by NAME through the one vocabulary. `Engine Oil leak`, `Fluid Leaks` and `Tire Issues` are correctly kept as faults; `ACC Programming` (which the sheet rates *critical*) is correctly excluded as a service. |
| **M8** | The fault filter moved INSIDE `classifyPriority()` rather than into each of its eight call sites — one place to change, nothing to remember. A wholly non-fault tag list falls through to the raw list rather than to silence. |
| **M9** | Service categories render a "Service" chip in the findings picker, from a named constant mirroring the backend authority. EN + AR strings added; `check:i18n` passes. |
| **M10** | `Periodic Maintenance` added to the ontology (13 synonyms, 6 misspellings, Arabic) — the commonest planned-work phrase in the corpus previously matched nothing. Declared `understanding_only`: the matcher must know it, the picker must not offer a whole visit as one finding. |
| **L1** | The saving guard validates `kind ∈ KINDS` BEFORE the all-catalogs-null early return (which is most rows). `protected $attributes` seeds `kind` so the guard is not defeated by Laravel firing `saving` before `creating`. |
| **L2** | New `php artisan events:kind-integrity` — verifies every invariant the CHECK constraint would, for the MySQL 8 production databases where the constraint cannot be installed. Read-only, exits non-zero. |
| **L3** | Documented in `EventKind`'s docblock and ADR §11 rather than renamed; a rename across six unrelated `kind` axes is churn with no correctness gain. |
| **L4** | Resolved by stating the policy instead of changing behaviour: `EventKind` now documents exactly what the flag governs (task-grain fault *analytics*) and what is unconditional (writes, classification, vocabulary). `RepairHistoryQueryService` was already on the right side of that line. |

## 9. Additional defects found during remediation

Neither was in Part I; both were found by doing the work.

1. **`FaultVocabulary` alias matching was substring-based**, so the two-letter alias `ac` matched inside unrelated words: **`Accessories` (186 rows)**, `Accessories & Mods`, `Glass Chip / Crack`, `Mirror Glass Crack` and `LED / Light Accessory Issue` all resolved to the **A/C category** — accessory and glass damage joining A/C fault chains in the recurrence engine and the foresight view. Now matched on whole words with earliest-position-wins, which additionally fixes the head noun: `AC Not Cooling` is an A/C fault (was filed under fluids), while `Cooling System Issues` correctly stays fluids.
2. **`labelKind()` depended on a seeded database** — see H7 above.

## 10. Files changed

**Backend — 30 files** (excluding concurrent work by others in the same tree):

```
app/Console/Commands/EventKindIntegrity.php                      NEW
app/Http/Controllers/FindingKeywordController.php
app/Http/Controllers/MaintenanceController.php
app/Http/Controllers/MaintenanceWorkflowController.php
app/Http/Controllers/VehicleController.php
app/Http/Resources/MaintenanceTaskResource.php
app/Http/Resources/WorkshopEventResource.php
app/Models/MaintenanceTask.php
app/Ontology/Matching/Stages/SemanticStage.php
app/Ontology/Reasoning/CausalReasoner.php
app/Ontology/Reasoning/ComplaintInterpreter.php
app/Ontology/Retrieval/FleetRetriever.php
app/Services/ActivityFeedService.php
app/Services/DashboardService.php
app/Services/EventClassificationService.php                      (the consolidation)
app/Services/FleetEvidenceService.php
app/Services/KeywordOntologyService.php
app/Services/Knowledge/RepairRecommendationService.php
app/Services/MaintenanceAnalyticsService.php
app/Services/MaintenanceForesightService.php
app/Services/MaintenanceTaskService.php
app/Services/MaintenanceWorkflowService.php
app/Services/NotificationScanner.php
app/Services/RecurringFaultService.php
app/Services/WorkshopEventService.php
app/Support/EventKind.php
app/Support/FaultVocabulary.php
config/maintenance_findings.php
config/sheet_label_kinds.php
database/seeders/ontology/routine.php
tests/Feature/EventClassificationTest.php                        (+2 tests)
tests/Unit/ServiceFaultSeparationTest.php                        NEW
tests/Crud/ServiceFaultWritePathTest.php                         NEW
```

**Frontend — 4 files:** `lib/vehicleTimeline.js` · `components/workflow/FindingsPicker.js` · `i18n/labels.js` (2 keys) · `lib/serviceFaultSeparation.test.js` (NEW).

**Docs — 2:** this file · `docs/Service-vs-Fault-Domain-Separation.md` (§11 status, flag rename, amendments).

## 11. Database & migration changes

**No schema migrations were written, and none were needed** — the Event Type layer's columns, FKs, indexes and CHECK were already in place from Phase 0. Changes to persisted data:

| Change | Mechanism | Result |
|---|---|---|
| Re-classified historical tasks under the corrected resolver | `php artisan events:backfill-kind` (pre-flight `db:backup`: `laravel-20260803-062355.sql`) | 21 of 108 rows changed · fault 101 / service 7 · `needs_review` 20 → 0 · second run 0 changes (idempotent) |
| `Periodic Maintenance` ontology concept | `db:seed --class=FaultOntologySeeder` | 105 → 106 concepts · 2,170 terms |
| Reference data (`maintenance_reasons`) | **untouched by design** | the sheet's own classification is preserved; the readers were fixed instead |

## 12. Behaviour changes

**Intended, user-visible:**

- Recurring-fault flags and review cases no longer open on repeated services.
- A fault named like a service no longer stamps the vehicle's service record; a catalog-linked service whose wording differs from its reminder label now correctly rolls it.
- "High repair cost" alerts on a service visit now read "High service cost".
- The vehicle timeline files service events under Routine; the Faults counter drops accordingly.
- The findings picker marks planned-service categories.
- Complaint interpretation, causal reasoning, repair recommendation and fleet evidence can no longer return a scheduled service as a diagnosis.
- Foresight now sees faults found on routine visits (previously dropped) and no longer counts service labels on standard visits (previously counted). Net direction depends on the car.

**Deliberately unchanged:** Top Faults figures (verified: 0 rows differ) · all cost, spend and profitability numbers · the maintenance workflow, its states and permissions · `EVENT_KIND_MODE=shadow`.

**API additions are purely additive** — every legacy field kept its name and shape.

## 13. Re-audit — second pass over the full checklist

| Layer | Result |
|---|---|
| DB schema / migrations | ✅ Invariants verified live by `events:kind-integrity` (7/7 pass). CHECK present on MariaDB; the command covers MySQL 8 where it cannot be. |
| Models & relationships | ✅ Guard validates the discriminator itself; `KIND_CATALOG_RELATIONS` added; `serviceReminderType()` is the one reminder resolver. Test pins that no two kinds share a catalog column. |
| Enums & constants | ✅ One authority, four grains. `FaultVocabulary::NON_FAILURE_CATEGORIES` deprecated in place for compatibility. |
| Ontology / taxonomy | ✅ 106 concepts; 10 correctly typed as service; `Periodic Maintenance` matchable (96) and declared `understanding_only`; `findings:vocabulary-check` passes. |
| AI matching & classification | ✅ Lane filter + `kind` on every match; 5 fault-lane callers converted; verified on the same probes as Part I. |
| Repair Capture | ✅ Unchanged — already separated findings into two statement types. |
| Maintenance workflow | ✅ Write paths type-guarded; findings validated; audit trail names the type. |
| Component replacement | ✅ Unchanged — `service_records` (consumables) vs `component_events` is a separate, correct axis. |
| Vehicle Health | ✅ Unchanged — was already exemplary; `$faultTasks` vs all-tasks-for-cost still holds. |
| Recommendation engine | ✅ Verified by execution: `extractCategories` keeps `routine` as its own category and never maps a service label onto a fault category (`Oil & Fillter Change` → `[routine]`, not `[engine]`). |
| Search & autocomplete | ✅ `/finding-keywords/resolve` labels each lane; picker marks services. |
| APIs / Controllers / Resources | ✅ `kind`, `kind_meta`, `catalog` shipped; no read-time re-derivation remains. |
| Policies · Jobs · Listeners | ✅ Re-confirmed zero type logic (correct by absence — none of them classify). |
| Reports / Dashboards / Analytics | ✅ All eight `classifyPriority` sites typed via one internal filter; Top Faults + faultCars gated; foresight at label grain; `recurringFaults` reason-typed. |
| Seeders | ✅ Catalogs disjoint (0 shared names, 0 routine rows in `fault_catalog`). |
| Factories | ⚠️ Still none — see risk R3. |
| Tests | ✅ 443 unit/feature, 7 new DB-backed write-path tests, 9 new frontend tests. |
| Frontend | ✅ Timeline, donut and picker all read the type. `check:i18n` passes (2,570 EN = 2,570 AR). |
| Documentation | ✅ ADR status corrected, flag rename recorded, three amendments captured. |

## 14. Test results

| Suite | Result |
|---|---|
| `php artisan test` (unit + feature) | **443 passed**, 1,446 assertions |
| `phpunit -c phpunit.crud.xml` (real MySQL) | ⚠️ **NON-DETERMINISTIC — see §17.** Consecutive full runs of the *same* code gave 10 / 25 / 91 / 221 errors. The single stash-and-rerun comparison reported here was therefore much weaker evidence than it was presented as. Every suite that fails in a full run passes when run in isolation. |
| `tests/Crud/ServiceFaultWritePathTest` (NEW) | **7 passed**, 27 assertions |
| `tests/Unit/ServiceFaultSeparationTest` (NEW) | **13 passed** |
| `tests/Feature/EventClassificationTest` (+2 new) | **4 passed** |
| Frontend `react-scripts test` | **148 passed** (1 pre-existing suite failure: `App.test.js` cannot resolve `./auth/AuthContext`, unrelated) |
| `frontend/src/lib/serviceFaultSeparation.test.js` (NEW) | **9 passed** |
| `events:kind-integrity` · `findings:vocabulary-check` · `events:backfill-kind` | all pass; backfill idempotent |

**Pre-existing failures, explicitly not caused by this work** (verified by stash-and-rerun): `AssetLayerPhase2Test` ×8 · `OdometerDiscrepancyNotifyTest::test_backward_transfer_reading_is_hard_blocked` · `PauseResumeMaintenanceTest::test_renting_an_in_shop_car_pauses_the_ticket_instead_of_closing_it`.

## 15. Remaining risks

**R1 · The flag is still `shadow`, deliberately.** Every correctness fix is unconditional and live. What `enforced` adds is narrowing task-grain fault *aggregates* to `kind = fault`. It is now safe to flip — C1/C3 are closed, so no fault will go invisible — but it moves visible dashboard numbers (ADR §9: Top Faults down, health/reliability up) and that is a business call. Recommendation: capture before/after screenshots, then set `EVENT_KIND_MODE=enforced`.

**R2 · Asking the fault lane about a service phrase still returns a weak fault.** `resolve("oil change", kinds:['fault'])` yields `Oil leak` at 66. Every fault-lane caller now has a score floor (55–70) and all receive complaints or symptoms, not service names, so this is not reachable in practice — but it is a property of a lane filter rather than a bug that was fixed.

**R3 · There are still no model factories.** The new DB-backed tests build rows by hand. This is the single biggest obstacle to deeper coverage and is unchanged by this work.

**R4 · `MaintenanceAnalyticsService::recurringFaults()` is dead on current data** — the `maintenances → contracts → maintenance_reasons` join yields **0 rows** fleet-wide, before any type filter. Pre-existing and unrelated to typing, but it means that method's correctness is unverifiable against live data and it should be either fixed or retired.

**R5 · Legacy provenance stays `resolver`.** 101 historical rows remain resolver-classified, which is honest — nobody picked a catalog for them in 2025. New writes are `catalog`. The `EventClassificationReview` queue is the path to upgrading history, and it is now empty (`needs_review = 0`) because the ontology resolves the previously-ambiguous rows confidently.

## 16. Final verdict

**READY FOR RELEASE** (in `shadow`; flipping to `enforced` is a separate, now-safe business decision).

Every **Critical** and **High** finding is resolved and verified by execution against real data, not by inspection:

- **C1, C2, C3** — the three data-corrupting write paths — are closed, each with a DB-backed regression test, and the one live bad row was repaired.
- **H2, H3, H4, H5, H6, H7** are implemented and verified; H1 is a deliberate, documented hold.
- All ten **Medium** and four **Low** findings are addressed, two of them (M5, M7) by correcting the audit's own reasoning rather than implementing a wrong fix.
- Two further defects found during the work are fixed, one of which (`Accessories` → A/C) was silently corrupting fault grouping for 186 rows.
- The seven parallel classifiers are one, with four documented grains and no duplicated rule.
- No regressions: 443 + 148 tests green, and the 10 pre-existing CRUD failures were proven pre-existing by stash-and-rerun rather than assumed.

What keeps this short of "finished" rather than short of "releasable": the type-first intake form (ADR §7 / Phase 3) does not exist — findings are typed from the catalog by NAME instead, which achieves `classification_source = catalog` without it. That is a feature gap, not a correctness gap, and the separation is now enforced structurally at every layer beneath it.

---

## 17. Post-flip addendum (2026-08-03) — `EVENT_KIND_MODE=enforced`

The flag was flipped. `backend/.env:119` now reads `EVENT_KIND_MODE=enforced`.

### Measured impact on live data

| Figure | shadow | enforced |
|---|---|---|
| Top Faults — total counted events | 2,743 | **2,737** |
| — sheet half | 2,652 | 2,652 (unchanged: already typed unconditionally) |
| — system (task) half | 91 | **85** |
| Tasks in fault analytics | 108 | **101** (7 services excluded) |

The 7 excluded events: 4 × Oil Change, Tire Change, Battery Replacement, Wheel alignment.

Per-car effect on the 7 vehicles holding a service task: health unchanged on all 7; `closed_faults` drops
by 1–2 on 6 of them; reliability unchanged on 6.

### ⚠️ ADR §9's prediction is wrong in one direction — and the new number is the right one

§9 told stakeholders that "Health / Reliability **rise** for cars whose history was padded with
services." Reliability can in fact **fall**: vehicle 3741 went 6 → 0.

That is not a regression. `reliabilityScore()` computes a recurrence rate as
`repeated tasks / all tasks`. Excluding a *non-repeating* service shrinks the denominator while the
numerator holds, so the measured recurrence rate rises and the score drops. Previously, planned services
were **diluting** the recurrence rate and making chronically-repeating cars look more dependable than
they are. The post-flip figure is the honest one: recurrence is now "repeated faults ÷ faults", not
"repeated faults ÷ (faults + oil changes)".

Communicate this before anyone reads a falling reliability score as a new problem.

### ⚠️ Correction: the CRUD suite is non-deterministic

Part I and §14 reported "10 pre-existing failures, 0 new — proven by stash-and-rerun". That claim was
overstated. Consecutive full runs of identical code produce wildly different results:

| Mode | Full-run error counts observed |
|---|---|
| `enforced` | 10 · 25 · 221 · 91 |
| `shadow` | 21 · 29 |

The instability is **independent of this flag** — `shadow` is equally erratic — so it is pre-existing and
not caused by the separation work. `CrudTestCase` uses `RefreshDatabase`, but suites that fail inside a
full run (`AssetLayerPhase1Test`, `PartSpendWindowTest`, `AssetLayerPhase2Test`) **pass cleanly when run
in isolation** (23/23, 6/6), which points at cross-test state leaking in the shared `laravel_test`
schema rather than at any product defect.

**Consequence for this audit:** the CRUD suite cannot currently prove the absence of regressions. The
evidence that does hold is deterministic and was re-run under `enforced`:

- `php artisan test` — **457 passed**, 1,471 assertions
- `php artisan events:kind-integrity` — 7/7 invariants pass
- `findings:vocabulary-check`, `events:backfill-kind` (idempotent) — pass
- frontend — 148 + 9 pass
- the failing CRUD suites pass in isolation

**This is now the top item on the remediation backlog** (supersedes R3): the CRUD suite needs its
isolation fixed before it can gate anything. Until then it is a smoke test, not a regression gate.

### Rollback

One line, no deploy and no data change: set `EVENT_KIND_MODE=shadow` in `backend/.env` and run
`php artisan config:clear`. Nothing written while enforced depends on the flag — classification, the
write guards and the vocabulary are unconditional by design.
