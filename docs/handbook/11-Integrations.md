# Integrations

Every system FleetView talks to, what it trusts each one for, and where the data lands.

```
OfficeManager API  -->  which vehicles exist, contracts, customers   AUTHORITATIVE
Google Sheets      -->  maintenance log, vehicle status, registrations, insurance,
                        oil change, garage locations, customer cases    (+ one write-back)
Excel import       -->  vehicle_expenses ledger
Odoo 18            -->  (planned) replaces the Excel expense ledger; (built) export payload out
Power BI           -->  configured, but NOT a source of any cost figure
```

---

## 1. OfficeManager (OM) — the upstream authority

**Code:** `app/Services/OfficeManagerClient.php`, `OfficeManagerSync.php`, `ContractImporter.php`, `CustomerImporter.php`
**Config:** `config/officemanager.php`
**Command:** `om:sync`

A REST API authenticated with an `X-API-Key` header; list endpoints page with `?page&page_size`. The client **throws on construction** if `OFFICEMANAGER_API_KEY` is missing.

### What OM is authoritative for

| Fact | Authority |
|---|---|
| Which vehicles exist | **OM, solely.** You do not create vehicles in FleetView. |
| Rental contracts | **OM, solely** — except type-U maintenance contracts, which FleetView creates. |
| Customers | OM |
| Revenue | Derived from OM contracts |

### The two timeout profiles — this matters

`OfficeManagerClient::req()` takes an `$interactive` flag:

| Profile | Connect | Timeout | Retries | Use for |
|---|---|---|---|---|
| **Interactive** (`true`) | ~4s | ~8s | 1, no sleep | Live web requests where a user is waiting. **Fails fast** so a slow OM endpoint cannot hang `artisan serve` past PHP's max execution time. |
| **Batch** (default) | ~30s | ~300s | 3, spaced ~30s | Sync commands. The OM server is fragile under load, so waiting a long time between attempts is deliberate — a retry storm must never look like an attack. |

**Using the batch profile inside a web request will hang the dev server.** Pass `interactive: true` for anything user-facing.

### Identity rules

- The fleet identity key is **`CarOwnerNo`**, *not* `TrafficID`. Getting this wrong silently matches the wrong car.
- **Never** resolve a vehicle with `where('plate_no')->first()` — plates are reassigned. Use `PlateResolver`.
- Contract ↔ maintenance linking is a strict **vehicle + date-window** match. No match means **"No Log"** — never a guess.
- The expense ledger joins on **`car_serial`** (OM `CarSerial`), resolved to `vehicle_id` at import. Unresolved rows are **kept** with `vehicle_id = null`, not dropped.

### ⚠️ Read-only replica

The OM API is a **read-only replica**. FleetView cannot push invoices or anything else back into it.

---

## 2. Google Sheets

**Code:** `app/Services/GoogleSheetsService.php` (**read–write**), `MaintenanceSheetImporter.php`, `GarageLocationSheetImporter.php`, `VehicleLogSheetExporter.php`
**Config:** `config/google.php`, credentials via `GOOGLE_SHEETS_CREDENTIALS`

Most flows are read-only imports, but `GoogleSheetsService` **can write**, and the vehicle-log exporter uses that to push back.

### The sheets in use

Each has its own spreadsheet id and tab (gid) in `.env`:

| Env prefix | Feeds |
|---|---|
| `GOOGLE_SHEETS_MAINTENANCE_ID` | the maintenance log |
| `GOOGLE_SHEETS_CARS_ID` / `_GID` / `_HEADER_ROW` | vehicle master data (make, model, colour, purchase price) |
| `GOOGLE_SHEETS_CONTRACTS_ID` / `_GID` | contracts |
| `GOOGLE_SHEETS_CUSTOMERS_ID` / `_GID` | customers |
| `GOOGLE_SHEETS_REGISTRATIONS_ID` / `_GID` | registration + fines ("F RTA" tab) |
| `GOOGLE_SHEETS_INSURANCE_ID` / `_GID` | insurance |
| `GOOGLE_SHEETS_OIL_CHANGE_GID` | oil-change records |
| `GOOGLE_SHEETS_ASSET_ID` / `_GID` | asset layer |
| `GOOGLE_SHEETS_EVENTS_ID` / `_TAB` | vehicle log events (the write-back target) |

### ⚠️ Sheet imports are not reliably scheduled in production

Some importers have no active scheduled run on the server. **Data can look fresh while being months stale.** Never assume an importer is running — check the scheduler and the last-import timestamp. See [12-Operations-Runbook.md](12-Operations-Runbook.md).

---

## 3. The expense ledger (Excel today, Odoo planned)

**Code:** `app/Contracts/VehicleExpenseProvider.php` (the interface), `app/Services/Expenses/ExcelVehicleExpenseProvider.php` (today's implementation), `ExpenseCategoryClassifier.php`
**Table:** `vehicle_expenses`
**Config:** `config/expenses.php`

One row per source line: date, remarks, debit, credit, amount (`debit − credit`), `car_serial`, resolved `vehicle_id`, and a **`source`** tag (`'excel'` today, `'odoo'` later) so a re-import replaces only its own rows cleanly.

The **`remarks`** free-text column is load-bearing: `ExpenseCategoryClassifier` derives the operational category (insurance / tyres / oil / fuel …) from it. If categories look wrong, fix the classifier rules and re-run `expenses:classify` — it is not a schema problem.

⚠️ **This ledger collapsed by ~99% after March 2026.** Anything derived from it is historical. See [10-Business-Rules.md](10-Business-Rules.md).

---

## 4. Odoo 18

Covered in full in **[16-Odoo-Integration.md](16-Odoo-Integration.md)**. Summary:

| Direction | Status |
|---|---|
| **FleetView → Odoo** | **Built, read-only.** `OdooExportService` assembles finalised parts + labour into an Odoo-shaped payload, served at `GET /api/odoo-export/ticket/{ticket}` and `/contract/{contract}` (permission `maintenance.manage`). **Nothing is pushed.** The `odoo_*` columns on `maintenance_line_items` are reserved for a future push job and are currently always empty. |
| **Odoo → FleetView** | **Specified, not built.** An `OdooVehicleExpenseProvider` implementing the expense seam. **Blocked on access**, not on code. |

Connection facts (verified 2026-07-23): Odoo 18 at `https://sys.fastercars.ae`, database `new_db_faster_vip`, JSON-RPC via `POST /web/session/authenticate` then `POST /web/dataset/call_kw`.

**The blocker:** the API user is scoped to company 9 "Back Office - Faster" and can see only 194 petty-cash journal entries — **zero vendor bills**, which live in the main operating company. Needed: multi-company access, accounting read rights, and a dedicated read-only API user. (A personal password was shared in plaintext during testing and should be rotated.)

---

## 5. Power BI

**Config:** `POWERBI_ENABLED`, `POWERBI_CLIENT_ID`, `POWERBI_CLIENT_SECRET`, `POWERBI_TENANT_ID`, `POWERBI_WORKSPACE_ID`, `POWERBI_DATASET_ID`

Configured, but note the standing rule: **Power BI is explicitly not a source of any expense figure.** Only `VehicleExpenseProvider` is.

---

## 6. Anthropic API (keyword enrichment)

**Config:** `ANTHROPIC_API_KEY`, `KEYWORD_AI_MODEL`, `KEYWORD_AI_EFFORT`, `config/keyword_ai.php`

Used for keyword/ontology enrichment runs (`KeywordEnrichmentRun`). Note that the shipped ontology (105 nodes) is **seeded, not AI-generated** — the system runs without an API key, and the AI path is an enrichment extra rather than a dependency.

---

## 7. Sync auditing

**Models:** `SyncRun`, `SyncChange`, `SyncCorrection`

Every sync records what it did and what changed, with autocorrect and a retention/prune path. This exists because silent sync drift was a repeated, real problem.

Related commands live under the `sync:` and `om:` namespaces — see [07-Artisan-Commands.md](07-Artisan-Commands.md). `sync.run` is a gated permission (admin, manager, super-admin only).

---

## Integration rules to carry with you

1. **Declare the authority.** Where two systems disagree, the code must name the winner. Do not average, do not guess.
2. **Unmatched is kept, not dropped.** An expense line that does not resolve to a vehicle stays in the table with a null `vehicle_id`.
3. **Tag your source.** Anything written by an importer carries a `source` so it can be replaced cleanly.
4. **Fail fast in web requests, be patient in batch.** See the OM timeout profiles.
5. **Freshness is part of the contract.** A source that stopped updating must be able to say so.
