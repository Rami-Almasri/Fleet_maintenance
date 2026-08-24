# Business Rules & Workflow

**The most important document in this handbook after the System Overview.**

This is what the system actually enforces: the maintenance state machine, the financial rules, the permission model, and the standing design rulings that shaped the code. Breaking these does not usually produce an error — it produces numbers that look right and are wrong.

---

## Part 1 — The maintenance workflow

Implemented in:
- **`backend/app/Services/MaintenanceWorkflowService.php`** — the state machine, the transition table, every guard.
- **`backend/app/Models/Maintenance.php`** — every state as a constant, each with a comment explaining what it means and why it exists.

Those two files are the specification. Read them.

### What a ticket is

A ticket **is** a `maintenances` row (`origin = 'manual'`). It flows through the same board, SLA, cost and utilisation surfaces as every other workshop event. What the workflow service adds on top is the **lifecycle** (`workflow_status`) and its guards.

### The core guarantee

> Every transition is guarded. An out-of-sequence move, or a handoff missing its required data, throws `WorkflowTransitionException` — mapped to **HTTP 422**. Each transition stamps **who** and **when**, and fires an alert to the next role automatically. **The data is generated, never typed.**

That last sentence is the point of the whole system. It replaced a WhatsApp group where every state lived in somebody's memory.

### The happy path

| Stage | State | Who acts | What happens |
|---|---|---|---|
| −1 | `pending_review` | Controller | Reviews a driver- or system-generated inspection request. Approve or reject. |
| 0 | `inspection_requested` | Inspector | Notified. **Not a ticket yet.** |
| 1 | `inspection_diagnostic` | Inspector | Test-drives and diagnoses. **Not a ticket yet.** |
| 2 | `inspection_pending` | — | **★ The ticket is born.** Awaiting the supervisor's dispatch decision. |
| 3 | `awaiting_dispatch` | Supervisor | Garage chosen, driver assigned. |
| 4 | `in_transit` | Driver | Captures odometer, picks the car up. Car is now physically out. |
| 5 | `under_repair` | Garage | Garage confirms it received the car. |
| 6 | `ready_for_reinspection` | Garage | Repair finished. |
| 7 | `ready_for_pickup` | Inspector | Re-inspection **passed**; car is signed off but still at the garage. |
| 8 | `in_our_park` | Driver | Car is back at base and rentable. Ticket still open for paperwork. |
| 9 | `closed` | Controller | Cost and invoice finalised. |

### The branches

| State | Meaning |
|---|---|
| `diagnostic_cleared` | Diagnosed, nothing wrong. **Terminal — no ticket ever existed.** |
| `on_site_pending` | **The mobile lane.** A minor job (battery, bulb, tyre) done where the car is parked. A committed ticket, but the car stays **free to rent**, tagged "Pending Maintenance". No dispatch, no garage, no re-inspection. One "Mark as Serviced" closes it. |
| `reinspection_failed` | Re-inspection failed. The car does **not** bounce back to the same garage automatically — it returns to the **supervisor's** queue, flagged as returned in a bad state, so a human decides where it goes next. |
| `awaiting_invoice` | Operationally complete, financially open. The car is freed and rentable; the ticket stays open so the invoice tracker and its 3-day SLA can chase the paperwork. |
| `paused_returned_to_service` | **Rental is king.** A repair already under way is interrupted because the car is needed. All progress, notes, parts, photos and history are preserved; the stage held is remembered in `paused_from_status`, and Resume re-enters at exactly that stage. Both legs capture a full custody handover. |
| `repair_review` | A supervisor reviews the garage's video before sign-off. Currently parked as a feature. |
| `recommendation_pending` | **The coordinator approval gate.** An inspection report is not a commitment to repair. A coordinator reads the report, faults and required parts, picks the garage, and only their explicit "Start Maintenance" advances the ticket. |
| `recommendation_dismissed` | Terminal. Disposition is `rejected` or `not_required`. |
| `complaint_triage` | A customer complaint parks with the inspector, who decides how to handle it. Not a committed ticket. |
| `triage_approval_pending` | The inspector's decision to send a car in is a **recommendation**; a supervisor must sign it off before anything moves. |
| `complaint_resolved` | Handled without a garage visit. Terminal. |
| `review_rejected` | The controller rejected the request. Terminal, nothing sent externally. |

### ⚠️ The concept that trips everyone up: *fenced* states

Several states are deliberately **excluded** from `WF_TICKET_STATES`, the set that marks a car as "in maintenance":

- all pre-ticket states (`pending_review`, `inspection_requested`, `inspection_diagnostic`, `recommendation_pending`, `complaint_triage`, `triage_approval_pending`)
- `on_site_pending`
- `awaiting_invoice`
- `paused_returned_to_service`

In every one of these, **the ticket is open but the car is free to rent.**

This is intentional and load-bearing. Merely *inspecting* a car must not make it look unavailable, and neither must chasing an invoice for a repair that already finished.

**Do not write `whereNotNull('workflow_status')` and call it "in the shop".** Use the existing helpers and scopes. There is a canonical answer to "is this car at a garage" — `shopStay()` — and you should use it rather than inventing a second one.

### The legacy second axis

`event_status` (`IN` / `OUT`) is inherited from the spreadsheet this system grew out of. The workflow service **maps** `workflow_status` onto it:

- from `in_transit` through `ready_for_reinspection` the car is physically out → `event_status = 'OUT'`, `out_date` stamped at dispatch, which the operational-status cascade reads as "In Maintenance";
- on close the event becomes `'IN'` with an `actual_in_date`, freeing the car.

During `inspection_pending` / `awaiting_dispatch` the ticket sits at `'IN'` with no `out_date`, so a car merely being inspected is not counted as in the garage.

**Never set `event_status` by hand.**

### Time in stage

`last_state_change_at` is stamped centrally, only when `workflow_status` actually changes. Quiet writes that touch other columns correctly leave it alone. That column is what every "time in stage" and SLA surface reads.

---

## Part 2 — The financial rules

### The seven rules

1. **Never fabricate a number.** Unknown is `null`. Absent is absent. A fabricated `0` reads as "this cost nothing", which is a lie.
2. **One write path per entity.** Enforced by `NoFinancialBypassTest`. If your change makes that test fail, the test is right.
3. **Revenue comes from contracts** (OfficeManager), not from FleetView. In the current model Net ≈ Gross.
4. **A ticket can have many invoices.** Its cost is a **roll-up**, never a single stored scalar.
5. **All vehicle expense is read through one interface** — `App\Contracts\VehicleExpenseProvider`. Nothing else may contribute an expense figure: not OfficeManager, not Power BI, not vouchers or accounts, not the maintenance tables.
6. **Cost ≠ every ledger line.** Some lines are not spend on the vehicle at all — for example a car hired in from another company and recharged through the same ledger. Aggregates remove them; the history view still returns them, **flagged**; and `exclusions()` names them so the UI can *say* what was taken out. **Nothing is dropped silently.**
7. **Money UI is feature-flagged** behind `SHOW_FINANCIALS`. The service log is deliberately money-free.

### Why the seam exists

```
Excel  ->  VehicleExpenseProvider  ->  FleetView     (today)
Odoo   ->  VehicleExpenseProvider  ->  FleetView     (intended)
```

Consumers know only the interface, so replacing the source changes no UI and no profit or cost-per-km calculation. See [16-Odoo-Integration.md](16-Odoo-Integration.md).

### ⚠️ The expense ledger is dying

`vehicle_expenses` volume collapsed by roughly **99% after March 2026**. Every cost figure derived from it is effectively **historical**, not current.

This is precisely why the provider interface carries a `freshness()` method. A frozen ledger fails *silently*: the figures keep rendering, keep looking precise, and describe a fleet that stopped existing months ago. **Do not present ledger-derived cost as current without checking freshness.**

### Cost provenance

Every `MaintenanceLineItem` carries `finding_text` — the fault the money was spent on — plus `entry_source` (`manual` / `ocr` / `import`). No cost in this system is allowed to be unexplained or of unknown origin.

---

## Part 3 — Roles and permissions

**10 roles**, **47 permissions**, via `spatie/laravel-permission`. Permission names are `resource.action`. **`super-admin` bypasses every check.**

### The roles

| Role | Who it represents |
|---|---|
| `super-admin` | Full bypass. |
| `admin` | Full administrative access. |
| `manager` | Broad management access across all domains. |
| `operations` | Day-to-day fleet operations. |
| `maintenance` | The maintenance controllers — own tickets and money. |
| `supervisor` | Dispatch decisions, garage choice, sign-offs. |
| `inspector` | Diagnosis, inspection reports, complaint triage, re-inspection. |
| `logistics` | Vehicle movement, dispatch, pickups. |
| `finance` | Billing and financial views. |
| `viewer` | Read-only across most domains. |

### The full permission matrix

| Permission | Roles holding it |
|---|---|
| `billing.manage` | admin, finance, manager, operations, super-admin |
| `billing.view` | admin, finance, manager, operations, super-admin, viewer |
| `booking_readiness.manage` | admin, manager, operations, super-admin |
| `booking_readiness.view` | admin, finance, manager, operations, super-admin, viewer |
| `components.backfill` | admin, super-admin |
| `components.manage` | admin, maintenance, manager, super-admin, supervisor |
| `components.view` | all roles |
| `contracts.manage` | admin, manager, operations, super-admin |
| `contracts.view` | admin, finance, manager, operations, super-admin, viewer |
| `customers.manage` | admin, finance, manager, operations, super-admin |
| `customers.view` | admin, finance, manager, operations, super-admin, viewer |
| `dashboard.view` | all roles |
| `drivers.manage` | admin, manager, super-admin |
| `drivers.view` | admin, manager, operations, super-admin, supervisor, viewer |
| `insights.view` | admin, finance, maintenance, manager, operations, super-admin, viewer |
| `inspections.manage` | admin, inspector, maintenance, manager, operations, super-admin |
| `inspections.view` | admin, finance, inspector, maintenance, manager, operations, super-admin, viewer |
| `logistics.claim` | admin, logistics, manager, operations, super-admin |
| `logistics.dispatch` | admin, logistics, manager, operations, super-admin, supervisor |
| `logistics.view` | admin, inspector, logistics, maintenance, manager, operations, super-admin, supervisor, viewer |
| `maintenance.approve` | admin, maintenance, manager, super-admin |
| `maintenance.checkpoint.create` | admin, maintenance, manager, super-admin, supervisor |
| `maintenance.checkpoint.manage` | admin, maintenance, manager, super-admin, supervisor |
| `maintenance.delegate` | admin, maintenance, manager, super-admin, supervisor |
| `maintenance.initiate` | admin, inspector, maintenance, manager, super-admin |
| `maintenance.logistics` | admin, logistics, maintenance, manager, super-admin, supervisor |
| **`maintenance.manage`** | admin, maintenance, manager, super-admin — **the money owners** |
| `maintenance.recurring.manage` | admin, maintenance, manager, super-admin |
| `maintenance.recurring.view` | admin, maintenance, manager, super-admin, supervisor |
| `maintenance.view` | admin, inspector, logistics, maintenance, manager, operations, super-admin, supervisor, viewer |
| `operations.manage` | admin, manager, operations, super-admin |
| `operations.override` | admin, manager, super-admin |
| `parts.investigate` | admin, maintenance, manager, super-admin |
| `parts.purchase` | admin, maintenance, manager, operations, super-admin, supervisor |
| `parts.request` | admin, inspector, logistics, maintenance, manager, operations, super-admin, supervisor |
| `parts.view` | all roles |
| `registration.manage` | admin, manager, super-admin |
| `registration.view` | admin, maintenance, manager, operations, super-admin, viewer |
| `reminders.manage` | admin, finance, maintenance, manager, operations, super-admin |
| `reminders.view` | admin, finance, maintenance, manager, operations, super-admin, viewer |
| `sync.run` | admin, manager, super-admin |
| `users.manage` | admin, super-admin |
| `vehicles.approve_odometer` | admin, super-admin |
| `vehicles.manage` | admin, manager, super-admin |
| `vehicles.view` | all roles |
| `vendors.manage` | admin, manager, super-admin |
| `vendors.view` | admin, inspector, logistics, maintenance, manager, super-admin, supervisor, viewer |

Note how tightly the sensitive ones are held: `users.manage` and `vehicles.approve_odometer` are **admin and super-admin only**.

---

## Part 4 — The standing design rulings

These shaped the codebase. They are not suggestions.

1. **One source of truth per fact, declared explicitly.** Where two systems disagree, the code names the winner rather than averaging them.
2. **No fabricated data.** Unknown is `null`.
3. **Nothing is dropped silently.** If a total excludes something, the UI must be able to say what and why.
4. **Traceability / Data Origin on every page.** No black boxes. Every surface can say where its numbers came from and how fresh they are.
5. **One write path per entity**, guarded by tests.
6. **Seams, not rewrites.** Where a data source will change, depend on an interface.
7. **Operational language in the UI.** Plain sentences about cars and repairs, not engine jargon. There is a banned-words list; the engine's own view lives behind "Technical details".
8. **Reason codes, never English strings.** Machine-generated explanations are emitted as codes plus parameters and translated at the edge — the app is bilingual.
9. **The ticket is the single source of truth** for maintenance. No separate vehicle-page logging path.
10. **Rental is king** — with an important caveat: the *hard block* that once prevented renting a car with open maintenance was **removed**. Today, deferrable faults let a car rent; grounding faults do not.
11. **Breakdown is the sole maintenance type.** The other five were deliberately removed. They have been accidentally re-added once. Do not re-add them.
12. **A fault is *what × how many × where*.** Locations are curated centrally on `/vehicle-locations`, never as a per-fault-type location system.
13. **Every intelligence field is Fact, Judgement, or Derived** — declared as such, and no field exists without a consumer.
14. **Map data directly.** Do not invent inference layers or confidence scores the data cannot support.

### Condition grading

Four grades. **Red and Yellow both ground the vehicle. Only Green and Orange may rent.**

### Two doors for "send a car in"

There are exactly two: *ask for a look* (diagnostic) and *straight to garage*. The reason is **fault, reason, or note — never two of them at once**. And `request_origin` (where it came from) is a different field from `trigger_reason` (why); stamp both.

### System suggestion is not a human decision

Only the **garage** door stands down the scanner's card. "Ask for a test" *joins* the queue rather than clearing it, and a rented car still counts as available.
