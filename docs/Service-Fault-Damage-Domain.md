# ADR: Damage as a First-Class Operational Kind

**Date:** 2026-08-03 · **Status:** IMPLEMENTED
**Supersedes the two-kind model in** `docs/Service-vs-Fault-Domain-Separation.md`
**Companion:** `docs/Service-Fault-Separation-Audit.md`

---

## 1. The question that produced this

> "Rim Scratch — this is fault??"

The system gave two answers. The Top Faults dashboard counted it (Body & Interior was the fleet's **#1
fault at 862**). The recurrence engine and foresight excluded it, via a category rule that called
`bodywork` and `interior` "cosmetic".

Both answers were defensible and neither was right, because the model had two buckets for three things.

## 2. Decision

A maintenance event is one of **four** kinds. Three are operational, one is procedural:

| `kind` | | Nature | Recurring means | Reliability | Fault stats | Cost | Billable |
|---|---|---|---|---|---|---|---|
| `service` | 🔵 | We planned this work | normal | no | no | yes | no |
| `fault` | 🔴 | The vehicle failed | a reliability signal | **yes** | **yes** | yes | no |
| `damage` | 🟣 | Something was done TO it | something about **drivers** | no | no | yes | **yes** |
| `inspection` | 🟨 | We looked at it | nothing | no | no | neutral | no |

**Damage is not a flavour of fault.** It is unplanned, like a fault, but it says nothing about whether
the car is reliable, and it carries a commercial life a fault does not: a liable party, a renter to
bill, an insurance claim.

## 3. Why a catalog, not a category rule

The tempting shortcut — *"`bodywork` and `interior` are damage"* — is wrong in both directions, and
measurably so:

**It would mislabel real faults.** The `interior` category holds `Dashboard fault`, `Door lock fault`,
`Interior light fault`, `Seat adjustment fault`, `Infotainment / screen issue` and `Water leakage into
cabin`. `bodywork` holds `Rust / corrosion` — the car deteriorating, not somebody hitting it. That is
seven concepts a category rule silently deletes from reliability.

**It would miss real damage.** A kerbed rim files under `tyres`, not `bodywork`.

Those categories are **systems** (where on the car), not **types** (what happened). So damage is typed
**per concept**, from `damage_catalog`, exactly the way service is typed from `service_catalog`.

There is deliberately **no `isDamageCategory()`** to pair with `isServiceCategory()`, and a test asserts
its absence so the shortcut cannot be reintroduced.

### The membership test

> A row is DAMAGE when an **external event** caused it — an impact, a kerb, a stone, a careless renter,
> vandalism. If the car did it to itself (wear, corrosion, a component failing), it is a **FAULT**,
> because it *is* evidence about the vehicle's condition.

## 4. Architecture

```
                       EventClassificationService
                    ── the ONE authority, four grains ──
                                    │
   ┌────────────────┬───────────────┼────────────────┬──────────────────┐
   │ labelKind()    │ conceptKind() │ isServiceCat() │ faultReasonIds() │
   │ sheet label    │ ontology      │ category       │ workshop reason  │
   └────────────────┴───────────────┴────────────────┴──────────────────┘
                                    │
        ┌───────────────┬───────────┴───────┬──────────────────┐
        ▼               ▼                   ▼                  ▼
  service_catalog  fault_catalog     damage_catalog     inspection_types
    kind=service     kind=fault        kind=damage       kind=inspection
        └───────────────┴───────────────────┴──────────────────┘
                                    │
                        maintenance_tasks.kind
                  + exactly ONE matching *_catalog_id
                  (model guard + DB CHECK where supported)
```

**Reliability is a named concept, not a hard-coded kind.** Analytics filter on
`MaintenanceTask::RELIABILITY_KINDS` via `affectsReliability()` / `scopeAffectingReliability()` /
`reliabilityKindsForMode()`, so "what counts as evidence about the car" is one edit, not a hunt.

### Two exclusion rules, deliberately different

| Kind | Excluded from reliability | Why |
|---|---|---|
| `service` | only when `EVENT_KIND_MODE=enforced` | services HAVE been counted historically; the flag exists so the change is comparable before/after |
| `damage` | **always, in every mode** | damage is a NEW kind — no reader ever counted a `damage` row, so there is no previous behaviour to stage. A flag deciding whether a kerbed rim is a reliability signal would be inventing a wrong mode. |

## 5. What changed, by layer

| Layer | Change |
|---|---|
| **Database** | `damage_catalog` (27 rows). `maintenance_tasks.damage_catalog_id` FK. `chk_task_kind_catalog` rebuilt for four kinds. |
| **Models** | `DamageCatalog`. `MaintenanceTask`: `KIND_DAMAGE`, `KINDS`, `KIND_META`, `KIND_CATALOG_FK`, `KIND_CATALOG_RELATIONS`, `RELIABILITY_KINDS`, `reliabilityKindsForMode()`, `damageCatalog()`, `scopeDamages()`, `scopeAffectingReliability()`, `isDamage()`, `affectsReliability()`, `damage_catalog_id` fillable. |
| **Enums / config** | `config/damage_catalog.php` (the authority). `sheet_label_kinds.damage`. |
| **Classification** | `classifyFromFinding()`, `resolveLegacyKind()`, `labelKind()`, `splitLabels()`, `attributes()`, `resolveCatalogRow()` all four-kind. New `damageMap()`, `kindAffectsReliability()`, `faultLabels()`. Damage asked FIRST — its vocabulary is the most specific. |
| **Ontology / AI** | `conceptKind()` types damage; the `kinds` lane filter on `resolve()` excludes it from every diagnosis lane. |
| **Recurrence** | `RecurringFaultService` subject guard reads `affectsReliability()`; the prior-occurrence query filters `RELIABILITY_KINDS`. `VehicleFaultRecurrenceService::bucketFor()` types at LABEL grain before any category reasoning. |
| **Vehicle Health / reliability** | `CarStatusService` derives its evidence set from `reliabilityKindsForMode()`. |
| **Dashboards** | `topFaults()` / `faultCars()` filter on the reliability kinds; the sheet half via `faultReasonIds()`. |
| **Parts / foresight** | `PartIntelligenceService` → `affectingReliability()`. `MaintenanceForesightService` already excluded non-fault labels via `splitLabels()`, so damage dropped out automatically. |
| **Damage reporting** | **NEW** `DamageAnalyticsService` + `GET Dashboard/damage`: counts, exposed vehicles, cost, damage type, chargeable/insurable split, top-exposed cars. |
| **API** | `damage_tags` beside `fault_tags`/`service_tags`/`context_tags` on the vehicle profile, `WorkshopEventResource` and the workshop-event snapshot. `kind` / `kind_meta` / `catalog` already generic over `KIND_META`, so damage rendered with no further change. |
| **Frontend** | `visitDamage()`, `isNonFaultVisit()`; the fault donut excludes damage-only visits without inflating "Unspecified"; the timeline files damage with accidents. |
| **Guardrails** | `events:kind-integrity` gained two damage checks. `events:reclassify-damage` migrates history. |

## 6. Migration & backfill

`php artisan events:reclassify-damage [--dry-run] [--review-only] [--skip-backup]`

- **Deterministic only.** A row moves when its symptom EXACTLY names a `damage_catalog` row or a label
  the sheet map declares damage. No fuzzy matching, no scoring.
- **Never guesses.** Genuinely ambiguous wording — `Interior problem / Chairs` (122 rows) could be a
  seat that won't adjust *or* a torn seat — is left `fault` and flagged `needs_review`.
- **Never overwrites a human.** `classification_source` of `catalog` or `manual` is skipped.
- **Idempotent**, transaction-wrapped dry run, pre-flight `db:backup`.

**Current result: 0 rows promoted.** All 114 task rows are workflow-created with catalog fault wordings;
the ~5,500 damage events live in the **sheet**, which has no task rows and is typed live at read time.
No data migration was needed for them — the vocabulary change alone reclassifies them.

## 7. Measured behaviour change

| Figure | Before | After |
|---|---|---|
| Top Faults #1 | **Body & Interior 862** | **Engine 735** |
| Body & Interior in Top Faults | 862 | **120** (the genuine interior/bodywork faults) |
| Damage events reported | — | **5,534** across **150** vehicles, **AED 131,335** |
| Interior faults reaching recurrence | 0 (blanket-excluded) | **132 rows** now correctly counted |

A real mixed visit now separates cleanly:

```
main = "Engine, Body & Exterior, Electrical, Interior"
sup  = "Oil & Fillter Change, Rim Scratch, Dashboard Warning Lights, Camera System Issue, Seatbelt Malfunction"

fault   → Engine · Electrical · Dashboard Warning Lights · Camera System Issue · Seatbelt Malfunction
service → Oil & Fillter Change
damage  → Body & Exterior · Interior · Rim Scratch
context → —
```

## 8. Behaviour contract

**Service** — never affects recurrence · never affects reliability · never in Top Faults · may drive reminders.
**Fault** — affects reliability, recurrence, health · appears in fault analytics · drives prediction.
**Damage** — never affects recurrence, reliability or prediction · never inflates fault stats · **remains**
fully reportable, searchable, costed and billable · has its own dashboard and KPIs.

## 9. Test coverage

| Suite | |
|---|---|
| `tests/Unit/ServiceFaultSeparationTest` | 19 tests / 129 assertions — incl. damage typing, the interior/bodywork trap, `RELIABILITY_KINDS`, every-mode exclusion, four-way partition, no-category-shortcut |
| `tests/Crud/ServiceFaultWritePathTest` | 10 tests — incl. repeated damage never flagged, prior damage never confirms a fault, damage typed from its catalog |
| `frontend/src/lib/serviceFaultSeparation.test.js` | 13 tests — damage bucket, damage-only visits, timeline filing, legacy fallback |
| `events:kind-integrity` | 9 invariants incl. "damage never counts as reliability evidence" |

## 10. Remaining risks

**R1 · `Interior problem / Chairs` (122 rows) is unresolved by design.** It currently types `fault`. If
those are torn seats, they are damage. The command flags them rather than guessing; a human should
decide and either add the wording to `damage_catalog` or leave it.

**R2 · Log-sourced damage has no catalog row**, so `chargeable` / `insurable` / `damage_type` are only
answerable for workflow-created events. The report counts sheet rows as `unclassified` rather than
assuming they are billable — billing on an assumption is not a reporting decision.

**R3 · No Damage UI page yet.** The API (`GET Dashboard/damage`) and the analytics exist and are tested;
the dashboard screen that consumes them is not built.

**R4 · Renter-liability linkage is modelled but not wired.** `is_chargeable` / `is_insurable` are
catalog defaults; connecting them to the existing damage/liability log and to invoicing is follow-on work.

**R5 · The CRUD suite remains non-deterministic** (pre-existing — see the audit's §17). Damage coverage
was verified by running its suite in isolation, which passes 10/10.

## 11. Verdict

**The domain is now three explicit operational kinds plus inspection, with one authority, four grains,
and no duplicated rule.** Damage is excluded from every reliability surface structurally — by type, at
the model, not by a filter any future feature can forget — and is fully visible in its own right.

The change also *fixed* a pre-existing defect it inherited: the old category-level cosmetic rule had
been silently dropping 132 rows of genuine interior faults from recurrence and foresight.
