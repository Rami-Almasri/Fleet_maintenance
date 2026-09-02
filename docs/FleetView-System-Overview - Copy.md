# FleetView — Complete System Overview

**A single-document introduction to the whole platform, for a developer joining cold.**

Written 2026-08-18. If anything here contradicts the code, trust the code and please correct this file.

This document explains *what the system is, what problem it solves, how it is built, and where every part of it lives*. It is meant to be read top to bottom once. After that, §14 tells you which of the other ~83 documents in `docs/` to read for whatever you are actually working on.

---

## Table of contents

1. [What FleetView is](#1-what-fleetview-is)
2. [The business it runs on](#2-the-business-it-runs-on)
3. [The people, and the process it replaced](#3-the-people-and-the-process-it-replaced)
4. [Technical stack and scale](#4-technical-stack-and-scale)
5. [Repository layout](#5-repository-layout)
6. [The domain map — all 110 models](#6-the-domain-map--all-110-models)
7. [The maintenance workflow — the heart of the system](#7-the-maintenance-workflow--the-heart-of-the-system)
8. [Where data comes from](#8-where-data-comes-from)
9. [The financial model](#9-the-financial-model)
10. [The intelligence layer](#10-the-intelligence-layer)
11. [Frontend](#11-frontend)
12. [Auth, roles and permissions](#12-auth-roles-and-permissions)
13. [The governing design rules](#13-the-governing-design-rules)
14. [Known hazards and traps](#14-known-hazards-and-traps)
15. [Environment, database, deployment](#15-environment-database-deployment)
16. [Map of the documentation](#16-map-of-the-documentation)

---

## 1. What FleetView is

FleetView is the in-house **fleet operations platform** for **Faster Cars**, a car-rental company in the UAE. It manages the physical and operational life of the fleet: what condition each vehicle is in, what is wrong with it, which garage is fixing it, what that cost, who moved it where, and whether it is fit to rent.

It is deliberately **not**:

- a **rental booking system** — that is OfficeManager (OM), an external system that owns contracts and customers;
- an **accounting system** — that is Odoo, an external system that owns bills and ledgers.

FleetView sits between them and owns the middle: **the vehicle's operational and maintenance reality.**

The clearest way to describe it in one line: *FleetView replaced a WhatsApp group.* Before it existed, the inspector, the drivers, the controllers and the garages coordinated repairs by messaging each other. Every state was in someone's head or scrolled off a chat. FleetView turns that relay into a guarded server-side state machine where every transition stamps who did it and when, and every handoff notifies the next role automatically.

---

## 2. The business it runs on

A few facts about the business shape the whole design:

- **Rental is king.** A car earning money outranks a car being fixed. Maintenance never automatically steals a vehicle from a live rental; a repair in progress can even be *paused* to release the car back to a customer, preserving all its state.
- **Repairs are outsourced to garages** (external vendors), not done in-house. So the system tracks *dispatch to* and *return from* third parties, along with the trust question: which garage actually fixes things the first time?
- **Currency is AED.** Fleet size is in the hundreds of vehicles.
- **The company runs on several systems that disagree with each other.** Reconciling them — and being honest about which one is authoritative for each fact — is a large part of what the codebase does.
- **The system is bilingual: English and Arabic.** There is an in-progress i18n rollout (`tf()` / `tp()` helpers, `npm run check:i18n`); a meaningful number of frontend files are still English-only.

---

## 3. The people, and the process it replaced

You will see these roles all over the code and docs. Knowing them makes the workflow readable:

| Role | Who / what they do |
|---|---|
| **Inspector** (referred to as *Abu Maroof*) | Test-drives vehicles, diagnoses faults, files the inspection report, runs complaint triage, performs the closing re-inspection. |
| **Controllers** (*Lin*, *Marwa*) | Own the money and the review gates. Permission: `maintenance.manage`. |
| **Supervisor** | Decides which garage a car goes to, assigns drivers, signs off transfers and failed re-inspections. |
| **Drivers / Logistics** | Physically move vehicles, capture odometer readings at pickup, relay garage status. |
| **Garages (vendors)** | External repair shops. Some submit invoices through a tokenised portal. |
| **Sales** | Gates certain flows — e.g. an oil-change recall cannot pull a car from a customer without a "Sales OK". |

The flow the system encodes: *a fault is noticed → someone requests a look → a controller reviews the request → the inspector diagnoses → a decision is made (fix in-shop, fix on-site, or nothing) → a supervisor picks a garage → a driver takes the car → the garage repairs it → a driver brings it back → the inspector re-inspects → it passes or fails → cost and invoice are finalised → the ticket closes.*

Section 7 is that same flow, as states.

---

## 4. Technical stack and scale

| Layer | Technology |
|---|---|
| Backend | **PHP 8.2, Laravel 12** |
| Auth | Laravel **Sanctum** (bearer tokens) |
| Permissions | **spatie/laravel-permission** — names in `resource.action` form |
| Database | **MySQL 8** in production; **MariaDB** locally via XAMPP |
| Frontend | **React 19**, React Router 7, **Tailwind 3**, Axios |
| External APIs | OfficeManager REST, Google Sheets (`google/apiclient`), Odoo 18 JSON-RPC |

**Scale**, counted at the time of writing:

| | Count |
|---|---|
| Eloquent models | **110** |
| Service classes | **198** |
| HTTP controllers | **87** |
| Migrations | **287** |
| Artisan commands | **95** |
| API routes | **456** |
| Frontend pages | **104** |
| Project documents (`docs/`) | **83** |

This is a big system built fast. It is well-commented — many classes carry long docblocks explaining *why*, not just *what*, and those docblocks are frequently more current than the design docs. **When in doubt, read the class docblock.**

---

## 5. Repository layout

```
fleet-fullstack/
├── ARCHITECTURE-CONVENTIONS.md   ← coding conventions & layering rules. READ FIRST if writing code.
├── DEPLOYMENT.md                 ← production deploy, server layout, permissions
├── backend/                      ← Laravel 12
│   ├── app/
│   │   ├── Console/Commands/     ← 95 artisan commands (imports, syncs, audits, backfills)
│   │   ├── Contracts/            ← interfaces that act as swap-in seams (e.g. VehicleExpenseProvider)
│   │   ├── Http/Controllers/     ← 87 controllers
│   │   ├── Http/Resources/       ← API response shaping
│   │   ├── Models/               ← 110 Eloquent models
│   │   └── Services/             ← 198 service classes — THIS is where the logic lives
│   ├── database/migrations/      ← 287 migrations
│   ├── routes/api.php            ← all 456 routes, one flat grouped file
│   └── tests/
│       ├── Crud/                 ← CRUD + notification suite (own phpunit config)
│       └── Foundation/           ← foundational guarantees
├── frontend/                     ← React 19
│   └── src/
│       ├── pages/                ← 104 pages
│       ├── components/           ← shared + per-domain components
│       ├── config/moduleRegistry.js  ← the app launcher / module map
│       ├── i18n/                 ← phrases.ar.js etc.
│       └── lib/                  ← client-side helpers
├── docs/                         ← 83 design/architecture/audit documents
└── secrets/                      ← credential handling (see its README; nothing committed)
```

**The single most important structural fact:** business logic lives in **`app/Services/`**, not in controllers and not in models. Controllers are thin — they validate, call one service, and wrap the result in `ResponseHelper`. Models carry relationships, scopes, constants and derived accessors. If you are looking for how something *works*, look in Services.

---

## 6. The domain map — all 110 models

Grouped by what they are about. This is the fastest way to orient in the schema.

### Core fleet
`Vehicle` · `VehicleRegistration` · `PlateAssignment` · `PlateCode` · `VehicleLocation` · `VehicleLocationGroup` · `VehicleGarageLocation` · `Driver` · `Vendor` (garages & suppliers) · `User`

### Rental / commercial (mirrored from OfficeManager)
`Contract` · `Customer` · `ContractMileageReading` · `ContractOilDecision`

### Maintenance — the ticket and its parts
`Maintenance` (**the ticket**) · `MaintenanceItem` · `MaintenanceTask` · `MaintenanceTaskAction` · `MaintenanceTaskAssignment` · `MaintenanceTaskLocation` · `MaintenanceLineItem` (parts & labor money lines) · `MaintenanceReason` · `MaintenanceMedia` · `MaintenanceSignature` · `MaintenanceTombstone` (deletion record) · `MaintenanceSwap` · `MaintenanceHandover` · `MaintenanceHandoverComparison` · `MaintenanceIncident` · `MaintenanceTemporaryRelease` · `MaintenanceCheckpoint` · `MaintenanceCheckpointReminder` · `MaintenanceRequiredPart` · `RepairVisit` · `RepairInspection`

### Faults, findings, condition
`FaultCatalog` · `FaultCause` · `FaultRecurrencePair` · `RecurringFaultReview` · `FindingKeyword` · `DamageCatalog` · `ServiceCatalog` · `ComponentCatalog` · `VehicleComponent` · `ComponentEvent`

### Inspections & reminders
`InspectionRecord` · `InspectionSchedule` · `InspectionType` · `InspectorPadFlag` · `ServiceReminder` · `ServiceRecord` · `ServiceDueSnooze` · `ReviewReminder` · `ContactReminder` · `OilRecallTask`

### Parts & procurement
`PartRequest` · `PartPurchase` · `PartInvoice` · `PartReturn` · `PartRfq` · `RfqLine` · `PartInvestigation` · `SupplierQuote` · `SupplierPayment` · `Warranty` · `WarrantyClaim`

### Money
`Invoice` · `InvoiceItem` · `Payment` · `PaymentAllocation` · `MaintenanceInvoice` · `GarageInvoiceSubmission` · `VehicleExpense` · `CostAdjustment`

### Logistics & movement
`LogisticsTask` · `LogisticsTaskEvent` · `VehicleLogEvent` · `OdometerBlockEvent` · `OdometerChangeRequest` · `MileageOverride`

### Complaints & oversight
`Complaint` · `ComplaintEvent` · `DriverObservation` · `ResolvedTransferFlag` · `Recommendation` · `RecommendationEvent` · `GarageRecommendationDecision` · `GarageRoutingRule`

### Intelligence & knowledge
`OntologyNode` · `OntologyEdge` · `OntologyFeedback` · `KnowledgeDocument` · `KnowledgeChunk` · `KnowledgeSource` · `KeywordProfile` · `KeywordTerm` · `KeywordEnrichmentRun` · `ConceptBridgeLabel` · `ConceptBridgeSample` · `CapabilityPromotion` · `EvidenceLink` · `TraceabilitySnapshot`

### Platform / audit
`DomainEvent` · `UserActivityEvent` · `SyncRun` · `SyncChange` · `SyncCorrection` · `AppSetting` · `ActionCatalog` · `SimulationEvent`

### API surface

All routes are in `backend/routes/api.php`, grouped by prefix. The prefixes give you the same map from the outside:

```
auth · Vehicle · Contract · Customer · Driver · Vendor · Registration
maintenance-tickets · maintenance-tasks · maintenance-operations · maintenance-swaps
Inspections · InspectionEngine · InspectionSchedules · inspector-pad
complaints · driver-observations · Oversight · recurring-fault-reviews
part-requests · part-purchases · part-invoices · part-returns · part-investigations
parts-catalog · procurement · supplier-payments · warranties
Invoice · Payment · maintenance-invoices · garage-invoice(s) · financial-documents · cost-verification
logistics · vehicle-locations · vehicle-status · car-status · readiness · booking-readiness
ServiceReminders · ContactReminders · cleaning · notifications
intelligence · concept-bridge · fault-causes · finding-keywords · event-classification
garage-recommendations · odoo-export · simulation
```

---

## 7. The maintenance workflow — the heart of the system

If you learn one thing about FleetView, learn this. It is implemented in:

- **`app/Services/MaintenanceWorkflowService.php`** — the state machine, its guards, and every transition.
- **`app/Models/Maintenance.php`** — the states themselves, as constants, each with a long comment explaining what it means.

A **ticket** *is* a `maintenances` row. The lifecycle lives in the `workflow_status` column. Every transition is **guarded**: an out-of-sequence move, or a handoff missing required data, throws `WorkflowTransitionException`, which the API maps to HTTP 422. Every transition stamps **who** and **when**, and fires an alert to the next role automatically — *the data is generated, never typed.*

### The happy path

```
pending_review          Controller reviews a driver/system-generated request
   ↓ approve
inspection_requested    Inspector notified — NOT a ticket yet
   ↓
inspection_diagnostic   Inspector test-drives and diagnoses — NOT a ticket yet
   ↓ files report (in-shop)
inspection_pending      ★ TICKET IS BORN — awaiting the supervisor's dispatch decision
   ↓ supervisor picks garage + driver
awaiting_dispatch
   ↓ driver captures odometer, picks the car up
in_transit
   ↓ garage confirms receipt
under_repair
   ↓ garage finished
ready_for_reinspection
   ↓ re-inspection PASSES
ready_for_pickup        signed off, sitting at the garage awaiting a driver
   ↓
in_our_park             car is back and rentable; ticket still open for paperwork
   ↓
closed
```

### The branches that matter

- **`diagnostic_cleared`** — the inspector diagnosed and found nothing needing repair. Terminal, no ticket ever existed.
- **`on_site_pending`** — the *mobile lane*. A minor job (battery, bulb, tyre) done where the car is parked. It is a committed ticket, but the car stays operationally **free**, tagged "Pending Maintenance". No dispatch, no garage. One "Mark as Serviced" closes it.
- **`reinspection_failed`** — the closing re-inspection failed. The car does **not** bounce back to the same garage automatically; it returns to the **supervisor's** queue, visibly flagged as returned in a bad state, so a human decides whether to re-send it there or move it elsewhere.
- **`awaiting_invoice`** — operationally complete, financially open. The car is freed and rentable; the ticket stays open so the invoice tracker and its 3-day SLA can chase the paperwork.
- **`paused_returned_to_service`** — *Rental is king.* A repair already under way is interrupted because the car is urgently needed. The car is released to service with all ticket progress, notes, parts, photos and history preserved; the stage it held is remembered in `paused_from_status`, and "Resume" puts it back at exactly that stage. Both the pause and the resume capture a full custody handover (odometer, fuel, condition, damage, signature).
- **Complaint lane** — `complaint_triage` → `triage_approval_pending` → (routed to a garage / the inspector's diagnostic) or `complaint_resolved`. A customer complaint is triaged by the inspector, but his decision to send a car in is a *recommendation* that a supervisor must approve.
- **`recommendation_pending`** — the coordinator approval gate. An inspection report is **not** a commitment to repair; a coordinator reviews the report, faults and required parts, picks the garage, and only their explicit "Start Maintenance" advances it.
- **`repair_review`** — a supervisor reviews the garage's video before sign-off. (Currently parked as a feature.)

### The concept that trips everyone up: *fenced* states

Several states are deliberately kept **out** of the set that marks a car as "in maintenance" (`WF_TICKET_STATES`). Those are the pre-ticket states, `on_site_pending`, `awaiting_invoice`, and `paused_returned_to_service`. In all of them the **ticket is open but the car is free to rent.**

This is intentional and load-bearing: merely *inspecting* a car must not make it look unavailable, and neither must chasing an invoice for a repair that already finished. If you write a query that means "is this car in the shop", use the existing helpers rather than testing `workflow_status != null`.

There is also a legacy axis: `event_status` (`IN` / `OUT`), inherited from the spreadsheet the system grew out of. The workflow service **maps** `workflow_status` onto `event_status` so older boards, SLA views and the operational-status cascade keep working. Do not set `event_status` by hand.

---

## 8. Where data comes from

```
OfficeManager API  ──►  which vehicles exist, contracts, customers   (AUTHORITATIVE, scheduled sync)
Google Sheets      ──►  maintenance log, garage locations, vehicle log  (import; one write-back export)
Excel import       ──►  vehicle_expenses ledger
Odoo 18            ──►  (planned) replaces the Excel expense ledger
Humans in the UI   ──►  tickets, faults, parts, inspections, logistics
```

### OfficeManager (OM) — the upstream authority

`app/Services/OfficeManagerClient.php` + `OfficeManagerSync.php`. A REST API authenticated with an `X-API-Key` header, paged with `?page&page_size`. Requires `OFFICEMANAGER_API_KEY` in `.env` or the client throws on construction.

The client has **two timeout profiles**, and the distinction matters: an *interactive* profile (short timeout, no retry storm) for live web requests where a user is waiting, and a *batch* profile (long timeout, retries spaced ≥30s) for sync commands, because the OM server is fragile under load. Using the batch profile in a web request will hang `artisan serve` past PHP's max execution time.

**Rules:**
- OM is the **sole source of which cars exist** and the **sole source for contracts**.
- The fleet identity key is **`CarOwnerNo`**, *not* `TrafficID`.
- Never resolve a vehicle with `where('plate_no')->first()` — plates get reassigned. Use `PlateResolver`.
- Contract ↔ maintenance linking is a strict **vehicle + date-window** match. No match means "No Log" — it is never guessed.

### Google Sheets

`GoogleSheetsService.php` is **read–write** (most importers are read-only, but the vehicle log export writes back). Importers include `MaintenanceSheetImporter`, `GarageLocationSheetImporter`, and the vehicle-log exporter.

⚠️ **Sheet imports are not reliably scheduled in production.** Data can look fresh while being stale. Never assume a scheduler entry exists for an importer — check.

### Sync auditing

`SyncRun`, `SyncChange`, `SyncCorrection` record what each sync did, with an autocorrect and retention/prune path. There is a CLI sync runner and a controlled re-import procedure. This exists because silent sync drift was a real, repeated problem.

---

## 9. The financial model

This is the part of the system with the strongest opinions. Read `docs/Cost-Source-of-Truth.md` and `docs/Financial-Source-of-Truth.md` before touching money.

### The rules

1. **FleetView never invents a financial number.** Unknown is `null`, never a fabricated `0`. A zero reads as "this cost nothing", which is a lie.
2. **One write path per entity.** There is a test — `NoFinancialBypassTest` — that fails if you add a second way to write cost. That failure is correct.
3. **Revenue comes from contracts** (OM), not from FleetView. In the current model Net ≈ Gross.
4. **A ticket can have many invoices.** A ticket's cost is a **roll-up**, not a single stored figure.
5. **All vehicle expense is read through one interface** — `app/Contracts/VehicleExpenseProvider.php`. Nothing else may contribute an expense figure: not OM, not Power BI, not vouchers, not the maintenance tables. Today the implementation reads the imported Excel ledger; the intended replacement is Odoo, and because consumers only know the interface, that swap changes no UI and no calculation.
6. **Cost ≠ every ledger line.** Some lines are not spend on the vehicle at all (e.g. a car hired in from another company and recharged through the same ledger). Aggregates remove them; the history view still returns them, **flagged**; and an `exclusions()` method names them so the UI can *say* what was taken out. Nothing is dropped silently.
7. **Money UI is feature-flagged** behind `SHOW_FINANCIALS`. The service log is deliberately money-free.

### The pieces

- `MaintenanceLineItem` — the itemised parts and labor lines. `kind` is `'part'` or `'labor'`; parts carry `part_number`, `installed_on`, warranty; labor carries hours. Each line also carries `finding_text` — **the fault the money was spent on** — so no cost is unexplained.
- `Invoice` / `InvoiceItem` / `Payment` / `PaymentAllocation` — the billing side.
- `GarageInvoiceSubmission` — garages submit invoices through a **tokenised portal** into a review queue.
- `vehicle_expenses` — the imported ledger, one row per source line, tagged by `source` (`'excel'` today, `'odoo'` later) so a re-import replaces only its own rows.
- `ExpenseCategoryClassifier` — derives the operational category (insurance / tyres / oil …) **from the free-text remark**. If categories look wrong, fix the rules and re-run `expenses:classify`.

⚠️ **The expense ledger is dying.** `vehicle_expenses` volume collapsed by roughly **99% after March 2026**. Cost figures derived from it are effectively *historical*, not current. This is exactly why the provider interface has a `freshness()` method — a frozen ledger otherwise fails silently, rendering precise-looking numbers about a fleet that stopped existing months ago.

---

## 10. The intelligence layer

A substantial part of the codebase tries to answer questions like *"which garage actually fixes things?"*, *"is this fault coming back?"*, and *"what will this repair cost?"*. It is worth understanding the **governance** around it even if you never touch it, because that governance constrains how features are allowed to be built.

### The rules that govern it

- **Every field is one of: Fact, Judgement, or Derived** — and must be declared as such. No field exists without a consumer.
- **Treat data as the source of truth.** Map data directly. Do **not** invent inference layers or confidence scores that the data cannot support.
- **Traceability is mandatory.** Every page must show its **Data Origin**. No black boxes.
- **Operational language, not engine vocabulary.** The UI speaks in plain sentences about vehicles and repairs; the engine's internal view is hidden behind "Technical details". There is a list of banned words.
- **Reason codes, never English strings.** Machine-generated explanations are emitted as codes plus parameters, translated at the edge.

### What it contains

- **Garage scorecard** (`/garages`) — case-mix-adjusted garage performance. Reliability is a hard gate.
- **Repair intelligence** — median and p90 repair durations, comeback rates, fix rates. Baselines currently: fix rate ~59.6%, comeback ~40.4%, turnaround ~2.7 days.
- **Recurring fault detection** — repeat-fault chains per vehicle.
- **A fault/finding ontology** — 105 seeded nodes and edges. Note: **seeded, not AI-generated**; it ships without an API key.
- **Concept Bridge** — a human-labelled gold set that decides whether legacy free-text maintenance data may be trusted.
- **Knowledge platform** — documents, chunks, sources.

⚠️ Be sceptical here. Internal audits flagged parts of this layer as **ungrounded**: zero real documents ingested, ~96% of ontology edges seeded rather than learned, and hardcoded confidence values. A predictive-maintenance backtest measured only **1.08× lift**, which does not earn the confidence the UI was prepared to display. Several intelligence pages have been retired. **Do not assume a document describing an intelligence feature describes running, trustworthy code** — verify against the service and its backtest.

---

## 11. Frontend

React 19 + React Router 7 + Tailwind 3, 104 pages under `frontend/src/pages/`.

- **Navigation** is consolidated into three hubs, with an **App Launcher** / module registry (`src/config/moduleRegistry.js`) as the entry point and a tabbed Operations hub.
- **Design system**: an internal system called **Cockpit+**, rolled out page by page, with an "Aurora" command-center theme. Shared primitives live in `src/components/`.
- **i18n**: English and Arabic via `tf()` / `tp()` helpers with phrase files in `src/i18n/`. Validate with `npm run check:i18n`. The rollout is incomplete — roughly 200 files still need converting. **New UI text must go through the helpers.**
- **Key pages** you will hear referenced: the maintenance workflow board, Action Center, Car Status / vehicle detail (tabbed), Vehicle Timeline (an investigation tool with the maintenance log merged in), In the Garage board, Oil Projection, Inspection Review, Readiness Dashboard, Oversight hub, Workforce Ops Center.

⚠️ One recurring class of bug worth knowing: **filtering that happens client-side on a paginated feed lies.** The Action Center's lanes once filtered only the currently loaded page, so a busy feed made lanes report "empty" when they were not. Filter on the server.

---

## 12. Auth, roles and permissions

- **Sanctum** bearer tokens for API auth.
- **spatie/laravel-permission** for RBAC. Permission names are `resource.action` — `maintenance.manage`, `registration.manage`, and so on. A **`super-admin`** role bypasses all checks.
- Routes are gated with middleware, e.g.:
  ```php
  Route::middleware(['auth:sanctum', 'permission:maintenance.manage'])->prefix('odoo-export')->group(...)
  ```
- All API responses go through **`ResponseHelper`** — uniform success and error envelopes. The standard catch is `ResponseHelper::fromException($e)`.
- User actions are audited in `UserActivityEvent`, with an activity audit trail UI and a write audit in the Workforce Ops Center.

⚠️ Note on notifications: checkpoint reminders go to **assigned user → allow-list → supervisor only**. Never broadcast to a whole delegate group.

---

## 13. The governing design rules

These are the standing rulings that shaped the codebase. Violating them produces code that reviewers will reject, or worse, numbers that look right and are wrong.

1. **One source of truth per fact, declared explicitly.** Where two systems disagree, the code names the winner rather than averaging them.
2. **No fabricated data.** Unknown is `null`. Absent is absent. Never a placeholder zero.
3. **Nothing is dropped silently.** If a total excludes something, there must be a way for the UI to say what and why.
4. **Traceability / Data Origin on every page.** No black boxes.
5. **One write path per entity**, guarded by tests.
6. **Seams, not rewrites.** Where a data source is expected to change (expenses), the code depends on an interface so the swap touches nothing downstream.
7. **Operational language in the UI.** Plain sentences, not engine jargon.
8. **The ticket is the single source of truth** for maintenance. There is no separate vehicle-page logging path.
9. **Rental is king** — but note this was partially reversed: the *hard block* preventing rental of a car with open maintenance was **removed**. Deferrable faults let a car rent; grounding faults do not.
10. **Breakdown is the sole maintenance type.** The other five were removed deliberately and have been re-added by accident once. Do not re-add them.
11. **Fault location** is curated on `/vehicle-locations` as a `what × how many × where` axis — never a per-type location system.
12. **Ask in prose, not multiple-choice.** A process note, but it reflects how requirements are gathered here.

---

## 14. Known hazards and traps

Every item below is a **previously observed failure**, not a theoretical risk.

### Data correctness

- **Schema-drift checks miss column types.** A check can report "matches exactly" while a column is `decimal(12,2)` on one side and `decimal(14,2)` on the other. For money, verify types by hand. See `docs/Invoice-Precision-Drift.md`.
- **Raw SQL against `maintenances` bypasses SoftDeletes.** Every raw query must declare whether it wants LIVE or HISTORICAL rows.
- **The odometer has ~7 writers and no owner.** Highest reading wins; `advanceOdometer` stamps the source. Continuity rules allow ±5km; a *forward* jump never blocks, a *backward* one does.
- **Duplicate-part detection only sees purchases.** Open part requests and required-part lines are invisible to it, so storing the same part twice can pass silently. The comparison is a raw string compare.
- **A withdrawn-request card exists only while the car is physically in the shop.** A car that came back is a recount, not a card.

### Database / environment

- **MariaDB locally vs MySQL 8 in production** — migrations that pass locally can fail on the server. Known trap: CHECK constraints on foreign keys.
- **MariaDB silently adds `ON UPDATE CURRENT_TIMESTAMP` to `timestamp()` columns.** For a stored moment in time, use `dateTime()`.
- **Migrations cannot run from empty** — a fresh deploy from zero would currently fail. Rebuild test databases by **cloning**, never `migrate:fresh`.
- **`laravel_test` is destroyed by the CRUD suite** (it runs `migrate:fresh`) and is already failing at HEAD.
- **Never run `artisan migrate` against `fleet_test`** — its migration ledger is broken (133 tables, 1 migrations row). Add columns there by hand.
- **Port 8000 is contested** by other projects on this machine. "Route api/X not found" usually means another project's server owns the port — check that *before* debugging routes.
- **MySQL privilege-table corruption** has happened (XAMPP dying after "Server socket created"); repaired with `aria_chk`.

### Process

- **A "dry run" is not automatically safe.** A rehearsal of a workflow sweep against the live `laravel` database once committed **56 real withdrawals**. Rehearse against a private schema.
- **Back up before anything destructive**: `php artisan db:backup`.
- **Scheduling is incomplete in production** — some importers have no scheduled command at all, so stale data looks fresh.

---

## 15. Environment, database, deployment

- Local: **XAMPP**, live database is MySQL `laravel`. Setup steps in `backend/README.md` and `frontend/README.md`.
- `.env` must include `OFFICEMANAGER_API_KEY`; credential handling is described in `secrets/README.md`. Nothing sensitive is committed.
- Backup: `php artisan db:backup`.
- Tests: `tests/Crud` runs under its own `phpunit.crud.xml` against `laravel_test`; `tests/Foundation` holds foundational guarantees. Both have their own READMEs.
- Deploy: `DEPLOYMENT.md`. ⚠️ A known production issue — **umask breaks file permissions**, requiring `chown www-data`; that fix has not been committed.

---

## 16. Map of the documentation

`docs/` holds 83 documents. They vary from current specs to historical design records. **Class docblocks in the code are often more current.** Suggested entry points by purpose:

**Start here, whatever you are doing**
- `ARCHITECTURE-CONVENTIONS.md` (repo root) — conventions and layering.
- This document.

**Maintenance workflow**
- `docs/Maintenance-Workflow-Audit.md` · `docs/Maintenance-Workflow-UseCases.md` · `docs/Maintenance-Deletion-Model.md`

**Money**
- `docs/Cost-Source-of-Truth.md` · `docs/Financial-Source-of-Truth.md` · `docs/maintenance-financial-architecture.md` · `docs/Financial-Workflow-Architecture.md` · `docs/Vehicle-Expense-Provider.md` · `docs/Invoice-Precision-Drift.md`
- Note `docs/Invoice-As-Financial-Source-of-Truth.md` describes a **design**, not fully-built code.

**Schema & API**
- `docs/Backend-Review-Schema-API-Architecture.md`

**Odoo integration specifically**
- `docs/Odoo-Integration-Handoff.md` — the companion to this document: the exact export payload, the expense-provider seam, the Odoo connection facts and the open access blocker.

**Parts & procurement**
- `docs/Parts-Purchase-Repair-Intelligence-Design.md` · `docs/Parts-Warranty-Architecture.md` · `docs/Maintenance-Procurement-Platform-Architecture.md`

**Operations manual (non-technical)**
- `docs/FleetView-Employee-Operations-Manual.md` — how staff actually use the system. Genuinely useful for understanding intent.
- `docs/Demo-Live-Walkthrough-AR.md` — an Arabic walkthrough of the live system.

**Intelligence layer** — read the evaluation *before* the architecture, so you know what is real:
- `docs/Automotive-Knowledge-Platform-Evaluation.md` · `docs/Intelligence-Platform-As-Built.md` · `docs/Repair-Intelligence-Architecture.md` · `docs/Fleet-Knowledge-Engine-Architecture.md`

**Roadmaps and plans** — aspirational; check against code before relying on them:
- `docs/Implementation-Backlog.md` (the canonical plan) · `docs/Fleet-Intelligence-Execution-Plan.md` · `docs/Phase1-Implementation-Plan.md` · `docs/data-pipeline-roadmap.md`

**Production readiness**
- `docs/FleetView-Production-Readiness-Audit.md` · `docs/QA-System-Validation-2026-08-01.md` · `docs/issues/migrations-cannot-build-fresh-database.md`

---

## One-page summary

- FleetView is the **operational** layer for a UAE car-rental fleet: condition, faults, garages, parts, movement, readiness.
- **OfficeManager owns contracts and which cars exist. Odoo owns accounting. FleetView owns everything physical and operational in between.**
- Laravel 12 + React 19; 110 models, 198 services, 456 routes, 287 migrations.
- **Logic lives in `app/Services/`.** Controllers are thin. Class docblocks are the best documentation in the project.
- The centre of the system is a **guarded maintenance ticket state machine** (`MaintenanceWorkflowService` + `Maintenance::WF_*`) that replaced a WhatsApp relay.
- The financial layer is opinionated: one source of truth, one write path, **never a fabricated zero**, and everything read through the `VehicleExpenseProvider` seam.
- The intelligence layer is large but partly ungrounded — verify before trusting.
- The traps in §14 are real. Read them before running anything destructive.
