# Operational Intelligence Layer — Product Specification

*Turning validated knowledge into the product. Where it appears, who it serves, at what moment, which decision it changes, which KPI it moves.*

**Date:** 2026-07-29
**Supersedes nothing.** Consumes: `Historical-Maintenance-Knowledge-Opportunities.md` (what is reconstructable), `Maintenance-Intelligence-Capability-Design.md` (capability catalogue), `Fleet-Knowledge-Engine-Discovery-Log.md` (validated discoveries).
**This document is a build spec, not an analysis.** Every entry is anchored to a real workflow state (`Maintenance::WF_*`) and a real screen or endpoint.

> **⚠ This is the design record, not a description of what runs.** Most of what is specified here was
> deliberately not built — three of the four capabilities are blocked by measured facts about the
> fleet's data. For what actually exists, the rules it obeys and the runbook, read
> **[`Intelligence-Platform-As-Built.md`](./Intelligence-Platform-As-Built.md)**.

---

## 0. The capability test

A discovery becomes a capability only when all five questions have answers. Anything that fails the test stays an insight and does not get built.

| Test | Fails if |
|---|---|
| **Where** does it appear? | "on a dashboard" |
| **Who** benefits? | "management" (no named role) |
| **When** exactly? | "whenever they want to look" |
| **Which decision** does it change? | "it's good to know" |
| **Which KPI** moves? | no baseline number exists to move |

Applied honestly, this test kills several things that looked strong as analysis. They are listed in §7.

---

## 1. Layer 3.5 — The Operational Intelligence Layer

The five layers, with the new one in its place:

```
L1  Historical Reconstruction  ── builds Repair Cases from two half-records
L2  Knowledge Engine           ── signatures, garage O/E, benchmarks, seasonality, chronic patterns
L3.5 OPERATIONAL INTELLIGENCE  ── ★ injects knowledge into the workflow at the deciding moment
L3  Decision Support surfaces  ── the cards / panels themselves
L4  Continuous Learning        ── captures every judgement + outcome back into L2
```

### 1.1 Why it is its own layer

Without it, every screen would query the knowledge engine ad hoc, each with its own idea of thresholds, sample-size rules and confidence display. That is how a fair metric (garage O/E) gets re-implemented as an unfair one (raw return rate) on someone else's screen.

**L3.5 owns four things nobody else may own:**

1. **Trigger binding** — which workflow state fires which knowledge card. Knowledge is *pushed* by a state transition, never pulled by a user opening analytics.
2. **The suppression rules** — sample-size floors, confounder exclusions (BODY/RIM never enter quality metrics), staleness limits. One implementation, enforced centrally.
3. **The card contract** — every card carries `{claim, evidence_n, confidence, coverage, source_ids[], as_of}`. A card that cannot express its own sample size cannot render.
4. **The feedback hook** — every card ships with its response captured. A card with no `on_response` handler is rejected at registration; that is what keeps L4 fed rather than aspirational.

### 1.2 The contract

```
KnowledgeCard {
  id                  'similar-repairs' | 'comeback-warning' | 'garage-match' | ...
  trigger             WF_* state | screen event | field change
  audience            role[]                      // who sees it
  severity            info | advisory | warning | blocking
  claim               human sentence, pre-rendered by L2
  evidence_n          int                         // ALWAYS rendered
  confidence          high | medium | proxy       // 'proxy' renders differently
  coverage            "234 of 438 vehicles"       // scope honesty
  source_ids[]        case ids behind the claim   // click-through to the raw cases
  as_of               timestamp of L2 materialisation
  on_response         accepted | overridden + reason  →  L4
}
```

**Rules L3.5 enforces, without exception:**

- `evidence_n < 8` → render the individual cases, never a statistic.
- `confidence = proxy` → the card must say what the proxy is ("return rate, not verified success").
- No card is `blocking` except the two safety gates in §5 (odometer continuity, chronic-vehicle escalation).
- Every card is **precomputed**. L3.5 reads materialised knowledge; if a card needs a live computation, that is an L2 gap.

---

## 2. Discovery → Product map

Every validated discovery, through the five-question test.

| Discovery | Where | Who | When (state) | Decision changed | KPI moved |
|---|---|---|---|---|---|
| **D1** rules labelling 80.5% / 78.7% coverage | intake fault picker | Inspector, Technician | `WF_INSPECTION_DIAGNOSTIC` | fault is classified in one click instead of free text | label coverage; time-to-classify |
| **D2** garage O/E (fault-mix standardised) | assign-garage step | Supervisor | `WF_INSPECTION_PENDING` → dispatch | which garage gets the job | comeback rate; O/E drift |
| **D3** comeback varies 5× by fault | (internal weighting) | — | all quality metrics | prevents unfair scoring | metric integrity |
| **D4** cascade 46.7% → 56.9% | comeback banner | Supervisor | ticket open on a repeat | re-dispatch **vs diagnostic reset** | second-comeback rate |
| **D5** switching garages doesn't help | same banner | Supervisor | on first comeback | stop re-routing, re-diagnose | first-time-fix rate |
| **D6** thermal season (engine peaks June) | campaign generator | Fleet Manager | **March 1 annually** | schedule pre-summer cooling work | summer breakdowns; June engine events |
| **D7** two opposite seasonal curves | capacity + stock plan | Fleet Manager, Procurement | quarterly | workshop capacity & stock timing | parts stockouts; queue time |
| **D8** model weaknesses (RR 4.01× etc.) | diagnosis panel + acquisition brief | Technician, Fleet Manager | `WF_INSPECTION_DIAGNOSTIC`; purchase cycle | what to check first; what to buy next | first-time-fix; cost/car by model |
| **D9** year-5 peak; year 0–1 = commissioning | lifecycle card | Fleet Manager | monthly sweep | disposal timing | lifetime cost/car |
| **D10** 16-day median rhythm | episode grouping | (internal) | case creation | related visits merge correctly | duplicate-case rate |
| **D11** road-impact bundle | inspection checklist | Inspector | `WF_INSPECTION_DIAGNOSTIC` | inspect all four, not one | repeat visits per episode |
| **D12** progression weak (1.4–2.1) | context line only | Technician | similar-repairs card | awareness, no alert | — (deliberately none) |
| **D13** 5 peer-deviant vehicles | chronic queue | Fleet Manager | weekly | investigate / replace | chronic vehicle count |
| **battery 255, CV 0.38** | quote check | Supervisor | invoice/quote entry | challenge or approve | overpayment caught |
| **A/C 10.8× seasonality** | procurement screen | Procurement | part selection | when to stock | stockout rate |
| **AED 1.15M no vendor** | vendor field | all | every cost entry | mandatory attribution | % spend with counterparty |

---

## 3. Role injection specs

What each role sees, at the exact moment, with the exact content.

### 3.1 Technician / Inspector — `WF_INSPECTION_DIAGNOSTIC`

**Trigger:** case opened on a vehicle. Fires *before* the form is filled, not after.

**Card 1 — This Vehicle** *(always, no threshold)*
```
Patrol 51363 · 126 prior repair events · last visit 12 days ago
Recurring: BODY ×29, RIM ×14, COOLING ×6
⚠ COOLING repaired 41 days ago — a comeback window is open
```

**Card 2 — Fault picker, pre-ranked** *(D1, D8)*
```
Suggested signature:  COOLING  (confidence 0.81)
Alternatives: ENGINE_MECH · CHECK_ENGINE · AC
On NISSAN PATROL, AC runs 1.29× fleet baseline
[confirm] [choose another]     ← the response is the training label (L4)
```

**Card 3 — Similar Historical Repairs** *(C2)*
```
COOLING on NISSAN PATROL — 88 similar cases (fleet-wide: 1,265)
  cost      p50 AED 1,240 · p90 AED 4,100      (Tier A/B linked: 61%)
  turnaround p50 1 day · p90 6 days
  garages   ALTIQNIAH 31% · RMR 22% · GPT 14%
  parts     radiator · water pump · thermostat · coolant hose
  comeback  38.7% within 90 days           ⓘ proxy: return rate, not verified success
  [see the 88 cases]
```

**Card 4 — Inspect-together prompt** *(D11)* — fires only for STEERING / SUSPENSION / BRAKES / TYRE
```
Reported: SUSPENSION. Historically arrives with STEERING (3.05×), BRAKES (2.70×), TYRE (2.7×).
Inspect all four now — separate visits are the main driver of the 16-day return cycle.
```

**Captured back:** confirmed/corrected signature, which alternatives were shown, whether the bundle prompt was acted on.

---

### 3.2 Supervisor — garage assignment, `WF_INSPECTION_PENDING` → `WF_AWAITING_DISPATCH`

Injects into the **existing** `GET /maintenance/{ticket}/garage-recommendations` endpoint — the surface already exists; this replaces its scoring.

**Card — Recommended garage, with the reason** *(D2, C7)*
```
For COOLING on this vehicle:

  ALTIQNIAH AL ALIAH        ★ recommended
    return rate 40.0%, but O/E 0.93 — at par for its fault mix
    engine/cooling = 52% of its work (specialist)
    turnaround p50 1d · p90 5d      ·  cost p50 within historical band
    n = 913 signature-events

  RMR                         alternative
    O/E 1.00 · handles the hardest jobs · avg line AED 985 (highest in fleet)

  POWER POINT               ⚠ not recommended for this fault
    O/E 1.20 — returns 20% more than its own mix predicts
    specialises electrical/airbag/ABS, not cooling

ⓘ Scored on fault-mix-standardised return rate. Raw rates are not used:
   they penalise body shops (BODY recurs 78.6% vs BATTERY 16.1%).
[accept] [choose another → reason required]
```

The "reason required" on override is not friction for its own sake — it is the highest-quality training signal in the system.

**Captured back:** accepted/overridden + reason, and 90 days later, whether the fault returned.

---

### 3.3 Supervisor — the comeback moment *(D4, D5 — the highest-value card in the spec)*

**Trigger:** a ticket opens whose signature matches a case closed on the same vehicle within 90 days.

```
⚠ THIS IS A COMEBACK — 2nd occurrence of COOLING in 41 days
   previous: closed 2026-06-18 at RMR

   Mechanical faults that come back once return AGAIN 56.9% of the time
   (first-time rate 46.7%) — this case is 1.22× more likely to fail than a fresh one.

   Sending it to a different garage historically changes little:
        same garage    → fails again 81.3%
        different shop  → fails again 77.5%

   → The evidence points at the DIAGNOSIS, not the workshop.
   Recommended: diagnostic reset before dispatch.
   [start diagnostic reset]  [dispatch anyway → reason required]
```

This is the one card that argues *against* the instinctive action. It exists because D5 showed the instinctive action doesn't work.

---

### 3.4 Supervisor — approval, `approval_status` / `approved_amount`

**Card — Quote position** *(C12, C13)*
```
Radiator + labour — AED 2,850
  historical band for COOLING on PATROL: p50 1,240 · p75 2,100 · p90 4,100
  → 78th percentile. Within normal range.
  Auto-approve threshold (p50): exceeded → one approval required.

Commodity lines on this quote:
  Battery  AED 640   ⚠ p99 — fleet median 255 (n=156, IQR 229–375, CV 0.38)
                        battery prices are up 75% since 2021, but 640 is above even that trend.
  [challenge this line]
```

**Card — Vehicle context** *(D13, C23)*
```
This vehicle: AED 68,008 lifetime · 126 events · 2.4× its Patrol peer group (n=42)
Not in the ≥2sd deviant set, but in the top quartile — flag for lifecycle review at next service.
```

**Captured back:** approved / challenged / renegotiated + final amount → sharpens the benchmark.

---

### 3.5 Procurement — part selection screen

**Trigger:** a part name is chosen on `POST /part-requests` or `/part-requests/{id}/purchase`. The intelligence renders *inside the purchasing form*, above the price field.

```
BATTERY 12V
  bought 156× · median AED 255 · IQR 229–375 · CV 0.38 (reliable benchmark)
  trend  AED 250 (pre-2022) → 438 (2024+)   ▲ +75%
  suppliers  concentration: top 3 = 71% of spend
  demand     peaks MAY–AUG (index 137) · trough APR (52)     ← order in April
  consumes   Patrol/QX80 41% · Camaro 18% · Charger 11%
  bundle     frequently bought with: ALTERNATOR · TERMINALS
  ⚠ this vehicle had a battery fitted 4 months ago  (duplicate-spend check)
```

**Design note:** this only renders for the ~15 commodity parts where CV is low enough to be a benchmark. For a bumper (CV 1.13) the card renders *frequency and suppliers only, no price benchmark* — quoting a median there would be a false accusation waiting to happen.

**Captured back:** supplier chosen vs suggested, price paid vs benchmark.

---

### 3.6 Fleet Manager — proactive, never browsed

The manager does not open anything. Four scheduled pushes:

| Push | Cadence | Content | Source |
|---|---|---|---|
| **Pre-summer campaign** | **1 March**, annually | "143 vehicles due cooling/AC inspection before May. Engine-mechanical failures peak in June at 3.7× the January rate; cooling and battery peak in May. Run this in March–April." | D6 |
| **Chronic & deviant** | weekly | the ≥2sd peer-normalised list (currently 5 vehicles, e.g. Patrol 20773: 299 events vs peer avg 100 across 42 cars) + repair-vs-replace scores | D13, C23 |
| **Garage drift** | monthly | any garage whose 90-day rolling O/E moved >0.15 vs its trailing year | D2 |
| **Supplier drift** | monthly | parts whose price trend diverges from the fleet-wide trend for the same part | C14 |

**Captured back:** actioned / dismissed + reason. A campaign dismissed three years running is a campaign that should stop being generated.

---

## 4. The lifecycle walkthrough

Every step: knowledge available → what is shown → decision changed → **new data captured** → how that improves the engine.

---

### Step 1 — Vehicle arrives / intake
`WF_INSPECTION_REQUESTED` · `WF_PENDING_REVIEW` · breakdown intake

| | |
|---|---|
| **Knowledge** | vehicle's full case history; open comeback windows; peer-group position; model weakness profile |
| **Shown** | *"126 prior events · last visit 12 days ago · COOLING window open (41 days) · 2.4× its peer group"* |
| **Decision** | routine intake vs immediate escalation; whether this is a new case or a continuing episode |
| **Captured** | **odometer** (the single most valuable missing field), arrival condition, trigger reason, request origin |
| **Improves** | odometer starts the per-km series that historical data can never provide — every intake compounds it |

---

### Step 2 — Inspection
`WF_INSPECTION_DIAGNOSTIC`

| | |
|---|---|
| **Knowledge** | model-specific weakness lifts (D8); road-impact bundle (D11); seasonal priors (D6/D7) |
| **Shown** | *"On a Durango, check-engine runs 3.54× baseline and transmission 2.79× — pull codes first."* · *"It's April: suspension and brake faults peak now (1.7×)."* · bundle prompt for the four impact signatures |
| **Decision** | what to inspect, and how widely — the difference between finding one fault and finding the episode |
| **Captured** | findings against the canonical signature set; which prompts were acted on; inspection odometer |
| **Improves** | structured findings replace free text at source, so future labels are gold rather than derived |

---

### Step 3 — Diagnosis / fault classification
`WF_INSPECTION_PENDING` (ticket opens)

| | |
|---|---|
| **Knowledge** | classifier at 80.5% (D1); 22 canonical signatures; similar-case retrieval |
| **Shown** | ranked signature suggestion + alternatives + the similar-repairs card (§3.1) |
| **Decision** | the classification itself — which determines every downstream recommendation |
| **Captured** | **confirmed or corrected signature + which alternatives were offered** |
| **Improves** | the single highest-value feedback event. Every correction attacks the 19.5% disagreement directly; at ~30 cases/week the human-label corpus doubles in under three years |

---

### Step 4 — Approval
`approval_status`, `approved_amount`

| | |
|---|---|
| **Knowledge** | cost distribution for signature × model; commodity benchmarks; vehicle lifetime position |
| **Shown** | percentile placement, auto-approve tiering, commodity outliers, chronic flag (§3.4) |
| **Decision** | approve / challenge / escalate — and *how much scrutiny this deserves* |
| **Captured** | decision + final amount + challenge reason |
| **Improves** | closes the loop on price benchmarks with *negotiated* prices, not just paid ones — the only way to learn what a fair price is rather than what the historical price was |

---

### Step 5 — Garage assignment
`WF_AWAITING_DISPATCH` · existing `garage-recommendations` endpoint

| | |
|---|---|
| **Knowledge** | fault-mix-standardised O/E (D2); specialisation profile; turnaround p50/p90; current load |
| **Shown** | ranked garages **with the reason**, plus the explicit note that raw rates are not used (§3.2) |
| **Decision** | which garage — the decision with the clearest measured quality differential in the fleet |
| **Captured** | accepted / overridden **+ reason**; garage at time of dispatch |
| **Improves** | override reasons are the richest training data available; the 90-day outcome then scores the choice |

---

### Step 6 — Transit
`WF_IN_TRANSIT`

| | |
|---|---|
| **Knowledge** | odometer continuity rules; expected arrival |
| **Shown** | odometer gate (existing) |
| **Decision** | none material — this is a **capture** step |
| **Captured** | **dispatch + arrival odometer** |
| **Improves** | builds the km series; km-between-failures becomes computable within a year of adoption |

---

### Step 7 — Parts procurement
`part-requests` / `part-purchases`

| | |
|---|---|
| **Knowledge** | predicted parts for the signature (C4); commodity benchmarks; supplier concentration; seasonality; duplicate check |
| **Shown** | the procurement card (§3.5) + *"COOLING jobs on this model historically consume: radiator, water pump, thermostat"* — so parts are pre-staged rather than discovered mid-repair |
| **Decision** | what to order, from whom, at what price — **and whether to order before the car arrives** |
| **Captured** | part → signature linkage, supplier chosen vs suggested, price vs benchmark, delivery lag |
| **Improves** | part↔signature linkage is almost absent historically; capturing it forward is what makes C4 accurate rather than approximate |

---

### Step 8 — Repair
`WF_UNDER_REPAIR` + `/findings`

| | |
|---|---|
| **Knowledge** | turnaround p50/p90 for this signature × garage; escalation-risk tail (C6) |
| **Shown** | *"p50 1 day, p90 6 days — day 7 is outside the p90 for this fault at this garage"* driving the existing checkpoint/SLA escalation |
| **Decision** | when to chase; when to escalate; when to pull the car |
| **Captured** | **actual parts fitted**, additional findings, real start/end timestamps, itemised invoice (via C16 extractor) |
| **Improves** | itemised invoices permanently close the labour-vs-parts gap that makes historical benchmarking imprecise |

---

### Step 9 — Quality check
`WF_REPAIR_REVIEW` → `WF_READY_REINSPECTION` → `WF_REINSPECTION_FAILED`

| | |
|---|---|
| **Knowledge** | this fault's comeback rate; this garage's O/E; whether this is already a repeat |
| **Shown** | *"COOLING returns 38.7% within 90 days — verify under load, not at idle."* Signature-specific verification prompts. |
| **Decision** | pass / fail / re-fix — and how hard to look |
| **Captured** | **★ THE OUTCOME VERDICT — the field that does not exist in 11 years of history** |
| **Improves** | this single capture converts "return rate" from a *proxy* into *measured first-time-fix*. It unblocks true garage scoring (Tier 4 → shippable) and is the highest-leverage new field in the entire platform |

---

### Step 10 — Release
`WF_READY_FOR_PICKUP` → `WF_IN_OUR_PARK` → `WF_AWAITING_INVOICE` → `WF_CLOSED`

| | |
|---|---|
| **Knowledge** | predicted vs actual cost and duration; episode summary |
| **Shown** | variance card: *"predicted p50 1d / actual 4d — outside p90 for this garage"* → feeds garage drift |
| **Decision** | release to service; whether to flag the garage; whether the episode is truly closed |
| **Captured** | return odometer, final cost, actual duration, **mandatory vendor** (closes the AED 1.15M attribution gap) |
| **Improves** | prediction error is the direct training signal for the duration and cost bands |

---

### Step 11 — Follow-up (the 90-day watch)

| | |
|---|---|
| **Knowledge** | the comeback window is the fleet's fundamental quality clock |
| **Shown** | nothing, unless it returns — then §3.3 fires with full episode context |
| **Decision** | warranty/rework claim vs new paid job; garage conversation |
| **Captured** | returned ✓/✗ at 30/90/180 days, automatically |
| **Improves** | **this closes every loop.** The label from step 3, the garage from step 5, the parts from step 7, the verdict from step 9 all get their outcome here. Without this step the other ten produce inputs and never learn |

---

## 5. The only two blocking gates

Everything else advises. These block, because the cost of being wrong is asymmetric:

1. **Odometer continuity** (existing) — a reading that breaks continuity blocks progression until confirmed. Protects the km series that everything future depends on.
2. **Chronic-vehicle escalation** — a vehicle hitting its 3rd comeback of the same signature in 180 days cannot be re-dispatched without supervisor sign-off. Justified by D4: at that point the return probability is ~57% and rising, and dispatching again is the measured wrong move.

---

## 6. KPI model

Baselines are measured; targets are proposals for sign-off.

| KPI | Baseline (measured) | Moved by | Target |
|---|---|---|---|
| **Mechanical first-time-fix** | 53.3% (100 − 46.7% return) | Steps 2/3/9, D5 cards | +8 pts yr 1 |
| **Second-comeback rate** | 56.9% | §3.3 diagnostic reset | −10 pts |
| **Overall 90-day comeback** | 25.9% | all of the above | −5 pts |
| **June engine-mechanical events** | index 183 vs Jan 50 | D6 March campaign | −20% of the June peak |
| **Turnaround vs promise** | p50 1d / p90 6d | Step 8 escalation | p90 −1 day |
| **Quote challenge rate** | 0% (no benchmark exists today) | §3.4 | ≥15% of quotes above p75 challenged |
| **Spend with a named counterparty** | 77% (AED 1.15M unattributed) | mandatory vendor, Step 10 | 100% |
| **Label coverage** | 78.7% derived / 30.5% human | Step 3 confirmations | 95% total, 60% human-confirmed |
| **Parts pre-staged before arrival** | ~0% | Step 7 / C4 | 40% of predicted-parts jobs |
| **Garage O/E spread** | 0.49 → 1.33 | Step 5 routing | narrow by shifting volume to <0.9 shops |
| **Suggestion acceptance rate** | n/a | all cards | tracked as engine health, not a target |

**The one that matters most:** *suggestion acceptance rate* is deliberately **not** a target. If it becomes one, the engine will be tuned to be agreeable rather than correct.

---

## 7. What failed the capability test

Honest casualties — strong as analysis, not yet product:

| Insight | Why it fails |
|---|---|
| **Fault progression (D12)** | lifts 1.4–2.1 — no threshold produces an alert that is right often enough. Ships as a *context line* in the similar-repairs card, nothing more. |
| **Maintenance rhythm (D10)** | changes no user's decision. It is an **internal** parameter for episode grouping. Correct and important — just not a card. |
| **Comeback variance by fault (D3)** | pure internal weighting. If it ever appears on a screen, someone will read "BODY 78.6%" as a quality failure. |
| **Model cost table (make-level)** | real, but its decision (fleet acquisition) happens once or twice a year outside this platform. Ships as the quarterly acquisition brief, not an in-workflow card. |
| **Per-part unit prices** | the extraction doesn't work (plate numbers, not prices). No card. |
| **Cost per km** | no historical denominator. Not buildable until Steps 1/6/10 have run for a year. |

---

## 8. Build order

**Phase 1 — Prove the loop on one signature.** Pick COOLING (n=1,265, clean seasonality, real cost tail). Build Steps 3, 5, 9, 11 only: classify → route → verdict → watch. This is the smallest slice that closes a complete learning loop, and it proves L4 works before scaling surface area.

**Phase 2 — All signatures, same four steps.** Widen the loop before widening the roles.

**Phase 3 — Approval + procurement** (Steps 4 and 7). These need the benchmarks that Phase 1–2 sharpen.

**Phase 4 — Manager pushes** (§3.6) and the seasonal campaign. Worth waiting: the March campaign needs O/E and chronic lists that have already been corrected by real feedback.

**Throughout — capture even where nothing is shown.** Steps 1, 6 and 10 are pure capture. They have no visible intelligence and they are the reason the platform gets smarter. Shipping them late is the one sequencing mistake that cannot be recovered, because the months without capture are permanently blank.

---

## 9. The rule this whole layer exists to enforce

> **No user should ever have to open analytics to make a maintenance decision.**
>
> If a decision is being made and the relevant knowledge is one click away rather than already on screen, that is a defect in L3.5 — not a training problem, not a UX preference, and not something a dashboard fixes.
