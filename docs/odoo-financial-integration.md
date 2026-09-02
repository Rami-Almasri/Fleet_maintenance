# Odoo Financial Integration

FleetView stays the operational source of truth. Odoo stays the financial authority. This document
records the decisions that are not obvious from the code, and the configuration a Finance/Odoo
administrator still has to complete.

## The shape

```
Operational workflow          FinancialEvent          Odoo
─────────────────────         ──────────────          ────
garage invoice        ──┐
recovery leg          ──┼──►  raise (upsert)   ──►  validate  ──►  freeze snapshot
                        │                                          + status SENDING
                        │                                                │
                        │                                          search by ref
                        │                                                │
                        │                                     ┌──────────┴──────────┐
                        │                                  found?                 not found?
                        │                                  LINK                    CREATE
                        │                                     └──────────┬──────────┘
                        └────────────────────────────────────────►  SYNCED + odoo id
```

## Decisions worth knowing

**Expense account, document type and expense type are three separate things.** `REPAIR` is not "a
vendor bill"; it is a repair that today happens to be recorded as one. Both answers live on
`expense_type_mappings`, one row per type, editable by Finance without a deploy. `config/odoo.php`
seeds that board once and is never read again.

**A mapping is a decision, never a match.** `odoo_mappings` holds the explicit answer for
vehicle→analytic account, part→product and supplier→partner. Fuzzy matching may *propose*
(`status = suggested`) and the validator treats a proposal as absent. Only a human choice or an exact
match on a stable external reference produces `status = mapped`.

**Idempotency is a search, not a flag.** Every document carries the event's
`FLEETVIEW-FE-<uuid>` in Odoo's own `ref` (`reference` for `hr.expense`). `OdooDocumentPusher` searches
for it before *every* create, including the first. Transport-level HTTP retries are disabled for
writes — retrying a `create` at that layer would bypass the search and duplicate a bill.

**`SENDING` is committed before the RPC leaves.** A crash mid-push therefore leaves a row that says
"a document may exist and we do not know", and the only way out is `reconcile()`, which asks Odoo.
There is deliberately no `SENDING → READY` transition.

**A synced event is frozen.** Editing the bill afterwards changes the ticket's cost in FleetView and
leaves the posted document alone. Corrections are credit notes raised in Odoo.

**Operational completion ≠ financial readiness (§8).** Nothing in the validation path is reachable
from a workflow transition. A car is repaired, closed and back on the road whether or not the
bookkeeping is done.

**Bills are created as drafts and not posted.** Posting is an accounting decision belonging to whoever
owns the books.

**Tax is not sent.** Lines carry net amounts; Odoo applies its own tax rules. VAT/discount bands on a
garage invoice are excluded from the postable lines, so the event total reconciles against the *work*
total.

## Expense type → account → document type → producer

| Expense type | Odoo account (intended)                            | Document    | Operational producer |
|--------------|----------------------------------------------------|-------------|----------------------|
| REPAIR       | Fleets Maintenance \| Repair Maintenance Expenses   | Vendor Bill | `MaintenanceInvoice` on a breakdown ticket |
| ROUTINE      | Fleets Maintenance \| Routine Maintenance Expenses  | Vendor Bill | `MaintenanceInvoice` on a routine ticket |
| RECOVERY     | Fleets Maintenance \| Recovery Maintenance Expenses | Vendor Bill | `Maintenance.recovery_*` — the tow leg |
| FUEL         | Fuel Expenses \| Fuel for Maintenance               | Vendor Bill | `FuelFill` — litres + odometer + receipt |
| REGISTRATION | Fleet Admin Expenses \| Registration                | Expense     | `VehicleRegistration.renewal_*` |
| CAR_WASH     | Fleets Maintenance \| Car Washing Expenses          | Vendor Bill | `VehicleWashJob` (external washes only) |
| TAXI         | Fleet Admin Expenses \| Public Transportation - TAXI| Expense     | `LogisticsTask.taxi_*` — the driver's fare |

Account names are **display only**. The identifier is `odoo_account_id`, deliberately **not seeded** —
inventing an Odoo id would post real money to the wrong account.

**Every cost attaches to the record that caused it**, never to a finance table. A renewal belongs to the
registration it renewed; a fare belongs to the journey that required it; a wash sets the car's cleaning
status through the same field Booking Readiness already reads. Two new tables exist (`fuel_fills`,
`vehicle_wash_jobs`) because those *actions* had no record at all — a fill has litres and an odometer,
a wash has a type and a history — not because finance needed somewhere to put a number.

## What is mandatory per type

Requirements come from the document type (`OdooDocumentType::REQUIREMENTS`), with a per-type override on
`expense_type_mappings.requires_attachment` (null = follow the default).

| Document    | Supplier + Odoo partner | Invoice/receipt no. | Date | Attached document |
|-------------|-------------------------|---------------------|------|-------------------|
| Vendor Bill | required                | required            | required | **required** |
| Expense     | not required            | not required        | required | **required** |

So: REPAIR, ROUTINE, RECOVERY, FUEL and CAR_WASH each need a mapped supplier, a document number, a date
and the scan. REGISTRATION and TAXI need a date and the receipt, plus `ODOO_EXPENSE_EMPLOYEE_ID`.
A vehicle analytic account is required for every type except TAXI, whose cost is the driver's transport
rather than a car's running cost — where a car is known the analytic account is still sent.

## Still to configure (nothing syncs until this is done)

1. **Credentials** — `ODOO_URL`, `ODOO_DATABASE`, `ODOO_USERNAME`, `ODOO_PASSWORD`
   (optionally `ODOO_COMPANY_ID`, `ODOO_API_VERSION`, `ODOO_DOCUMENT_URL_TEMPLATE`).
2. **`php artisan odoo:pull-master-data`** — until this runs every mapping picker is empty.
3. **Expense accounts** — resolve `odoo_account_id` for all seven types on `/odoo-mappings`.
4. **`ODOO_EXPENSE_EMPLOYEE_ID`** — required for any type recorded as an Expense (REGISTRATION, TAXI).
5. **Vehicle → analytic account** mappings for every eligible car.
6. **Supplier → partner** mappings for every garage/recovery company that bills us.
7. **Part → product** mappings for every catalogue part that appears on a bill.
8. **`php artisan odoo:rebuild-events`** — backfill obligations for existing bills.

Optional: schedule `odoo:sync-events` once the mappings are settled. It is deliberately unscheduled by
default — an unattended pusher plus one mis-mapped account is three hundred wrong bills.

## Commands

| Command | What it does |
|---------|--------------|
| `odoo:pull-master-data` | Pull accounts / analytic accounts / products / partners into the picker cache. Read-only against Odoo. |
| `odoo:rebuild-events` | Rebuild obligations from garage invoices and recovery legs. Never sends. |
| `odoo:sync-events --mode=ready\|failed\|stranded\|all [--dry-run]` | Send / retry / reconcile. |
| `odoo:verify [--push]` | Walk the REAL path against the configured Odoo: connection → master data → account mappings → events → (with `--push`) create one document and prove the retry links rather than duplicates. |

## Mapping states shown on `/odoo-mappings`

| State | Posts? | Meaning |
|-------|--------|---------|
| `unmapped` | no | nobody has looked at this yet — it is work |
| `suggested` | **no** | a matcher proposed it; no human confirmed it |
| `mapped` | yes | confirmed and stable |
| `changed` | yes | confirmed, but re-pointed at least once — previously-posted documents were coded against the old target |
| `stale` | no | the last master-data pull could not find the target in Odoo |
| `none` | no | somebody looked and the answer is legitimately "no counterpart" — it leaves the backlog |

Only `mapped` and `changed` satisfy the validator. A suggestion is never enough: §18's "Brake Pad Front"
vs "Front Brake Pads" is exactly why a name may propose but never decide.
