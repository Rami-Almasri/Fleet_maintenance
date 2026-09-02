# FleetView — Financial Workflow Architecture

**Status:** ✅ **VERSION 1.0 — FROZEN** (2026-08-05) · Architectural contract · Enforced by tests
**Enforced by:** `backend/tests/Crud/NoFinancialBypassTest.php` (21 tests) — the executable form of this document
**Companion to:** `docs/Cost-Source-of-Truth.md` (superseded audit), `docs/Vehicle-Expense-Provider.md` (a
different ledger — vehicle expenses, not repair money)

> This document defines how money attaches to a repair. It is a **contract**, not a description: the rules
> below are enforced by tests that fail the build when a new write path appears. Do not add a financial
> write path without changing this document and that test together.

---

## 0. The one rule

> **Every dirham on a ticket traces to a source document, and every financial change has exactly one write
> path.**

Two corollaries that decide most design arguments:

1. **Totals are summed from rows, never typed beside them.** A ticket's `cost` is the sum of its
   `maintenance_line_items`. An invoice's `amount` is the sum of its lines. A bill's `paid_amount` is the
   sum of its payment allocations. Anywhere a total is stored, exactly one function derives it.
2. **A figure nobody can explain is reported, never hidden.** Untraceable money stays visible and is
   labelled untraceable. Deleting it, or quietly excluding it from a total, would be a worse error than
   admitting it — the ticket would stop reconciling against the garage's paper.

---

## 1. The four source documents

There are exactly **four** things that can back an amount. Anything else is `unsourced`.

| Document | Table | Owns | Photo |
|---|---|---|---|
| **Supplier invoice** | `part_invoices` | What a supplier charged for a part | ✅ |
| **Garage invoice** | `maintenance_invoices` | What a garage charged — labour, and any part *it* supplied | ✅ |
| **Credit note** | `part_returns` | A part that went back, and the money that came with it | — |
| **Adjustment** | `cost_adjustments` | Money that moved without supplier or garage paper | ✅ |

`CostSourceResolver` classifies every line into one of these or into `unsourced`, and
`maintenance_line_items.source_type` / `source_id` denormalise the answer so spend is reportable by
document with a plain join.

### The anti-double-count rule

```
purchase_source = supplier  →  part_invoice        (a separate financial event)
purchase_source = garage    →  maintenance_invoice (already a line on the garage's bill)
```

A garage-supplied part **never** gets a supplier invoice. `PartInvoiceService::assertAttachable()`
refuses it — the rule is enforced, not documented.

### Allocation

A supplier invoice is **not owned by a ticket**. One counter trip buys parts for three cars. The document
exists once; each ticket shows its **allocated share** (from `part_purchases.maintenance_id`) with the
document total as clearly-labelled context. Showing the document total as the ticket's part cost would
over-state every shared invoice.

The same shape applies to payments: one transfer settles many bills through `payment_allocations`.

---

## 2. The financial state machine

### 2.1 Two independent axes

A repair has an **operational** state (where is the car?) and a **financial** state (is the money
accounted for?). Conflating them is what produced the original mess.

```
OPERATIONAL  pending_review → inspection → awaiting_dispatch → in_transit → under_repair
             → ready_for_pickup → in_our_park → ready_reinspection → …

FINANCIAL    (per document)  draft → pending → approved → paid
                                        ↘ cancelled
             (derived)       partially_paid · partially_refunded · refunded
```

Document status lives in `App\Support\FinancialDocumentStatus`, shared by both invoice kinds through the
`IsFinancialDocument` trait.

**The stored status only ever holds a decision somebody took.** Anything that is a statement about money —
`partially_paid`, `partially_refunded`, `refunded` — is **derived at read time** from `paid_amount` and the
credit notes against the document. A status can therefore never contradict the figures beside it.

### 2.2 Where the two axes meet: closing a ticket

```
                    ┌──────────────────────────────────────────┐
   operational      │  car is back, faults resolved            │
   completion   ──▶ │  MaintenanceWorkflowService::close()     │
                    └───────────────────┬──────────────────────┘
                                        │
                         FinancialCompletenessService::check()
                                        │
                    ┌───────────────────┴───────────────────┐
                    │                                       │
             complete = true                        complete = false
                    │                                       │
                    ▼                                       ▼
             ┌─────────────┐                    ┌────────────────────────┐
             │  WF_CLOSED  │                    │ WF_AWAITING_INVOICE    │
             │             │                    │ awaiting_invoice_since │
             │ money is    │                    │ stamped                │
             │ accounted   │                    │                        │
             │ for         │                    │ operational work DONE  │
             └─────────────┘                    │ money still open       │
                                                └───────────┬────────────┘
                                                            │
                                              document it / adjust it
                                                            │
                                                            ▼
                                          MaintenanceWorkflowService::finalizeInvoice()
                                             (runs the SAME completeness check)
                                                            │
                                                     ┌─────────────┐
                                                     │  WF_CLOSED  │
                                                     └─────────────┘
```

**Why route rather than block.** The car is physically back. Blocking the operational close would create
pressure to work around the system, and a gate people route around protects nothing. Separating the two
completions keeps `CLOSED` meaning *"the money is accounted for"* while letting operations finish.

If `WF_AWAITING_INVOICE` is not reachable from the ticket's current state, `close()` **refuses outright** —
there is no silent close.

### 2.3 What blocks financial completion

`FinancialCompletenessService::check()` returns **blockers** (must be resolved) and **warnings** (shown,
never blocking).

**Blockers — a ticket may not reach CLOSED with any of these:**

| Code | Meaning | The fix |
|---|---|---|
| `supplier_invoice_missing` | A part was bought from a supplier with no invoice recorded | Record the supplier invoice |
| `supplier_invoice_unapproved` | A supplier bill is still draft/pending | Approve it — a draft is not an accepted obligation |
| `garage_invoice_unapproved` | A garage bill is still draft/pending | Approve it |
| `return_unsettled` | A part went back but the refund never resolved | Record the refund, or mark it rejected |
| `lump_sum_cost` | A total typed onto the ticket with no lines behind it | Itemise it, or record an adjustment explaining it |
| `line_unsourced` | A real line never attached to any document | Attach it to its invoice, or adjust |

**Warnings — never block:**

| Code | Why it does not block |
|---|---|
| `supplier_invoice_unpaid` | Payment terms are the supplier's business, not the repair's |
| `garage_invoice_unpaid` | Same |

Holding a car's ticket open over supplier payment terms would teach people to work around the gate.

### 2.4 The two escape hatches — and there is no third

Every blocker has exactly two legitimate resolutions:

1. **Attach the real document** — supplier invoice, garage invoice, or credit note.
2. **Record an adjustment** — `cost_adjustments`, which demands a reason code, a written explanation and a
   named approver, and may carry a photo.

> **There is deliberately no third option where someone types a number and moves on.**
> `guardTypedCost()` is the single implementation of that rule, called by both `close()` and
> `recordCost()`, so the closing screen and the deferred-cost screen cannot disagree.

---

## 3. Single write owner per entity

**One authoritative writer per financial entity.** Enforced by
`NoFinancialBypassTest::test_each_financial_entity_has_exactly_one_authoritative_writer`, which scans
Services, Controllers, Console commands, Listeners and Observers.

| Entity | Table | **Sole writer** | Notes |
|---|---|---|---|
| **Garage invoice** | `maintenance_invoices` | `MaintenanceInvoiceService` | create · update · delete · reconcile |
| **Supplier invoice** | `part_invoices` | `PartInvoiceService` | supplier-only; refuses garage-sourced parts |
| **Part purchase** | `part_purchases` | `PartWorkflowService` | request → approve → purchase → deliver → install |
| **Return / credit note** | `part_returns` | `PartReturnService` | only `refunded` writes the credit line |
| **Cost adjustment** | `cost_adjustments` | `CostAdjustmentService` | reason + explanation + approver all mandatory |
| **Supplier payment** | `supplier_payments` | `SupplierPaymentService` | |
| **Payment allocation** | `payment_allocations` | `SupplierPaymentService` | sole writer of every `paid_amount` |
| **Cost line** | `maintenance_line_items` | **four** writers — one per source document | see below |

### 3.1 Why the cost line has four writers

A cost line is the one ledger every source document writes into. There are four documents, so there are
four writers — and each attaches its own `source_type`:

| Writer | Writes | `source_type` |
|---|---|---|
| `MaintenanceInvoiceService` | parts, labour, VAT, discount | `garage_invoice` |
| `PartWorkflowService` | the part line, on install | `supplier_invoice` (or null until invoiced) |
| `PartReturnService` | the negative credit line | `credit_note` |
| `CostAdjustmentService` | the correction line | `adjustment` |

`PartInvoiceService` fills in the origin when an invoice is attached **after** the part was fitted, and
**clears it on detach** — a line must never point at a document that no longer backs it.

### 3.2 Derived totals — one function each

| Total | Derived by | From |
|---|---|---|
| `maintenances.cost` | `Maintenance::recalcLineItemTotals()` | sum of **all** its line items |
| `maintenance_tasks.parts_cost` / `labor_cost` | `MaintenanceTask::recalcCosts()` | its line items by kind |
| `maintenance_invoices.amount` | `MaintenanceInvoice::recalcTotals()` | its lines (parts + labor + vat + discount) |
| `part_invoices.total_amount` | `PartInvoice::recalcTotals()` | attached purchases + tax − discount |
| `*.paid_amount` | `SupplierPaymentService::recalcDocument()` | live payment allocations |
| `part_purchases` net cost | `PartPurchase::netCost()` | gross − refunded |

**Live invariant, verified:** `0` itemised tickets where `cost != SUM(line_total)`.

---

## 4. What was removed to get here

Documented so nobody re-introduces it believing it was an oversight.

| Removed / fixed | Why it was a bypass |
|---|---|
| `MaintenanceWorkflowService::applyLineItems` (old body) | Wrote cost lines with **no `maintenance_invoice_id`** — untraceable by construction. Source of the AED 13,685 of "lines never attached to any invoice". **Deleted**, not deprecated. |
| `GarageInvoiceService` no-vendor fallback | Fell through to the above. Now creates an **internal** invoice. |
| `close()` typed cost | Set `$ticket->cost` directly, bypassing the `recordCost` gate. Now shares `guardTypedCost()`. |
| `close()` ungated closure | Reached `WF_CLOSED` with no completeness check. Now routes to `WF_AWAITING_INVOICE`. |
| Demo writers | `parts:demo-seed`, `parts:demo-approval`, `VehicleComponentDemoSeeder` created purchases with no environment guard. Now refused on production **and** on any database named `laravel`/`fleet`/`production` (`Concerns\GuardsDemoWrites`) — because a local `.env` pointed at live is the realistic accident. |

**Audited and cleared (not bypasses):** no scheduler exists · no `app/Jobs` · listeners write no financial
rows · controllers only read · `InvoiceObserver` is customer *rental* billing · `maintenance:backfill-tasks`
only sets `maintenance_task_id` (attribution, never amount or source) · `ImportVehicleExpenses` writes
`vehicle_expenses`, a different ledger.

---

## 5. Legacy data — marked, never faked

At the time of writing, **AED 173,934.50 across 354 tickets has no source document.** It predates the
documents and never will have one.

**It is not being back-filled with invented invoices.** Every pre-rollout cost is stamped
`maintenances.cost_legacy_at`, which separates two things that look identical in a total:

| State | Meaning |
|---|---|
| `verified` | Every amount traces to a document |
| `legacy_unverified` | Recorded before the rules existed — a known, bounded, shrinking backlog |
| `unverified` | Recorded **after** the rules with no document — the gates should make this impossible, so any non-zero figure is a live problem |

Collapsing the last two would leave management staring at a number that never improves while hiding the one
case that needs chasing.

Progress is a **trend**, not a fact: `cost:traceability-snapshot` stores a daily measurement;
`/cost-verification/{summary,queue,spend-by-category}` reports it; the migration queue is ordered by
undocumented value so cleanup starts where it moves the metric most.

**Baseline 2026-08-05:** total 173,934.50 · verified 0 · legacy 173,934.50 · **post-rules unverified 0.00** ·
coverage 0%.

---

## 6. The guardrail

`backend/tests/Crud/NoFinancialBypassTest.php` — **21 tests, permanent.**

It asserts:

- One authoritative writer per entity (data-provider over all eight entities)
- No console command may write an amount or a provenance column
- The line-items endpoint produces a real invoice, and every amount it creates is traceable
- Editing lines reuses the same invoice rather than stacking documents
- A ticket with two invoices refuses the ambiguous "replace all lines"
- A typed total is refused over an itemised ticket — at the cost screen **and** at the closing screen
- Closing with undocumented money parks the ticket in `AWAITING_INVOICE`
- Closing with an approved, documented bill really closes
- An unapproved bill holds the close open
- `markReady` with line items also produces a document
- **The ticket total is always the sum of its lines** — the invariant every displayed figure rests on

> If you are reading this because the test failed: you have added a second write path. That is the test
> working. Route your change through the owning service in §3, or change this document and the test
> together — deliberately, in one commit.

### Running it

```bash
# The financial guardrail alone
php artisan test --filter NoFinancialBypassTest

# Fleet-wide traceability audit (non-zero exit when money is undocumented — CI-gateable)
php artisan cost:trace-audit
php artisan cost:traceability-snapshot
```

> ⚠️ The CRUD suite uses `RefreshDatabase` against a shared schema. When another session is working the
> same repo, run on your own: `DB_DATABASE=laravel_scratch php vendor/bin/phpunit -c phpunit.crud.xml`.

---

## 7. Roadmap from here

Built on this foundation, in order — **not** in parallel with it:

1. **Payment terms** per supplier, so payables age against agreed terms rather than invoice date
2. **Supplier statements** — a period view per payee reconciling bills, credits and payments
3. **PO workflow** — purchase-order issuance from an RFQ award

Each extends the entities in §3 through their existing owners. None introduces a new write path.
