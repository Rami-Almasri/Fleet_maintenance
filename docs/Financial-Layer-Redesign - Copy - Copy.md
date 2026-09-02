# FleetView — Financial Layer Redesign (OM API as the authority)

> ⛔ **SUPERSEDED 2026-07-22.** This OM-API / GL / Power BI cost-reconstruction direction was abandoned.
> Vehicle expense now comes from a single pluggable provider (Excel today, Odoo later) — see
> **`docs/Vehicle-Expense-Provider.md`**. This file is kept for history only; do not implement from it.

**Status:** Proposal + phased implementation · 2026-07-22
**Authority:** OfficeManager API `http://81.85.92.150:8080` (already FleetView's sync source; `OfficeManagerClient`).
**Anchors:** `docs/Financial-Source-of-Truth.md` (v1.0), `docs/Cost-Source-of-Truth.md` (v1.0).

## The decision

FleetView **stops computing financial truth from its own tables** and **consumes the OM API**, which returns GL-backed, per-vehicle **revenue, cost, profit** (period + cumulative, owned vs leased) — verified against the accounting ledger. FleetView keeps only the **operational** layer (workflow, inspections, logistics) and treats invoices/manual costs as **operational documents, not financial calculations**.

The API answers, per vehicle, lifetime→today: **how much we spent, on what categories, when, and which vouchers explain it** — via `/fleet/kpis` (totals) + a voucher-details endpoint (categories) + `/vehicle-breaches` (salik/fines) + `/reports/balance` (cash).

---

## 1. Audit of existing FleetView financial features

### Backend services
| Service | What it does today | Data quality | Verdict |
|---|---|---|---|
| `RealProfitService` | Profit Bridge: rent−disc+usage − commissions − maintenance | Cost side ~empty (162k); misses owner-rent | **REPLACE** with `/fleet/kpis` |
| `CostIntelligenceService` | cost per km/day/rental | numerator <2% coverage | **REPLACE** with `/fleet/kpis` (`cost_per_km` etc.) |
| `DepreciationService` | economic profit (policy straight-line) | model, not booked; GL books its own | **REPLACE booked** w/ GL; keep as optional managerial overlay |
| `VehicleFinancialBreakdownService` | drill-down rows behind Profitability | derived from the above (incomplete) | **REPLACE source** (feed from API) |
| `FinancialExplanationService` + `Explainability/*` | recursive explanation graph/platform | presentation is right; numbers incomplete | **KEEP platform, RE-SOURCE** from API |
| `AccountingService` | customer balance/wallet (contract_debit/credit + native) | OM ledger real; native payments empty | **KEEP** (AR/customer), not vehicle P&L |
| `ReconciliationService` | bridges to OM accounting cash journal | real, aligns with new direction | **KEEP / EXPAND** |
| `InvoiceService` / `MaintenanceInvoiceService` / `GarageInvoiceService` | M-/garage invoices | invoices partial/broken (VAT math) | **KEEP as operational docs**, not financial truth |
| `FinancialConflictService` | flags broken invoices (VAT mismatch, overlaps) | a data-quality tool | **KEEP** (operational QA), relabel "not a source" |
| `FuelMileageService`, `ServiceWindowService` | distance, in-service dates | real operational data | **KEEP** |

### Frontend pages / components
| Page/Component | Verdict | Note |
|---|---|---|
| `Profitability.js` | **REPLACE numbers** | keep shell; feed `/fleet/kpis` (cum_revenue/cum_cost/profit, owned vs leased) |
| `CostIntelligence.js` | **REPLACE numbers** | `cost`, `cost_per_km` from API |
| `FinancialBreakdownDrawer.js` (Explainability) | **KEEP, RE-SOURCE** | the drill-down UI is right; source from API totals + voucher-details categories |
| `ProfitBridge.js` | **KEEP** | feed API numbers |
| `vehicles/FinancialsHero.js`, `vehicles/VehicleAnalytics.js` | **REPLACE** | per-vehicle metrics from `/fleet/vehicle/{car_serial}` |
| `PendingInvoices.js`, `GarageInvoicePortal.js`, `GarageInvoiceQueue.js`, `PartInvestigations.js`, `Parts.js`, `TireDetails.js`, `CompletedRepairs.js` | **KEEP as operational** | remove any "cost total = financial truth" framing; show costs from API where displayed |
| `FinancialConflicts.js` | **KEEP** | operational QA |
| `customers/CustomerProfile.js` wallet/balance | **KEEP** | AR from `AccountingService` (honor `SHOW_FINANCIALS`) |
| Manual maintenance **cost entry** widgets (lump-sum `maintenances.cost`, line-items as *financial* totals) | **REMOVE (as financial truth)** | keep line-items only as operational parts/labor log; the money comes from GL |

### Classification summary
- **A) KEEP (real):** AccountingService (AR), ReconciliationService, FuelMileage/ServiceWindow, FinancialConflicts (QA), invoice pages *as documents*, the Explainability platform *as UI*.
- **B) REPLACE (move calc to API):** RealProfitService, CostIntelligenceService, booked depreciation, VehicleFinancialBreakdown source, Profitability/CostIntelligence/per-vehicle financial numbers.
- **C) REMOVE (fake accuracy):** treating `maintenances.cost` / manual line-item sums / invoices as the vehicle's financial cost; any "Net/Economic profit" presented as truth while cost was ~empty (replaced, not kept in parallel).

---

## 2. Mapping — feature → new API source

| FleetView need | OM API source | Status |
|---|---|---|
| Vehicle total cost (cumulative) | `/fleet/kpis` → `cum_cost`; `/fleet/vehicle/{car_serial}` → `metrics.cum_cost` | ✅ ready |
| Vehicle cost by period | `/fleet/kpis?from_date&to_date` → `cost` | ✅ ready |
| Vehicle revenue / profit | `/fleet/kpis` → `revenue`, `profit` (+ cumulative) | ✅ ready |
| Owned vs Leased | `Owner` field + `owned_only` filter | ✅ ready |
| Cost per km / revenue per km | `/fleet/kpis` → `cost_per_km`, `revenue_per_km` | ✅ ready |
| Salik / traffic / parking | `/vehicle-breaches` (per car+contract, `BreachVoucherSerialNo`) | ✅ ready |
| Cash collected | `/reports/balance` | ✅ ready |
| **Cost by category** (rent/maintenance/parts/tires/oil/insurance/fuel/registration) | **`/api/v1/accounts/vouchers/details`** (voucher lines: `DebitAmount`,`Remarks`,`AccountSerialNo`,`CarSerial`) | ⛔ **endpoint not yet exposed — must be added** |
| **Documents/vouchers behind a cost** | voucher-details + `/vouchers` headers | ⛔ needs the details endpoint |

**Join key:** OM `CarSerial` ↔ FleetView `vehicles.car_serial` (native). Owner via OM `Owner`/account `(Our)`/`(Lent)`.

---

## 3. Proposal — remove / change / add

**Add**
- `OfficeManagerClient::fleetKpis()`, `fleetVehicle($carSerial)`, `vehicleBreaches()`.
- `FleetFinancialService` — authoritative per-vehicle + fleet financials from the API (cached), keyed to FleetView vehicles by `car_serial`.
- Endpoints: `GET /intelligence/fleet-financials`, `GET /intelligence/vehicle/{vehicle}/financials`.
- **Request one new OM API endpoint:** `GET /api/v1/accounts/vouchers/details` (see §5) — the only missing piece for category-level "why".

**Change (replace numbers, keep shells)**
- Profitability + Cost Intelligence pages → API-backed values.
- Explainability platform → source totals from `/fleet/kpis`, categories from voucher-details, salik from `/vehicle-breaches`.
- Per-vehicle financial hero/analytics → `/fleet/vehicle/{car_serial}`.

**Remove (fake accuracy)**
- FleetView-computed Net/Economic profit and cost-per-km as *truth* (replaced by API).
- Any UI implying `maintenances.cost` / invoices are the vehicle's financial cost.

**Keep (operational)**
- Invoices, garage-invoice queues, parts/tires/oil logs, maintenance workflow, customer AR/wallet, FinancialConflicts QA, distance/in-service.

---

## 4. Phased implementation

1. **Foundation (safe, additive):** API client methods + `FleetFinancialService` + read-only endpoints. *(this PR)*
2. **Replace Profitability + Cost Intelligence** to API-backed (feature-flagged, reconciled against old for one release).
3. **Re-source the Explainability platform** (totals now; categories when the details endpoint lands).
4. **Category layer:** consume `/api/v1/accounts/vouchers/details` → cost-by-category per vehicle + voucher drill-down.
5. **Demote invoices/manual-cost** UI to operational-only; remove fake-accuracy framings.

## 5. The endpoint to request from the OM API developer (REVISED 2026-07-22)

### Why FleetView cannot build this itself
`/fleet/vehicle/{car_serial}` → `metrics.cum_cost` is computed from **multiple accounts + internal scoping**, proven live:
- Car 2044 (owned): repair-expense account = **17,590.31**, but `cum_cost` = **16,087.24** (API scopes DOWN ~1,503).
- Another car: repair-expense account = **30,399.61**, but `cum_cost` = **50,399.64** (API adds ~20,000).

**Cost composition identified (live GL):**
- Every car has ONE **`RAVehicles.ExpencesAccountNo`** — holds maintenance, parts, tyres, oil, insurance, owner-rent, salik, registration as voucher LINES, distinguished by `Remarks` (not separate accounts).
- **444 owned cars** additionally have a **`RAVehicles.DepreciationAccountNo`** (444 = the Faster-owned fleet) — the likely source of the "API > account" gap.
- Vendor/garage accounts (RMR GARAGE, ELI STUDIO…) are **AP-clearing** (debit = credit) — NOT cost.
- On top of the accounts, `cum_cost` applies an **internal scope** (date window / inclusions) we can't see.

⇒ Per the decision: **do NOT reproduce this logic in FleetView.** Only OM can emit the authoritative lines.

### Required endpoint
```
GET /api/v1/accounts/vouchers/details
  ?car_serial=  & from_date=YYYY-MM-DD & to_date=YYYY-MM-DD  (+ X-API-Key)
→ [ {
      car_serial, voucher_no, voucher_date,
      account_no, account_name,
      debit, credit,
      invoice_no, supplier, remarks,     // parsed where OM can; else remarks verbatim
      cost_category                      // Maintenance | Parts | Tyres | Oil | Insurance |
                                         // Owner Rent | Depreciation | Salik | Registration | Other
  } ]
```
**Hard requirement — it MUST reconcile:**
> For a given `car_serial`, `Σ (debit − credit)` of the returned lines **equals**
> `/api/v1/fleet/vehicle/{car_serial}` → `metrics.cum_cost`, to the fils.

Return **only** the vouchers OM's cost engine actually counts (same accounts incl. depreciation, same
scope). With `cost_category` tagged per line, FleetView renders the exact
"Maintenance / Parts / Insurance / Owner Rent / Depreciation / Other = cum_cost, difference 0" breakdown
with **zero FleetView calculation**.

### Until it ships
FleetView shows a **"Partial GL account entries"** panel (the car's repair-expense account only, via Power
BI) with the reconciliation gap displayed, plus a pending category shell stating the target total
(= `cum_cost`) and difference (0). Nothing is presented as a complete cost breakdown.

---

**Final goal restated:** for any vehicle, lifetime→today — spend total (`/fleet/kpis`), by category (voucher-details), when (voucher dates), and which documents (voucher serials) — all from the OM accounting authority, not FleetView calculations.
