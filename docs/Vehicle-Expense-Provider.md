# FleetView — Vehicle Expense Provider (the single expense seam)

**Status:** ✅ Active · 2026-07-22 · Supersedes the OM/GL/Power BI cost-reconstruction direction
(`docs/Financial-Layer-Redesign.md`, `docs/Cost-Source-of-Truth.md`, and the cost half of
`docs/Financial-Source-of-Truth.md` — all now historical).

## The decision

FleetView no longer reconstructs vehicle cost from OfficeManager, Power BI, GL accounts, vouchers,
depreciation accounts, reconciliation, or the maintenance tables. **Every per-vehicle expense value comes
from ONE pluggable provider**, and nothing else:

```
Excel → VehicleExpenseProvider → FleetView   (today)
Odoo  → VehicleExpenseProvider → FleetView   (later)
```

The UI, the profit calculation, cost/km, the KPIs, the dashboard, and the expense drawer are **unchanged**.
Only the origin of the expense number moved. Swapping Excel → Odoo is one config value + one class; no
consumer changes.

## The contract

`App\Contracts\VehicleExpenseProvider` — the only way the app reads expense:

- `totalsByVehicle(?ids, ?from, ?to): array` — `[vehicle_id => total]` (the profit/cost engines use this)
- `total(vehicleId, ?from, ?to): ?float`
- `history(vehicleId, ?from, ?to): array` — `[{date, remarks, amount, account_type}]`, oldest first
  (the expense drawer / timeline / remarks list render this verbatim)
- `source(): array` — `{label, available, as_of, lines}`

Bound in `AppServiceProvider` from `config('expenses.provider')` (`VEHICLE_EXPENSE_PROVIDER`, default
`excel`). Add `OdooVehicleExpenseProvider` and flip the config to switch.

## Today's implementation — Excel

- **Store:** `vehicle_expenses` (one row per sheet line: `car_serial`, `vehicle_id`, `entry_date`,
  `account_type`, `remarks`, `debit`, `credit`, `amount`, `source`). Line grain so the drawer can show the
  exact remarks/date/amount history and windowed metrics can filter by date.
- **Import:** `php artisan expenses:import "<path>.xlsx"` (native XLSX parse — no PhpSpreadsheet). Idempotent;
  a run replaces its own `source`. `--dry-run` validates without writing.
- **Provider:** `ExcelVehicleExpenseProvider` reads `vehicle_expenses WHERE source='excel'`.
- **Total rule:** expense = Σ(`debit − credit`). **Year-end "Closing Expense Account for the year YYYY"
  reversal lines are dropped at import** — they are accounting mechanics that would net real spend back to
  ~0, and are not real vehicle expense.

### Sheet shape (as imported 2026-07-22)
Columns: `CarSerial · Account type (Expence/xExpence) · Remarks · VoucherDate · Debit · Credit`.
30,367 lines → 2,040 closing reversals dropped → **28,327 expense lines**, **AED 26,938,222.96** total,
across **358** matched FleetView vehicles. Join key: `vehicles.car_serial`.

## Where it plugs in

- `RealProfitService::vehicleAggregates()` — the `maintenance` slot of the Profit Bridge is now
  `VehicleExpenseProvider::totalsByVehicle()`. Field name kept as `maintenance` so every downstream
  consumer (Profitability, Cost Intelligence, Dashboard, negative-yield, per-vehicle profile) is untouched.
- `VehicleFinancialBreakdownService` / `FinancialExplanationService` — the drawer's expense sub-tree is
  built from `history()`; it reconciles to the total by construction.

## What was removed (OM cost reconstruction)

`FleetFinancialService`, `PowerBiClient`, `config/powerbi.php`; `OfficeManagerClient::{fleetKpis,
fleetVehicle, vehicleBreaches, accountsVouchersDetails}`; `officemanager.vouchers_details_enabled`;
`IntelligenceController::{fleetFinancials, vehicleFinancials, financialTrace, voucherLines,
vehicleBreaches}` + their routes; frontend `FleetFinancialsView.js`, `FinancialTraceDrawer.js`, the
Profitability/Cost-Intelligence source toggle, and `USE_API_FINANCIALS`.

Revenue is **unchanged** — still rental income from contracts (`RealProfitService`); it is not an expense.
