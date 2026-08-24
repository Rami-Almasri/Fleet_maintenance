# Glossary

Terms, roles, systems and status values you will meet in the code, the UI and the older documents.

---

## Systems

| Term | Meaning |
|---|---|
| **FleetView** | This system. The fleet **operations** platform. |
| **OfficeManager** / **OM** | The external rental system. **Authoritative** for which vehicles exist, contracts and customers. Read-only replica from FleetView's side. |
| **Odoo** | The external accounting system (Odoo 18). Downstream today, intended upstream source of vehicle expense. |
| **Power BI** | Reporting tool. Explicitly **not** a permitted source of any expense figure. |
| **N-Maintenance sheet** | The legacy Google Sheet the maintenance log grew out of. Its vocabulary still shapes some fields. |

## People and roles

| Term | Meaning |
|---|---|
| **Inspector** (*Abu Maroof*) | Diagnoses faults, files inspection reports, triages complaints, performs the closing re-inspection. |
| **Controllers** (*Lin*, *Marwa*) | Own money and review gates. Permission `maintenance.manage`. |
| **Supervisor** | Chooses the garage, assigns drivers, signs off transfers and failed re-inspections. |
| **Coordinator** | Reviews an inspection report before it becomes an active repair (`recommendation_pending`). |
| **Driver / Logistics** | Moves vehicles, captures odometer at pickup, relays garage status. |
| **Garage / Vendor** | External repair shop. Repairs are outsourced. `Vendor` also covers parts suppliers. |
| **Sales** | Gates certain flows — e.g. the "Sales OK" required before an oil recall can pull a car from a customer. |

## Core concepts

| Term | Meaning |
|---|---|
| **Ticket** | A maintenance job. Physically a `maintenances` row with a `workflow_status`. |
| **Task** | A container of faults inside a ticket. **Single-garage.** |
| **Fault** | Something broken. |
| **Service** | Planned/preventive work (oil change, filter). **A different domain from a fault.** |
| **Damage** | Something done *to* the car (impact, accident). A third, separate domain. |
| **Finding** | A diagnosed observation from the findings catalog; what a cost line is justified by. |
| **Fenced state** | A workflow state deliberately excluded from "in maintenance", so the ticket is open but the car is still rentable. |
| **Grounding vs deferrable** | A grounding fault stops the car being rented; a deferrable one does not. |
| **Comeback** | A car returning for the same fault — the key garage-quality signal. |
| **Stint / shop stay** | A continuous period at a garage. `shopStay()` is the canonical answer to "is this car at a garage". |
| **Handover** | Custody transfer capture: odometer, fuel, condition, damage, signature. |
| **Type-U contract** | A maintenance contract representing a car off-rent because it is being worked on. **FleetView creates these** (unlike rental contracts). |
| **Roll-up** | A cost derived by summing its parts rather than stored as a scalar. A ticket's cost is a roll-up. |
| **Seam** | An interface that isolates a data source expected to change — e.g. `VehicleExpenseProvider`. |
| **Choke point** | A single enforced write path for an entity. |
| **Data Origin** | The mandatory statement on every page of where its numbers came from. |
| **Evidence class** | The F/D/R/K/P/J tagging applied to intelligence services. |
| **Fact / Judgement / Derived** | The mandatory classification of every intelligence field. |
| **Reason code** | A machine-generated explanation emitted as a code + parameters, never as an English string (the app is bilingual). |

## Identity keys

| Term | Meaning |
|---|---|
| **`CarOwnerNo`** | **The** fleet identity key against OM. |
| **`TrafficID`** | *Not* the identity key. A common and silent mistake. |
| **`CarSerial` / `car_serial`** | The join key for the expense ledger. |
| **`PlateResolver`** | The correct way to resolve a plate. Never `where('plate_no')->first()` — plates get reassigned. |

## Workflow states

Pre-ticket (car stays free): `pending_review` · `inspection_requested` · `inspection_diagnostic` · `recommendation_pending` · `complaint_triage` · `triage_approval_pending`

Active repair: `inspection_pending` · `awaiting_dispatch` · `in_transit` · `under_repair` · `repair_review` · `ready_for_reinspection` · `reinspection_failed` · `ready_for_pickup`

Car freed, ticket open: `on_site_pending` · `in_our_park` · `awaiting_invoice` · `paused_returned_to_service`

Terminal: `closed` · `diagnostic_cleared` · `review_rejected` · `recommendation_dismissed` · `complaint_resolved`

See [10-Business-Rules.md](10-Business-Rules.md) for what each one means.

## The other status axis

| Term | Meaning |
|---|---|
| **`event_status`** | Legacy `IN` / `OUT` axis from the spreadsheet era. The workflow service maps `workflow_status` onto it. **Never set by hand.** |
| **`operational_status`** | The derived answer to "what is this car doing" — Available / Rented / In Maintenance. Driven by the cascade, not stored by you. |

## Condition grades

**Red** and **Yellow** both ground the vehicle. Only **Green** and **Orange** may rent.

## Line item kinds

`part` → a physical component. `labor` → hours × rate. `uom` is `unit` / `hour` / `litre`.

## Provenance values

`entry_source` on a cost line: `manual` · `ocr` · `import`.
`source` on an expense row: `excel` (today) · `odoo` (planned).

## Abbreviations

| Short | Long |
|---|---|
| **OM** | OfficeManager |
| **QA** | Quality Assurance gate before a car returns to service |
| **QC** | Quality Control — the re-inspection layer |
| **SLA** | The clock on a stage (e.g. the 3-day invoice chase) |
| **RBAC** | Role-Based Access Control (spatie) |
| **SPA** | The React frontend |
| **AED** | UAE Dirham — the system currency |
| **RTA** | The UAE roads authority; the "F RTA" sheet tab carries fines and registration status |
| **BOM** | Bill of Materials (an Odoo mapping target) |
| **p90** | 90th percentile — used for repair durations alongside the median |
