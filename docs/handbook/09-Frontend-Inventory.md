# Frontend Inventory

React 19 + React Router 7 + Tailwind 3, built with Create React App. **104 pages**, **204 components**, **91 routes**.

## Where things live

```
frontend/src/
  App.js                     the route table - the fastest map of the whole UI
  pages/                     one file per screen
  components/                shared and per-domain components
  config/moduleRegistry.js   the App Launcher / module tiles
  i18n/                      phrases.ar.js and the tf() / tp() helpers
  lib/                       client-side helpers (vehicleTimeline.js, formatters, api client)
```

## Conventions

- **All user-facing text goes through `tf()` / `tp()`.** The app is bilingual English/Arabic. Run `npm run check` before committing - it checks both missing translations and hardcoded strings, plus RTL layout.
- **Filter on the server, not the client.** Filtering a paginated feed client-side only filters the loaded page. This has shipped as a real bug: a busy feed made Action Center lanes report "empty" when they were not.
- **Every page must show its Data Origin.** No black boxes - a surface that shows a number must be able to say where it came from and how fresh it is.
- **Design system: Cockpit+**, with an "Aurora" command-center theme. The rollout is page by page and is not complete, so not every screen matches.
- **Operational language.** Plain sentences about cars and repairs; engine vocabulary belongs behind a "Technical details" disclosure.

## Routes

Declared in `src/App.js`, in source order.

| Route |
|---|
| `/login` |
| `/garage-invoice/:token` |
| `/notifications` |
| `/settings` |
| `/apps/:moduleId` |
| `/readiness` |
| `/classification-review` |
| `/concept-bridge-review` |
| `/simulation` |
| `/users` |
| `/` |
| `/dashboard` |
| `/vehicles` |
| `/vehicles/:id` |
| `/odometer-approvals` |
| `/driver-dispatch` |
| `/logistics` |
| `/customers` |
| `/customers/:id` |
| `/drivers` |
| `/cleaning` |
| `/contracts` |
| `/contracts/new` |
| `/contracts/:id/edit` |
| `/contracts/:id` |
| `/inspections/schedules` |
| `/oil-projection` |
| `/service-reminders` |
| `/reminders/service` |
| `/registrations` |
| `/vendors` |
| `/components` |
| `/procurement` |
| `/garage-finder` |
| `/car-status` |
| `/car-status/:vehicleId` |
| `/maintenance-operations` |
| `/maintenance` |
| `/maintenance-hub` |
| `/maintenance-bookings` |
| `/maintenance-workflow` |
| `/in-garage` |
| `/complaints` |
| `/complaints/:id` |
| `/driver-observations` |
| `/maintenance-workflow/:id` |
| `/my-maintenance-queue` |
| `/maintenance-progress` |
| `/completed-repairs` |
| `/invoice-matching` |
| `/maintenance-foresight` |
| `/garages` |
| `/executive` |
| `/intelligence/garages/compare` |
| `/intelligence/garages/:id` |
| `/finding-keywords` |
| `/vehicle-locations` |
| `/maintenance-analytics` |
| `/maintenance-history` |
| `/damage-accidents` |
| `/parts` |
| `/parts-catalog` |
| `/warranties` |
| `/part-invoices` |
| `/recurring-fault-reviews` |
| `/cost-capture` |
| `/inspection-review` |
| `/inspections/history` |
| `/activity` |
| `/vehicle-status` |
| `/cost-intelligence` |
| `/recommendation-intelligence` |
| `/service-due` |
| `/mileage` |
| `/fuel-mileage` |
| `/mileage-reconciliation` |
| `/mileage-chain-audit` |
| `/fleet-utilization` |
| `/maintenance-swap` |
| `/data-health` |
| `/intelligence-center` |
| `/status-mismatch` |
| `/oversight/mileage` |
| `/oversight/left-garage` |
| `/oversight/severity` |
| `/oversight/misdiagnoses` |
| `/oversight/resolved-transfers` |
| `/oversight/checkpoint-compliance` |
| `/sync-audit` |
| `*` |
| `*` |

## Pages

All 104 page components, by folder.

### pages/

`CarStatus` - `CarStatusVehicle` - `ComplaintsCenter` - `CompletedRepairs` - `ComponentsDashboard` - `ConceptBridgeReview` - `Contracts` - `CostIntelligence` - `Customers` - `DamageAccidents` - `Dashboard` - `DataHealth` - `DriverObservations` - `Drivers` - `EventClassificationReview` - `FindingKeywords` - `FleetUtilization` - `FuelMileage` - `GarageFinder` - `GarageInvoicePortal` - `Garages` - `InGarage` - `InspectionReviewQueue` - `IntelligenceCenter` - `IntelligenceCenter.test` - `InvoiceMatching` - `Login` - `LogisticsDispatch` - `MaintenanceAnalytics` - `MaintenanceBookings` - `MaintenanceCheckpoints` - `MaintenanceHistory` - `MaintenanceSwap` - `MaintenanceWorkflow` - `MileageCenter` - `MileageChainAudit` - `MileageReconciliation` - `ModuleLauncher` - `ModuleOverview` - `MyMaintenanceQueue` - `NotFound` - `Notifications` - `PartInvoices` - `Parts` - `PartsCatalog` - `PartsCatalog.test` - `Procurement` - `QuickCostInput` - `ReadinessDashboard` - `RecommendationIntelligence` - `RecurringFaultReviews` - `Registrations` - `Settings` - `SimulationPanel` - `StatusMismatch` - `SyncAudit` - `Users` - `VehicleLocations` - `Vehicles` - `VehicleStatusDashboard` - `Vendors` - `Warranties`

### pages/activity/

`GlobalActivityFeed`

### pages/cleaning/

`CleaningCapture`

### pages/contracts/

`ContractDetail` - `ContractForm` - `RentalReadinessGate` - `RentalReadinessInline`

### pages/customers/

`CustomerForm` - `CustomerProfile`

### pages/drivers/

`DriverForm`

### pages/inspections/

`FleetHealth` - `InspectionSchedules`

### pages/intelligence/

`Executive` - `GarageCompare` - `GarageProfile`

### pages/logistics/

`CreateMoveModal`

### pages/operations/

`ReadinessInspectionPanel` - `RoutineMaintenancePanel` - `UpcomingRentalsPanel` - `usePanelFilter`

### pages/oversight/

`CheckpointCompliance` - `GarageInvoiceQueue` - `MileageDiscrepancies` - `Misdiagnoses` - `ResolvedTransfers` - `SeverityReview`

### pages/reminders/

`OilProjection` - `OilProjection.test` - `ServiceReminders`

### pages/vehicles/

`ConditionGradeModal` - `DispatchModal` - `FinancialsHero` - `OdometerApprovals` - `PickUpModal` - `PlateHistory` - `ReadinessChecklist` - `ServiceHistory` - `TireDetails` - `VehicleAnalytics` - `VehicleForm` - `VehicleOverviewDashboard` - `VehicleProfile`

### pages/vendors/

`VendorForm`

## Components

All 204 components, by folder.

| Folder | Components |
|---|---|
| `components/` | `BillingReconciliation`, `Brand`, `CommandPalette`, `ContractInvoices`, `ContractPayments`, `CustomerReconciliation`, `ErrorBoundary`, `ExchangeChainPanel`, `FinancialBreakdownDrawer`, `FleetPulseGrid`, `LanguageToggle`, `NotificationBell`, `NotificationCard`, `NotificationRow`, `PageStat`, `ProtectedRoute`, `RecentlyFixedCard`, `RequirePermission`, `ShortcutsHelp`, `ThemeToggle`, `WorkshopEvents` |
| `components/activity/` | `ActivityTimeline` |
| `components/analytics/` | `AnalyticsCard`, `AuditAnalytics`, `CarStatusAnalytics`, `CheckpointsAnalytics`, `ComplaintsAnalytics`, `CompletedRepairsAnalytics`, `ContractsAnalytics`, `CostIntelligenceAnalytics`, `CustomersAnalytics`, `DamageAnalytics`, `DriverObservationsAnalytics`, `DriversAnalytics`, `FleetUtilizationAnalytics`, `GaragesAnalytics`, `InspectionReviewAnalytics`, `IssueGroupsAnalytics`, `KeywordRiskAnalytics`, `LogisticsAnalytics`, `MaintenanceHistoryAnalytics`, `MaintenanceWorkflowAnalytics`, `MaintenanceWorkflowAnalyticsPanel`, `OdometerApprovalsAnalytics`, `PartsAnalytics`, `RecurringFaultsAnalytics`, `RegistrationsAnalytics`, `SyncAuditAnalytics`, `VehiclesAnalytics`, `VendorsAnalytics` |
| `components/complaints/` | `ComplaintDetailDrawer`, `ComplaintTimeline`, `stages` |
| `components/contracts/` | `ContractRepairInvoices`, `VisitJourneyPanel` |
| `components/dashboard/` | `MaintenanceProgress`, `RepeatPartPurchases` |
| `components/garages/` | `DomainLeaderboard`, `DomainMatrix`, `FaultGarageFinder`, `GarageCard`, `GarageScoreboard`, `OutcomeBar`, `phrasing`, `ScorecardOrigin`, `scorecardUtils` |
| `components/inspection/` | `BeforeAfterSlider`, `DamageFlagModal`, `FuelGaugeSlider`, `InspectionReport`, `InspectionSummary`, `VehicleDiagram` |
| `components/intelligence/` | `ConfidenceBadge`, `EvidenceDrawer`, `IntelligenceMetricCard` |
| `components/keywords/` | `KeywordKnowledgeDrawer`, `KeywordMatchTester` |
| `components/knowledge/` | `FaultKnowledgeCard`, `FaultKnowledgeCard.test`, `RepairIntelligencePanel` |
| `components/maintenance/` | `CheckpointModal`, `CheckpointTimeline`, `DelayExplanation`, `MaintenanceOperationsDrawer`, `RepairCaptureModal`, `RepairVerifyModal`, `ShopTimer` |
| `components/ops/` | `DualState`, `index` |
| `components/parts/` | `CatalogPartPicker`, `CatalogPartPicker.test`, `PartPurchaseHistory`, `PartRecordModal`, `PartReturnModal` |
| `components/readiness/` | `ReadinessPanel` |
| `components/ui/` | `Badge`, `BarChart`, `Button`, `chartUtils`, `CompositionDonut`, `ConfirmDialog`, `CountUp`, `DateRangePicker`, `Drawer`, `Field`, `FilterChips`, `FleetStatusCard`, `Gauge`, `GroupedBarChart`, `Icon`, `LeaderDonut`, `LineChart`, `MetricCard`, `Misc`, `Modal`, `MultiLineChart`, `Pagination`, `PieChart`, `Progress`, `RankedBar`, `SearchSelect`, `Segmented`, `Skeleton`, `Sparkline`, `Table`, `Tabs`, `Toast`, `Tooltip` |
| `components/vehicles/` | `ComponentRepeatAlert`, `VehicleCheckpointsPanel`, `VehicleComplaintsPanel`, `VehicleComponentsPanel`, `VehicleInvestigationTimeline`, `VehicleRepeatFaults`, `VehicleWorkflowPanel` |
| `components/vehicle-status/` | `CarStatusDrawer`, `MaintenanceTimelineDrawer` |
| `components/warranties/` | `WarrantyClaims`, `WarrantyClaims.test`, `WarrantyForm` |
| `components/workflow/` | `BreakdownIntakeModal`, `ComplaintIntakeModal`, `ComplaintTriageModal`, `CostJourney`, `CycleGuide`, `DecisionCards`, `DecisionCards.test`, `DispatchPlan`, `DispatchPlan.test`, `DriverObservationModal`, `FaultDecision`, `FaultDecision.test`, `FaultDetailPicker`, `FaultDetailPicker.test`, `FaultHistoryInsight`, `FinancialStory`, `FindingsAiSuggestion`, `FindingsAiSuggestion.test`, `FindingsList`, `FindingsPicker`, `FindingsPicker.test`, `format`, `GarageRecommendations`, `GarageRecommendations.test`, `InvoicesPanel`, `InvoicesPanel.test`, `LineItemsEditor`, `MaintenanceCarsCard`, `meta`, `OdometerContinuityHint`, `OpsCard`, `opsMeta`, `ProcurementLifecycle`, `reasons`, `RepairOutlook`, `RepairOutlook.test`, `RepairQualityCheck`, `RequiredPartsEditor`, `RequiredPartsPanel`, `RootCausePicker`, `SendCarInModal`, `severitySuggestion.test`, `SignaturePad`, `SuggestedChecks`, `SuggestedChecks.test`, `TaskRoutingModal`, `TestIntakeModal`, `TicketActionModal`, `TicketCommandView`, `TicketDetailDrawer`, `TicketParts`, `TicketWorkflowPanel`, `VehicleOpsDrawer`, `VehicleStatusSelect`, `VideoEvidence`, `WhyThisGarage`, `WhyThisGarage.test` |
| `components/workspace/` | `ActionCenter`, `ActionItem`, `AppCard`, `ModuleCard`, `ModuleTabBar`, `OperationalDashboard`, `RecentActivity` |

