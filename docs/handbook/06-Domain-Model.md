# Domain Model

The 110 Eloquent models, what they mean, and how they relate.

For raw column-level detail see [04-Database-Schema.md](04-Database-Schema.md). This document is the map; that one is the territory.

---

## The five entities that matter most

If you understand these, the rest follows.

### `Vehicle`
The centre of the system. Almost everything hangs off a vehicle. **The master list of which vehicles exist comes from OfficeManager, not from FleetView** — you do not create vehicles here. The identity key against OM is `CarOwnerNo` (*not* `TrafficID`), and the expense ledger joins on `car_serial`.

**Never look a vehicle up by plate** (`where('plate_no')->first()`). Plates get reassigned between cars. Use `PlateResolver`.

### `Contract`
A rental contract, mirrored from OfficeManager. **OM is authoritative**; FleetView does not create ordinary rental contracts. Contracts are how revenue enters the system and how a repair is tied to a rental period. Linking a maintenance ticket to a contract is a strict **vehicle + date-window** match — no match means "No Log", never a guess.

One exception: **type-U maintenance contracts** *are* created by FleetView, to represent a car that is off-rent because it is being worked on.

### `Maintenance` — **the ticket**
The single most important model in the project. A maintenance ticket *is* a `maintenances` row. Its `workflow_status` column carries the lifecycle described in [10-Business-Rules.md](10-Business-Rules.md), and `Maintenance.php` defines every state as a constant with a long explanatory comment.

The ticket is the **single source of truth** for maintenance. There is deliberately no separate vehicle-page logging path.

Note the two parallel status axes:
- `workflow_status` — the app-driven lifecycle (the state machine).
- `event_status` (`IN` / `OUT`) — a legacy axis inherited from the spreadsheet this system grew out of. The workflow service **maps** the lifecycle onto it so older boards and the operational-status cascade keep working. **Never set `event_status` by hand.**

### `MaintenanceLineItem`
The money. One row per part or labour line, with `kind` = `'part'` or `'labor'`. Parts carry `part_number`, `installed_on`, warranty dates; labour carries hours.

Critically, each line also carries **`finding_text`** — the fault the money was spent on. That is deliberate: no cost in this system is allowed to be unexplained.

It also carries `odoo_product_ref`, `odoo_external_id` and `odoo_synced_at`, reserved for a future accounting sync. They are currently unused.

### `Vendor`
Garages **and** parts suppliers. Repairs are outsourced, so vendors are central: which garage got the car, how long it took, whether the fault came back. The garage scorecard is built on this.

---

## Everything else, grouped

### Core fleet
`Vehicle` · `VehicleRegistration` · `PlateAssignment` · `PlateCode` · `VehicleLocation` · `VehicleLocationGroup` · `VehicleGarageLocation` · `Driver` · `Vendor` · `User`

`VehicleLocation` / `VehicleLocationGroup` are the curated **fault location axis** — a fault is *what × how many × where*. Locations are curated centrally on `/vehicle-locations`; they are not a per-fault-type location system.

### Rental & commercial (mirrored from OfficeManager)
`Contract` · `Customer` · `ContractMileageReading` · `ContractOilDecision`

`ContractOilDecision` supports the oil-change follow-up flow: chasing a real odometer reading from a car that is out on rent.

### Maintenance — ticket and structure
`Maintenance` · `MaintenanceItem` · `MaintenanceTask` · `MaintenanceTaskAction` · `MaintenanceTaskAssignment` · `MaintenanceTaskLocation` · `MaintenanceLineItem` · `MaintenanceReason` · `MaintenanceMedia` · `MaintenanceSignature` · `MaintenanceTombstone` · `MaintenanceSwap` · `MaintenanceHandover` · `MaintenanceHandoverComparison` · `MaintenanceIncident` · `MaintenanceTemporaryRelease` · `MaintenanceCheckpoint` · `MaintenanceCheckpointReminder` · `MaintenanceRequiredPart` · `RepairVisit` · `RepairInspection`

- **`MaintenanceTask`** is a *container of faults*, and is **single-garage**. A ticket can hold several tasks.
- **`MaintenanceHandover`** captures custody transfer — odometer, fuel, condition, damage, signature — on both the pause and resume legs when a car is pulled out of a repair for a rental.
- **`MaintenanceTombstone`** records deletions, so a removed ticket leaves evidence.
- **`MaintenanceCheckpoint`** is the daily chase on cars sitting at garages. Its reminders go to **assigned user → allow-list → supervisor only**, never a broad delegate group.

### Faults, findings, condition
`FaultCatalog` · `FaultCause` · `FaultRecurrencePair` · `RecurringFaultReview` · `FindingKeyword` · `DamageCatalog` · `ServiceCatalog` · `ComponentCatalog` · `VehicleComponent` · `ComponentEvent`

The distinction between a **service** (planned, scheduled work like an oil change), a **fault** (something broken), and **damage** (impact/accident) is a hard boundary in this codebase, with its own audit documents. Do not blur them.

`VehicleComponent` / `ComponentEvent` are the Asset Layer — a shadow record of installed components and their lifecycle. `ComponentService` is the single write choke point.

### Inspections & reminders
`InspectionRecord` · `InspectionSchedule` · `InspectionType` · `InspectorPadFlag` · `ServiceReminder` · `ServiceRecord` · `ServiceDueSnooze` · `ReviewReminder` · `ContactReminder` · `OilRecallTask`

`ReviewReminder` is the only reminder kind backed by a **stored future timestamp**; the others are computed. Cancellation behaviour differs by kind.

### Parts & procurement
`PartRequest` · `PartPurchase` · `PartInvoice` · `PartReturn` · `PartRfq` · `RfqLine` · `PartInvestigation` · `SupplierQuote` · `SupplierPayment` · `Warranty` · `WarrantyClaim`

"Are we waiting on a part?" has exactly one owner: the ticket's **`part_requests`**. An older `awaiting_parts` workflow state was deliberately removed because it was a second, blinder answer to the same question.

⚠️ Duplicate-part detection currently sees **purchases only** — open part requests and required-part lines are invisible to it, so storing the same part twice can pass silently.

### Money
`Invoice` · `InvoiceItem` · `Payment` · `PaymentAllocation` · `MaintenanceInvoice` · `GarageInvoiceSubmission` · `VehicleExpense` · `CostAdjustment`

- A **ticket can have many invoices**; its cost is a roll-up, not a stored scalar.
- `GarageInvoiceSubmission` is the tokenised portal through which external garages submit invoices into a review queue.
- `VehicleExpense` is the imported ledger — one row per source line, tagged by `source` (`'excel'` today, `'odoo'` planned).

### Logistics & movement
`LogisticsTask` · `LogisticsTaskEvent` · `VehicleLogEvent` · `OdometerBlockEvent` · `OdometerChangeRequest` · `MileageOverride`

**`LogisticsTask` is the one home for vehicle movements.** If you need to move a car, it is a logistics task — not a field on something else.

⚠️ The odometer has roughly **seven writers and no single owner**. Highest reading wins; `advanceOdometer` stamps the source. Continuity tolerance is ±5 km, a *forward* jump never blocks, a *backward* one does.

### Complaints & oversight
`Complaint` · `ComplaintEvent` · `DriverObservation` · `ResolvedTransferFlag` · `Recommendation` · `RecommendationEvent` · `GarageRecommendationDecision` · `GarageRoutingRule`

A **complaint is a first-class entity, not a maintenance ticket.** Ops logs it; the inspector's triage lane resolves it. `DriverObservation` is a deliberately lightweight note — not a complaint, not a ticket.

### Intelligence & knowledge
`OntologyNode` · `OntologyEdge` · `OntologyFeedback` · `KnowledgeDocument` · `KnowledgeChunk` · `KnowledgeSource` · `KeywordProfile` · `KeywordTerm` · `KeywordEnrichmentRun` · `ConceptBridgeLabel` · `ConceptBridgeSample` · `CapabilityPromotion` · `EvidenceLink` · `TraceabilitySnapshot`

⚠️ Treat this layer as provisional — see [15-Known-Issues.md](15-Known-Issues.md).

### Platform & audit
`DomainEvent` · `UserActivityEvent` · `SyncRun` · `SyncChange` · `SyncCorrection` · `AppSetting` · `ActionCatalog` · `SimulationEvent`

The `Sync*` trio records what each external sync did and what it changed, with autocorrect and pruning. It exists because silent sync drift was a repeated real problem.

---

## Reading the table below

- **Table** — only shown where the model declares `$table` explicitly; otherwise Laravel's convention applies (snake-case plural).
- **Rels** — count of relationship methods, a rough proxy for how central the model is.
- **Soft deletes** — `yes` means rows are hidden, not removed. **Raw SQL bypasses this**; every raw query against such a table must declare whether it wants live or historical rows.

## All 110 models

| Model | Table | Rels | Soft deletes | Purpose (from docblock) |
|---|---|---|---|---|
| `ActionCatalog` | `action_catalog` | 0 |  | One entry in the generic action vocabulary. See the create_action_catalog_table migration for why |
| `AppSetting` |  | 0 |  | A single runtime-adjustable setting (key → JSON value). The UI-editable counterpart to the static |
| `CapabilityPromotion` |  | 0 |  | One decision about whether a capability may reason from measured evidence instead of a proxy. |
| `Complaint` | `complaints` | 7 |  | A Customer Complaint — a first-class entity (NOT a Maintenance ticket). It carries its own |
| `ComplaintEvent` | `complaint_events` | 2 |  | One entry in a complaint's timeline. Immutable once written — the append-only record of what happened |
| `ComponentCatalog` | `component_catalog` | 6 |  | A component TYPE — the dictionary entry that says what kind of thing a part is and how it is |
| `ComponentEvent` |  | 7 |  | One line of a component's biography — APPEND-ONLY. No service exposes an update or delete on |
| `ConceptBridgeLabel` |  | 2 |  | One answer to one benchmark question. |
| `ConceptBridgeSample` |  | 1 |  | One frozen question in the Concept Bridge benchmark: a real ticket segment plus what the matcher |
| `ContactReminder` |  | 6 |  | A follow-up to-do tied to a garage / vendor: "Call {vendor} about {subject}". |
| `Contract` |  | 11 |  |  |
| `ContractMileageReading` |  | 3 |  | A customer-reported odometer reading captured during an open rental — Evidence class **F** |
| `ContractOilDecision` |  | 8 |  | What a person decided about a rental that will finish past its oil tolerance — Evidence class |
| `CostAdjustment` | `cost_adjustments` | 5 |  | A reasoned, approved correction to a ticket's cost — the fourth source document. |
| `Customer` |  | 1 |  |  |
| `DamageCatalog` | `damage_catalog` | 1 |  | One kind of DAMAGE the fleet can record — the authoritative "what was done to the car" vocabulary. |
| `DomainEvent` |  | 1 |  | One recorded fact or judgement. Append-only, enforced in code. |
| `Driver` |  | 1 |  |  |
| `DriverObservation` | `driver_observations` | 4 |  | A Driver Handover Observation — the lightweight internal-note path recorded when a driver receives a car |
| `EvidenceLink` | `evidence_links` | 4 |  | "This claim is backed by that document." The citation behind one thing the engine asserts. |
| `FaultCatalog` | `fault_catalog` | 2 |  | A FAULT type — one entry in the failure/defect menu (leaks, noises, overheating, electrical faults). |
| `FaultCause` |  | 3 |  | One entry in the Symptom → Root-Cause knowledge base. |
| `FaultRecurrencePair` |  | 3 |  | One deduplicated fault event, paired with the next occurrence of the same fault on the same car. |
| `FindingKeyword` |  | 7 |  | One entry in the maintenance findings keyword library, with its baseline RISK grade. |
| `GarageInvoiceSubmission` |  | 4 |  | One garage-submitted invoice awaiting (or through) the team's audit — see the Garage Invoice Portal. |
| `GarageRecommendationDecision` |  | 5 |  | One recorded garage-choice decision at dispatch — the durable answer to "why did we send this car here?". |
| `GarageRoutingRule` |  | 3 |  | One editable Garage Preference Rule in the Smart Routing Engine matrix. |
| `InspectionRecord` |  | 3 |  | One Vehicle Inspection record: a condition photo (stored in S3) and/or a manual |
| `InspectionSchedule` |  | 2 |  | A recurring SAFETY / OPERATIONS inspection plan for a vehicle. See the |
| `InspectionType` | `inspection_types` | 1 |  | An INSPECTION type — one entry in the checks menu (pre-rental, post-repair QC, periodic, dormancy). |
| `InspectorPadFlag` |  | 3 |  | A single Inspector's-Pad flag: the Inspector (Abu Maroof) noting an issue keyword and/or a free-text |
| `Invoice` |  | 6 |  | An invoice (charge) on a contract. Two origins coexist: |
| `InvoiceItem` |  | 1 |  | One line on a service-log invoice — a part replaced or a service performed (e.g. "Oil Filter", |
| `KeywordEnrichmentRun` |  | 2 |  | One AI enrichment attempt against one findings keyword — success or failure, always logged. |
| `KeywordProfile` |  | 1 |  | The structured engineering knowledge behind one findings keyword (1:1 with [[FindingKeyword]]). |
| `KeywordTerm` |  | 1 |  | One surface form of a findings keyword — a way a human might write the fault. |
| `KnowledgeChunk` |  | 1 |  | One retrievable passage of a knowledge document. |
| `KnowledgeDocument` |  | 2 |  | One document in the knowledge corpus — a service manual, a technical bulletin, an SAE paper, or |
| `KnowledgeSource` |  | 1 |  | One body of automotive knowledge the engine may draw on — and, critically, HOW it may draw on it. |
| `LogisticsTask` |  | 3 |  | A Logistics Dispatch task: an order to MOVE a vehicle, now run as a CLAIM-based, driver-executed |
| `LogisticsTaskEvent` |  | 3 |  | One immutable entry in a Logistics Dispatch's audit trail — "George claimed it 14:02", "Picked up |
| `Maintenance` | `maintenances` | 42 | yes | A row in `maintenances` plays one of two roles, told apart by `origin`: |
| `MaintenanceCheckpoint` | `maintenance_checkpoints` | 5 |  | One Maintenance Checkpoint — a dated progress UPDATE a responsible user (Waleed/Abdullah, or a ticket's |
| `MaintenanceCheckpointReminder` | `maintenance_checkpoint_reminders` | 4 |  | One reminder the daily Checkpoint Scan actually pushed to one supervisor about one car, on one day. |
| `MaintenanceHandover` |  | 3 |  | One immutable custody-transfer EVENT on a maintenance ticket — the pause leg (car leaves) or the |
| `MaintenanceHandoverComparison` |  | 3 |  | The Handover Comparison Report — a permanent, generated-once-per-resume diff between the pause |
| `MaintenanceIncident` |  | 3 |  | A Handover Comparison that breached its configured thresholds — must be ACKNOWLEDGED before the |
| `MaintenanceInvoice` | `maintenance_invoices` | 6 |  | One garage bill on a maintenance ticket. |
| `MaintenanceItem` |  | 1 |  | A single line on a maintenance visit (invoice-style): one service + its cost. |
| `MaintenanceLineItem` | `maintenance_line_items` | 5 |  | One billable line on a maintenance ticket — a replaced PART or a LABOR charge. Together the lines |
| `MaintenanceMedia` | `maintenance_media` | 5 |  | One Video Evidence row on a maintenance ticket — the garage's repair video, uploaded by a supervisor |
| `MaintenanceReason` |  | 0 |  |  |
| `MaintenanceRequiredPart` | `maintenance_required_parts` | 6 |  | One part the INSPECTOR expects a repair to need — a technical requirement, not a procurement request. |
| `MaintenanceSignature` |  | 2 |  | A canonical repair signature attached to a maintenance ticket. |
| `MaintenanceSwap` |  | 2 |  | A Maintenance Swap: a replacement vehicle attached to an original rental whose car went to the |
| `MaintenanceTask` | `maintenance_tasks` | 25 |  | One independently-routable FAULT on a maintenance ticket. |
| `MaintenanceTaskAction` |  | 3 |  | One thing that was physically done to fix a fault. See the create_maintenance_task_actions |
| `MaintenanceTaskAssignment` | `maintenance_task_assignments` | 5 |  | One garage "STINT" in a fault's life: the fault was at THIS garage from assigned_at until released_at. |
| `MaintenanceTaskLocation` | `maintenance_task_locations` | 2 |  | "This fault is HERE" — one link between an event and one place on the car. |
| `MaintenanceTemporaryRelease` |  | 4 |  | One out→in round trip where the car physically left the workshop MID-REPAIR (road test, customer |
| `MaintenanceTombstone` | `maintenance_tombstones` | 0 |  | A deleted sheet-synced workshop event, kept by its importer `row_hash` so the |
| `MileageOverride` |  | 1 |  | One manual mileage correction — a non-destructive overlay on a single contract reading |
| `OdometerBlockEvent` |  | 3 |  | A single REJECTED odometer entry — an attempt that violated the continuity rules hard enough to be |
| `OdometerChangeRequest` |  | 2 |  | A pending / reviewed request to change a vehicle's odometer by a SIGNIFICANT amount. |
| `OilRecallTask` |  | 4 |  | "Phone the customer and get the car back" — the operational task a recall produces. |
| `OntologyEdge` |  | 3 |  | One directed, weighted, scoped relationship in the knowledge graph. |
| `OntologyFeedback` | `ontology_feedback` | 2 |  | One human correction to the ontology — the raw material of continuous learning. |
| `OntologyNode` |  | 4 |  | One entity in the automotive knowledge graph — a fault, a component, a cause, a repair, a part. |
| `PartInvestigation` |  | 5 |  | An admin accountability record raised when the intelligence layer detects a repeated spend or a |
| `PartInvoice` | `part_invoices` | 3 |  | A SUPPLIER's bill for parts we bought ourselves — the document behind a {@see PartPurchase}'s price. |
| `PartPurchase` |  | 14 |  | A part purchase — the money event, and the bridge onto the existing maintenance cost chain. |
| `PartRequest` |  | 8 |  | A part request — the INTENT to fit a part to a vehicle, tracked through its lifecycle. |
| `PartReturn` | `part_returns` | 6 |  | A part sent back to where it came from — recorded as an event, never as an erasure. |
| `PartRfq` | `part_rfqs` | 1 |  | An RFQ — the procurement PROCESS header (Phase 2, blueprint §3a). It is NOT a single part: it covers |
| `Payment` |  | 3 |  | A payment / receipt recorded on the website — the credit (collection) side of a |
| `PaymentAllocation` | `payment_allocations` | 1 |  | One slice of a payment, pointed at one bill. |
| `PlateAssignment` |  | 1 |  | One row of the plate-history timeline: which vehicle held a plate over which date window. |
| `PlateCode` |  | 0 |  | One entry of the OM plate-code dictionary: a numeric code (OM PlateColorNo / EmNo) and the |
| `Recommendation` |  | 1 |  | One recommendation the platform made, frozen at the moment it made it. |
| `RecommendationEvent` |  | 2 |  | One thing that happened to a recommendation. |
| `RecurringFaultReview` | `recurring_fault_reviews` | 9 |  | A management REVIEW CASE for a confirmed fault that recurred after a completed repair. |
| `RepairInspection` | `repair_inspections` | 6 |  | One post-repair quality-control verdict — the structured answer to "did the garage actually fix it?" |
| `RepairVisit` |  | 2 |  | One real workshop visit, collapsed from several maintenance EVENTS. |
| `ResolvedTransferFlag` | `resolved_transfer_flags` | 3 |  | An Oversight case: a car was transferred to a different garage while EVERY fault on its ticket was |
| `ReviewReminder` | `review_reminders` | 3 |  | One person's "come back to me about this request at this time", on the Inspection Review Queue. |
| `RfqLine` | `rfq_lines` | 4 |  | One RFQ line — the bridge that makes an RFQ single- or multi-part (Phase 2, blueprint §3b). Each line |
| `ServiceCatalog` | `service_catalog` | 1 |  | A SERVICE type — one entry in the planned/preventive-work menu (oil, filters, tyres, fluids). |
| `ServiceDueSnooze` |  | 2 |  | A manager's "not now" on a service-due vehicle (Maintenance Operations Center). An operational |
| `ServiceRecord` |  | 6 |  | A performed ACTION (labor) — oil change, alignment, inspection, repair labor. An immutable |
| `ServiceReminder` |  | 2 |  | A recurring TECHNICAL maintenance due point for a vehicle (oil, filters, brakes, …). |
| `SimulationEvent` |  | 2 |  | One rolled-back-able change made by the admin Demo/Simulation Panel. |
| `SupplierPayment` | `supplier_payments` | 3 |  | One payment made to a supplier or a garage — the money actually leaving the account. |
| `SupplierQuote` | `supplier_quotes` | 2 |  | A supplier's bid on one RFQ line (Phase 2, blueprint §3c) — the multi-supplier comparison substrate. |
| `SyncChange` |  | 2 |  | One record-level change from a CMD sync: a new contract (operation=insert, full row in |
| `SyncCorrection` |  | 2 |  | One auto-correction applied during a CMD sync: a stale field the API no longer carries |
| `SyncRun` |  | 2 |  | Per-field auto-corrections recorded during this run (cleared stale data). |
| `TraceabilitySnapshot` | `traceability_snapshots` | 0 |  | One measurement of how much of the fleet's cost can be proved, taken on a date. |
| `User` |  | 2 |  |  |
| `UserActivityEvent` |  | 1 |  | One entry in the append-only workforce activity log: a login, a logout, a |
| `Vehicle` |  | 13 |  |  |
| `VehicleComponent` |  | 14 |  | ONE physical asset instance — the heart of the Asset Layer. Created once when the part enters |
| `VehicleExpense` |  | 1 |  | One imported expense line (see the vehicle_expenses migration). The SOLE store of vehicle expense — |
| `VehicleGarageLocation` |  | 2 |  | One row of the maintenance sheet's N-Location tab: a car and the garage it is at. |
| `VehicleLocation` |  | 2 |  | A PLACE on a car — one entry in the shared "where is it?" vocabulary. |
| `VehicleLocationGroup` |  | 1 |  | A SECTION of the location picker — Exterior, Wheels & Tyres, Glass & Mirrors, Lights, … |
| `VehicleLogEvent` |  | 5 |  | One append-only entry in a vehicle's historical event log — the audit trail of the |
| `VehicleRegistration` |  | 2 |  |  |
| `Vendor` |  | 1 |  |  |
| `Warranty` |  | 8 | yes | A promise somebody made us, recorded so we can hold them to it. |
| `WarrantyClaim` |  | 3 | yes | One time we went back to the counterparty and said "this one is yours". |

