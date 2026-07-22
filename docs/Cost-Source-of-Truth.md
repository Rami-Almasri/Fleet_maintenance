# FleetView — Cost Source of Truth

> ⛔ **SUPERSEDED 2026-07-22.** Vehicle cost is no longer reconstructed from the maintenance tables, OM,
> GL accounts or depreciation policy. Expense now comes solely from the pluggable
> `VehicleExpenseProvider` (Excel today, Odoo later) — see **`docs/Vehicle-Expense-Provider.md`**. The
> audit below is kept for history only.

**Status:** ✅ **VERSION 1.0 — FROZEN** (2026-07-22) · Architectural contract · Read-only audit (no code changed)
**Basis:** Live production DB (`laravel`, MySQL) — snapshot taken during this audit.
**Companion to:** `docs/Financial-Source-of-Truth.md` (Revenue). Same provenance legend applies.
**Scope:** Every cost that feeds Net Profit, Economic Profit, Cost Intelligence, and future cost analytics.

> Freeze this before improving cost coverage. Every future feature references this contract; it does not
> redefine cost logic. Provenance tags: **[DATA]** live data · **[CODE]** source · **[RULE]** documented policy
> · **[ASSUMPTION]** flagged interpretation · **[REC]** recommendation.

---

## 0. Headline — the schema is right; the data is empty

The structured cost model **already exists and is well-designed** (`maintenance_line_items` with
per-line kind/category/tire fields/warranty, Odoo-ready; `maintenance_invoices` for per-garage
reconciliation; `part_purchases` for procurement). **The problem is population, not architecture:**
the granular tables hold **single-digit to low-double-digit row counts**. Total captured cost across the
entire fleet lifetime is **≈ AED 380k** (162k maintenance + 43k insurance-excess + 176k fines), against
53.3M revenue. **[DATA]**

---

## 1. Cost category audit

Coverage = share of the relevant population that has a real cost value.

### 1.1 Maintenance — total repair spend
- **Source:** `maintenances.cost`, `origin ∈ {sheet, manual}` **[CODE]** (`Maintenance.php:38`).
- **Data:** 26,827 workshop rows; **317 have cost > 0 (Σ 162,128.50)**; `parts_total > 0` on 6 (Σ 2,545); `labor_total > 0` on **0**. **[DATA]**
- **Coverage:** **1.2%.** · **Type:** manual / sheet-imported lump sum. · **Confidence:** **Not suitable for decision making.**
- **Other source?** `maintenance_line_items` (rollup owner when present) — near-empty (§1.2).
- **Authoritative target:** rollup of line items; lump-sum only as legacy fallback.

### 1.2 Parts
- **Source:** `maintenance_line_items` (`kind='part'`) + `part_purchases` (procurement). **[CODE]**
- **Data:** line_items = **11 rows total** (all parts: 9 uncategorised Σ1,400, 1 brakes Σ245, 1 engine Σ900); part_purchases = **9 rows (Σ 3,715)**; part_requests = 17 (carry `estimated_price`). **[DATA]**
- **Coverage:** **≈ 0%.** · **Type:** manual entry; `part_requests.estimated_price` = *estimated*, `part_purchases.purchase_price` = *actual*, `line_items.line_total` = *actual/invoiced*. · **Confidence:** **Not suitable.**
- **Authoritative target:** `maintenance_line_items` (canonical), fed by `part_purchases` via `maintenance_line_item_id`.

### 1.3 Labor
- **Source:** `maintenance_line_items` (`kind='labor'`) / `maintenances.labor_total`. **[CODE]**
- **Data:** **0 labor line items; 0 `labor_total`.** **[DATA]** · **Coverage: 0%.** · **Confidence: Not suitable (not captured).**

### 1.4 Tires
- **Source:** `maintenance_line_items` (`tire_brand`, `tire_dot`, `tire_tread_mm`, `category_key='tires'`). **[CODE]** — a dedicated, well-modelled tire schema.
- **Data:** **0 tire line items.** **[DATA]** · **Coverage: 0%.** · **Confidence: Not suitable (not captured).**

### 1.5 Oil / fluids
- **Source:** `maintenance_line_items` (`category_key`). Oil-change **interval** exists (per-car validity km, Oil-Change sheet) but **carries no cost**. **[CODE]/[DATA]**
- **Data:** **0 oil line items; interval sheet has km only, no price.** · **Coverage: 0% (cost).** · **Confidence: Not suitable.**

### 1.6 Fuel (as a company cost)
- **Source:** **None.** Contract `fuel_debit`/`fuel_credit` is **customer billing (revenue side)**, not a fleet fuel expense. **[CODE]/[ASSUMPTION]** (no company fuel-cost store found).
- **Coverage: 0%.** · **Confidence: Not modeled.**

### 1.7 Insurance
- **Source:** `vehicle_registrations.insurance_bear_amount` (+ insurance company/no/dates). **[CODE]**
- **Data:** 542 registration rows; **`insurance_bear_amount > 0` on 11 (Σ 43,200)**. **[DATA]**
- **Coverage: 2%.** · **Type:** imported (API / F-Insurance sheet). · **Confidence: Not suitable.**
- ⚠️ **`insurance_bear_amount` is the excess/"bearing" amount, not the premium.** The insurance **premium (the actual cost)** is **not stored**. **[ASSUMPTION]** on field meaning — confirm with the business.

### 1.8 Registration / Fines
- **Source:** `vehicle_registrations.fines_amount` / `fines_count` (RTA). **[CODE]**
- **Data:** **`fines_amount > 0` on 109 of 542 (Σ 176,210)**; 66 mortgaged. **[DATA]**
- **Coverage: 20%.** · **Type:** imported (F-RTA). · **Confidence: Partially complete.**
- ⚠️ Fines are frequently a **renter liability** (see renter-liability model), not always a fleet cost — classify as a *contingent* cost, not operating cost. **[CODE]/[ASSUMPTION]**
- **Registration renewal (Mulkiya) fee:** **not stored** → Coverage 0%, Not modeled.

### 1.9 Depreciation (asset ownership cost)
- **Source:** `DepreciationService` policy (`config/depreciation.php`) over `vehicles.purchase_price` + `purchase_date` (FASTER Asset sheet). **[CODE]**
- **Policy:** straight-line, **useful life 5y, residual 20%**, no category overrides. **[RULE]** (`config/depreciation.php:31-37`).
- **Data:** 438 vehicles; **purchase_price > 0 on 237 (54%)**, purchase_date on 438; Σ purchase_price = 19,461,207. **[DATA]**
- **Coverage: 54%.** · **Type:** **calculated / estimated** (a managerial model, not a booked figure). · **Confidence: Trustworthy with known limitations.**

### 1.10 Operating cost (commissions + co-driver)
- Cross-reference `Financial-Source-of-Truth.md §3.2`: `contracts` columns, **Σ 27,438 total → ~empty → Not suitable.** **[DATA]**

### 1.11 Overhead / salaries / GPS / CDW / insurance-of-driver
- **Not modeled as costs.** GPS/CDW/extra-driver appear only as customer *charges* (revenue). **[ASSUMPTION]** (no cost store found).

---

## 2. Cost trust classification

| Cost category | Coverage | Type | Classification |
|---|---:|---|---|
| Depreciation | 54% | calculated (policy) | **Trustworthy with known limitations** |
| Fines | 20% | imported (RTA) | **Partially complete** (contingent cost) |
| Maintenance total | 1.2% | manual/sheet | **Not suitable for decision making** |
| Insurance | 2% | imported (excess, not premium) | **Not suitable** |
| Parts | ~0% | manual | **Not suitable** |
| Labor · Tires · Oil | 0% | — | **Not suitable (not captured)** |
| Operating (commission) | ~0% | imported | **Not suitable** |
| Fuel · Registration renewal · Overhead | 0% | — | **Not modeled** |

**Net effect:** the only cost inputs with meaningful coverage are **Depreciation (54%)** and **Fines (20%)** — and fines are contingent. Everything that drives **Net Profit / Cost Intelligence** (maintenance, parts, labor) is **~0%**. This is the single biggest data gap in the financial engine.

---

## 3. Recommended long-term Cost Source of Truth architecture

The target already exists in the schema. Declare and enforce it:

1. **Canonical granular ledger — `maintenance_line_items`.** Every repair cost is a typed line
   (`kind` ∈ part/labor/tire/oil/fluid/other, `category_key`, `quantity × unit_price = line_total`,
   warranty, Odoo refs). Tires and oil use the existing dedicated columns. **This is the one place a
   maintenance cost is entered.**
2. **Rollup — `maintenances.cost`** is owned by the line items via `recalcLineItemTotals()` (`Maintenance.php:1474`); lump-sum entry survives only as a legacy fallback and is phased out.
3. **Per-garage reconciliation — `maintenance_invoices`** (`parts_total`/`labor_total`/`amount`/`receipt_total`/`variance`) verifies line-item totals against the garage's actual invoice/receipt. Currently 0 rows → activate.
4. **Procurement cost — `part_purchases`** (actual `purchase_price × quantity`) bridges to a line item
   (`maintenance_line_item_id`); `part_requests.estimated_price` is the *estimate*, the purchase is the *actual* → carry an **actual-vs-estimate** flag on every part.
5. **Holding costs — `vehicle_registrations`:** add a true **insurance premium** field (separate from `insurance_bear_amount` excess) and a **registration-renewal fee** field; keep fines as a *contingent* cost tagged by renter-liability.
6. **Asset cost — `DepreciationService`** stays the depreciation authority (policy-based); raise `purchase_price` coverage above 54%.
7. **Fuel & overhead:** decide whether they are in-scope; if so, add explicit cost stores (do **not** reuse contract fuel columns, which are revenue).
8. **Reconcile to the books:** the accounting **AP** side (supplier invoices, vouchers) is the ultimate cost truth; integrate via the same accounting API used for cash on the revenue side. **[REC]**

**Cost taxonomy (one enum across the platform):**
`repair.part · repair.labor · repair.tire · repair.oil · repair.other · procurement.part ·
holding.insurance · holding.registration · holding.fine(contingent) · ownership.depreciation ·
operating.commission · operating.co_driver`.
Each cost line carries: `cost_type`, `amount`, `source_table`, `basis` (actual/estimated/imported/manual),
`confidence`, `as_of`. This is exactly the node contract the Explainability Platform already uses.

---

## 4. Provenance & assumptions (nothing hidden)

- All row counts, sums, coverage % — **[DATA]**.
- All table/column/service/policy facts — **[CODE]/[RULE]**.
- **[ASSUMPTION]s flagged for business confirmation:**
  1. `insurance_bear_amount` = excess, not premium (§1.7).
  2. No company **fuel** cost exists (only customer-billed fuel) (§1.6).
  3. Costs are "**missing**" vs "**genuinely near-zero**" — the columns are [DATA]-empty; that this is a
     capture gap (very likely) rather than reality is unconfirmed (§0).
  4. Fines are a **contingent** (renter-liable) cost, not always a fleet cost (§1.8).
  5. Overhead/salaries out of scope — no store found (§1.11).
- §2 classifications and §3 architecture are **[REC]**.

---

## 5. Sign-off

- **Version:** 1.0 — **FROZEN** 2026-07-22.
- **Change control:** edit this file + bump version BEFORE any cost-logic change. Features reference it.
- **Immediate roadmap consequence (see Financial-Source-of-Truth §7):** improving **maintenance line-item
  coverage** is the highest-leverage next step — it converts Maintenance, Parts, Labor, Tires, Oil, Net
  Profit, and Cost Intelligence from "Not suitable" toward "Trustworthy" in one stroke.
- **Open items requiring business confirmation:** §4 assumptions 1–5.

_End of contract._
