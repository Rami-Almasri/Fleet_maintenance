# FleetView ⇄ Odoo — Integration Handoff

**Audience:** the Odoo developer joining this project.
**Written:** 2026-08-18 · **Status of the integration:** one direction half-built (outbound, read-only), one direction specified but not built (inbound expenses).

Read this document first. It is self-contained: you should be able to start work from it alone. Section 10 tells you which of the other ~82 project documents are worth your time and which are not.

---

## 1. What FleetView is

FleetView is the in-house fleet operations platform for **Faster Cars** (car rental, UAE). It is **not** an accounting system and it is **not** a rental booking system. Those two jobs already belong to other software:

| System | Owns | Relationship to FleetView |
|---|---|---|
| **OfficeManager (OM)** | Rental contracts, customers, which cars exist | **Upstream, authoritative.** FleetView pulls from it. Read-only replica. |
| **Odoo 18** (`sys.fastercars.ae`) | Accounting, vendor bills, petty cash | **Downstream today** (we prepare payloads for it). **Upstream tomorrow** (we want to read spend back from it). |
| **FleetView** | Vehicle condition, maintenance workflow, garages, parts, inspections, logistics | The operational layer between them. |

The one sentence to hold on to: **FleetView never invents a financial number.** Every cost figure it displays is read from a declared source through a single seam. Your work is on both ends of that seam.

### Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.2, Laravel 12 |
| Auth | Laravel Sanctum (token) + `spatie/laravel-permission` (RBAC, `resource.action` names) |
| Database | MySQL 8 in production; **MariaDB locally** (see §9 — this difference has caused real breakage) |
| Frontend | React 19, React Router 7, Tailwind 3, Axios |
| External | OfficeManager REST API, Google Sheets API (`google/apiclient`), Odoo 18 JSON-RPC |

### Scale (counted at the time of writing)

| | Count |
|---|---|
| Eloquent models | 110 |
| Service classes | 198 |
| HTTP controllers | 87 |
| Migrations | 287 |
| Artisan commands | 95 |
| API routes | 456 |
| Frontend pages | 104 |

This is a large system. You do **not** need to understand most of it. The Odoo surface is small and deliberately isolated — four files and two tables, listed in §4 and §5.

---

## 2. Where all the documentation lives

There are two separate bodies of writing, and only one of them is in the repository.

### 2a. In the repo — `../` (82 markdown files)

These are design documents, architecture specs, and audits, committed alongside the code. They are the shareable record. Full listing is just `ls docs/*.md`. The ones relevant to you are named in §10.

Also at the repo root:
- `ARCHITECTURE-CONVENTIONS.md` — coding conventions, layering rules, how services/controllers are expected to be written. **Read this if you will write PHP in this repo.**
- `DEPLOYMENT.md` — production deploy procedure, server layout, permissions.
- `backend/README.md`, `frontend/README.md` — local setup.

### 2b. Outside the repo — the working-memory notes (230 markdown files)

Path on the original developer's machine:

```
~/.claude/projects/C--Users-Rami-Almasri-Desktop-fleet-fullstack/memory/
```

These are short single-fact notes accumulated during development — architectural rulings, live hazards, "this looks fine but is actually broken" warnings. `MEMORY.md` in that folder is the index.

**They are not in git and are not automatically shared.** They contain the sharpest institutional knowledge in the project (the traps, the reversed decisions, the things that silently fail), but they are raw internal notes: terse, occasionally referencing credentials, and written for one reader. If you want them, ask for a curated export rather than the whole folder. The ones that bear on Odoo work have been folded into §9 of this document already.

---

## 3. How data gets into FleetView today

Understanding the existing inbound paths matters, because the Odoo expense feed is meant to slot into the **same seam** as one of them.

```
OfficeManager API  ──►  contracts, customers, which vehicles exist   (authoritative, scheduled sync)
Google Sheets      ──►  maintenance log, garage locations, vehicle log  (import + one write-back export)
Excel import       ──►  vehicle_expenses ledger                       (◄── THIS is the slot Odoo takes over)
Humans in the UI   ──►  maintenance tickets, faults, parts, inspections
```

Key identity rules you will need:

- The fleet identity key against OM is **`CarOwnerNo`**, *not* `TrafficID`. Getting this wrong silently matches the wrong car.
- The expense ledger joins on **`car_serial`** (OM `CarSerial`), resolved to a FleetView `vehicle_id` at import time. Unresolved rows are kept with `vehicle_id = null` rather than dropped.
- Never look up a vehicle by `where('plate_no')->first()` — plates are reassigned. There is a `PlateResolver` service for this.
- Currency throughout is **AED**.

---

## 4. Outbound: FleetView → Odoo (built, read-only)

This exists and works today. It does **not** push anything. It assembles finalised maintenance cost data into an Odoo-shaped payload that a human — or, later, your Odoo module — pulls on demand.

### The files

| File | Role |
|---|---|
| `backend/app/Services/OdooExportService.php` | Builds the payload. All mapping intent is documented in its class docblock. |
| `backend/app/Http/Controllers/OdooExportController.php` | Two read-only endpoints. |
| `backend/routes/api.php` (~line 1343) | Route definitions. |
| `backend/app/Models/MaintenanceLineItem.php` | The row being exported; carries the `odoo_*` sync columns. |

### The endpoints

```
GET /api/odoo-export/ticket/{ticket}      → one maintenance ticket
GET /api/odoo-export/contract/{contract}  → every ticket linked to one OM contract, with a grand total
```

Both require `auth:sanctum` **and** the `maintenance.manage` permission (the money owners). Responses are wrapped by the app's standard `ResponseHelper::SuccessResponse` envelope; the payload below is what sits in the data field.

### Ticket payload shape

```jsonc
{
  "source": "maintenance_ticket",
  "ticket_id": 1234,
  "vehicle_id": 88,
  "plate": "A 12345",
  "car": "Toyota Corolla",
  "contract_id": 5678,          // FleetView id of the linked OM contract
  "contract_no": "C-000123",    // the OM contract number — your analytic reference
  "garage": "Abu Maroof",
  "status": "final_qa",         // workflow_status
  "currency": "AED",
  "totals": { "parts": 450.00, "labor": 200.00, "grand": 650.00 },
  "is_itemized": true,
  "lines": [
    {
      "id": 9001,
      "kind": "part",            // "part" | "labor"
      "description": "Front brake pad set",
      "part_number": "04465-02220",
      "category_key": "brakes",
      "finding_text": "Grinding noise front left",   // WHY this money was spent
      "task_id": 77,
      "quantity": 1,
      "uom": "unit",             // "unit" | "hour" | "litre" …
      "unit_price": 450.00,
      "line_total": 450.00,
      "installed_on": "2026-08-01",
      "warranty_until": "2027-02-01",
      "entry_source": "manual",  // manual | ocr | import  (provenance)
      "odoo": {
        "product_ref": null,     // ── these three are the sync bookkeeping
        "external_id": null,     //    columns, empty until a push job
        "synced_at": null        //    fills them in. That job is yours.
      }
    }
  ],
  "sync": { "synced": false, "unsynced_lines": 1, "ready_to_push": true },
  "generated_at": "2026-08-18T10:00:00+00:00"
}
```

The contract payload is the same tickets nested under `{ source: "contract", contract_id, contract_no, totals, ticket_count, tickets: [...] }`, and it filters out tickets that carry no cost lines.

### Intended mapping into Odoo

Documented in the service, not enforced in code — deciding the real mapping is part of your job:

| FleetView | Odoo |
|---|---|
| line `kind: "part"` | vendor-bill line / BOM component (product, qty, unit price) |
| line `kind: "labor"` | expense / service product (hours × rate) |
| `category_key` | product category or analytic tag |
| `vehicle` | **per-asset analytic account** — this is what makes total-cost-of-ownership work |
| `finding_text` + task | the diagnostic justification carried alongside the line, so no cost is unexplained |
| `contract_no` | analytic reference back to the OM rental contract |

### Schema for the sync columns

On `maintenance_line_items` (migration `2026_06_30_120000_create_maintenance_line_items_table.php`):

```php
$table->string('odoo_product_ref')->nullable();    // → product.product / BOM component
$table->string('odoo_external_id')->nullable();    // → the created Odoo record's external id
$table->timestamp('odoo_synced_at')->nullable();   // → stamped by the push job
```

They already exist. A push job fills them in with **no migration needed**.

### What is deliberately NOT built

- No write/push to Odoo of any kind.
- No queue job, no scheduler entry, no retry/idempotency handling.
- Nothing sets `odoo_product_ref` / `odoo_external_id` / `odoo_synced_at` — the `sync` block in the payload therefore always reports `synced: false`.
- There is no product-catalogue mapping between FleetView `part_number` / `category_key` and Odoo products. **This is the single biggest open design question on the outbound side.**

---

## 5. Inbound: Odoo → FleetView (specified, not built)

This is the more valuable half and it is blocked on access, not on code.

### The seam

`backend/app/Contracts/VehicleExpenseProvider.php` is the **single interface through which FleetView reads vehicle expenses**. Read its docblock in full — it is the clearest statement of the rule. The short version:

> Everything cost-related the app displays — the expense total on Profitability, cost/km on Cost Intelligence, the expense drawer's history, every derived summary — is read through this contract and **nothing else**. No OfficeManager, no Power BI, no vouchers/accounts, no maintenance tables ever contribute an expense figure.

```
Excel → VehicleExpenseProvider → FleetView   (today)
Odoo  → VehicleExpenseProvider → FleetView   (the target)
```

Because consumers only know the interface, swapping the implementation changes **zero** UI and **zero** profit / cost-per-km calculation code.

### What you implement

A class `OdooVehicleExpenseProvider` implementing `VehicleExpenseProvider`. Use `backend/app/Services/Expenses/ExcelVehicleExpenseProvider.php` as the reference implementation. The interface requires nine methods:

| Method | Returns |
|---|---|
| `totalsByVehicle(?ids, ?from, ?to)` | `[vehicle_id => total AED]` — vehicles with no expense are **absent**, not `0` |
| `total(vehicleId, ?from, ?to)` | `?float` — `null` when unknown, **never a fabricated `0`** |
| `totalsByMonth(?from, ?to, ?ids)` | `['Y-m' => ['total' => float, 'lines' => int]]` |
| `history(vehicleId, ?from, ?to)` | every source line, oldest first, each flagged `excluded` or not |
| `linesByVehicle(?ids, ?from, ?to)` | bulk form of `history()` — exists so cost estimation never reaches past the seam |
| `source()` | `{label, available, as_of, lines}` — provenance shown in the UI |
| `exclusions()` | which categories are deliberately kept out of totals, **and why** |
| `freshness()` | is anyone still putting data in? (see the warning below) |

Three rules that are non-negotiable in this codebase:

1. **Unknown is `null`, never `0`.** A fabricated zero reads as "this car cost nothing", which is a lie.
2. **Cost ≠ every line.** Some ledger lines are not spend on the vehicle (e.g. a car hired in from another company and recharged through the same ledger). The aggregate methods must remove those; `history()` must still return them, flagged. `exclusions()` must name them so the UI can *say* what was taken out. Nothing is dropped silently.
3. **`freshness()` is not optional.** A frozen ledger fails silently — every figure derived from it keeps rendering, keeps looking precise, and describes a fleet that stopped existing months ago. This is not hypothetical here: see §9.

### The target table

`vehicle_expenses` (migration `2026_07_22_100000_create_vehicle_expenses_table.php`) — one row per source line:

| Column | Type | Meaning |
|---|---|---|
| `car_serial` | `unsignedBigInteger`, indexed | OM `CarSerial` — the join key |
| `vehicle_id` | nullable, indexed | resolved FleetView vehicle; `null` = unmatched, kept not dropped |
| `entry_date` | date, nullable, indexed | the voucher / expense date |
| `account_type` | string, nullable | e.g. `Expence` / `xExpence` |
| `remarks` | text, nullable | free-text line label (`CHANGE OIL`, `ENOC (PETROL)`…) |
| `debit` / `credit` | `decimal(14,2)` | as they arrive |
| `amount` | `decimal(14,2)` | `debit − credit`, the signed expense |
| `source` | string, indexed, default `'excel'` | **`'excel'` today, `'odoo'` for your rows** |
| `imported_at` | timestamp, nullable | when this batch landed (the source as-of) |

The `source` column is what lets a re-import replace only its own rows cleanly. **Tag every row you write `'odoo'`.** Do not touch `'excel'` rows.

Note that `remarks` is load-bearing beyond display: an `ExpenseCategoryClassifier` derives the operational category (insurance / tyres / oil …) **from the remark text**. Whatever field you map from Odoo into `remarks` determines how spend is categorised across the app. If categories look wrong after import, the fix is the classifier rules plus a re-run of `expenses:classify` — not a schema change.

### Odoo connection facts (verified 2026-07-23)

- **Odoo 18**, `https://sys.fastercars.ae`, database `new_db_faster_vip`.
- JSON-RPC verified end-to-end: `POST /web/session/authenticate` → `session_id` cookie → `POST /web/dataset/call_kw` (tested against `account.move` / `search_read`).

**The blocker, still open as far as this document knows:** the account used (uid 467) belongs only to company 9, *"Back Office - Faster"*. From there it can see **194 `move_type=entry` petty-cash journal entries** (journals CSH1 USD / CSH2 SYP) and **zero `in_invoice` vendor bills** — the bills live in the main operating company.

What was requested from the Odoo admin and needs confirming before inbound work can start:
1. Multi-company access to the company that holds the vendor bills.
2. Accounting read rights.
3. **A dedicated read-only API user.** (A personal password was shared in plaintext during testing and should be rotated.)

**Intended pull design once access exists:** incremental pull of *posted* `in_invoice` records by `write_date`, mapped per vehicle. The per-vehicle link is the open question — Odoo bills must carry something that resolves to `car_serial` (an analytic account per asset is the natural candidate, which dovetails with the outbound mapping in §4).

---

## 6. The financial rules you must not break

FleetView has a deliberate, and slightly unusual, financial model. Violating these produces numbers that look right and are wrong.

- **One write path per entity.** There is a test, `NoFinancialBypassTest`, that guards this. If you add a way to write cost, it will fail — correctly.
- **Revenue comes from contracts** (OM), not from FleetView. Net ≈ Gross in the current model.
- **A ticket can have many invoices**; a ticket's cost is a roll-up, not a single number.
- **The invoice is the designed financial source of truth**, but that design is only partly implemented — see `../Invoice-As-Financial-Source-of-Truth.md` versus `../Financial-Source-of-Truth.md`. Do not assume the design doc describes running code.
- **Money UI is feature-flagged** behind `SHOW_FINANCIALS`. The service log is deliberately money-free.
- **Every page must show its data origin.** This is a standing product rule: no black boxes. If you surface an Odoo-derived number, it must be able to say where it came from and how fresh it is. That is why `source()` and `freshness()` are on the provider interface.

---

## 7. Working with the API

- Auth: Sanctum bearer token. RBAC via `spatie/laravel-permission`, permission names in `resource.action` form (`maintenance.manage`, `registration.manage`, …). A `super-admin` role bypasses checks.
- Responses go through `ResponseHelper` — success and exception envelopes are uniform. `ResponseHelper::fromException($e)` is the standard catch.
- Routes are all in `backend/routes/api.php` (456 of them). It is long but flat and grouped by prefix; search it rather than reading it.
- Conventions for adding code — layering, where logic belongs, naming — are in `ARCHITECTURE-CONVENTIONS.md` at the repo root. Follow it; the codebase is consistent and reviewers will expect it.

---

## 8. Local setup

`backend/README.md` and `frontend/README.md` cover the normal path. Environment specifics:

- Live database is MySQL `laravel`, served locally via XAMPP. `php artisan db:backup` exists — **use it before anything destructive.**
- `OFFICEMANAGER_API_KEY` must be set in `.env` or `OfficeManagerClient` throws on construction.
- `secrets/README.md` explains credential handling. Do not commit credentials.

---

## 9. Traps — read before you touch anything

These are real, previously-observed failures, not theoretical risks. They are drawn from the working-memory notes described in §2b.

**Financial / data-quality**

- **The expense ledger is dying.** `vehicle_expenses` volume collapsed by roughly 99% after **March 2026**. Cost figures derived from it are effectively **historical**, not current. This is the concrete reason `freshness()` exists on the provider interface — and it is a large part of *why* the Odoo feed matters. Do not present ledger-derived costs as current without checking freshness.
- **The odometer has ~7 writers and no clear owner.** Highest reading wins. If you touch mileage-derived cost (cost/km), read `../data-pipeline-roadmap.md` first.
- **Schema-drift checks miss column types.** A drift check can report "matches exactly" while a column is `decimal(12,2)` on one side and `decimal(14,2)` on the other. Verify types by hand when precision matters — which, for money, is always. See `../Invoice-Precision-Drift.md`.

**Database / environment**

- **MariaDB locally vs MySQL 8 in production.** Migrations that pass locally can fail on the server. The known trap is CHECK-constraints-on-foreign-keys.
- **MariaDB silently adds `ON UPDATE CURRENT_TIMESTAMP`** to `timestamp()` columns. For a stored moment in time, use `dateTime()`. `odoo_synced_at` is a `timestamp()` — be aware.
- **Migrations cannot run from empty.** A fresh deploy from zero would currently fail. Rebuild test databases by *cloning*, never `migrate:fresh`.
- **`laravel_test` is destroyed by the CRUD suite** (`migrate:fresh` empties it), and it is already red at HEAD.
- **Never run `artisan migrate` against `fleet_test`** — its migration ledger is broken (133 tables, 1 migrations row). Add columns there by hand.
- **Port 8000 is contested** by other projects on this machine. If you get "route api/X not found", check which server owns the port *before* debugging routes.

**Process**

- **Rehearsals are not safe by default.** A "dry run" of a workflow sweep against the live `laravel` database once committed 56 real records. Rehearse against a private schema, not the live one.
- **Raw SQL against `maintenances` bypasses SoftDeletes.** Every raw query must declare whether it wants LIVE or HISTORICAL rows.
- **Sheet imports are unscheduled in production.** Data can look fresh while being stale. Do not assume a scheduler entry exists for any importer.

---

## 10. Reading order for the other docs

Of the 82 documents in `../`, these are the ones that pay for themselves for an Odoo integrator, roughly in order:

**Essential**
1. `../Vehicle-Expense-Provider.md` — the seam you are implementing (short).
2. `../maintenance-financial-architecture.md` — where the outbound bridge sits in the money model.
3. `../Cost-Source-of-Truth.md` — which number is authoritative and why.
4. `../Financial-Source-of-Truth.md` — the as-built financial picture.
5. `ARCHITECTURE-CONVENTIONS.md` (repo root) — before writing PHP here.

**Useful context**
6. `../Invoice-As-Financial-Source-of-Truth.md` — the *design* (note: not fully built).
7. `../Financial-Workflow-Architecture.md`, `../Financial-Layer-Redesign.md`.
8. `../Backend-Review-Schema-API-Architecture.md` — schema and API overview.
9. `../Parts-Purchase-Repair-Intelligence-Design.md` — where part identity and spend meet, relevant to product mapping.
10. `../Invoice-Precision-Drift.md` — decimal precision problems, directly relevant to money mapping.
11. `DEPLOYMENT.md` (repo root).

**Safe to skip.** The bulk of `../` covers maintenance intelligence, knowledge engines, recurrence metrics, the asset layer, and demo scripts. Well-written and irrelevant to accounting integration. Skip anything named *Knowledge*, *Intelligence*, *Recurrence*, *Concept-Bridge*, *Asset-Layer*, or *Demo* unless something points you there.

---

## 11. Summary of open work

| # | Work | Status | Blocked on |
|---|---|---|---|
| 1 | Get read access to vendor bills in the operating company; issue a dedicated read-only API user; rotate the shared password | **Blocking everything inbound** | Odoo admin |
| 2 | Decide the per-vehicle link in Odoo (analytic account per asset ↔ `car_serial`) | Not started | #1, plus a joint decision |
| 3 | Build `OdooVehicleExpenseProvider` implementing `VehicleExpenseProvider`; incremental pull of posted `in_invoice` by `write_date`; write rows with `source = 'odoo'` | Not started | #1, #2 |
| 4 | Map FleetView `part_number` / `category_key` to an Odoo product catalogue | Not started | design decision |
| 5 | Build the outbound push job that consumes `/api/odoo-export/*` and fills `odoo_product_ref`, `odoo_external_id`, `odoo_synced_at` (needs idempotency) | Not started | #4 |
| 6 | Schedule + monitor both directions, with freshness surfaced in the UI | Not started | #3, #5 |

Items 1–3 are the valuable half and are worth doing first: they replace a ledger that has been effectively dead since March 2026. Items 4–5 are a convenience for the accounting team and can follow.

---

*Questions this document cannot answer should go to the FleetView maintainer. Anything in it that contradicts the code — trust the code, and please correct this file.*
