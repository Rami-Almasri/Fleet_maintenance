# Service Layer Catalog

**This is where the business logic lives.** Controllers are thin: they validate input, call one service, and wrap the result. Models carry relationships, scopes and derived accessors. Everything else is here.

The description column is the first line of each class's docblock. Many of these classes carry far longer comments explaining *why* they work the way they do - those docblocks are usually the most current documentation in the project. When a design document and a docblock disagree, trust the docblock, then trust the code.

Total: **198 service classes**.

## app/Services

| Class | Purpose |
|---|---|
| `AccountingService` | Financial calculations (customer balances, contract totals). |
| `ActivityFeedService` | The Activity Audit Trail read layer — the single normaliser behind the Vehicle History Timeline |
| `BookingReadinessService` | Booking Readiness — the pickup-prep board's brain. For every UPCOMING booking (type-R reservation) |
| `CaptureFrictionService` | UX telemetry for the capture flow — opened when the form opens, closed when it resolves. |
| `CarStatusService` | Car Status — the Maintenance Intelligence Center. Read-only. |
| `ComplaintService` | Complaint Center — the READ layer over the first-class Complaint entity. A complaint is now its own row |
| `ComplaintWorkflowService` | Complaint Workflow — the MUTATION side of the customer-complaint entity. Every triage step runs through |
| `ComponentService` | Asset Layer — THE single write choke point for vehicle_components / component_events. |
| `ContractEligibilityService` | Contract Eligibility — the SINGLE authority for "may this vehicle go onto a rental / booking |
| `ContractExchangeService` | Contract Exchange — detect and manage "car swaps", where one retail customer returns a |
| `ContractImporter` | Cache of VIN => vehicle id. |
| `ContractRepairInvoiceService` | EVERY BILL RAISED AGAINST ONE CONTRACT, IN ONE PLACE. |
| `ContractService` | Paginated contracts with optional filters. |
| `ContractValidator` | Read-only data-quality checks on contracts. Nothing here mutates the database — |
| `CostAdjustmentService` | Recording a correction to a ticket's cost as a DOCUMENT rather than as an edit. |
| `CostIntelligenceService` | Cost Intelligence — maintenance cost per km / per day / per rental, per vehicle, plus a rental-segment |
| `CostSourceResolver` | "Which document does this dirham come from?" — asked of every line on a ticket, and answered or flagged. |
| `CostVerificationService` | Verified vs Legacy vs Unverified — the honest state of the fleet's financial record, and the metric the |
| `CustomerImporter` | Import rich customer details from the "Customers" tab (matched by CustomerNo). |
| `CustomerService` | Next "W-#####" customer number for a web-created customer (global running sequence). |
| `DamageAnalyticsService` | DAMAGE ANALYTICS — the surface damage gets in exchange for leaving the fault charts. |
| `DashboardService` | Read-only KPIs for the dashboard. |
| `DatabaseBackup` | Full logical backup of the live MySQL database via mysqldump. |
| `DataHealthService` | "Data Health" — finds INCOMPLETE or BROKEN records that make the rest of the system less |
| `DepreciationService` | Straight-line vehicle depreciation (Phase 1 — managerial). |
| `DiagnosticGateService` | The Diagnostic condition brain — decides what routine upkeep a car is DUE for, and (for the |
| `DriverAvailabilityService` | Driver Availability — a driver's physical "can they take a move right now?" state. A driver is BUSY for |
| `DriverObservationService` | Driver Handover Observation — the LIGHTWEIGHT path. A driver receives a car back and records what they |
| `DriverService` | Create a new class instance. |
| `EventClassificationService` | The SINGLE classification choke point for maintenance events. |
| `FaultLocationService` | WHERE — the one owner of "which places is this event at, and does it need any?". |
| `FaultRepairTimeService` | Per-FAULT repair time — evidence class D (Derived). |
| `FinancialCompletenessService` | "Is this ticket's money finished, and if not, what exactly is missing?" |
| `FinancialConflictService` | "Financial Conflicts" — the accounting Clean-up Hub. Surfaces ONLY the invoices that |
| `FinancialDocumentService` | Moving a financial document through its life: draft → pending → approved → paid, with cancel as the |
| `FinancialExplanationService` | Financial EXPLANATION tree — the recursive "explain every number down to the original record" layer |
| `FinancialTimelineService` | The financial story of a repair, in the order it happened: diagnosis → required part → request → |
| `FleetEvidenceService` | FLEET LEARNING — turning our own maintenance history into graph evidence. |
| `FleetUtilizationService` | Fleet Utilization — for every car, how its calendar time splits between earning, in the |
| `FleetValidationService` | Decides whether a vehicle is allowed to do something (mainly: be rented). |
| `FuelMileageService` | Fuel & Mileage Reconciliation — the live version of the "fuel_data_plate_summary" report. |
| `GarageAssignmentStrategy` | The multi-fault DECISION layer that sits on top of GarageRecommendationService's ranking. |
| `GarageImporter` | Imports garages & used-parts ("scrap") shops from the garages sheet into vendors. |
| `GarageInvoiceService` | Garage Invoice Portal — the lifecycle of an outside garage self-submitting an itemised invoice through |
| `GarageLocationSheetImporter` | Reads the maintenance sheet's N-Location tab — "Car / Garage", the hand-kept list of which car |
| `GarageRecommendationService` | The Garage Recommendation engine — learns from maintenance HISTORY which garage best fits a given |
| `GarageRoutingService` | The Smart Routing Engine — suggests which garage a maintenance ticket should go to. |
| `GoogleSheetsService` | Resolve the credentials file path (supports relative or absolute). |
| `HandoverComparisonService` | Handover Comparison Report — diffs a pause-leg handover against its matching resume-leg handover |
| `IncorrectFaultCostGuard` | A fault ruled INCORRECT must not generate NEW repair cost — and must never have its OLD cost hidden. |
| `InGarageService` | IN THE GARAGE — the one-line-per-car answer to "which of our cars is at a garage right now, and |
| `InspectionEngineService` | The read-only MONITOR over the automatic inspection engine — the data brain behind the Inspection |
| `InsuranceImporter` | Sync vehicle_registrations from the "F Insurance" tab — the source of truth for the |
| `InvoiceMatchingService` | Invoice Matching Desk — the read side of "the car is back, now key the paper against the work". |
| `InvoiceService` | Create / edit / delete MANUAL invoices (origin = 'manual') straight from the website — |
| `KeywordAiEnrichmentService` | The enrichment engine — turns one fault keyword into a grounded, cited, connected knowledge entry. |
| `KeywordOntologyService` | Free text → ranked fault concepts. The application's entry point into ontology matching. |
| `KnowledgeRetrievalService` | RETRIEVAL — finding the documentation that should ground an enrichment, before generating it. |
| `LeftGarageInvoiceService` | Left-the-Garage Invoice Queue — the single definition of "this car physically left the garage and the |
| `LogisticsDispatchService` | The single place a Logistics Dispatch is raised, claimed, walked through its round trip and closed — |
| `MaintenanceAnalyticsService` | Cost intelligence over maintenance line items: per-service price trends, |
| `MaintenanceCheckpointService` | Maintenance Checkpoint — the progress-tracking engine. Owns three concerns the controller, the |
| `MaintenanceForecastService` | Preventive-maintenance forecasting — "this car will need service SOON". |
| `MaintenanceForesightService` | Maintenance Foresight — catch a car BEFORE it breaks down, and quantify the cost of |
| `MaintenanceIncidentService` | The damage & accident log, presented straight from the maintenance records. |
| `MaintenanceInvoiceService` | One Ticket → Many Invoices — the lifecycle of a single garage bill on a maintenance ticket. |
| `MaintenanceOperationsService` | Maintenance Operations — the operational control center for every vehicle CURRENTLY IN THE WORKSHOP. |
| `MaintenanceOpsCardService` | The OPERATIONS card for one open maintenance ticket — the answer to the six questions an Operations |
| `MaintenanceOpsCenterService` | Maintenance Operations Center — the read/aggregate layer that turns the plain Service-Due board into |
| `MaintenanceReasonMatcher` | Cross-references a maintenance record to the controlled "Maintenance Reason" |
| `MaintenanceRequiredPartService` | The inspector's required parts, and the Part Requests they raise. |
| `MaintenanceReturnService` | Reconciles a maintenance CONTRACT's open/closed state against what the |
| `MaintenanceSheetImporter` | Imports the "N-Maintenance & Repair" Google-Sheet log into the `maintenances` table |
| `MaintenanceTaskService` | The TASK-LEVEL engine — the per-fault tracking layer on top of the ticket lifecycle. |
| `MaintenanceVisitJourneyService` | THE VISIT JOURNEY — everything that happened to a car under ONE maintenance contract. |
| `MaintenanceWorkflowService` | The Fleet Maintenance Workflow engine — the server-side state machine that replaces the manual |
| `MatchExplanationService` | EXPLAINABILITY — why the engine answered the way it did, in plain sentences. |
| `MileageBaselineService` | Global Mileage Baseline — the self-healing odometer engine. |
| `MileageChainService` | Mileage Chain Audit — verifies the odometer hands off cleanly from one contract to the next. |
| `NotificationScanner` | The single place that turns "current fleet conditions" into notifications. |
| `OdometerContinuityService` | Odometer Continuity Rules — the single referee for "does this new reading make sense given the last |
| `OdooExportService` | Manual-to-Odoo bridge (DOWNSTREAM, read-only). |
| `OfficeManagerClient` | Thin client for the OfficeManager API (the primary source of truth). |
| `OfficeManagerSync` | Syncs data FROM the OfficeManager API into our tables (the API is the source of truth). |
| `OilChangeImporter` | Import the per-car service interval + last-service baseline from the "Oil Change" tab of |
| `OilChangeProjectionService` | Oil-change projection for cars that are OUT on rental — Evidence class **P** (prediction). |
| `OntologyFeedbackService` | CONTINUOUS LEARNING — capturing human corrections and feeding them back into the engine. |
| `OntologyGraphService` | Reading and writing the automotive knowledge graph. |
| `OperationsService` | Every change of a vehicle's situation (rent, maintenance, test drive, transfer, |
| `PartCatalogMatcher` | Resolves free-typed part wording to a catalog row — the bridge from "what someone typed" to |
| `PartIdentityService` | WHEN ARE TWO RECORDS THE SAME PART? This service is the single answer, and everything that warns |
| `PartIntelligenceService` | The brain of the Parts Purchase + Repair Intelligence workflow. Three pure, side-effect-free reads that |
| `PartInvoiceService` | The SUPPLIER half of a ticket's cost — creating the parts bill, attaching the parts it covers, and |
| `PartReturnService` | Sending a part back — recorded as an event, never by erasing the buy. |
| `PartSpendService` | PartSpendService — the ONE answer to "where does parts money go?". |
| `PartWorkflowService` | Orchestrates the Part Request lifecycle and the purchase → install cost bridge. Mirrors the existing |
| `PaymentService` | Create / edit / delete payments (receipts) recorded on the website — the collection |
| `PlateHistoryService` | Assembles the "Plate History" view for a vehicle: every car that has ever carried the same |
| `PlateResolver` | The ONE place that decides which car a license plate belongs to. |
| `ProcurementLifecycleService` | The accounting lifecycle of a repair, read as one chain per part: |
| `ProcurementReportService` | Procurement reporting — what we owe, to whom, for how long, and which suppliers are actually worth |
| `ProcurementService` | Owns the RFQ sourcing lifecycle (Phase 2). It is the SINGLE writer of the three procurement tables — |
| `ReadinessDashboardService` | The Readiness Dashboard — a role-scoped task board built around the FOUR inspection pillars: |
| `RealProfitService` | Real Net Profit — the ground-truth profitability engine that replaces OfficeManager's opaque |
| `ReconciliationService` | Financial Reconciliation (read-only MVP) — bridges ONE contract to the company's official |
| `RecurringFaultService` | Recurring-Fault Intelligence — detects a vehicle returning with the SAME confirmed problem after a |
| `RepairCaptureService` | Tier 1 capture — the four answers that turn a closed ticket into a learnable repair. |
| `RepairInspectionService` | The Post-Repair Inspection layer — records the structured QC verdict a car gets at the |
| `RepairVerificationService` | "Verify repair" — the inspector's independent confirmation, and a separate act from the repair. |
| `ReviewReminderService` | "Remind me about this request later" — the engine behind the Inspection Review Queue's snooze. |
| `ServiceWindowService` | In-Service Date — the single source of truth for "when did a car start earning", shared by every |
| `StatusMismatchService` | Finds vehicles whose lifecycle status (the OfficeManager AssetStatus stored on the |
| `SupplierPaymentService` | Paying suppliers and garages — and keeping every invoice's settlement derived from those payments |
| `TicketCostJourneyService` | The ticket's complete cost journey, read end to end: |
| `UserActivityService` | The brain behind the Workforce Operations Center. |
| `VehicleFaultRecurrenceService` | ONE CAR's repeat-fault story — "this car keeps breaking down with the SAME thing". |
| `VehicleFinancialBreakdownService` | Financial traceability for ONE vehicle — the drill-down behind every number on the Fleet |
| `VehicleImporter` | ENRICH our API-sourced cars from the "Faster" master tab (make/model/color/category + |
| `VehicleLifeStreamService` | The fleet-status side of the Vehicle Life-Stream page — one "where is this car RIGHT NOW" chip per |
| `VehicleLogService` | Writes the vehicle historical event log — the audit trail behind the Maintenance Workflow. |
| `VehicleLogSheetExporter` | Formats vehicle_log_events into flat spreadsheet rows for the Vehicle Timeline export. |
| `VehicleReadinessService` | The Readiness data provider — the single "how fit is this car?" authority. It runs one checklist |
| `VehicleRegistrationImporter` | Import RTA fines + registration status from the "F RTA" tab, one row per car |
| `VehicleRegistrationService` | Vehicle-anchored registration + insurance coverage for EVERY active car. |
| `VehicleService` | Create a new class instance. |
| `VehicleStatusDashboardService` | Vehicle Status Dashboard — the "single pane of glass" follow-up board (one row per vehicle): |
| `VehicleStatusImporter` | Overlay each car's REAL status from the fleet "Status" sheet (Code / Plate / Chassis / Status), |
| `VehicleSuggestedChecksService` | "What should the inspector actually look at on THIS car?" — the one place that answers it. |
| `VendorInsuranceImporter` | Pulls the distinct insurance companies from the "F Insurance" tab into the |
| `VendorService` | Create a new class instance. |
| `WarrantyService` | Every write to a warranty or a claim goes through here. |
| `WorkshopEventService` | Create / edit / delete WORKSHOP EVENTS (rows in `maintenances`, origin = 'manual') |

## app/Services/Components

| Class | Purpose |
|---|---|
| `ComponentLifecycle` | The PURE lifecycle math behind Vehicle Installed Components — no database, no models, no clock |
| `ComponentReadModel` | Vehicle Installed Components — the READ side of the Asset Layer. |

## app/Services/Expenses

| Class | Purpose |
|---|---|
| `ExcelVehicleExpenseProvider` | Excel-backed expense provider — reads the imported `vehicle_expenses` rows (source = 'excel') and |
| `ExpenseCategoryClassifier` | Expense category classifier — turns a free-text expense remark into ONE operational bucket. |

## app/Services/Explainability

| Class | Purpose |
|---|---|
| `Confidence` | Confidence grade of a value — how much to trust it, and why. Essential for operational intelligence: |
| `Explainer` | Contract every Intelligence module implements to plug into the shared Explainability platform. |
| `ExplanationContext` | The audit + snapshot envelope every explanation is produced under. It answers "under what conditions |
| `ExplanationEngine` | The Explainability platform's entry point — the shared engine every Intelligence module runs through. |
| `ExplanationGraph` | The canonical explanation model: a directed acyclic graph of self-describing nodes, shared across |
| `FinancialExplainer` | Finance module on the Explainability platform — Profitability + Cost Intelligence. |
| `NodeType` | Node types in the Explainability graph. A node's type drives how the UI renders it and what the |
| `ServiceDueExplainer` | Maintenance-Intelligence module on the SAME Explainability platform — proof that the graph is not a |

## app/Services/Garage

| Class | Purpose |
|---|---|
| `DecisionLearning` | What the operation is telling us by the garages it actually picks. |
| `FaultCriticality` | How much should this fault influence the decision? |
| `ForecastCalibration` | Was the forecast any good? |
| `GarageOutcomeForecaster` | "If we send the car here, what is likely to happen?" — the forecast layer under the garage |
| `GarageScorecardService` | THE GARAGE SCORECARD — "what is this garage good at, and where does it have a problem?" |
| `MetricDictionary` | What every number on the recommendation actually means. |
| `PerFaultRecommender` | A recommendation for EVERY fault, not one recommendation for the ticket. |
| `Reason` | A reason the interface can render in ANY language. |
| `RepairCostEstimator` | Rebuilds repair cost from the vehicle expense ledger, because `maintenances.cost` cannot carry it. |
| `RepairCostIndex` | What a repair is likely to cost, and — the part that matters — how much that figure is worth. |
| `RepairOutlook` | WHAT THE GARAGE WILL ACTUALLY DO — the inspector's findings, answered with the work they imply. |
| `ScoreBreakdown` | The 0–100 match score, as something a supervisor can audit without reading PHP. |

## app/Services/Intelligence

| Class | Purpose |
|---|---|
| `CapabilityContext` | The decision being made right now. |
| `CapabilityHealth` | One number for a product owner, computed from the dimensions that already exist. |
| `CapabilityPolicy` | WHEN, WHERE and HOW OFTEN a capability's card may appear — declared as data, outside the |
| `CardArbitrator` | Chooses what is shown, knowing NOTHING about where the cards came from. |
| `ComebackCapability` | The comeback warning — the first capability on the shared pipeline, and the card that argues |
| `Confidence` | How much weight a Decision Card is allowed to carry. |
| `DecisionCard` | One recommendation, at one decision, with its working shown. |
| `DecisionEngine` | Runs the registered capabilities and hands their cards to the arbitrator. |
| `Evidence` | What a capability found in history, and how much it is worth. |
| `EvidenceLedger` | The intelligence platform's operating KPI: what every capability is waiting on, in numbers. |
| `EvidenceRequirement` | One capability's evidence position — what it needs, what it has, and when it will be ready. |
| `ExecutiveDashboardService` | The Executive Home payload — Basem's page. |
| `IntelligenceCapability` | A single thing the platform has learned how to say. |
| `IntelligenceSnapshot` | The whole platform's state, in one serialisable read — what the Intelligence Center renders. |
| `OperationalIntelligence` | The delivery layer — where a decision moment in the workflow meets the intelligence pipeline. |
| `PolicyRegistry` | Every capability's orchestration rules, in one place. |
| `PromotionGate` | Decides whether a capability may stop reasoning from a proxy — by measuring, not by waiting. |
| `RecommendationRecorder` | The learning loop's write side — generic across every capability. |

## app/Services/Knowledge

| Class | Purpose |
|---|---|
| `ConceptBridgeBenchmark` | Scores the Concept Bridge benchmark — and keeps the two tracks apart. |
| `ConfidenceInputs` | The EXACT, explicit inputs the ConfidenceScorer reasons over. A plain value object (no DB, no time |
| `ConfidenceScore` | The scorer's verdict. `score` is 0–100, `band` is high/medium/low, and `reasons` are the plain-language |
| `ConfidenceScorer` | Turns the evidence behind a repair recommendation into a single, HONEST confidence score. |
| `GaragePerformanceQueryService` | Garage Performance Score (docs/Repair-Intelligence-Architecture.md §7.1). |
| `RepairCohortStats` | PURE aggregation over a cohort of matched past repairs (plain arrays — no DB, no models), so every |
| `RepairDurationQueryService` | Repair Intelligence — the duration substrate (docs/Repair-Intelligence-Architecture.md §3, §8). |
| `RepairHistoryQueryService` | Layer 2 — the retrieval spine of the Fleet Knowledge Engine. Given a fault (existing or being typed), |
| `RepairIntelligencePresenter` | The INTERFACE BOUNDARY. Maps the engine's rich internal output (RepairRecommendationService) to the |
| `RepairRecommendationService` | Layer 4 — the composer. Turns the tiered cohort (RepairHistoryQueryService) into the single actionable |
| `RepairSignatureClassifier` | Resolves free-text repair notes to the CANONICAL REPAIR SIGNATURE vocabulary. |
| `SimilarRepairQuery` | The immutable input to the Knowledge Engine's retrieval. Deliberately decoupled from any Eloquent row |
| `SimilarRepairResult` | The output of RepairHistoryQueryService::similarRepairs — the four confidence tiers of matched past |

## app/Services/RepairIntelligence

| Class | Purpose |
|---|---|
| `ComebackBacktest` | Scores the comeback rule against history — under either definition of "was it right?". |
| `HistoricalAnswer` | The answer to a historical question — data AND its own trustworthiness, together. |
| `ProjectionRepairHistoryQuery` | The `maintenance_signatures` projection behind the query interface. |
| `RepairHistoryQuery` | The intelligence platform's public API for historical knowledge. |

## app/Services/Schema

| Class | Purpose |
|---|---|
| `SchemaHealthService` | Is the database actually shaped the way the intelligence layer assumes — and is this environment even |

## app/Services/State

| Class | Purpose |
|---|---|
| `EffectiveState` | The canonical operational state of a vehicle for one open ticket: the two axes plus the deterministic |
| `MaintenanceDelay` | Immutable value object — the DERIVED "why is this ticket delayed?" answer. |
| `MaintenanceDelayResolver` | Derives WHY a maintenance ticket is delayed — the single owner of the delay reason (blueprint §5, |
| `OperationalStateLoader` | Read-model loader (blueprint Step 4, DP1-B): the ONE place that eager-loads the ticket graph the |
| `RepairState` | Immutable value object — the DERIVED service-axis (repair) condition of ONE maintenance ticket. |
| `WorkflowStateResolver` | Derives the service-axis (repair) condition of a maintenance ticket from the three persisted truths |

