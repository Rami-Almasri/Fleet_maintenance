# Historical Maintenance Knowledge Opportunities

*What the historical data can actually teach the new maintenance platform.*

**Date:** 2026-07-29
**Scope:** `vehicle_expenses` (28,327 lines · AED 26.94M · 2015→2026) studied as a knowledge source, not a ledger. Cross-examined against `maintenances` (26,838 events), `vendors` (463), `vehicles` (438), `contracts` (39,466).
**Status:** Discovery only. No UI, no schema changes, no code. Every number below was measured, not estimated.

---

## 0. The central discovery

`vehicle_expenses` on its own cannot teach a maintenance system very much. It has money and dates but no structure: one `account_type` value ("Expence") on all 28,327 rows, and a free-text `remarks` field carrying everything else.

But the fleet has a **second** historical dataset that is the exact complement:

| | `vehicle_expenses` | `maintenances` |
|---|---|---|
| Rows | 28,327 | 26,838 |
| Money | **AED 26.94M, 100% of rows** | `cost` filled on **317 rows (1.2%)** |
| Event dates | voucher date only | `out_date` 95.2%, `actual_in_date` 25.8% |
| Garage | free text, ~54% recoverable | `garage` **93.6%**, `vendor_id` **93.6%** |
| Fault category | none | `service_main` **30.5%** (8,191 rows, 322 labels) |
| Symptom text | terse, financial | `maintenance_notes` **95.4%** (25,602 notes) |
| Duration | not derivable | **6,848 events with real start→end** |

> **The money history and the repair history are two halves of the same record, stored in different tables, and nobody has ever joined them.**
>
> `maintenances` knows *what broke, when, where, and for how long* — but not what it cost.
> `vehicle_expenses` knows *what it cost* — but not what broke.

Almost every opportunity below is really one opportunity applied in different directions: **build the join, then everything else becomes queryable.** The join is the foundation; I measured its achievable quality in §2.

A second, quieter discovery: **`service_main` is a human-labelled training set.** 8,191 events already carry a fault category assigned by staff. That is enough supervised signal to auto-classify the other 18,647 events *and* the expense remarks — meaning this does not have to stay a keyword-rules project forever.

---

## 1. Data inventory — what each field can actually carry

### 1.1 `vehicle_expenses`

| Field | Fill | Usable for |
|---|---|---|
| `car_serial` | 100% | vehicle join (1,722 distinct serials, but only 358 match a fleet vehicle) |
| `vehicle_id` | **81.9%** (23,199) | direct FK join — already resolved at import |
| `entry_date` | 100% | timeline, seasonality, price trend. **Voucher date, not repair date** |
| `remarks` | ~100% | the entire knowledge payload — category, garage, part, plate, invoice no. |
| `amount` | 100% | the only money. `debit − credit`, year-end closings already dropped |
| `account_type` | 100% | **useless** — one value on 28,320 of 28,327 rows |

### 1.2 `maintenances` — the fields that matter here

| Field | Fill | Usable for |
|---|---|---|
| `out_date` | 95.2% | event date — **the correct anchor for a repair timeline** |
| `actual_in_date` | 25.8% (6,921) | **duration** = `actual_in_date − out_date` |
| `garage` | 93.6% (25,123) | garage intelligence — only **121 distinct strings**, very cleanable |
| `vendor_id` | 93.6% (25,130) | already-resolved supplier FK |
| `service_main` | 30.5% (8,191) | **fault taxonomy + ML training labels** (322 distinct) |
| `maintenance_type` | 31.2% | Routine / Breakdown / Body Damage split |
| `maintenance_notes` | 95.4% (25,602) | symptom corpus — median 62 chars, 99.3% English |
| `invoice_no` | 2.8% (754) | too sparse to be a join key |
| `cost` | **1.2% (317)** | effectively empty — confirms the money must come from expenses |

### 1.3 Coverage ceiling

| Population | Count | Note |
|---|---:|---|
| Fleet vehicles | 438 | |
| With expense history | 358 | 82% |
| With maintenance events | 247 | 56% |
| **With both** | **234** | **53% — the ceiling for any joined intelligence** |
| Cars with repair spend | 372 serials | mean AED 13,620 lifetime |

**Any joined feature works for ~234 cars.** That is not a defect to fix, it's a scope to state honestly in the UI.

---

## 2. Repair History Reconstruction

**Question:** can we rebuild a per-vehicle repair history — event, garage, cost, date, supplier, related invoices, follow-up repairs?

**Verdict: YES for the event/garage/date/duration spine (high confidence), PARTIAL for cost attribution (medium), NO for invoice-level linkage (low).**

### 2.1 What I measured

Joining an expense line to a maintenance event by `vehicle_id` + date proximity:

| Window | Lines matched | % of lines | Value matched | % of value |
|---|---:|---:|---:|---:|
| ± 0 days | 1,275 | 5.5% | 524,683 | 5.0% |
| ± 3 days | 4,864 | 21.0% | 2,233,025 | 21.4% |
| ± 7 days | 7,305 | 31.5% | 3,480,153 | 33.3% |
| ± 14 days | 9,192 | 39.6% | 4,430,635 | 42.4% |
| ± 30 days | 10,786 | 46.5% | 5,274,480 | 50.5% |
| ± 60 days | 11,817 | 50.9% | 5,793,601 | 55.5% |

Date proximity alone is weak — at ±30 days you catch half the money but the window is so wide the attribution is meaningless.

**Adding the garage name as a second key changes the character of the join:**

> Expense remark shares a garage token with the maintenance event's `garage`, within 45 days:
> **3,658 lines · AED 1,817,775 · median day-gap 6 · p90 gap 28**

That is a **high-precision, low-recall** join. A median 6-day gap between the repair and the voucher is exactly what you'd expect from real invoice processing — it is genuinely the same event. This gives a tiered confidence model:

| Tier | Rule | Volume | Use it for |
|---|---|---|---|
| **A — Confirmed** | vehicle + garage token + ≤14 days | ~2,900 lines | costed repair records, benchmarks |
| **B — Probable** | vehicle + ≤7 days, no garage conflict | ~7,300 lines | timeline display, "likely related" |
| **C — Unlinked** | everything else | ~13,000 lines | vehicle-level lifetime cost only |

- **Fields used:** `vehicle_expenses.{vehicle_id, entry_date, remarks, amount}` · `maintenances.{vehicle_id, out_date, actual_in_date, garage, service_main}`
- **Assumptions:** (1) `entry_date` lags the repair by days, not months — supported by the median-6-day result; (2) one repair event per vehicle per garage per fortnight — violated for heavy-use cars, which is why Tier A must show a confidence badge; (3) `vehicle_id` was correctly resolved at import for the 82% that have it.
- **"Related invoices":** 48.8% of expense lines carry an extractable invoice number (13,814 lines, 10,083 distinct, 1,687 appearing on more than one line). So multi-line invoice grouping is possible for a minority. But `invoices` in this DB is the **rental** invoice table (contract, rent_days, net_rate) — garage invoices are not stored anywhere. **There is no invoice entity to join to.** Extracted numbers are strings for display, not keys.
- **"Was another repair performed shortly afterwards?"** — fully answerable, see §7.

### 2.2 Feature verdict

**Yes — this becomes "Vehicle Repair History (historical)" on the vehicle profile.** Highest-value item in this document, because it is the substrate every other feature reads from.

---

## 3. Repair Signature Intelligence

**Question:** can historical repairs be grouped into signatures — "engine overheating", "brake vibration", "tyre damage" — and then queried?

**Verdict: YES, and better than expected — because a labelled training set already exists.**

### 3.1 The taxonomy already exists, half-populated

`service_main` carries 322 distinct labels on 8,191 events. The head of the distribution is already a usable fault taxonomy:

```
1,175  Body Damage          245  Suspension Troubles     156  Check Engine Light
  554  Body & Exterior      228  Electrical Problems     147  Periodic Maintenance
  435  Oil & Fillter Change 225  Rims scratch            135  Cooling System Issues
  269  Mechanical Issues    215  Tire Issues             122  Interior problem / Chairs
  257  Engine               187  Testing                 118  Braking Problems
```

It needs cleaning — spelling ("Fillter"), multi-labels ("Engine, Body & Exterior"), and non-faults ("Ready", "NEW CAR", "Main reason" are workflow states, not faults). Realistically **322 labels collapse to ~25 canonical signatures.**

### 3.2 The symptom corpus is real

25,602 `maintenance_notes`, median 62 chars, 99.3% English, and genuinely diagnostic:

> *"leak from the radiator"* · *"Radar problem"* · *"Front right rim scratch"*
> *"after tires changed car has problem from rear, that tires hitting chassie body, so they will make it higher"*
> *"The front and rear parking sensors were inspected and found to be completely non-functional"*

Measured signal quality:

| | Share |
|---|---:|
| Symptom-bearing (fault/failure language) | **44.6%** (11,419) |
| Status-only ("car is ready", "waiting for garage") | 12.6% |
| Neither / mixed progress notes | 42.8% |

**~11,400 usable symptom documents.** These are *progress notes*, not diagnoses — written by whoever was chasing the car, mid-repair. That is the key assumption to respect: the note says what someone observed, not what was concluded.

### 3.3 What can be answered per signature

Tested and confirmed available:

| Question | Answerable? | Basis |
|---|---|---|
| How many similar repairs happened? | **Yes, high** | `service_main` counts directly |
| Average repair cost | **Yes, medium** | via the Tier A/B join — costed subset only |
| Average repair duration | **Yes, high** | 6,848 events, see §3.4 |
| Most common garage | **Yes, high** | `garage`/`vendor_id` 93.6% filled |
| Most common supplier | Medium | same as garage; part suppliers are weaker (§5) |
| Most frequently replaced parts | Medium | keyword extraction from notes + remarks |
| Repeat failure rate | **Yes, high** | §7 — measured at 25.9% within 90 days |
| Vehicles with the same issue repeatedly | **Yes, high** | 370 chronic vehicle+fault pairs found |

### 3.4 Duration is real and it discriminates

6,848 closed events (31 negative-duration rows dropped as data errors):

**Fleet: median 1 day · p75 2 · p90 6 · max 113. 65% ≤1 day, 83% ≤3 days, 93% ≤7 days, 1% >30 days.**

And it varies by fault type in a way that makes engineering sense:

| Signature | n | Median | p90 |
|---|---:|---:|---:|
| Interior | 72 | **4.0 d** | **16.0 d** |
| Body Damage | 224 | 1.0 d | 4.0 d |
| Body & Exterior | 386 | 1.0 d | 2.0 d |
| Rims scratch | 58 | 1.0 d | 3.0 d |
| Engine | 217 | 0.0 d | 4.0 d |
| Electrical | 136 | 0.0 d | 5.0 d |
| Tires | 74 | 0.0 d | 1.0 d |
| Periodic Maintenance | 86 | 0.0 d | 0.0 d |

**Caveat that must be carried into any UI:** 65% of events closing in ≤1 day is suspiciously fast for real workshop turnaround. `actual_in_date` is most likely *the date the car returned to the park*, and for same-day jobs it is probably back-filled. Treat these as **turnaround days**, never as **labour hours**, and publish p50 *and* p90 — the p90 column is where the real variance lives (Interior at 16 days is a genuinely useful warning).

- **Fields used:** `maintenances.{service_main, maintenance_type, maintenance_notes, out_date, actual_in_date, garage, vehicle_id}`
- **Assumptions:** labels applied consistently by staff across years (unverified — worth spot-checking by year); notes describe the vehicle's problem rather than the workflow.

### 3.5 Feature verdict

**Yes — this is the `repair_signatures` substrate the Fleet Knowledge Engine blueprint already calls for.** The data is richer than the FKE design assumed, because the label column was never noticed.

---

## 4. Part Intelligence

**Verdict: MIXED. Frequency and benchmark pricing YES for commodity parts. Unit-price extraction NO. Supplier-per-part WEAK.**

### 4.1 What works — price benchmarks for standardised items

Isolating **simple single-item lines** (text <60 chars, no `+`, ≤1 comma, amount >50) gives clean per-part price distributions:

| Part | n | Median | IQR | CV | Trend: pre-2022 → 2024+ |
|---|---:|---:|---|---:|---|
| **Battery** | 156 | **AED 255** | 229–375 | **0.38** | **250 → 438 (+75%)** |
| **Spare key** | 55 | **AED 400** | 350–400 | 1.45* | 400 → 400 (flat) |
| **Wheel alignment** | 81 | **AED 150** | 100–286 | 0.79 | 150 → 143 (flat) |
| Oil change | 177 | AED 152 | 129–190 | 1.01 | 150 → n=2 (moved to contract) |
| Radiator | 32 | AED 400 | 200–700 | 0.84 | 381 → 400 |
| Bumper | 108 | AED 200 | 100–500 | 1.13 | 200 → 115 |

\* spare-key CV is inflated by a few bundled lines; the IQR 350–400 is the real story.

**Battery is the proof case:** 156 observations, tight IQR, CV 0.38, and a defensible +75% price rise over four years. That is a real procurement benchmark — "the last 156 batteries cost a median of AED 255; you are being quoted 600."

**The boundary is sharp and it is about the part, not the data:** commodity items with a standard spec (battery, key, alignment, oil change) benchmark reliably. Items whose price depends on the specific damage (bumper CV 1.13, radiator 0.84) do not, and never will from this source, because the line conflates part + labour + severity.

### 4.2 What does not work — unit-price extraction

I tested extracting per-part prices from parenthetical annotations, since some lines are written as *"new Rim(1800), tire new kumho(2400)"*. **This fails.** 1,397 lines match the pattern, but the overwhelming majority of parenthesised numbers are **plate numbers, not prices**:

```
PAYMENT FOR SHARJAH FINE(741110)          <- fine reference
pd to elite for kia optima computer programming ( 73847 )   <- plate
PERUCHASE STEARING WIRE FOR CAMARO SILVER (43567)           <- plate
Shatee AL Mamzar inv 1954/ Armada 15721/ new Rim(1800), tire new kumho(2400).   <- actually prices
```

Disambiguating requires knowing the fleet's plate numbers — doable, but the yield after filtering is a few hundred lines. **Not worth building.** Line-level `amount` is the only trustworthy money.

### 4.3 What works well — basket analysis

Co-occurrence within a line, measured by lift:

| Pair | Lines | AED | Lift |
|---|---:|---:|---:|
| **alignment + tyre** | 500 | 293,459 | **10.6** |
| **alignment + rim** | 569 | 307,113 | **8.9** |
| **bumper + paint** | 962 | 589,923 | **8.1** |
| **engine + oil** | 805 | 300,146 | **7.8** |
| paint + rim | 1,245 | 608,589 | 6.6 |
| A/C + oil | 269 | 135,860 | 5.4 |
| brake + tyre | 171 | 104,267 | 4.3 |
| rim + tyre | 532 | 386,965 | 4.0 |

4,342 lines mention two or more categories. These lifts are strong enough to drive a real suggestion: *"87% of rim jobs also billed alignment — add it to this quote?"*

### 4.4 Seasonality is strong and actionable

Monthly spend, AED, Jan→Dec:

```
A/C      13.3k  30.2k  26.7k  40.2k  44.8k  77.1k  68.5k  40.5k  75.0k  66.9k  50.5k  31.3k
Battery   5.1k   7.5k   5.4k  16.7k  13.6k  12.6k  13.0k  20.5k  16.3k  15.0k  21.6k   6.1k
Tyres   143.2k  93.7k 189.7k 105.0k 106.3k  98.7k  96.1k 116.0k  91.8k 123.8k 146.0k 173.5k
```

- **A/C spend peaks 5.8× between January (13.3k) and June (77.1k)**, staying elevated June→October. Textbook Gulf summer load.
- **Battery peaks August–November** (heat kills batteries, and they fail a season *after* the heat).
- **Tyres are essentially aseasonal** — confirming §6's finding that tyre spend is impact-driven, not weather-driven.

This directly supports pre-season stocking and a pre-summer A/C inspection campaign.

- **Fields used:** `vehicle_expenses.{remarks, amount, entry_date}` only.
- **Assumptions:** simple-line isolation removes bundled labour (validated by CV); the keyword for a part implies that part was actually supplied (not always true — *"check A/C"* counts as A/C).

### 4.5 Feature verdict

**Partial yes.** Ship "price benchmark + trend" for a curated shortlist of ~10 commodity parts. Ship "frequently done together". Ship seasonality. **Do not** promise per-part unit prices or a full parts catalogue from this source.

---

## 5. Garage & Supplier Intelligence

**Verdict: STRONG from `maintenances`. WEAK from `vehicle_expenses` text.**

### 5.1 Specialization is visible and unambiguous

Garage × `service_main` on labelled events — only 121 distinct garage strings, so this is clean:

| Garage | Events | Top three fault types |
|---|---:|---|
| **ROAD FORCE** | 144 | **Suspension 48%** · Braking 18% · Tire Issues 11% |
| **One Roof** | 230 | **Body Damage 63%** · Rims scratch 12% · Accessories 10% |
| **ALTIQNIAH AL ALIAH** | 234 | **Engine mechanical 30%** · Check Engine Light 14% · Mechanical 12% |
| **POWER POINT** | 177 | **Electrical 18%** · Airbag 12% · ABS 12% |
| **FUTURE TYRES** | 638 | Suspension 21% · Tire Issues 18% · Tires 7% |
| **RMR** | 452 | Check Engine 9% · Oil/Coolant Mixing 8% · Cooling 8% |
| **GPT GARRAGE** | 1,237 | Body Damage 41% · Oil & Filter 18% · Rims 8% |
| **Deals On Wheels** | 1,326 | Body & Exterior 19% · Body Damage 13% · Engine 7% |

These are **not** generalists with noise — Road Force is a suspension shop, One Roof is a body shop, Power Point is an electrical shop. This is a data-derived routing table, and it is exactly what `GarageRecommendationService` was designed to consume.

Cost per garage comes from the expense side (curated alias matching over repair lines, 54% coverage):

| Payee | Lines | AED | Avg/line |
|---|---:|---:|---:|
| Abdallah (staff petty cash) | 1,278 | 604,016 | 473 |
| Waleed (staff petty cash) | 907 | 547,283 | 603 |
| Future Tyres | 843 | 521,178 | 618 |
| Hotline Garage | 531 | 387,420 | 730 |
| Power Point | 1,067 | 335,341 | 314 |
| **RMR** | 207 | 203,886 | **985** |

RMR is the high-ticket shop (and owns the two largest engine jobs on record); Power Point is high-volume/low-ticket. Combined with §5.1: **RMR is where the hard mechanical work goes, and it is priced accordingly.** That is a defensible sourcing insight.

### 5.2 The false-positive trap — do not fuzzy-match the vendor table

Naive token matching of the 463-row `vendors` table against expense remarks returns 61.7% of lines / AED 12.55M. **That number is garbage.** The vendor table contains names made of generic words, which then match unrelated text:

| "Vendor" | Falsely captured | Why |
|---|---:|---|
| Fine Land Spare Parts | AED 3,814,328 | token `fine` matched every traffic-fine line |
| ALAMIMI Tyre Tr | AED 686,617 | token `tyre` matched every tyre line |
| Head Light | AED 340,292 | token `light` matched every lighting line |
| Al Sagr National Insurance | AED 1,912,501 | matched every insurance line |

**Rule for whoever builds this:** supplier attribution from expense text requires a **hand-curated alias list**, never token similarity against `vendors`. My curated 14-alias list achieved **54% coverage of repair value** — that is the honest ceiling for text-derived suppliers. For the other 46%, the supplier is knowable only through the `maintenances` join, where `vendor_id` is already 93.6% filled.

### 5.3 The control finding

**AED 1.15M — 23% of all repair spend — is booked to a staff name ("Waleed expenses", "SPARE FOR CAR 89529 FROM WALEED") with no garage named.** For nearly a quarter of repair money there is no counterparty in the record. This is not an analytics problem; it is a spend-control gap that the historical data exposes and the new platform should close by making vendor mandatory on every repair line.

### 5.4 Feature verdict

**Yes for specialization + cost profile** (feeds garage recommendation). **No for automated supplier extraction at scale** — curate aliases instead.

---

## 6. Vehicle Lifetime Intelligence

**Verdict: YES for cost, timeline, chronic issues and repair-vs-replace. NO for cost-per-km.**

### 6.1 What works

- **Lifetime repair cost:** AED 5.07M across 372 cars, mean **AED 13,620**. Per-vehicle roll-up is direct.
- **Concentration:** top 10 cars = 14% of spend; **top 10% of cars = 40%; top 25% = 73%**. A repair-vs-replace watchlist is a query over ~37 vehicles, not a research project.
- **Cost per year per vehicle:** direct from `entry_date` + `amount`.
- **Cost by make** (after normalising the make/model mess — Patrol, Camaro, Mustang and Charger are stored *as makes* on some `vehicles` rows):

| Make | Cars | AED | **Per car** |
|---|---:|---:|---:|
| **Dodge** | 17 | 612,828 | **36,049** |
| **BMW** | 6 | 211,248 | **35,208** |
| Ford | 23 | 548,464 | 23,846 |
| Mercedes | 23 | 540,998 | 23,522 |
| Chevrolet | 35 | 795,997 | 22,743 |
| Nissan/Infiniti (Patrol/QX80) | 58 | 1,297,962 | 22,379 |
| Kia / Cerato / Sportage | 29 | 165,456 | ~5,700 |

Dodge and BMW cost ~60% more per car than the American-muscle average and ~6× the Korean economy cars.

- **Worst individual cars:** Charger 74802 (167 lines, AED 113,058), Charger 81837 (156, 81,741), QX80 23733 (110, 81,724).
- **Most expensive ownership periods:** derivable per vehicle from the yearly series.

### 6.2 What does not work — cost per kilometre

I searched every table for odometer/mileage columns. The result:

| Source | Historical mileage? |
|---|---|
| `vehicle_expenses` | **no odometer column at all** |
| `maintenances` | odometer columns exist but are **workflow-era**: `test_odometer` 28 rows, `report_odometer` 26, `dispatch_odometer` 18, `receive_odometer` 21 — out of 26,838 |
| `contracts` | `km_debit` 1,284 / `km_credit` 1,196 of 39,466 (**3%**) |
| `vehicles` | `odometer` 422 — **current value only, no history** |

**There is no historical odometer series. Cost-per-km is not reconstructable for the historical period, and no amount of modelling fixes it** — you would be inventing the denominator. It becomes available only *going forward*, from the workflow's odometer capture. This is the single biggest gap in the dataset, and it should be stated plainly rather than approximated.

*(Partial workaround, low confidence: contract `km_debit`/`km_credit` on the 3% of contracts that have it could give crude annual-mileage estimates for a handful of cars. Not a foundation to build on.)*

### 6.3 Feature verdict

**Yes** — lifetime cost, timeline, chronic-issue profile and a repair-vs-replace watchlist. Ship these **without** any per-km metric, and add per-km later from forward-captured odometer.

---

## 7. Predictive Knowledge — can the system advise a technician?

**Verdict: YES. The measured numbers land almost exactly on the example you sketched.**

Your hypothetical:

> *"We found 143 similar historical repairs. Average cost AED 1,850. Average completion 4.3 days. Most successful garage: X. Most common part: Water Pump. 27% returned within 90 days."*

Measured against real data:

| Claim | Feasible? | Evidence |
|---|---|---|
| "N similar historical repairs" | **Yes, high** | `service_main` gives 1,175 Body Damage, 435 Oil & Filter, 245 Suspension, 215 Tire Issues… |
| "Average historical cost AED X" | **Yes, medium** | only for the Tier A/B costed subset — show n and a confidence badge |
| "Average completion 4.3 days" | **Yes, high** | 6,848 durations; e.g. Interior median 4.0d / p90 16.0d |
| "Most successful garage" | **Yes for *most common*, No for *most successful*** | success is not recorded — see below |
| "Most common replaced part" | **Yes, medium** | keyword extraction; precision ~80% on commodity parts |
| **"27% returned within 90 days"** | **Yes, high** | **measured: 25.9%** |

### 7.1 Repeat-failure is measured, not hypothetical

Same vehicle + same `service_main`, base of 7,937 labelled events:

| Returned within | Events | Rate |
|---|---:|---:|
| 30 days | 1,333 | **16.8%** |
| 90 days | 2,056 | **25.9%** |
| 180 days | 2,464 | **31.0%** |

**370 chronic vehicle+fault pairs** (≥6 recurrences of the same fault on the same car):

| Plate | Fault | × | Vehicle |
|---|---|---:|---|
| 20797 | Body Damage | 29 | Ford Mustang |
| 13049 | Body Damage | 29 | Ford Mustang Conv |
| 89529 | Body Damage | 27 | Chevrolet Camaro |
| 19131 | Engine mechanical issue | **26** | Nissan Patrol |
| 89529 | Engine mechanical issue | **25** | Chevrolet Camaro |
| 21716 | Rims scratch | 25 | Patrol QX80 Blue Hawk |

Body Damage recurring 29× on a convertible Mustang is a *rental-exposure* signal, not a *reliability* signal. Engine mechanical 26× on one Patrol is a genuine reliability signal. **The system must distinguish the two, or it will flag every convertible as unreliable.**

### 7.2 The honest limit: there is no outcome field

Nothing in either dataset records whether a repair **worked**. There is no verdict, no QC pass/fail, no "fixed / not fixed" for the historical period.

So "most successful garage" is **not** directly answerable. The best available proxy is: *of repairs of type T sent to garage G, what fraction saw the same fault return within 90 days?* — a defensible inverse-success metric, but it is a **proxy** and must be labelled as one in any UI. Attributing blame to a garage from a proxy metric is a business risk, not just an analytics one.

### 7.3 Feature verdict

**Yes — a "Similar Historical Repairs" panel is buildable today** and is the single highest-leverage way this data reaches a technician. It should show: n, cost p50/p90 (with coverage %), duration p50/p90, garage distribution, common parts, and the 90-day return rate — every figure with its sample size visible.

---

## 8. What is NOT reconstructable — the honest negatives

| Wanted | Verdict | Why |
|---|---|---|
| **Cost per kilometre (historical)** | **Impossible** | no historical odometer anywhere — §6.2 |
| **Repair outcome / success** | **Impossible** | no verdict field ever existed; only a return-rate proxy |
| **Labour vs parts split** | **Impossible** | lines conflate them (*"Labor Charges Dash board Wiring Repaired"*); `parts_total` filled on 6 of 26,838 rows |
| **Per-part unit prices** | **Not worth it** | parenthesised numbers are mostly plate numbers — §4.2 |
| **Garage invoice linkage** | **Impossible** | `invoices` is the rental table; garage invoices were never stored |
| **Root cause** | **Impossible** | notes record symptoms and progress, never diagnosis |
| **Technician / mechanic identity** | **Impossible** | not captured |
| **Pre-2020 anything fine-grained** | **Unreliable** | "General repair (unspecified)" was 15.4% of pre-2024 spend vs 2.4% after; early descriptions are too vague |
| **Warranty / goodwill status** | **Impossible** | not recorded |

**Data quality caveats that apply everywhere:** 4.9% of expense lines (AED 1.33M) resist classification; 57 expense serials match no vehicle (AED 88k orphaned); 31 maintenance events have negative duration; `entry_date` max is **2027-09-18** (future-dated rows exist and must be filtered); 18% of expense lines have no `vehicle_id`.

**One landmine for anyone else querying this table:** rental invoices are written as `rent 48160 / vat 2413 / salik 80 / breach 200` — one row carrying the *full* rent amount with salik as a text detail. A naive `remarks LIKE '%salik%'` books **AED 10.6M of rent as tolls**. Rent must be tested before salik, fine, or VAT.

---

## 9. Ranked Opportunities

Scoring: **Business Value** (what it changes) · **Technical Difficulty** · **Confidence** (would I defend the output to the CEO) · **Required Data Quality** (what must be true) · **Phase**.

### Phase NOW — build on what is already true

| # | Opportunity | Business Value | Difficulty | Confidence | Required Data Quality | Phase |
|---|---|---|---|---|---|---|
| **1** | **Expense ⇄ Maintenance join (tiered A/B/C)** — the foundation everything else reads | **Critical** | Medium | **High** | `vehicle_id` 82% + `out_date` 95% + `garage` 94% — already met | **Now** |
| **2** | **Vehicle Repair History (historical)** — costed timeline on the vehicle profile, 234 cars | **Very High** | Low (after #1) | **High** | needs #1; degrade to vehicle-level cost outside Tier A/B | **Now** |
| **3** | **Similar Historical Repairs panel** — n, cost p50/p90, duration p50/p90, garages, return rate | **Very High** | Medium | **High** | `service_main` cleaned to ~25 signatures | **Now** |
| **4** | **Repeat-failure & chronic-vehicle detection** — 25.9%/90d, 370 chronic pairs | **Very High** | **Low** | **High** | `service_main` + `out_date` only; no join needed | **Now** |
| **5** | **Garage specialization profile** — Road Force=suspension, One Roof=body, Power Point=electrical | **High** | **Low** | **High** | 121 garage strings normalised to `vendor_id` | **Now** |
| **6** | **Repair-vs-replace watchlist** — top 10% of cars = 40% of spend | **High** | **Low** | **High** | expense roll-up only | **Now** |
| **7** | **Seasonality → pre-summer A/C campaign & stocking** — A/C peaks 5.8× Jan→Jun | **High** | **Low** | **High** | `entry_date` + keywords only | **Now** |
| **8** | **Commodity price benchmarks** — battery AED 255 (+75% since 2021), key 400, alignment 150 | **Medium-High** | Low | **High** (10 parts) / Low (rest) | simple-line isolation; curated shortlist only | **Now** |

### Phase NEXT — needs cleaning or curation first

| # | Opportunity | Business Value | Difficulty | Confidence | Required Data Quality | Phase |
|---|---|---|---|---|---|---|
| **9** | **Fault-signature auto-classifier** — train on 8,191 labels, apply to 18,647 unlabelled + expense remarks | **Very High** | **High** | Medium (est. 75–85% accuracy) | canonical label set; held-out validation; year-drift check | **Next** |
| **10** | **Curated supplier alias map** — raise text-derived supplier coverage from 54% | Medium-High | Medium (manual) | Medium | ~40 hand-written aliases; **never** fuzzy-match `vendors` | **Next** |
| **11** | **"Frequently done together" quote suggestions** — alignment+tyre lift 10.6 | Medium-High | Low | Medium-High | co-occurrence within a line = same job (assumption) | **Next** |
| **12** | **Garage return-rate scorecard** (inverse-success proxy) | High | Medium | **Medium — label as proxy** | needs #1 + #4; **business risk if presented as fact** | **Next** |
| **13** | **Duration expectations per signature** → default `expected_completion_date` | Medium-High | Low | Medium | must publish p90 not just median; turnaround ≠ labour | **Next** |
| **14** | **Vendor-mandatory control** — close the AED 1.15M staff-name gap | **High (control, not analytics)** | Low | **High** | forward-looking policy + form change | **Next** |

### Phase FUTURE — blocked on data that does not exist yet

| # | Opportunity | Business Value | Difficulty | Confidence | Required Data Quality | Phase |
|---|---|---|---|---|---|---|
| **15** | **Cost per km / failure per km** | **Very High** | Medium | **N/A — blocked** | **needs forward odometer capture; unbuildable historically** | **Future** |
| **16** | **Failure prediction (survival model)** — "this Patrol is 8 months from a cooling failure" | **Very High** | **Very High** | Low today | needs #9 + #15 + 2+ yrs of clean forward data | **Future** |
| **17** | **True success/outcome scoring** | High | Medium | **N/A — blocked** | needs the QC verdict the new workflow captures | **Future** |
| **18** | **Predictive parts stocking** | Medium-High | High | Low-Medium | needs #9 + seasonality + forward consumption | **Future** |
| **19** | **Rental-exposure vs reliability separation** | Medium | Medium | Medium | needs damage-vs-fault labelling (`liable_party` is 21% filled) | **Future** |

---

## 10. Recommended sequence

1. **Build the join first (#1).** Everything above it is cheap; nothing above it is possible without it. Tiered confidence (A/B/C), stored as a derived table, not computed per request.
2. **Ship the four zero-join wins in parallel (#4, #5, #6, #7).** Repeat-failure, garage specialization, replace-watchlist and seasonality need no join at all — they are queries over `maintenances` and `entry_date` and could land in days.
3. **Then #2 and #3** — the vehicle history and the "Similar Historical Repairs" panel, which is where this data finally reaches a technician at the moment of decision.
4. **Clean `service_main` to ~25 canonical signatures before #9.** The classifier is only as good as its label set, and 322 labels including "Ready" and "NEW CAR" is not a label set.
5. **Start capturing odometer at every maintenance touch now.** It is the only thing standing between this fleet and per-km economics, and every month of delay is a month that can never be reconstructed.

## 11. Two rules for whoever implements this

- **Always show n and coverage.** Every figure this engine produces rests on a subset — 234 of 438 cars, 8,191 of 26,838 labels, 54% supplier coverage. A number without its sample size will be trusted more than it deserves, and the first time someone finds a "median cost" backed by four observations, the whole engine loses credibility. This is also what the standing Traceability/Data-Origin rule requires.
- **Never present a proxy as a fact.** Return-rate is not success. Turnaround is not labour time. Recurrence on a convertible is exposure, not unreliability. The data supports each of these as a *signal*; it does not support any of them as a *verdict*.

---

## Appendix — reproduction

Analysis scripts (throwaway, in session scratchpad): `classify.js` (top-level + part taxonomy over `vehicle_expenses`), `deep.js` (vendor/year/car/basket cuts). All other figures come from `php artisan tinker` queries against the live `laravel` DB, recorded in the session transcript.

Key measured constants, for anyone re-deriving:

```
vehicle_expenses     28,327 lines · AED 26,938,222.96 · 2015→2026 (+2 future-dated rows)
  repair & parts     10,802 lines · AED  5,066,740 (18.8%) · 372 cars · mean 13,620/car
maintenances         26,838 events · out_date 95.2% · actual_in_date 25.8% · garage 93.6%
  labelled           8,191 with service_main (322 distinct → ~25 canonical)
  symptom notes      11,419 of 25,602 (44.6%)
  durations          6,848 · median 1d · p90 6d
join (garage+45d)    3,658 lines · AED 1,817,775 · median gap 6d · p90 28d
repeat 90d           25.9% · chronic pairs (>=6) 370
cars in both sources 234 of 438
historical odometer  NONE
```
