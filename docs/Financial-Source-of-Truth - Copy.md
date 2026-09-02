# FleetView — Financial Source of Truth

> ⚠️ **PARTIALLY SUPERSEDED 2026-07-22.** The **cost/expense** side described here (maintenance-table
> spend, operating cost as the vehicle's cost) is replaced: expense now comes solely from the pluggable
> `VehicleExpenseProvider` (Excel today, Odoo later) — see **`docs/Vehicle-Expense-Provider.md`**. The
> **revenue** definitions below (rental income from contracts) remain accurate and in force.

**Status:** ✅ **VERSION 1.0 — FROZEN** (validated 2026-07-22) · Architectural contract · Read-only audit (no code changed to produce it)
**Date:** 2026-07-22
**Basis:** Live production DB (`laravel`, MySQL) — figures below are a snapshot taken during this audit.
**Scope:** The financial KPIs surfaced on `/profitability` and `/cost-intelligence`, and the Explainability Platform built on top of them.

> This document is the agreed definition of every financial number FleetView shows. No metric may be
> added, changed, or explained in a way that contradicts this contract without updating it first.
> **Every future Explainability, Profitability, Cost-Intelligence, Forecasting, and Analytics feature
> MUST reference this contract rather than redefine financial logic independently.**

### Provenance legend (every statement in this contract is tagged)

- **[DATA]** — Verified by live production data (a read-only query was run this audit; see §8 / Appendix).
- **[CODE]** — Verified by existing code (a specific service/model/config, cited with `file:line`).
- **[RULE]** — A documented business rule (config value, docblock-stated policy).
- **[ASSUMPTION]** — An interpretation NOT yet confirmed by data, code, or the business. **Flagged, not hidden.**
- **[REC]** — A recommendation / proposed direction, not a statement of current fact.

§8 gives the section-by-section classification. The load-bearing rule: **business *definitions* and
*purposes* are [ASSUMPTION]s** (proposed interpretations of intent, not business-signed-off); the
*formulas, sources, and magnitudes* behind them are [DATA]/[CODE].

---

## 1. The financial subsystems (there is no single "money truth")

FleetView contains **four** distinct financial data sets, answering **four different questions**. They are
NOT interchangeable, and they legitimately produce different totals.

| # | Subsystem | Question it answers | Authoritative store | Service |
|---|---|---|---|---|
| 1 | **P&L / Profitability** | "What did this car *earn* and *cost*?" | `contracts` (a **subset** of its ledger columns) | `RealProfitService` |
| 2 | **AR / Balance** | "What does the customer *owe*; what did we *record collecting*?" | `contracts.contract_debit/credit` + native `invoices` + native `payments` | `AccountingService` |
| 3 | **Invoicing** | "What was formally *billed* on a document?" | `invoices` (OM `api` origin) | `InvoiceService` (native), OM sync |
| 4 | **Books / Cash** | "What actually hit the *bank* and was *posted*?" | OfficeManager **accounting API** (cash journal + voucher headers) | `ReconciliationService` |

**Every KPI in this document is from subsystem #1.** It does not read invoices, payments, or the accounting API.

---

## 2. Production data snapshot (type-C rental contracts)

| Measure | Amount (AED) | Completeness |
|---|---:|---|
| **Gross Revenue** (`rents_debit − discount + usage_credit`) | **53,281,950** | All 34,045 contracts |
| Billed rent (`rents_debit`) | 53,197,542 | complete |
| Collected rent (`rents_credit`) | 52,616,240 | complete — **98.9%** of billed |
| Contract discount | 788,674 | complete |
| Usage credits (collected km/fuel/cardoo/…) | 873,082 | complete |
| OM `contract_income` | 54,163,706 | 77% populated · **= `rents_debit` for 19,788, > it for 6,594, < for 11** |
| `contract_debit` (all billed, incl VAT, **excl deposit**) | 70,411,478 | complete |
| `contract_credit` (all recorded collected) | 68,479,757 | complete — **97%** of billed |
| VAT (debit) | 2,495,769 | — |
| Deposits (debit, excluded from `contract_debit`) | 9,320,449 | — |
| Invoiced (`Σ invoices.total_after_vat`) | 52,139,468 | **57% of contracts; partial** |
| **Payments table** | **0** | **empty — feature unused** |
| **Operating cost** (commissions + co-driver) | **27,438** | **~empty** |
| **Maintenance cost** (workshop log) | **≈162,128** | **<2% of events** |

**Contract states:** `open` (95) / `closed` (33,950). No `cancelled` state. No soft-deletes. **However 7,792 closed C contracts (23%) carry zero `rents_debit`** (voids / swaps / data gaps) — they add 0 to revenue but **inflate the Rentals count and Cost-per-rental denominator**.
**Invoices:** 23,592 rows, all `origin=api`; 0 without a contract; 3,010 contracts have multiple (max 36); 14,614 C contracts (43%) have none.
**Maintenance coverage:** sheet 20,434 rows → 310 with cost; customer-sheet 6,245 → 0; manual 141 → 7; contract 7 → 0.

**Load-bearing consequence:** because Operating (27k) and Maintenance (162k) are near-empty,
**Net Profit ≈ Gross Revenue**, and **Cost Intelligence** rests on ~162k of thinly-spread cost. The
revenue side is mature; the cost side is not.

---

## 3. KPI reference

### 3.1 Gross Revenue
- **Business definition:** Rental revenue recognized = billed rent − contract discount + collected usage surcharges, ex-VAT.
- **Business purpose:** "What the fleet earned from renting this car."
- **Authoritative source:** `contracts` (OfficeManager sync). **Contracts only.**
- **Service:** `RealProfitService::vehicleAggregates` (`backend/app/Services/RealProfitService.php:92`).
- **Tables:** `contracts` (`contract_type='C'`).
- **Calculation:** `Σ(rents_debit) − Σ(contract_discount) + Σ(km_credit + fuel_credit + cardoo_credit + extra_driver_credit + cdw_credit + gps_credit + co_driver_credit)`.
- **Data completeness:** Complete — all 34,045 contracts; no state/date filter.
- **Known limitations:** (a) **Hybrid basis** — rent on the billed side (accrual), surcharges on the collected side (cash); (b) ex-VAT and excludes salik/damages/breaches/extra-charges/deposit; (c) counts revenue on unpaid/open contracts; (d) does not exclude any voided rentals (none flagged in data).
- **Confidence:** **Trustworthy with known limitations.** Complete, intentional, and within ~1.6% of OM's own `contract_income`.
- **Why this source:** Contracts are the only complete, per-vehicle, lifetime rental record. `RealProfitService` deliberately rejected OM's opaque `contract_income` to build a cost-aware margin. Invoices cover only 57% of contracts and are partial; payments are empty.

### 3.2 Operating Cost
- **Business definition:** Direct per-rental selling cost = salesman commissions + co-driver cost.
- **Business purpose:** Cost of acquiring/servicing the rental.
- **Authoritative source:** `contracts`.
- **Service:** `RealProfitService::vehicleAggregates` (`RealProfitService.php:40`).
- **Tables:** `contracts`.
- **Calculation:** `Σ(salesman_commission_value1 + salesman_commission_value2 + co_driver_cost)`.
- **Data completeness:** **~Empty — 27,438 total across all 34k contracts.**
- **Known limitations:** Barely populated; not representative of real operating cost (no overhead, fuel-cost, insurance, salaries, depreciation-of-effort).
- **Confidence:** **Not suitable for decision making.**
- **Why this source:** Same engine as revenue; it is the only per-contract cost the ledger carries.

### 3.3 Maintenance Cost
- **Business definition:** Workshop repair spend recorded against the vehicle.
- **Business purpose:** Cost of keeping the asset serviceable.
- **Authoritative source:** `maintenances` (Google-Sheet workshop log + hand-entered), `origin ∈ {sheet, manual}` (`backend/app/Models/Maintenance.php:38`).
- **Service:** `RealProfitService::vehicleAggregates` (`RealProfitService.php:111`).
- **Tables:** `maintenances`, `maintenance_line_items` (cost owned by line items when present, else lump sum: `Maintenance.php:1474`).
- **Calculation:** `Σ(maintenances.cost) WHERE origin ∈ {sheet, manual}`.
- **Data completeness:** **<2% — ≈162,128 total across ~27,000 events; 310 of 20,434 sheet rows priced; customer-sheet rows all zero.**
- **Known limitations:** Per-row basis is inconsistent (line-item sum vs hand-entered); the vast majority of repairs have no cost; no link to AP/purchase invoices or the books.
- **Confidence:** **Partially complete → Not suitable for decision making** at car level.
- **Why this source:** The workshop log is the only per-vehicle repair record; there is no financial/AP feed for maintenance.

### 3.4 Net Profit
- **Business definition:** `Gross Revenue − Operating Cost − Maintenance Cost` — cash pocketed from operating the car.
- **Business purpose:** "Did this car make money operationally?"
- **Authoritative source:** derived (contracts + maintenances).
- **Service:** `RealProfitService::vehicleBridge` (`RealProfitService.php:160`); surfaced by `ProfitabilityController` (`backend/app/Http/Controllers/ProfitabilityController.php:34`).
- **Tables:** `contracts`, `maintenances`.
- **Data completeness:** Revenue complete; **cost arms near-empty** → Net ≈ Gross.
- **Known limitations:** Currently **revenue-dominated**; not a real profit measure until costs are populated.
- **Confidence:** **Partially complete** (trustworthy as revenue, misleading as "profit").
- **Why this source:** Single shared engine so every "earned vs cost" number reconciles.

### 3.5 Economic Profit
- **Business definition:** `Net Profit − accumulated straight-line depreciation` — value created after the asset's lost value.
- **Business purpose:** "Did the car create value, not just cash?"
- **Authoritative source:** `DepreciationService` policy (`config/depreciation.php`) over `vehicles.purchase_price/purchase_date` (FASTER Asset sheet).
- **Service:** `DepreciationService` (`backend/app/Services/DepreciationService.php:81`).
- **Tables:** `vehicles` (+ config).
- **Data completeness:** Only cars with a known purchase price/date; NULL (never 0) otherwise.
- **Known limitations:** **Policy-based, not accounting-book based** — a managerial model, not a posted figure; inherits Net's cost-side emptiness.
- **Confidence:** **Trustworthy with known limitations** as a *modeled* figure; **Not suitable** as booked accounting profit.
- **Why this source:** OM's asset/depreciation feed is ~98% empty, so FleetView owns this number.

### 3.6 Cost Intelligence — Cost per km / day / rental
- **Business definition:** Maintenance cost ÷ validated distance / in-service days / rental count.
- **Authoritative source:** numerator = §3.3 (near-empty); denominators = `FuelMileageService` (odometer), `FleetUtilizationService` (days), `RealProfitService` (rentals).
- **Service:** `CostIntelligenceService`.
- **Data completeness:** Denominators solid; **numerator <2% populated.**
- **Confidence:** **Not suitable for decision making** at car level until maintenance cost coverage improves. (Denominators alone — distance, days, rentals — are trustworthy.)

---

## 4. Trust classification

| KPI | Classification |
|---|---|
| Distance (km) · In-service days | **Fully trustworthy** (operational data) |
| Rentals count | **Trustworthy with known limitations** — includes ~7,792 zero-value closed contracts |
| **Gross Revenue** | **Trustworthy with known limitations** (billed-rent basis, ex-VAT, hybrid) |
| **Economic Profit** (as a model) | **Trustworthy with known limitations** (policy-based, not booked) |
| **Net Profit** | **Partially complete** (revenue-dominated; costs missing) |
| **Maintenance Cost** · **Cost per km/day/rental** | **Not suitable for decision making** (<2% coverage) |
| **Operating Cost** | **Not suitable for decision making** (~empty) |

---

## 5. Financial Risks (assumptions that could mislead users)

1. **Revenue is recognized from contracts, not invoices or cash.** It is *billed rent* (accrual), so it counts revenue on open/unpaid contracts. (~99% collection here makes the aggregate gap small, but per open contract it matters.)
2. **Hybrid accounting basis.** Rent is billed-side; surcharges are collected-side. One number blends accrual and cash.
3. **Ex-VAT and rent-centric.** Gross Revenue (53.3M) is far below all-in billed `contract_debit` (70.4M); it excludes VAT, salik, damages, deposits, and *billed* (uncollected) surcharges. Users comparing to an OM "total" will see a mismatch.
4. **Payments table is empty.** There is no independent cash record in FleetView; "collected" exists only as OM's `contract_credit`. The Explainability drawer's Payments panel is always empty and must not imply it reconciles to revenue.
5. **Invoices are partial and incomplete.** 43% of contracts have no invoice; invoiced contracts can have up to 36 interim invoices that do not sum to the contract. Invoiced Revenue is **not** a usable single truth.
6. **Maintenance cost coverage is <2%.** Any car-level maintenance/cost-per-km figure is dominated by missing data.
7. **Operating cost is ~empty (27k total).** Commission data is not maintained.
8. **Profit metrics are therefore revenue-dominated.** Net Profit ≈ Gross Revenue; ranking cars "by profit" is effectively ranking them by rental revenue.
9. **Economic Profit is policy-based, not book-based.** Depreciation is a FleetView config model over sheet-sourced purchase prices, not the accounting asset register.
10. **No reconciliation to the books.** None of these KPIs are verified against the accounting API's cash journal; `ReconciliationService` exists but is a per-contract MVP, not a fleet feed.
11. **`contract_income` (OM's own revenue) is ignored.** It is ≈ `rents_debit` in the data, so this is low-risk, but it is a deliberate divergence from OM's stated number.

---

## 6. The four revenue perspectives (recommended model)

Rather than force one "Revenue," expose all four as reconcilable measures on the same figure:

| Perspective | Definition | Amount | Completeness | Use |
|---|---|---:|---|---|
| **Recognized** (default) | Billed rent − discount + collected usage (ex-VAT) | 53.3M | complete | P&L / performance |
| **Billed (all-in)** | `contract_debit` (incl VAT, excl deposit) | 70.4M | complete | invoicing/AR ceiling |
| **Collected** | `contract_credit` (OM-recorded) | 68.5M | complete | cash-in / AR |
| **Invoiced** | `Σ invoices.total_after_vat` | 52.1M | 57% coverage | document trail |
| **Booked** | Accounting cash journal | — | live-only, unmeasured | the real books |

Each gap (Recognized→Billed = non-rent + VAT; Billed→Collected = AR outstanding; Recognized→Invoiced = uninvoiced contracts) becomes an explainable node, not a hidden assumption.

---

## 7. Recommended roadmap (priority order)

1. **Adopt this document as the contract.** Freeze the definitions above; label every UI number with its perspective and completeness.
2. **Fix financial-data completeness (cost side first).** Populate/import maintenance cost and operating cost. Until then, mark Net/Economic/Cost-Intelligence as "revenue-dominated — cost data incomplete" in the UI.
3. **Improve maintenance cost coverage.** Wire workshop line-items / invoices so `maintenances.cost` is real; this is the single biggest unlock for profit and cost intelligence.
4. **Expose multiple financial perspectives** (Recognized / Billed / Collected / Invoiced / Booked) in the Explainability Platform, with gaps reconciled. Fix or repurpose the empty Payments panel to show Collected via `AccountingService`.
5. **Reconcile to the books.** Promote `ReconciliationService` from per-contract MVP to a fleet feed against the accounting cash journal; surface variance.
6. **Continue building Explainability** on the corrected foundation — every node carrying its perspective + completeness + confidence (already modeled in the platform).
7. **Add Forecasting & Intelligence** (service-due already live) once the cost foundation is trustworthy.

---

## 8. Provenance & Validation (facts vs interpretations)

Every statement in this contract is classified below. **[ASSUMPTION]** rows are the ones to treat with
care — they are interpretations, not verified fact.

### §1 Subsystems
- Four subsystems exist; KPIs use P&L only — **[CODE]** (`RealProfitService`, `AccountingService`, `InvoiceService`, `ReconciliationService`).
- **The "Books/Cash" (accounting API) subsystem is [CODE] only — NOT data-observed.** Its behaviour is described from `ReconciliationService`'s docblock; no accounting-API data was queried this audit. Amounts/coverage there are **[ASSUMPTION]**.

### §2 Data snapshot
- All monetary sums, contract/invoice/payment counts, states, coverage percentages, deposit-exclusion, zero-value-closed count, usage billed-vs-collected — **[DATA]** (queries in Appendix).
- "Payments table empty", "43% uninvoiced", "maintenance <2%", "operating 27k" — **[DATA]**.
- `contract_income` = rent for 19,788 / > for 6,594 / < for 11 — **[DATA]**.

### §3 Per-KPI
- **Formulas, services, tables** (every calculation, `file:line`) — **[CODE]**.
- **Magnitudes / completeness** (53.3M, 27k, 162k, <2%…) — **[DATA]**.
- **"Business definition" and "Business purpose" of every KPI — [ASSUMPTION].** These are the proposed *meaning* of each number (e.g. "Gross Revenue = rental revenue recognized"). The math is [CODE]; naming it "revenue recognized" is an interpretation **not yet signed off by Faster management**.
- "≈ OM `contract_income`" — **[DATA]** per-contract; the "within ~1.6% aggregate" comparison is weak because `contract_income` is only 77% populated → treat the aggregate closeness as **[ASSUMPTION]**, the per-contract equality as **[DATA]**.
- "`_credit` = collected" — **[CODE]** label (OM-*recorded* receipt, per `AccountingService`/`ReconciliationService` docblocks); that it equals **bank cash** is **[ASSUMPTION]** (unverified against the books).
- Depreciation is straight-line, config-driven — **[CODE]** (`DepreciationService`). Specific policy values (5yr / 20% residual) — **[RULE]** (`config/depreciation.php` defaults).
- "OM asset/depreciation feed ~98% empty" — **[CODE]** (docblock claim), **not independently verified** → **[ASSUMPTION]**.

### §4 Trust classification
- The classifications themselves are **[REC]** — an editorial judgement built on the [DATA]/[CODE] above (deliberately conservative).

### §5 Financial Risks
- Risks 1–10 each rest on a [DATA] or [CODE] fact (empty payments, <2% coverage, ex-VAT gap, partial invoices, revenue-dominated profit, policy-based depreciation). The *risk framing* ("could mislead users") is **[REC]**.
- Risk 11 (`contract_income` ignored) — **[CODE]** (RealProfitService does not read it) + **[DATA]** (it ≈ rent).

### §6 Revenue perspectives
- The five amounts — **[DATA]** (Booked = unmeasured, **[ASSUMPTION]**). Exposing all five — **[REC]**.

### §7 Roadmap
- Entirely **[REC]**.

### Consolidated list of ASSUMPTIONS in this contract (nothing hidden)
1. Every KPI's **business definition & purpose** — interpretation, not business-signed-off.
2. The **accounting-API "Books/Cash"** subsystem's behaviour and amounts — code-docblock only, not data-observed.
3. `_credit` / `contract_credit` = **actual bank cash** — unverified against the accounting cash journal (it is OM-*recorded* collection).
4. "Gross Revenue within ~1.6% of OM income" **in aggregate** — `contract_income` is only 77% populated.
5. "OM asset/depreciation feed ~98% empty" — a docblock claim, not re-verified.
6. The **cause** of the 7,792 zero-value closed contracts (voids vs swaps vs gaps) — not investigated.
7. That commissions/maintenance are "missing" rather than "genuinely near-zero for this business" — [DATA] shows the columns are near-empty; whether that reflects reality or a data gap is **[ASSUMPTION]** (very likely a gap, but unconfirmed).

Everything **not** in this list is [DATA], [CODE], or [RULE] — i.e., verified fact.

---

## 9. Sign-off

- **Version:** 1.0 — **FROZEN** 2026-07-22.
- **Validated by:** live-data audit (this document) — provenance tagged in §8.
- **Change control:** any change to a financial definition requires editing this file (bump the version) BEFORE the code change. Features reference this contract; they do not redefine it.
- **Open items requiring business confirmation before promotion to fact:** items 1, 3, 6, 7 in §8's assumptions list.

---

## Appendix — verification queries (read-only)

All figures were produced by read-only aggregate SQL over the live `laravel` DB, e.g.:
- Revenue components: `SELECT SUM(rents_debit), SUM(contract_discount), SUM(km_credit+…+co_driver_credit), SUM(contract_debit), SUM(contract_credit), SUM(contract_income) FROM contracts WHERE contract_type='C'`.
- Ledger integrity: `contract_debit` vs `Σ line-debits` → gap = `Σ deposit_debit` (deposits excluded).
- Invoices: `origin`, orphans, per-contract count, `Σ total_after_vat`; 43% of C contracts uninvoiced.
- Payments: `COUNT(*) = 0`.
- Maintenance coverage: `origin, COUNT(*), SUM(cost>0), SUM(cost)`.

_End of contract. Update this file before changing any financial definition._
