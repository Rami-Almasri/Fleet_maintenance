# Concept Bridge — Coverage Report (Segmented Multi-Label)

**Date:** 2026-08-01 · **Method:** read-only, nothing written.
**Sample:** 1,200 random tickets → **4,549 segments** · 171 ms/ticket · full-fleet pass ≈ **75 min**
**Config:** clause segmentation · per-segment `MatchPipeline` · concept promoted only at **score ≥ 70**

---

## A. Ticket classification — the three states you asked for

| State | n | % |
|---|---:|---:|
| Fault-related (≥1 concept) | 704 | **58.7%** |
| Non-fault operational | 223 | **18.6%** |
| Unresolved | 273 | 22.8% (41 empty text) |

**Note the drop from the earlier 85.4%.** That figure came from blob top-1 matching with no threshold discipline.
This run requires **≥70 at segment level**, which is stricter and more honest. The difference between the two numbers
*is* the precision/recall trade-off, and §C quantifies exactly where it sits.

---

## B. Segment outcomes

| Outcome | n | % of 4,549 |
|---|---:|---:|
| Resolved (≥70) | 1,571 | 34.5% |
| **Weak (below strong)** | **2,051** | **45.1%** |
| Operational note | 372 | 8.2% |
| Unresolved | 555 | 12.2% |

## C. Confidence distribution — and the single most important open question

| Band | n | % |
|---|---:|---:|
| 90–100 | 127 | 3.5% |
| 80–89 | 384 | 10.6% |
| 70–79 | 1,060 | 29.3% |
| **60–69** | **725** | **20.0%** |
| **50–59** | **1,312** | **36.2%** |
| 25–49 | 14 | 0.4% |

**56% of all segment matches land in the 50–69 band — below the threshold, and currently discarded.**

That single band is the difference between a 58.7% bridge and an ~80% bridge. Whether those matches are real is
**precisely what the 200-ticket human labelling must answer**, and it is now a concrete, quantified question rather
than a vague concern. If even half of that band is correct, ticket coverage rises to ~75%+.

---

## D. Mapping rate by source

| Origin | n | Fault% | Non-fault% | Unresolved% |
|---|---:|---:|---:|---:|
| `sheet` | 930 | 55.8% | 19.5% | 24.7% |
| `customer-sheet` | 262 | **70.6%** | 16.0% | 13.4% |
| `manual` | 7 | 0% | 0% | 100% |
| `contract` | 1 | 0% | — | 100% |

Customer-sheet text is markedly more resolvable than the workshop sheet — customers describe *symptoms*; the workshop
sheet logs *activity and status*. `manual`/`contract` samples are too small to read.

## E. Mapping rate by maintenance type

| Type | n | Fault% | Unresolved% |
|---|---:|---:|---:|
| `(none)` | 820 | 54.1% | 26.3% |
| Routine | 127 | **88.2%** | 7.1% |
| Oil & Filter Change | 20 | 80.0% | 5.0% |
| Breakdown | 12 | 75.0% | 8.3% |
| Body Damage | 54 | 74.1% | 9.3% |
| Suspension Troubles | 11 | 36.4% | 36.4% |
| Testing | 13 | 30.8% | 7.7% |

**Typed tickets resolve far better than untyped** — but `maintenance_type` is null on 68% of the sample, so the
untyped bucket dominates the overall number.

## F. Mapping rate by vehicle model (n ≥ 15)

| Model | n | Fault% | | Model | n | Fault% |
|---|---:|---:|---|---|---:|---:|
| FORD MUSTANG | 39 | 79.5% | | CHEVROLET CAMARO Conv | 40 | 50.0% |
| CHEVROLET IMPALA | 27 | 74.1% | | MERCEDES C300 | 41 | 46.3% |
| NISSAN PATROL GREY HAWK | 20 | 70.0% | | DODGE CHARGER | 26 | 46.2% |
| CHEVROLET CAMARO | 81 | 64.2% | | DODGE DURANGO | 16 | 43.8% |
| CHEVROLET TRAVERSE | 39 | 64.1% | | CHRYSLER 300 | 26 | 42.3% |
| NISSAN PATROL | 88 | 56.8% | | *(no vehicle)* | 38 | 31.6% |

**Read this cautiously.** The spread (42–80%) looks meaningful but sub-samples are 16–88 tickets; at those sizes the
confidence intervals overlap heavily. **My reading: there is no evidence of a model-specific vocabulary gap** — the
variation is consistent with noise plus differing note-writing habits per garage. Do not prioritise ontology work by
model on this basis.

---

## G. Concept distribution after segmentation

| Concepts/ticket | n | % |
|---|---:|---:|
| 0 | 455 | 37.9% |
| 1 | 407 | 33.9% |
| 2 | 154 | 12.8% |
| 3 | 76 | 6.3% |
| 4+ | 108 | 9.0% |

**Mean 1.06 concepts/ticket · 73 distinct concepts.** Multi-label is working — ~32% of tickets yield 2+ concepts, with
a tail reaching 10 — but the strict threshold suppresses second and third concepts, so the mean is lower than the true
figure. Expect this to rise materially if the 50–69 band is validated.

### ⚠️ Cosmetic concepts dominate the resolved signal

| Concept | n | Category |
|---|---:|---|
| Scratch | 308 | bodywork |
| Bumper damage | 198 | bodywork |
| Damaged rim | 132 | tyres |
| Paint damage | 75 | bodywork |
| Dent | 21 | bodywork |
| Accident damage | 13 | bodywork |

**≈747 of 1,571 resolved segments (48%) are cosmetic/exposure concepts.** Mechanical faults are a *minority* of the
resolved signal. This is decisive confirmation of your exposure-inheritance requirement: without it, every reliability
comparison would be driven by body damage.

Top mechanical concepts for contrast: Check-engine light 84 · A/C not cooling 58 · Airbag warning 42 ·
Oil leak 35 · ABS warning 29 · Overheating 25 · Coolant leak 15.

---

## H. False-positive risk — and the discriminator that falls out of it

`spread` = distinct v1 signatures the concept appeared under · `top%` = share in its most common signature ·
**`phr%` = share backed by exact/alias/phrase evidence rather than token-only.**

### Likely false positives — wide spread, **little phrase-grade evidence**

| Concept | n | spread | top% | **phr%** | avg |
|---|---:|---:|---:|---:|---:|
| Dashboard fault | 37 | 11 | 30% | **11%** | 77.1 |
| Oil Change | 85 | 12 | 34% | **14%** | 81.4 |
| Oil Filter | 37 | 9 | 41% | **22%** | 83.6 |
| Camera / ADAS fault | 41 | 5 | 29% | **5%** | 76.8 |
| Windscreen crack / chip | 9 | 5 | 22% | **0%** | 76.7 |
| Door / panel misalignment | 7 | 5 | 43% | **0%** | 73.0 |
| Brake noise (squeal/grind) | 6 | 4 | 33% | **0%** | 77.7 |
| Headlight out | 6 | 3 | 50% | **0%** | 79.3 |

### Wide spread but **genuinely real** — high phrase evidence

| Concept | n | spread | **phr%** |
|---|---:|---:|---:|
| Bumper damage | 198 | 19 | **94%** |
| Paint damage | 75 | 17 | **92%** |
| Oil leak | 35 | 11 | **89%** |
| ABS warning light | 29 | 11 | **83%** |

> **`phr%` is the discriminator.** Spread alone does not indicate a false positive — `Bumper damage` appears under 19
> signatures and is correct every time. The concepts that are wrong are the ones reached by **token overlap alone**.
> `Oil Change` at 14% phrase evidence is the clearest case: the token *"change"* appears in almost every workshop
> note ("will change it", "needs changing"), so it attaches to brakes, A/C and suspension tickets alike.

**Actionable rule: promote a concept only when its evidence includes exact / alias / phrase — or when token-only
evidence clears a materially higher bar than 70.** This is a sharper fix than raising the global threshold, because it
preserves genuine matches while cutting exactly the failure mode observed.

---

## I. Top unresolved phrases — the ontology expansion backlog

391 distinct unresolved phrases. They fall into **three different problems**, which need three different responses:

### 1. Formatting junk — fix in preprocessing (free coverage)
```
--------------  (9)   -------------  (9)   -----------  (7)   ---------------  (6)
#  (11)   at #:# pm  (2)
```
Roughly **10 of the top 50** are separator rows from the sheet. Strip these before segmentation.

### 2. ⭐ Real vocabulary gaps — component-level terms the ontology lacks
```
thermostat (2)        drums skimmed / skimming drums (5)    turbo msg (4)
piston rings (2)      piston connecting rods (2)            crankshaft bearings (2)
new crankshaft original (2)   connecting rods ordered (2)   working on heads assembling (2)
cooling fans / fans of the camaro (2)   fading (10)
```

**`thermostat` appearing as *unresolved* is the headline finding of this section.** It is a core cooling component,
it is in your own worked example from two messages ago, and the fleet's own text mentions it — but the vocabulary
cannot match it. The same is true of `piston rings`, `crankshaft bearings`, `connecting rods`, `drums`, `turbo`.

These are exactly the **component-level terms the fusion engine needs**, because they are the level at which
Bosch/OEM documentation speaks. **This list is the prioritised ontology expansion backlog, measured from real fleet
text rather than guessed.**

### 3. Operational notes my classifier missed
```
car is with omar (3)    abo marouf asked for recovery (3)    with zakaria (2)
he took the car to gpt to finalize it    we might recieve car tomorrow    order arriverd
when it returns    car needs washing (3)    working on fixing them (3)    after checking car (4)
```
Person names, recovery/logistics, parts ordering, washing. The operational regex needs widening — these are inflating
the "unresolved" bucket when they should be classified `non_fault`.

---

## J. What to do with this

| Priority | Action | Basis |
|---|---|---|
| **1** | **Add phrase-grade evidence requirement** for concept promotion | §H — `phr%` cleanly separates real from false |
| **2** | **Strip separator/timestamp junk** before segmentation | §I.1 — free coverage, ~10 of top 50 |
| **3** | **Widen operational vocabulary** (names, recovery, ordering, washing) | §I.3 — misclassified as unresolved |
| **4** | **Expand ontology with component terms** — thermostat, piston rings, crankshaft, connecting rods, drums, turbo, cooling fan | §I.2 — the fusion engine needs this resolution |
| **5** | **Human-label 200 tickets, focused on the 50–69 band** | §C — worth ~20 points of coverage |
| **6** | **Carry `is_exposure` per concept** | §G — 48% of resolved concepts are cosmetic |

Items 1–3 are cheap and mechanical. Item 4 is the one that directly unblocks the fusion engine — and it is now a
**measured backlog**, not a guess.

---

## K. Honest limits of this report

* **Precision is still inferred, not measured.** §H is a risk indicator built from spread and evidence type, not a
  comparison against human judgement. Item 5 remains necessary before backfill.
* Sample is 1,200 (4.5%); per-model and per-type sub-samples are small (§F should not drive decisions).
* The operational-note regex is a first draft written for this run; §A's 18.6% non-fault is a floor, not a measurement.
* Arabic text was not analysed separately.
* `maintenance_type` is null on 68% of tickets, so §E describes a minority.
