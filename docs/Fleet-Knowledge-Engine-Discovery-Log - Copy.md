# Fleet Knowledge Engine — Knowledge Discovery & Layer Design

*Second mining pass over the historical corpus, and the four-layer engine the findings justify.*

**Date:** 2026-07-29
**Relationship to existing docs:** `Fleet-Knowledge-Engine-Architecture.md` holds the locked blueprint (10 modules, confidence model, delivery phases). This document does not replace it. It supplies the **measured evidence** that blueprint was designed without, and refines it where the data contradicted an assumption.
**Companion docs:** `Historical-Maintenance-Knowledge-Opportunities.md` (what is reconstructable), `Maintenance-Intelligence-Capability-Design.md` (what to build on top).

---

## Part I — The Knowledge Object

### I.1 The unit of knowledge

The engine's atom is not a maintenance row or an expense line. It is a **Repair Case** — one fault, on one vehicle, at one garage, in one window of time, with everything the fleet learned from it attached.

```
                          ┌──────────────────┐
                          │   REPAIR CASE    │
                          └──────────────────┘
   IDENTITY                       │                    OUTCOME
   ├─ vehicle ────── model ── family ── make            ├─ cost (tier A/B/C)
   ├─ occurred_at                │                      ├─ turnaround (p50/p90 band)
   └─ case_id                    │                      ├─ returned_within_90d ✓/✗
                                 │                      └─ outcome_verdict (forward only)
   FAULT                         │                    CONTEXT
   ├─ signature (canonical, ~22) │                      ├─ garage ── vendor
   ├─ signature_source: human│derived│confirmed         ├─ suppliers
   ├─ symptoms (raw note text)   │                      ├─ parts used
   └─ bundle (co-occurring sigs) │                      └─ season / vehicle age
                                 │
   LINEAGE                       │                    CONFIDENCE
   ├─ preceded_by  (cases ≤180d before)                 ├─ label_confidence
   ├─ followed_by  (cases ≤90d after)                   ├─ cost_link_tier (A/B/C)
   ├─ is_comeback_of (case_id)                          ├─ n_backing
   └─ episode_id (chain of related cases)               └─ evidence_ids[]
```

Two properties of this object matter more than the fields:

**Every edge carries confidence, and confidence is never hidden.** `cost` is Tier A (garage-matched, median 6-day gap), Tier B (date-proximate) or Tier C (vehicle-level only). `signature` is human-labelled, machine-derived, or human-confirmed. A consumer that cannot see the tier will eventually quote a Tier C number as fact.

**Cases chain into episodes.** A comeback is not a new case — it is the same episode continuing. This single modelling decision is what makes D5 and D6 below computable, and it is the thing a row-per-repair schema cannot express.

### I.2 The canonical signature set

Mining produced **22 canonical signatures** that survive validation. They replace the 322 raw `service_main` strings:

```
COOLING · AC · ENGINE_MECH · CHECK_ENGINE · TRANSMISSION · SUSPENSION · STEERING
BRAKES · TYRE · RIM · BATTERY · ELECTRICAL · LIGHTS · GLASS · BODY · INTERIOR
OIL_SERVICE · EXHAUST · FUEL_SYS · KEY · ACCESSORY · LEAK_OTHER
```

`Ready`, `NEW CAR`, `Testing`, `Main reason` are **workflow states, not faults** — they leave the taxonomy entirely (2,645 events are pure status noise).

---

## Part II — The Four Layers

Each layer is defined by what it *owns*, and by the measured capability it can actually deliver today.

### Layer 1 — Historical Reconstruction

**Owns:** turning two half-records into Repair Cases.
**Delivers:** ~10,800 costed repair lines joined to 26,838 events across **234 of 438 vehicles**. Cost linkage tiered A/B/C (Tier A = vehicle + garage token + ≤14d, median gap 6 days).
**Hard boundary:** no historical odometer exists. Layer 1 must never emit a per-km field.

### Layer 2 — Knowledge Extraction

**Owns:** signatures, garage intelligence, supplier intelligence, chronic patterns, seasonality, bundles, progression.
**Delivers — and this is the layer the second mining pass transformed:**

| Capability | Measured today | Was assumed |
|---|---|---|
| Label coverage | **78.7%** (21,114 of 26,838) | 30.5% (human labels only) |
| Labelling method | **rules, 80.5% agreement** with human labels | ML required |
| Garage quality | fault-mix standardised O/E | raw return rate |
| Seasonality | 22 signatures, up to **10.8× swing** | spend-level only |
| Progression | measured, **weak (lift 1.4–2.1)** | assumed strong |

**The headline for this layer: the auto-labelling capability does not need ML to ship.** A rules classifier over `maintenance_notes` + `service_sup` agrees with human labels **80.5%** of the time and lifts coverage from 30.5% to **78.7%** — 13,744 events gain a label they never had. ML becomes a refinement, not a prerequisite. That moves the single biggest enabler out of "Wave 4, hard" into "buildable now".

### Layer 3 — Decision Support

**Owns:** pushing knowledge into the moment of decision — intake, routing, approval, procurement, lifecycle.
**Delivers:** the capability set in `Maintenance-Intelligence-Capability-Design.md`, now re-prioritised by what Layer 2 actually proved (see Part IV).
**Contract:** Layer 3 never computes. It reads Layer 2's materialised knowledge and renders it. Anything that needs a fresh computation at request time is a Layer 2 gap, not a Layer 3 feature.

### Layer 4 — Continuous Learning

**Owns:** capturing every human judgement and every real-world outcome as a labelled example.

| Feedback event | Captured at | Trains |
|---|---|---|
| Signature confirmed / corrected | intake | the classifier — directly closes the 19.5% disagreement |
| Garage recommendation accepted / rejected + reason | dispatch | garage scoring, routing weights |
| Quote approved / challenged / renegotiated | approval | price benchmarks, overcharge thresholds |
| Supplier chosen over the suggested one | procurement | supplier ranking |
| **Repair verdict at close (fixed / not fixed)** | QC gate | **the outcome field that does not exist historically** |
| Return within warranty window | automatic | first-time-fix, garage O/E |
| Predicted vs actual duration & cost | close | duration and cost bands |

**The design rule:** every feedback event is written as an immutable observation with `{case_id, suggestion, human_action, actor, timestamp, reason?}` — never as a mutation of the case. The engine's opinion and the human's decision must both survive, because the delta between them *is* the training signal.

**Why this layer is urgent rather than eventual:** the classifier is at 80.5%. Confirm-or-correct at intake is the cheapest possible labelling mechanism — a technician clicking "yes, cooling" costs nothing and produces a gold label. At even 30 cases a week, the corpus of human labels doubles in under three years, and every statistic in Layer 2 tightens with it.

---

## Part III — Discovery Log

Fifteen findings from the second pass. Each carries confidence, method, and the feature it becomes.

---

### D1 — Rules-based labelling reaches 80.5% agreement; coverage triples
**Confidence: High** (validated on 6,544 events where both labels exist)

A keyword classifier over `maintenance_notes` was validated against the 7,370 human `service_main` labels: **80.5% agreement** (at least one shared signature), with 949 partial matches where the human saw a second fault the rules missed.

Applying it fleet-wide: **coverage 30.5% → 78.7%**, adding **13,744 newly labelled events**. Remaining unlabelled: 5,724, of which 2,645 are pure workflow noise ("car is ready") that *should* stay unlabelled.

**Becomes:** C30 auto-labelling — reclassified from "Wave 4, ML, hard" to **buildable now with rules**, with ML as a later refinement targeting the 19.5% gap.

---

### D2 — The obvious garage quality metric is wrong, and would have punished the best shops
**Confidence: High · This is the most consequential finding in the document**

Raw 90-day return rate ranks garages like this: One Roof **64.4% (worst)**, Power Point 62.3%, Hot Line 63.8% — against Kasr Al Zaiton 27.1% (best).

That ranking is an artifact of **fault mix**, not quality. Return rates vary 5× by fault type (D4): BODY recurs 78.6%, BATTERY 16.1%. One Roof is 63% body work. **Any body shop is guaranteed to look terrible, and any battery shop is guaranteed to look excellent, regardless of how well either works.**

Standardising — expected returns computed from each garage's *own* fault mix, then O/E = observed ÷ expected — inverts the picture:

| Garage | n | Raw rate | **O/E** | Verdict |
|---|---:|---:|---:|---|
| Kasr Al Zaiton | 314 | 27.1% | **0.49** | far better than mix predicts |
| Petro Min Express | 198 | 24.2% | **0.52** | far better |
| Al Reda | 281 | 25.3% | **0.57** | far better |
| 7 Cylinder | 635 | 50.1% | **0.80** | better |
| **Road Force** | 518 | 36.1% | **0.82** | better |
| GPT Garage | 4,781 | 52.5% | 0.92 | par |
| Deals on Wheels | 4,027 | 54.5% | 0.97 | par |
| RMR | 2,104 | 45.9% | 1.00 | par |
| **One Roof** | 1,357 | **64.4%** | **1.08** | **par — not worst** |
| Hot Line | 1,172 | 63.8% | 1.15 | par |
| **Power Point** | 3,925 | 62.3% | **1.20** | worse |
| Alresala Al Zahabia | 204 | 63.7% | 1.30 | worse |
| **Wahat Al Fursan** | 432 | 61.3% | **1.33** | worst |

One Roof moves from *worst of 33* to *par*. Road Force moves from mid-table to top five. Power Point, which looked mid-pack on raw numbers among body shops, is genuinely underperforming its own mix.

**Becomes:** the scoring core of C7/C12. **Design rule: the raw metric must never ship.** Shipping it would damage relationships with the fleet's best specialist shops on the strength of a statistical error.

---

### D3 — Comeback rate varies 5× by fault type, and body "comebacks" are not repair failures
**Confidence: High**

| Signature | n | Recurs ≤90d | | Signature | n | Recurs ≤90d |
|---|---:|---:|---|---|---:|---:|
| BODY | 9,021 | **78.6%** | | COOLING | 1,265 | 38.7% |
| RIM | 2,820 | 62.4% | | ENGINE_MECH | 1,678 | 36.7% |
| ELECTRICAL | 3,095 | 52.4% | | TRANSMISSION | 953 | 33.8% |
| INTERIOR | 2,461 | 49.5% | | AC | 1,081 | 32.6% |
| LIGHTS | 1,936 | 49.1% | | BRAKES | 1,077 | 30.7% |
| STEERING | 1,519 | 44.8% | | GLASS | 588 | 28.2% |
| TYRE | 2,012 | 44.0% | | **BATTERY** | 348 | **16.1%** |

BODY at 78.6% is not a quality signal — it is a **rental exposure** signal. Each is new damage from a new customer, not a failed repair. Feeding it into any quality metric (garage scoring, first-time-fix, technician performance) is a category error.

**Becomes:** the standardisation weights for D2, and a hard exclusion rule — **BODY and RIM are excluded from all repair-quality metrics** and routed to the exposure model (C28) instead.

---

### D4 — Failure cascades: a repair that comes back once is likelier to come back again
**Confidence: Medium-High** (mechanical faults only; BODY/RIM excluded)

| Stage | n | Returns within 90d |
|---|---:|---:|
| First occurrence of a mechanical fault | 4,652 | **46.7%** |
| Already a comeback → returns *again* | 476 | **56.9%** |

**Escalation factor 1.22×.** The second failure is a meaningfully different animal from the first — and 46.7% first-time recurrence for mechanical work is itself a striking number that the fleet-wide 25.9% headline was hiding.

**Becomes:** an escalation rule in C3 — *the first comeback triggers supervisor review and a diagnostic reset, not another dispatch.* The current pattern is to re-dispatch, and the data says re-dispatching is how you get a third visit.

---

### D5 — Switching garages after a failed repair barely helps
**Confidence: Medium · Counterintuitive, and it redirects the fix**

After a repair comes back:

| Action | n | Fails **again** |
|---|---:|---:|
| Sent back to the **same** garage | 384 | **81.3%** |
| Sent to a **different** garage | 1,404 | **77.5%** |

A 3.8-point difference. The instinctive response — "that shop couldn't fix it, try another" — buys almost nothing.

**Interpretation:** the failure is concentrated in the **diagnosis**, not the workshop. Sending the same wrong diagnosis to a different shop reproduces the same wrong repair.

**Becomes:** the strongest argument in this document for C1/C5 (symptom resolver + diagnostic prompter). It reframes the entire quality problem: the fleet's comeback rate is a *diagnostic* problem, and re-routing is treating the symptom.

---

### D6 — A thermal season drives mechanical failure, not just A/C
**Confidence: High** (normalised against monthly fleet activity, so not an activity artifact)

Monthly event index, 100 = flat:

| Signature | J | F | M | A | M | J | J | A | S | O | N | D | Peak |
|---|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|---|
| **AC** | 33 | **17** | 44 | 130 | 140 | **184** | 162 | 149 | 104 | 70 | 66 | 111 | **Jun, 10.8× swing** |
| **ENGINE_MECH** | 50 | 103 | 80 | 134 | 164 | **183** | 89 | 59 | 104 | 80 | 50 | 59 | **Jun, 3.7×** |
| COOLING | 55 | 79 | 102 | 61 | **150** | 102 | 78 | 80 | 124 | 118 | 107 | 124 | May |
| BATTERY | 85 | 110 | 79 | 52 | **137** | 135 | 116 | 132 | 80 | 108 | 129 | 62 | May |

The non-obvious part: **engine mechanical failures peak in the same month as A/C failures, at 3.7× their January rate** — and cooling and battery failures peak *one month earlier*, in May.

That is a causal chain visible in the timing: heat load rises → cooling and battery fail first (May) → engine mechanical failures follow at the peak (June). A/C is the visible symptom of a season that is actually damaging engines.

**Becomes:** C18 reframed. A pre-summer campaign is not an A/C comfort programme — it is **engine-failure prevention**, and it must run in **March–April** to land ahead of the May cooling peak. That is a materially different (and much more valuable) business case than "check the A/C before summer".

---

### D7 — Other seasonal structure worth acting on
**Confidence: Medium-High**

- **SUSPENSION peaks Apr–May (174 / 169), troughs Sep (52)** — 3.3× swing.
- **BRAKES peak Apr (153), trough Nov (51)** — 3× swing, same window as suspension.
- **RIM / STEERING / LIGHTS peak Nov–Jan** (RIM 145 Dec, STEERING 141 Dec, LIGHTS 149 Jan) — the impact-damage cluster runs opposite to the mechanical cluster.
- **EXHAUST peaks Dec (215), troughs Feb (30)** — 7× swing, unexplained; worth a human look.
- **OIL_SERVICE peaks Jan (160) and Sep (148)** — administrative rhythm, not wear.

**Becomes:** a seasonal work-planning calendar in C17/C18 — mechanical work loads spring/summer, impact/cosmetic work loads winter. Workshop capacity and parts stock should be planned against *opposite* curves, not one average.

---

### D8 — Model-specific weaknesses, externally corroborated
**Confidence: High** (lifts vs fleet baseline; families with ≥250 events)

| Model family | Events | Signature lift vs fleet |
|---|---:|---|
| **Range Rover** | 351 | **CHECK_ENGINE 4.01×** · EXHAUST 2.72× · SUSPENSION 2.25× |
| **Jeep Grand** | 369 | **EXHAUST 3.88×** · BRAKES 2.74× · **ENGINE_MECH 2.59×** |
| **Jaguar F** | 535 | **BATTERY 3.83×** · GLASS 2.46× · ACCESSORY 2.24× |
| **Chrysler 300** | 360 | **TRANSMISSION 3.58×** · OIL_SERVICE 1.44× |
| **Dodge Durango** | 418 | **CHECK_ENGINE 3.54×** · **TRANSMISSION 2.79×** · LIGHTS 1.74× |
| **Land Rover** | 279 | **SUSPENSION 3.50×** · COOLING 1.91× · AC 1.84× |
| Chevrolet Traverse | 721 | EXHAUST 3.39× · TRANSMISSION 1.67× |
| Chevrolet Camaro | 1,948 | FUEL_SYS 1.87× · ENGINE_MECH 1.66× · COOLING 1.44× |
| Mercedes GLC | 572 | COOLING 1.73× · CHECK_ENGINE 1.34× |
| Nissan Patrol | 4,207 | ACCESSORY 1.34× · AC 1.29× (**notably flat — a robust platform**) |

**Why this is more than a table:** Land Rover suspension (air suspension), Range Rover electronics, Chrysler/Dodge transmissions and Jaguar batteries are *known* weak points in these platforms. The method independently rediscovered them from 11 years of Dubai rental data. **That is external validation that the signature pipeline is measuring reality**, not noise — which matters far more than any single row.

Equally: Nissan Patrol, the fleet's largest family at 4,207 events, shows **no mechanical weakness above 1.34×**. The most-used platform is also the most robust.

**Becomes:** C25 acquisition intelligence with fault-level detail, and model-aware priors in C1/C2 (*"on a Durango, check-engine is 3.5× baseline — pull codes before quoting"*).

---

### D9 — Repair load peaks at vehicle year 5, and year 0–1 "faults" are not faults
**Confidence: Medium-High**

| Vehicle age | Events | Cars | Events/car | Dominant signature lift |
|---|---:|---:|---:|---|
| 0y | 333 | 17 | 19.6 | ACCESSORY 3.48× |
| 1y | 1,059 | 50 | 21.2 | KEY 3.15× |
| 2y | 1,656 | 73 | 22.7 | FUEL_SYS 1.71× |
| 3y | 2,737 | 98 | 27.9 | KEY 1.63× |
| 4y | 4,365 | 136 | 32.1 | CHECK_ENGINE 1.35× |
| **5y** | 5,703 | 129 | **44.2** | **TRANSMISSION 1.42×** |
| 6y | 3,696 | 94 | 39.3 | BRAKES 1.55× |

Repair load rises steadily and **peaks at year 5 at 2.3× the year-0 rate**, with transmission work concentrating exactly there.

The year 0–1 signal is a trap worth naming: ACCESSORY 3.48× and KEY 3.15× are **fitting-out activity on new arrivals** — GPS, tint, spare keys — not failures. Any age-based reliability model that counts them will conclude new cars are unreliable.

**Becomes:** the age curve behind C23/C26 (disposal before the year-5 transmission window), and an exclusion rule — commissioning activity is tagged `COMMISSIONING`, not a fault.

---

### D10 — The fleet is in near-continuous maintenance contact
**Confidence: High**

Days between consecutive events on the same vehicle (n=4,798): **p10 = 2, p25 = 7, median = 16, p75 = 36, p90 = 65.**
**27.5% of consecutive visits are within 7 days; 70.8% within 30 days.**

A median of 16 days between garage events per vehicle is not a maintenance *schedule*, it is a continuous state. This reframes "downtime" — for many cars the relevant question is not *when will it next go in* but *is it ever really out*.

**Becomes:** the base rate that makes "related case" grouping possible at all — and a warning for C3, because at a 16-day median gap, naive date-proximity linking will merge unrelated visits. Episode grouping must use signature identity, not just recency.

---

### D11 — The road-impact cluster is one job, not four
**Confidence: Medium-High** (within-event co-occurrence lift, artifact-filtered)

| Bundle | n | Lift |
|---|---:|---:|
| STEERING + SUSPENSION | 323 | **3.05** |
| BRAKES + STEERING | 245 | 2.97 |
| STEERING + TYRE | 419 | 2.72 |
| BRAKES + SUSPENSION | 203 | 2.70 |
| BRAKES + TYRE | 293 | 2.68 |
| GLASS + LIGHTS | 144 | 2.51 |

Steering, suspension, brakes and tyres co-occur at ~3× chance across five overlapping pairs. Combined with the expense-side finding (rim spend level with tyre spend, AED 389k on alignment) and D7 (all peak Apr–May), this is one coherent phenomenon: **road impact damage presenting as four separate line items.**

**Becomes:** C4 predicted-parts and C22 bundling — when one of the four is reported, the other three should be inspected in the same visit rather than generating three more visits (feeding D10's 16-day cycle).

---

### D12 — Fault progression is real but weak; do not build prediction on it
**Confidence: Medium — reported as a limit, not a capability**

Naive look-back suggested strong precursors. It was wrong (see M1 below). Properly controlled — exposed vs unexposed vehicles, 180-day window — the strongest honest signals are:

| Precursor → later fault | n exposed | P(exposed) | P(unexposed) | Lift |
|---|---:|---:|---:|---:|
| LEAK_OTHER → FUEL_SYS | 204 | 21.6% | 10.4% | **2.07** |
| KEY → GLASS | 249 | 51.0% | 29.0% | 1.76 |
| EXHAUST → TRANSMISSION | 332 | 52.1% | 32.1% | 1.62 |
| STEERING → FUEL_SYS | 1,519 | 16.5% | 10.0% | 1.65 |
| EXHAUST → ENGINE_MECH | 332 | 60.8% | 42.7% | 1.42 |
| TRANSMISSION → COOLING | 953 | 55.4% | 38.9% | 1.42 |

Lifts of 1.4–2.1 are real but far too weak to drive automated prediction, and several (KEY → GLASS) are more plausibly *shared exposure* — a car that gets broken into needs a key and a window — than mechanical causation.

**Becomes:** a *contextual note* in C2 ("vehicles with recent exhaust work saw transmission work 1.6× more often"), never an alert. **The precursor-prediction capability the earlier design assumed would be strong is not supported by this data.** Honest downgrade.

---

### D13 — Only five vehicles genuinely deviate from their model peers
**Confidence: Medium**

Within model families of ≥5 cars, only **5 vehicles sit ≥2 standard deviations above their peers** on event count:

| Plate | Family | Events | Peer avg | z | Peers |
|---|---|---:|---:|---:|---:|
| 20773 | Nissan Patrol | 299 | 100.2 | 3.0 | 42 |
| 89529 | Chevrolet Camaro | 327 | 108.2 | 2.6 | 18 |
| 70598 | Nissan Platinum | 238 | 50.1 | 2.4 | 7 |
| 87143 | Nissan Patrol | 251 | 100.2 | 2.3 | 42 |
| 94823 | Dodge Charger | 329 | 94.5 | 2.1 | 8 |

Patrol 20773 has **3× its peer group's repair events across 42 comparable cars** — that is not variance, that is a specific vehicle with a specific problem.

**Useful negative:** only five. Most "expensive" cars are expensive because their *model* is expensive (D8) or because they are *used more*, not because they are individually defective. A lemon-detector built on raw cost would flag dozens of cars wrongly; peer-normalisation is what makes the list short enough to act on.

**Becomes:** C24's shortlist — five investigations, not a dashboard.

---

### D14 — Two methodological traps caught, both of which would have shipped
**Confidence: High · Recorded so they are not re-introduced**

**M1 — Density confounding.** A naive look-back reported BODY as a "precursor" of engine failure at **93.5% with lift 2.05**. The tell: BODY scored *exactly 2.05* for engine, transmission and cooling alike. It was measuring "this vehicle has many events", not causation. Any precursor analysis must compare **exposed vs unexposed vehicles**, never precursor frequency against a global base rate.

**M2 — Classifier self-overlap.** CHECK_ENGINE + LIGHTS appeared as the strongest bundle in the fleet (n=481, **lift 6.13**). It is an artifact: the phrase *"check engine light"* matches both patterns. 591 notes contain it. Any co-occurrence analysis over derived labels must first test whether the two extractors can fire on the same substring.

**Becomes:** validation gates in the Layer 2 build. Both traps produced confident, plausible, presentable numbers — which is exactly what makes them dangerous.

---

### D15 — What the corpus still refuses to answer
**Confidence: High**

Unchanged from the first pass, re-confirmed with the expanded label set: **no historical odometer**, **no outcome verdict**, **no labour/parts split**, **no root cause** (notes are symptoms and progress only), and **garage invoices were never stored**. 5,724 events remain unlabelled, of which ~3,079 carry text too vague to classify.

---

## Part IV — What the discoveries change

| Capability | Prior plan | Revised by | New position |
|---|---|---|---|
| C30 auto-labelling | Wave 4, ML, hard | **D1** | **Wave 1, rules, 80.5% today** |
| C7 garage matcher | raw return rate | **D2, D3** | must ship **fault-mix standardised**; raw metric forbidden |
| C3 comeback detector | flag the return | **D4** | first comeback triggers **diagnostic reset**, not re-dispatch |
| C1/C5 diagnosis aids | Wave 4 "nice to have" | **D5** | **promoted** — re-routing doesn't work, diagnosis is the bottleneck |
| C18 pre-summer campaign | A/C comfort | **D6** | **engine-failure prevention**, runs Mar–Apr |
| C17 stocking | one seasonal curve | **D7** | two opposite curves — mechanical vs impact |
| C25 acquisition | cost per make | **D8** | fault-level model weaknesses, externally corroborated |
| C24 chronic detection | cost-ranked list | **D13** | **peer-normalised** — 5 cars, not dozens |
| Precursor prediction | assumed strong | **D12** | **downgraded** to contextual note; no alerts |
| C23/C26 lifecycle | age-agnostic | **D9** | year-5 transmission window is the disposal trigger |

**Net effect:** the single biggest enabler (labelling) got much cheaper, the single most-used metric (garage quality) was saved from a serious error, the diagnosis capabilities got promoted, and one assumed capability (precursor prediction) was honestly downgraded.

---

## Part V — Guardrails for Layer 2

Beyond the guardrails already recorded in the capability design, the mining added four:

1. **Standardise before comparing anything across garages, models or people.** Raw rates encode workload mix. D2 is the proof: the correction inverted the ranking.
2. **Exclude exposure-driven signatures from quality metrics.** BODY and RIM measure customers, not workshops (D3).
3. **Test every derived-label correlation for extractor overlap** before reporting it (M2).
4. **Compare exposed vs unexposed, never against a global base rate**, when the population has wildly varying activity levels (M1).

And one that is cultural rather than statistical: **the two traps in D14 both produced numbers that looked like discoveries.** "Body damage predicts engine failure at 93.5%" is exactly the kind of finding that gets into a slide before anyone checks it. The engine's credibility depends on catching these internally — every Layer 2 statistic should ship with the confound it was tested against, recorded next to it.

---

## Appendix — Reproduction

Scripts in session scratchpad: `kb.js` (signature classifier + human-label validation), `mine.js` (progression, comeback, garage, model, seasonality, bundles), `mine2.js` (confound controls, fault-mix standardisation, peer deviation, cascade, age).

```
events                    26,838      labelled 21,114 (78.7%)   validation agreement 80.5%
canonical signatures      22          (from 322 raw service_main strings)
garages scored            29          (n>=150 signature-events each)
model families scored     17          (n>=250 events each)
vehicles with events      247         peer-deviant (>=2sd) 5
mechanical comeback       46.7% first / 56.9% second  (escalation 1.22x)
same-garage retry         81.3% fail again  vs  77.5% different garage
thermal peak              AC index 184 (Jun) vs 17 (Feb) = 10.8x ; ENGINE_MECH 183 (Jun) = 3.7x
repair load peak          vehicle year 5, 44.2 events/car
```
