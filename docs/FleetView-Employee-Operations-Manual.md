# FleetView — Employee Operations Manual (Current System)

**What this is:** A complete, factual map of how the FleetView system behaves **today**, exactly as implemented. It describes the real employee journeys, the screens/APIs they use, what changes in the database, what blocks each action, and where the current gaps and confusion points are.

**What this is NOT:** A redesign, a proposal, or a Phase-2 plan. Nothing here suggests changes. It documents current behavior only.

**Audience:** Operations leads writing UAT scripts and onboarding real staff.

**Source of truth:** read-only audit of `backend/` (Laravel 12) and `frontend/` (React SPA), verified against the live database and code. File/line references included where useful.

---

## How to read this manual

Each scenario answers the same 10 questions: **(1) Role · (2) Permission · (3) Screen/API · (4) Tables · (5) Fields/statuses · (6) Validations · (7) Blockers · (8) Next step · (9) Failure cases · (10) Final state.**

**Legend:** ✅ works end-to-end · ⚠️ backend works but UI unwired / partial · 🔴 known gap.

**Key architectural fact to understand first:** FleetView is a **read + operate** layer on top of two external systems.
- **OfficeManager (OM) API** is the *source of truth* for which cars exist, contracts, customers, and invoices. It is **read-only** — FleetView pulls from it, never pushes.
- **Google Sheets** *enrich* the OM data (make/model/color/price, maintenance history log, oil-change intervals) and are read-only sources.
- **FleetView's own database** adds the operational layer OM doesn't have: the maintenance workflow, inspections, logistics, condition grading, readiness, notifications.

Because of this, **most of the fleet/contract/customer data arrives via nightly sync, not by employees typing it in.** Employees mostly *operate* on synced data (open maintenance tickets, inspect, grade, dispatch) rather than *create* core records.

---

# PART A — Roles & Permissions (read this before the scenarios)

FleetView uses Spatie roles/permissions (37 permissions, 10 roles). Enforcement is at the **route layer** (`permission:` middleware). `admin` and `super-admin` **bypass every check** via `Gate::before`.

### The roles and who they map to
| Role | Real people (from code) | In one line |
|---|---|---|
| **super-admin** | seeded `admin@fleet.local` | Everything; bypasses all gates. |
| **admin** | — | Everything incl. user management. |
| **manager** | — | Full operations minus user admin & odometer approval. |
| **operations** | Marwa, Leen | Rentals, customers, move cars in/out, take payments, complaints. |
| **maintenance** | — | Garage/workshop coordination + bill approvals. |
| **supervisor** | Waleed Medhat, Abdullah Asham | The **dispatcher**: picks garage, delegates driver, oversees logistics. |
| **inspector** | Abu Maroof | Opens tickets, files test-drive report, re-inspects. |
| **logistics** | Drivers | Field pool: claims + drives car movements, garage pickup/dropoff. |
| **finance** | — | Customer financials, invoices, payments. |
| **viewer** | default on signup | Read-only across the board. |

### Role → Permission matrix (complete)
`super-admin`/`admin` hold all 37 and are omitted. ● = granted.

| Permission | manager | operations | maintenance | supervisor | inspector | logistics | finance | viewer |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| vehicles.view | ● | ● | ● | ● | ● | ● | ● | ● |
| vehicles.manage | ● | | | | | | | |
| vehicles.approve_odometer | | | | | | | | |
| drivers.view | ● | ● | | ● | | | | ● |
| drivers.manage | ● | | | | | | | |
| vendors.view | ● | | ● | ● | ● | ● | | ● |
| vendors.manage | ● | | | | | | | |
| customers.view | ● | ● | | | | | ● | ● |
| customers.manage | ● | ● | | | | | ● | |
| contracts.view | ● | ● | | | | | ● | ● |
| contracts.manage | ● | ● | | | | | | |
| booking_readiness.view | ● | ● | | | | | ● | ● |
| booking_readiness.manage | ● | ● | | | | | | |
| inspections.view | ● | ● | ● | | ● | | ● | ● |
| inspections.manage | ● | ● | ● | | ● | | | |
| reminders.view | ● | ● | ● | | | | ● | ● |
| reminders.manage | ● | ● | ● | | | | ● | |
| billing.view | ● | ● | | | | | ● | ● |
| billing.manage | ● | ● | | | | | ● | |
| operations.manage | ● | ● | | | | | | |
| operations.override | ● | | | | | | | |
| logistics.view | ● | ● | ● | ● | ● | ● | | ● |
| logistics.dispatch | ● | ● | | ● | | ● | | |
| logistics.claim | ● | ● | | | | ● | | |
| maintenance.view | ● | ● | ● | ● | ● | ● | | ● |
| maintenance.approve | ● | | ● | | | | | |
| maintenance.manage | ● | | ● | | | | | |
| maintenance.initiate | ● | | ● | | ● | | | |
| maintenance.logistics | ● | | ● | ● | | ● | | |
| maintenance.delegate | ● | | ● | ● | | | | |
| registration.view | ● | ● | ● | | | | | ● |
| registration.manage | ● | | | | | | | |
| insights.view | ● | | ● | | | | ● | ● |
| dashboard.view | ● | ● | ● | ● | ● | ● | ● | ● |
| sync.run | ● | | | | | | | |
| users.manage | | | | | | | | |

**What each role can/cannot do (plain language):**
- **Admin / Super-admin** — see and do everything, including managing users. Only role that can approve odometer change requests and run syncs from anywhere.
- **Manager** — near-total operational control: vehicles, contracts, customers, maintenance (all stages), logistics, billing, sync. **Cannot** manage users or approve odometer changes.
- **Operations (Marwa/Leen)** — the rental desk: create/edit customers & contracts, start/close vehicle operations, take payments, dispatch/claim logistics, log complaints. **Cannot** manage vehicles master data, run maintenance repair stages, or manage users.
- **Maintenance** — garage coordination: view/approve/manage maintenance, initiate & delegate, handle bills. **Cannot** touch contracts, customers, or billing.
- **Supervisor (Waleed/Abdullah)** — the dispatcher: picks garages, delegates drivers, oversees logistics moves. **Cannot** create contracts/customers, manage vehicles, or approve bills.
- **Inspector (Abu Maroof)** — opens tickets via the Inspector Pad, files diagnostics, re-inspects repairs. **Cannot** direct-open a manager ticket, dispatch, or manage money.
- **Logistics (Drivers)** — claims and drives car movements, captures pickup/dropoff odometer photos. **Cannot** create tickets, dispatch (assign), or see customers/billing.
- **Finance** — customer financials, invoices, payments, reminders. **Cannot** manage vehicles/contracts/maintenance operations.
- **Viewer** — reads almost everything (fleet, customers, contracts, billing, maintenance, dashboard). Creates/edits **nothing**.

🔴 **Confusion/gap points (admin):** new self-signups become **viewer** and can immediately read the whole dataset (fleet + customers + billing). Login does **not** check account `status`, so a "suspended" user still works. Role changes are **CLI-only** (`php artisan user:role <email> <role>`) — there is no in-app user admin screen.

---

# PART B — Fleet Management

## Scenario 1 — Vehicle onboarding

There are **two** ways a car enters the system.

### 1a. Bulk import from OfficeManager (the normal path) ✅
1. **Role:** manager/admin (runs the sync); in practice it's scheduled, not clicked.
2. **Permission:** `sync.run` (CLI runs bypass anyway).
3. **Action:** `php artisan om:sync --vehicles` (or `sync-fleet.cmd cars`). The `/sync` page is a **read-only monitor** — nobody can trigger a sync from the browser (`web_sync_enabled=false`).
4. **Tables:** `vehicles` (`origin='api'`), plus `sync_runs`, `sync_changes` for the audit trail.
5. **Fields:** VIN, plate, make/model/year/color (from Google Sheet enrichment), `status` (from OM StatusNo mapping), `external_id` = `OM:{serial}`.
6. **Validations:** idempotent `updateOrCreate` by `external_id` — re-running never duplicates.
7. **Blockers:** OM API must be reachable at `81.85.92.150:8080` and the key valid; otherwise the phase fails and retries next run.
8. **Next:** enrichment sheets fill make/model/price; the car appears in the Vehicles list.
9. **Failure cases:** OM unreachable → phase logged as failed, retried next run (idempotent, safe). Google Sheet down → car imports but make/model blank until next enrich.
10. **Final state:** a `vehicles` row exists, ready to be graded and operated on.
   - ⚠️ **Important:** the **nightly** cron uses `--link` which only *refreshes existing* cars. **Adding new cars in bulk requires a manual `om:sync --vehicles`.**

### 1b. Manual vehicle creation ✅
1. **Role:** manager/admin.
2. **Permission:** `vehicles.manage`.
3. **Screen/API:** Vehicles page → "Add New Vehicle" → `POST /Vehicle` → `VehicleController@store` → `VehicleService::store`.
4. **Tables:** `vehicles` (`origin='web'`).
5. **Fields written:** whatever is entered; only **VIN is required and must be unique**.
6. **Validations** (`StoreVehicleRequest`): `vin` required+unique(≤32); `code` unique; `year` 1950–next year; `odometer`/`purchase_price` ≥ 0; `status` limited to `office_use, ready, rented, out_of_order, under_maintenance, suspended, disposed, sold, returned`.
7. **Blockers:** duplicate VIN → 422.
8. **Next:** grade condition (Scenario 2) and the car is operable.
9. **Failure cases:** 🔴 **Latent enum mismatch** — the form validation allows `status='ready'`, but the actual `vehicles.status` **database column is an enum of `active, sold, for_sale, insurance_claim, personal, under_process, exported, office`** (default `active`). Writing `ready` into that column is not a valid enum value — under strict MySQL it errors; under non-strict it stores blank. **Onboarding staff should leave status at default and grade via condition instead.**
10. **Final state:** a manually-created `vehicles` row.

**When does a vehicle become available?** Not at creation — availability is derived (Scenario 2).

---

## Scenario 2 — Vehicle availability lifecycle

FleetView tracks a vehicle's state across **three independent columns** — understanding this is the single most important thing for operations staff:

### The three status columns

**(A) `status` — lifecycle / ownership** (enum, default `active`):
`active · sold · for_sale · insurance_claim · personal · under_process · exported · office`
- Set by: OM sync (from StatusNo) or manual edit.
- Meaning: is this car part of the rentable fleet at all, or sold/exported/office-use?

**(B) `operational_status` — live movement** (enum, default `available`):
`available · rented · maintenance · test · transfer · sale_prep · in_transit`
- Set by: **derived automatically** from the car's open contracts and open maintenance/logistics by `OperationsService::reconcileAllOperationalStatus` (runs after each OM sync), and by the maintenance workflow's `cascade()`.
- Meaning: what is the car doing *right now*.

**(C) `condition_grade` — physical condition** (default `green`):
`green · orange · yellow · red`
- Set by: staff via `POST /Vehicle/{id}/condition` (`vehicles.manage`), or auto-set **red** by a Breakdown ticket.
- Rental effect: **red & yellow BLOCK rental** (car hidden/grounded); **orange** requires a sales acknowledgement; **green** rents freely.

### What makes a car rentable — the eligibility gate
When a contract is created, `ContractEligibilityService::assertEligibleForContract` runs a checklist. A car is blocked if **any** of:
- `status` ∈ `{under_maintenance, out_of_order, sold, disposed, suspended, returned, office_use, rented}` (the blocklist),
- `operational_status` ∈ `{under_maintenance, in_transit}`,
- `condition_grade` = red or yellow (hard block; yellow overridable only by a manager with `operations.override`),
- an **open maintenance ticket** exists (workflow_status in the active set),
- an **open/unreviewed damage** inspection exists,
- the car is **dirty** (cleaning_status).
- Insurance/registration expiry is a **warning only** (`DOCUMENTS_BLOCK = false`) — the F-Insurance/F-RTA sync is too stale to block on.

### Who changes each state & what triggers it
| State change | Trigger | Who / mechanism |
|---|---|---|
| → `available` | contract closed / maintenance closed | `OperationsService::closeOperation`, workflow `cascade()`, or nightly reconcile |
| → `rented` | open rental contract detected | reconcile after OM sync (⚠️ **not** the web contract form — see gap) |
| → `maintenance` | maintenance ticket opened | workflow `cascade()` on any active ticket state |
| → `in_transit` | logistics move started | Logistics dispatch |
| → `test` / `transfer` / `sale_prep` | corresponding op | operations/logistics |
| condition → red | breakdown ticket, or manual grade | Breakdown intake auto-grounds; manual via condition modal |

🔴 **The big availability gap:** the **web contract form does not set `operational_status='rented'`**, and closing/returning through the UI edit path does not free the car. Only the (unwired) `OperationController@start`/`@close` endpoints and the **nightly OM reconcile** flip it. **Practical consequence: between syncs, a just-rented car can still look Available and be double-booked, and a just-returned car can still look Rented.**

---

# PART C — Rental Operation

## Scenario 3 — Customer rental request ⚠️

1. **Role:** operations (Marwa/Leen) or manager.
2. **Permission:** `contracts.manage` (customers need `customers.manage`).
3. **Screen/API:** `/contracts/new` (`ContractForm.js`) → `POST /Contract` → `ContractController@store` → `ContractService::store`. Contract number auto-fetched via `GET /Contract/next-no` (`W-#####`).
4. **Tables:** `contracts` (`origin='web'`); customers can be created inline (`customers`); a `U`-type maintenance contract also writes `maintenances` + `contract_items`.
5. **Fields:** `contract_no`, `contract_type` (C=rental car, R=rental, U=maintenance), `state='open'`, `vehicle_id`, `customer_id`, `out_date/out_milage`, pricing (`day_price`, `week_price`, …), `opened_by`.
6. **Validations** (`StoreContractRequest`): `contract_no` required + unique per type; `vehicle_id`/`customer_id` `exists:` but **nullable**; condition-acknowledgement + manager-override fields for orange/yellow cars.
7. **Blockers:** the eligibility gate (Scenario 2). Orange car → requires `condition_acknowledged`. Yellow car → requires `manager_override` + reason (permission-gated).
8. **Next:** hand the car over (Scenario 4).
9. **Failure cases:** 🔴 vehicle must pre-exist (no inline vehicle create); 🔴 both `vehicle_id` and `customer_id` are nullable → a contract can save with neither; 🔴 the car is **not** marked rented (double-book window).
10. **Final state:** an open `contracts` row; **the vehicle's availability is unchanged until a reconcile runs.**

---

## Scenario 4 — Vehicle handover / delivery ⚠️ (fragmented)

**There is no single "check-out to customer" transaction.** In practice handover is a *procedure around* contract creation:
1. **Role:** operations / delivery clerk.
2. **Permission:** `contracts.manage` (or `operations.manage` for the true op path).
3. **Screen/API:** `ContractForm.js` gates handover behind an **8-point readiness checklist** for C/R contracts and stamps `out_date`, `out_milage`, `opened_by`. The **only** path that also flips `operational_status='rented'` is `OperationController@start` (`POST /Vehicle/{id}/operation`) — but **no frontend calls it**.
4. **Tables:** `contracts` (out_* fields). Pre-rental inspection/photos would live in `inspection_records` — but the inspection capture UI is a demo (Scenario 7a).
5. **Fields/statuses:** `out_date`, `out_milage`, fuel; `opened_by`.
6. **Validations:** the readiness checklist is a UI gate; server-side, out_milage is `integer|min:0`.
7. **Blockers:** eligibility gate already passed at contract create.
8. **Next:** active rental (Scenario 5).
9. **Failure cases:** 🔴 mileage/fuel/photos/signature at handover are **not** captured by a dedicated transaction; the status-flip path is unwired.
10. **Final state:** open contract with pickup mileage recorded; car should be "rented" but may still read "available" until reconcile.

**On photos/mileage/fuel/documents/signature:** the schema *supports* pre-rental inspection with photos and damage flags (`inspection_records`), but the capture screen is not wired (Scenario 7a). Mileage/fuel are captured as contract `out_milage`/fuel fields, not as an inspection.

---

## Scenario 5 — Active rental ✅ (monitoring) / ⚠️ (mutations)

1. **Role:** operations / manager monitor; any viewer can watch.
2. **Permission:** `contracts.view`, `dashboard.view`, `operations.manage` for actions.
3. **Screens:** Dashboard, Overdue Rentals, RentalOperationsHub (read-only board of readiness verdicts), Fleet Utilization.
4. **What employees monitor:** open contracts, overdue returns (a `notifications:scan` detector raises an in-app alert when a rental runs past its expected return), fleet status counts.
5. **Extensions:** handled in OM (the source of truth) and picked up by sync; there is no in-app "extend contract" action.
6. **Problems during rental (issue/ticket):** if the car develops a fault while rented, a maintenance ticket can be opened on it (Scenario 9). **Note:** maintenance on a rented car is **allowed** (the old hard-block was removed) — overlaps are managed manually; the car's operational status shows the rental as primary.
7. **Blockers:** none to monitoring.
8. **Next:** return (Scenario 6).
9. **Failure cases:** extensions/edits made only in OM won't reflect until the next sync.
10. **Final state:** rental continues until returned.

---

## Scenario 6 — Vehicle return ⚠️ (backend works, UI unwired)

1. **Role:** operations / manager.
2. **Permission:** `operations.manage`.
3. **Screen/API:** the working path is `POST /Contract/{contract}/close` → `OperationController@close` → `OperationsService::closeOperation`. **No frontend calls it.** The reachable UI path (edit contract → set `state=closed` via `ContractController@update`) **bypasses** the free-the-vehicle logic.
4. **Tables:** `contracts` (state, in_* fields), `vehicles` (operational_status).
5. **Fields/statuses:** `state='closed'`, `in_date`, `in_time`, `in_milage`, `in_fuel`, `closed_by`; `vehicles.operational_status='available'`.
6. **Validations:** `in_milage` `integer|min:0`. 🔴 **No check that `in_milage ≥ out_milage`** — a backward reading is accepted and can drag the car's canonical odometer backward.
7. **Blockers:** none once the contract is found.
8. **Next:** post-return inspection / damage check (Scenarios 7–8), then the car is available again.
9. **Failure cases:** 🔴 because the UI doesn't call `/close`, returns in practice rely on the **nightly OM sync** detecting the closed contract; `ContractValidator::zombies` exists specifically to catch contracts that were closed in OM but left open in-app.
10. **Final state:** closed contract, car freed — **but only reliably after a sync/reconcile.**

**Damage detection on return:** there is no automatic damage step; damage is filed manually (Scenario 8) and is not currently reachable through the UI.

---

# PART D — Inspection Workflow

## Scenario 7 — Inspection process (four distinct concepts)

FleetView has **four things all called "inspection."** They are separate:

### 7a. Hotspot inspection with photos & damage flags ⚠️ (backend-only)
- **Role:** inspector/operations · **Permission:** `inspections.manage`.
- **API:** `POST /Inspections` → `InspectionController@store`; photo upload via S3 presign (`InspectionController::presign`, **hardcoded S3**).
- **Tables:** `inspection_records` (vehicle_id, optional contract_id, damage flags with type/severity/note, before/after photo refs).
- **Data stored:** car-diagram hotspots, damage type + severity + note, before/after slider images.
- 🔴 **No frontend consumer** — `InspectionPrototype.js` is a client-side demo that never POSTs. A damage-flag-only record can persist without S3, but photo capture needs `AWS_*` configured.
- **Final state:** an `inspection_records` row (when driven via API).

### 7b. Inspection Schedules (recurring safety/ops) ✅
- **Role:** inspector/operations/maintenance · **Permission:** `inspections.manage`.
- **Screen/API:** `InspectionSchedules.js` → `/InspectionSchedules` store + `/{id}/complete`.
- **Tables:** `inspection_schedules`.
- **Behavior:** define a recurring safety/ops inspection; mark complete each cycle. Self-contained and fully working.

### 7c. Readiness / pre-rental checklist ✅
- **Role:** operations/manager (read perms) · **Screen:** `ReadinessDashboard.js` → `/readiness`.
- **Engine:** `VehicleReadinessService::evaluate` runs **9 live checks** (open maintenance, condition, cleaning, docs, GPS, etc.) and a hard `rentalGuard`.
- **Note:** registration/insurance/GPS points are **advisory** (stale external sync), not hard blocks.

### 7d. Re-inspection QC (after repair) ✅
- **Role:** inspector · **Permission:** `maintenance.initiate | maintenance.delegate`.
- **Where:** inside the maintenance workflow — a failed re-inspection routes the car back to the supervisor with a garage-blame badge (`reinspection_failures`).

**Approval flow / final result:** for 7d, the inspector's pass/fail decides whether a repaired car returns to service or bounces back. For 7a/7b, completion is recorded but there is no multi-step approval chain.

---

# PART E — Damage Workflow

## Scenario 8 — Damage reporting ⚠️ (view works, create has no UI)

1. **Role:** whoever spots damage — inspector/operations (view: `maintenance.view`).
2. **Permission (view):** `maintenance.view`. **Permission (create):** `inspections.manage`.
3. **Screen/API (view):** `DamageAccidents.js` → `GET /Maintenance/incidents` → `MaintenanceIncidentService::log`. This is a **read-only, derived** view built from `maintenances` rows (which arrive via sheet import or the workflow).
4. **Create path:** the only write path is `POST /Inspections` with `damage_flagged=true`. 🔴 **No frontend calls it** — on day 1 an employee **cannot file a damage report through the app**, only via raw API.
5. **Information stored (when filed):** vehicle, damage type, severity, note, optional photos, optional contract link (in `inspection_records`); red/green fault attribution derives from `liable_party` + insurance on the underlying maintenance record.
6. **Who reviews it:** an open/unreviewed damage inspection **blocks the car from rental** (eligibility gate) until resolved — that is the review pressure.
7. **What happens after "approval":** clearing the damage (resolving the record) removes the rental block; damage tied to a fault flows into the maintenance workflow.
8. **Next:** if the damage needs repair, a maintenance ticket is opened (Scenario 9).
9. **Failure cases:** 🔴 no UI to create a damage record; relies on API or on damage arriving as a maintenance/sheet record.
10. **Final state:** a damage record visible in the Damage & Accidents log; car blocked until resolved.

---

# PART F — Maintenance Workflow (the core module) ✅

This is the most complete part of the system. A **ticket** is a `maintenances` row carrying a `workflow_status`; it is a **container of per-fault `maintenance_tasks`** that roll up cost/status, and it lives at **one garage at a time**.

## Scenario 9 — Maintenance request → repair → release

### Entry points (5 ways a ticket is born)
| Entry | Role / Permission | Endpoint | Born as |
|---|---|---|---|
| **A. Inspector Pad → pickup** | inspector · `maintenance.initiate` | flag then odometer-gated `POST /inspector-pad/pickup/{vehicle}` | `inspection_pending` |
| **B. Driver request** | driver · `maintenance.logistics` | `POST /maintenance-tickets/request` | `inspection_requested` (alert only, **not yet a ticket**) |
| **C. Manager direct open** | manager/maintenance · `maintenance.manage` | `POST /maintenance-tickets` (odometer+photo) | `inspection_diagnostic` (inspector blocked here by design) |
| **D. Breakdown (emergency)** | inspector/manager · `maintenance.initiate\|manage` | `POST /maintenance-tickets/breakdown` | `inspection_pending`, auto `fault_severity=critical`, **grounds car RED**. *Simplest path — 2 fields, no odometer.* |
| **E. Customer complaint** | operations · `maintenance.manage` | `POST /maintenance-tickets/complaint` | `complaint_triage` |

### The full state machine (17 states)

```
              ┌─ PRE-TICKET (no dispatch, no operational_status change) ─┐
              │                                                          │
 (B) inspection_requested ─┐                                            │
 (E) complaint_triage ─────┤ Abu Maroof triages:                        │
 (C) inspection_diagnostic ┘  resolve on-site → complaint_resolved ✔    │
              │                clear diagnosis → diagnostic_cleared ✔    │
              │                needs shop ↓                             │
              ▼                                                          │
        inspection_pending ─────────────► on_site_pending               │
        (ticket OPENED, awaiting          (committed on-site job;       │
         supervisor dispatch)              car stays available)         │
              │  Supervisor picks garage + driver                       │
              ▼  (may split a SUBSET of faults)                         │
        awaiting_dispatch                                               │
              │  Driver pickup: odometer + garage captured              │
              ▼                                                         │
         in_transit  ── "Now at Garage" arrival gate (odometer+photo) ──┤
              │                                                          │
              ▼                                                          │
        under_repair  (garage has the car; faults repaired, cost added) │
              │                                                          │
     [video_review PARKED] ──► repair_review (supervisor video sign-off)│
              │  markReady → promoteFromRepair (skips parked stage)      │
              ▼                                                          │
        ready_for_pickup ── driver collects FROM garage ──► in_our_park  │
              │                                              (back in pool,│
              │  arriveAtPark auto-branches on repair size:   ticket open)│
              │   • minor/routine  → auto-CLOSE                          │
              │   • major repair   → ready_for_reinspection             │
              ▼                                                          │
     ready_for_reinspection ──► inspector QC:                           │
              │   PASS → close ✔                                        │
              │   FAIL → reinspection_failed → back to supervisor        │
              ▼                                                          │
          (closed) ──► awaiting_invoice (SLA: 3 days) ──► fully done ✔  │
```

**Terminal states:** `closed`, `diagnostic_cleared`, `complaint_resolved`.
**"Operationally done" (car back on road):** `closed`, `awaiting_invoice`, `in_our_park`.

### Answering the 10 questions for the maintenance flow
1. **Roles:** inspector opens/diagnoses & re-inspects; supervisor dispatches & delegates; driver moves the car; garage repairs; maintenance/manager close & cost.
2. **Permissions:** each transition is individually gated — `maintenance.initiate` (open/close), `maintenance.logistics` (driver moves), `maintenance.delegate` (dispatch/task status), `maintenance.manage` (direct open, cost, line items), re-guarded inside `MaintenanceWorkflowService`.
3. **Screens/APIs:** `MaintenanceBoard.js` + `TicketActionModal.js`; endpoints under `/maintenance-tickets/{ticket}/*` and `/maintenance-tasks/{task}/*`.
4. **Tables:** `maintenances` (the ticket), `maintenance_tasks` (per fault), `maintenance_line_items` (parts/labor), `maintenance_invoices`, `maintenance_media` (photos/videos), `vehicle_log_events` (append-only audit), `reinspection_failures`, `odometer_block_events`.
5. **Fields/statuses:** `workflow_status`, `fault_severity` (critical/moderate/routine), `trigger_reason`, `event_status`, odometer captures per stage, `last_state_change_at` (drives time-in-stage SLA). `cascade()` derives `vehicles.operational_status='maintenance'` on any active state.
6. **Validations:** odometer-continuity checks (±5km tolerance, `OdometerContinuityService`, non-blocking confirm); mandatory arrival odometer + photo at garage; findings + root-cause pickers at diagnose/garage steps; cost **deferred** (not required to close).
7. **Blockers:** you can't skip stages; re-inspection failure forces a return to the supervisor, not the same garage; a car can't be dispatched without a chosen garage + driver.
8. **Next:** each stage hands to the next actor; the board shows time-in-stage and flags SLA breaches (e.g. Pending Dispatch > 4h).
9. **Failure cases:** 🔴 `close()` does **not** reset `condition_grade`, so a breakdown-grounded (RED) car **stays booking-blocked after the repair closes** until someone manually re-grades it. Odometer discrepancies are flagged but non-blocking.
10. **Final state:** ticket `closed`; car `operational_status='available'`; an auto legacy-format summary note written; invoice may remain outstanding in `awaiting_invoice` under a 3-day SLA.

**Cost & parts:** faults' `maintenance_line_items` (part/labor, qty×price) auto-sum into `parts_total`/`labor_total`/`cost`; ticket cost is a roll-up. Cost can be added **after** closing via `POST /{ticket}/cost` (`maintenance.manage`).

**Parked:** the **video-review** stage (`repair_review`) is behind feature flags (`FEATURE_VIDEO_REVIEW=false` + frontend `SHOW_VIDEO_REVIEW=false`) — `markReady` skips it. The old separate **QA** columns were added then **dropped**; QA = the re-inspection lane.

---

# PART G — Integration Workflows

### OfficeManager (OM) API — the source of truth
- **What enters:** vehicles (which cars exist), contracts (open + recently closed), customers, invoices — scoped to owner `1541` (+ extra serials).
- **When:** nightly `om:sync --link --contracts --invoices --customers` at 03:00 (Laravel scheduler) and/or the `sync-fleet.cmd` runner via Task Scheduler. `--link` refreshes existing car statuses; new cars need a manual `--vehicles`.
- **Direction:** **read-only** — the API is a replica (POST → 405). FleetView never writes back.
- **If it fails:** the phase is logged failed and retried next run; every phase is idempotent (`updateOrCreate`), so re-runs never duplicate. If the host can't reach `81.85.92.150:8080`, insights/reconciliation pages that read live go blank; synced data stays as last-good.
- ⚠️ **`om:sync` is scheduled in TWO places** (Laravel 03:00 *and* the runner) — confirm they're deduped/staggered.

### Google Sheets — enrichment (read-only)
- **What enters:** make/model/color/purchase price (Faster + Asset sheets), maintenance history log (N-Maintenance), oil-change intervals, customer cases, registration/insurance snapshots.
- **When:** `import:maintenance-sheet` nightly 02:30; asset/oil/registration/insurance via their sync commands.
- **Rule:** the sheet **never creates cars** — it only enriches cars that already exist from OM (VIN match). Blank sheet cells never wipe existing data.
- **If it fails:** requires `storage/app/google/credentials.json`; without it, sheet syncs and the Trip Dashboard (Delivery/Orders board) break — a **cold cache + unreachable sheet 500s** the ops board.

### Media / S3
- Inspection photos, maintenance videos, odometer photos, invoice receipts. Currently `FILESYSTEM_DISK=local`, `AWS_BUCKET` empty. Maintenance odometer photos **degrade to the public disk** if S3 is absent; the standalone inspection presign and video presign are **hard-S3** and fail without `AWS_*`.

---

# PART H — Background Processes

### Scheduled jobs (Laravel scheduler via `run-scheduler.vbs` → `schedule:run` every minute)
| Time | Command | Purpose |
|---|---|---|
| 02:00 | `fleet:check-expiry` | Ground cars with expired registration/insurance. |
| 02:30 | `import:maintenance-sheet` | Refresh N-Maintenance sheet (garage stage/notes). |
| 02:50 | `maintenance:link-reasons` | Re-categorise maintenance rows against the reason vocab. |
| 03:00 | `om:sync --link --contracts --invoices --customers` | Nightly source-of-truth sync. |
| 03:10 | `notifications:scan` | Post-sync alert refresh. |
| 03:45 | `mileage:scan --apply` | Re-anchor global mileage baseline; heal odometer from contracts. |
| 04:00 | `service:sync-reminders` | Keep oil-change service reminders in step with the sheet. |
| 07:30 | `inspections:generate-tasks` | Proactive "Needs Test Drive" requests for due cars. |
| 08:00 | `notifications:scan` | Start-of-day sweep (service-due alerts). |
| 08:05 | `invoices:scan-overdue` | Missing-invoice SLA flag (>3 days). |
| every 10 min | `notifications:scan` | Near-realtime bell. |
| every 10 min | `trips:warm` | Pre-warm the Delivery/Orders board cache. |

### Notifications
- **In-app bell only** — DB notifications + frontend **polling** (no real-time broadcaster; `BROADCAST_CONNECTION=log`).
- **Detectors** (`NotificationScanner` / `notifications:scan`): overdue rentals, maintenance overruns, expiring documents, service-due (by km/date), pending approvals, missing-invoice SLA.
- **Idempotent** — only raises genuinely new conditions, with dedup keys, so it never spams.
- **Parked:** email delivery (`NOTIFY_MAIL_ENABLED=false`) and per-debtor overdue-invoice alerts (`invoice_overdue_alerts=false`).

### Queues
- **None.** No queued jobs exist (zero `ShouldQueue`). Everything runs synchronously or via the scheduler. **No `queue:work` worker is needed.**

### Automated / rule-based actions
- Breakdown intake auto-sets `fault_severity=critical` and grounds the car RED.
- Maintenance `cascade()` derives `operational_status` from ticket state.
- `OperationsService::reconcileAllOperationalStatus` re-derives Available/Rented/Maintenance from open contracts after each sync.
- Closing a workflow ticket auto-writes a legacy-format summary note and auto-rolls the oil-change Service Reminder for routine services.
- Odometer continuity auto-flags discrepancies (non-blocking).

---

# PART I — Current Gaps & Things Employees May Get Confused About

### Gaps (behavioral, as-built)
1. 🔴 **Rental round-trip doesn't flip vehicle availability from the UI.** Contract create doesn't mark the car rented; UI close doesn't free it. Relies on nightly reconcile → **double-book / stale-status window**. (Scenarios 3, 4, 6)
2. 🔴 **No wired UI for:** contract close/return, the true "start operation" handover, damage-record creation, and inspection-photo capture. Backends exist; employees can't reach them through the app today.
3. 🔴 **Red car stays grounded after repair.** Maintenance close doesn't reset `condition_grade`; a breakdown car remains unrentable until manually re-graded. (Scenario 9)
4. 🔴 **Vehicle `status` has three mismatched vocabularies** (DB enum vs eligibility blocklist vs create-form validation) — manual creates with `status='ready'` can violate the DB enum. (Scenario 1b)
5. 🔴 **Missing cross-field validation** on contract/movement odometer (`in ≥ out`) and dates (`in_date ≥ out_date`).

### Confusion points (train staff on these)
- **"Available" is derived, not set.** Staff can't directly toggle a car available — it follows contracts/maintenance and only refreshes on reconcile/sync. A car may briefly show the wrong movement status between syncs.
- **Three status columns.** `status` (is it fleet at all), `operational_status` (what it's doing now), `condition_grade` (physical condition). All three affect rentability differently.
- **Yellow blocks, orange only warns.** Counter-intuitively, **yellow AND red both ground the car**; orange rents with an acknowledgement. Only green is unconditional.
- **Two kinds of "request" in maintenance.** A *Driver request* (`inspection_requested`) and a *diagnostic* (`inspection_diagnostic`) are **not tickets yet** — they raise no dispatch and don't change the car's status until the inspector commits them.
- **"Inspection" means four different things** (hotspot damage capture, recurring schedules, pre-rental readiness, post-repair QC) — only two are fully wired in the UI.
- **Cost after close.** A closed maintenance ticket can still be missing its cost — it's added later and tracked by a 3-day invoice SLA. "Closed" ≠ "paperwork done."
- **Money is hidden.** All financial widgets are hidden behind `SHOW_FINANCIALS=false` — staff won't see balances/margins until that flag is flipped and the app rebuilt.
- **Sync lag.** Contract extensions/edits made in OfficeManager don't appear until the next sync. The `/sync` page is view-only.

---

*End of manual. This document reflects the system as implemented on 2026-07-12 (branch `ui-overhaul-v1`). It describes current behavior only — no redesign, no Phase 2.*
