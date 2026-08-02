# Automotive Knowledge Engine — Feasibility Evaluation & Architecture

**Status:** EVALUATION + DESIGN. No code written.
**Verdict:** Feasible. **And roughly 70% of it is already built in this repository.** The blocker is not
architecture — it is *evidence acquisition*, and behind that, a licensing decision that is yours, not engineering's.
**Date:** 2026-08-01

---

## 0. The finding that should change the plan

You asked me to evaluate whether this architecture is feasible. Before designing anything I measured what exists.
The architecture you just described — provider-independent knowledge layer, structured automotive facts per fault
category, tiered trusted sources, citations, confidence, fleet-vs-domain fusion, LLM as final reasoning layer only —
**is already designed and substantially built** under `App\Ontology` + `config/knowledge_platform.php`.

Here is the live database, measured just now:

| Table | Rows | Reading |
|---|---:|---|
| `knowledge_sources` | **17** | Bosch, Denso, NGK, ACDelco, SAE, ASE, OEM manuals, manufacturer TSBs, ALLDATA, Mitchell 1, MOTOR, Haynes, Chilton, NHTSA, gov transport, fleet history, fleet uploads — **the exact list you named, already registered with tier, trust weight, licence footing and domain allowlists** |
| `knowledge_documents` | **0** | ⛔ **The corpus is empty. Nothing has ever been ingested.** |
| `knowledge_chunks` | **0** | ⛔ `CorpusRetriever` therefore returns nothing today. |
| `evidence_links` | **0** | ⛔ **Not one claim in the knowledge base carries a citation.** |
| `ontology_nodes` | 1,470 | 106 fault · 367 cause · 360 symptom · 274 procedure · 271 component · 92 repair |
| `ontology_edges` | 2,087 | **2,005 `seed` + 82 `fleet` + 0 from corpus/web/AI** |
| `keyword_profiles` | 96 of 105 | Structured per-fault knowledge — causes, symptoms, components, repair actions, inspection order, tools, skills. All 96 have `inspection_order` populated. |
| `keyword_terms` | 2,128 | The matching vocabulary |
| `maintenances` | 26,839 | The fleet's history |
| `maintenance_signatures` | 49,501 | The derived fault index over that history |
| `maintenance_tasks` | **39** | ⛔ The structured per-fault plane |
| `maintenance_task_actions` | **0** | ⛔ No captured repair actions at all |
| `repair_inspections` | **9** | ⛔ Almost no verified outcomes |

Your worked example — *Engine Overheating → common causes / recommended diagnostics / common replacement parts /
sources* — **is literally the shape of a `KeywordProfile` plus that fault node's `caused_by` / `inspected_by` /
`fixed_by` / `requires_part` edges.** The engine can answer it today. What it cannot do is render the last line,
**Sources: Bosch, Toyota Service Manual** — because `evidence_links` is empty and no document has been ingested.

**So the honest recommendation is: do not design a new Automotive Knowledge Layer. Fill and ground the one you have,
fix two real defects in it, and build the one genuinely missing piece — the fusion layer.**

---

## 1. What exists, precisely

### 1.1 The layer you asked for
```
App\Ontology\
  Contracts\      ResearchProvider · ExtractionProvider · EmbeddingProvider · RerankerProvider · KnowledgeRetriever
  Retrieval\      RetrievalManager · CorpusRetriever · WebRetriever · FleetRetriever
  Providers\      Anthropic\{Research,Extraction} · NullDriver\{Embedding,Reranker,Research}
  Matching\       MatchPipeline: exact → alias → phrase → token → fuzzy → semantic
  Reasoning\      CausalReasoner · ProbableCause · ComplaintInterpreter
  Confidence\     ConfidenceVector · ConfidenceEnricher
  Versioning\     RunProvenance
  Learning\       CausalKnowledgeImporter
```
Four capabilities behind four contracts, each with a null implementation; vendor names appear only in
`config/knowledge_platform.php`. Your requirement *"the LLM should not be responsible for knowing automotive facts"*
is already the stated design principle of that config file.

### 1.2 The fleet/domain distinction you want is already a first-class column
`ontology_edges.source` separates **ASSERTED** (`seed`/`ai`/`human` — a claim about how cars work) from **OBSERVED**
(`fleet` — a counted fact about our cars, with `observed_count` / `observed_rate`). Both live in one table so a
technician gets one ranked answer, with provenance preserved. That is exactly the two-worlds model in your proposal.

### 1.3 Fleet history is already a retriever, not a bolt-on
`FleetRetriever` implements the same `KnowledgeRetriever` contract as a Bosch document, is merged in the same pass,
capped per-source so it cannot be crowded out, and contributes its own confidence dimension. Config already weights
`fleet` (0.90) **above** `documentation` (0.85), on the reasoning that a pattern measured on our cars in this climate
predicts our next repair better than a general manual. I agree with that call.

### 1.4 Partial fusion already exists
`CausalReasoner` does exactly the merge you describe, for one question ("what is causing this fault?"):
uniform catalogue **prior** × **fleet lift** from measured co-occurrence (min 3 observations, lift capped at 3.0),
normalised across competing explanations, returning a flat distribution *and saying so* when nothing is measured.
That is the prototype of the fusion layer. It needs generalising, not inventing.

---

## 2. The three real gaps

### GAP 1 — The corpus is empty, so nothing is citable (**the headline gap**)
`knowledge:ingest` exists and works (plain text / Markdown, chunked on headings, vehicle-scoped, checksummed). It has
never been run. Consequently: `CorpusRetriever` contributes nothing, `evidence_links` has zero rows, and the
`METHOD_GROUNDING` scale (corpus 100 · fleet 85 · web 80 · human 70 · **model_prior 20**) currently scores everything
in the bottom band.

**This is not an engineering problem. It is a content-licensing problem, and the code already encodes the constraint
honestly:** of the 17 registered sources, **6 are `licensed`** — OEM factory manuals, ALLDATA, Mitchell 1, MOTOR,
Haynes, Chilton. The retriever *skips a licensed source with no credentials rather than scraping the public site*,
which is the correct behaviour and non-negotiable. So the realistic near-term corpus is:

| Route | Sources | Status |
|---|---|---|
| **Public web (live retrieval, allow-listed)** | Bosch, Denso, NGK, ACDelco, SAE abstracts, NHTSA, manufacturer TSB pages, gov transport | ✅ Available now — `WebRetriever` + `AnthropicResearchProvider` enforce the allowlist **at the tool**, not in the prompt |
| **Uploaded (you own it)** | Your own procedures, manuals you purchased, supplier PDFs you're licensed for | ✅ Available now via `knowledge:ingest` — needs someone to actually convert and run it |
| **Licensed subscription** | ALLDATA / Mitchell 1 / Haynes / Chilton / OEM | 🔒 **Requires a commercial subscription. This is a procurement decision, and it is the single biggest determinant of how good this platform gets.** |

My recommendation: **start with public-web + uploaded, and treat an ALLDATA or Mitchell 1 subscription as a
deliberate, costed decision later** — once the platform is demonstrably used and you can see which fault categories
are actually being asked about. Buying a subscription before that is buying breadth you cannot yet aim.

### GAP 2 — 96% of the knowledge is ungrounded seed data presented without that caveat
2,005 of 2,087 edges are `source='seed'` — a hand/AI-authored starter catalogue. That is a perfectly legitimate
**prior**, and it is why the engine can answer anything at all today. But it must never render as *"Bosch says"*.

Worse, and concretely: **`keyword_profiles.confidence` is a constant 70 across all 96 rows** (min = max = 70). It is a
hardcoded default, not a computed score. So the "confidence level" your proposal asks for is currently **decorative on
the profile table**, even though a real confidence model (`ConfidenceVector`, dimension weights, `coverage_ceiling`
of 72/86/94/100 by dimension count) exists elsewhere in the same platform and is genuinely good.

Fix: profiles must derive confidence from their evidence links via the existing vector, and a profile with zero links
must read **low / model_prior**, not 70.

### GAP 3 — There is no fusion layer, and no single "ask the platform a question" entry point
`CausalReasoner` fuses for one question. `RetrievalManager` merges passages. `MatchPipeline` resolves text → concept.
But there is no composed object that answers *"tell me everything about this fault, for this vehicle"* by combining
domain knowledge + fleet evidence into one cited, confidence-scored, LLM-ready structure. That is the piece worth
building, and it is what §4 designs.

### GAP 4 (inherited, and it resizes the previous proposal) — the structured fleet plane is 39 rows
`maintenance_tasks` = **39** against 26,839 tickets; `maintenance_task_actions` = **0**; `repair_inspections` = **9**.

This sharpens what I wrote in the Vehicle Intelligence doc: Plane A is not "small and young", it is **39 rows**. Every
component-level claim — "the water pump was replaced 3 times", "commonly replaced parts on our fleet" — has
essentially **no structured basis today**. The fleet half of your vision currently rests on:

* **49,501 `maintenance_signatures`** — rich, real, and the actual substrate. Concept-level, not component-level.
* **82 fleet-mined edges** — the entire measured "our cars" knowledge in the graph.

That is enough for *"this vehicle has had 6 cooling-system repairs"* (your example — which works). It is **not** enough
for *"and 2 radiator replacements"* (which does not, without repair capture data). Worth being precise about, because
those two clauses sit in the same sentence of your proposal.

---

## 3. Answers to your seven questions

**Q: How should the Automotive Knowledge Layer be designed?**
As it already is: a **graph of typed nodes and provenance-tagged edges** (fast, deterministic, enumerable — this is
the primary answer path), a **document corpus** behind retriever contracts (the citation path), and **profiles** as
the denormalised per-fault view. Do not redesign. The one structural addition is the Dossier/fusion layer (§4).

**Q: Static, searchable, vector-based, or hybrid?**
**Hybrid — and stay lexical-first. Do not buy a vector database.** The decision is already correctly made in code:
`knowledge_chunks.embedding` is *nullable*, `NullEmbeddingProvider` is the default, and retrieval falls back to
lexical per-chunk so a partially-embedded corpus degrades gracefully. Reasoning:

* With **106 fault nodes and 96 profiles**, the answer space is small and enumerable. A graph lookup keyed on a
  normalised concept beats semantic search on both accuracy and explainability.
* Semantic matching already runs **last** in `MatchPipeline` and may only *add* recall — it can never outrank or
  suppress an exact match. That ordering is right and should be preserved.
* Anthropic does not sell embeddings, so this is a separate vendor commitment (Voyage / OpenAI / local model).
* **Threshold to revisit: ~5,000 corpus chunks.** Below that, lexical + graph is better and free. Above it,
  embed — the column and the contract are already waiting.

**Q: How should knowledge be versioned and updated?**
Three of four mechanisms exist: `config('knowledge_platform.versions')` (prompt / ontology / retrieval, stamped on
every run), `RunProvenance`, and `keyword_enrichment_runs`. **What is missing is a review gate.** Knowledge changes
should not go live silently. Design: enrichment writes a **changeset** (proposed nodes/edges/profile deltas with
their evidence), an admin sees a **diff**, and promotion flips it live — reusing the existing `CapabilityPromotion`
model and `Intelligence\Readiness\PromotionGate` rather than inventing a second approval concept. Every promoted
change carries prompt+ontology+retrieval versions, so a regression is explainable as *"the prompt went 2.0 → 2.1"*.

**Q: Should it support citations back to original technical sources?**
It already does, structurally, and the design is better than most: citation fields (`document_title`, `section`,
`url`, `snippet`) are **denormalised on purpose so they survive deletion of the chunk they came from** — an audit
trail that evaporates when someone prunes the corpus is not an audit trail. `retrieval_method` distinguishes
`corpus` / `web_search` / `fleet` / `human` / `model_prior`, and **`model_prior` is explicitly labelled "not a
citation"**. The table just has zero rows. Filling it is GAP 1 + GAP 2.

**Q: How do we ensure recommendations are explainable and traceable?**
Three existing mechanisms, plus one rule. `MatchExplanationService` (why this concept matched), `ExplanationEngine`
(the shared DAG — register one `Explainer` and inherit drill-down), `ConfidenceVector` + `coverage_ceiling` (a
single-dimension answer is capped at 72, so "one strong lexical hit" can never read as certainty). The rule to add:
**every claim in a Dossier carries a `claim_id`, and the LLM may only emit prose that cites claim_ids** — validated
after generation, with unmatched claims *dropped, not repaired*. Same enforcement I proposed for Vehicle Intelligence;
one implementation serves both.

**Q: How should Fleet Intelligence and Automotive Knowledge be merged before reaching the LLM?**
See §4. The short version: **not by concatenating two blobs into a prompt.** They merge into one ranked, typed
claim set where each claim knows whether it is asserted or observed, and where agreement and *disagreement* between
the two worlds are computed facts rather than something the model is asked to notice.

**Q: Cleanest architecture for reuse by Garages / Suppliers / Diagnostics / Procurement / Predictive?**
The `Scope` + `Retriever` + `Dossier` triad in §5.

---

## 4. The missing piece: the Fusion Layer

### 4.1 The unit of fusion — a Claim
```php
final class Claim {
    string  $id;              // 'C7' — what the LLM cites
    string  $type;            // cause | symptom | procedure | component | part | repair | pattern | risk
    string  $subject;         // 'Thermostat failure'
    string  $predicate;       // 'is a probable cause of'
    string  $object;          // 'Engine overheating'
    string  $world;           // 'domain' | 'fleet' | 'fused'
    ?float  $priorWeight;     // domain: catalogue/documentation strength
    ?int    $observedCount;   // fleet: how many of our tickets
    ?float  $observedRate;    // fleet: in what share of cases
    ?float  $lift;            // fused: how far our history moves the prior
    float   $probability;     // normalised across competing claims of the same type
    ConfidenceVector $confidence;
    Citation[] $citations;    // evidence_links — corpus/web/fleet/human/model_prior
    string  $groundingBand;   // documented | measured | asserted   ← the honesty label
}
```

`groundingBand` is the field that makes the whole thing trustworthy at a glance:
* **documented** — a real source was retrieved and read (corpus / web_search)
* **measured** — counted in our own tickets (fleet)
* **asserted** — seed or model prior, nothing retrieved. *Today, 96% of edges land here.*

### 4.2 The Dossier — what gets assembled, cached, and handed to the LLM
```php
final class FaultDossier {
    ConceptRef   $fault;
    ?VehicleRef  $vehicle;        // when asked in vehicle context
    string[]     $scopeChain;     // VehicleScope::chain() — make/model/platform/engine → universal

    Claim[]      $causes;         // CausalReasoner, generalised
    Claim[]      $symptoms;
    Claim[]      $diagnostics;    // ordered — inspection_order is already populated on all 96 profiles
    Claim[]      $components;
    Claim[]      $parts;
    Claim[]      $relatedFaults;
    Claim[]      $confusedWith;   // "these get mistaken for each other" — a distinct, valuable relation

    FleetEvidence $fleet;         // this vehicle's / the fleet's history for THIS concept
    Agreement[]   $agreements;    // §4.3 — computed, not model-noticed
    Coverage      $coverage;      // what we do NOT know, stated explicitly
    Provenance    $provenance;    // versions, generated_at, source mix
}
```

### 4.3 Agreement / disagreement — the part that produces the insight in your example
Your worked example is not *"domain knowledge plus fleet data, concatenated"*. It is **a comparison**:

> Domain says thermostat/coolant/water-pump. Fleet says 6 cooling repairs, 2 radiator replacements.
> → *investigate thermostat or circulation before replacing more components.*

The insight is that **the fleet has been repeatedly treating a component the domain knowledge does not rank first.**
That is a computable relation, and computing it is what stops the LLM from having to be clever:

```php
final class Agreement {
    string $claimId; string $kind;
    // 'confirms'          domain-expected cause is also our most common → high confidence
    // 'fleet_only'        we keep doing X; documentation doesn't rank X → possible mis-repair OR local condition
    // 'domain_only'       documentation ranks X; we have never checked it → an inspection GAP  ← the actionable one
    // 'contradicts'       our outcomes say X did not fix it, documentation says it should
    float  $strength; string[] $evidenceRefs;
}
```

**`domain_only` is the highest-value output of this entire platform.** It is the machine finding the thing the
workshop has never inspected. That is worth more than any summary paragraph, and it needs no LLM to produce — only
to phrase.

### 4.4 Assembly pipeline
```
FaultDossierAssembler::for(ConceptRef $fault, ?Vehicle $v): FaultDossier

 1. RESOLVE   text/fault → concept       MatchPipeline (existing, deterministic, explainable)
 2. SCOPE     VehicleScope::chain()      make+model+engine → make → universal   (existing)
 3. DOMAIN    graph edges + profile      OntologyGraphService + KeywordProfile   (existing)
 4. FLEET     measured evidence          Vehicle Intelligence MetricEngine + signatures + fleet edges
 5. RETRIEVE  passages + citations       RetrievalManager (corpus/web/fleet)     (existing, corpus empty)
 6. FUSE      prior × lift, normalise    CausalReasoner generalised to all relation types
 7. AGREE     compute Agreement[]        NEW — §4.3
 8. GRADE     ConfidenceVector + ceiling (existing)
 9. COVER     state what is unknown      NEW — the refusal surface
```

Steps 6–9 are **pure functions over steps 3–5's output** — DB-free, unit-testable, matching the house style already
used by `ConfidenceScorer::score` and `CausalReasoner`.

### 4.5 Where the LLM sits
It receives the Dossier rendered as a cited fact sheet and returns prose with `claim_refs`. It may **explain,
summarise, connect, prioritise and word recommendations**. It may not introduce a cause, a part, a procedure or a
number that is not already a Claim. Enforcement is post-validation (unknown ref → block dropped; unmatched number →
block dropped), identical to the Vehicle Intelligence narrative layer — **one implementation, both consumers.**

And critically: **the Dossier is fully useful with the LLM switched off.** Ranked causes, ordered diagnostics,
common parts, fleet history and the `domain_only` inspection gaps all render as structured UI. That is the product.
The prose is the polish.

---

## 5. Reuse across future modules

The triad that makes this general:

| Module | `Scope` | New retriever? | New Dossier assembler | Reuses unchanged |
|---|---|---|---|---|
| Vehicle Intelligence | vehicle | no | `VehicleIntelligenceProfile` | metrics, confidence, narrative validation |
| **Diagnostics** | fault + vehicle | no | `FaultDossier` (§4) | everything |
| **Garages** | vendor | no | `GarageDossier` — capability from repair history × domain skill/tool requirements | `requires_skill` / `requires_tool` edges already modelled |
| **Suppliers / Procurement** | part | maybe (catalogue) | `PartDossier` — `requires_part` edges × our spend × failure rates | graph + PartIntelligenceService |
| **Predictive Maintenance** | vehicle + concept | no | `RiskDossier` — cohort intervals × domain preventive schedules | intervals, calibration |

`requires_part`, `requires_tool`, `requires_skill` and `part_of` edges **already exist in the schema and are already
populated** — the graph was designed for these modules before they were requested. Adding one is: a Scope factory, a
Dossier assembler, a prompt template, and one `Explainer` registration. If a new module needs a new retriever or a
second confidence model, the boundary was drawn wrong and should be fixed rather than duplicated.

---

## 6. Recommended sequence

| Phase | Work | Why first |
|---|---|---|
| **0 — Measure** | Run `intelligence:evidence-health` + `ontology:coverage`. Establish which of the 106 fault concepts actually appear in the 49,501 signatures, and how often. | **Aim the corpus effort.** Ingesting Bosch cooling-system docs matters only if cooling faults are frequent here. Do not skip this. |
| **1 — Ground what exists** | Fix `keyword_profiles.confidence` (constant 70 → computed from `ConfidenceVector`). Backfill `evidence_links` for the 2,005 seed edges as `model_prior`, so they are *honestly labelled* rather than silently unattributed. | Removes the worst current defect: unearned confidence. Cheap. |
| **2 — Fill the corpus (top ~20 concepts)** | Convert and `knowledge:ingest` what you already own; enable `WebRetriever` against the public-web allowlist for Bosch/Denso/NGK/SAE/NHTSA/TSBs. Re-enrich those concepts so profiles gain real citations. | Turns "the AI thinks" into "the manual says" for the faults that actually occur. **This is the step that realises your vision.** |
| **3 — Fusion layer** | `Claim`, `FaultDossier`, `FaultDossierAssembler`, `Agreement` computation. No LLM. Surface `domain_only` inspection gaps in the workflow. | The genuinely new engineering. Ships value with zero AI. |
| **4 — Mine fleet edges properly** | Expand `ontology:learn-from-fleet` over 49,501 signatures; today only 82 fleet edges exist. | Makes the "measured" band real at scale. |
| **5 — LLM reasoning layer** | Shared narrative provider + claim-ref validation, serving both Vehicle Intelligence and Diagnostics. | Last, as you asked. |
| **6 — Repair capture backfill-forward** | Drive `maintenance_tasks` / `maintenance_task_actions` adoption. | Unblocks component-level truth (currently 39 / 0 rows). Long-horizon, operational not technical. |

Phases 0–4 contain **no LLM work at all** and deliver most of the value. That ordering is the direct expression of
your closing sentence — the AI is the final reasoning layer, so it is built last.

---

## 7. Risks and decisions that are yours

1. **The licensing decision is the real ceiling.** Bosch/Denso/NGK/SAE/NHTSA public technical material + your own
   uploads will support good general answers. Model-specific procedures and real TSB depth need ALLDATA or Mitchell 1
   subscriptions. The code refuses to scrape them, correctly. **Recommend: defer, and decide after Phase 0 shows which
   concepts dominate.**
2. **Seed knowledge quality is unaudited.** 2,005 edges nobody has reviewed against a source. Phase 1 labels them
   honestly; a spot-audit of the top 20 concepts by a technician would be cheap and worth more than it costs.
3. **Do not buy a vector database yet.** Revisit at ~5k chunks. The schema already accommodates it.
4. **`maintenance_tasks` = 39 is the quiet constraint on both proposals.** Concept-level fleet claims work now;
   component-level ones do not. Worth deciding whether driving repair-capture adoption outranks further engine work —
   I think it may.
5. **One narrative validator, not two.** If Vehicle Intelligence and Diagnostics each grow their own prompt-and-
   validate layer, they will drift. They should share it from the start.
6. **Naming collision to watch:** `App\Services\Knowledge` (maintenance-side repair recommendations) and
   `App\Ontology` (this platform) are different things with confusingly similar names. The boundary — maintenance
   reads the ontology, never the reverse — is documented and should be enforced in review.
