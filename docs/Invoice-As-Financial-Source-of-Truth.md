# Invoice as the Financial Source of Truth

**Status:** PROPOSAL — no code written. Supersedes `PartSpendService` as a permanent design.
**Date:** 2026-07-29

---

## 1. What the schema actually looks like today

Before proposing anything, here is every place a dirham of *cost* can currently originate. Row counts are from the live DB.

| # | Table | Rows | Money column(s) | What it really is |
|---|---|---:|---|---|
| 1 | `maintenance_line_items` | 12 | `line_total` (`kind` = part\|labor) | The itemised cost lines of a repair. **Already the model you want.** |
| 2 | `maintenance_invoices` | 0 | `parts_total`, `labor_total`, `amount`, `receipt_total` | One garage bill on a ticket. **Already the "Repair Invoice" you want.** |
| 3 | `part_purchases` | 10 | `purchase_price` | A part bought via the request flow. Second money field. |
| 4 | `maintenances.cost` | 317 costed | `cost`, `parts_total`, `labor_total` | Ticket scalar. 310 of these came from the sheet with **no invoice at all**. |
| 5 | `maintenance_tasks` | 36 | `parts_cost`, `labor_cost` | Per-fault roll-up of #1. Derived. |
| 6 | `garage_invoice_submissions` | 6 | `parts_total`, `labor_total`, `itemized_total` | Garage-portal draft, before it becomes #2. |
| 7 | `vehicle_expenses` | **28,327** | `amount` | **AED 26.9M.** The Excel accounting ledger. |
| 8 | `supplier_quotes` / `rfq_lines` | 0 | `unit_price` | Pre-purchase quotes. Not spend. |
| 9 | `maintenance_items` | 0 | `cost` | Dead — type-U contract service lines. |

Plus, on the revenue side:

| # | Table | Rows | What it is |
|---|---|---:|---|
| 10 | `invoices` | **23,592** | **Rental invoices to customers.** `customer_id`, `contract_id`, `rent_days`, `net_rate`, VAT, `paid_amount`. This is *income*, not cost. |
| 11 | `invoice_items` | 0 | A money-free "Technical Service Log" hung off #10. Effectively dead — one read path in `VehicleController`. |

### Three findings that change the shape of the proposal

**(a) You have already built most of this.** `maintenance_invoices` + `maintenance_line_items` is *exactly* your Repair Invoice model: an invoice row with a `vendor_id`, whose `parts_total` / `labor_total` / `amount` are re-derived from child lines carrying `kind = part | labor`. `MaintenanceInvoice::recalcTotals()` already does `Total = Labour + Parts`. The "One Ticket → Many Invoices" migration already backfilled history into it. The problem is **not that the model is wrong — it's that it isn't mandatory and isn't the only door.**

**(b) The name `invoices` is taken, by 23,592 rows of rental revenue.** Any design that says "everything financial comes from `invoice_items`" collides head-on with the customer billing table. This is the single biggest trap in the redesign and it forces a naming decision up front (§4).

**(c) The real fragmentation is not where the ticket says it is.** `PartPurchase` vs `maintenance_line_item` is a 22-row problem. The 26.9M-dirham problem is `vehicle_expenses` — a flat Excel ledger whose `remarks` field is unstructured free text that *already contains* both of your invoice types:

```
"FENDER AND COVER SMALL FRONT - CHROME TRUNK W167 GLE"        → a Parts Invoice
"Power point garage inv 002335/Dodge Challenger 54765/ airbag  → a Repair Invoice
 two side repaire..."
"INV 18816/ CAR 88106/ RENT 2000/ VAT 123.25/ SALIK 65"        → not a cost at all (rental income)
"Car Wash Expence For MiniCooper Black 36726"                  → neither invoice type
```

A design that makes invoices the source of truth without saying what happens to these 28,327 rows will just create source #10 instead of removing sources #3–#6. §7 addresses this directly, and it is the one place I'd push back on "no reconciliation layer."

---

## 2. The target model

Your shape, with the naming forced by finding (b):

```
purchase_invoice                      (was: maintenance_invoices)
  ├─ type: 'parts' | 'repair'
  ├─ vendor_id                        supplier / parts shop / garage
  ├─ maintenance_id  (NULLABLE)       a repair invoice has one; a stock parts buy may not
  ├─ vehicle_id      (NULLABLE)       direct-to-car buy; null when a parts invoice spans cars
  ├─ invoice_no, invoice_date
  ├─ parts_total / labour_total / amount     ← derived, never hand-set
  └─ receipt_total, variance_explanation, reconciliation_status

purchase_invoice_item                 (was: maintenance_line_items)
  ├─ purchase_invoice_id  (REQUIRED — this is the change that makes it a source of truth)
  ├─ kind: 'part' | 'labour'
  ├─ vehicle_id                       denormalised, for per-car TCO in one scan
  ├─ maintenance_task_id              which fault this line paid for (optional)
  ├─ description, part_number, quantity, uom, unit_price, line_total
  └─ installed_on, installed_odometer, warranty_months     (parts only)
```

**Type rule, enforced in the model, not by convention:**

| type | may contain | requires |
|---|---|---|
| `parts` | `kind = part` only | vendor |
| `repair` | `kind = part` **and** `kind = labour` | vendor + `maintenance_id` |

Then, exactly as you wrote it:

```sql
-- Where parts money goes
SELECT description, SUM(line_total) FROM purchase_invoice_items
WHERE kind = 'part' GROUP BY 1;

-- Labour spend
SELECT SUM(line_total) FROM purchase_invoice_items WHERE kind = 'labour';

-- Garage spend / Supplier spend
SELECT vendor_id, SUM(amount) FROM purchase_invoices WHERE type = 'repair' GROUP BY 1;
SELECT vendor_id, SUM(amount) FROM purchase_invoices WHERE type = 'parts'  GROUP BY 1;

-- Vehicle repair cost
SELECT SUM(line_total) FROM purchase_invoice_items WHERE vehicle_id = ?;
```

`PartSpendService` deletes itself: it becomes one `GROUP BY`.

### What the other tables become

| Today | After | Why |
|---|---|---|
| `part_purchases.purchase_price` | **procurement record, not money.** Keeps ordering/delivery/install (`po_number`, `expected_delivery_date`, `delivered_at`, `installed_at`, duplicate detection). `purchase_price` is retained but renamed in meaning to *expected* price; the actual charge lives on the invoice item it points at. | The parts board's *workflow* is genuinely useful. Only its claim to be a cost ledger goes away. |
| `maintenances.cost / parts_total / labor_total` | **derived cache, write-blocked.** Recomputed from its invoices. | ~19 services read `maintenances.cost`. Keeping it as a cache means **none of them change**. |
| `maintenance_tasks.parts_cost / labor_cost` | unchanged (already derived from lines) | Per-fault cost survives for free. |
| `garage_invoice_submissions` | unchanged | It's a *draft* that converts into a `purchase_invoice`. Correct as-is. |
| `invoice_items` (money-free service log) | **dropped** | 0 rows, 1 read path, and the name is needed. Its `service_records.source_invoice_item_id` FK is also unused (0 rows). |
| `maintenance_items` | **dropped** | 0 rows, dead code path. |
| `invoices` / `payments` | untouched | Revenue side. Explicitly out of scope. |
| `vehicle_expenses` | **stays.** Becomes the *external accounting mirror* that invoices are reconciled against — never summed with them. | See §7. |

---

## 3. Why generalise the existing table instead of building a new one

The instinct is to create clean `purchase_invoices` / `purchase_invoice_items` tables and migrate into them. I recommend **renaming and relaxing the existing pair** instead:

- `maintenance_invoices` already carries the full reconciliation machinery — receipt total, variance + mandatory explanation, per-invoice reconciliation clock, receipt photo, `recorded_by/at`. Rebuilding that is weeks of re-testing for zero gain.
- `maintenance_line_items` already has `kind`, `quantity`/`uom`/`unit_price`/`line_total`, warranty + install-odometer tracking, `category_key`, and Odoo export columns. It is already the right table.
- The roll-up chain (line → invoice → task → ticket) already exists and is hook-driven. A new table means re-writing all of it.
- History is already backfilled into it by the "One Ticket → Many Invoices" migration.

So the delta is small and mostly *constraints*, not structure:

1. rename the two tables + models
2. add `type`, `invoice_date`, `vehicle_id` to the invoice
3. make `maintenance_id` **nullable** (this is what unlocks the standalone Parts Invoice)
4. make `purchase_invoice_id` on items **required** (this is what makes it a source of truth)
5. rename `kind = 'labor'` → `'labour'` if you want the spelling to match your spec (cosmetic; I'd leave it as `labor` and only change the UI label)

---

## 4. The naming decision — I need your call

`invoices` is rental revenue. Options:

- **A. `purchase_invoices` / `purchase_invoice_items`** *(my recommendation)* — unambiguous against the revenue table, standard AP terminology, reads correctly for both a supplier parts bill and a garage repair bill.
- **B. `cost_invoices`** — clearer to non-accountants, less standard.
- **C. Rename the revenue table to `rental_invoices` and take `invoices` for cost.** Cleanest end state, but it touches 23,592 rows, `payments.invoice_id`, the OM sync, and every revenue report. High blast radius for a naming preference.

I'd take A and revisit C only if the revenue table ever gets rewritten for other reasons.

---

## 5. Migration path

Six phases. Each is independently deployable and leaves the app working — nothing here is a big-bang cutover.

### Phase 0 — Freeze and measure *(no schema change)*
- `php artisan db:backup` (the standing gate before any re-import).
- Add a temporary command that reports total cost per source. This becomes the **invariant check** run after every later phase: the fleet's total cost must not move.
- Establish the baseline: `maintenances.cost` = 162,338.50; part lines = 2,755.00; unfitted purchases = 2,370.00.

### Phase 1 — Rename + relax *(structural, no behaviour change)*
- Rename `maintenance_invoices` → `purchase_invoices`, `maintenance_line_items` → `purchase_invoice_items`, and the FK `maintenance_invoice_id` → `purchase_invoice_id`.
- Add `type` (default `'repair'` — every existing row *is* a repair invoice), `invoice_date`, `vehicle_id`.
- Make `maintenance_id` nullable.
- Keep Eloquent aliases (`class MaintenanceInvoice extends PurchaseInvoice`) for one release so nothing breaks mid-deploy.
- **Ship.** Nothing user-visible changes.

### Phase 2 — Wrap the orphans *(data, idempotent)*
Every dirham that exists today without an invoice gets one, so the invariant "cost ⇒ invoice" becomes true *before* it is enforced:

- **310 sheet-costed tickets** → one `purchase_invoice` each, `type = repair`, `origin = 'legacy_sheet'`, a single `kind = labor` line described from the sheet's `Maintenance and repair notes`, `vendor_id` from the ticket's garage. These are honest: the sheet recorded a repair total with no breakdown, so it becomes a one-line repair invoice, flagged as unitemised.
- **Loose line items** (`purchase_invoice_id IS NULL`) → wrapped in a per-ticket invoice.
- **Unfitted part purchases** (no line item, 10 rows / AED 2,370) → one `type = parts` invoice per purchase, vendor from `source_vendor_id`, one `kind = part` line, and `part_purchases.purchase_invoice_item_id` stamped back.
- Idempotent via an `origin` + source-id key, exactly like `complaints:migrate-legacy`.
- Re-run the Phase 0 invariant: total must be **unchanged**.

### Phase 3 — Close the side doors *(enforcement — the phase that actually delivers the goal)*
- `purchase_invoice_items.purchase_invoice_id` → `NOT NULL`.
- `Maintenance::cost` / `parts_total` / `labor_total` become **read-only derived**: guard in `saving()` that throws if set outside `recalcFromInvoices()`. This is what kills `MaintenanceWorkflowService`'s three direct `$ticket->cost = $data['cost']` write points (lines ~3823, ~4313, ~5186) — they must open a one-line invoice instead.
- `PartWorkflowService::purchase()` writes an invoice + item rather than a bare `purchase_price`.
- Model-level validation of the type rule (a `parts` invoice rejects a labour line).
- The sheet importer stops writing `maintenances.cost` directly and routes through the Phase-2 legacy wrapper.

### Phase 4 — Standalone Parts Invoice *(the new capability)*
- UI + API to record a supplier/parts-shop bill with no ticket: vendor, invoice no/date, N part lines, optional per-line vehicle.
- Optional link from a line to a `maintenance_task_id` when the part is for a known fault.
- This is the piece that genuinely does not exist today — `maintenance_invoices.maintenance_id` is currently `NOT NULL`, so there is nowhere to file a parts-shop bill.

### Phase 5 — Collapse the reporting layer
- Delete `PartSpendService`; the Parts chart becomes `GROUP BY description WHERE kind = 'part'`.
- Point `CostIntelligenceService`, `VehicleFinancialBreakdownService`, `RealProfitService`, `MaintenanceAnalyticsService`, `OdooExportService` at invoice items.
- Register a `PurchaseInvoice` explainer in the Explainability DAG so every figure traces to an invoice line — which is exactly what that engine was built for.
- Drop `invoice_items`, `maintenance_items`, and `service_records.source_invoice_item_id`.

---

## 6. What is preserved

Explicitly, none of the maintenance workflow changes:

- The ticket lifecycle (`workflow_status`, all stage transitions) is untouched — invoices hang off it, they don't drive it.
- One Ticket → Many Invoices survives; it is now One Ticket → Many *Repair* Invoices.
- Fault→invoice linkage (`maintenance_tasks.maintenance_invoice_id`) and per-fault cost survive.
- Receipt validation, variance explanation, per-invoice reconciliation, receipt photos — all kept, and now apply to parts invoices too.
- The garage portal submission flow is unchanged; it just converts into a `purchase_invoice`.
- The parts request → approve → RFQ → purchase → deliver → install workflow is unchanged. Duplicate-spend detection keeps working — better, in fact, since it can then see garage-invoiced parts, which it is blind to today.
- Every existing reader of `maintenances.cost` keeps working, because that column survives as a derived cache.
- Historical data: nothing is deleted. The 310 sheet costs become visibly *unitemised legacy* invoices rather than being silently dropped or fabricated into fake part lines.

---

## 7. Where I'd push back: "no reconciliation layer"

Everything above holds for money that originates *inside* this system. It does not hold for `vehicle_expenses` — 28,327 rows, AED 26.9M, imported from Excel, and per the standing contract in `docs/Financial-Source-of-Truth.md` it is *the* source of vehicle expense.

Those rows are real payments that already happened, recorded by accounting, in free text, with no line structure. Three ways to treat them:

1. **Mirror (recommended).** Invoices are the operational truth; `vehicle_expenses` stays the accounting truth. They are compared, never summed. A reconciliation report shows "AED 7,000 paid to Power Point in May, no invoice recorded" — which is a genuinely useful control, not accounting overhead.
2. **Import.** Parse `remarks` into invoices. This means guessing part names and vendors out of free text — it manufactures data, and violates the "treat data as source of truth, no inference" rule you already hold elsewhere in this codebase.
3. **Ignore.** Report only on invoiced spend and accept that the number is a small fraction of real spend. Honest, but the Parts chart stays as unrepresentative as it is now.

So the model becomes "one invoice per dirham **we record**", with a thin mirror against the ledger of dirhams **already paid**. That is one reconciliation surface, at the system boundary — not the four internal ones you're removing.

---

## 8. Decisions I need from you

1. **Naming** — `purchase_invoices` (A), `cost_invoices` (B), or rename the revenue table (C)? *Recommend A.*
2. **`vehicle_expenses`** — mirror, import, or ignore? *Recommend mirror.*
3. **Scope of Phase 3** — do you want `maintenances.cost` genuinely write-blocked (correct, but it forces changes in `MaintenanceWorkflowService`, `WorkshopEventService` and the sheet importer in one go), or left writable with a deprecation warning for a release first?
4. **Legacy sheet costs** — one-line `labour` invoice flagged unitemised (my proposal), or a distinct `type = 'legacy'` so they never mix into labour reporting?
5. **`labor` vs `labour`** — the column value is `labor` today. Change the data, or just the UI label? *Recommend UI label only.*

---

## 9. Recommendation on the interim code

`PartSpendService` and `GET /part-requests/spend` should stay for now — they are ~120 lines, they make today's chart honest, and Phase 5 deletes them. Reverting them would leave the chart wrong for the length of this migration for no benefit. But they should not be extended, and no further reporting should be built on the multi-source pattern.
