# API Reference

Generated from `php artisan route:list`. Every HTTP endpoint the backend exposes, the controller method that serves it, and the permission required to call it.

## How to read this

- Base URL is the deployed host plus `/api`.
- Almost every endpoint requires a **Laravel Sanctum bearer token**: `Authorization: Bearer <token>`. Obtain one from `POST /api/auth/login`.
- The **Permission** column is the `spatie/laravel-permission` name enforced by middleware. Blank means a valid token is sufficient. Endpoints marked *public* need no token at all - these are login, health, and the tokenised garage-invoice portal links that are emailed to external garages.
- A user holding the `super-admin` role bypasses every permission check.
- Where two permissions are listed separated by `/`, the middleware accepts either one.
- All responses are wrapped by `App\Helpers\ResponseHelper` in a uniform envelope. Errors carry an HTTP status plus a message; workflow guard violations return **422**.
- `{param}` segments are route-model-bound when the name matches a model, so a bad id returns **404** before your controller runs.

## Endpoint groups

| Group | Endpoints |
|---|---|
| [`/api/Activity`](#apiactivity) | 1 |
| [`/api/auth`](#apiauth) | 11 |
| [`/api/booking-readiness`](#apibookingreadiness) | 3 |
| [`/api/car-status`](#apicarstatus) | 2 |
| [`/api/cleaning`](#apicleaning) | 4 |
| [`/api/complaints`](#apicomplaints) | 8 |
| [`/api/components`](#apicomponents) | 3 |
| [`/api/concept-bridge`](#apiconceptbridge) | 3 |
| [`/api/ContactReminders`](#apicontactreminders) | 5 |
| [`/api/Contract`](#apicontract) | 22 |
| [`/api/cost-adjustments`](#apicostadjustments) | 1 |
| [`/api/cost-verification`](#apicostverification) | 3 |
| [`/api/Customer`](#apicustomer) | 7 |
| [`/api/Dashboard`](#apidashboard) | 15 |
| [`/api/DataHealth`](#apidatahealth) | 1 |
| [`/api/Driver`](#apidriver) | 5 |
| [`/api/driver-observations`](#apidriverobservations) | 4 |
| [`/api/event-classification`](#apieventclassification) | 2 |
| [`/api/fault-causes`](#apifaultcauses) | 3 |
| [`/api/FinancialConflicts`](#apifinancialconflicts) | 1 |
| [`/api/financial-documents`](#apifinancialdocuments) | 6 |
| [`/api/finding-keywords`](#apifindingkeywords) | 11 |
| [`/api/Fleet`](#apifleet) | 2 |
| [`/api/FuelMileage`](#apifuelmileage) | 2 |
| [`/api/garage-invoice`](#apigarageinvoice) | 2 |
| [`/api/garage-invoices`](#apigarageinvoices) | 2 |
| [`/api/garage-recommendations`](#apigaragerecommendations) | 1 |
| [`/api/InGarage`](#apiingarage) | 1 |
| [`/api/InspectionEngine`](#apiinspectionengine) | 2 |
| [`/api/Inspections`](#apiinspections) | 3 |
| [`/api/InspectionSchedules`](#apiinspectionschedules) | 6 |
| [`/api/inspector-pad`](#apiinspectorpad) | 4 |
| [`/api/intelligence`](#apiintelligence) | 14 |
| [`/api/Invoice`](#apiinvoice) | 6 |
| [`/api/logistics`](#apilogistics) | 16 |
| [`/api/Maintenance`](#apimaintenance) | 18 |
| [`/api/maintenance-invoices`](#apimaintenanceinvoices) | 3 |
| [`/api/maintenance-operations`](#apimaintenanceoperations) | 2 |
| [`/api/maintenance-swaps`](#apimaintenanceswaps) | 3 |
| [`/api/maintenance-tasks`](#apimaintenancetasks) | 11 |
| [`/api/maintenance-tickets`](#apimaintenancetickets) | 100 |
| [`/api/MileageChain`](#apimileagechain) | 3 |
| [`/api/notifications`](#apinotifications) | 7 |
| [`/api/OdometerChangeRequests`](#apiodometerchangerequests) | 4 |
| [`/api/odoo-export`](#apiodooexport) | 2 |
| [`/api/OilProjection`](#apioilprojection) | 1 |
| [`/api/OilRecallTasks`](#apioilrecalltasks) | 2 |
| [`/api/Oversight`](#apioversight) | 9 |
| [`/api/part-investigations`](#apipartinvestigations) | 5 |
| [`/api/part-invoices`](#apipartinvoices) | 6 |
| [`/api/part-purchases`](#apipartpurchases) | 8 |
| [`/api/part-requests`](#apipartrequests) | 8 |
| [`/api/part-returns`](#apipartreturns) | 4 |
| [`/api/parts-catalog`](#apipartscatalog) | 6 |
| [`/api/Payment`](#apipayment) | 5 |
| [`/api/procurement`](#apiprocurement) | 4 |
| [`/api/Profitability`](#apiprofitability) | 1 |
| [`/api/readiness`](#apireadiness) | 4 |
| [`/api/Reconciliation`](#apireconciliation) | 2 |
| [`/api/recurring-fault-reviews`](#apirecurringfaultreviews) | 3 |
| [`/api/Registration`](#apiregistration) | 6 |
| [`/api/RentalOperations`](#apirentaloperations) | 1 |
| [`/api/repair-intelligence`](#apirepairintelligence) | 1 |
| [`/api/service-due`](#apiservicedue) | 2 |
| [`/api/ServiceReminders`](#apiservicereminders) | 8 |
| [`/api/simulation`](#apisimulation) | 4 |
| [`/api/StatusMismatch`](#apistatusmismatch) | 1 |
| [`/api/supplier-payments`](#apisupplierpayments) | 4 |
| [`/api/Sync`](#apisync) | 2 |
| [`/api/TripDashboard`](#apitripdashboard) | 2 |
| [`/api/user`](#apiuser) | 1 |
| [`/api/Vehicle`](#apivehicle) | 25 |
| [`/api/vehicle-locations`](#apivehiclelocations) | 13 |
| [`/api/vehicle-status`](#apivehiclestatus) | 4 |
| [`/api/Vendor`](#apivendor) | 5 |
| [`/api/warranties`](#apiwarranties) | 10 |
| [`/api/warranty-claims`](#apiwarrantyclaims) | 1 |

Total: **493 endpoints** across **77 groups**.

---

## /api/Activity

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/Activity` | `VehicleActivityController@feed` | `insights.view` |

## /api/auth

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/auth/activity` | `WorkforceController@heartbeat` |  |
| POST | `/api/auth/login` | `AuthController@login` | *public* |
| POST | `/api/auth/logout` | `AuthController@logout` | *public* |
| GET | `/api/auth/me` | `AuthController@me` |  |
| GET | `/api/auth/roles` | `AuthController@roles` | `users.manage` |
| POST | `/api/auth/signup` | `AuthController@signup` | `users.manage` |
| GET | `/api/auth/users` | `AuthController@index` | `users.manage` |
| DELETE | `/api/auth/users/{user}` | `AuthController@destroy` | `users.manage` |
| PUT | `/api/auth/users/{user}` | `AuthController@update` | `users.manage` |
| GET | `/api/auth/users/{user}/activity` | `WorkforceController@userActivity` | `users.manage` |
| GET | `/api/auth/workforce` | `WorkforceController@overview` | `users.manage` |

## /api/booking-readiness

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/booking-readiness` | `BookingReadinessController@index` | `booking_readiness.view` |
| POST | `/api/booking-readiness/settings` | `BookingReadinessController@updateSettings` | `booking_readiness.manage` |
| GET | `/api/booking-readiness/settings` | `BookingReadinessController@settings` | `booking_readiness.view` |

## /api/car-status

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/car-status` | `CarStatusController@dashboard` | `maintenance.view` |
| GET | `/api/car-status/vehicle/{vehicle}` | `CarStatusController@vehicle` | `maintenance.view` |

## /api/cleaning

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/cleaning/vehicle/{vehicle}` | `CleaningController@show` | `contracts.view|contracts.manage|vehicles.view|vehicles.manage|booking_readiness.view|inspections.view` |
| POST | `/api/cleaning/vehicle/{vehicle}/photo` | `CleaningController@store` | `contracts.manage|vehicles.manage` |
| DELETE | `/api/cleaning/vehicle/{vehicle}/photo/{photo}` | `CleaningController@destroy` | `contracts.manage|vehicles.manage` |
| POST | `/api/cleaning/vehicle/{vehicle}/status` | `CleaningController@setStatus` | `contracts.manage|vehicles.manage` |

## /api/complaints

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/complaints` | `ComplaintController@store` | `maintenance.manage` |
| GET | `/api/complaints` | `ComplaintController@index` | `maintenance.view` |
| GET | `/api/complaints/{complaint}` | `ComplaintController@show` | `maintenance.view` |
| POST | `/api/complaints/{complaint}/close` | `ComplaintController@close` | `maintenance.initiate` |
| POST | `/api/complaints/{complaint}/contact` | `ComplaintController@contact` | `maintenance.initiate` |
| POST | `/api/complaints/{complaint}/decision` | `ComplaintController@decide` | `maintenance.initiate` |
| POST | `/api/complaints/{complaint}/resolve` | `ComplaintController@resolve` | `maintenance.initiate` |
| POST | `/api/complaints/{complaint}/send-in` | `ComplaintController@sendIn` | `maintenance.initiate` |

## /api/components

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/components/{component}` | `VehicleComponentController@show` | `components.view` |
| GET | `/api/components/catalog` | `VehicleComponentController@catalog` | `components.view` |
| GET | `/api/components/dashboard` | `VehicleComponentController@dashboard` | `components.view` |

## /api/concept-bridge

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/concept-bridge/results` | `ConceptBridgeReviewController@results` | `maintenance.manage` |
| GET | `/api/concept-bridge/review` | `ConceptBridgeReviewController@index` | `maintenance.manage` |
| POST | `/api/concept-bridge/review/{sample}` | `ConceptBridgeReviewController@store` | `maintenance.manage` |

## /api/ContactReminders

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/ContactReminders` | `ContactReminderController@store` | `reminders.manage` |
| GET | `/api/ContactReminders` | `ContactReminderController@index` | `reminders.view` |
| DELETE | `/api/ContactReminders/{contactReminder}` | `ContactReminderController@destroy` | `reminders.manage` |
| POST | `/api/ContactReminders/{contactReminder}` | `ContactReminderController@update` | `reminders.manage` |
| GET | `/api/ContactReminders/{contactReminder}` | `ContactReminderController@show` | `reminders.view` |

## /api/Contract

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/Contract` | `ContractController@store` | `contracts.manage` |
| GET | `/api/Contract` | `ContractController@index` | `contracts.view` |
| POST | `/api/Contract/{contract}` | `ContractController@update` | `contracts.manage` |
| DELETE | `/api/Contract/{contract}` | `ContractController@destroy` | `contracts.manage` |
| GET | `/api/Contract/{contract}` | `ContractController@show` | `contracts.view` |
| POST | `/api/Contract/{contract}/close` | `OperationController@close` | `operations.manage` |
| GET | `/api/Contract/{contract}/exchange` | `ExchangeController@show` | `contracts.view` |
| POST | `/api/Contract/{contract}/exchange/link` | `ExchangeController@link` | `contracts.manage` |
| DELETE | `/api/Contract/{contract}/exchange/link` | `ExchangeController@unlink` | `contracts.manage` |
| POST | `/api/Contract/{contract}/garage` | `InGarageController@recordGarage` | `maintenance.manage` |
| GET | `/api/Contract/{contract}/journey` | `ContractController@journey` | `contracts.view|maintenance.view` |
| POST | `/api/Contract/{contract}/mileage-reading` | `OilProjectionController@reading` | `reminders.manage` |
| POST | `/api/Contract/{contract}/oil-change-done` | `OilProjectionController@oilChanged` | `reminders.manage|maintenance.manage|maintenance.logistics` |
| POST | `/api/Contract/{contract}/oil-decision` | `OilProjectionController@decide` | `reminders.manage` |
| GET | `/api/Contract/{contract}/oil-projection` | `OilProjectionController@show` | `reminders.view` |
| POST | `/api/Contract/{contract}/oil-recall/hand-over` | `OilProjectionController@handOverToSupervisor` | `reminders.manage|maintenance.manage|maintenance.logistics` |
| POST | `/api/Contract/{contract}/oil-recall/instructions` | `OilProjectionController@collectionInstructions` | `reminders.manage` |
| POST | `/api/Contract/{contract}/oil-recall/sales-confirm` | `OilProjectionController@confirmSales` | `reminders.manage` |
| POST | `/api/Contract/{contract}/oil-returned` | `OilProjectionController@returnedToCustomer` | `reminders.manage|maintenance.manage|maintenance.logistics` |
| GET | `/api/Contract/{contract}/repair-invoices` | `ContractController@repairInvoices` | `contracts.view|maintenance.view` |
| GET | `/api/Contract/exchanges/pending` | `ExchangeController@pending` | `contracts.view` |
| GET | `/api/Contract/next-no` | `ContractController@nextNo` | `contracts.manage` |

## /api/cost-adjustments

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/cost-adjustments/{costAdjustment}/reverse` | `CostAdjustmentController@reverse` | `maintenance.manage` |

## /api/cost-verification

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/cost-verification/queue` | `CostVerificationController@queue` | `maintenance.view` |
| GET | `/api/cost-verification/spend-by-category` | `CostVerificationController@spendByCategory` | `maintenance.view` |
| GET | `/api/cost-verification/summary` | `CostVerificationController@summary` | `maintenance.view` |

## /api/Customer

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/Customer` | `CustomerController@store` | `customers.manage` |
| GET | `/api/Customer` | `CustomerController@index` | `customers.view` |
| DELETE | `/api/Customer/{customer}` | `CustomerController@destroy` | `customers.manage` |
| POST | `/api/Customer/{customer}` | `CustomerController@update` | `customers.manage` |
| GET | `/api/Customer/{customer}` | `CustomerController@show` | `customers.view` |
| GET | `/api/Customer/{customer}/balance` | `CustomerController@balance` | `customers.view` |
| GET | `/api/Customer/{customer}/profile` | `CustomerController@profile` | `customers.view` |

## /api/Dashboard

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/Dashboard` | `DashboardController@summary` | `dashboard.view` |
| GET | `/api/Dashboard/damage` | `DashboardController@damage` | `dashboard.view` |
| GET | `/api/Dashboard/fault-cars` | `DashboardController@faultCars` | `dashboard.view` |
| GET | `/api/Dashboard/fleet-pulse` | `DashboardController@fleetPulse` | `dashboard.view` |
| GET | `/api/Dashboard/maintenance-history` | `DashboardController@maintenanceHistory` | `dashboard.view` |
| GET | `/api/Dashboard/maintenance-history/{vehicle}/visits` | `DashboardController@maintenanceHistoryVisits` | `dashboard.view` |
| GET | `/api/Dashboard/maintenance-progress` | `DashboardController@maintenanceProgress` | `dashboard.view` |
| GET | `/api/Dashboard/most-maintained` | `DashboardController@mostMaintained` | `dashboard.view` |
| GET | `/api/Dashboard/most-maintained-cars` | `DashboardController@mostMaintainedCars` | `dashboard.view` |
| GET | `/api/Dashboard/most-maintained-models` | `DashboardController@mostMaintainedModels` | `dashboard.view` |
| GET | `/api/Dashboard/overdue-maintenance` | `DashboardController@overdueMaintenance` | `dashboard.view` |
| GET | `/api/Dashboard/overdue-rentals` | `DashboardController@overdueRentals` | `dashboard.view` |
| GET | `/api/Dashboard/proactive-flags` | `DashboardController@proactiveFlags` | `dashboard.view` |
| GET | `/api/Dashboard/top-faults` | `DashboardController@topFaults` | `dashboard.view` |
| GET | `/api/Dashboard/trends` | `DashboardController@trends` | `dashboard.view` |

## /api/DataHealth

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/DataHealth` | `DataHealthController@index` | `insights.view` |

## /api/Driver

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/Driver` | `DriverController@store` | `drivers.manage` |
| GET | `/api/Driver` | `DriverController@index` | `drivers.view` |
| DELETE | `/api/Driver/{driver}` | `DriverController@destroy` | `drivers.manage` |
| POST | `/api/Driver/{driver}` | `DriverController@update` | `drivers.manage` |
| GET | `/api/Driver/{driver}` | `DriverController@show` | `drivers.view` |

## /api/driver-observations

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/driver-observations` | `DriverObservationController@store` | `maintenance.logistics` |
| GET | `/api/driver-observations` | `DriverObservationController@index` | `maintenance.view` |
| POST | `/api/driver-observations/{observation}/dismiss` | `DriverObservationController@dismiss` | `maintenance.manage` |
| POST | `/api/driver-observations/{observation}/request-inspection` | `DriverObservationController@requestInspection` | `maintenance.logistics|maintenance.manage` |

## /api/event-classification

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/event-classification/review` | `EventClassificationReviewController@index` | `maintenance.manage` |
| POST | `/api/event-classification/review/{task}/confirm` | `EventClassificationReviewController@confirm` | `maintenance.manage` |

## /api/fault-causes

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/fault-causes` | `FaultCauseController@index` | `maintenance.manage` |
| POST | `/api/fault-causes/{faultCause}/approve` | `FaultCauseController@approve` | `maintenance.manage` |
| POST | `/api/fault-causes/{faultCause}/reject` | `FaultCauseController@reject` | `maintenance.manage` |

## /api/FinancialConflicts

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/FinancialConflicts` | `FinancialConflictController@index` | `insights.view` |

## /api/financial-documents

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/financial-documents/{type}/{id}/approve` | `FinancialDocumentController@approve` | `maintenance.manage` |
| POST | `/api/financial-documents/{type}/{id}/cancel` | `FinancialDocumentController@cancel` | `maintenance.manage` |
| POST | `/api/financial-documents/{type}/{id}/pay` | `FinancialDocumentController@pay` | `maintenance.manage` |
| POST | `/api/financial-documents/{type}/{id}/submit` | `FinancialDocumentController@submit` | `maintenance.manage` |
| POST | `/api/financial-documents/{type}/{id}/unapprove` | `FinancialDocumentController@unapprove` | `maintenance.manage` |
| GET | `/api/financial-documents/vocabulary` | `FinancialDocumentController@vocabulary` |  |

## /api/finding-keywords

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/finding-keywords` | `FindingKeywordController@store` | `maintenance.initiate` |
| GET | `/api/finding-keywords` | `FindingKeywordController@index` | `maintenance.view` |
| POST | `/api/finding-keywords/{findingKeyword}` | `FindingKeywordController@update` | `maintenance.manage` |
| DELETE | `/api/finding-keywords/{findingKeyword}` | `FindingKeywordController@destroy` | `maintenance.manage` |
| GET | `/api/finding-keywords/{findingKeyword}` | `FindingKeywordController@show` | `maintenance.view` |
| POST | `/api/finding-keywords/{findingKeyword}/enrich` | `FindingKeywordController@enrich` | `maintenance.manage` |
| POST | `/api/finding-keywords/{findingKeyword}/terms` | `FindingKeywordController@storeTerm` | `maintenance.manage` |
| POST | `/api/finding-keywords/{findingKeyword}/terms/{term}` | `FindingKeywordController@updateTerm` | `maintenance.manage` |
| DELETE | `/api/finding-keywords/{findingKeyword}/terms/{term}` | `FindingKeywordController@destroyTerm` | `maintenance.manage` |
| POST | `/api/finding-keywords/match-feedback` | `FindingKeywordController@matchFeedback` | `maintenance.view` |
| POST | `/api/finding-keywords/resolve` | `FindingKeywordController@resolve` | `maintenance.view` |

## /api/Fleet

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/Fleet/expiring` | `FleetController@expiring` | `dashboard.view` |
| GET | `/api/Fleet/life-status` | `VehicleActivityController@fleetStatus` | `vehicles.view` |

## /api/FuelMileage

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/FuelMileage` | `FuelMileageController@index` | `insights.view` |
| GET | `/api/FuelMileage/{vehicle}` | `FuelMileageController@vehicle` | `insights.view` |

## /api/garage-invoice

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/garage-invoice/{token}` | `GarageInvoiceController@submit` | *public* |
| GET | `/api/garage-invoice/{token}` | `GarageInvoiceController@show` | *public* |

## /api/garage-invoices

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/garage-invoices/{submission}/accept` | `GarageInvoiceController@accept` | `maintenance.manage` |
| POST | `/api/garage-invoices/{submission}/reject` | `GarageInvoiceController@reject` | `maintenance.manage` |

## /api/garage-recommendations

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/garage-recommendations` | `GarageRecommendationController@index` | `maintenance.view` |

## /api/InGarage

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/InGarage` | `InGarageController@index` | `maintenance.view` |

## /api/InspectionEngine

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/InspectionEngine/idle-watch` | `InspectionEngineController@idleWatch` | `insights.view|maintenance.view|maintenance.manage|inspections.view` |
| GET | `/api/InspectionEngine/monitor` | `InspectionEngineController@monitor` | `insights.view|maintenance.view|maintenance.manage|inspections.view` |

## /api/Inspections

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/Inspections` | `InspectionController@store` | `inspections.manage` |
| GET | `/api/Inspections` | `InspectionController@index` | `inspections.view` |
| DELETE | `/api/Inspections/{inspection}` | `InspectionController@destroy` | `inspections.manage` |

## /api/InspectionSchedules

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/InspectionSchedules` | `InspectionScheduleController@store` | `inspections.manage` |
| GET | `/api/InspectionSchedules` | `InspectionScheduleController@index` | `inspections.view` |
| DELETE | `/api/InspectionSchedules/{inspectionSchedule}` | `InspectionScheduleController@destroy` | `inspections.manage` |
| POST | `/api/InspectionSchedules/{inspectionSchedule}` | `InspectionScheduleController@update` | `inspections.manage` |
| GET | `/api/InspectionSchedules/{inspectionSchedule}` | `InspectionScheduleController@show` | `inspections.view` |
| POST | `/api/InspectionSchedules/{inspectionSchedule}/complete` | `InspectionScheduleController@complete` | `inspections.manage` |

## /api/inspector-pad

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/inspector-pad` | `InspectorPadController@store` | `maintenance.initiate` |
| GET | `/api/inspector-pad` | `InspectorPadController@index` | `maintenance.view` |
| DELETE | `/api/inspector-pad/{flag}` | `InspectorPadController@destroy` | `maintenance.initiate` |
| POST | `/api/inspector-pad/pickup/{vehicle}` | `InspectorPadController@pickup` | `maintenance.initiate` |

## /api/intelligence

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/intelligence/center` | `IntelligenceCenterController@show` | `insights.view` |
| POST | `/api/intelligence/center/evaluate` | `IntelligenceCenterController@evaluate` | `maintenance.manage` |
| GET | `/api/intelligence/cost` | `IntelligenceController@cost` | `insights.view` |
| GET | `/api/intelligence/evidence/{queryId}` | `Intelligence\EvidenceController@show` | `intelligence.view` |
| GET | `/api/intelligence/executive` | `Intelligence\ExecutiveDashboardController@show` | `intelligence.view` |
| GET | `/api/intelligence/garages/{vendor}` | `Intelligence\GarageIntelligenceController@show` | `intelligence.view` |
| GET | `/api/intelligence/garages/compare` | `Intelligence\GarageIntelligenceController@compare` | `intelligence.view` |
| GET | `/api/intelligence/maintenance-ops` | `IntelligenceController@maintenanceOps` | `insights.view` |
| GET | `/api/intelligence/recommendation-learning` | `IntelligenceController@recommendationLearning` | `insights.view` |
| GET | `/api/intelligence/service-due` | `IntelligenceController@serviceDue` | `insights.view` |
| GET | `/api/intelligence/vehicle/{vehicle}/explain` | `IntelligenceController@explain` | `insights.view` |
| GET | `/api/intelligence/vehicle/{vehicle}/financial-breakdown` | `IntelligenceController@financialBreakdown` | `insights.view` |
| GET | `/api/intelligence/vehicle/{vehicle}/financial-explain` | `IntelligenceController@financialExplain` | `insights.view` |
| GET | `/api/intelligence/vehicle/{vehicle}/maintenance-detail` | `IntelligenceController@maintenanceOpsVehicle` | `insights.view` |

## /api/Invoice

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/Invoice` | `InvoiceController@store` | `billing.manage` |
| GET | `/api/Invoice` | `InvoiceController@index` | `billing.view` |
| POST | `/api/Invoice/{invoice}` | `InvoiceController@update` | `billing.manage` |
| GET | `/api/Invoice/{invoice}` | `InvoiceController@show` | `billing.view` |
| DELETE | `/api/Invoice/{invoice}` | `InvoiceController@destroy` | `billing.manage` |
| GET | `/api/Invoice/status-summary` | `InvoiceController@statusSummary` | `billing.view` |

## /api/logistics

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/logistics` | `LogisticsDispatchController@store` | `logistics.dispatch` |
| GET | `/api/logistics` | `LogisticsDispatchController@index` | `logistics.view` |
| POST | `/api/logistics/{logisticsTask}/cancel` | `LogisticsDispatchController@cancel` | `logistics.dispatch` |
| POST | `/api/logistics/{logisticsTask}/claim` | `LogisticsDispatchController@claim` | `logistics.claim` |
| POST | `/api/logistics/{logisticsTask}/complete` | `LogisticsDispatchController@complete` | `logistics.view` |
| POST | `/api/logistics/{logisticsTask}/deliver` | `LogisticsDispatchController@deliver` | `logistics.view` |
| POST | `/api/logistics/{logisticsTask}/pickup` | `LogisticsDispatchController@pickup` | `logistics.view` |
| POST | `/api/logistics/{logisticsTask}/ping` | `LogisticsDispatchController@ping` | `logistics.dispatch` |
| POST | `/api/logistics/{logisticsTask}/reassign` | `LogisticsDispatchController@reassign` | `logistics.dispatch` |
| POST | `/api/logistics/{logisticsTask}/return` | `LogisticsDispatchController@markReturned` | `logistics.view` |
| POST | `/api/logistics/{logisticsTask}/status` | `LogisticsDispatchController@respondStatus` | `logistics.view` |
| GET | `/api/logistics/assignees` | `LogisticsDispatchController@assignees` | `logistics.dispatch` |
| GET | `/api/logistics/drivers` | `LogisticsDispatchController@roster` | `logistics.view` |
| GET | `/api/logistics/my-collections` | `LogisticsDispatchController@myCollections` |  |
| GET | `/api/logistics/my-queue` | `LogisticsDispatchController@myQueue` |  |
| GET | `/api/logistics/pool` | `LogisticsDispatchController@pool` | `logistics.view` |

## /api/Maintenance

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/Maintenance/{contract}/approve` | `MaintenanceController@approve` | `maintenance.approve` |
| GET | `/api/Maintenance/analytics` | `MaintenanceController@analytics` | `maintenance.view` |
| GET | `/api/Maintenance/approvals` | `MaintenanceController@approvals` | `maintenance.approve` |
| GET | `/api/Maintenance/board` | `MaintenanceController@board` | `maintenance.view` |
| GET | `/api/Maintenance/booking-conflicts` | `MaintenanceController@bookingConflicts` | `maintenance.view` |
| GET | `/api/Maintenance/cost-capture` | `CostCaptureController@index` | `maintenance.view` |
| POST | `/api/Maintenance/cost-capture/{workshopEvent}` | `CostCaptureController@store` | `maintenance.manage` |
| POST | `/api/Maintenance/events` | `WorkshopEventController@store` | `maintenance.manage` |
| GET | `/api/Maintenance/events` | `WorkshopEventController@index` | `maintenance.view` |
| DELETE | `/api/Maintenance/events/{workshopEvent}` | `WorkshopEventController@destroy` | `maintenance.manage` |
| POST | `/api/Maintenance/events/{workshopEvent}` | `WorkshopEventController@update` | `maintenance.manage` |
| GET | `/api/Maintenance/events/{workshopEvent}` | `WorkshopEventController@show` | `maintenance.view` |
| POST | `/api/Maintenance/events/tombstones/{tombstone}/restore` | `WorkshopEventController@restore` | `maintenance.manage` |
| GET | `/api/Maintenance/garages` | `MaintenanceController@garages` | `maintenance.view` |
| GET | `/api/Maintenance/garage-scorecards` | `GarageScorecardController@index` | `maintenance.view` |
| GET | `/api/Maintenance/incidents` | `MaintenanceController@incidents` | `maintenance.view` |
| GET | `/api/Maintenance/reasons` | `MaintenanceController@reasons` | `maintenance.view` |
| GET | `/api/Maintenance/recurring` | `MaintenanceController@recurring` | `maintenance.view` |

## /api/maintenance-invoices

| Method | Path | Handler | Permission |
|---|---|---|---|
| DELETE | `/api/maintenance-invoices/{invoice}` | `MaintenanceInvoiceController@destroy` | `maintenance.manage` |
| POST | `/api/maintenance-invoices/{invoice}` | `MaintenanceInvoiceController@update` | `maintenance.manage` |
| POST | `/api/maintenance-invoices/{invoice}/reconcile` | `MaintenanceInvoiceController@reconcile` | `maintenance.manage` |

## /api/maintenance-operations

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/maintenance-operations` | `MaintenanceOperationsController@index` | `maintenance.view` |
| GET | `/api/maintenance-operations/vehicle/{vehicle}` | `MaintenanceOperationsController@vehicle` | `maintenance.view` |

## /api/maintenance-swaps

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/maintenance-swaps` | `MaintenanceSwapController@store` | `operations.manage` |
| DELETE | `/api/maintenance-swaps/{maintenanceSwap}` | `MaintenanceSwapController@release` | `operations.manage` |
| GET | `/api/maintenance-swaps/board` | `MaintenanceSwapController@board` | `insights.view` |

## /api/maintenance-tasks

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/maintenance-tasks/{task}/capture` | `MaintenanceWorkflowController@captureRepair` | `maintenance.delegate` |
| GET | `/api/maintenance-tasks/{task}/capture` | `MaintenanceWorkflowController@captureOptions` | `maintenance.delegate` |
| POST | `/api/maintenance-tasks/{task}/capture/abandon` | `MaintenanceWorkflowController@captureAbandon` | `maintenance.delegate` |
| POST | `/api/maintenance-tasks/{task}/capture/start` | `MaintenanceWorkflowController@captureStart` | `maintenance.delegate` |
| POST | `/api/maintenance-tasks/{task}/confirm` | `MaintenanceWorkflowController@confirmTask` | `maintenance.delegate` |
| POST | `/api/maintenance-tasks/{task}/incorrect` | `MaintenanceWorkflowController@markTaskIncorrect` | `maintenance.delegate` |
| POST | `/api/maintenance-tasks/{task}/repair-approval` | `MaintenanceWorkflowController@repairApproval` | `maintenance.recurring.manage` |
| GET | `/api/maintenance-tasks/{task}/repair-intelligence` | `RepairIntelligenceController@forTask` | `maintenance.view` |
| POST | `/api/maintenance-tasks/{task}/status` | `MaintenanceWorkflowController@setTaskStatus` | `maintenance.delegate` |
| POST | `/api/maintenance-tasks/{task}/verification` | `MaintenanceWorkflowController@verifyRepair` | `inspections.manage` |
| GET | `/api/maintenance-tasks/{task}/verification` | `MaintenanceWorkflowController@verificationOptions` | `inspections.manage` |

## /api/maintenance-tickets

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/maintenance-tickets` | `MaintenanceWorkflowController@store` | `maintenance.manage` |
| GET | `/api/maintenance-tickets` | `MaintenanceWorkflowController@index` | `maintenance.view` |
| GET | `/api/maintenance-tickets/{ticket}` | `MaintenanceWorkflowController@show` | `maintenance.view` |
| POST | `/api/maintenance-tickets/{ticket}/adjustments` | `CostAdjustmentController@store` | `maintenance.manage` |
| GET | `/api/maintenance-tickets/{ticket}/adjustments` | `CostAdjustmentController@index` | `maintenance.view` |
| POST | `/api/maintenance-tickets/{ticket}/approve-repair` | `MaintenanceWorkflowController@approveRepair` | `maintenance.delegate` |
| POST | `/api/maintenance-tickets/{ticket}/arrive-at-park` | `MaintenanceWorkflowController@arriveAtPark` | `maintenance.logistics` |
| POST | `/api/maintenance-tickets/{ticket}/assign-dispatch` | `MaintenanceWorkflowController@assignDispatch` | `maintenance.delegate` |
| POST | `/api/maintenance-tickets/{ticket}/assign-pending` | `MaintenanceWorkflowController@assignPending` | `maintenance.delegate` |
| GET | `/api/maintenance-tickets/{ticket}/billable-parts` | `MaintenanceWorkflowController@billableParts` | `maintenance.view` |
| GET | `/api/maintenance-tickets/{ticket}/checkpoints` | `MaintenanceCheckpointController@index` | `maintenance.view` |
| POST | `/api/maintenance-tickets/{ticket}/checkpoints` | `MaintenanceCheckpointController@store` | `maintenance.view` |
| DELETE | `/api/maintenance-tickets/{ticket}/checkpoints/{checkpoint}` | `MaintenanceCheckpointController@destroy` | `maintenance.view` |
| POST | `/api/maintenance-tickets/{ticket}/close` | `MaintenanceWorkflowController@close` | `maintenance.initiate|maintenance.delegate` |
| POST | `/api/maintenance-tickets/{ticket}/collect-from-garage` | `MaintenanceWorkflowController@collectFromGarage` | `maintenance.logistics` |
| POST | `/api/maintenance-tickets/{ticket}/cost` | `MaintenanceWorkflowController@recordCost` | `maintenance.manage` |
| GET | `/api/maintenance-tickets/{ticket}/cost-journey` | `TicketCostJourneyController@show` | `maintenance.view` |
| GET | `/api/maintenance-tickets/{ticket}/decision-cards` | `MaintenanceIntelligenceController@cards` | `maintenance.view` |
| POST | `/api/maintenance-tickets/{ticket}/decision-cards/{recommendation}/respond` | `MaintenanceIntelligenceController@respond` | `maintenance.view` |
| POST | `/api/maintenance-tickets/{ticket}/delegate` | `MaintenanceWorkflowController@delegate` | `maintenance.delegate` |
| GET | `/api/maintenance-tickets/{ticket}/diagnostic-context` | `MaintenanceWorkflowController@diagnosticContext` | `maintenance.view` |
| POST | `/api/maintenance-tickets/{ticket}/dispatch` | `MaintenanceWorkflowController@dispatch` | `maintenance.logistics` |
| POST | `/api/maintenance-tickets/{ticket}/expected-completion` | `MaintenanceCheckpointController@setExpected` | `maintenance.view` |
| POST | `/api/maintenance-tickets/{ticket}/finalize-invoice` | `MaintenanceWorkflowController@finalizeInvoice` | `maintenance.manage` |
| GET | `/api/maintenance-tickets/{ticket}/financial-story` | `TicketCostJourneyController@story` | `maintenance.view` |
| POST | `/api/maintenance-tickets/{ticket}/findings` | `MaintenanceWorkflowController@addFindings` | `maintenance.logistics` |
| POST | `/api/maintenance-tickets/{ticket}/follow-up` | `MaintenanceWorkflowController@followUp` | `maintenance.delegate` |
| GET | `/api/maintenance-tickets/{ticket}/garage-invoice-garages` | `GarageInvoiceController@garages` | `maintenance.manage` |
| POST | `/api/maintenance-tickets/{ticket}/garage-invoice-link` | `GarageInvoiceController@issueLink` | `maintenance.manage` |
| GET | `/api/maintenance-tickets/{ticket}/garage-recommendations` | `GarageRecommendationController@forTicket` | `maintenance.delegate` |
| POST | `/api/maintenance-tickets/{ticket}/incidents/{incident}/acknowledge` | `MaintenanceWorkflowController@acknowledgeIncident` | `maintenance.manage` |
| GET | `/api/maintenance-tickets/{ticket}/invoices` | `MaintenanceInvoiceController@index` | `maintenance.view` |
| POST | `/api/maintenance-tickets/{ticket}/invoices` | `MaintenanceInvoiceController@store` | `maintenance.manage` |
| GET | `/api/maintenance-tickets/{ticket}/lifecycle` | `FinancialDocumentController@lifecycle` | `maintenance.view` |
| PUT | `/api/maintenance-tickets/{ticket}/line-items` | `MaintenanceWorkflowController@syncLineItems` | `maintenance.manage` |
| GET | `/api/maintenance-tickets/{ticket}/line-items` | `MaintenanceWorkflowController@lineItems` | `maintenance.view` |
| POST | `/api/maintenance-tickets/{ticket}/mark-returned` | `MaintenanceWorkflowController@markReturned` | `maintenance.manage|maintenance.delegate|logistics.claim` |
| POST | `/api/maintenance-tickets/{ticket}/mark-serviced` | `MaintenanceWorkflowController@markServiced` | `maintenance.initiate|maintenance.delegate` |
| GET | `/api/maintenance-tickets/{ticket}/media` | `MaintenanceWorkflowController@listMedia` | `maintenance.view` |
| DELETE | `/api/maintenance-tickets/{ticket}/media/{media}` | `MaintenanceWorkflowController@destroyMedia` | `maintenance.delegate` |
| GET | `/api/maintenance-tickets/{ticket}/mileage` | `MaintenanceWorkflowController@mileage` | `maintenance.view` |
| POST | `/api/maintenance-tickets/{ticket}/pause` | `MaintenanceWorkflowController@pause` | `maintenance.manage` |
| POST | `/api/maintenance-tickets/{ticket}/ready` | `MaintenanceWorkflowController@ready` | `maintenance.logistics` |
| POST | `/api/maintenance-tickets/{ticket}/recovery-dispatch` | `MaintenanceWorkflowController@recoveryDispatch` | `maintenance.logistics|maintenance.delegate` |
| POST | `/api/maintenance-tickets/{ticket}/reopen` | `MaintenanceWorkflowController@reopen` | `maintenance.initiate|maintenance.delegate` |
| GET | `/api/maintenance-tickets/{ticket}/repair-inspections` | `MaintenanceWorkflowController@repairInspections` | `maintenance.view` |
| GET | `/api/maintenance-tickets/{ticket}/repair-outlook` | `GarageRecommendationController@outlookForTicket` | `maintenance.view` |
| POST | `/api/maintenance-tickets/{ticket}/report` | `MaintenanceWorkflowController@submitReport` | `maintenance.initiate` |
| POST | `/api/maintenance-tickets/{ticket}/request-invoice` | `MaintenanceWorkflowController@requestInvoice` | `maintenance.manage` |
| POST | `/api/maintenance-tickets/{ticket}/request-refix` | `MaintenanceWorkflowController@requestRefix` | `maintenance.delegate` |
| GET | `/api/maintenance-tickets/{ticket}/required-parts` | `MaintenanceRequiredPartController@index` | `maintenance.view` |
| POST | `/api/maintenance-tickets/{ticket}/required-parts` | `MaintenanceRequiredPartController@store` | `maintenance.initiate` |
| PUT | `/api/maintenance-tickets/{ticket}/responsibles` | `MaintenanceCheckpointController@setResponsibles` | `maintenance.view` |
| POST | `/api/maintenance-tickets/{ticket}/resume` | `MaintenanceWorkflowController@resume` | `maintenance.manage|maintenance.delegate` |
| POST | `/api/maintenance-tickets/{ticket}/return-from-release` | `MaintenanceWorkflowController@returnFromTemporaryRelease` | `maintenance.manage|maintenance.delegate|logistics.claim` |
| POST | `/api/maintenance-tickets/{ticket}/review/acknowledge-legacy` | `MaintenanceWorkflowController@acknowledgeLegacyReview` | `maintenance.manage` |
| POST | `/api/maintenance-tickets/{ticket}/review/approve` | `MaintenanceWorkflowController@approveReview` | `maintenance.manage` |
| POST | `/api/maintenance-tickets/{ticket}/review/reject` | `MaintenanceWorkflowController@rejectReview` | `maintenance.manage` |
| POST | `/api/maintenance-tickets/{ticket}/review/remind` | `MaintenanceWorkflowController@remindReview` | `maintenance.manage` |
| DELETE | `/api/maintenance-tickets/{ticket}/review/remind` | `MaintenanceWorkflowController@cancelReviewReminder` | `maintenance.manage` |
| POST | `/api/maintenance-tickets/{ticket}/start` | `MaintenanceWorkflowController@startDiagnostic` | `maintenance.initiate` |
| GET | `/api/maintenance-tickets/{ticket}/tasks` | `MaintenanceWorkflowController@listTasks` | `maintenance.view` |
| POST | `/api/maintenance-tickets/{ticket}/temporary-release` | `MaintenanceWorkflowController@temporarilyRelease` | `maintenance.manage` |
| POST | `/api/maintenance-tickets/{ticket}/transfer-garage` | `MaintenanceWorkflowController@transferGarage` | `maintenance.delegate` |
| POST | `/api/maintenance-tickets/{ticket}/triage/approve-route` | `MaintenanceWorkflowController@approveTriageRoute` | `maintenance.delegate|maintenance.manage` |
| POST | `/api/maintenance-tickets/{ticket}/triage/call` | `MaintenanceWorkflowController@triageCall` | `maintenance.initiate` |
| POST | `/api/maintenance-tickets/{ticket}/triage/reject-route` | `MaintenanceWorkflowController@rejectTriageRoute` | `maintenance.delegate|maintenance.manage` |
| POST | `/api/maintenance-tickets/{ticket}/triage/resolve` | `MaintenanceWorkflowController@triageResolve` | `maintenance.initiate` |
| POST | `/api/maintenance-tickets/{ticket}/triage/route` | `MaintenanceWorkflowController@triageRoute` | `maintenance.initiate` |
| PATCH | `/api/maintenance-tickets/{ticket}/type` | `MaintenanceWorkflowController@updateType` | `maintenance.manage` |
| POST | `/api/maintenance-tickets/{ticket}/under-repair` | `MaintenanceWorkflowController@underRepair` | `maintenance.logistics` |
| POST | `/api/maintenance-tickets/{ticket}/video` | `MaintenanceWorkflowController@storeVideo` | `maintenance.delegate` |
| GET | `/api/maintenance-tickets/assignable-drivers` | `MaintenanceWorkflowController@assignableDrivers` | `maintenance.delegate` |
| GET | `/api/maintenance-tickets/board` | `MaintenanceWorkflowController@board` | `maintenance.view` |
| POST | `/api/maintenance-tickets/breakdown` | `MaintenanceWorkflowController@storeBreakdown` | `maintenance.initiate|maintenance.manage` |
| GET | `/api/maintenance-tickets/checkpoint-candidates` | `MaintenanceCheckpointController@candidates` | `maintenance.view` |
| POST | `/api/maintenance-tickets/complaint` | `MaintenanceWorkflowController@storeComplaint` | `maintenance.manage` |
| GET | `/api/maintenance-tickets/completed` | `MaintenanceWorkflowController@completed` | `maintenance.view` |
| POST | `/api/maintenance-tickets/contract/{contract}/ensure-ticket` | `MaintenanceCheckpointController@ensureForContract` | `maintenance.view` |
| POST | `/api/maintenance-tickets/direct-dispatch` | `MaintenanceWorkflowController@storeDirectDispatch` | `maintenance.initiate|maintenance.manage` |
| GET | `/api/maintenance-tickets/findings-catalog` | `MaintenanceWorkflowController@findingsCatalog` | `maintenance.view` |
| GET | `/api/maintenance-tickets/invoice-matching` | `MaintenanceWorkflowController@invoiceMatchingQueue` | `maintenance.view` |
| GET | `/api/maintenance-tickets/my-queue` | `MaintenanceWorkflowController@myQueue` | `maintenance.view` |
| GET | `/api/maintenance-tickets/parked-in-shop` | `MaintenanceWorkflowController@parkedInShop` | `maintenance.manage` |
| GET | `/api/maintenance-tickets/pending-invoices` | `MaintenanceWorkflowController@pendingInvoices` | `maintenance.view` |
| GET | `/api/maintenance-tickets/pending-review` | `MaintenanceWorkflowController@reviewQueue` | `maintenance.manage` |
| GET | `/api/maintenance-tickets/repair-quality` | `MaintenanceWorkflowController@repairQuality` | `maintenance.view` |
| POST | `/api/maintenance-tickets/request` | `MaintenanceWorkflowController@requestInspection` | `maintenance.logistics` |
| POST | `/api/maintenance-tickets/request-inspection` | `MaintenanceWorkflowController@storeInspectionRequest` | `maintenance.manage` |
| GET | `/api/maintenance-tickets/request-options` | `MaintenanceWorkflowController@requestOptions` | `maintenance.view|maintenance.logistics` |
| GET | `/api/maintenance-tickets/review/rejection-reasons` | `MaintenanceWorkflowController@reviewRejectionReasons` | `maintenance.view` |
| GET | `/api/maintenance-tickets/review-gate-rules` | `MaintenanceWorkflowController@reviewGateRules` | `maintenance.manage` |
| GET | `/api/maintenance-tickets/test-countdown` | `MaintenanceWorkflowController@testCountdown` | `maintenance.manage` |
| GET | `/api/maintenance-tickets/vehicle/{vehicle}` | `MaintenanceWorkflowController@vehicleTimeline` | `maintenance.view` |
| GET | `/api/maintenance-tickets/vehicle/{vehicle}/checkpoints` | `MaintenanceCheckpointController@vehicleTimeline` | `maintenance.view` |
| GET | `/api/maintenance-tickets/vehicle/{vehicle}/fault-insights` | `MaintenanceWorkflowController@faultInsights` | `maintenance.view` |
| GET | `/api/maintenance-tickets/vehicle/{vehicle}/idle` | `MaintenanceWorkflowController@vehicleIdle` | `maintenance.view` |
| GET | `/api/maintenance-tickets/vehicle/{vehicle}/inspection-request` | `MaintenanceWorkflowController@vehicleInspectionRequest` | `maintenance.view|maintenance.logistics` |
| GET | `/api/maintenance-tickets/vehicle/{vehicle}/recent-faults` | `MaintenanceWorkflowController@vehicleRecentFaults` | `maintenance.view|maintenance.logistics` |
| GET | `/api/maintenance-tickets/vehicle/{vehicle}/repair-quality` | `MaintenanceWorkflowController@vehicleRepairQuality` | `maintenance.view` |

## /api/MileageChain

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/MileageChain/audit` | `MileageChainAuditController@index` | `insights.view` |
| DELETE | `/api/MileageChain/audit/{contract}/override` | `MileageChainAuditController@destroyOverride` | `contracts.manage` |
| POST | `/api/MileageChain/audit/{contract}/override` | `MileageChainAuditController@storeOverride` | `contracts.manage` |

## /api/notifications

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/notifications` | `NotificationController@index` |  |
| DELETE | `/api/notifications/{id}` | `NotificationController@destroy` |  |
| POST | `/api/notifications/{id}/read` | `NotificationController@markRead` |  |
| POST | `/api/notifications/clear` | `NotificationController@clear` |  |
| POST | `/api/notifications/demo` | `NotificationController@demo` |  |
| GET | `/api/notifications/poll` | `NotificationController@poll` |  |
| POST | `/api/notifications/read-all` | `NotificationController@markAllRead` |  |

## /api/OdometerChangeRequests

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/OdometerChangeRequests` | `OdometerChangeRequestController@index` | `vehicles.approve_odometer` |
| POST | `/api/OdometerChangeRequests/{odometerRequest}/approve` | `OdometerChangeRequestController@approve` | `vehicles.approve_odometer` |
| POST | `/api/OdometerChangeRequests/{odometerRequest}/reject` | `OdometerChangeRequestController@reject` | `vehicles.approve_odometer` |
| GET | `/api/OdometerChangeRequests/stage-readings` | `OdometerChangeRequestController@stageReadings` | `vehicles.approve_odometer` |

## /api/odoo-export

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/odoo-export/contract/{contract}` | `OdooExportController@contract` | `maintenance.manage` |
| GET | `/api/odoo-export/ticket/{ticket}` | `OdooExportController@ticket` | `maintenance.manage` |

## /api/OilProjection

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/OilProjection` | `OilProjectionController@index` | `reminders.view` |

## /api/OilRecallTasks

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/OilRecallTasks` | `OilProjectionController@recallTasks` | `reminders.view` |
| PATCH | `/api/OilRecallTasks/{task}` | `OilProjectionController@updateRecallTask` | `reminders.manage` |

## /api/Oversight

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/Oversight/checkpoint-compliance` | `WorkflowOversightController@checkpointCompliance` | `insights.view` |
| GET | `/api/Oversight/left-garage` | `WorkflowOversightController@leftGarage` | `insights.view` |
| GET | `/api/Oversight/mileage-discrepancies` | `WorkflowOversightController@mileageDiscrepancies` | `insights.view` |
| GET | `/api/Oversight/misdiagnoses` | `WorkflowOversightController@misdiagnoses` | `insights.view` |
| GET | `/api/Oversight/overview` | `WorkflowOversightController@overview` | `insights.view` |
| GET | `/api/Oversight/resolved-transfers` | `WorkflowOversightController@resolvedTransfers` | `insights.view` |
| GET | `/api/Oversight/severity-review` | `WorkflowOversightController@severityReview` | `insights.view` |
| POST | `/api/Oversight/severity-review/{ticket}/decide` | `WorkflowOversightController@decide` | `maintenance.manage` |
| GET | `/api/Oversight/stage-accountability` | `WorkflowOversightController@stageAccountability` | `insights.view` |

## /api/part-investigations

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/part-investigations` | `PartInvestigationController@index` | `parts.investigate` |
| POST | `/api/part-investigations/{partInvestigation}/approve` | `PartInvestigationController@approve` | `parts.investigate` |
| POST | `/api/part-investigations/{partInvestigation}/provide-reason` | `PartInvestigationController@provideReason` | `parts.investigate` |
| POST | `/api/part-investigations/{partInvestigation}/reject` | `PartInvestigationController@reject` | `parts.investigate` |
| POST | `/api/part-investigations/{partInvestigation}/review` | `PartInvestigationController@review` | `parts.investigate` |

## /api/part-invoices

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/part-invoices` | `PartInvoiceController@store` | `parts.purchase` |
| GET | `/api/part-invoices` | `PartInvoiceController@index` | `parts.view` |
| POST | `/api/part-invoices/{partInvoice}` | `PartInvoiceController@update` | `parts.purchase` |
| GET | `/api/part-invoices/{partInvoice}` | `PartInvoiceController@show` | `parts.view` |
| DELETE | `/api/part-invoices/{partInvoice}` | `PartInvoiceController@destroy` | `parts.purchase` |
| GET | `/api/part-invoices/unbilled` | `PartInvoiceController@unbilled` | `parts.view|parts.purchase` |

## /api/part-purchases

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/part-purchases` | `PartPurchaseController@index` | `parts.view` |
| POST | `/api/part-purchases/{partPurchase}/delivered` | `PartPurchaseController@markDelivered` | `parts.purchase|maintenance.logistics` |
| POST | `/api/part-purchases/{partPurchase}/install` | `PartPurchaseController@install` | `parts.purchase|maintenance.logistics` |
| POST | `/api/part-purchases/{partPurchase}/returns` | `PartReturnController@store` | `parts.purchase` |
| GET | `/api/part-purchases/duplicate-check` | `PartPurchaseController@duplicateCheck` | `parts.purchase|parts.request|parts.view` |
| GET | `/api/part-purchases/recurrence-check` | `PartPurchaseController@recurrenceCheck` | `parts.view|maintenance.view` |
| GET | `/api/part-purchases/repeats` | `PartPurchaseController@repeats` | `parts.view` |
| GET | `/api/part-purchases/vehicle/{vehicle}/history` | `PartPurchaseController@vehicleHistory` | `parts.view` |

## /api/part-requests

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/part-requests` | `PartRequestController@store` | `parts.request` |
| GET | `/api/part-requests` | `PartRequestController@index` | `parts.view` |
| GET | `/api/part-requests/{partRequest}` | `PartRequestController@show` | `parts.view` |
| POST | `/api/part-requests/{partRequest}/approve` | `PartRequestController@approve` | `parts.investigate|maintenance.manage` |
| POST | `/api/part-requests/{partRequest}/complete` | `PartRequestController@complete` | `parts.request|maintenance.manage` |
| POST | `/api/part-requests/{partRequest}/purchase` | `PartRequestController@purchase` | `parts.purchase` |
| POST | `/api/part-requests/{partRequest}/reject` | `PartRequestController@reject` | `parts.investigate|maintenance.manage` |
| GET | `/api/part-requests/spend` | `PartRequestController@spend` | `parts.view` |

## /api/part-returns

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/part-returns` | `PartReturnController@index` | `parts.view` |
| POST | `/api/part-returns/{partReturn}/refund` | `PartReturnController@refund` | `parts.purchase` |
| POST | `/api/part-returns/{partReturn}/reject` | `PartReturnController@reject` | `parts.purchase` |
| POST | `/api/part-returns/{partReturn}/sent` | `PartReturnController@markSent` | `parts.purchase` |

## /api/parts-catalog

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/parts-catalog` | `PartsCatalogController@store` | `components.manage` |
| GET | `/api/parts-catalog` | `PartsCatalogController@index` | `parts.view|components.view|maintenance.view` |
| DELETE | `/api/parts-catalog/{part}` | `PartsCatalogController@destroy` | `components.manage` |
| POST | `/api/parts-catalog/{part}` | `PartsCatalogController@update` | `components.manage` |
| POST | `/api/parts-catalog/{part}/restore` | `PartsCatalogController@restore` | `components.manage` |
| POST | `/api/parts-catalog/{part}/retire` | `PartsCatalogController@retire` | `components.manage` |

## /api/Payment

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/Payment` | `PaymentController@store` | `billing.manage` |
| GET | `/api/Payment` | `PaymentController@index` | `billing.view` |
| DELETE | `/api/Payment/{payment}` | `PaymentController@destroy` | `billing.manage` |
| POST | `/api/Payment/{payment}` | `PaymentController@update` | `billing.manage` |
| GET | `/api/Payment/{payment}` | `PaymentController@show` | `billing.view` |

## /api/procurement

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/procurement/overview` | `SupplierPaymentController@overview` | `maintenance.view` |
| GET | `/api/procurement/payables` | `SupplierPaymentController@payables` | `maintenance.view` |
| GET | `/api/procurement/payments` | `SupplierPaymentController@paymentsReport` | `maintenance.view` |
| GET | `/api/procurement/suppliers` | `SupplierPaymentController@suppliers` | `maintenance.view` |

## /api/Profitability

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/Profitability` | `ProfitabilityController@index` | `insights.view` |

## /api/readiness

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/readiness` | `ReadinessController@index` | `insights.view|logistics.view|maintenance.view|maintenance.initiate|maintenance.delegate|inspections.view` |
| GET | `/api/readiness/vehicle/{vehicle}` | `ReadinessController@vehicle` | `contracts.view|contracts.manage|insights.view|logistics.view|maintenance.view|maintenance.initiate|maintenance.delegate|inspections.view` |
| POST | `/api/readiness/vehicle/{vehicle}/checklist-field` | `ReadinessController@setChecklistField` | `contracts.manage|vehicles.manage` |
| GET | `/api/readiness/vehicle/{vehicle}/rental-checklist` | `ReadinessController@rentalChecklist` | `contracts.view|contracts.manage|insights.view|logistics.view|maintenance.view|inspections.view` |

## /api/Reconciliation

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/Reconciliation` | `ReconciliationController@show` | `insights.view` |
| GET | `/api/Reconciliation/fleet` | `ReconciliationController@fleet` | `insights.view` |

## /api/recurring-fault-reviews

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/recurring-fault-reviews` | `RecurringFaultReviewController@index` | `maintenance.recurring.view` |
| POST | `/api/recurring-fault-reviews/{recurringFaultReview}/decide` | `RecurringFaultReviewController@decide` | `maintenance.recurring.manage` |
| GET | `/api/recurring-fault-reviews/stats` | `RecurringFaultReviewController@stats` | `maintenance.recurring.view` |

## /api/Registration

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/Registration` | `VehicleRegistrationController@store` | `registration.manage` |
| GET | `/api/Registration` | `VehicleRegistrationController@index` | `registration.view` |
| POST | `/api/Registration/{registration}` | `VehicleRegistrationController@update` | `registration.manage` |
| GET | `/api/Registration/{registration}` | `VehicleRegistrationController@show` | `registration.view` |
| DELETE | `/api/Registration/{registration}` | `VehicleRegistrationController@destroy` | `registration.manage` |
| GET | `/api/Registration/coverage` | `VehicleRegistrationController@coverage` | `registration.view` |

## /api/RentalOperations

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/RentalOperations` | `RentalOperationsController@index` | `contracts.view` |

## /api/repair-intelligence

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/repair-intelligence/preview` | `RepairIntelligenceController@preview` | `maintenance.view` |

## /api/service-due

| Method | Path | Handler | Permission |
|---|---|---|---|
| DELETE | `/api/service-due/{vehicle}/snooze` | `ServiceDueSnoozeController@release` | `maintenance.initiate|maintenance.manage` |
| POST | `/api/service-due/snooze` | `ServiceDueSnoozeController@store` | `maintenance.initiate|maintenance.manage` |

## /api/ServiceReminders

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/ServiceReminders` | `ServiceReminderController@store` | `reminders.manage` |
| GET | `/api/ServiceReminders` | `ServiceReminderController@index` | `reminders.view` |
| POST | `/api/ServiceReminders/{serviceReminder}` | `ServiceReminderController@update` | `reminders.manage` |
| DELETE | `/api/ServiceReminders/{serviceReminder}` | `ServiceReminderController@destroy` | `reminders.manage` |
| GET | `/api/ServiceReminders/{serviceReminder}` | `ServiceReminderController@show` | `reminders.view` |
| POST | `/api/ServiceReminders/{serviceReminder}/complete` | `ServiceReminderController@complete` | `reminders.manage` |
| POST | `/api/ServiceReminders/{serviceReminder}/notify` | `ServiceReminderController@notify` | `reminders.manage` |
| GET | `/api/ServiceReminders/due-by-vehicle` | `ServiceReminderController@dueByVehicle` | `reminders.view` |

## /api/simulation

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/simulation/fault-discovery` | `SimulationController@faultDiscovery` | `users.manage` |
| POST | `/api/simulation/oil-alert` | `SimulationController@oilAlert` | `users.manage` |
| POST | `/api/simulation/reset` | `SimulationController@reset` | `users.manage` |
| GET | `/api/simulation/status` | `SimulationController@status` | `users.manage` |

## /api/StatusMismatch

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/StatusMismatch` | `StatusMismatchController@index` | `insights.view` |

## /api/supplier-payments

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/supplier-payments` | `SupplierPaymentController@store` | `maintenance.manage` |
| GET | `/api/supplier-payments` | `SupplierPaymentController@index` | `maintenance.view` |
| POST | `/api/supplier-payments/{supplierPayment}/allocate` | `SupplierPaymentController@allocate` | `maintenance.manage` |
| POST | `/api/supplier-payments/{supplierPayment}/cancel` | `SupplierPaymentController@cancel` | `maintenance.manage` |

## /api/Sync

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/Sync/audit` | `SyncAuditController@index` | `sync.run` |
| GET | `/api/Sync/audit/{syncRun}` | `SyncAuditController@show` | `sync.run` |

## /api/TripDashboard

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/TripDashboard` | `TripDashboardController@index` | `dashboard.view` |
| GET | `/api/TripDashboard/refresh` | `TripDashboardController@refresh` | `dashboard.view` |

## /api/user

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/user` | `*(closure)*` |  |

## /api/Vehicle

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/Vehicle` | `VehicleController@store` | `vehicles.manage` |
| GET | `/api/Vehicle` | `VehicleController@index` | `vehicles.view` |
| GET | `/api/Vehicle/{vehicle}` | `VehicleController@show` | `vehicles.view` |
| POST | `/api/Vehicle/{vehicle}` | `VehicleController@update` | `vehicles.manage` |
| DELETE | `/api/Vehicle/{vehicle}` | `VehicleController@destroy` | `vehicles.manage` |
| GET | `/api/Vehicle/{vehicle}/activity` | `VehicleActivityController@vehicle` | `vehicles.view` |
| POST | `/api/Vehicle/{vehicle}/apply-baseline` | `VehicleController@applyBaseline` | `vehicles.manage` |
| GET | `/api/Vehicle/{vehicle}/components` | `VehicleComponentController@forVehicle` | `components.view` |
| POST | `/api/Vehicle/{vehicle}/condition` | `VehicleController@updateCondition` | `vehicles.manage` |
| POST | `/api/Vehicle/{vehicle}/defer-maintenance` | `VehicleController@deferMaintenance` | `maintenance.manage` |
| DELETE | `/api/Vehicle/{vehicle}/defer-maintenance` | `VehicleController@resolveDeferMaintenance` | `maintenance.manage` |
| GET | `/api/Vehicle/{vehicle}/operation` | `OperationController@current` | `vehicles.view` |
| POST | `/api/Vehicle/{vehicle}/operation` | `OperationController@start` | `operations.manage` |
| GET | `/api/Vehicle/{vehicle}/plate-history` | `VehicleController@plateHistory` | `vehicles.view` |
| GET | `/api/Vehicle/{vehicle}/profile` | `VehicleController@profile` | `vehicles.view` |
| GET | `/api/Vehicle/{vehicle}/repeat-faults` | `VehicleController@repeatFaults` | `maintenance.view` |
| GET | `/api/Vehicle/{vehicle}/service-history` | `VehicleController@serviceHistory` | `maintenance.view` |
| POST | `/api/Vehicle/{vehicle}/service-ticket` | `VehicleController@openServiceTicket` | `maintenance.initiate` |
| GET | `/api/Vehicle/{vehicle}/status-on` | `VehicleController@statusOn` | `insights.view` |
| GET | `/api/Vehicle/{vehicle}/suggested-checks` | `VehicleController@suggestedChecks` | `maintenance.view` |
| GET | `/api/Vehicle/{vehicle}/tire-history` | `VehicleController@tireHistory` | `maintenance.view` |
| GET | `/api/Vehicle/active-shop-stays` | `VehicleController@activeShopStays` | `insights.view` |
| GET | `/api/Vehicle/maintenance-overlaps` | `VehicleController@maintenanceOverlaps` | `insights.view` |
| GET | `/api/Vehicle/mileage-reconciliation` | `VehicleController@mileageReconciliation` | `insights.view` |
| GET | `/api/Vehicle/utilization` | `VehicleController@utilization` | `insights.view` |

## /api/vehicle-locations

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/vehicle-locations` | `VehicleLocationController@store` | `maintenance.manage` |
| GET | `/api/vehicle-locations` | `VehicleLocationController@index` | `maintenance.view` |
| POST | `/api/vehicle-locations/{vehicleLocation}` | `VehicleLocationController@update` | `maintenance.manage` |
| DELETE | `/api/vehicle-locations/{vehicleLocation}` | `VehicleLocationController@destroy` | `maintenance.manage` |
| POST | `/api/vehicle-locations/{vehicleLocation}/toggle` | `VehicleLocationController@toggle` | `maintenance.manage` |
| POST | `/api/vehicle-locations/groups` | `VehicleLocationController@storeGroup` | `maintenance.manage` |
| POST | `/api/vehicle-locations/groups/{group}` | `VehicleLocationController@updateGroup` | `maintenance.manage` |
| DELETE | `/api/vehicle-locations/groups/{group}` | `VehicleLocationController@destroyGroup` | `maintenance.manage` |
| POST | `/api/vehicle-locations/groups/reorder` | `VehicleLocationController@reorderGroups` | `maintenance.manage` |
| POST | `/api/vehicle-locations/policy` | `VehicleLocationController@updatePolicy` | `maintenance.manage` |
| POST | `/api/vehicle-locations/policy/reset` | `VehicleLocationController@resetPolicy` | `maintenance.manage` |
| POST | `/api/vehicle-locations/reorder` | `VehicleLocationController@reorder` | `maintenance.manage` |
| POST | `/api/vehicle-locations/settings` | `VehicleLocationController@updateSettings` | `maintenance.manage` |

## /api/vehicle-status

| Method | Path | Handler | Permission |
|---|---|---|---|
| GET | `/api/vehicle-status` | `VehicleStatusController@index` | `insights.view` |
| POST | `/api/vehicle-status/{vehicle}/set-ready` | `VehicleStatusController@setReady` | `maintenance.initiate|maintenance.delegate` |
| GET | `/api/vehicle-status/{vehicle}/timeline` | `VehicleStatusController@timeline` | `insights.view` |
| GET | `/api/vehicle-status/history` | `VehicleStatusController@history` | `insights.view` |

## /api/Vendor

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/Vendor` | `VendorController@store` | `vendors.manage` |
| GET | `/api/Vendor` | `VendorController@index` | `vendors.view` |
| DELETE | `/api/Vendor/{vendor}` | `VendorController@destroy` | `vendors.manage` |
| POST | `/api/Vendor/{vendor}` | `VendorController@update` | `vendors.manage` |
| GET | `/api/Vendor/{vendor}` | `VendorController@show` | `vendors.view` |

## /api/warranties

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/warranties` | `WarrantyController@store` | `parts.purchase|maintenance.manage` |
| GET | `/api/warranties` | `WarrantyController@index` | `parts.view|maintenance.view` |
| POST | `/api/warranties/{warranty}` | `WarrantyController@update` | `parts.purchase|maintenance.manage` |
| DELETE | `/api/warranties/{warranty}` | `WarrantyController@destroy` | `parts.investigate|maintenance.manage` |
| GET | `/api/warranties/{warranty}` | `WarrantyController@show` | `parts.view|maintenance.view` |
| POST | `/api/warranties/{warranty}/claims` | `WarrantyController@storeClaim` | `parts.purchase|maintenance.manage` |
| GET | `/api/warranties/{warranty}/claims` | `WarrantyController@claims` | `parts.view|maintenance.view` |
| POST | `/api/warranties/{warranty}/reinstate` | `WarrantyController@reinstate` | `parts.investigate|maintenance.manage` |
| POST | `/api/warranties/{warranty}/void` | `WarrantyController@void` | `parts.investigate|maintenance.manage` |
| GET | `/api/warranties/vehicle/{vehicle}` | `WarrantyController@forVehicle` | `parts.view|maintenance.view` |

## /api/warranty-claims

| Method | Path | Handler | Permission |
|---|---|---|---|
| POST | `/api/warranty-claims/{claim}/resolve` | `WarrantyController@resolveClaim` | `parts.investigate|maintenance.manage` |

