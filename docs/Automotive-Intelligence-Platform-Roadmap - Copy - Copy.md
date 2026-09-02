# Automotive Intelligence Platform — Ingestion, Fusion & Fleet Learning

**Status:** DESIGN. No code written.
**Supersedes nothing** — extends `docs/Automotive-Knowledge-Platform-Evaluation.md`.
**Date:** 2026-08-01

---

## 0. One structural finding that changes the order

I verified the Outcome-Knowledge world before designing, and found something that reorders your plan.

**The system has three different fault vocabularies that do not join.**

| Vocabulary | Size | Where | Covers |
|---|---:|---|---|
| **Ontology concepts** | 106 fault nodes, 367 causes, 2,128 terms | `ontology_nodes` + `keyword_terms` + `MatchPipeline` | Automotive Knowledge |
| **Repair signatures** | **22 coarse system buckets** (v1 classifier) | `maintenance_signatures` — 49,501 rows | Fleet Knowledge |
| **FaultVocabulary** | separate again | `FleetEvidenceService::learnCoOccurrence` over `service_main`/`service_sup` | Fleet co-occurrence |

The 49,501 signatures resolve to just **22 signatures**, and two of them — `BODY` (10,706) and `RIM` (4,907) — are
`is_exposure` and correctly excluded from quality scoring (customer damage, not repair failure). So the
quality-relevant fleet substrate is **~33,888 rows across 20 system-level buckets**:

```
ELECTRICAL 4124 · INTERIOR 3376 · OIL_SERVICE 2982 · STEERING 2870 · TYRE 2521 · LIGHTS 2350
ENGINE_MECH 1868 · COOLING 1719 · SUSPENSION 1686 · ACCESSORY 1510 · AC 1506 · CHECK_ENGINE 1412
BRAKES 1409 · TRANSMISSION 1340 · GLASS 1276 · BATTERY 589 · EXHAUST 445 · KEY 402
FUEL_SYS 254 · LEAK_OTHER 249
```

**This is the join-key problem, and it is the real obstacle to the agreement engine.** Fleet Knowledge speaks
*`COOLING`*. Automotive Knowledge speaks *"thermostat failure", "water pump bearing wear", "radiator core blockage"*.
You cannot compute `confirms` / `fleet_only` / `contradicts` between vocabularies at different resolutions — the
comparison collapses to "the cooling system had problems", which is not an insight.

Your example sentence assumed the join already worked:

> Domain: *thermostat / coolant leak / water pump.* Fleet: *6 cooling repairs, 2 radiator replacements.*

The first half is concept-level. The second half, today, is `COOLING × 6`. There is no *"2 radiator replacements"*
(`maintenance_line_items` = 12 rows fleet-wide; `maintenance_task_actions` = 0).

**Therefore: a Concept Bridge must come before corpus ingestion.** Ingesting Bosch documentation into a graph that
cannot be joined to fleet evidence produces a better encyclopaedia, not a better agreement engine. This is the one
change I'd make to your ordering, and I think it's the highest-leverage work in the entire plan.

### 0.1 The good news
The bridge does not need building from scratch. `MatchPipeline` (exact → alias → phrase → token → fuzzy → semantic,
over 2,128 terms, deterministic and explainable) **already resolves free text to ontology concepts.** The signature
classifier is a separate, older, 22-pattern regex layer that predates it.

And the outcome signal is real: **3,162 vehicle+signature pairs recur** — the same fault returning on the same
vehicle. That is Outcome Knowledge at scale, derivable today, without any repair-capture data.

---

## 1. The Concept Bridge (new Phase 1.5)

### 1.1 What it is
A **second, concept-level labelling of the same 26,839 tickets**, produced by running the existing `MatchPipeline`
over ticket text and storing the matched ontology concept ids — **stored alongside v1, never replacing it.**

```
maintenance_concept_labels           (the one new table in this whole plan — a rebuildable read-model)
  maintenance_id · vehicle_id · occurred_at
  ontology_node_id            ← the native join key to Automotive Knowledge
  match_stage                 ← exact | alias | phrase | token | fuzzy | semantic
  match_score                 ← MatchPipeline's explainable score
  is_exposure                 ← carries the BODY/RIM quality exclusion forward
  classifier_version          ← 'concept-v1'
```

### 1.2 Why side-by-side and not a replacement — this is load-bearing
`maintenance_signatures` v1 is the substrate of comeback detection, garage scoring, seasonality and similar-case
retrieval, and **the operational KPI baseline is frozen against it** (first-time fix 59.6%, comeback 40.4%,
turnaround 2.7d). A signature rebuild moves those numbers. Replacing v1 would silently invalidate the baseline
and every KPI comparison built on it.

So: **v1 keeps owning operational KPIs. The concept layer owns knowledge fusion.** They coexist, each versioned,
and the `signature → concept` mapping is itself recorded so the two views can always be reconciled.

### 1.3 Expected gain
Fleet Knowledge resolution goes from **20 usable buckets → up to 106 concepts**, sharing a primary key with the
knowledge graph. Every downstream capability in this document becomes computable at the level domain documentation
actually speaks.

### 1.4 The honest caveat
`MatchPipeline` will not resolve every ticket to a concept — free text is messy, and the classifier's own measured
agreement with human labels was 80.5%. Expect a **long tail of unmatched tickets**, and expect the tail to be
informative: unmatched text is exactly the vocabulary the ontology is missing, and should feed term enrichment.
Report coverage as a first-class metric (`concepts resolved / tickets`), do not hide it.

---

## 2. The three worlds, formalised

Each world is a distinct evidence type with its own truth conditions. **They are never merged into one number** —
they are compared, and the comparison is the product.

| World | Question | Source | Granularity today | After bridge |
|---|---|---|---|---|
| **Automotive** | What *should* happen? | `ontology_edges` (asserted) + corpus + web | Concept (106 faults / 367 causes) | unchanged |
| **Fleet** | What *did* happen? | 33,888 quality-relevant signatures + `vehicle_expenses` (28,327) | 20 system buckets | **~106 concepts** |
| **Outcome** | What *actually worked*? | recurrence (3,162 recurring pairs) · `repair_inspections` (**9**) · `maintenance_task_actions` (**0**) | recurrence only | recurrence at concept level |

### 2.1 Outcome Knowledge — what it can and cannot say
This is the world you're most excited about and the one with the least data, so it needs stating precisely.

**Computable today (and at scale):** *"Did the fault come back?"* — derived from recurrence of the same
vehicle+concept within a window. 3,162 recurring pairs is a genuine, large signal. It supports statements like
*"COOLING recurs on 23% of vehicles within 90 days of a cooling repair"* — a real quality finding.

**Not computable today:** *"Which repair fixed it?"* — that requires knowing what was physically done.
`maintenance_task_actions` = 0, `maintenance_line_items` = 12, `repair_inspections` = 9, `service_records` = 0,
`component_events` = 0.

So Outcome Knowledge is currently **negative evidence only**: it can tell you a repair *failed to hold*. It cannot
tell you *which* repair succeeded. That asymmetry has a direct consequence for the agreement engine (§5) and is the
strongest operational argument for driving repair-capture adoption — the whole `contradicts` category depends on it.

---

## 3. Knowledge Ingestion Pipeline

### 3.1 Stages
```
1 ACQUIRE    document (uploaded file | allow-listed web retrieval)     KnowledgeIngest · WebRetriever
2 NORMALISE  → UTF-8 text; checksum; vehicle scope; doc_type; publisher; published_at
3 CHUNK      heading-aware split, heading retained with body           KnowledgeChunk (existing)
4 EXTRACT    chunk → structured claims, closed vocabulary              AnthropicExtractionProvider
5 RESOLVE    claim strings → EXISTING ontology nodes                   MatchPipeline    ← anti-fragmentation
6 PROPOSE    write a changeset, not live graph rows                    NEW
7 REVIEW     admin diff: added / changed / conflicting                 NEW UI + CapabilityPromotion
8 PROMOTE    apply changeset; stamp versions + provenance              PromotionGate (existing pattern)
9 LINK       every node/edge/term gets ≥1 EvidenceLink                 EvidenceLink (existing, 0 rows)
```

### 3.2 The rule you stated, made structural
> *"Nothing should become knowledge without evidence."*

Enforce it at the write boundary, not by convention: **`OntologyGraphService` refuses to persist an edge or term
without at least one `EvidenceLink`.** A `model_prior` link is acceptable — it is honest, and scores 20 on the
existing grounding scale — but *no* link is not. The 2,005 existing seed edges get backfilled as `model_prior` in
Phase 1 precisely so this invariant can be turned on without deleting anything.

### 3.3 Extraction contract
The extractor emits **only** the closed vocabulary already in the schema — 10 node types, 12 relation types. Anything
outside is rejected, not coerced. Per an existing hard-won constraint, research and extraction are **two API calls,
not one** (`output_config.format` is incompatible with the citation blocks search tools emit → 400). Keep them split.

Each extracted claim carries: subject, relation, object, a **verbatim supporting quote**, chunk ordinal, and the
extractor's own certainty. The quote is what becomes `evidence_links.snippet`, and it is what makes a reviewer able
to check the claim in seconds.

### 3.4 Resolution before creation — the anti-fragmentation rule
An extracted `"thermostat stuck closed"` must resolve to the **existing** cause node, not create a 368th one. Route
every extracted string through `MatchPipeline`; create a node only when nothing scores above the `strong` threshold
(70). That `ontology:duplicates` exists as a maintenance command tells you fragmentation is already a real problem —
ingestion at volume would make it much worse without this rule.

### 3.5 Idempotency and cost control
Documents are checksummed; re-ingesting an unchanged document is a no-op. Extraction results are cached per
`(chunk checksum, prompt_version)`, so re-running after a prompt bump re-extracts only what the bump affects. Both
matter once you are processing thousands of chunks against a metered API.

### 3.6 Where to point it first
Do **not** ingest broadly. Take the **top 8 quality-relevant systems by fleet volume** — ELECTRICAL, STEERING,
ENGINE_MECH, COOLING, SUSPENSION, AC, CHECK_ENGINE, BRAKES — and ingest only for the concepts underneath them.
Knowledge about faults the fleet never has is knowledge nobody will read.

---

## 4. Knowledge Freshness

### 4.1 What already exists
`keyword_profiles.enriched_at` · `knowledge_documents.ingested_at` / `published_at` / `checksum` ·
`ontology_edges.observed_count` / `observed_rate` · `evidence_links.retrieval_method` ·
`config('knowledge_platform.versions')` (prompt / ontology / retrieval) · `RunProvenance` · `keyword_enrichment_runs`.

### 4.2 What to add
```php
final class Freshness {
    ?Carbon $importedAt;         // exists
    ?string $sourceKey;          // exists
    ?string $sourceVersion;      // NEW — TSB revision, manual edition, doc version string
    ?Carbon $lastValidatedAt;    // NEW — someone/something last re-checked it against its source
    int     $evidenceCount;      // NEW (derivable) — how many links back it
    ?Carbon $fleetConfirmedAt;   // NEW — when our own outcomes last agreed with it
    int     $fleetObservations;  // exists on edges
    ?int    $supersededBy;       // NEW — this claim was replaced by that one
    string  $state;              // fresh | aging | stale | superseded | unvalidated
}
```

### 4.3 Staleness is per document type, not global
A TSB is superseded; Ohm's law is not. Half-lives belong in config:

| doc_type | half-life | rationale |
|---|---|---|
| `tsb` | 12 months | actively revised; a superseded TSB is *wrong*, not merely old |
| `service_manual` | 36 months | stable per model year |
| `spec_sheet` | 24 months | part numbers change |
| `paper` / `regulation` | 60 months | slow-moving |
| `internal` | 12 months | your procedures change with your fleet |
| **fleet-observed edges** | **rolling window, never "stale"** | they decay by recency weighting, not expiry |

**Aging never deletes.** It downgrades confidence and surfaces the item in a revalidation queue. Silent expiry of
knowledge is as bad as silent invention of it.

---

## 5. The Agreement Engine — computability, honestly

This is the centrepiece, so here is exactly what can be computed and when.

| Category | Requires | Today (22 buckets) | After Concept Bridge | After repair capture |
|---|---|---|---|---|
| **`domain_only`** | a domain edge + **absence** of fleet observation | ✅ **computable now** | ✅ sharper | ✅ |
| **`confirms`** | domain edge + fleet co-occurrence at same granularity | ⚠️ system-level only | ✅ | ✅ |
| **`fleet_only`** | fleet pattern + no domain edge | ⚠️ system-level only | ✅ | ✅ |
| **`contradicts`** | *which repair was done* + *did it recur* | ❌ **not computable** | ❌ still not | ✅ |

### 5.1 `domain_only` is available immediately — start there
Absence is measurable even at coarse granularity. The graph holds **302 `inspected_by` edges** — documented
diagnostic procedures. If documentation says *"pressure-test the cooling system"* and our 1,719 COOLING tickets
contain no record of a pressure test ever being performed, that is a **finding today**, at current data quality,
with no LLM and no corpus.

It is also the finding with the clearest operational owner: it is a **training and procedure gap**, exactly as you
described. I would build this first and ship it into the workflow as an inspection prompt.

### 5.2 `contradicts` is blocked, and should be labelled blocked
*"A documented repair repeatedly fails in our fleet"* requires knowing the repair was performed. With
`maintenance_task_actions` = 0 that is not derivable. There is a tempting proxy — *the system recurred after a
completed ticket, and documentation names one standard repair for it, so presumably that repair was done and failed* —
and it should be **rejected as a finding**. It assumes the repair we didn't record, and it would generate confident
accusations about garages from an inference. If surfaced at all, it must be labelled a **hypothesis requiring
capture**, in a separate tray from findings.

That is the honest cost of `maintenance_task_actions` = 0, and it is the argument to take to whoever owns
technician adoption.

### 5.3 Guards
* **Exposure exclusion**: BODY and RIM must stay out of quality comparisons — they recur because customers damage
  cars, and including them ranks every body shop last.
* **Minimum observations**: existing floors (`MIN_OBSERVATIONS = 3`, `MIN_COOCCURRENCE = 5`) apply; below them a
  pattern is an anecdote, recorded but not ranked.
* **Directional rates**: co-occurrence A→B ≠ B→A, already handled — a rare fault paired with a common one looks
  falsely strong when symmetric.
* **Routine exclusion**: OIL_SERVICE-type routine concepts stay out of co-occurrence, or they co-occur with
  everything and fill the graph with true-but-meaningless counts.

---

## 6. Fleet Learning loop

### 6.1 Why it's currently near-empty
`FleetEvidenceService::learnFromResolvedTasks` mines **`maintenance_tasks` — 39 rows.** That is the entire reason
there are only 82 fleet edges. The miner is not broken; it is pointed at the empty half of the data.

### 6.2 The fix
Point it at the concept-labelled history instead: **33,888 quality-relevant tickets** rather than 39 tasks. Mine:

* **fault → co-occurring fault** (existing logic, now concept-level)
* **fault → recurrence rate** (NEW — the Outcome world; 3,162 recurring pairs)
* **fault → seasonality** (COOLING in UAE summer is the one likely to clear the bar)
* **fault → garage outcome** (reusing `OperationalKpiService` definitions — never a second comeback definition)

### 6.3 Evidence strengthening, without letting one world overwrite another
Your requirement — *"if a repair repeatedly succeeds, that should gradually strengthen our fleet evidence"* — is
already half-implemented by design: asserted weight and observed weight are **separate columns on the same edge**
(`weight` vs `observed_count`/`observed_rate`), and provenance rules already say `human` edges are untouchable and
`fleet` is never overwritten by `ai`.

The addition: an **outcome-weighted update** where each new confirming observation moves the observed rate with
diminishing returns (a √-credibility ramp, as `ConfidenceScorer` already uses), and disconfirming outcomes move it
down — but **the asserted weight never changes.** Documentation saying X and our fleet finding X fails are two facts
that must both survive; averaging them into one number destroys the very disagreement the platform exists to find.

---

## 7. Revised implementation order

Your order, with one insertion and one demotion:

| # | Phase | Change from your list |
|---|---|---|
| 1 | **Ground what exists** — computed profile confidence (currently hardcoded 70), backfill 2,005 seed edges as `model_prior`, turn on the no-evidence-no-write rule | as you had it |
| **1.5** | **⭐ Concept Bridge** — `MatchPipeline` over 26,839 tickets → `maintenance_concept_labels`, side-by-side with v1, coverage reported | **NEW — the unlock. Without it, 3 and 4 compare mismatched vocabularies** |
| 2 | **`domain_only` agreement** over `inspected_by` edges — inspection gaps into the workflow | **PROMOTED — computable today, no corpus, no LLM, immediate operational value** |
| 3 | **Ingestion pipeline** — stages, changeset, review, promotion, citations | as you had it |
| 4 | **Populate top-8 systems** from public-web + uploads | narrowed from "populate trusted sources" |
| 5 | **Fleet learning at scale** — miner re-pointed at concept labels; recurrence as Outcome evidence | as you had it |
| 6 | **Full fusion engine** — Claims, Dossier, all four agreement categories | as you had it |
| 7 | **Explainable recommendations** — `Explainer` registration, drill-down | as you had it |
| 8 | **LLM narrative layer** — shared validator with Vehicle Intelligence | last, as you asked |
| 9 | **Repair-capture adoption** — unblocks `contradicts` | operational, runs in parallel throughout |

Phases 1–7 involve **no LLM at runtime at all**. The only model calls are offline, during ingestion, producing
reviewed and cited structured data.

---

## 8. Risks and decisions

1. **`MatchPipeline` coverage over legacy text is unmeasured.** Phase 1.5 should begin with a dry run reporting
   resolution rate before anything is written. If it resolves under ~50%, the bridge needs term enrichment first,
   and that changes the estimate. **This is the number I would want before committing to the order above.**
2. **Do not replace signature v1.** The KPI baseline is frozen on it. Coexistence is a requirement, not a preference.
3. **`contradicts` stays blocked until repair capture lands** — and the proxy is worse than nothing.
4. **Review capacity is the ingestion bottleneck.** A pipeline that proposes 500 changes nobody reviews will either
   stall or get rubber-stamped, and rubber-stamping is how ungrounded knowledge re-enters through the front door.
   Design the review UI for triage — group by concept, show the verbatim quote, allow bulk-accept of high-confidence
   exact-resolution claims only.
5. **Three vocabularies is itself a defect** worth a decision: long-term, is the ontology concept the single fault
   identity, with signatures and `FaultVocabulary` as derived views? I think yes, but it is a migration, not a
   refactor, and it should be chosen deliberately rather than drifted into.
6. **`ontology_feedback` = 0** — the continuous-learning table exists and is unused. Worth wiring the review
   decisions from Phase 3 into it, so human corrections become training signal rather than one-off edits.
