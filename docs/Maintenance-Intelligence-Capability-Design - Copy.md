# Maintenance Intelligence — Capability Design

*Every decision-support capability that becomes possible once the historical repair reconstruction engine exists.*

**Date:** 2026-07-29
**Premise:** the reconstruction engine from `docs/Historical-Maintenance-Knowledge-Opportunities.md` is live — costed repair records (Tier A/B/C), ~25 canonical repair signatures, garage/vendor resolution, duration series, repeat-failure detection.
**Scope:** capability design. No UI, no schemas, no code. Ranked for build order.
**Not in scope:** dashboards, charts, reports. Every capability below must change a decision at the moment it is made, or it does not belong here.

---

## 0. The thesis, stated once

The engine's value is not that it *knows* history. It is that it can put history in front of a person **at the moment they are about to make an expensive, irreversible decision** — and that moment is always one of six:

| # | Moment | Who | The decision | What it costs to get wrong |
|---|---|---|---|---|
| **M1** | Intake & diagnosis | Technician / inspector | *What is actually wrong?* | Misdiagnosis → comeback → **25.9% return rate** |
| **M2** | Routing & dispatch | Supervisor | *Where do I send it?* | Wrong shop → longer, dearer, returns |
| **M3** | Quote & approval | Manager | *Is this price fair?* | Overpayment, silent price drift |
| **M4** | Procurement | Buyer | *What do I buy, from whom, when?* | Stockouts, panic buying, supplier spread |
| **M5** | Lifecycle | Owner / fleet manager | *Keep, fix, or sell?* | **Top 10% of cars = 40% of spend** |
| **M6** | Prevention | Ops | *What breaks next?* | Breakdowns, downtime, lost rental days |

A second thesis, which should shape the architecture:

> **Most of the money here is unlocked by statistics and rules, not by AI.**
>
> AI earns its place in exactly one layer — **turning free text into structure** (25,602 notes, 28,327 remarks, garage invoices) and **retrieving similar cases**. Everything downstream of that is arithmetic on a clean table. Building this as "an AI project" would put the hard, fragile part first and the valuable, reliable part last.

The honest split across the 28 capabilities below: **11 pure rules, 9 statistical, 5 LLM/embedding, 3 blocked on forward data.**

---

# M1 — Intake & Diagnosis

*The highest-leverage moment. A misdiagnosis here costs a full second repair cycle, and the data says that happens a quarter of the time.*

### C1. Symptom → Repair Signature Resolver
**What:** technician types or dictates what they observe — *"leak from the bottom of the radiator"*, *"pulls left after tyre change"* — and the system resolves it to one of ~25 canonical signatures, with alternates ranked.
**Decision changed:** the case is classified correctly at minute one instead of at invoice time, so every downstream capability fires.
**How:** embedding search over the **11,419 symptom-bearing notes**, plus a supervised classifier trained on the **8,191 `service_main` labels**. LLM as fallback for the long tail.
**Why it works here:** the corpus is 99.3% English, median 62 chars, and genuinely diagnostic. This is the one place where the text layer is unavoidable — no keyword list survives *"tires hitting chassie body"*.
**Cold start:** if confidence <0.6, show top 3 and let the human pick — every pick becomes a new training label.
**Impact H · Feasibility Med · Confidence Med-High · Requires: LLM/embeddings**

### C2. Similar Historical Repairs (case retrieval)
**What:** the panel from the previous document, but as an input to a decision rather than a report: *n similar repairs · cost p50/p90 · turnaround p50/p90 · garage distribution · parts commonly used · 90-day return rate* — every figure with its sample size.
**Decision changed:** the technician sees what this job has historically become before committing to a diagnosis.
**How:** retrieval over the reconstructed records, filtered by signature → then make → then model, widening until n ≥ 20.
**Guardrail:** below n = 8, show the cases individually, never a statistic. A "median cost" backed by four observations destroys trust in the whole engine the first time someone checks it.
**Impact Very High · Feasibility Low-Med · Confidence High · Requires: rules + retrieval (no AI)**

### C3. Comeback Detector at Intake
**What:** the moment a car is logged in, the system checks whether the same signature was repaired recently, and says so: *"same fault closed 34 days ago at One Roof — this is a comeback, not a new job."*
**Decision changed:** the job is reopened against the original repair (warranty/rework conversation with the garage) instead of being paid for twice.
**How:** pure lookup. Base rates already measured — **16.8% within 30d, 25.9% within 90d, 31.0% within 180d**.
**Why it's near the top:** trivial to build, and it directly attacks the single largest measured inefficiency in the fleet.
**Impact Very High · Feasibility Very Easy · Confidence High · Requires: rules only**

### C4. Predicted Parts List ("what this job will need")
**What:** given the signature and vehicle model, predict the parts the job will consume, ranked by historical frequency, so they can be ordered *before* the car reaches the garage.
**Decision changed:** parts are pre-staged instead of discovered mid-repair — the main controllable driver of turnaround.
**How:** frequency table per signature × model, reinforced by the measured basket lifts (**alignment+tyre 10.6, alignment+rim 8.9, bumper+paint 8.1, engine+oil 7.8**).
**Limit:** part naming from historical text is ~80% precise for commodity items and poor for the long tail. Ship for the top ~40 part names only.
**Impact Very High · Feasibility Med · Confidence Med · Requires: statistical**

### C5. Diagnostic Prompter ("check this first")
**What:** for the resolved signature, surface what similar historical cases *turned out to be*, and the checks that distinguished them — *"12 of 31 'overheating' cases were the radiator; 9 were the water pump; 6 were a fan/thermostat. Check the fan clutch before quoting a radiator."*
**Decision changed:** raises first-time-fix rate by attacking the misdiagnosis path directly.
**How:** LLM summarisation over retrieved note clusters, with every claim citing the case IDs it came from.
**The honest caveat:** notes record **symptoms and progress, never root cause**. This capability infers a cause distribution from what was *done*, which is a proxy. It must read as *"historically these were repaired as…"*, never *"the cause is…"*.
**Impact High · Feasibility Med-High · Confidence Med · Requires: LLM**

### C6. Escalation Risk Flag
**What:** *"jobs that open as 'Check Engine Light' on a Patrol exceed AED 5,000 in 23% of cases and exceed 7 days in 18%."*
**Decision changed:** sets expectations before a customer is promised a car back, and triggers earlier supervisor attention on the cases that historically run away.
**How:** conditional distributions over reconstructed records — no model needed, just the tail of the cost/duration distribution per signature.
**Impact High · Feasibility Low · Confidence Med-High · Requires: statistical**

---

# M2 — Routing & Dispatch

*The measured specialization here is unusually clean, which makes this the most defensible AI-free win in the document.*

### C7. Evidence-Based Garage Matcher
**What:** given a signature, rank garages by a composite of **historical return rate (quality proxy), cost percentile, and turnaround** — not by a hand-maintained routing rule.
**Decision changed:** replaces "where do we usually send it" with "where does this specific fault historically go best".
**Evidence it works:** the specialization measured is not noise —

| Garage | Events | Concentration |
|---|---:|---|
| ROAD FORCE | 144 | **48% suspension**, 18% braking |
| One Roof | 230 | **63% body damage** |
| ALTIQNIAH AL ALIAH | 234 | **30% engine mechanical**, 14% check-engine |
| POWER POINT | 177 | electrical 18%, airbag 12%, ABS 12% |
| RMR | 452 | cooling/engine — and **avg AED 985/line**, the high-ticket shop |

**Integration:** this is the data-driven half of `GarageRecommendationService`; the existing rules-based `GarageRoutingService` stays as the override/constraint layer (contracts, distance, blacklists).
**Impact Very High · Feasibility Low-Med · Confidence High · Requires: rules + statistics (no AI)**

### C8. Route-Risk Warning
**What:** flags a dispatch that contradicts history — *"suspension work → a body shop; historically 2.1× the return rate and 1.6× the turnaround."*
**Decision changed:** stops the routine mis-routes that a busy supervisor makes at 6pm.
**How:** compare proposed garage's historical performance on this signature against the fleet's best available. Warn, never block.
**Impact High · Feasibility Very Easy · Confidence Med-High · Requires: rules only**

### C9. Turnaround Promise Engine
**What:** auto-fills `expected_completion_date` from the signature × garage duration distribution, and states the risk band: *"p50 1 day, p90 6 days."*
**Decision changed:** the promise made to ops/customer is grounded, and the existing checkpoint/SLA escalation machinery finally has a defensible baseline instead of a guessed date.
**Evidence:** 6,848 durations, and they discriminate — Interior p50 4.0d / p90 **16.0d** vs Periodic Maintenance p50 0d / p90 0d.
**Non-negotiable caveat:** `actual_in_date` is effectively *back-to-park* date, so this predicts **turnaround, never labour hours**, and must always publish p90 alongside p50. The p90 is where the operational risk lives.
**Impact High · Feasibility Low · Confidence Med · Requires: statistical**

### C10. Split-Dispatch Advisor
**What:** a ticket carrying faults across domains (body + electrical + suspension) is checked against the reality that no single garage in the data does all three well; recommends the split and the sequence.
**Decision changed:** avoids the "one shop tries everything" pattern that produces long stays and partial fixes.
**How:** signature set → per-garage capability coverage → minimal-garage cover with sequencing by dependency (mechanical before paint).
**Integration:** feeds the existing split-dispatch feature, which currently has no intelligence behind the choice.
**Impact Med-High · Feasibility Med · Confidence Med · Requires: rules**

### C11. Garage Load & Responsiveness Awareness
**What:** tempers routing with recent throughput — *"Road Force is the right shop for this, but its last 5 jobs averaged 9 days against a 2-day norm."*
**Decision changed:** prevents piling work onto a shop that is currently underwater.
**How:** rolling window over event dates per garage vs its own historical baseline.
**Impact Med · Feasibility Low · Confidence Med · Requires: rules**

---

# M3 — Quote & Approval

*Where the engine converts knowledge directly into cash.*

### C12. Quote Sanity Check / Overcharge Detector
**What:** an incoming quote or invoice line is scored against the historical distribution for that signature × model × garage: *"AED 900 for a battery — historical median 255, IQR 229–375, n=156. This is the 99th percentile."*
**Decision changed:** the manager challenges the line before paying it, with evidence.
**Where it is reliable, and where it is not** — the boundary is a property of the part, not the data:

| Reliable (low dispersion) | n | Median | CV |
|---|---:|---:|---:|
| Battery | 156 | AED 255 | **0.38** |
| Spare key | 55 | AED 400 | tight IQR 350–400 |
| Wheel alignment | 81 | AED 150 | 0.79 |

| Unreliable (part+labour+severity conflated) | CV |
|---|---:|
| Bumper | 1.13 |
| Radiator | 0.84 |

**Ship it only for a curated shortlist (~10–15 commodity items) at launch.** Extending it to variable-severity work produces false accusations, which is worse than no feature.
**Impact Very High · Feasibility Low-Med · Confidence High (shortlist) / Low (general) · Requires: statistical**

### C13. Approval Auto-Tiering
**What:** quotes below the historical p50 for their signature auto-approve; p50–p90 needs one approval; above p90 needs a supervisor plus a written justification.
**Decision changed:** removes rubber-stamping from routine work and concentrates human scrutiny on the genuine outliers.
**How:** thresholds straight off the reconstructed distributions. Pure rules.
**Second-order benefit:** turnaround improves, because most jobs stop waiting in an approval queue.
**Impact Very High · Feasibility Low · Confidence Med-High · Requires: rules only**

### C14. Price-Drift Monitor
**What:** separates **market inflation** from **supplier creep** by comparing a garage's price trend for a part against the fleet-wide trend for the same part.
**Decision changed:** turns a renegotiation from opinion into evidence.
**Evidence the signal exists:** battery median moved **AED 250 (pre-2022) → 438 (2024+), +75%**, while alignment stayed flat (150 → 143). Those are different stories and the engine can tell them apart.
**Impact High · Feasibility Low · Confidence Med-High · Requires: statistical**

### C15. Historical Duplicate-Spend Detector
**What:** extends the existing duplicate-spend warning backwards across the full 11-year reconstruction: *"this alternator was replaced on this car 4 months ago, at a different garage."*
**Decision changed:** catches the rebilled part and the premature failure — both of which are actionable, and neither of which is currently visible.
**Impact High · Feasibility Very Easy · Confidence Med-High · Requires: rules only**

### C16. Invoice Line-Item Extractor
**What:** LLM parses a garage's free-text or photographed invoice into structured lines (part | labour | qty | unit price | total), pre-filled for a human to confirm.
**Decision changed:** this is the capability that **fixes the dataset going forward.** The single biggest historical gap — labour vs parts is unseparable, `parts_total` filled on 6 of 26,838 rows — closes permanently from the day this ships.
**Why it belongs in an AI design doc:** it is the highest-value use of an LLM here, and its value is compounding rather than immediate.
**Guardrail:** extraction proposes, a human disposes. Never auto-post money.
**Impact Very High (compounding) · Feasibility Med · Confidence Med · Requires: LLM**

---

# M4 — Procurement

### C17. Seasonal Demand Forecaster
**What:** forecasts part demand by month from measured seasonality × fleet composition.
**Decision changed:** stock before the season instead of panic-buying during it.
**Evidence:** **A/C spend peaks 5.8× from January (13.3k) to June (77.1k)**, elevated June→October. Battery peaks August–November. Tyres are aseasonal — which is itself a finding: tyre spend is impact-driven, not weather-driven, so it cannot be forecast this way.
**Impact High · Feasibility Low · Confidence High (A/C, battery) · Requires: rules**

### C18. Pre-Season Campaign Generator
**What:** converts the forecast into a work list — *"inspect A/C on these 143 cars during April–May"*, prioritised by each car's own A/C history and rental exposure.
**Decision changed:** shifts A/C failures from June breakdowns (lost rental days, angry customer) to April scheduled work.
**Impact Very High · Feasibility Low · Confidence Med-High · Requires: rules**

### C19. Supplier Negotiation Brief
**What:** auto-generated, evidence-backed brief per supplier: what we buy, volumes, our price vs fleet median, spread, trend, and the annualised opportunity.
**Decision changed:** procurement walks into a renegotiation with numbers instead of impressions.
**Constraint:** supplier attribution from historical text tops out at **~54% coverage** and requires a **hand-curated alias list** — token-matching the `vendors` table returns a confident-looking 61.7% that is garbage ("Fine Land Spare Parts" captures every traffic fine). State coverage on the brief.
**Impact High · Feasibility Med · Confidence Med · Requires: rules + LLM (drafting only)**

### C20. Supplier Consolidation Recommender
**What:** finds the same part bought from many suppliers at materially different prices, and quantifies consolidation.
**Decision changed:** fewer suppliers, better rates, less price variance.
**Impact Med-High · Feasibility Low · Confidence Med · Requires: statistical**

### C21. New-vs-Used Sourcing Advisor
**What:** the vendor list contains a large used/scrap-parts market (dozens of Arabic-named used-parts dealers). Recommends new vs used per part by criticality, historical failure-after-fit, and the value of the vehicle.
**Decision changed:** formalises a sourcing choice currently made ad hoc per buyer.
**Blocked on:** failure-after-fit needs the forward outcome field — until then this can rank *cost* but not *risk*.
**Impact Med-High · Feasibility Med · Confidence Low today · Requires: statistical — Phase Future**

### C22. Bundle Purchasing
**What:** buy commonly co-consumed work as a package (alignment with every tyre job — **lift 10.6**).
**Impact Med · Feasibility Very Easy · Confidence Med-High · Requires: rules only**

---

# M5 — Vehicle Lifecycle

### C23. Repair-vs-Replace Scoring Engine
**What:** per vehicle — lifetime repair cost, trajectory, chronic-fault load, remaining earning capacity, residual value — into a single keep/fix/sell recommendation with the reasoning shown.
**Decision changed:** the most expensive decision in the fleet, currently made on instinct.
**Evidence the target list is small:** **top 10% of cars carry 40% of repair spend; top 25% carry 73%.** This is a watchlist of ~37 vehicles, not a research programme. Charger 74802 alone: 167 repair lines, AED 113,058.
**Integrates with:** `DepreciationService`, purchase-price source, `RealProfitService` for per-vehicle yield.
**Impact Very High · Feasibility Med · Confidence Med-High · Requires: rules + statistical**

### C24. Chronic Vehicle Detection with Exposure/Reliability Separation
**What:** flags the **370 measured chronic vehicle+fault pairs** — but classifies each as *reliability* or *exposure* before acting.
**Why this distinction is the whole feature:** Body Damage recurring **29×** on a convertible Mustang is a rental-exposure signal (who rents it, how it's driven). Engine mechanical **26×** on one Patrol is a reliability signal. Conflating them means flagging every convertible as a lemon and every lemon as bad luck.
**How:** signature class + `liable_party` + damage-vs-fault taxonomy. Rules, with the caveat that `liable_party` is only 21% filled.
**Impact Very High · Feasibility Med · Confidence Med-High · Requires: rules**

### C25. Model-Level Acquisition Intelligence
**What:** feeds the *next purchase* decision with lifetime cost per model, not just per car.
**Evidence:** **Dodge AED 36,049/car and BMW 35,208/car vs Kia-class ~5,700/car.** Whether that is acceptable depends on rental yield per model — a join `RealProfitService` can already supply.
**Decision changed:** fleet composition, which is the largest-value decision the company makes and currently has no maintenance evidence behind it.
**Impact Very High · Feasibility Low-Med · Confidence Med-High · Requires: rules**

### C26. Pre-Sale Timing Advisor
**What:** identifies vehicles approaching their historically expensive window and recommends disposal before it.
**Blocked on:** needs the age/mileage failure curve — and **there is no historical odometer**, so age-only curves are weak.
**Impact High · Feasibility Med · Confidence Low today · Requires: statistical — Phase Future**

---

# M6 — Prevention & Foresight

### C27. Fleet-Wide Fault Cluster Alarm
**What:** detects the same signature appearing across several vehicles of the same model in a short window — *"3 Patrols, cooling system, 6 weeks"* — indicating a batch defect, a bad parts lot, or a garage doing poor work.
**Decision changed:** catches systemic problems while they are three cars instead of thirty.
**How:** temporal clustering by model × signature against each model's own baseline rate. No AI needed.
**Impact High · Feasibility Med · Confidence Med · Requires: statistical**

### C28. Exposure Scoring (driver / customer segment)
**What:** attributes impact-driven damage to who was using the car. The data strongly implies this is worth doing: rim spend runs level with tyre spend (~642k vs ~629k) plus **AED 389k on alignment** — rims do not wear out, they get kerbed.
**Decision changed:** pricing, deposits, and driver coaching — moving cost recovery to where the cost is generated.
**Blocked on:** joining repairs to the contract/driver in force at the time. Feasible via contract date windows, but attribution must be conservative — this touches customers and staff.
**Impact High · Feasibility Med-High · Confidence Med · Requires: rules — handle as sensitive**

### C29. Deferred-Maintenance Escalation Risk
**What:** for a fault someone wants to defer, states what deferral historically cost: *"deferred cooling faults returned as engine work in 31% of cases, at 4.2× the cost."*
**Decision changed:** makes the defer/fix trade-off explicit at the moment of deferral, feeding the existing deferred-maintenance flag.
**Impact High · Feasibility Med · Confidence Med · Requires: statistical**

---

# Cross-Cutting AI Layer

### C30. Historical Auto-Labelling (backfill)
**What:** classify the **18,647 unlabelled** maintenance events using a model trained on the **8,191 labelled** ones.
**Why it is foundational:** it roughly triples the sample size behind every statistic in this document, which is what moves capabilities from "n too small" to "shippable".
**Expected quality:** 75–85% accuracy on the head signatures; worse on the tail. Must be stored as `derived` with a confidence score, never overwriting a human label.
**Impact Very High (as an enabler) · Feasibility High · Confidence Med · Requires: ML**

### C31. Natural-Language Fleet Query
**What:** *"which cars had gearbox work twice in a year?"* → query, answer, and the underlying rows.
**Decision changed:** removes the analyst bottleneck between a manager's question and the data.
**Guardrail:** generate a **constrained query against the reconstructed tables**, show it, and never let the model narrate numbers it did not retrieve.
**Impact Med-High · Feasibility Med · Confidence Med · Requires: LLM**

### C32. Vehicle Repair Narrative
**What:** a readable history summary for handover, sale, insurance or dispute — *"this car, in 4 years: 3 cooling repairs, a gearbox rebuild, 11 body incidents."*
**Decision changed:** supports sale pricing and insurance/liability conversations that currently rely on memory.
**Guardrail:** strictly extractive — summarise retrieved records only, never infer events.
**Impact Med · Feasibility Low-Med · Confidence Med-High · Requires: LLM**

---

# Master Ranking

**Impact:** effect on money / time / first-time-fix. **Feasibility:** 1 = trivial, 5 = research project. **Confidence:** would I defend this output to the CEO. **Type:** Rules / Stat / ML / LLM.

## Tier 1 — Build first (high impact, low difficulty, high confidence)

| # | Capability | Impact | Feas. | Conf. | Type |
|---|---|---|---|---|---|
| C3 | Comeback detector at intake | **Very High** | 1 | **High** | Rules |
| C13 | Approval auto-tiering | **Very High** | 1 | Med-High | Rules |
| C7 | Evidence-based garage matcher | **Very High** | 2 | **High** | Rules+Stat |
| C2 | Similar historical repairs | **Very High** | 2 | **High** | Rules |
| C12 | Quote sanity check *(shortlist only)* | **Very High** | 2 | **High** | Stat |
| C15 | Historical duplicate-spend detector | High | 1 | Med-High | Rules |
| C8 | Route-risk warning | High | 1 | Med-High | Rules |
| C18 | Pre-season campaign generator | **Very High** | 2 | Med-High | Rules |
| C23 | Repair-vs-replace scoring | **Very High** | 3 | Med-High | Rules+Stat |
| C24 | Chronic detection w/ exposure split | **Very High** | 3 | Med-High | Rules |
| C25 | Model-level acquisition intelligence | **Very High** | 2 | Med-High | Rules |

## Tier 2 — Build next

| # | Capability | Impact | Feas. | Conf. | Type |
|---|---|---|---|---|---|
| C30 | Historical auto-labelling backfill | **Very High** (enabler) | 4 | Med | ML |
| C1 | Symptom → signature resolver | High | 3 | Med-High | LLM |
| C16 | Invoice line-item extractor | **Very High** (compounding) | 3 | Med | LLM |
| C4 | Predicted parts list | **Very High** | 3 | Med | Stat |
| C9 | Turnaround promise engine | High | 2 | Med | Stat |
| C14 | Price-drift monitor | High | 2 | Med-High | Stat |
| C6 | Escalation risk flag | High | 2 | Med-High | Stat |
| C17 | Seasonal demand forecaster | High | 2 | High | Rules |
| C29 | Deferred-maintenance escalation risk | High | 3 | Med | Stat |
| C27 | Fleet-wide fault cluster alarm | High | 3 | Med | Stat |
| C19 | Supplier negotiation brief | High | 3 | Med | Rules+LLM |
| C10 | Split-dispatch advisor | Med-High | 3 | Med | Rules |
| C22 | Bundle purchasing | Med | 1 | Med-High | Rules |

## Tier 3 — Later / lower leverage

| # | Capability | Impact | Feas. | Conf. | Type |
|---|---|---|---|---|---|
| C5 | Diagnostic prompter | High | 4 | Med | LLM |
| C31 | Natural-language fleet query | Med-High | 3 | Med | LLM |
| C20 | Supplier consolidation | Med-High | 2 | Med | Stat |
| C11 | Garage load awareness | Med | 2 | Med | Rules |
| C32 | Vehicle repair narrative | Med | 2 | Med-High | LLM |
| C28 | Exposure scoring *(sensitive)* | High | 4 | Med | Rules |

## Tier 4 — Blocked on data that does not exist yet

| # | Capability | Blocked on |
|---|---|---|
| C21 | New-vs-used sourcing advisor | failure-after-fit — needs the forward **outcome** field |
| C26 | Pre-sale timing advisor | **no historical odometer** — needs forward capture |
| — | Failure prediction / survival model | needs C30 + odometer + 2 yrs clean forward data |
| — | True garage success scoring | needs the QC verdict the new workflow captures |

---

# Build Sequence

**Wave 1 — the zero-AI wins.** C3, C13, C15, C8, C22. Five capabilities, all pure rules over the reconstructed table, all attacking measured waste (25.9% comeback rate, unscrutinised approvals, duplicate spend). This wave should ship before anyone writes a model.

**Wave 2 — the decision surfaces.** C2, C7, C12, C9. The technician panel, the garage matcher, the quote check, the promise engine. These are what make the engine visible to the people doing the work.

**Wave 3 — the manager layer.** C23, C24, C25, C18, C17. Lifecycle and procurement decisions, where the largest single amounts are decided.

**Wave 4 — the text layer.** C30 first (it multiplies every earlier statistic), then C1, C16, C4. Deliberately last: it is the hardest, the most fragile, and the least valuable per unit of effort — despite being the part that sounds most like "AI".

**Continuously — close the forward gaps.** Odometer at every touch, outcome verdict at every close, mandatory vendor on every line, itemised invoice via C16. Each one converts a Tier 4 blocker into a Tier 2 capability, and none can be backfilled later.

---

# The Flywheel

The historical data **bootstraps** the engine; the new workflow **compounds** it.

| Gap today | Closed by | Unlocks |
|---|---|---|
| No outcome field | QC verdict at close | true garage scoring, C21 |
| No odometer | capture at every touch | cost/km, failure curves, C26, survival models |
| Labour/parts conflated | C16 extractor | true part benchmarks beyond the commodity shortlist |
| 23% of spend has no vendor (**AED 1.15M**) | mandatory vendor field | supplier intelligence past the 54% ceiling |
| 69% of events unlabelled | C30 + forced label at close | ~3× sample size behind every statistic |

Every human decision the engine assists should be written back as a label. The technician confirming a signature, the supervisor overriding a routing suggestion, the manager rejecting a quote — each is a training example the fleet is currently throwing away.

---

# Guardrails

These are design constraints, not caveats. Breaking any one of them is how this engine loses the trust it needs to be used at all.

1. **Never present a proxy as a fact.** Return rate is not success. Turnaround is not labour time. Recurrence on a convertible is exposure, not unreliability. Each is a legitimate *signal* and none is a *verdict* — and the difference matters most in C7, C12 and C24, which touch supplier and staff relationships.
2. **Always show n and coverage.** Every output rests on a subset — **234 of 438 cars**, 8,191 of 26,838 labels, 54% supplier coverage. A number without its sample size is trusted more than it deserves. This is also what the standing Data-Origin rule requires.
3. **Recommend, never auto-execute.** No auto-dispatch, no auto-posted money, no auto-rejected invoice. Every capability proposes; a person disposes. The only exception is C13's auto-approve *below* p50, which is bounded and reversible.
4. **Keep derived data physically separate from recorded data.** Auto-labels, predictions and confidences live in derived columns with a source flag — never overwriting what a human recorded. This preserves the standing "treat data as source of truth" rule: the engine adds a *layer*, it does not edit the record.
5. **Degrade visibly.** Below n = 8, show the individual cases instead of a statistic. Silence is better than a confident wrong number.
6. **Handle C28 as sensitive.** Attributing damage to drivers or customer segments has consequences for real people. Conservative thresholds, human review, and no automated penalty.

---

# What I would deliberately *not* build

- **A chatbot as the primary interface.** The decisions above happen inside existing screens at specific moments. A chat box makes the user do the work of knowing what to ask; C2, C3 and C8 fire without being asked, which is the whole point.
- **A general cost-prediction model.** The variance is dominated by severity, which is not recorded. A "predicted cost AED 3,420" that is routinely 3× off destroys credibility. Distributions with percentiles (C6, C12) are honest and nearly as useful.
- **Root-cause inference presented as diagnosis.** The notes record symptoms and progress. Anything stronger than *"historically repaired as…"* is fabrication.
- **Anything that quietly narrows scope.** If a capability covers only the 234 joined cars, it says so on the screen.
