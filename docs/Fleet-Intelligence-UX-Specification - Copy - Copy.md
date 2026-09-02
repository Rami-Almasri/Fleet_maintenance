# Fleet Intelligence — UX Specification

**Companion to:** `docs/Fleet-Maintenance-Intelligence-Study.md` · `docs/Fleet-Intelligence-Implementation-Roadmap.md`
**Date:** 2026-08-03 · **Status:** Design specification. No code.
**Users:** Basem (Owner/Executive) · Adham (Maintenance Manager)

Every card, number, and drill-down in this document maps to a verified metric ID from the roadmap.
Where a design decision is driven by a data limitation, the limitation is named inline. Nothing here
requires data we do not have.

---

## 0. Design Foundations

### 0.1 What already exists (this spec extends it, it does not replace it)

The application has **68 pages**, a mature primitive library (`MetricCard`, `Gauge`, `RankedBar`,
`LineChart`, `MultiLineChart`, `GroupedBarChart`, `Sparkline`, `Table`, `Tabs`, `Drawer`, `Badge`,
`FilterChips`, `DateRangePicker`, `Segmented`, `Progress`, `CompositionDonut`, `LeaderDonut`,
`CountUp`, `SearchSelect`, `Modal`, `Skeleton`, `Toast`, `Tooltip`), a light/dark token system in
`index.css`, EN/AR i18n via `tf()`/`tp()` with RTL, Spatie RBAC across 10 roles, a `CommandPalette`,
and the `ModuleLauncher` / `ModuleOverview` hub pattern.

**Design constraint accepted up front:** we are not adding a tenth silo to a product that already has
navigation strain. §1 therefore specifies what gets **absorbed and retired**, not only what gets
built.

### 0.2 Five product principles

**P1 · Conclusion first, chart second, rows third.**
Every card leads with a sentence a human would say, not a metric name. "Faults sent to Deals On Wheels come
back in 55 days — the fleet takes 120" outranks "Avg. Recurrence Interval: 55.0". The chart proves
the sentence; the row list proves the chart.

**P2 · Confidence is a visible property of every number.**
No number appears without its sample size and coverage. A metric built on 25.7% of tickets says so on
the card, not in a tooltip. This is the difference between an executive who trusts the platform and
one who stops opening it.

**P3 · Every number is a door.**
Nothing is terminal. A KPI opens a list; a list row opens an entity; an entity opens its evidence.
Basem must always be able to reach the raw rows behind any claim in ≤3 clicks — because he will ask,
and the answer must be on screen rather than in someone's follow-up email.

**P4 · Two-tier labelling is mandatory.**
252 tickets carry `workflow_status`; 26,942 carry `out_date`. Any surface mixing them badges each
tile **Live workflow** or **Full history**. Silently blending them is how the numbers stop agreeing.

**P5 · Operational language, never engine vocabulary.**
The default view says "came back with the same problem." "Signature," "recurrence pair,"
"classifier," and "exposure flag" live under a **Technical details** disclosure. This follows the
project's existing operational-language rule.

### 0.3 Non-negotiable UI rules

| Rule | Implementation |
|---|---|
| Below n=30, show `Not enough data` — never a number | Metric layer returns `insufficient_sample`; card renders the empty state with the current count |
| Never impute a missing composite term | Renormalise remaining weights; card lists which terms were used |
| Mean never appears alone | Always paired with median; p90 on any duration |
| Financial surfaces carry an as-of watermark | Persistent header chip: **Financials as of 31 Mar 2026** |
| Synthetic data never renders | Global `write_mode='seed'` query filter (roadmap F9) |
| Every metric card links to Data Health | The confidence badge *is* the link |
| Volume trends normalise by fleet size | Fleet grew 129→186 vehicles 2023→2025; raw counts read growth as decay |
| RTL + dark mode are acceptance criteria | Not a later pass. Every component ships both |

---

# 1. Information Architecture

## 1.1 The core structural decision

Basem and Adham need **different products**, not different filters on one product. Basem opening a
sidebar of 68 items is a failure of design. The IA therefore introduces **role-based landing** on top
of a single consolidated intelligence hub.

```
FLEETVIEW
│
├─ ⌂ Landing (role-resolved)
│   ├─ Executive Home ......... Basem      → §2
│   ├─ Operations Home ........ Adham      → §3
│   └─ Workspace (existing) ... everyone else
│
├─ ▣ FLEET INTELLIGENCE  ← new consolidated hub, tabbed
│   ├─ Overview ............... hub landing, module cards + top insights
│   ├─ Garage Intelligence ★ .. §4   (3 sub-pages)
│   ├─ Vehicle Intelligence ... §5
│   ├─ Fault Intelligence ..... §6
│   ├─ Financial Intelligence . §7
│   └─ Insights .............. §8
│
├─ ⚙ MAINTENANCE OPERATIONS   ← existing block, largely unchanged
│   ├─ Maintenance Cycle · My Queue · Car Status · Inspection Review
│   ├─ Service Reminders · Complaints · Driver Observations
│   └─ Completed Repairs · Recurring Fault Reviews
│
├─ ⛁ FLEET  ← existing
│   └─ Vehicles · Customers · Contracts · Drivers · Garages · Vendors · Parts
│
└─ ⚑ PLATFORM
    └─ Data Health ★ (elevated) · Intelligence Center · Sync Audit · Users · Settings
```

## 1.2 Pages absorbed and retired

Consolidation is what earns the right to add a hub. Each of these already exists and overlaps the new
modules:

| Existing page | Disposition | Rationale |
|---|---|---|
| `/cost-intelligence` | **Absorb** → Financial Intelligence | Cost-per-km already lives here; it becomes a tab, not a page |
| `/garages` | **Absorb** → Garage Intelligence · Overview | Current page is a ratings list; the new Overview supersedes it |
| `/garage-finder` | **Keep, relocate** → Garage Intelligence · Finder tab | Distinct job (pre-ticket lookup), same domain |
| `/maintenance-history` | **Absorb** → Vehicle Intelligence · list mode | Same data, better home |
| `/recurring-fault-reviews` | **Keep, cross-link** | Human adjudication queue — an *action* surface, feeds Fault Intelligence |
| `/fleet-utilization` | **Keep, cross-link** | Owns the canonical maintenance-days definition; Financial links to it |
| `/data-health` | **Elevate** | Becomes the target of every confidence badge |
| `/intelligence-center` | **Keep** | Platform-capability view for us, not a business surface |

**Net effect:** the sidebar gains one section and loses three pages.

## 1.3 Route map

```
/executive                              Executive Home (Basem)
/operations-home                        Operations Home (Adham)

/intelligence                           Hub overview
/intelligence/garages                   Garage Overview (leaderboard + matrix)
/intelligence/garages/compare?ids=      Garage Comparison (2–4 garages)
/intelligence/garages/:id               Garage Profile
/intelligence/garages/finder            Garage Finder (relocated)
/intelligence/vehicles                  Vehicle list (health-ranked)
/intelligence/vehicles/:id              Vehicle Profile
/intelligence/faults                    Fault Overview
/intelligence/faults/:signature         Fault Profile
/intelligence/financial                 Financial Overview
/intelligence/financial/lifecycle       Lifetime Cost Curve
/intelligence/financial/warranty        Warranty Leakage
/intelligence/insights                  Insight Feed
/intelligence/insights/:id              Insight Evidence view

/evidence/:queryId                      Universal evidence drawer (deep-linkable)
```

**Every route serialises its filters into the URL.** When Basem forwards a link to Adham, Adham must
land on identical numbers. This is a functional requirement, not a nicety.

## 1.4 Permissions

Uses the existing Spatie `resource.action` model. Three new permissions; everything else reuses what
exists.

| Permission | New? | Grants |
|---|---|---|
| `intelligence.view` | **new** | The hub, Garage/Vehicle/Fault modules |
| `intelligence.executive` | **new** | Executive Home, strategic recommendations |
| `intelligence.financial` | **new** | Financial module (gated additionally by `SHOW_FINANCIALS`) |
| `insights.view` | exists | Insight feed |
| `maintenance.view` / `.manage` | exists | Operations Home, ticket actions |

| Role | Landing | Intelligence access |
|---|---|---|
| **super-admin / admin** | Workspace | All |
| **manager** (Basem) | **Executive Home** | All, incl. financial |
| **maintenance** (Adham) | **Operations Home** | Garage, Vehicle, Fault. **No financial** |
| **finance** | Workspace | Financial + Vehicle (read) |
| **supervisor** | My Queue | Garage, Vehicle (read) |
| **operations / logistics / inspector** | My Queue | None by default |
| **viewer** | Dashboard | Read-only, no financial |

**Design note on Basem's account.** He gets `manager` + `intelligence.executive` + `intelligence.financial`.
His sidebar renders **only** Executive Home, Fleet Intelligence, and Settings. He never sees My Queue,
Inspection Review, or Sync Audit. Hiding operational surfaces from an executive is a feature — the
brief says he does not want operational detail, and the IA should enforce that rather than rely on him
ignoring it.

---

# 2. Executive Dashboard — Basem

**Route** `/executive` · **Primitive reuse:** `MetricCard`, `Gauge`, `RankedBar`, `LineChart`,
`Sparkline`, `Table`, `Badge`, `DateRangePicker`

## 2.1 The first 30 seconds

Basem opens the laptop Monday morning. Above the fold, without scrolling or clicking, he sees
**four sentences and four numbers**:

```
┌────────────────────────────────────────────────────────────────────────────┐
│  Good morning, Basem                    Last 12 months ▾   ⓘ Financials    │
│                                                             as of 31 Mar   │
├────────────────────────────────────────────────────────────────────────────┤
│                                                                            │
│   FLEET HEALTH          MAINTENANCE SPEND       AVAILABILITY      AT RISK  │
│                                                                            │
│      ◕ 72              AED 6.20M               ▁▂▃▅▆ 86%          14 cars  │
│     of 100              ▁▃▅▂▇▃▂ ▼               ▲ 2pts             ▲ 3     │
│                                                                            │
│   38 cars healthy      Tyres are the largest    Measured on       4 need a │
│   14 need attention    single line at 1.87M     closed tickets    decision │
│                                                     (25.7%)                │
│   [Fleet health ▸]     [Cost breakdown ▸]      [Availability ▸]  [Review ▸]│
├────────────────────────────────────────────────────────────────────────────┤
│  ⚡ WHAT CHANGED THIS WEEK                                                  │
│                                                                            │
│  ▸ Faults sent to Deals On Wheels come back in 55 days.                    │
│    The fleet norm is about 120. 473 repairs measured.           [Evidence] │
│                                                                            │
│  ▸ Vehicle 26831 has spent 24 days per workshop visit across               │
│    90 visits — roughly 10× the fleet average.                   [Evidence] │
│                                                                            │
│  ▸ Electrical is the fleet's most common failure: 4,124 events             │
│    across 206 of 438 cars. This is a fleet pattern, not a few cars.        │
│                                                                 [Evidence] │
└────────────────────────────────────────────────────────────────────────────┘
```

**What he can decide in 30 seconds, without a meeting:** stop sending work to one garage; put four
cars on a replacement review; ask why electrical faults are fleet-wide rather than car-specific.

**What is deliberately absent:** ticket counts, queue depths, driver names, status enums. If Basem
wants operational detail he clicks through to it; he is never shown it by default.

## 2.2 Full page specification

### Row 1 — Fleet Health Strip *(4 cards)*

**Card 1 · Fleet Health**
- **Value** `◕ 72 / 100` — fleet-median Vehicle Health Score
- **Meaning** "How healthy is my fleet overall?" Sub-line splits 438 cars into Healthy (80–100) /
  Watch (60–79) / At Risk (40–59) / Critical (<40)
- **Source** V1 · `repair_visits`, `maintenance_signatures`, `fault_recurrence_pairs`,
  `contracts(days)`, `vehicles(odometer, purchase_date)`
- **Visual** `Gauge` + a 4-segment stacked bar of the band distribution
- **Drill-down** → `/intelligence/vehicles` ranked ascending by health
- **Confidence** READY · badge: *438 cars · all inputs ≥78% complete*

**Card 2 · Maintenance Spend**
- **Value** `AED 6.20M` (trailing 12m, repair categories only)
- **Meaning** True repair spend. Explicitly **excludes** sub-rental (AED 14.99M), insurance, salik,
  registration, fuel, fines — a distinction that must be stated on the card, because the raw ledger
  total is 4× larger and someone will eventually compare them
- **Source** E1 · `vehicle_expenses(amount, category, entry_date, vehicle_id)`
- **Visual** `MetricCard` + 24-month `Sparkline`
- **Drill-down** → Financial Intelligence
- **Confidence** READY\* · badge: **as of 31 Mar 2026** *(amber)* — the ledger collapse from
  AED 1.02M/month to under 1k is a data gap, not a saving, and the card must never let that be misread

**Card 3 · Fleet Availability**
- **Value** `86%` with direction vs prior period
- **Meaning** Share of fleet-days not lost to the workshop
- **Source** E7 · `maintenances(out_date, actual_in_date)` + `contracts(days)` + fleet size
- **Visual** `LineChart`, 12 months, with utilisation overlaid
- **Drill-down** → Fleet Utilization (existing page — canonical maintenance-days owner)
- **Confidence** PARTIAL · badge: *measured on 25.7% of tickets that record a return date* — the
  honest framing is "availability is at least 86%," and the card says so

**Card 4 · Vehicles at Risk**
- **Value** `14` cars, `4` with a replacement recommendation
- **Meaning** Cars whose health is declining or whose economics have turned
- **Source** E11 + V12 · health trend + `vehicle_expenses` + `vehicles(purchase_price)`
- **Visual** `MetricCard` with severity chips
- **Drill-down** → Vehicle list filtered `risk = high`
- **Confidence** PARTIAL · badge: *economic test available for 237 of 438 cars* — cars without a
  purchase price show **Insufficient data for economic assessment**, never a verdict built on half
  the criteria

### Row 2 — Where The Money Goes *(2 panels)*

**Panel A · Cost by Vehicle Model** *(E2 — blocked on roadmap F1)*
- **Value** Ranked AED per 1,000 rental days by canonical model
- **Meaning** The purchasing decision: which models bleed
- **Visual** `RankedBar`, model on the left, fleet count as a secondary muted bar
- **Filters** period · make · year band · expense category · min fleet count (default 3)
- **Drill-down** model → vehicle list → vehicle profile → ticket → expense rows with their raw
  `remarks` text
- **Design note** Models with <3 cars render greyed and unranked. Normalisation is **per 1,000 rental
  days**, never per vehicle — fleet counts run 1 to 53 and a per-vehicle average would be dominated
  by singletons
- **Blocker banner until F1 ships:** *Model grouping unavailable — the vehicle register lists Kia
  under six different spellings. [Fix in Vehicle Register ▸]*

**Panel B · Repair Cost by Category** *(E9)*
- **Visual** Treemap; fallback `RankedBar` if treemap is not worth building
- **Verified data** tyres 1.87M · other 1.56M · oil_fluids 725k · body_paint 723k · electrical 685k ·
  brakes_suspension 493k · service_repair 467k · engine_transmission 439k · ac_cooling 349k ·
  parts 311k
- **Drill-down** category → 12-month trend → vehicles → individual expense rows
- **Design note** Tyres being the largest true repair line is genuinely surprising and is the kind of
  fact that justifies the platform. Give it a callout, not just a rectangle

### Row 3 — Garage Performance *(the flagship, surfaced executively)*

**Panel · Worst-Performing Garages** *(E5 / E6 / G2)*
- **Value** Ranked by average days until the same fault returns, ascending (worst first)
- **Verified live data:**

| Garage | Same fault returns in | Repeats ≤30d | Repairs measured |
|---|---:|---:|---:|
| *Alresala al zahabia* | *19.6 days* | — | *29 — below gate* |
| Deals On Wheels auto | 55.0 days | — | 473 |
| ALTIQNIAH AL ALIAH | 110.4 days | — | 183 |
| *— fleet norm ~120 days —* | | | |
| POWER POINT | 124.8 days | — | 1,605 |
| GPT GARRAGE | 131.0 days | — | 719 |
| HOT LINE | 148.8 days | — | 420 |

- **Visual** Dumbbell chart — garage marker against the fleet-average marker, one row per garage
- **Filters** fault category *(defaults to All, but the card warns when comparing across categories)*
· period · min sample (default 30) · exclude non-garage locations *(default ON)*
- **Drill-down** garage → Garage Profile → fault category → the actual recurrence pairs
- **Mandatory caveat line on the card:** *Tyre and body shops naturally see faster returns. Compare
  within the same repair type.* Without it, this table gets misread as a pure quality ranking

**Panel · Cost of Rework** *(G17)*
- **Value** `AED ~N` estimated cost of repairs done twice in 12 months
- **Meaning** Money paid for work that did not hold
- **Source** recurrences ≤30 days × fleet-average category cost
- **Visual** `MetricCard` + per-garage `RankedBar`
- **Confidence** READY\* · badge: *estimated using fleet-average category costs, not billed amounts*
- **Design note** This is the most persuasive garage number available to us and it sidesteps the
  missing expense→garage join entirely. Label it "estimated" everywhere it appears

### Row 4 — Fleet Failure Patterns

**Panel · Top Recurring Failures** *(E10 / F-1)*
- **Visual** Pareto — bars descending with a cumulative line
- **Verified** ELECTRICAL 4,124 (206 cars) · INTERIOR 3,376 (205) · OIL_SERVICE 2,982 (202) ·
  STEERING 2,870 (190) · TYRE 2,521 (198) · LIGHTS 2,350 (184)
- **Drill-down** fault → Fault Profile
- **Design note** Each bar carries a **concentration chip** (F-16): `spread` or `concentrated`.
  ELECTRICAL across 206 of 438 cars is a *fleet* problem; the same count inside 20 cars is a
  *disposal* problem. One chip, opposite decisions

### Row 5 — Lifecycle Economics *(the strategic panel)*

**Panel · Lifetime Cost Curve** *(C13)*
- **Value** Repair spend per vehicle-month by vehicle age band (0–1y, 1–3y, 3–5y, 5y+)
- **Meaning** *At what age does a car in our fleet stop being profitable?* — converts replacement
  from case-by-case argument into policy
- **Source** `vehicle_expenses` (12 years) + `vehicles.purchase_date` (438/438)
- **Visual** `LineChart` — cost/month rising with age, with the fleet's current age distribution as a
  faint histogram behind it, so Basem sees how much of his fleet sits past the inflection
- **Drill-down** age band → vehicles in that band → profiles
- **Opinion** This is the highest-value panel on the page and nobody has built it. Give it full width

**Panel · Warranty Leakage** *(C15)*
- **Value** `AED N` spent on vehicles still under warranty
- **Meaning** Possibly recoverable cash
- **Source** `vehicles(warranty_end_date, warranty_end_km)` 383/438 + `vehicle_expenses`
- **Visual** `MetricCard` + table of candidate vehicles and amounts
- **Drill-down** → vehicle → the expense rows inside the warranty window
- **Confidence** READY\* · *warranty dates known for 383 of 438 cars*

### Row 6 — Insights Feed
Top 5 insight cards (§8), ranked by severity × impact × recency. `[See all insights ▸]`

## 2.3 Executive page rules

- **One screen, six scroll-stops.** No tabs on Executive Home — tabs hide things from a user who will
  not go looking
- **No table exceeds 10 rows** before a "view all" link
- **Every panel has exactly one drill-down verb.** Never a row of icon buttons
- **Print/PDF export** on the whole page. Basem will want to take it into a meeting, and if we don't
  provide it he will screenshot it — losing the confidence badges in the process

---

# 3. Maintenance Operations — Adham

**Route** `/operations-home` · This is a **workspace**, not a report. Every tile is a worklist and
every number is a button.

## 3.1 Layout

```
┌───────────────────────────────────────────────────────────────────────────────┐
│  Operations · Monday 3 August           Today ▾    All garages ▾   [+ Ticket] │
├───────────────────────────────────────────────────────────────────────────────┤
│  NEEDS YOU NOW                                                                │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐            │
│  │   140    │ │    12    │ │     7    │ │     5    │ │   25.7%  │            │
│  │ Pending  │ │ Delayed  │ │  Under   │ │ Awaiting │ │ Closure  │            │
│  │  review  │ │  return  │ │  repair  │ │  driver  │ │   rate ⚠ │            │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘            │
├───────────────────────────────────────────────────────────────────────────────┤
│  IN THE WORKSHOP NOW                    │  GARAGE WORKLOAD                    │
│  ┌─────────────────────────────────┐    │  ┌───────────────────────────────┐ │
│  │ Car    Garage    Day  Promise   │    │  │ GPT GARRAGE     ███████ 7     │ │
│  │ 60385  GPT        4    ⚠ +1     │    │  │ Deals On Wheels ████   4      │ │
│  │ 26831  Deals      12   ⚠ +6     │    │  │ RMR             ██     2      │ │
│  │ 75985  RMR         1    ✓        │    │  │ FUTURE TYRES    █      1      │ │
│  └─────────────────────────────────┘    │  └───────────────────────────────┘ │
├───────────────────────────────────────────────────────────────────────────────┤
│  PROMISE vs ACTUAL          │  REPAIR AGING          │  CRITICAL VEHICLES     │
│  94.6% met (n=5,169)        │  0-2d ████████ 61      │  60385 · 149 visits    │
│  Avg promise 6.8d           │  3-7d ████ 22          │  26831 · 24.2 d/visit  │
│  Avg actual  2.5d           │  8-14d ██ 9            │  48304 · 105 visits    │
│  ⚠ 4.3 days of slack        │  15d+  █ 4  ⚠          │                        │
└───────────────────────────────────────────────────────────────────────────────┘
```

## 3.2 Tile specifications

| Tile | Value | Meaning | Source | Visual | Drill-down |
|---|---|---|---|---|---|
| **Pending Review** | 140 | Largest live blockage in the workflow | O6 · `workflow_status='pending_review'` | Count card, red | Filtered ticket list → ticket |
| **Delayed Return** | count | Past the promised return date | O2 · `expected_return_date` (14,213) + `follow_date` (26,666) | Count card + overdue table | Overdue list sorted by days late |
| **Under Repair** | 7 | Cars physically in a workshop | O7 · `workflow_status` | Count card | Car Status board (existing) |
| **Awaiting Driver** | 5 | Ready but no collection | O5 · `awaiting_dispatch`, `ready_for_pickup` | Count card | Logistics dispatch |
| **Closure Rate** ⚠ | 25.7% | Share of tickets ever closed | O15 · `actual_in_date` fill | Trend + target band | Never-closed ticket list |
| **In The Workshop Now** | table | Live custody with day count | `repair_visits` + workflow | `Table`, promise-delta coloured | Ticket → vehicle timeline |
| **Garage Workload** | bars | Open tickets vs 90-day throughput | O10 · `vendor_id` | `RankedBar` + capacity marker | Garage Profile |
| **Promise vs Actual** | 94.6% | SLA + how much slack we build in | O8 · verified n=5,169 | Gauge + distribution histogram | Ticket list by variance |
| **Repair Aging** | histogram | Age distribution of open work | O9 · `out_date` | `BarChart` buckets | Tickets in bucket |
| **Critical Vehicles** | list | Repeat offenders needing a decision | E4 · `repair_visits` | Ranked list | Vehicle Profile |

**Design note on Promise vs Actual.** 94.6% compliance against a 6.83-day promise and a 2.53-day
actual is not a success story — it means the promises are padded by ~4.3 days. The tile therefore
shows **three numbers**, not one, and flags the slack. Showing 94.6% alone would be a vanity metric,
and Adham would learn nothing from it.

## 3.3 User workflows

**W1 · Chasing a delayed repair** *(the daily loop)*
```
Delayed Return (12) → list sorted by days overdue
  → row: car 26831, Deals On Wheels, promised 28 Jul, 6 days late
    → [Open vehicle timeline]  — every visit, fault, garage, day count
    → sidebar shows: this garage's on-time rate 61%, its avg return 72 days
    → [Log checkpoint] — records the chase, sets a new promise date
    → [Call garage] — phone from vendors.phone
```
The garage's own performance appears **in the chase context**, so Adham knows whether he is dealing
with an unusual delay or a chronic supplier — which changes what he says on the call.

**W2 · Morning triage** — Pending Review (140) → batch-select → approve/reject with reason →
approved tickets flow to dispatch. The 140 is the single biggest lever on this page.

**W3 · Routing a new repair** — new ticket → select faults → **Garage Finder** (relocated into the
Intelligence hub, reachable inline) → per-fault recommendation with each garage's history for *that*
fault → assign.

**W4 · Investigating a repeat** — Critical Vehicles → vehicle → **Recurring faults** panel → same
fault, previous garage, days between → `[Send to Recurring Fault Review]` (existing adjudication
queue) for a formal decision on responsibility.

---

# 4. Garage Intelligence ★

**The flagship.** Best data in the platform (`vendor_id` 93.5% across 26,942 tickets, 3 years),
highest decision value. Three pages.

## 4.0 Corrections applied on every surface

Rendered as a visible, dismissible-but-persistent note on the Overview page — not hidden in code:

> **How these numbers are built.** Non-repair locations (Office Parking, Under Test, unnamed "Garage"
> — 3,916 tickets) are excluded. Garages are compared **within the same repair type**, because tyre
> shops naturally see faster returns than mechanical shops. Any garage with fewer than 30 measured
> repairs shows **Not enough data** rather than a score. [How we measure ▸]

## 4.1 Page 1 — Garage Overview `/intelligence/garages`

**Section A · Leaderboard**
- `Table` / card hybrid, one row per garage: name · quality score (or *Not enough data*) · same-fault
  return days · repeat ≤30d % · median repair days · ticket volume · trend sparkline
- Sortable on every column; default sort = quality ascending (problems first)
- **Filters** fault category · period · min sample (default 30) · exclude non-garages (default ON) ·
  active-only

**Section B · Garage × Fault Matrix** *(G16 — the most useful screen in the platform)*
```
                ELEC  ENGINE  BRAKE  SUSP   AC    TYRE  BODY  ...
GPT GARRAGE      ███   ███     ██     ███   ██     ·     ██
Deals On Wheels  ██    ██      ███    ██    ███    ·     ███
RMR              ███   ███     ██     ███   ·      ·     ·
FUTURE TYRES      ·     ·      ·      ·     ·     ███    ·
Deals On Wheels   ░     ░      ██     ░     ·      ·     ██
                                      ░ poor  ██ good  ███ strong  · no data
```
- **Cell colour** = quality score for that garage × fault · **cell size** = volume · **blank** =
  below n=30
- **Interaction** hover → mini-stats popover; click → filtered ticket list; shift-click multiple
  garages → Comparison page
- **Why this matters:** it answers "where do I send a car with an AC fault?" in one glance. A manager
  acts on this matrix, not on a leaderboard number

**Section C · Cost of Rework** *(G17)* — ranked bar, estimated AED wasted on repairs that did not
hold, per garage.

**Section D · Insights** — 3–5 garage insight cards.

## 4.2 Page 2 — Garage Comparison `/intelligence/garages/compare?ids=331,364`

Side-by-side, 2–4 garages, one metric per row:

```
                          GPT GARRAGE      Deals On Wheels    Fleet avg
Same fault returns in     131.0 days ✓     55.0 days ⚠        ~120 days
Repeats within 30 days    20.3%      ✓     38.6%     ⚠        ~28%
Repairs measured          719              473                —
Median repair time        N days           N days             2.5 days
Promise met               N%               N%                 94.6%
Strongest at              Engine, Elec     Body, Brakes       —
Weakest at                Body             Suspension         —
Volume trend              ▁▂▄▆█ rising     ▃▅▆▇█ rising       —
```

- Best value per row highlighted; fleet average always present as the third column
- **Filter by fault category recomputes every row** — this is the point of the page, because a garage
  that is strong overall may be weak at the specific job in hand
- **Drill-down** any cell → the tickets behind it

## 4.3 Page 3 — Garage Profile `/intelligence/garages/:id`

```
┌──────────────────────────────────────────────────────────────────────────┐
│  GPT GARRAGE                                        garage · active      │
│  2,713 tickets · Oct 2024 – Aug 2026 · currently holds 7 cars            │
│                                                                          │
│  ┌────────────┬────────────┬────────────┬────────────┐                  │
│  │ Quality    │ Same fault │ Repeats    │ Median     │                  │
│  │ ██ 78/100  │ 131.0 days │ 20.3%      │ N days     │                  │
│  │ 3 of 5     │ better than│ better than│ vs fleet   │                  │
│  │ measures   │ fleet ✓    │ fleet ✓    │ 2.5d       │                  │
│  └────────────┴────────────┴────────────┴────────────┘                  │
├──────────────────────────────────────────────────────────────────────────┤
│  Tabs:  Overview │ By fault type │ Repeat repairs │ Timeline │ Tickets   │
└──────────────────────────────────────────────────────────────────────────┘
```

**Quality card design note.** It reads **"3 of 5 measures"** because cost and re-inspection data are
unavailable for most garages. The card lists which measures were used and which were skipped. Per the
no-imputation rule, remaining weights are renormalised — and the user is told.

**Tab · By fault type** — a table of every fault category this garage handles: volume, quality,
return days, vs fleet. This is the specialisation evidence.

**Tab · Repeat repairs** — **the evidence table that makes the whole module defensible:**

| Vehicle | Fault | Repaired | At | Came back | Days | Then went to |
|---|---|---|---|---|---:|---|
| 60385 | BRAKES | 12 Mar 2026 | GPT | 24 Mar 2026 | 12 | GPT |
| 75985 | ELECTRICAL | 03 Feb 2026 | GPT | 19 Mar 2026 | 44 | RMR |

Both tickets, side by side, with dates. When a garage owner disputes the score, this table is the
answer — and it is on screen rather than in a follow-up email.

**Tab · Timeline** — quality score by quarter, volume overlaid. Catches decline before it costs.

**Tab · Tickets** — the raw filtered list.

## 4.4 Example insights on this module

> **Deals On Wheels auto** — faults come back after **55.0 days** on average, against roughly 120
> days across the fleet. Based on **473 measured repairs**.
> *Consider reviewing routing before further work is sent.* [See the 473 repairs ▸]

> **HOT LINE** had one of the longest gaps before a fault returned — **148.8 days** (n=420) — but has received no work
> since **November 2024**. [See history ▸]

> **FUTURE TYRES** shows a 119-day average return, but 94% of its work is tyres, which recur by
> nature. Compared only against other tyre work, it ranks *N*th of 4. [Compare tyre specialists ▸]

---

# 5. Vehicle Intelligence

**Routes** `/intelligence/vehicles` (list) · `/intelligence/vehicles/:id` (profile)

## 5.1 List view
Health-ranked table: plate · model · health score bar · visits/1,000 rental days · downtime days ·
12-month cost · risk chip · recommendation chip.
**Filters** model *(post-F1)* · age band · health band · risk · status · condition grade · garage.
**Views** `Segmented`: Health · Cost · Downtime · Risk.

## 5.2 Profile

```
┌──────────────────────────────────────────────────────────────────────────┐
│  ← 60385 · Jeep Grand Cherokee SRT · 2021       ⬤ Available   [Actions ▾]│
│  VIN ···· · 84,220 km · in service since Mar 2023                        │
├──────────────────────────────────────────────────────────────────────────┤
│  HEALTH 41/100  At Risk        RISK  High        RECOMMENDATION          │
│  ◔                             ▲ worsening        ⚠ Evaluate             │
│                                                   for replacement        │
│  What is pulling it down:                                                │
│  Repair frequency  ████████░░  −22                                       │
│  Recurrence        ██████░░░░  −16                                       │
│  Downtime          █████░░░░░  −12                                       │
│  Fault breadth     ███░░░░░░░   −6                                       │
│  Severity load     ██░░░░░░░░   −3                                       │
│  Age & mileage     ░░░░░░░░░░    0                                       │
├──────────────────────────────────────────────────────────────────────────┤
│  Tabs: Overview │ Timeline │ Faults │ Cost │ Downtime │ Components       │
└──────────────────────────────────────────────────────────────────────────┘
```

**Health card.** The six components are **always visible**, never behind a tooltip. A bare gauge
violates the project's no-black-boxes rule and will not survive a challenge. Each bar is clickable →
the tickets that produced it.

**Tab · Timeline** *(V4)* — the investigation surface. A horizontal time axis with four lanes:
```
        2025 Q3      2025 Q4      2026 Q1      2026 Q2
Rentals ▬▬▬▬  ▬▬▬▬▬▬  ▬▬▬  ▬▬▬▬▬▬▬▬  ▬▬▬▬   ▬▬▬▬▬▬
Repairs   ●BRAKE   ●ELEC ●BRAKE      ●AC        ●BRAKE ⟲
Costs      ▲320     ▲1,150            ▲480       ▲890
Events         ⓘ inspection    ⓘ condition: yellow
```
`⟲` marks a recurrence — hovering shows "same fault, 12 days after the previous repair at GPT."
Clicking any marker opens the ticket. Zoom and pan; default window 12 months.

**Tab · Faults** *(V5)* — recurring faults grouped into chains: fault, occurrences, first/last seen,
average gap, garages involved. Each chain expands to the individual visits.

**Tab · Cost** *(V7/V8/V9)* — 12-month spend by category, cost per km, cost per rental day, vs the
model average. **Coverage note on the panel:** *cost data available through 31 Mar 2026*.
Mileage comes from `contracts(out_milage, in_milage)` — 96%+ populated — never from the
`maintenances` odometer columns, which are effectively empty.

**Tab · Downtime** *(V6)* — days in shop vs days rented vs idle, by month, with rent lost at
`day_rent_value` (437/438 populated, mean AED 385/day).

**Tab · Components** — **shows an empty state, deliberately.** Real installed components: 2 fleet-wide.
The panel reads: *Component tracking starts when parts capture is switched on. No component history
recorded for this vehicle.* We do **not** render the 3,475 seeded demo rows. This is the single
clearest place where the platform proves it does not fabricate.

**Replacement recommendation** — `Keep` / `Monitor` / `Evaluate` / `Replace`, with the triggering rule
in plain language: *"Repairs in the last 12 months came to AED N — 47% of what the car cost. The
threshold is 40%."* For the 201 cars with no purchase price: **Insufficient data for economic
assessment**, with a link to fix the register.

---

# 6. Fault Intelligence

**Routes** `/intelligence/faults` · `/intelligence/faults/:signature`

## 6.1 Overview

**Section A · Fault Ranking** *(F-1)* — Pareto bars with a cumulative line. Each row carries:
occurrences · vehicles affected · **concentration chip** · recurrence rate · avg return days · trend
arrow.

**Section B · Concentration** *(F-16)* — the interpretive panel:
> **ELECTRICAL** appears on **206 of 438** cars — spread across the fleet. This is a specification or
> supplier question, not a few bad cars.
> **KEY** appears on **81** cars but **62% of occurrences sit in 12 vehicles** — concentrated. These
> are vehicle problems.

Lorenz curve per fault plus a concentration league table. Same raw counts, opposite decisions — no
other metric distinguishes them.

**Section C · Trends** *(F-3/F-4)* — small-multiple sparklines, one per signature, 24 months,
**normalised per 100 vehicles**. Fastest-growing highlighted. Normalisation is mandatory: the fleet
grew 129→186 cars, and raw counts would read growth as deterioration.

**Section D · Fault × Garage** *(F-17)* — the transpose of G16, read fault-first: for this fault, who
handles it and who is good at it.

**Section E · Fault × Model** *(F-7, blocked on F1)* — heatmap, per 1,000 rental days.

## 6.2 Fault Profile `/intelligence/faults/BRAKES`

Header: occurrences · vehicles affected · recurrence rate · avg return days · trend.
Tabs: **Trend** (monthly, normalised) · **By garage** (who fixes it best — dumbbell) ·
**By vehicle** (worst-affected cars) · **By model** *(post-F1)* · **Related faults** (F-9
co-occurrence, empirical lift) · **Tickets**.

**Related faults design note.** Co-occurrence is **measured** from 49,487 rows. Where it contradicts
the seeded ontology (92% authored, not learned), the UI shows the measured relationship and offers
`[Flag ontology edge for review]`, writing to `ontology_feedback` (currently 0 rows). This is how the
knowledge graph starts earning its weights instead of asserting them.

**What this page never shows:** a predicted probability of the fault occurring. Our own backtest
returned 1.08× lift and −42% skill. The page shows what *has* happened, at what rate, with what
sample.

---

# 7. Financial Intelligence

**Route** `/intelligence/financial` · Gated by `intelligence.financial` + `SHOW_FINANCIALS`.
**Adham does not see this module.**

## 7.1 The watermark is part of the layout

A persistent amber band below the page title, not a footnote:

> **Financial data is current through 31 March 2026.** Later months are incomplete — spend fell from
> AED 1.02M in December 2025 to under AED 1,000 in July 2026 because the ledger stopped being
> updated, not because costs fell. [Data Health ▸]

Any date filter extending past 2026-03-31 shows the affected range hatched on every chart.

## 7.2 Panels

| Panel | Metric | Visual | Coverage note shown |
|---|---|---|---|
| **Repair Spend** | C1/C6 · AED 6.20M, 24-month trend | `LineChart` + category `Segmented` | Excludes sub-rental, insurance, salik, fuel, fines |
| **Cost per Vehicle** | C1 · ranked | `Table` + `Sparkline` | *82% of expense rows linked to a vehicle* |
| **Cost per Kilometre** | C9 · AED/km by vehicle and model | `RankedBar` | Mileage from rental contracts (96% coverage) |
| **Cost per Rental Day** | C10 | `RankedBar` | — |
| **Lifetime Cost Curve** | C13 · spend per vehicle-month by age band | `LineChart` + age histogram | 12 years of ledger |
| **Spend Concentration** | C14 · top 10% of cars = N% of spend | `LeaderDonut` + Lorenz | — |
| **Warranty Leakage** | C15 · AED on in-warranty cars | `MetricCard` + candidate table | *Warranty dates for 383 of 438 cars* |
| **Downtime Cost** | E8 · days × day rate | Waterfall by fault category | *Measured on closed tickets (25.7%)* |
| **Cost by Garage** | C3 · **index, not total** | `RankedBar` + coverage donut | **See below** |

## 7.3 Cost by Garage — the honest design

This panel is where a lesser product would lie. Only **AED 705,622 across 1,259 expenses (~20%)**
attributes to a single garage with confidence; the rest falls inside 2–14 overlapping ticket windows.

**Design decision: show the coverage donut *before* the ranking, not after.**

```
┌────────────────────────────────────────────────────────────────┐
│  Cost by Garage                                                │
│                                                                │
│  ◐  Of AED 3.1M in repair spend since 2024:                    │
│     ▓▓ AED 706k (23%) traced to one garage                     │
│     ░░ AED 1.5M  (48%) matched several garages — not assigned  │
│     ·· AED 880k  (28%) matched no ticket                       │
│                                                                │
│  Ranking below uses the traced 23% only.                       │
│  Expense records do not store which garage was paid.           │
│  [Why ▸]                          [Fix this — add garage to    │
│                                    the expense form ▸]         │
├────────────────────────────────────────────────────────────────┤
│  GPT GARRAGE      ████████ AED N/repair  (n=NNN traced)        │
│  Deals On Wheels  ██████   AED N/repair  (n=NNN traced)        │
└────────────────────────────────────────────────────────────────┘
```

**Absolute rule: ambiguous cost is never spread proportionally.** A table that silently allocates 77%
of spend by heuristic is worse than no table — it would be wrong in ways nobody can audit. The
unassigned buckets stay visible, and the fix is offered as a call to action rather than hidden as a
caveat.

---

# 8. AI Insights Experience

**No predictions. No confidence percentages. No generated prose.**
Every insight is a **template rendered over a verified query**, carrying its numbers, sample size,
baseline, and a link to the rows.

## 8.1 Where insights appear

| Surface | Behaviour |
|---|---|
| **Executive Home · What Changed** | Top 5, executive-scoped, refreshed weekly |
| **Operations Home** | Top 3, operational-scoped, refreshed daily |
| **Module pages** | 3–5 scoped to that module (garage insights on the garage page) |
| **Entity pages** | Insights about *this* garage/vehicle/fault, in context |
| **Insight Feed** `/intelligence/insights` | Everything, filterable, with history |
| **Notification bell** | Only severity=critical, and only on state change |

## 8.2 Anatomy of an insight card

```
┌──────────────────────────────────────────────────────────────────┐
│  ⚠  GARAGE QUALITY                              3 Aug · Critical │
│                                                                  │
│  Faults sent to Deals On Wheels come back after                  │
│  55 days on average. Across the fleet it takes about 120.        │
│                                                                  │
│  ┌────────────────────────────────────────────────────┐          │
│  │  Deals On Wheels ●─────────── 55d                  │          │
│  │  Fleet                 ──────────────● ~120d       │          │
│  └────────────────────────────────────────────────────┘          │
│                                                                  │
│  Based on 473 measured repairs over full history                 │
│                                                                  │
│  [Show the 473 repairs]  [Open garage]  [Not useful]  [Dismiss]  │
└──────────────────────────────────────────────────────────────────┘
```

**Required elements — an insight without these does not ship:** severity chip · domain label ·
plain-language claim with both numbers · inline micro-visual · **sample size and window** · evidence
link · feedback control.

## 8.3 Evidence view — the trust mechanism

`[Show the 473 repairs]` opens a `Drawer` (deep-linkable at `/evidence/:queryId`) with three stacked
sections:

1. **The claim** — restated, with the exact figures
2. **How it was measured** — plain language: *"For each repair at this garage, we looked for the same
   fault returning on the same car. 473 such pairs were found. We measured the days between."*
   Under **Technical details**: the signature vocabulary, `is_exposure=0` filter, exclusion list, n≥30 gate
3. **The rows** — the actual paired tickets, exportable to CSV, each row clicking through to the ticket

**This drawer is the product's credibility.** It is the thing that turns "the system says" into "here
is what happened."

## 8.4 Insight catalogue (all verified, all Tier-1 or Tier-2)

**Garage** — same-fault return time vs fleet · repeat rate by fault category · quality trend decline ·
a strong garage stopped receiving work · specialisation mismatch in routing

**Vehicle** — visit count outlier · downtime outlier (26831: 24.2 days/visit) · declining health ·
crossed the replacement threshold · in-warranty spend detected

**Fault** — fastest-growing fault · concentration verdict (fleet problem vs vehicle problem) ·
seasonal onset · recurrence rate above fleet

**Operational** — pending review backlog · SLA slack (promise 6.8d vs actual 2.5d) · closure-rate
warning · garage over capacity

**Financial** *(Basem/finance only)* — largest cost category · record monthly spend · spend
concentration · warranty leakage candidates

**Data integrity** *(shown to admins from day one, per roadmap: ship in Phase 1)* — financial
staleness · unlinked expense rows · synthetic-data quarantine · make/model spellings blocking model
reporting

## 8.5 Feedback loop
`[Not useful]` writes to a feedback store with the reason. Insights dismissed by the same user three
times stop appearing. Feedback rates surface in Intelligence Center — an insight nobody finds useful
is a template to retire, and we should be able to see that.

---

# 9. UI Component Library

## 9.1 Reuse — existing primitives, no changes needed

`MetricCard` · `Gauge` · `RankedBar` · `LineChart` · `MultiLineChart` · `GroupedBarChart` ·
`BarChart` · `Sparkline` · `CompositionDonut` · `LeaderDonut` · `Table` · `Tabs` · `Segmented` ·
`Drawer` · `Modal` · `Badge` · `FilterChips` · `DateRangePicker` · `SearchSelect` · `Progress` ·
`Skeleton` · `Toast` · `Tooltip` · `CountUp` · `Pagination` · `Icon`

## 9.2 Extend — existing components gaining an intelligence variant

**`MetricCard` → `IntelligenceMetricCard`**
- **Adds:** confidence badge (linking to Data Health), sample-size line, as-of chip, coverage note,
  single drill-down verb, `insufficient_sample` state
- **Use:** every KPI tile in every module
- **Example:** `Fleet Availability · 86% · ▲2pts · measured on 25.7% of tickets · [Availability ▸]`

**`Gauge` → `ScoreCard`**
- **Adds:** mandatory component-contribution breakdown bars, "N of M measures used" line, per-component
  drill-down
- **Use:** Vehicle Health, Vehicle Risk, Garage Quality
- **Example:** `41/100 At Risk` with six weighted bars beneath, each clickable
- **Rule:** a `ScoreCard` may never render without its breakdown

**`Table` → `RankingTable`**
- **Adds:** fleet-average reference row pinned, per-row sparkline, sample-size column, `Not enough
  data` row state, multi-select → compare
- **Use:** garage leaderboard, vehicle ranking, fault ranking

## 9.3 New — six components

**`ComparisonDumbbell`**
- **Purpose:** one entity's value against a benchmark on a shared axis
- **When:** any "this garage vs the fleet" comparison — the signature visual of Garage Intelligence
- **Example:** `Deals On Wheels ●—— 55d ······ ●~120d Fleet`
- **Notes:** two markers + connector; colour by better/worse; sample size on the row; RTL-mirrored

**`PerformanceMatrix`**
- **Purpose:** two-dimensional entity × category grid, colour = quality, size = volume
- **When:** G16 garage × fault; F-7 fault × model
- **Example:** rows = 12 garages, columns = 20 fault signatures
- **Notes:** blank cell = below n=30 (never a pale colour, which reads as "poor"); hover popover;
  click → filtered list; horizontally scrollable inside its own container on narrow screens

**`EvidenceDrawer`**
- **Purpose:** the universal claim → method → rows disclosure
- **When:** behind every insight and every drill-down that ends in raw data
- **Example:** claim, plain-language method, 473 paired tickets, CSV export
- **Notes:** deep-linkable; **the single most important new component in this spec**

**`InsightCard`**
- **Purpose:** render one verified insight
- **When:** all six insight surfaces
- **Notes:** severity chip, claim, micro-visual, sample line, actions. Never renders without sample
  size — enforced by the component, not by the caller

**`VehicleTimeline`**
- **Purpose:** multi-lane chronological view of one vehicle
- **When:** Vehicle Profile · Timeline tab; investigation flows
- **Example:** four lanes — rentals, repairs, costs, events — with `⟲` recurrence markers
- **Notes:** zoom/pan; marker click → entity; lane toggles; the recurrence marker is what makes it an
  investigation tool rather than a log

**`CoverageDisclosure`**
- **Purpose:** show what share of the underlying data a number actually covers, *before* the number
- **When:** Cost by Garage (23% traced), availability, any PARTIAL metric
- **Example:** the three-band donut in §7.3
- **Notes:** deliberately placed **above** the ranking it qualifies. Placing it below would make it a
  disclaimer; above, it is context

## 9.4 States — every data component implements all five

| State | Rendering |
|---|---|
| **Loading** | `Skeleton` at final dimensions — no layout shift |
| **Empty (no data)** | Icon + what would populate it + the action that would ("Component tracking starts when parts capture is switched on") |
| **Insufficient sample** | `Not enough data yet — 12 of 30 repairs measured`, with the progress implied |
| **Blocked (prerequisite)** | Amber panel naming the blocker + link to fix it ("Model grouping unavailable — six spellings of Kia in the register") |
| **Error** | Plain message + retry + the metric code for support |

## 9.5 Visual language

- **Severity:** critical `#DC2626` · warning `#D97706` · good `#059669` · neutral `--ink-muted`.
  Never colour alone — always paired with an icon or label, for accessibility and for print
- **Confidence badges:** `Verified` (green outline) · `Partial · N%` (amber) · `Estimated` (grey) ·
  `As of 31 Mar 2026` (amber, financial only)
- **Charts:** one accent per series; the fleet benchmark is always a dashed neutral reference; grid
  lines use `--chart-line`
- **Numbers:** AED thousands separators, no decimals above 1,000; percentages one decimal; durations
  in days with one decimal below 10
- **RTL:** all charts mirror; numerals stay LTR; dumbbell and matrix flip direction. Acceptance
  criterion on every component, not a follow-up pass
- **Dark mode:** existing `index.css` tokens; charts re-derive from `--chart-line` and `--ink`

---

# 10. User Journeys

## Scenario 1 — Basem, Monday 09:00

**Sees (30 seconds, no clicks):** fleet health 72/100 with 14 cars needing attention · AED 6.20M
trailing spend, flagged as current to 31 March · availability 86%, honestly labelled as measured on
a quarter of tickets · three insight cards: a garage returning faults in 18 days, a car averaging 24
days per visit, electrical faults across 206 of 438 cars.

**Journey:**
```
Reads insight → "Deals On Wheels: 55 days vs fleet 120"
  → [Show the 473 repairs] → EvidenceDrawer: paired tickets, real dates
  → [Open garage] → Garage Profile → By-fault tab: weak across all categories, not one
  → [Compare] with Deals On Wheels and GPT → Comparison page
  → Decision: change routing at Deals On Wheels; message Adham with the link
```
**Second thread:** Vehicles at Risk (14) → filtered list → 26831 (24.2 days/visit) → Vehicle Profile
→ health 38, recurring AC and electrical → Cost tab: AED N over 12 months → recommendation
**Evaluate for replacement** with the rule stated → **Decision: put it on the disposal list.**

**Third thread:** Lifetime Cost Curve → cost per vehicle-month rises sharply after year 3, and 40% of
the fleet is past it → **Decision: set a fleet-wide replacement policy rather than deciding car by
car.** This is the strategic outcome the platform exists for.

**Total: about 6 minutes, three decisions, every number traceable to rows.**

## Scenario 2 — Adham investigates repeated brake failures

```
Operations Home → Critical Vehicles → notices brake repeats
  → Fault Intelligence → BRAKES profile
      occurrences 1,405 · 163 vehicles · recurrence N% · avg return N days
  → Concentration chip: "concentrated — 41% of occurrences in 18 cars"
      ⇒ this is a vehicle-and-garage question, not a fleet-wide spec question
  → By garage tab → dumbbell: Garage A returns brakes in 22 days, fleet 78
  → [Show repairs] → EvidenceDrawer → 34 paired tickets, same cars, same fault
  → By vehicle tab → three cars account for 40% of the repeats
  → Opens each Vehicle Profile → Timeline → sees the ⟲ recurrence markers clustered
      after repairs at the same garage
  → Actions:
      1. [Send to Recurring Fault Review] for formal responsibility adjudication
      2. Garage Finder → reroute brake work to the strongest brake garage
      3. Flags the three cars for inspection
```
**Time: about 10 minutes.** Previously this required exporting spreadsheets and manual cross-referencing,
if it happened at all.

## Scenario 3 — A garage problem surfaces itself

**The point of this scenario: nobody had to go looking.**

```
Week 1  · Quality trend crosses threshold → insight generated automatically
Week 1  · Adham sees it on Operations Home (operational scope, daily refresh)
          → opens Garage Profile → Timeline: quality falling three quarters running
          → By-fault: the decline is concentrated in suspension work
Week 1  · Adham reroutes suspension work; logs the reason
Week 2  · Insight escalates to severity=critical (no improvement)
          → appears on Basem's Executive Home under "What Changed"
          → notification bell fires (critical only)
Week 2  · Basem opens it, sees the same evidence Adham saw, plus Cost of Rework
          → [Compare] against two alternatives → confirms the reroute
          → Decision: renegotiate or terminate the vendor relationship
```

**Both users saw the same evidence, at the altitude appropriate to their role, without either
producing a report.** That is the product.

---

# 11. Cross-Cutting Requirements

**Responsive.** Desktop-first (both users work on laptops). Tablet: cards stack, matrix scrolls
horizontally inside its own container — the page body never scrolls sideways. Phone: Operations Home
and insight feed only; the executive page is explicitly not a phone surface.

**Performance.** Every dashboard renders in <2s from cache. Heavy aggregates (`fault_recurrence_pairs`,
`repair_visits`) are materialised nightly per roadmap F2/F4. Drill-downs query live. Every panel loads
independently — one slow query never blocks the page.

**Accessibility.** WCAG AA contrast in both themes · full keyboard navigation including the matrix ·
chart data available as a table alternative · severity never conveyed by colour alone · every
interactive element labelled through `tf()`.

**i18n.** Every string through `tf()`/`tp()`; `npm run check:i18n` must pass. Insight templates are
translatable with parameter slots — never concatenated sentences, which cannot be translated into
Arabic grammatically.

**Export.** Every table → CSV. Executive Home → PDF. Every evidence drawer → CSV. If we don't provide
export, users will screenshot, and screenshots lose the confidence badges.

---

# 12. Design → Roadmap Traceability

| UX surface | Roadmap IDs | Readiness | Roadmap phase |
|---|---|---|---|
| Executive · Fleet Health | V1 | READY | 5 → 2 |
| Executive · Spend | E1, E9 | READY\* | 7 → 2 |
| Executive · Availability | E7 | PARTIAL 25.7% | 2 |
| Executive · At Risk | E11, V12 | PARTIAL 54% | 5 |
| Executive · Cost by Model | E2 | **BLOCKED on F1** | 2 |
| Executive · Worst Garages | E5, E6, G2 | READY | 4 |
| Executive · Cost of Rework | G17 | READY\* | 4 |
| Executive · Recurring Failures | E10, F-1, F-16 | READY | 6 |
| Executive · Lifetime Curve | C13 | READY\* | 7 |
| Executive · Warranty Leakage | C15 | READY\* 87% | 7 |
| Operations · all tiles | O1–O15 | mixed (O4 killed) | 3 |
| Garage · Leaderboard | G1, G2, G8 | READY | 4 |
| Garage · Matrix | G16 | READY | 4 |
| Garage · Comparison | G15 | READY | 4 |
| Garage · Repeat evidence | F4 | READY | 1 → 4 |
| Vehicle · Health | V1 | READY | 5 |
| Vehicle · Timeline | V4 | READY | 5 |
| Vehicle · Cost | V7, V8, V9 | PARTIAL | 7 |
| Vehicle · Components | V13 | **NOT VIABLE — empty state by design** | — |
| Fault · Ranking/Trends | F-1, F-3, F-4 | READY | 6 |
| Fault · Concentration | F-16 | READY | 6 |
| Fault · Related | F-9 | READY\* | 6 |
| Financial · all | C1–C15, CB | mixed, watermarked | 7 |
| Insights · T1/T3 | T1, T3 | READY | 8 (T3 in 1) |
| Insights · predictions | T4 | **NOT VIABLE — excluded** | — |

---

# 13. Design Position

**Three decisions define this product, and each is a refusal.**

**We refuse to show Basem the operational product.** His sidebar has three items. An executive
dashboard that requires navigating 68 pages is not an executive dashboard.

**We refuse to hide uncertainty.** The confidence badge, the coverage donut placed *above* the
ranking it qualifies, the "3 of 5 measures" line, the empty Components tab where 3,475 seeded rows
would have rendered — these are not apologies for weak data. They are the reason a number on this
platform can be trusted when someone challenges it. Every competitor product in this category shows a
confident number and hides the caveat. The moment Basem finds one number he cannot defend, he stops
opening the platform.

**We refuse to predict.** No probability, no confidence score, no "likely to fail." Our own backtest
returned worse-than-baseline skill. What we ship instead — observed rates with sample sizes — drives
exactly the same decisions and survives being questioned.

**The single screen that justifies the project** is the Garage × Fault matrix, backed by the paired
recurrence-evidence table. It converts three years of unexamined tickets into "send suspension work
here, not there," with both tickets and their dates one click away. Build that first, show it to
Basem, and the rest of this specification funds itself.
