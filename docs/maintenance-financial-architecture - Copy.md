# Maintenance & Logistics Architecture — the Single Source of Truth

_Last updated: 2026-06-30._

This document is the canonical description of how a vehicle's **entire maintenance journey** — and the
**costs** attached to it — flow through the system as ONE record. The maintenance ticket is the single
source of truth: when an admin opens a ticket they don't see a "repair", they see the **whole journey**:

> **Symptom → Diagnostic → Dispatch → Transfer / Logistics → Repair → Financials.**

There are no separate modules stitched together. Driver/logistics movements are **not** a side-system —
they are the **execution layer** (the moving parts) of the very same ticket. Garage transfers, workshop
entry and the return trip are all states of the ticket's life, surfaced on one board. The financial
layer (Parts + Labor → OCR → Odoo) is the tail of that same journey.

---

## 1. Source of truth: OM contracts

Contracts are managed in our **OM (Operations Management)** system; they remain the primary source of
truth. Every maintenance ticket links back to its contract:

- `maintenances.contract_id` — the strict 1:1 maintenance-header link (type-`U` contracts).
- `maintenances.linked_contract_id` — the **workflow ticket's** contract link, materialised at
  dispatch (`MaintenanceWorkflowService::resolveMaintenanceContractId`). This is the column the Odoo
  export and reporting read.

Nothing in this financial layer creates or mutates contracts — OM owns them.

## 2. The ticket is the container — the whole journey on one record

One **ticket** (`maintenances` row, `workflow_status` set) is the container that holds every stage of
the journey. Each stage is a part of the same record, not a separate log:

| Journey stage | Where it lives | Exposed on the ticket as |
|---|---|---|
| **Symptom** | `findings` JSON (`text`, `source`, `severity`) | `findings` |
| **Diagnostic** (root cause) | `fault_causes` table + per-finding `root_cause`/`root_cause_id` | `findings[].root_cause`, `tasks[].root_cause` |
| **Per-fault routing** | `maintenance_tasks` (status, garage, severity, cached cost) | `tasks`, `tasks_progress` |
| **Dispatch / Transfer / Logistics** (execution layer) | `maintenance_task_assignments` (garage "stints") + `logistics_tasks` (the moves) | `tasks[].assignments`, `position` |
| **Repair** | `workflow_status` lifecycle + `garage_feedback` | `workflow_status`, `status_label`, `position` |
| **Financials** | `maintenance_line_items` (Parts + Labor) | `line_items`, `parts_total`, `labor_total`, `cost` |

`Maintenance::recalcFromTasks()` keeps the container roll-ups honest: `cost` (= Σ line items, via
`recalcLineItemTotals()`), headline `fault_severity`, and primary `vendor_id` are all derived from the
child tasks/lines on every change.

### 2a. The execution layer — logistics is a moving part of the ticket, not a side-module

Picking the car up, transferring it between garages, and the return trip are the **execution layer** of
the ticket's lifecycle — the moving parts of THIS ticket, not a separate task log. Physically they are
`logistics_tasks` rows, but each links back to the ticket by `maintenance_id`, and the maintenance ticket
now owns that relation:

- `Maintenance::logisticsTasks()` — every move this ticket generated.
- `Maintenance::activeMove()` — the currently-open move (`completed_at IS NULL`), if the car is in/awaiting
  transit right now.

So the maintenance board shows transit **on the ticket card** (no second logistics board to reconcile).
The standalone `/driver-dispatch` page still exists as the drivers' working surface, but the *position* it
reports is the same record the ticket reads.

### 2b. The unified live position — the backend reports WHERE the car is, automatically

`Maintenance::livePosition()` is the single function that answers "where is this car and what's happening
to it", fusing three signals **in priority**:

1. an **open move** that is actually rolling (`activeMove` in `en_route`/`picked_up`/`in_transit`/
   `to_destination`/`to_base`) → **"In Transit"** (+ destination + driver), `moving: true`;
2. else the **active garage stint** (the open task's `currentVendor`) → which garage the car is physically at;
3. else the ticket's **`workflow_status`** → the lifecycle stage label.

It returns a presentation-ready shape — `{ phase, label, detail, tone, garage, destination, driver, since,
moving, open_ticket_id }` — exposed on the ticket resource as **`position`**. The board card, the command
view and the vehicle-profile health chip all render this one field, so they can never disagree
(`vehicleHealth()` now delegates to `livePosition()`). Concretely:

- a car being **transferred between garages** reads **"🚚 In Transit · En route to <garage> · <driver>"**
  on the same ticket card — the workshop lane no longer hides the move;
- a car at the bench reads **"🔧 In Workshop · <garage>"** (or "· N garages" when faults are split);
- when a move is **delivered** (arrived), the position falls back to the workshop/stint — arrival is not transit.

> **Status note (2026-06-30):** the task/stint container schema + models are complete; the ticket resource
> exposes `tasks`/`tasks_progress`/`position`. **Live creation of tasks/stints by the workflow** (so a
> ticket auto-explodes into per-fault tasks as it runs, rather than only via the `maintenance:backfill-tasks`
> command) is the one remaining wiring step. Until then, line items attach at the **container level**
> (task-less "general charges"), which `recalcLineItemTotals()` rolls straight to `maintenances.cost` — so
> costs are correct and Odoo-exportable today regardless of task wiring. `position` already reads the active
> move + stint regardless, so the unified "where is the car" is live now.

## 3. Invoice processing — the hybrid strategy

### Path A — Manual entry (live today)

1. **Request the invoice.** `POST /maintenance-tickets/{ticket}/request-invoice` (perm
   `maintenance.manage`) stamps `invoice_requested_at` / `invoice_requested_by`, writes a
   `VehicleLogEvent` (`invoice_requested`), and notifies the controllers.
   - ⚠️ A garage is a **`Vendor`, not a system user** (no login, no in-app inbox). So "notify the
     garage" is implemented as an **internal prompt to the team that contacts the garage**, carrying the
     garage's name + `phone`/`email`. There is no in-app channel to a vendor; the team reaches out via
     the surfaced contact.
2. **Enter the details.** When the itemised invoice arrives, the team keys Parts + Labor into the
   **`LineItemsEditor`** → `PUT /maintenance-tickets/{ticket}/line-items` (or at the garage `ready`
   step). Diagnosis-First applies: every line must link to a finding (no ghost costs).

### Path B — OCR-ready (future)

The line-item **data structure is already OCR-ready**. An OCR pipeline does not need any new shape — it
produces the **same array** the editor produces and posts it through the **same `PUT` endpoint**. The
canonical per-line contract (see `LineItemsEditor::serializeLineItems` + `lineItemRules`):

```jsonc
{
  "kind": "part" | "labor",       // required
  "finding_text": "Brake noise",  // required — links the cost to a diagnosed symptom (Diagnosis-First)
  "description": "Front brake pads",
  "quantity": 2,                   // labor: hours
  "unit_price": 120.00,            // labor: hourly rate
  "part_number": "OEM-1234",       // parts only
  "category_key": "brakes",        // optional → Odoo product category / analytic tag
  "installed_on": "2026-06-30",    // parts only (durability)
  "warranty_months": 12,           // parts only
  "entry_source": "ocr"            // provenance: manual (default) | ocr | import
}
```

The only field that distinguishes an OCR-captured line from a hand-typed one is **`entry_source`**
(`maintenance_line_items.entry_source`, default `'manual'`). So turning on OCR is purely an ingestion
concern — the storage, validation, totals and export are unchanged.

## 4. Odoo — downstream destination (manual push)

Odoo is treated strictly as a **downstream** destination. The system **does not auto-sync**; it keeps
the data ready and lets us push on demand.

- **Read-only export endpoints** (perm `maintenance.manage`), served by `OdooExportService` /
  `OdooExportController`:
  - `GET /odoo-export/ticket/{ticket}` — one ticket's finalised Parts + Labor in Odoo shape.
  - `GET /odoo-export/contract/{contract}` — **open the contract, review, push**: aggregates every
    linked ticket's line items with a grand total.
- The payload carries the **mapping intent** for the future Odoo module:
  - a `part` line → vendor-bill line / BOM component; a `labor` line → expense / service product;
  - `category_key` → product category / analytic tag; the **vehicle** → per-asset analytic account (TCO);
  - each line carries its `finding_text` + `root_cause` (the diagnostic justification) for traceability.
- **Sync bookkeeping** lives on each line: `odoo_product_ref`, `odoo_external_id`, `odoo_synced_at`
  (all null until a push runs). The export's `sync.ready_to_push` / `sync.unsynced_lines` summarise state.

When the Odoo module is built, it consumes this exact payload and writes back the `odoo_*` refs — no
schema change, no re-shaping.

## 5. Confirmation — does the architecture support the bridge?

- **Manual-to-Odoo:** ✅ Manual entry (Path A) → structured `maintenance_line_items` → read-only
  Odoo-export endpoints (ticket + contract). The bridge stands on its own (separate controller/service).
- **Granular enough for OCR:** ✅ The line shape is fully structured (kind, qty, unit price, part no.,
  category, durability) with an `entry_source` provenance flag; OCR reuses the existing endpoint.
- **Granular enough for Odoo:** ✅ Every line maps to an Odoo line type, carries category/analytic
  hints, vehicle/contract references, diagnostic justification, and dedicated `odoo_*` sync columns.
- **Ticket as container:** ✅ Findings, root causes, line items (+ totals) are exposed on the ticket;
  tasks/stints schema + exposure are in place. Remaining: live task/stint creation by the workflow.
