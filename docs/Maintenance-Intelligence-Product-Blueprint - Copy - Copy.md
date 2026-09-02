# Maintenance Intelligence Product Blueprint

*The intelligence operating model of the maintenance platform. The last document before production code.*

**Date:** 2026-07-29
**Series position:** L1 `Historical-Maintenance-Knowledge-Opportunities` → L2 `Fleet-Knowledge-Engine-Discovery-Log` → L3 `Maintenance-Intelligence-Capability-Design` → L3.5 `Operational-Intelligence-Layer-Spec` → **this**.
**Scope:** every state in the live maintenance workflow (`Maintenance::WF_*`), every decision in it, and the intelligence that fires there. Actors are the real permission gates: `maintenance.initiate` (Inspector), `maintenance.delegate` (Supervisor), `maintenance.logistics` (Driver), `maintenance.manage` (Controller/Management), `parts.*` (Procurement).

---

# Part 0 — The inversion

The platform exposes **decisions**, not knowledge. Knowledge is an implementation detail of a decision, the way an index is an implementation detail of a query.

This inverts the usual build order. We do not ask *"what do we know that we could show?"* — that question always terminates in a dashboard, because everything we know is showable. We ask *"what decision is being made right now, and what single fact would most change it?"*

Three consequences, and they are strict:

1. **A screen has a card budget, not a card list.** One primary card, at most two secondary. Everything else is behind a deliberate click and is *not* considered shown.
2. **Statistical significance does not earn display.** Decision leverage does. A 25.9% comeback rate that changes what the supervisor does next outranks a p<0.001 correlation that changes nothing.
3. **A card that would not degrade a decision by its absence is deleted.** Not collapsed, not moved to a tab. Deleted.

---

# Part 1 — The Decision Engine

Sits above the Knowledge Engine. It does not discover anything. It answers exactly one question:

> **Of everything we know about this moment, what is the one thing this user needs?**

## 1.1 Hard precedence tiers

Arbitration is **not** a single blended score. It is tiered, because some categories must never lose to a better-scoring member of a lower category. Within a tier, scoring decides; across tiers, precedence does.

| Tier | Class | Rationale | Example |
|---|---|---|---|
| **P0** | Safety / roadworthiness | never outrankable | grounded fault, brake/airbag defect |
| **P1** | Irreversible or money-at-risk | hard to undo once done | quote above p90; 3rd comeback escalation gate |
| **P2** | **Rework prevention** | highest measured ROI in the fleet | comeback warning, cascade escalation |
| **P3** | Routing quality | measured, large differential | garage O/E recommendation |
| **P4** | Execution efficiency | time, not money | parts pre-stage, turnaround promise |
| **P5** | Cost optimisation | recoverable if missed | price benchmark, supplier choice |
| **P6** | Context / baseline | orientation only | similar-repairs summary |

**Why P2 sits above routing and cost:** it is the only tier where we have measured both the size of the problem (46.7% mechanical comeback) and the ineffectiveness of the instinctive response (re-routing changes 81.3% → 77.5%). Nothing else in the fleet has that combination of magnitude and evidence.

## 1.2 Scoring within a tier

```
score = decision_leverage × confidence_weight × actionability × recency_decay

decision_leverage   0–1   how much the decision changes if the card is right
confidence_weight   strong 1.0 · moderate 0.7 · limited 0.4
actionability       1.0 if the card offers an action the user can take here
                    0.5 if it only informs a choice they were making anyway
                    0.0 if there is no action at this state  → card is not eligible
recency_decay       0.5^(overrides_same_reason_30d / 3)      ← anti-fatigue
```

`actionability = 0` is an eligibility gate, not a penalty. **If the user cannot act on it at this state, it does not render at this state.** This single rule removes most of what would otherwise become dashboard drift.

## 1.3 Anti-fatigue

A card overridden three times in 30 days for the same reason halves in score; six times, it stops rendering for that user and raises a **calibration flag** for review. A card that is always dismissed is either wrong or badly timed, and the platform should discover that about itself rather than wait to be told.

## 1.4 What the Decision Engine is forbidden from doing

- Showing more than the budget, even when many cards are eligible.
- Rendering a card whose evidence falls below its declared minimum (§3).
- Re-ranking based on acceptance rate. *Acceptance is monitored as engine health, never optimised.* An engine tuned to be accepted becomes an engine tuned to be agreeable.

---

# Part 2 — The Decision Card

Four sections, always in this order, never optional.

```
┌─────────────────────────────────────────────────────────────┐
│ OBSERVATION    What happened                                │
│   "2nd COOLING failure on this vehicle in 41 days."         │
│                                                             │
│ EVIDENCE       Why we believe it                            │
│   "Previous case #8842, closed 18 Jun at RMR.               │
│    476 comparable comeback cases fleet-wide."               │
│                                                             │
│ RECOMMENDATION What to do                                   │
│   "Run a diagnostic reset before dispatching."              │
│   [start diagnostic reset]  [dispatch anyway → reason]      │
│                                                             │
│ REASONING      Why that is the right action                 │
│   "Mechanical faults that return once return AGAIN 56.9%    │
│    of the time (46.7% first-time). Re-routing barely helps: │
│    same garage 81.3% vs different 77.5% — so the evidence   │
│    points at the diagnosis, not the workshop."              │
│                                                             │
│ ⓘ strong evidence · n=476 · derived+human labels · 234/438 cars │
└─────────────────────────────────────────────────────────────┘
```

## 2.1 Schema

```
DecisionCard {
  id, tier P0..P6, trigger WF_* | event, audience role[]
  observation      string   // fact, past tense, no inference
  evidence         { n, source_ids[], tier A|B|C, label_source human|derived|mixed, coverage }
  recommendation   { text, actions[{label, effect}], strength must|should|consider }
  reasoning        string   // the causal argument, quoting measured numbers
  confidence       strong | moderate | limited        // computed, never authored
  suppress_if      predicate[]
  on_response      accepted | overridden(reason) | ignored   → L4
}
```

**Authoring rules.** `observation` states what happened and never why. `reasoning` must cite a measured number — a reasoning string with no number is rejected in review. `confidence` is computed from evidence by the rules in Part 3; **an author cannot assert it**, which is what prevents optimism from leaking into the UI.

---

# Part 3 — Confidence model

## 3.1 Computation

| Input | Strong | Moderate | Limited |
|---|---|---|---|
| Sample size `n` | ≥ 30 | 8–29 | < 8 |
| Label source | human or human-confirmed | mixed | derived only |
| Cost link tier | A (garage+date matched) | B (date proximate) | C (vehicle-level) |
| Population coverage | ≥ 70% | 40–69% | < 40% |
| Metric type | measured | measured | **proxy** |

Confidence is the **lowest** band across all applicable inputs. One weak input downgrades the card — a large sample of derived labels linked at Tier C is not strong evidence, and must not present as such.

## 3.2 Automatic self-downgrade

| Condition | Effect |
|---|---|
| n < 8 | statistic suppressed; the individual cases render instead; strength drops to `consider` |
| proxy metric | card must name the proxy inline: *"return rate, not verified success"* |
| coverage < 40% | header reads **"Limited historical evidence"**; recommendation drops to `consider` |
| `as_of` older than 30 days | staleness note; tier −1 |
| derived labels only | tier −1 |

**Language ladder, enforced by the renderer:**

- strong → *"Route this to Road Force."*
- moderate → *"Road Force is the stronger choice for this fault."*
- limited → *"Limited historical evidence — 4 comparable cases, shown below."*

The platform is never allowed to sound more certain than its evidence. This is the guarantee that makes the recommendations usable at all; one confidently wrong routing recommendation costs more trust than fifty correctly-hedged ones earn.

---

# Part 4 — The blueprint: every state

The format per state: **User · Decision · Knowledge available · Cards · Triggers · Suppression · Confidence · Evidence floor · Feedback captured · Learning effect.**

---

## Pre-ticket stages

### `WF_PENDING_REVIEW` → `WF_INSPECTION_REQUESTED`
**User:** Controller (`maintenance.manage`) · **Decision:** is this worth an inspector's time?

| | |
|---|---|
| **Knowledge** | vehicle case history; open comeback windows; peer-group position |
| **Cards** | **[P2] Open comeback window** — *"COOLING closed 41 days ago; the 90-day window is open."* → *treat as continuation, not a new request* |
| **Trigger** | request created on a vehicle with a matching signature closed ≤90d |
| **Suppression** | BODY/RIM-only history (exposure, not failure); vehicle has <2 lifetime cases |
| **Confidence** | strong (n=476 comeback base) |
| **Evidence floor** | 1 prior matching case — this card is a *lookup*, not a statistic |
| **Feedback** | linked-to-prior vs treated-as-new |
| **Learning** | builds the episode graph — the structure every downstream loop reads |

### `WF_INSPECTION_REQUESTED` — intake
**User:** Inspector · **Decision:** routine intake or immediate escalation?

| | |
|---|---|
| **Knowledge** | full case history; model weakness profile; seasonal priors |
| **Cards** | **[P6] This vehicle** — *"126 prior events · last visit 12 days ago · 2.4× its Patrol peer group (n=42)"*. No recommendation; pure orientation. |
| **Trigger** | always (this is the one unconditional card in the system) |
| **Suppression** | none |
| **Confidence** | strong (own history, not inference) |
| **Evidence floor** | none — it is the vehicle's own record |
| **Feedback** | **odometer at intake** (capture, not a card) |
| **Learning** | ★ starts the per-km series that 11 years of history cannot provide |

---

### `WF_INSPECTION_DIAGNOSTIC` — inspection & diagnosis
**User:** Inspector · **Decision:** what is wrong, and how widely do I look?

This is the **highest-leverage state in the platform**, because D5 established that comebacks are a diagnostic failure, not a workshop failure. Every downstream recommendation inherits the classification made here.

| | |
|---|---|
| **Knowledge** | classifier (80.5% agreement); 22 signatures; model lifts (D8); bundle lifts (D11); seasonal index (D6/D7) |
| **Cards** | **[P2] Model weakness prior** — *"On a Durango, check-engine runs 3.54× fleet baseline and transmission 2.79×. Pull codes before quoting."*<br>**[P4] Inspect-together** — *"SUSPENSION reported. Historically arrives with STEERING (3.05×), BRAKES (2.70×), TYRE (2.7×) — inspect all four now."*<br>**[P6] Signature suggestion** — ranked, with alternates, one click to confirm |
| **Trigger** | weakness card: model lift ≥ 2.0 **and** family n ≥ 250 · bundle card: reported signature ∈ the impact cluster |
| **Suppression** | vehicle age < 1yr → suppress weakness cards (D9: year 0–1 activity is *commissioning*, not failure) · seasonal card outside its window |
| **Confidence** | weakness strong (externally corroborated); bundle moderate; suggestion shows its own probability |
| **Evidence floor** | model family n ≥ 250; bundle n ≥ 150 |
| **Feedback** | ★ **confirmed or corrected signature, plus which alternates were offered**; whether the bundle prompt was acted on |
| **Learning** | ★ the single most valuable feedback event in the platform. Every correction attacks the classifier's 19.5% disagreement directly, and converts a derived label into a human label — which upgrades the confidence tier of every future card built on it |

---

### `WF_INSPECTION_PENDING` — ticket opened, severity set
**User:** Inspector → Supervisor · **Decision:** severity, rental eligibility, urgency

| | |
|---|---|
| **Knowledge** | escalation tail for this signature; chronic status; cost distribution |
| **Cards** | **[P1] Escalation risk** — *"Check-engine cases on this model exceeded AED 5,000 in 23% and 7 days in 18%."*<br>**[P2] Chronic vehicle** — fires only for the peer-normalised deviant set |
| **Trigger** | escalation card when p90 cost > 3× p50 · chronic card when vehicle ≥2sd above model peers |
| **Suppression** | chronic card suppressed if the recurrence is BODY/RIM-dominated (**D3**: that is customer exposure, and flagging it as a vehicle defect is the error this rule exists to prevent) |
| **Confidence** | moderate (cost tail rests on Tier A/B links only) |
| **Evidence floor** | n ≥ 30 for the tail; peer group ≥ 5 vehicles for chronic |
| **Feedback** | severity set; deferral decision |
| **Learning** | severity ↔ eventual cost/duration calibrates the escalation predictor |

---

## Dispatch

### `WF_AWAITING_DISPATCH` — garage assignment
**User:** Supervisor · **Decision:** which garage
**Injection point:** the existing `GET /maintenance/{ticket}/garage-recommendations` — surface exists, scoring is replaced.

| | |
|---|---|
| **Knowledge** | fault-mix-standardised O/E (D2); specialisation; turnaround p50/p90; recent load |
| **Cards** | **[P3] Garage recommendation with reasoning** — ranked, each with O/E, specialisation share, turnaround band, n. Includes the explicit note that raw return rates are **not** used and why. |
| **Trigger** | state entered and signature is resolved |
| **Suppression** | garage n < 150 signature-events → not ranked, listed as "insufficient history" (never silently omitted) · BODY/RIM excluded from the O/E computation entirely |
| **Confidence** | strong for garages with n ≥ 500; moderate 150–499; the metric is flagged **proxy** in all cases — it is return rate, not verified success, until Step 9 verdicts accumulate |
| **Evidence floor** | 150 signature-events per garage |
| **Feedback** | ★ **accepted / overridden + reason** (reason mandatory on override) |
| **Learning** | override reasons are the richest text signal available; the 90-day outcome then scores the choice and updates O/E |

### `WF_IN_TRANSIT`
**User:** Driver · **Decision:** none material — a **capture** state

| | |
|---|---|
| **Cards** | none. Odometer continuity gate only (existing, **blocking**) |
| **Feedback** | dispatch + arrival odometer, photos |
| **Learning** | ★ builds the km series; km-between-failures becomes computable ~12 months after adoption |

**Deliberate design note:** this state shows nothing and is one of the three most important states in the blueprint. Intelligence value and screen presence are not correlated.

---

## Parts

### Part request / purchase — `part-requests`, `part-purchases`
**User:** Inspector (raises) → Procurement (`parts.purchase`) · **Decision:** what to order, from whom, at what price

| | |
|---|---|
| **Knowledge** | predicted parts per signature×model; commodity benchmarks (CV-gated); supplier concentration; seasonality; duplicate check |
| **Cards** | **[P1] Duplicate spend** — *"This vehicle had a battery fitted 4 months ago."*<br>**[P4] Predicted parts** — *"COOLING on this model historically consumes: radiator · water pump · thermostat — pre-stage before arrival."*<br>**[P5] Price benchmark** — *"Battery: 156 buys, median AED 255, IQR 229–375. Quoted 640 = 99th pct."*<br>**[P5] Seasonal timing** — *"Battery demand peaks May–Aug; order in April."* |
| **Trigger** | benchmark card **only if CV < 0.6** for that part |
| **Suppression** | ★ **high-variance parts render frequency + suppliers but NO price benchmark.** Bumper CV 1.13, radiator CV 0.84 — quoting a median there is a false accusation waiting to happen |
| **Confidence** | strong for the ~15 commodity parts; the rest never render a price claim |
| **Evidence floor** | n ≥ 30 buys **and** CV < 0.6 |
| **Feedback** | supplier chosen vs suggested; price paid vs benchmark; delivery lag; part → signature link |
| **Learning** | part↔signature linkage is nearly absent historically — capturing it forward is what makes the predicted-parts card accurate rather than approximate |

---

## Approval

### Approval — `approval_status`, `approved_amount`
**User:** Supervisor / Controller · **Decision:** approve, challenge, or escalate

| | |
|---|---|
| **Knowledge** | cost distribution for signature×model; commodity outliers; vehicle lifetime position |
| **Cards** | **[P1] Quote position** — percentile placement + auto-approve tiering (<p50 auto · p50–p90 one approval · >p90 justification)<br>**[P5] Commodity outlier** — line-level flags<br>**[P2] Chronic context** — if the vehicle is on the lifecycle watchlist |
| **Trigger** | quote entered |
| **Suppression** | signature n < 30 → show band without a percentile claim · no Tier A/B linked costs → suppress the money card entirely rather than quote Tier C |
| **Confidence** | moderate — the cost side rests on the 61%-ish linked subset; coverage is always displayed |
| **Evidence floor** | n ≥ 30 linked cases |
| **Feedback** | approved / challenged / renegotiated + final amount |
| **Learning** | ★ negotiated prices — not just paid prices — are the only way to learn what a *fair* price is rather than what the historical price was |

---

## Repair

### `WF_UNDER_REPAIR` (+ `/findings`)
**User:** Driver relays · Supervisor chases · **Decision:** when to chase, escalate, or pull the car

| | |
|---|---|
| **Knowledge** | turnaround p50/p90 for signature×garage; escalation tail |
| **Cards** | **[P4] Turnaround position** — *"Day 7. p90 for COOLING at this garage is 5 days — this is outside the expected band."* → feeds existing checkpoint/SLA escalation |
| **Trigger** | elapsed > p75 |
| **Suppression** | garage×signature n < 20 → fall back to the fleet-wide band and say so |
| **Confidence** | moderate — **must render as turnaround, never labour hours** (`actual_in_date` ≈ back-to-park) |
| **Evidence floor** | n ≥ 20 |
| **Feedback** | actual parts fitted; additional findings; real timestamps; itemised invoice |
| **Learning** | itemisation permanently closes the labour-vs-parts gap that limits historical benchmarking |

---

## Quality

### `WF_REPAIR_REVIEW` → `WF_READY_REINSPECTION` → `WF_REINSPECTION_FAILED`
**User:** Supervisor (video review) / Inspector (re-inspection) · **Decision:** pass, fail, or re-fix

| | |
|---|---|
| **Knowledge** | this signature's comeback rate; this garage's O/E; whether this case is already a repeat |
| **Cards** | **[P2] Verification prompt** — *"COOLING returns 38.7% within 90 days — verify under load, not at idle."* Signature-specific.<br>**[P1] Repeat case** — if this is already a 2nd occurrence, the pass bar is raised and stated |
| **Trigger** | state entered |
| **Suppression** | BODY/RIM → generic prompt only (their "recurrence" is new damage, not a failed repair) |
| **Confidence** | strong (n ≥ 300 per major signature) |
| **Evidence floor** | n ≥ 100 for a signature-specific prompt |
| **Feedback** | ★★ **THE OUTCOME VERDICT — the field that does not exist in 11 years of history** |
| **Learning** | ★★ this single capture converts "return rate" from **proxy** to **measured first-time-fix**. It is what upgrades every garage card from `proxy` to `measured`, and it unblocks true garage scoring from "blocked" to "shippable". If only one thing in this blueprint ships, it is this field. |

---

## Release & follow-up

### `WF_READY_FOR_PICKUP` → `WF_IN_OUR_PARK` → `WF_AWAITING_INVOICE` → `WF_CLOSED`
**User:** Driver → Controller · **Decision:** release; flag the garage; close the episode

| | |
|---|---|
| **Knowledge** | predicted vs actual cost and duration |
| **Cards** | **[P5] Variance** — *"Predicted p50 1d / actual 4d — outside p90 for this garage."* Feeds garage drift, not a user action |
| **Trigger** | actual outside predicted p90 |
| **Suppression** | no prediction was made (pre-adoption tickets) |
| **Confidence** | moderate |
| **Feedback** | return odometer; final cost; actual duration; ★ **mandatory vendor** (closes the AED 1.15M unattributed-spend gap) |
| **Learning** | prediction error directly trains the duration and cost bands |

### The 90-day watch — no state, a scheduled window
**User:** nobody, until it fires · **Decision:** warranty/rework claim vs a new paid job

| | |
|---|---|
| **Cards** | **[P2] Comeback** (§Part 5 loop 1) — fires into whoever opens the next case |
| **Trigger** | matching signature on the same vehicle ≤90d |
| **Suppression** | BODY/RIM |
| **Feedback** | returned ✓/✗ at 30 / 60 / 90 / 180 days, automatic |
| **Learning** | ★★ **this closes every loop in the platform.** The label from diagnosis, the garage from dispatch, the parts from procurement and the verdict from QC all receive their outcome here. Without this window the other states produce inputs and nothing ever learns |

---

## Side states

| State | Cards | Note |
|---|---|---|
| `WF_ON_SITE_PENDING` | same diagnosis cards, garage cards suppressed | no garage decision exists |
| `WF_PAUSED_RETURNED_TO_SERVICE` | **[P1]** deferred-escalation risk — *"deferred cooling faults returned as engine work in X% of cases"* | fires at the pause decision, not after |
| Temporary release | none | pure capture (odometer out/in) |
| `WF_REVIEW_REJECTED`, `WF_DIAGNOSTIC_CLEARED` | none | terminal, no decision follows |
| Complaint lane (`WF_COMPLAINT_TRIAGE`) | **[P2]** vehicle history + comeback window | the complaint may be a comeback wearing a different label — a case the current workflow cannot see at all |

---

# Part 5 — The five closed loops

A capability is not a loop. A loop has a recommendation, an outcome, and a mechanism that changes the next recommendation. There are exactly five.

### Loop 1 — Diagnosis
`suggest signature → human confirms/corrects → repair → 90-day watch → was the classification right?`
**Improves:** classifier accuracy (80.5% baseline), and converts derived labels to human labels, upgrading confidence tiers platform-wide.
**Health metric:** correction rate falling over time.

### Loop 2 — Routing
`recommend garage → accepted/overridden + reason → repair → verdict + 90-day watch → update O/E`
**Improves:** garage scoring; override reasons expose factors the model lacks (distance, relationship, capacity).
**Health metric:** O/E spread narrowing as volume shifts to sub-0.9 garages.

### Loop 3 — Cost
`predict band → approve/challenge → final negotiated amount → update distribution`
**Improves:** benchmarks learn *fair* prices rather than *historical* prices.
**Health metric:** share of quotes above p75 that get challenged.

### Loop 4 — Parts
`predict parts → what was actually fitted → did the fault return?`
**Improves:** part↔signature linkage (nearly absent historically) and eventually part *reliability* — the input to the new-vs-used sourcing decision that is currently blocked.
**Health metric:** predicted-parts precision; share pre-staged before arrival.

### Loop 5 — Lifecycle
`chronic/replace recommendation → kept or sold → subsequent cost → was it right?`
**Improves:** repair-vs-replace scoring. **Slowest loop — years, not weeks**, and the blueprint should say so rather than imply it self-corrects quickly.

---

# Part 6 — Product invariants

1. **Knowledge is pushed, never pulled.** If a decision is being made and the relevant knowledge is one click away instead of on screen, that is a Decision Engine defect — not a training issue and not a dashboard gap.
2. **The card budget is absolute.** One primary, two secondary. Eligibility beyond that is a ranking problem, never a layout problem.
3. **Confidence is computed, never authored.**
4. **Proxies announce themselves.** Until outcome verdicts accumulate, every quality metric renders as `proxy` with its proxy named.
5. **Exposure never enters quality.** BODY and RIM are excluded from every workshop, technician and garage metric — enforced in the Knowledge Engine, not per card, so no future screen can reintroduce the error.
6. **Advise, don't execute.** Two blocking gates only: odometer continuity, and 3rd-comeback escalation. Everything else recommends.
7. **Capture even where nothing renders.** Intake, transit and release show no intelligence and generate most of it.
8. **Acceptance rate is health, never a target.**
9. **Every card is deletable.** Quarterly: *if this card vanished, would the decision get worse?* No → delete.

---

# Part 7 — What the test deleted

Cards that were designed and then failed *"would the decision get worse?"*:

| Card | Verdict |
|---|---|
| Fault progression alert | Lifts 1.4–2.1 — no threshold is right often enough. Survives as one context line inside the similar-repairs card. |
| Maintenance rhythm | Changes no decision. Becomes an internal parameter for episode grouping. |
| Comeback rate by fault type | Internal weighting only. On screen, "BODY 78.6%" would be misread as a quality failure. |
| Fleet cost-by-make table | Its decision (acquisition) happens twice a year, outside this platform. Quarterly brief, not a card. |
| Seasonality on the technician screen | The technician cannot act on a season. It belongs to the manager's March campaign and procurement's order timing. |
| Lifetime cost on the approval screen | The approver cannot act on lifetime cost mid-quote. Belongs to the lifecycle review. |
| "Similar vehicles" panel | Interesting; changes nothing the vehicle's own history doesn't already drive. |

Seven designed cards deleted. That is the test working — if nothing had been deleted, the test would be decorative.

---

# Part 8 — Definition of done for this milestone

The architecture is complete when all five hold:

1. Every workflow state has a documented decision, or is explicitly marked **capture-only**. ✅ Part 4
2. Every card has: trigger, suppression, confidence rule, evidence floor, feedback hook. ✅ Part 4
3. Every card belongs to a closed loop with a named outcome. ✅ Part 5
4. Every proxy metric is identified and has a path to becoming measured. ✅ (outcome verdict at QC)
5. Every discovery is either a card, an internal parameter, or explicitly deleted. ✅ Part 7

**First code:** the Phase 1 slice from the L3.5 spec — one signature (COOLING), four states (diagnosis → dispatch → QC verdict → 90-day watch). It is the smallest slice containing a complete loop, and it validates the Decision Engine, the card contract, the confidence model and Layer 4 in one pass.

The three capture-only states (intake odometer, transit odometer, release vendor+cost) ship **with** Phase 1, not after. They render nothing, and every month they are delayed is a month of permanently blank history.

---

## Closing note

The series moved from *what does the data contain* → *what can be known* → *what could be built* → *where does it appear* → *what decides what appears*.

The architectural claim of this blueprint is narrow and testable: **an intelligence platform is not defined by what it knows, but by what it chooses not to say.** Every mechanism here — precedence tiers, card budget, computed confidence, evidence floors, suppression rules, the deletion test — exists to make the platform quieter than it could be. The knowledge base will keep growing; the number of things shown at any moment must not.
