# The Intelligence Platform — As Built

*What exists, where it lives, what rules it obeys, and what to do when it misbehaves.*

**Last verified against the code:** 2026-08-01
**Status:** the framework is frozen. The phase is evidence collection and controlled activation, not capability building.

Every other intelligence document in `docs/` is a **design spec written before the build**
(`Operational-Intelligence-Layer-Spec.md`, `Maintenance-Intelligence-Capability-Design.md`,
`Repair-Intelligence-Architecture.md`, …). They are kept as the record of *why* — they are not a
description of what runs. **This document is the one that must stay true.** If it disagrees with the
code, the code is right and this file is a bug.

---

## 1. The shape, in one screen

```
                         ┌──────────────────────────────────────────────┐
  a supervisor opens ──▶ │ OperationalIntelligence                      │  the ONLY class that knows
  a maintenance ticket   │  binds a live ticket + actor to ranked cards │  both the workflow and the
                         └───────────────┬──────────────────────────────┘  intelligence pipeline
                                         │
              ┌──────────────────────────┼──────────────────────────┐
              ▼                          ▼                          ▼
    ┌──────────────────┐      ┌────────────────────┐     ┌──────────────────────┐
    │ PolicyRegistry   │      │ DecisionEngine     │     │ RecommendationRecorder│
    │ WHEN / WHO / HOW │      │ runs capabilities  │     │ freezes what was shown│
    │ OFTEN it speaks  │      │ → CardArbitrator   │     │ + every version of it │
    └──────────────────┘      └─────────┬──────────┘     └──────────────────────┘
                                        │
                                        ▼
                            ┌───────────────────────┐
                            │ IntelligenceCapability│   answers ONE question:
                            │  ComebackCapability   │   "do I have useful history here?"
                            └───────────┬───────────┘
                                        │  asks questions, never composes SQL
                                        ▼
                            ┌───────────────────────┐
                            │ RepairHistoryQuery    │   the platform's public API for history
                            │ └ Projection…Query    │   the ONLY class that knows the tables
                            └───────────┬───────────┘
                                        │
                                        ▼
                             maintenance_signatures      the corpus (49,501 rows / 21,136 tickets)
```

### The three prohibitions

Documented on `IntelligenceCapability` and enforced by `CapabilityIsolationTest`:

1. **No capability talks to another capability.** Ever. Two capabilities that agree because one read
   the other's output are not two pieces of evidence; they are one, counted twice.
2. **No capability composes SQL.** It asks `RepairHistoryQuery` a question. A capability that reaches
   for a table has quietly become a data-access layer, and the next one will re-implement the same
   read slightly differently.
3. **No capability promotes itself.** Only `PromotionGate` moves a capability from proxy to measured,
   and only by measuring.

---

## 2. Concept → class

| Concept | Class | Notes |
|---|---|---|
| Delivery: ticket + actor → cards | `Intelligence\OperationalIntelligence` | the only class spanning both worlds |
| Which capabilities run | `Intelligence\DecisionEngine` | knows capabilities, knows nothing about ranking |
| Ranking / interruption budget | `Intelligence\CardArbitrator` | receives a flat `DecisionCard[]`, structurally cannot favour a source |
| When/who/how often a card may appear | `Intelligence\CapabilityPolicy` + `PolicyRegistry` | data, not code; registered in `AppServiceProvider` |
| A capability | `Intelligence\Contracts\IntelligenceCapability` | today: `Capabilities\ComebackCapability` (v2) |
| The card | `Intelligence\DecisionCard`, `Evidence`, `Confidence` | `Evidence::SCHEMA_VERSION = v2` |
| History, asked as questions | `RepairIntelligence\Query\RepairHistoryQuery` | the public API |
| History, actually read | `RepairIntelligence\Query\ProjectionRepairHistoryQuery` | `VERSION = v1`; **the only class that knows projection tables** |
| An answer + its own metadata | `RepairIntelligence\Query\HistoricalAnswer` | sample size, tier, completeness, proxy-ness — so capabilities never recompute confidence |
| What was shown, frozen | `Models\Recommendation` + `RecommendationRecorder` | carries five versions (see §5) |
| What the human said | `Models\RecommendationEvent` | accepted / overrode / dismissed + reason |
| Did it turn out right | `Console\Commands\RecordRecommendationOutcomes` | nightly, judges at 90 days |
| Evidence position per capability | `Intelligence\Readiness\EvidenceLedger` → `EvidenceRequirement` | the operating KPI |
| One score per capability | `Intelligence\Readiness\CapabilityHealth` | 5 equal dimensions; a blocker **caps**, never averages |
| Proxy → measured decision | `Intelligence\Readiness\PromotionGate` → `Models\CapabilityPromotion` | append-only, refusals included |
| The comparison itself | `RepairIntelligence\Backtest\ComebackBacktest` | `VERSION = v1` |
| The whole state, serialised | `Intelligence\Readiness\IntelligenceSnapshot` | what `/intelligence-center` renders |

---

## 3. What actually ships today

**One capability: Comeback Warning (`comeback-warning`, v2).** Says "this fault has come back before"
at the moment a supervisor is choosing where to send the car.

- **Fires at** `inspection_pending`, `reinspection_failed`, `complaint_triage` — the three states
  where a human can still change the outcome. Deliberately **not** at `awaiting_invoice`: a warning
  nobody can act on is a dashboard, and this pipeline exists in order not to become one.
- **Tuning** (`config/features.php` → `intelligence.comeback`): 14-day window, ≥1 prior, 8 excluded
  signatures (OIL_SERVICE, BATTERY, CHECK_ENGINE, LEAK_OTHER, TRANSMISSION, ACCESSORY, KEY, FUEL_SYS).
- **Measured performance:** 58.9% precision, **1.45× lift**, ~54 cards/month. The shipped-then-retuned
  rule was 1.20× lift at 219 cards/month — useful arithmetic, useless product.
- **Currently OFF** (`FEATURE_INTEL_COMEBACK=false`). 1.45× on proxy evidence is real but not yet
  worth the interruption budget. It is not off because it is broken.

**Three capabilities deliberately not built.** They stay on the ledger rather than being deleted,
because each is blocked by a measured fact that could change:

| Capability | Blocked by |
|---|---|
| Garage Recommendation | Fault-mix-standardised spread is real (5.59× vs 2.22× chance) but **does not persist** between periods (r = −0.36 / +0.02 / +0.21). Picking a top-third garage was worth **−2.6pp** in held-out data. May be a failure of the *outcome measure* (return rate ≠ bad repair), so it is a re-test with a condition, not a dead end. |
| Parts Recommendation | Parts are not recorded against repairs at all. |
| ETA Prediction | 4,082 of 6,880 durations run **backwards** — the sheet often carried in and out as one date. Only workflow-native timestamps (`repair_started_at → ready_at`) can replace them. |

---

## 4. The rules that keep it honest

**Proxy vs measured.** The comeback card reasons from *a car came back*, which is not *the repair
failed*. No volume of proxy evidence becomes a measurement; only a different input does — the QC
verdict an inspector records at Reinspect. This is the single fact the whole platform is waiting on,
and it exists nowhere in the 26,839-row history because nobody ever recorded it.

**Promotion is evidence-driven, not calendar-driven.** Reaching the threshold buys the right to run
the comparison, not the promotion. The measured model must predict at least as well as the proxy it
replaces (lift, not precision — the two outcomes have different base rates). **A refusal is the most
informative row in the table**: the platform declining better-looking evidence because it predicted
worse. Every decision, including every refusal and every "not yet", is written append-only with its
full provenance bundle, which `CapabilityPromotion` enforces by throwing on a missing field.

**Weakest input wins.** Confidence is the weakest of its inputs, not their average. `CapabilityHealth`
applies the same principle: a blocker **caps** the score at 0.35 rather than subtracting from it,
because a chain is not the average of its links.

**Four reinspection verdicts, not two.** `fixed` / `still_exists` / `new_issue` are **conclusive** —
they can be learned from. `unable_to_verify` counts toward coverage (the inspector attended and
answered honestly) and is excluded from every statistic and from training. Without it, an inspector
who cannot tell must either guess "fixed" — poisoning the only trustworthy dataset — or record
nothing, which is indistinguishable from a skipped queue.

**Never auto-write a verdict.** Closing a ticket must not stamp "fixed". It would show 100% coverage
overnight and destroy the only honest dataset the platform has.

---

## 5. Versioning: "if this were generated today, would it be different?"

Every stored recommendation freezes five versions (`OperationalIntelligence::versions()`):
`capability`, `engine`, `query_layer`, `evidence_schema`, `classifier`. Every promotion decision
freezes six more (`PromotionGate::provenance()`): proxy model, measured model, dataset, backtest,
capability, query layer. `CapabilityPromotion::isSupersededBy()` answers whether the ground has moved
under a standing decision — which does not make it wrong, only no longer evidence about the present.

> The capability version was once hardcoded `'v2'` in `PromotionGate`. Bumping the capability would
> have made every later decision claim to have evaluated v2, so a reader comparing two decisions
> would see no methodology change where there was one. It now asks the capability. **Do not
> reintroduce a literal version string anywhere.**

---

## 6. Operating it

### The page
**`/intelligence-center`** (`insights.view`) — capability health, readiness, freshness, QC throughput,
promotion history with provenance, feature-flag drift, background-job health, data-quality blockers.
Cached 5 minutes; "Re-read now" bypasses it. A degraded read is never cached, and a failed section is
named rather than rendered empty.

### The commands
| Command | Cadence | Purpose |
|---|---|---|
| `intelligence:rebuild-signatures` | daily 03:05 | rebuild the corpus every capability reads from |
| `intelligence:record-outcomes` | daily 03:15 | judge recommendations at 90 days |
| `intelligence:evidence-health --promote --alert` | Mon 04:00 | run the promotion gate; alert if verdict capture stalls |
| `intelligence:validate-classifier` | on demand | **gate** — run before any rebuild after a pattern change |

### The flags
| Flag | Default | Must be |
|---|---|---|
| `MAINT_REQUIRE_QC_VERDICT` | **true** | **ON.** Off means repaired cars close unverified, and that evidence is unrecoverable — the car has gone. The only default-on entry in `config/features.php`. |
| `FEATURE_INTEL_COMEBACK` | false | OFF until measured evidence is in. |

### The one alert that matters
`EvidenceLedger::verdictPipelineAlert()` — verdict capture stopping is the only failure the platform
cannot see from the outside. It does not break: it keeps producing cards from proxy evidence, looking
exactly as healthy as before, while the dataset that would let it improve quietly stops growing. The
alert distinguishes *the gate was switched off* (a configuration change nobody announced) from *the
queue is being skipped* (a conversation with the workshop), because those need different responses.

---

## 7. Runbook

| Symptom | Likely cause | What to do |
|---|---|---|
| No cards ever appear | `FEATURE_INTEL_COMEBACK` off, or the ticket is not in one of the three states | Both are correct behaviour. Check `/intelligence-center` → Feature flags. |
| A card appears once and never again | `REFRESH_PER_STATE` + no cooldown — answered once stays answered | Correct. Re-asking someone who already decided costs credibility across every card. |
| Capability health drops with no code change | Evidence aged, or the feed went quiet | Open the capability row: `freshness` and `feed_quiet` say which. |
| Corpus age climbing | `intelligence:rebuild-signatures` not running | Check Background jobs on `/intelligence-center`. **This job was unscheduled for months** and had no symptom. |
| Promotion refused repeatedly | Working as designed | Read the reason column. "Below the evidence threshold" is not a failure. |
| A section of the Center is blank | It is not blank — it names its own failure at the top of the page | Read `failed_sections`; the rest of the page is accurate. |

---

## 8. Adding a capability

Three steps, no orchestration code:

1. Implement `IntelligenceCapability::evaluate()`. Ask `RepairHistoryQuery` for what you need — do
   not write SQL, do not read another capability.
2. Register it in `AppServiceProvider` → `DecisionEngine`.
3. Register a `CapabilityPolicy` in `AppServiceProvider` → `PolicyRegistry`.

**Before any of that**, add it to `EvidenceLedger::all()` and run
`php artisan intelligence:evidence-health`. If it is blocked or below threshold, the honest outcome
is a ledger row, not a build. Garage Recommendation looked obviously buildable and a persistence test
killed it; nothing in the codebase could have said so, only measuring did.

> **No new intelligence capability is promoted before the evidence engine proves it can support it.**

---

## 9. Where the numbers came from

| Figure | Source |
|---|---|
| 26,839 maintenance records → 49,501 signature rows / 21,136 tickets | the historical N-Maintenance sheet |
| Comeback base rate 39.5%, precision 58.9%, lift 1.45× | `ComebackBacktest` over 7,227 outcome-verified cases |
| Garage O/E spread 5.59× vs 2.22× chance; r = −0.36 / +0.02 / +0.21 | indirect standardisation + temporal split, label-shuffle null |
| 4,082 negative durations of 6,880 | `maintenances.actual_in_date` vs `out_date` |
| First-time fix 59.6%, comeback 40.4%, turnaround 2.7d | frozen pre-rollout baseline, `kpi:snapshot` |

**The sheet is fully consumed.** It answers *what happened* — which fault, when, which car, which
garage. It cannot answer *did the repair work*: all 129 columns were checked, and the only populated
status fields are `event_status` (IN / OUT / Follow up / Delay — a movement state) and
`approval_status` (the constant `not_required`). Nothing is being held back for later; the one
missing fact can only be created going forward, at Reinspect.
