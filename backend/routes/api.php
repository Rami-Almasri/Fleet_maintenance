<?php

use App\Http\Controllers\DataHealthController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingReadinessController;
use App\Http\Controllers\CleaningController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\ExchangeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\FaultCauseController;
use App\Http\Controllers\FindingKeywordController;
use App\Http\Controllers\VehicleLocationController;
use App\Http\Controllers\FleetController;
use App\Http\Controllers\GarageInvoiceController;
use App\Http\Controllers\MaintenanceInvoiceController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\MileageChainAuditController;
use App\Http\Controllers\OdometerChangeRequestController;
use App\Http\Controllers\FuelMileageController;
use App\Http\Controllers\ProfitabilityController;
use App\Http\Controllers\ReadinessController;
use App\Http\Controllers\RentalOperationsController;
use App\Http\Controllers\FinancialConflictController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SimulationController;
use App\Http\Controllers\LogisticsDispatchController;
use App\Http\Controllers\PartRequestController;
use App\Http\Controllers\PartPurchaseController;
use App\Http\Controllers\PartInvestigationController;
use App\Http\Controllers\RecurringFaultReviewController;
use App\Http\Controllers\MaintenanceSwapController;
use App\Http\Controllers\MaintenanceIntelligenceController;
use App\Http\Controllers\MaintenanceWorkflowController;
use App\Http\Controllers\MaintenanceCheckpointController;
use App\Http\Controllers\InspectorPadController;
use App\Http\Controllers\OperationController;
use App\Http\Controllers\StatusMismatchController;
use App\Http\Controllers\SyncAuditController;
use App\Http\Controllers\VehicleActivityController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VehicleStatusController;
use App\Http\Controllers\VehicleRegistrationController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\CostCaptureController;
use App\Http\Controllers\InspectionController;
use App\Http\Controllers\InspectionScheduleController;
use App\Http\Controllers\ServiceReminderController;
use App\Http\Controllers\ContactReminderController;
use App\Http\Controllers\WorkshopEventController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Authorization model: every authenticated route also carries a Spatie
// `permission:` middleware. Reads require `<resource>.view`, writes require
// `<resource>.manage`. Permissions and the roles that grant them live in
// database/seeders/RolesAndPermissionsSeeder.php. super-admin bypasses all
// checks via Gate::before (App\Providers\AppServiceProvider).

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::prefix('auth')->controller(AuthController::class)->group(function () {
    // Admin-only account directory — same gate as signup.
    Route::get('users', 'index')->middleware(['auth:sanctum', 'permission:users.manage']);
    // Assignable role list for the admin create/edit form.
    Route::get('roles', 'roles')->middleware(['auth:sanctum', 'permission:users.manage']);
    // signup is NOT public — it is the admin-only "create a user" endpoint.
    // Only an authenticated user holding `users.manage` may mint accounts.
    Route::post('signup', 'signup')->middleware(['auth:sanctum', 'permission:users.manage']);
    // Edit / delete an existing account — same admin gate as the rest of user management.
    Route::put('users/{user}', 'update')->middleware(['auth:sanctum', 'permission:users.manage']);
    Route::delete('users/{user}', 'destroy')->middleware(['auth:sanctum', 'permission:users.manage']);
    // Throttle login to blunt credential-stuffing/brute force (per IP+email).
    Route::post('login', 'login')->middleware('throttle:10,1');
    // The signed-in account, refreshed from the database (roles + permissions). The SPA calls this on
    // boot so a role change takes effect on the next page load, not the next login.
    Route::get('me', 'me')->middleware('auth:sanctum');
    Route::post('logout', 'logout');
});

// Workforce Operations Center — real user-activity surfaces layered on the
// existing account directory. Reads reuse the `users.manage` gate; the heartbeat
// is open to any authenticated user (each reports only their own presence).
Route::prefix('auth')->controller(\App\Http\Controllers\WorkforceController::class)->group(function () {
    Route::post('activity', 'heartbeat')->middleware('auth:sanctum');
    Route::get('workforce', 'overview')->middleware(['auth:sanctum', 'permission:users.manage']);
    Route::get('users/{user}/activity', 'userActivity')->middleware(['auth:sanctum', 'permission:users.manage']);
});
Route::middleware('auth:sanctum')->prefix('Vehicle')->controller(VehicleController::class)->group(function () {

    Route::get('/', 'index')->middleware('permission:vehicles.view');
    Route::get('/utilization', 'utilization')->middleware('permission:insights.view');   // fleet rented/maintenance/idle days (static — must precede /{vehicle})
    Route::get('/active-shop-stays', 'activeShopStays')->middleware('permission:insights.view'); // ops: cars on rent but stuck in the shop (static — must precede /{vehicle})
    Route::get('/maintenance-overlaps', 'maintenanceOverlaps')->middleware('permission:insights.view'); // ops: maintenance↔rental overlap history for true off-road shop days (static — must precede /{vehicle})
    Route::get('/mileage-reconciliation', 'mileageReconciliation')->middleware('permission:insights.view'); // system odometer vs scanner value (static — must precede /{vehicle})
    Route::get('/{vehicle}/status-on', 'statusOn')->middleware('permission:insights.view');   // time machine: car's status on a given day
    Route::get('/{vehicle}/service-history', 'serviceHistory')->middleware('permission:maintenance.view'); // technical service log (parts/services done, searchable)
    Route::get('/{vehicle}/tire-history', 'tireHistory')->middleware('permission:maintenance.view');       // tyre details (brand/DOT/tread/warranty) from maintenance line items
    Route::get('/{vehicle}/plate-history', 'plateHistory')->middleware('permission:vehicles.view');        // every car that shared this plate (reuse timeline) — history discoverable, never merged
    Route::get('/{vehicle}/repeat-faults', 'repeatFaults')->middleware('permission:maintenance.view');     // "keeps breaking down": faults that returned after a repair, as a chain of episodes
    Route::get('/{vehicle}/suggested-checks', 'suggestedChecks')->middleware('permission:maintenance.view'); // what to inspect on THIS car: its repeat faults + service forecast (never a fixed checklist)
    Route::get('/{vehicle}/profile', 'profile')->middleware('permission:vehicles.view');   // full car profile: registration, insurance, fines, contracts
    Route::get('/{vehicle}', 'show')->middleware('permission:vehicles.view');
    Route::post('/', 'store')->middleware('permission:vehicles.manage');
    Route::post('/{vehicle}/apply-baseline', 'applyBaseline')->middleware('permission:vehicles.manage'); // adopt the scanner's validated odometer for one car
    Route::post('/{vehicle}/condition', 'updateCondition')->middleware('permission:vehicles.manage');    // set the Visual Condition Grade (green/orange/red)
    Route::post('/{vehicle}/service-ticket', 'openServiceTicket')->middleware('permission:maintenance.initiate'); // Service-Due → originate a maintenance ticket for the due service (vehicle updates only on ticket close)
    Route::post('/{vehicle}/defer-maintenance', 'deferMaintenance')->middleware('permission:maintenance.manage');     // flag "owes maintenance" (pulled out of the shop for a customer)
    Route::delete('/{vehicle}/defer-maintenance', 'resolveDeferMaintenance')->middleware('permission:maintenance.manage'); // supervisor Resolve / Dismiss of the flag
    Route::post('/{vehicle}', 'update')->middleware('permission:vehicles.manage');
    Route::delete('/{vehicle}', 'destroy')->middleware('permission:vehicles.manage');
});

// Activity Audit Trail — the "total transparency" read layer. One unified, newest-first timeline per
// car (every action: inspection / cleaning / readiness / condition / maintenance / movement) plus the
// fleet-wide manager feed with action-category + time-window filters. Pure reads over
// ActivityFeedService (unions vehicle_log_events + logistics_task_events + inspection_records).
Route::middleware('auth:sanctum')->controller(VehicleActivityController::class)->group(function () {
    Route::get('Vehicle/{vehicle}/activity', 'vehicle')->middleware('permission:vehicles.view'); // one car's full history timeline
    Route::get('Fleet/life-status', 'fleetStatus')->middleware('permission:vehicles.view');       // per-car live "where is it now" chips + counts
    Route::get('Activity', 'feed')->middleware('permission:insights.view');                       // fleet-wide activity feed (manager view)
});

// Vehicle Installed Components — the "rolling asset" read layer: exactly what is fitted to a car
// TODAY, the full replacement history of every slot, and the fleet-wide component dashboard
// (warranty expiring / past expected life / recently + frequently replaced / installed value / age).
//
// READ-ONLY BY DESIGN — there is no create route here and there must never be one. A component
// appears on a vehicle only when the maintenance workflow reaches its install step
// (PartWorkflowService::installPurchase → ComponentService), which is what keeps the record equal to
// the physical car. See VehicleComponentController's docblock.
Route::middleware(['auth:sanctum', 'permission:components.view'])->controller(\App\Http\Controllers\VehicleComponentController::class)->group(function () {
    Route::get('components/dashboard', 'dashboard');            // fleet cards (static — must precede /{component})
    Route::get('components/catalog', 'catalog');                // component TYPE list for the install step (static — must precede /{component})
    Route::get('components/{component}', 'show');               // one component's dossier + replacement chain
    Route::get('Vehicle/{vehicle}/components', 'forVehicle');    // one car's current configuration + history
});

// Parts Catalog — the WRITE side of component_catalog: the one list of part names the whole app
// selects from (required parts, purchases, installs), in English and Arabic, with the search
// aliases that let someone find a part by the symptom instead of the part name.
//
// This is the deliberate exception to the read-only rule above. A component INSTANCE may only be
// created by the workflow (that rule stands), but the VOCABULARY of part types is reference data
// the operation must be able to curate — the alternative, which we lived with, is a free-text box
// and a hundred spellings of the same part. Every write stamps `edited_in_app`, after which the
// config seeder leaves the row alone for good; see PartsCatalogController.
//
// Reading is wider than writing on purpose: anyone who can raise a part request needs the list, or
// the picker is empty for exactly the people it exists for. Curation stays at components.manage,
// which already means "curate the catalog" in the permission seeder.
Route::middleware('auth:sanctum')->prefix('parts-catalog')->controller(\App\Http\Controllers\PartsCatalogController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:parts.view|components.view|maintenance.view');
    Route::post('/', 'store')->middleware('permission:components.manage');
    Route::post('/{part}', 'update')->middleware('permission:components.manage');          // POST like Vendor/FindingKeyword
    // Retire and restore are the pair that matter operationally: a part type is almost never
    // deleted (anything ever fitted, warranted or asked for is referenced forever), so retiring is
    // the real "stop offering this" action and DELETE is reserved for a row created by mistake.
    Route::post('/{part}/retire', 'retire')->middleware('permission:components.manage');   // hide from pickers
    Route::post('/{part}/restore', 'restore')->middleware('permission:components.manage'); // un-retire
    Route::delete('/{part}', 'destroy')->middleware('permission:components.manage');       // retires when in use
});

// Warranties — the promises suppliers and garages made us, and every claim made against them.
//
// TWO KINDS, ONE TABLE: kind=part is owed by the SUPPLIER (anchored to the purchase or the fitted
// component); kind=repair is owed by the GARAGE (anchored to the FAULT, which is what makes a
// comeback provable). Both carry BOTH expiry legs — months and kilometres, whichever runs out
// first — and whether one is still live is COMPUTED per read against the car's current odometer,
// never stored. See the create_warranties_table migration and WarrantyService.
//
// Permissions: reading rides with parts.view (the people chasing a warranty are the people who
// bought the part). Recording and claiming is parts.purchase — the same bar as spending money,
// because a warranty is the other side of that transaction. Voiding a warranty and adjudicating a
// claim's outcome is parts.investigate: those are the two actions that decide whether money is
// recoverable, and they are the ones a supplier would dispute.
Route::middleware('auth:sanctum')->prefix('warranties')->controller(\App\Http\Controllers\WarrantyController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:parts.view|maintenance.view');
    Route::post('/', 'store')->middleware('permission:parts.purchase|maintenance.manage');
    // Static segment BEFORE /{warranty} so "vehicle" is never swallowed as a model binding.
    Route::get('/vehicle/{vehicle}', 'forVehicle')->middleware('permission:parts.view|maintenance.view');
    Route::get('/{warranty}', 'show')->middleware('permission:parts.view|maintenance.view');
    Route::post('/{warranty}', 'update')->middleware('permission:parts.purchase|maintenance.manage');
    Route::delete('/{warranty}', 'destroy')->middleware('permission:parts.investigate|maintenance.manage');

    Route::post('/{warranty}/void', 'void')->middleware('permission:parts.investigate|maintenance.manage');
    Route::post('/{warranty}/reinstate', 'reinstate')->middleware('permission:parts.investigate|maintenance.manage');

    Route::get('/{warranty}/claims', 'claims')->middleware('permission:parts.view|maintenance.view');
    Route::post('/{warranty}/claims', 'storeClaim')->middleware('permission:parts.purchase|maintenance.manage');
});

// A claim's outcome is adjudication, not data entry — it sits outside the /warranties tree because
// the claim id is enough to find it and the permission bar is different.
Route::middleware(['auth:sanctum', 'permission:parts.investigate|maintenance.manage'])
    ->post('warranty-claims/{claim}/resolve', [\App\Http\Controllers\WarrantyController::class, 'resolveClaim']);

// Vehicle Status Dashboard — the team's all-day follow-up board (one derived row per car: status,
// current owner, last/next action, days-in-status, blocked). Read-only aggregation (insights.view);
// the Supervisor's "Set to Ready" sign-off reuses the same authority that closes a workflow ticket.
Route::middleware('auth:sanctum')->prefix('vehicle-status')->controller(VehicleStatusController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:insights.view');
    Route::get('/history', 'history')->middleware('permission:insights.view');
    Route::get('/{vehicle}/timeline', 'timeline')->middleware('permission:insights.view');
    Route::post('/{vehicle}/set-ready', 'setReady')->middleware('permission:maintenance.initiate|maintenance.delegate');
});

// Readiness Dashboard — the role-scoped four-pillar task board (Logistics check-out/in, Supervisor
// damage reviews, Inspector garage sign-offs) + the Pre-Delivery Readiness Gate's read side. Gated
// broadly so every operational role reaches its own queue; the page itself scopes what each sees.
Route::middleware('auth:sanctum')->prefix('readiness')->controller(ReadinessController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:insights.view|logistics.view|maintenance.view|maintenance.initiate|maintenance.delegate|inspections.view');
    // NB: contracts.view|contracts.manage included so the Rental Operations Hub's contract-detail
    // readiness panel (9-point evaluate) loads for a rental manager who only holds contract perms.
    Route::get('/vehicle/{vehicle}', 'vehicle')->middleware('permission:contracts.view|contracts.manage|insights.view|logistics.view|maintenance.view|maintenance.initiate|maintenance.delegate|inspections.view');

    // Rental Readiness Checklist — the 8-point pre-confirm gate on the contract form (sales side).
    Route::get('/vehicle/{vehicle}/rental-checklist', 'rentalChecklist')->middleware('permission:contracts.view|contracts.manage|insights.view|logistics.view|maintenance.view|inspections.view');
    Route::post('/vehicle/{vehicle}/checklist-field', 'setChecklistField')->middleware('permission:contracts.manage|vehicles.manage');
});

// Vehicle Cleaning — before/after photo capture + clean/dirty flag for the Booking Readiness Cleaning
// point. Reads gated broadly (so the readiness "Fix" link opens for any prep role); writes require the
// same manage perm as the readiness checklist field.
Route::middleware('auth:sanctum')->prefix('cleaning')->controller(CleaningController::class)->group(function () {
    Route::get('/vehicle/{vehicle}', 'show')->middleware('permission:contracts.view|contracts.manage|vehicles.view|vehicles.manage|booking_readiness.view|inspections.view');
    Route::post('/vehicle/{vehicle}/photo', 'store')->middleware('permission:contracts.manage|vehicles.manage');
    Route::delete('/vehicle/{vehicle}/photo/{photo}', 'destroy')->middleware('permission:contracts.manage|vehicles.manage');
    Route::post('/vehicle/{vehicle}/status', 'setStatus')->middleware('permission:contracts.manage|vehicles.manage');
});

Route::middleware('auth:sanctum')->prefix('Driver')->controller(DriverController::class)->group(function () {

    Route::get('/', 'index')->middleware('permission:drivers.view');
    Route::get('/{driver}', 'show')->middleware('permission:drivers.view');
    Route::post('/', 'store')->middleware('permission:drivers.manage');
    Route::post('/{driver}', 'update')->middleware('permission:drivers.manage');
    Route::delete('/{driver}', 'destroy')->middleware('permission:drivers.manage');
});
Route::middleware('auth:sanctum')->prefix('Vendor')->controller(VendorController::class)->group(function () {

    Route::get('/', 'index')->middleware('permission:vendors.view');
    Route::get('/{vendor}', 'show')->middleware('permission:vendors.view');
    Route::post('/', 'store')->middleware('permission:vendors.manage');
    Route::post('/{vendor}', 'update')->middleware('permission:vendors.manage');
    Route::delete('/{vendor}', 'destroy')->middleware('permission:vendors.manage');
});
Route::middleware('auth:sanctum')->prefix('Customer')->controller(CustomerController::class)->group(function () {

    Route::get('/', 'index')->middleware('permission:customers.view');
    Route::get('/{customer}/profile', 'profile')->middleware('permission:customers.view');   // identity + financials + per-contract breakdown
    Route::get('/{customer}/balance', 'balance')->middleware('permission:customers.view');   // financial summary across all contracts
    Route::get('/{customer}', 'show')->middleware('permission:customers.view');
    Route::post('/', 'store')->middleware('permission:customers.manage');
    Route::post('/{customer}', 'update')->middleware('permission:customers.manage');
    Route::delete('/{customer}', 'destroy')->middleware('permission:customers.manage');
});
// Rental Operations Hub — active + upcoming rentals/bookings, each with the live 9-check readiness
// verdict for its car. The Rental Manager's check-in/out surface (reads VehicleReadinessService).
Route::middleware('auth:sanctum')->controller(RentalOperationsController::class)->group(function () {
    Route::get('RentalOperations', 'index')->middleware('permission:contracts.view');
});

Route::middleware('auth:sanctum')->prefix('Contract')->controller(ContractController::class)->group(function () {

    Route::get('/', 'index')->middleware('permission:contracts.view');
    Route::get('/next-no', 'nextNo')->middleware('permission:contracts.manage');   // auto contract number for the New form
    Route::get('/{contract}', 'show')->middleware('permission:contracts.view');
    // The visit journey: every fault, garage, repair time and event under a maintenance contract.
    // Declared BEFORE nothing in particular — '{contract}/journey' can't collide with '{contract}' —
    // but kept next to show() because it's the same page's second half.
    // Readable by maintenance viewers too: the people who lived the visit shouldn't need contract rights
    // to read it back.
    Route::get('/{contract}/journey', 'journey')->middleware('permission:contracts.view|maintenance.view');
    Route::post('/', 'store')->middleware('permission:contracts.manage');
    Route::post('/{contract}', 'update')->middleware('permission:contracts.manage');
    Route::delete('/{contract}', 'destroy')->middleware('permission:contracts.manage');
});

// Mid-rental oil-change projection: where a rented-out car's odometer has probably reached, and
// the customer-reported readings that re-anchor it. The 2-segment Contract routes are registered
// after the 1-segment `Contract/{contract}` group above, which is safe (see the Exchange note).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('OilProjection', [\App\Http\Controllers\OilProjectionController::class, 'index'])
        ->middleware('permission:reminders.view');
    Route::get('Contract/{contract}/oil-projection', [\App\Http\Controllers\OilProjectionController::class, 'show'])
        ->middleware('permission:reminders.view');
    Route::post('Contract/{contract}/mileage-reading', [\App\Http\Controllers\OilProjectionController::class, 'reading'])
        ->middleware('permission:reminders.manage');
    // Recall now vs do it on return — the operational call on a rental that can't finish inside
    // the oil tolerance. Same permission as recording the reading: it's the same person, on the
    // same call, acting on what the number just told them.
    Route::post('Contract/{contract}/oil-decision', [\App\Http\Controllers\OilProjectionController::class, 'decide'])
        ->middleware('permission:reminders.manage');
    // The SALES GATE on a recall. Until this is clicked no driver hears anything — the car is a
    // paying customer's until Sales say they have agreed to give it back. Same permission as the
    // decision itself: it is the same person, closing the loop on the call they started.
    Route::post('Contract/{contract}/oil-recall/sales-confirm', [\App\Http\Controllers\OilProjectionController::class, 'confirmSales'])
        ->middleware('permission:reminders.manage');
    // What the car owes after collection. Accepts `test_required` only — the oil change is derived
    // from the recall, never posted, so no caller can remove it.
    Route::post('Contract/{contract}/oil-recall/instructions', [\App\Http\Controllers\OilProjectionController::class, 'collectionInstructions'])
        ->middleware('permission:reminders.manage');
    // THE OIL WAS CHANGED. One number — the odometer it was changed at — and the follow-up ends:
    // the car's next service runs from that reading, the projection re-anchors on it, and the
    // recall, the collection and the announcing inspection request all stand down. Reachable by
    // whoever is actually holding the car: the follow-up controllers OR the workshop side, which is
    // why it accepts either permission instead of forcing the change to be relayed back to Leen.
    Route::post('Contract/{contract}/oil-change-done', [\App\Http\Controllers\OilProjectionController::class, 'oilChanged'])
        // …and the DRIVER too: on a parking job he is often the one who changes it, and making him
        // relay the number back to Leen to type in is how a reading stops being a reading.
        ->middleware('permission:reminders.manage|maintenance.manage|maintenance.logistics');
    // GIVE THE CAR BACK. The oil is done, the customer is still paying, and the car is in our yard —
    // this is what ends the recall and silences the chase. Same audience as recording the change:
    // whoever is standing next to the car hands over the keys.
    Route::post('Contract/{contract}/oil-returned', [\App\Http\Controllers\OilProjectionController::class, 'returnedToCustomer'])
        ->middleware('permission:reminders.manage|maintenance.manage|maintenance.logistics');
    // The recall call queue — "phone the customer and arrange the return". A follow-up task, NOT a
    // logistics dispatch: no route, no driver, no ETA (see the OilRecallTask migration).
    Route::get('OilRecallTasks', [\App\Http\Controllers\OilProjectionController::class, 'recallTasks'])
        ->middleware('permission:reminders.view');
    Route::patch('OilRecallTasks/{task}', [\App\Http\Controllers\OilProjectionController::class, 'updateRecallTask'])
        ->middleware('permission:reminders.manage');
});

// IN THE GARAGE — every car at a garage right now, and which garage. Two sources: the workflow
// ticket (the Supervisor already picked the garage) and an open OfficeManager maintenance contract
// (OM has no garage field, so a person records it). See InGarageService.
// The 2-segment `Contract/{contract}/garage` route is registered after the 1-segment
// `Contract/{contract}` group above, which is safe (see the Exchange note).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('InGarage', [\App\Http\Controllers\InGarageController::class, 'index'])
        ->middleware('permission:maintenance.view');
    // Writing the garage onto one open maintenance visit. `maintenance.manage` — the same people
    // who move cars between garages are the ones who know where a car actually went.
    Route::post('Contract/{contract}/garage', [\App\Http\Controllers\InGarageController::class, 'recordGarage'])
        ->middleware('permission:maintenance.manage');
});

// Invoices CRUD — website-created (manual) invoices coexist with OfficeManager-synced ones.
// Reads return both ledgers; writes only ever touch manual invoices (the controller guards
// origin). ?contract_id= scopes the list to one contract (the Contract Detail panel).
Route::middleware('auth:sanctum')->prefix('Invoice')->controller(\App\Http\Controllers\InvoiceController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:billing.view');
    // Track A — rental payment-status summary for the dashboard (STATIC — must precede /{invoice}).
    Route::get('/status-summary', 'statusSummary')->middleware('permission:billing.view');
    Route::post('/', 'store')->middleware('permission:billing.manage');
    Route::get('/{invoice}', 'show')->middleware('permission:billing.view');
    Route::post('/{invoice}', 'update')->middleware('permission:billing.manage');
    Route::delete('/{invoice}', 'destroy')->middleware('permission:billing.manage');
});

// Payments / Receipts CRUD — the collection side of a contract, recorded on the website.
Route::middleware('auth:sanctum')->prefix('Payment')->controller(\App\Http\Controllers\PaymentController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:billing.view');
    Route::post('/', 'store')->middleware('permission:billing.manage');
    Route::get('/{payment}', 'show')->middleware('permission:billing.view');
    Route::post('/{payment}', 'update')->middleware('permission:billing.manage');
    Route::delete('/{payment}', 'destroy')->middleware('permission:billing.manage');
});

// Contract Exchange — detect & link "car swaps" (customer returns one car, takes another).
// Static `exchanges/pending` (3 segments) and `{contract}/exchange/*` don't collide with the
// 2-segment `Contract/{contract}` above, so registration order here is safe.
Route::middleware('auth:sanctum')->prefix('Contract')->controller(ExchangeController::class)->group(function () {
    Route::get('/exchanges/pending', 'pending')->middleware('permission:contracts.view');     // suggestion feed
    Route::get('/{contract}/exchange', 'show')->middleware('permission:contracts.view');       // chain + candidates
    Route::post('/{contract}/exchange/link', 'link')->middleware('permission:contracts.manage'); // link (+optional carry)
    Route::delete('/{contract}/exchange/link', 'unlink')->middleware('permission:contracts.manage'); // undo link
});

// Vehicle operations / state changes (every movement becomes a Contract)
Route::middleware('auth:sanctum')->controller(OperationController::class)->group(function () {
    Route::get('Vehicle/{vehicle}/operation', 'current')->middleware('permission:vehicles.view');   // current open movement
    Route::post('Vehicle/{vehicle}/operation', 'start')->middleware('permission:operations.manage'); // start rent/maintenance/test_drive/transfer/sale_prep
    Route::post('Contract/{contract}/close', 'close')->middleware('permission:operations.manage');   // close an open movement
});

// Maintenance Swap board — assign a replacement car when a rented vehicle needs urgent maintenance.
Route::middleware('auth:sanctum')->prefix('maintenance-swaps')->controller(MaintenanceSwapController::class)->group(function () {
    Route::get('/board', 'board')->middleware('permission:insights.view');                       // queue + pool + active swaps
    Route::post('/', 'store')->middleware('permission:operations.manage');                       // assign a replacement
    Route::delete('/{maintenanceSwap}', 'release')->middleware('permission:operations.manage');  // end a swap
});

// Logistics Dispatch — the data-driven replacement for WhatsApp coordination. A COORDINATOR raises a
// move (pooled, or pre-assigned); the first DRIVER to claim it owns it and walks it through Picked Up →
// Delivered → Returned/Arrived (GPS-stamped). Every step auto-audits + auto-updates the coordinator.
// Static `/pool`, `/my-queue`, `/assignees` precede the `{logisticsTask}` wildcard. Claim/pickup/
// deliver/return/status are gated logistics.view (the controller enforces assignee-or-dispatcher);
// raising/cancelling/pinging is coordinator-only (logistics.dispatch); claiming needs logistics.claim.
Route::middleware('auth:sanctum')->prefix('logistics')->controller(LogisticsDispatchController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:logistics.view');                          // all open dispatches (board)
    Route::get('/pool', 'pool')->middleware('permission:logistics.view');                        // unclaimed moves up for grabs
    Route::get('/my-queue', 'myQueue');                                                          // current user's claimed queue
    // Cars to collect FROM CUSTOMERS — the driver's panel on /my-maintenance-queue. Includes his
    // just-delivered ones whose oil change is still unrecorded, because a one-way move closes on
    // delivery and the card would otherwise vanish one step before its last step.
    Route::get('/my-collections', 'myCollections');
    Route::get('/assignees', 'assignees')->middleware('permission:logistics.dispatch');          // people + destination presets
    Route::get('/drivers', 'roster')->middleware('permission:logistics.view');                   // driver availability roster (free / busy + what they're on)
    Route::post('/', 'store')->middleware('permission:logistics.dispatch');                      // coordinator raises a move
    Route::post('/{logisticsTask}/claim', 'claim')->middleware('permission:logistics.claim');     // driver takes a pooled move
    // The assignee steps the round trip along — each call auto-audits (who+when) and pings the coordinator.
    Route::post('/{logisticsTask}/pickup', 'pickup')->middleware('permission:logistics.view');     // car is with the driver
    Route::post('/{logisticsTask}/deliver', 'deliver')->middleware('permission:logistics.view');   // car at the destination
    Route::post('/{logisticsTask}/return', 'markReturned')->middleware('permission:logistics.view'); // car back at base (+ GPS)
    Route::post('/{logisticsTask}/complete', 'complete')->middleware('permission:logistics.view');  // back-compat one-tap close
    Route::post('/{logisticsTask}/cancel', 'cancel')->middleware('permission:logistics.dispatch');  // call off a move
    // Supervisor override — reassign the move to a different driver at any point (pulls a pooled move out
    // of the pool and locks it to the new driver). Coordinator-only.
    Route::post('/{logisticsTask}/reassign', 'reassign')->middleware('permission:logistics.dispatch');
    // "Where is the car?" — coordinator pings the assignee, the assignee replies one-click. One unified
    // location channel for every movement (garage or showroom), not just repairs.
    Route::post('/{logisticsTask}/ping', 'ping')->middleware('permission:logistics.dispatch');     // coordinator: "where is it?"
    Route::post('/{logisticsTask}/status', 'respondStatus')->middleware('permission:logistics.view'); // assignee: one-click status reply
});

// Parts Purchase + Repair Intelligence — the part-request lifecycle (Requested → … → Completed), the
// purchase ledger + install cost-bridge, and the admin duplicate/recurrence investigation inbox. Reads
// are parts.view; requests parts.request; buying/installing parts.purchase; adjudication parts.investigate.
// Supplier parts invoices — the paper behind what a part cost. SUPPLIER purchases only: a garage-supplied
// part is billed on that garage's maintenance invoice, and attaching it here too would charge the ticket
// twice (PartInvoiceService refuses it). Reads parts.view; keying paper is a money action → parts.purchase.
Route::middleware('auth:sanctum')->prefix('part-invoices')->controller(\App\Http\Controllers\PartInvoiceController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:parts.view');
    // Static path BEFORE /{partInvoice} so "unbilled" is never swallowed as an id.
    Route::get('/unbilled', 'unbilled')->middleware('permission:parts.view|parts.purchase');
    Route::get('/{partInvoice}', 'show')->middleware('permission:parts.view');
    Route::post('/', 'store')->middleware('permission:parts.purchase');
    Route::post('/{partInvoice}', 'update')->middleware('permission:parts.purchase');   // POST: multipart photo
    Route::delete('/{partInvoice}', 'destroy')->middleware('permission:parts.purchase');
});

// Part returns — a part sent back is an EVENT beside the purchase, never a deletion. Only the refund step
// credits the ticket (a negative line item); requested/sent move no money. Money actions → parts.purchase.
Route::middleware('auth:sanctum')->prefix('part-returns')->controller(\App\Http\Controllers\PartReturnController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:parts.view');
    Route::post('/{partReturn}/sent', 'markSent')->middleware('permission:parts.purchase');
    Route::post('/{partReturn}/refund', 'refund')->middleware('permission:parts.purchase');
    Route::post('/{partReturn}/reject', 'reject')->middleware('permission:parts.purchase');
});

// The financial-document lifecycle, shared by supplier and garage invoices: draft → pending → approved
// → paid, with cancel as the off-ramp. `{type}` is 'supplier-invoice' or 'garage-invoice'. Reading the
// vocabulary is open to any authed user; every transition is a money action → maintenance.manage.
Route::middleware('auth:sanctum')->prefix('financial-documents')->controller(\App\Http\Controllers\FinancialDocumentController::class)->group(function () {
    Route::get('/vocabulary', 'vocabulary');
    Route::middleware('permission:maintenance.manage')->group(function () {
        Route::post('/{type}/{id}/submit', 'submit');
        Route::post('/{type}/{id}/approve', 'approve');
        Route::post('/{type}/{id}/unapprove', 'unapprove');
        Route::post('/{type}/{id}/pay', 'pay');
        Route::post('/{type}/{id}/cancel', 'cancel');
    });
});

// Supplier payments — money leaving the account, recorded once and split across the bills it settles.
// An invoice's paid_amount is DERIVED from these allocations, so settlement is never typed twice.
// Recording/voiding is a money action → maintenance.manage; the reports are readable by maintenance.view
// because "what do we owe" is an operational question, not only a finance one.
Route::middleware('auth:sanctum')->prefix('supplier-payments')->controller(\App\Http\Controllers\SupplierPaymentController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:maintenance.view');
    Route::post('/', 'store')->middleware('permission:maintenance.manage');
    Route::post('/{supplierPayment}/allocate', 'allocate')->middleware('permission:maintenance.manage');
    Route::post('/{supplierPayment}/cancel', 'cancel')->middleware('permission:maintenance.manage');
});

// Procurement reporting — payables aging, supplier performance, payments made. Built on the structured
// origin, so every figure can be opened and checked rather than merely believed.
Route::middleware(['auth:sanctum', 'permission:maintenance.view'])->prefix('procurement')
    ->controller(\App\Http\Controllers\SupplierPaymentController::class)->group(function () {
        Route::get('/overview', 'overview');
        Route::get('/payables', 'payables');
        Route::get('/suppliers', 'suppliers');
        Route::get('/payments', 'paymentsReport');
    });

// Financial traceability — how much of the fleet's cost can be proved, how much is legacy backlog, and
// whether the gap is closing. Read-only reporting; the cleanup itself happens on the tickets.
Route::middleware(['auth:sanctum', 'permission:maintenance.view'])->prefix('cost-verification')
    ->controller(\App\Http\Controllers\CostVerificationController::class)->group(function () {
        Route::get('/summary', 'summary');
        Route::get('/queue', 'queue');                       // the legacy migration worklist
        Route::get('/spend-by-category', 'spendByCategory');  // documented vs undocumented, per category
    });

// Reversing a cost adjustment — the record survives, its money comes off the ticket. Money action.
Route::middleware(['auth:sanctum', 'permission:maintenance.manage'])
    ->post('cost-adjustments/{costAdjustment}/reverse', [\App\Http\Controllers\CostAdjustmentController::class, 'reverse']);

Route::middleware('auth:sanctum')->prefix('part-requests')->controller(PartRequestController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:parts.view');
    Route::post('/', 'store')->middleware('permission:parts.request');
    // Static path BEFORE /{partRequest} so "spend" is never swallowed as an id.
    Route::get('/spend', 'spend')->middleware('permission:parts.view');
    Route::get('/{partRequest}', 'show')->middleware('permission:parts.view');
    Route::post('/{partRequest}/approve', 'approve')->middleware('permission:parts.investigate|maintenance.manage');
    Route::post('/{partRequest}/reject', 'reject')->middleware('permission:parts.investigate|maintenance.manage');
    Route::post('/{partRequest}/purchase', 'purchase')->middleware('permission:parts.purchase');
    Route::post('/{partRequest}/complete', 'complete')->middleware('permission:parts.request|maintenance.manage');
});

Route::middleware('auth:sanctum')->prefix('part-purchases')->controller(PartPurchaseController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:parts.view');
    Route::get('/duplicate-check', 'duplicateCheck')->middleware('permission:parts.purchase|parts.request|parts.view');
    Route::get('/recurrence-check', 'recurrenceCheck')->middleware('permission:parts.view|maintenance.view');
    // Fleet-wide "bought again for the same car" sweep + who approved each buy (dashboard card).
    Route::get('/repeats', 'repeats')->middleware('permission:parts.view');
    Route::get('/vehicle/{vehicle}/history', 'vehicleHistory')->middleware('permission:parts.view');
    // Send a part back. Never deletes the buy — logs a return next to it (PartReturnController).
    Route::post('/{partPurchase}/returns', [\App\Http\Controllers\PartReturnController::class, 'store'])->middleware('permission:parts.purchase');
    Route::post('/{partPurchase}/install', 'install')->middleware('permission:parts.purchase|maintenance.logistics');
    Route::post('/{partPurchase}/delivered', 'markDelivered')->middleware('permission:parts.purchase|maintenance.logistics');
});

Route::middleware('auth:sanctum')->prefix('part-investigations')->controller(PartInvestigationController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:parts.investigate');
    Route::post('/{partInvestigation}/review', 'review')->middleware('permission:parts.investigate');
    Route::post('/{partInvestigation}/provide-reason', 'provideReason')->middleware('permission:parts.investigate');
    Route::post('/{partInvestigation}/approve', 'approve')->middleware('permission:parts.investigate');
    Route::post('/{partInvestigation}/reject', 'reject')->middleware('permission:parts.investigate');
});

// Fleet Maintenance Workflow — the role-driven ticket state machine (Inspector → Logistics →
// Garage → Re-inspection) that replaces the WhatsApp relay. Each transition advances ONE legal
// step and is re-guarded server-side (MaintenanceWorkflowService). Reads feed the live pipeline.
// `/board` is registered before `/{ticket}` so it's never swallowed as an id.
Route::middleware('auth:sanctum')->prefix('maintenance-tickets')->controller(MaintenanceWorkflowController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:maintenance.view');
    Route::get('/board', 'board')->middleware('permission:maintenance.view');              // live pipeline (6 columns + counts)
    Route::get('/my-queue', 'myQueue')->middleware('permission:maintenance.view');          // role-scoped dashboard (Inspector / Driver)
    Route::get('/findings-catalog', 'findingsCatalog')->middleware('permission:maintenance.view'); // central issue-keyword library
    Route::get('/assignable-drivers', 'assignableDrivers')->middleware('permission:maintenance.delegate'); // supervisor: delegation picker
    // Vehicle profile panel: health + tickets + condition photos. Static segment before {ticket}.
    Route::get('/vehicle/{vehicle}', 'vehicleTimeline')->middleware('permission:maintenance.view');
    // Post-Repair Inspection quality trail for one car (static segment before {ticket}).
    Route::get('/vehicle/{vehicle}/repair-quality', 'vehicleRepairQuality')->middleware('permission:maintenance.view');
    // Chronic Fault Watchdog — prior closed repairs of the given fault tags (?tags[]=…) on this vehicle.
    Route::get('/vehicle/{vehicle}/fault-insights', 'faultInsights')->middleware('permission:maintenance.view');
    // Park-duration (idle) — how long the car has sat since its last movement; feeds the Scheduled-tab intake.
    Route::get('/vehicle/{vehicle}/idle', 'vehicleIdle')->middleware('permission:maintenance.view');
    // Is an inspection already in flight for this car (review queue / with the Inspector / being driven)?
    // Read by the driver's Request Inspection form to show the note before submitting, so `logistics` —
    // the permission that may file a request — is enough to read it.
    Route::get('/vehicle/{vehicle}/inspection-request', 'vehicleInspectionRequest')->middleware('permission:maintenance.view|maintenance.logistics');
    // Everything the "send a car in" form needs that doesn't depend on the car: the fault vocabulary,
    // the two reason lists, and who the filer is (read from the token — it is shown, never chosen).
    // STATIC — must precede /{ticket}.
    Route::get('/request-options', 'requestOptions')->middleware('permission:maintenance.view|maintenance.logistics');
    // "Is it this again?" — the faults THIS car has already been in for, so the form can offer its own
    // history before a generic catalog. Same readership as the in-flight check above.
    Route::get('/vehicle/{vehicle}/recent-faults', 'vehicleRecentFaults')->middleware('permission:maintenance.view|maintenance.logistics');
    // Awaiting-Invoice tracker: signed-off-but-uninvoiced tickets (STATIC — must precede /{ticket}).
    Route::get('/pending-invoices', 'pendingInvoices')->middleware('permission:maintenance.view');
    // Invoice Matching Desk: cars back from the shop, each with how much of its work is on a bill and
    // whether the bills agree with their receipts (STATIC — must precede /{ticket}).
    Route::get('/invoice-matching', 'invoiceMatchingQueue')->middleware('permission:maintenance.view');
    // Inspection Request Review Gate — Controllers' (Lin & Marwa) queue. STATIC — must precede /{ticket}.
    Route::get('/pending-review', 'reviewQueue')->middleware('permission:maintenance.manage');
    // The rules behind system-raised requests — powers the queue's "when & why the system asks for a
    // test" explainer with the LIVE thresholds. STATIC — must precede /{ticket}.
    Route::get('/review-gate-rules', 'reviewGateRules')->middleware('permission:maintenance.manage');
    // The other side of the same rulebook: every active car and how many days until the system asks for
    // a test on it. Powers the queue's fleet tab. STATIC — must precede /{ticket}.
    Route::get('/test-countdown', 'testCountdown')->middleware('permission:maintenance.manage');
    // Cars physically in a shop right now (open OM type-U contract or open garage-log trip), with the
    // request each one had pending when it went in. STATIC — must precede /{ticket}.
    Route::get('/parked-in-shop', 'parkedInShop')->middleware('permission:maintenance.manage');
    // The fixed rejection-reason list the queue's picker renders (code + label). Read-only reference,
    // gated on `view`: anyone who can see a rejected request should be able to read what its stored code
    // means, without also being able to reject one. STATIC — must precede /{ticket}.
    Route::get('/review/rejection-reasons', 'reviewRejectionReasons')->middleware('permission:maintenance.view');
    // Repair Quality Tracking — fleet-wide per-garage success rate + Possible Part Failure signals.
    // STATIC — must precede /{ticket}.
    Route::get('/repair-quality', 'repairQuality')->middleware('permission:maintenance.view');
    // Fixed & Completed Repairs ledger — closed tickets, newest-closed first (STATIC — must precede /{ticket}).
    Route::get('/completed', 'completed')->middleware('permission:maintenance.view');
    // Maintenance Checkpoint — assignable responsible-user picker (STATIC — must precede /{ticket}).
    Route::get('/checkpoint-candidates', [MaintenanceCheckpointController::class, 'candidates'])->middleware('permission:maintenance.view');
    // A vehicle's checkpoint timeline for its profile tab (STATIC /vehicle/... — precedes /{ticket}).
    Route::get('/vehicle/{vehicle}/checkpoints', [MaintenanceCheckpointController::class, 'vehicleTimeline'])->middleware('permission:maintenance.view');
    // Lazily link (idempotent) the checkpoint ticket for a contract-sourced Maintenance-Progress row —
    // open type-U contract cars have no workflow ticket until their first checkpoint is filed (STATIC
    // /contract/... — precedes /{ticket}).
    Route::post('/contract/{contract}/ensure-ticket', [MaintenanceCheckpointController::class, 'ensureForContract'])->middleware('permission:maintenance.view');
    Route::get('/{ticket}', 'show')->middleware('permission:maintenance.view');
    // The car's real current mileage + the full log of manual mileage corrections on this vehicle.
    Route::get('/{ticket}/mileage', 'mileage')->middleware('permission:maintenance.view');
    // Diagnostic context — idle duration, last check (+ link), and live oil/battery/tyre status vs. limits.
    Route::get('/{ticket}/diagnostic-context', 'diagnosticContext')->middleware('permission:maintenance.view');
    // Decision Cards — what the intelligence platform thinks the user should know BEFORE deciding, at
    // this ticket's current workflow state. Each card is frozen into an append-only Recommendation as
    // it is served, and /respond records what the human actually did about it. Those two calls are the
    // whole learning loop; without the second the platform recommends but never learns.
    Route::get('/{ticket}/decision-cards', [MaintenanceIntelligenceController::class, 'cards'])->middleware('permission:maintenance.view');
    Route::post('/{ticket}/decision-cards/{recommendation}/respond', [MaintenanceIntelligenceController::class, 'respond'])->middleware('permission:maintenance.view');
    // Complaint Intake — Operations (Marwa & Leen) log a customer complaint → ticket born in Abu Maroof's
    // TRIAGE lane (complaint_triage); he decides how to handle it before any garage is involved.
    Route::post('/complaint', 'storeComplaint')->middleware('permission:maintenance.manage');
    // Complaint TRIAGE actions (Abu Maroof, maintenance.initiate): talk to the customer, resolve on-site
    // (closes the complaint), or send the car in (garage dispatch / diagnostic, optional replacement swap).
    Route::post('/{ticket}/triage/call', 'triageCall')->middleware('permission:maintenance.initiate');
    Route::post('/{ticket}/triage/resolve', 'triageResolve')->middleware('permission:maintenance.initiate');
    Route::post('/{ticket}/triage/route', 'triageRoute')->middleware('permission:maintenance.initiate');
    // Triage Routing Approval — the Supervisor/delegate (maintenance.delegate|maintenance.manage) signs off
    // Abu Maroof's "send the car in" recommendation: approve executes the route, reject bounces it to triage.
    Route::post('/{ticket}/triage/approve-route', 'approveTriageRoute')->middleware('permission:maintenance.delegate|maintenance.manage');
    Route::post('/{ticket}/triage/reject-route', 'rejectTriageRoute')->middleware('permission:maintenance.delegate|maintenance.manage');
    // Breakdown Intake — a technician (initiate) OR a manager (manage, via the Scheduled-tab test intake)
    // reports a NOT-driveable car → ticket born (grounded) in the Supervisors' dispatch queue, skipping
    // the test drive; car set RED + graded critical.
    Route::post('/breakdown', 'storeBreakdown')->middleware('permission:maintenance.initiate|maintenance.manage');
    // Stage 0 — a Driver (Logistics) requests an inspection → lands in the Controllers' review queue.
    Route::post('/request', 'requestInspection')->middleware('permission:maintenance.logistics');
    // THE SECOND DOOR — a car that needs a garage, not a diagnosis (booked service, parts arrived, the
    // garage asked for it back, a fault we already know). Skips the review gate AND the test drive: born
    // in the Supervisors' dispatch queue (inspection_pending — "Needs Dispatch"), with the named faults
    // already promoted so there is something to dispatch. Gated to diagnostic/dispatch authority — this
    // commits a car to a workshop with nobody having diagnosed it, which a Driver may not do.
    Route::post('/direct-dispatch', 'storeDirectDispatch')->middleware('permission:maintenance.initiate|maintenance.manage');
    // Inspection Request Review Gate actions — Controllers (Lin & Marwa, maintenance.manage) approve
    // (→ sent to the Inspector, exactly as before) or reject (→ terminated, nothing sent).
    Route::post('/{ticket}/review/approve', 'approveReview')->middleware('permission:maintenance.manage');
    Route::post('/{ticket}/review/reject', 'rejectReview')->middleware('permission:maintenance.manage');
    // "Remind me about this request later" — personal to the caller, books nothing for anyone else and
    // changes nothing about the request itself, so it needs no more authority than reviewing does.
    Route::post('/{ticket}/review/remind', 'remindReview')->middleware('permission:maintenance.manage');
    Route::delete('/{ticket}/review/remind', 'cancelReviewReminder')->middleware('permission:maintenance.manage');
    // Legacy system requests that bypassed the gate before it existed (see pendingReview()) — retroactive
    // sign-off only, no stage change.
    Route::post('/{ticket}/review/acknowledge-legacy', 'acknowledgeLegacyReview')->middleware('permission:maintenance.manage');
    // Task-based accountability: an inspection must always begin from a TASK (a Driver flag via
    // /request, or a system-generated mileage task) — never the Inspector picking an arbitrary car.
    // So the direct-open endpoint is gated to maintenance.manage (manager override only); the
    // Inspector (maintenance.initiate, no manage) is blocked server-side and works from his queue.
    // Stage 0 (manager entry) — a Controller (Lin/Marwa, maintenance.manage) REQUESTS an inspection.
    // They ARE the review authority, so it skips the review gate: born in inspection_requested, assigned
    // to the Inspector (Abu Maroof) who is notified immediately. NO odometer/photo — the Inspector
    // captures every reading later at Start Inspection. This is the "managers request, inspectors perform"
    // split; the direct-open store() below stays as a manager override that opens a diagnostic outright.
    Route::post('/request-inspection', 'storeInspectionRequest')->middleware('permission:maintenance.manage');
    Route::post('/', 'store')->middleware('permission:maintenance.manage');                // manager override: open a diagnostic directly
    // UC-1 / UC-2 — Inspector (Abu Maroof). `start` picks up an existing task → diagnostic.
    Route::post('/{ticket}/start', 'startDiagnostic')->middleware('permission:maintenance.initiate');
    Route::post('/{ticket}/report', 'submitReport')->middleware('permission:maintenance.initiate');
    // INSPECTION REQUIRED PARTS — the inspector's technical requirement, kept for traceability. Filing them
    // with the report (POST /report) raises the Part Requests automatically; POST here covers the mid-repair
    // case and does the same thing. There is NO convert/approve route: procurement owns every sourcing
    // decision from the moment the request exists. See MaintenanceRequiredPartService.
    Route::get('/{ticket}/required-parts', [\App\Http\Controllers\MaintenanceRequiredPartController::class, 'index'])->middleware('permission:maintenance.view');
    Route::post('/{ticket}/required-parts', [\App\Http\Controllers\MaintenanceRequiredPartController::class, 'store'])->middleware('permission:maintenance.initiate');
    // The ticket's whole cost story: fault → required part → purchase source → invoice → labour → total,
    // with supplier and garage money kept apart so neither can double-count the other. Read-only.
    Route::get('/{ticket}/cost-journey', [\App\Http\Controllers\TicketCostJourneyController::class, 'show'])->middleware('permission:maintenance.view');
    // The whole financial story in one call: ordered timeline + what is still owed before the ticket can
    // close + the audit verdict. One question, one round trip, so no two panels can disagree.
    Route::get('/{ticket}/financial-story', [\App\Http\Controllers\TicketCostJourneyController::class, 'story'])->middleware('permission:maintenance.view');
    // The accounting lifecycle per part: request → PO → invoice → received → installed → return → paid
    // → closed. Answers what is ORDERED / RECEIVED / FITTED / RETURNED / OWED, not merely what it cost.
    Route::get('/{ticket}/lifecycle', [\App\Http\Controllers\FinancialDocumentController::class, 'lifecycle'])->middleware('permission:maintenance.view');
    // Cost adjustments — the fourth source document, for money that moved without supplier or garage
    // paper (a labour refund, a goodwill discount, a keying error). Reason + approver are mandatory, so
    // recording one is a money action → maintenance.manage.
    Route::get('/{ticket}/adjustments', [\App\Http\Controllers\CostAdjustmentController::class, 'index'])->middleware('permission:maintenance.view');
    Route::post('/{ticket}/adjustments', [\App\Http\Controllers\CostAdjustmentController::class, 'store'])->middleware('permission:maintenance.manage');
    // Supervisor Delegation — a supervisor delegates a specific driver to pickup/dropoff
    // (→ "Driver Assigned"). Fault severity itself is set by the inspector at /report, not here.
    Route::post('/{ticket}/delegate', 'delegate')->middleware('permission:maintenance.delegate');
    // Data-driven garage recommendation for THIS ticket (learned from maintenance history) — surfaced in
    // the assign step so the Supervisor sees which garages have proven experience with this vehicle+fault.
    Route::get('/{ticket}/garage-recommendations', [\App\Http\Controllers\GarageRecommendationController::class, 'forTicket'])->middleware('permission:maintenance.delegate');
    // "What the garage will do" — the expected work behind this ticket's faults, WITHOUT scoring a single
    // garage. Read on the ticket itself, so it is gated on plain view: knowing what the car is having done
    // to it is not a dispatcher's privilege.
    Route::get('/{ticket}/repair-outlook', [\App\Http\Controllers\GarageRecommendationController::class, 'outlookForTicket'])->middleware('permission:maintenance.view');
    // Phase 2 — Supervisor (Dispatcher): review the open ticket, pick the garage + assign a driver. May
    // split-dispatch: route only a subset of faults now (fault_ids), leaving the rest Pending Assignment.
    Route::post('/{ticket}/assign-dispatch', 'assignDispatch')->middleware('permission:maintenance.delegate');
    // Split-dispatch Dispatch Queue — assign Pending-Assignment faults to the car's current garage later.
    Route::post('/{ticket}/assign-pending', 'assignPending')->middleware('permission:maintenance.delegate');
    // UC-3 / UC-4 / UC-5 — the Driver (maintenance.logistics). dispatch() = the physical pickup
    // (garage already chosen by the Supervisor); under-repair / ready relay the garage's progress.
    Route::post('/{ticket}/dispatch', 'dispatch')->middleware('permission:maintenance.logistics');
    // Recovery (towing) variant of dispatch — a broken-down car is towed in by a Recovery Truck (winch),
    // not driven by a driver. Same odometer/photo gate; captures the towing unit. Driver or supervisor.
    Route::post('/{ticket}/recovery-dispatch', 'recoveryDispatch')->middleware('permission:maintenance.logistics|maintenance.delegate');
    Route::post('/{ticket}/under-repair', 'underRepair')->middleware('permission:maintenance.logistics');
    Route::post('/{ticket}/findings', 'addFindings')->middleware('permission:maintenance.logistics'); // garage-identified, during repair
    // Follow-up status is MANAGEMENT authority (Waleed/Abdullah) — they chase the garage, not the driver.
    Route::post('/{ticket}/follow-up', 'followUp')->middleware('permission:maintenance.delegate');    // Supervisor's follow-up log
    Route::post('/{ticket}/ready', 'ready')->middleware('permission:maintenance.logistics');          // garage finished → ready for pickup (or supervisor's video review first)
    // UC-5a — Supervisor Video-Review gate (Waleed/Abdullah): after reviewing the garage's video they
    // APPROVE it for pickup, or REQUEST A RE-FIX to send the car back to the same garage.
    Route::post('/{ticket}/approve-repair', 'approveRepair')->middleware('permission:maintenance.delegate');
    Route::post('/{ticket}/request-refix', 'requestRefix')->middleware('permission:maintenance.delegate');
    // UC-5b / UC-5c — Ready for Pickup → In Our Park (Driver/Logistics authority). Both mandatory photo
    // checkpoints on the return leg: collect-from-garage records the pickup (no status change), then
    // arrive-at-park (mandatory photo, auto-branches by repair severity — minor auto-closes, major routes
    // to the final QA re-inspection).
    Route::post('/{ticket}/collect-from-garage', 'collectFromGarage')->middleware('permission:maintenance.logistics');
    Route::post('/{ticket}/arrive-at-park', 'arriveAtPark')->middleware('permission:maintenance.logistics');
    // Video Evidence — the garage's repair videos, uploaded by a supervisor as the permanent repair record.
    // Presign + save + delete are supervisor authority; anyone who can view the ticket can watch them.
    Route::post('/{ticket}/video', 'storeVideo')->middleware('permission:maintenance.delegate');
    Route::get('/{ticket}/media', 'listMedia')->middleware('permission:maintenance.view');
    Route::delete('/{ticket}/media/{media}', 'destroyMedia')->middleware('permission:maintenance.delegate');
    // Maintenance Checkpoint — workshop progress tracking. Route gate is view-level; the finer
    // submit/manage authority (permission OR assigned-responsible OR admin) is enforced in the controller.
    Route::get('/{ticket}/checkpoints', [MaintenanceCheckpointController::class, 'index'])->middleware('permission:maintenance.view');
    Route::post('/{ticket}/checkpoints', [MaintenanceCheckpointController::class, 'store'])->middleware('permission:maintenance.view');
    Route::delete('/{ticket}/checkpoints/{checkpoint}', [MaintenanceCheckpointController::class, 'destroy'])->middleware('permission:maintenance.view');
    // Set the promised completion (duration → derived date, or an explicit date) + the responsible users.
    Route::post('/{ticket}/expected-completion', [MaintenanceCheckpointController::class, 'setExpected'])->middleware('permission:maintenance.view');
    Route::put('/{ticket}/responsibles', [MaintenanceCheckpointController::class, 'setResponsibles'])->middleware('permission:maintenance.view');
    // "Where is the car?" location tracking is now CANONICAL on Logistics Dispatch (see the /logistics
    // ping + status routes above) — one channel for every movement. The duplicate per-ticket
    // reassign/ping/status endpoints were removed in favour of it.
    // Final re-inspection — the Inspector (maintenance.initiate) OR a Supervisor (maintenance.delegate,
    // e.g. Waleed/Abdullah) may re-check the car when it's back from the garage and sign off (close)
    // or send it back (reopen). Drivers can SEE a ready ticket in their queue to track that the car is
    // back, but the closing decision stays with the inspector or a supervisor.
    // On-Site (mobile) completion — the mobile work is done, but it does NOT close the ticket: it routes to
    // the final QA re-inspection (ready_for_reinspection), exactly like an in-shop repair, so the vehicle's
    // service data is confirmed only on a PASS. Same authority as a close (inspector or supervisor).
    Route::post('/{ticket}/mark-serviced', 'markServiced')->middleware('permission:maintenance.initiate|maintenance.delegate');
    Route::post('/{ticket}/close', 'close')->middleware('permission:maintenance.initiate|maintenance.delegate');
    // Pause Maintenance & Return to Service — pull a mid-repair car out for a customer, preserving the
    // ticket's full state; Resume continues from the exact stage. Pause is the controllers' call (the
    // rental-form "pull" decision); Resume may be a controller or a supervisor sending the car back in.
    Route::post('/{ticket}/pause', 'pause')->middleware('permission:maintenance.manage');
    // Vehicle Physically Returned — anyone plausibly handing the keys back in (controller, supervisor,
    // or the driver/logistics claim role) can flag it; the actual Resume handover stays gated above.
    Route::post('/{ticket}/mark-returned', 'markReturned')->middleware('permission:maintenance.manage|maintenance.delegate|logistics.claim');
    Route::post('/{ticket}/resume', 'resume')->middleware('permission:maintenance.manage|maintenance.delegate');
    // Temporary Vehicle Release — take the car OUT of the workshop mid-repair (road test / customer test /
    // external inspection / storage) WITHOUT pausing: the ticket stays open at its stage. Releasing it is
    // the controllers' call; bringing it back may also be done by a supervisor or the driver/claim role.
    Route::post('/{ticket}/temporary-release', 'temporarilyRelease')->middleware('permission:maintenance.manage');
    Route::post('/{ticket}/return-from-release', 'returnFromTemporaryRelease')->middleware('permission:maintenance.manage|maintenance.delegate|logistics.claim');
    // Acknowledge a flagged handover discrepancy (Incident) — clears the gate and finalizes the resume
    // that was held pending it. Controller authority only.
    Route::post('/{ticket}/incidents/{incident}/acknowledge', 'acknowledgeIncident')->middleware('permission:maintenance.manage');
    Route::post('/{ticket}/reopen', 'reopen')->middleware('permission:maintenance.initiate|maintenance.delegate');
    // Financial Decoupling (Deferred Cost) — record the final invoice cost later, after close.
    Route::post('/{ticket}/cost', 'recordCost')->middleware('permission:maintenance.manage');
    // Structured Parts + Labor breakdown — read with view; record/replace is a money action
    // (maintenance.manage), works in any state so the bill can be itemised after close (deferred edit).
    Route::get('/{ticket}/line-items', 'lineItems')->middleware('permission:maintenance.view');
    Route::put('/{ticket}/line-items', 'syncLineItems')->middleware('permission:maintenance.manage');
    // Path A (Manual Entry) — ask the garage for an itemised invoice (stamps + alerts the team).
    Route::post('/{ticket}/request-invoice', 'requestInvoice')->middleware('permission:maintenance.manage');
    // Garage Invoice Portal — the garages that worked on the ticket (each with its own faults + live link),
    // then issue a secure link the outside garage uses to self-submit its invoice (optionally per-garage).
    Route::get('/{ticket}/garage-invoice-garages', [GarageInvoiceController::class, 'garages'])->middleware('permission:maintenance.manage');
    Route::post('/{ticket}/garage-invoice-link', [GarageInvoiceController::class, 'issueLink'])->middleware('permission:maintenance.manage');
    // Mark the outstanding invoice as received → close the awaiting_invoice ticket (a money action).
    Route::post('/{ticket}/finalize-invoice', 'finalizeInvoice')->middleware('permission:maintenance.manage');
    // One Ticket → Many Invoices — list + create the individual garage bills on a ticket (each covering
    // only the faults its garage fixed). Edit/delete/reconcile live under the /maintenance-invoices group.
    Route::get('/{ticket}/invoices', [MaintenanceInvoiceController::class, 'index'])->middleware('permission:maintenance.view');
    Route::post('/{ticket}/invoices', [MaintenanceInvoiceController::class, 'store'])->middleware('permission:maintenance.manage');
    // Reclassify the maintenance type mid-lifecycle (e.g. Routine → Insurance Incident on discovery).
    Route::patch('/{ticket}/type', 'updateType')->middleware('permission:maintenance.manage');
    // Single-garage routing — the ticket's faults as first-class tasks (read), grouped under one garage.
    Route::get('/{ticket}/tasks', 'listTasks')->middleware('permission:maintenance.view');
    // Post-Repair Inspection — the durable QC verdicts recorded at sign-off (Repair Quality Check panel).
    // The verdicts themselves are WRITTEN as a layer on top of the existing close (PASS) / reopen (FAIL)
    // endpoints above — this is the read of that structured history.
    Route::get('/{ticket}/repair-inspections', 'repairInspections')->middleware('permission:maintenance.view');
    // Garage Transfer (whole car) — the Supervisor sequentially moves the car to the next garage: closes
    // the current stints, opens new ones, re-points the ticket's single current garage. All open faults
    // move together. The Supervisor's dispatch authority (maintenance.delegate), mirroring assign-dispatch.
    Route::post('/{ticket}/transfer-garage', 'transferGarage')->middleware('permission:maintenance.delegate');
});

// Inspector's Pad — the Inspector (Abu Maroof) flags cars with issue keywords + observations ahead of
// any ticket (maintenance.initiate to write, maintenance.view to read), and picks a car up for
// maintenance. Pick-up is odometer-gated and mints a ticket carrying the car's pending flags (see
// InspectorPadController + MaintenanceWorkflowService::pickupIntoMaintenance).
Route::middleware('auth:sanctum')->prefix('inspector-pad')->controller(InspectorPadController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:maintenance.view');
    Route::post('/', 'store')->middleware('permission:maintenance.initiate');
    // Static segment before {flag}/{vehicle} — pick a car up for maintenance (odometer-gated).
    Route::post('/pickup/{vehicle}', 'pickup')->middleware('permission:maintenance.initiate');
    Route::delete('/{flag}', 'destroy')->middleware('permission:maintenance.initiate');
});

// Complaints Center — the first-class Customer Complaint entity (App\Models\Complaint), with its own
// customer-support lifecycle INDEPENDENT of maintenance. Reads: the filterable management table (+ KPIs)
// and a single complaint's timeline. Mutations: intake (Ops) + triage actions (Inspector). A complaint
// only touches the workshop via `send-in`, which spawns a maintenance ticket. See [[complaint-entity]].
Route::middleware('auth:sanctum')->prefix('complaints')->controller(\App\Http\Controllers\ComplaintController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:maintenance.view');
    // Intake — Operations (Marwa & Leen) log a customer complaint → Inspector notified to triage.
    Route::post('/', 'store')->middleware('permission:maintenance.manage');
    Route::get('/{complaint}', 'show')->middleware('permission:maintenance.view');
    // Triage actions (Abu Maroof, maintenance.initiate): contact the customer, record the decision, send
    // the car in (spawns a maintenance ticket), resolve (no repair), or close.
    Route::post('/{complaint}/contact', 'contact')->middleware('permission:maintenance.initiate');
    Route::post('/{complaint}/decision', 'decide')->middleware('permission:maintenance.initiate');
    Route::post('/{complaint}/send-in', 'sendIn')->middleware('permission:maintenance.initiate');
    Route::post('/{complaint}/resolve', 'resolve')->middleware('permission:maintenance.initiate');
    Route::post('/{complaint}/close', 'close')->middleware('permission:maintenance.initiate');
});

// Driver Handover Observations — the lightweight internal-note path (driver noticed something on return).
// NOT a customer complaint: no contact, no escalation. May raise an inspection request → the review queue.
Route::middleware('auth:sanctum')->prefix('driver-observations')->controller(\App\Http\Controllers\DriverObservationController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:maintenance.view');
    Route::post('/', 'store')->middleware('permission:maintenance.logistics');
    Route::post('/{observation}/request-inspection', 'requestInspection')->middleware('permission:maintenance.logistics|maintenance.manage');
    Route::post('/{observation}/dismiss', 'dismiss')->middleware('permission:maintenance.manage');
});

// Per-fault status actions (independent fault tracking) — a fault is marked fixed / reopened / cancelled
// on its own, but it is NEVER routed to its own garage: every open fault belongs to the ticket's single
// current garage (see /{ticket}/assign-dispatch and /{ticket}/transfer-garage). Returns the parent ticket
// (with every task) so the board card refreshes whole. The Supervisor's dispatch authority.
Route::middleware(['auth:sanctum', 'permission:maintenance.delegate'])->prefix('maintenance-tasks')->controller(MaintenanceWorkflowController::class)->group(function () {
    Route::post('/{task}/status', 'setTaskStatus');  // pending / in_progress / completed / cancelled
    // In-Workshop only: the single "not a real fault" outcome — cancels the fault + stamps who/why (see markIncorrect).
    Route::post('/{task}/incorrect', 'markTaskIncorrect');
    // In-Workshop confirmation — `confirmed` is the only verdict; it opens a recurring-fault review
    // (the gate against false duplicate alerts). "Not a real fault" is the /incorrect path above.
    Route::post('/{task}/confirm', 'confirmTask');

    // Tier 1 repair capture — what was done, did it work, how do we know. The one place the fleet
    // records the repair itself; everything downstream (effectiveness, supplier quality, technician
    // accuracy, recommendation validation) is blocked without it. Same authority as the rest of the
    // workshop actions, because it is filled in by whoever is closing the fault out.
    Route::get('/{task}/capture', 'captureOptions');
    Route::post('/{task}/capture/start', 'captureStart');       // opens a friction session
    Route::post('/{task}/capture/abandon', 'captureAbandon');   // closes it unfinished
    Route::post('/{task}/capture', 'captureRepair');
});

// INDEPENDENT VERIFICATION — the inspector's separate act, deliberately behind a DIFFERENT
// permission from the repair capture above. The party performing a repair must never be the only
// party confirming it, so `inspections.manage` gates this and `maintenance.delegate` gates the
// capture. The permission split is necessary but NOT sufficient (the `maintenance` role holds both),
// so RepairVerificationService additionally blocks the claimant from verifying their own work.
Route::middleware(['auth:sanctum', 'permission:inspections.manage'])->prefix('maintenance-tasks')->controller(MaintenanceWorkflowController::class)->group(function () {
    Route::get('/{task}/verification', 'verificationOptions');
    Route::post('/{task}/verification', 'verifyRepair');
});

// Recurring-fault REPAIR GATE approval — a manager clears (or rejects) the repair of a fault that recurred
// within the window. Separate group so it needs the approval authority, not the workshop delegate role.
Route::middleware(['auth:sanctum', 'permission:maintenance.recurring.manage'])->prefix('maintenance-tasks')->controller(MaintenanceWorkflowController::class)->group(function () {
    Route::post('/{task}/repair-approval', 'repairApproval');
});

// Recurring Fault Reviews — the management inbox of confirmed faults that recurred after a completed
// repair. Cases open automatically at the workshop-confirmation step; here they are read + decided.
Route::middleware('auth:sanctum')->prefix('recurring-fault-reviews')->controller(RecurringFaultReviewController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:maintenance.recurring.view');
    // Declared before the {recurringFaultReview} bindings so "stats" is never read as a model key.
    Route::get('/stats', 'stats')->middleware('permission:maintenance.recurring.view');
    Route::post('/{recurringFaultReview}/decide', 'decide')->middleware('permission:maintenance.recurring.manage');
});

// Garage Invoice Portal — team-side AUDIT of a garage-submitted invoice (accept applies it to the ticket,
// reject discards it). A money action, so gated to the controllers who own the cost (maintenance.manage).
Route::middleware(['auth:sanctum', 'permission:maintenance.manage'])->prefix('garage-invoices')->controller(GarageInvoiceController::class)->group(function () {
    Route::post('/{submission}/accept', 'accept');
    Route::post('/{submission}/reject', 'reject');
});

// One Ticket → Many Invoices — edit / delete / reconcile a single garage bill. Money actions →
// maintenance.manage. Update is POST (multipart: it may carry a replacement receipt photo).
Route::middleware(['auth:sanctum', 'permission:maintenance.manage'])->prefix('maintenance-invoices')->controller(MaintenanceInvoiceController::class)->group(function () {
    Route::post('/{invoice}', 'update');
    Route::delete('/{invoice}', 'destroy');
    Route::post('/{invoice}/reconcile', 'reconcile');
});

// Garage Invoice Portal — PUBLIC, token-gated (no login): the garage opens its link and submits its
// invoice. Rate-limited; every response is scoped to the one ticket and carries only car + fault data.
Route::prefix('garage-invoice')->controller(GarageInvoiceController::class)->group(function () {
    Route::get('/{token}', 'show')->middleware('throttle:40,1');
    Route::post('/{token}', 'submit')->middleware('throttle:12,1');
});

// Symptom → Root-Cause knowledge base administration. The diagnostic picker auto-files custom causes
// as 'pending'; a manager reviews the queue here and approves (→ joins the master list) or rejects.
// Gated to maintenance.manage (the manager-override permission), like cost + type changes above.
Route::middleware(['auth:sanctum', 'permission:maintenance.manage'])->prefix('fault-causes')->controller(FaultCauseController::class)->group(function () {
    Route::get('/', 'index');                              // review queue + counts (?status=, ?symptom=)
    Route::post('/{faultCause}/approve', 'approve');       // promote a custom cause to the master list
    Route::post('/{faultCause}/reject', 'reject');         // decline (kept for audit)
});

// Findings keyword library — the quick-pick fault keywords + their RISK grade (critical / moderate /
// routine). Read with maintenance.view (anyone who works tickets sees the library); create / edit /
// delete are curation actions gated to maintenance.manage, matching cost / type / fault-cause admin.
Route::middleware('auth:sanctum')->prefix('finding-keywords')->controller(FindingKeywordController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:maintenance.view');                  // whole library + risk legend + counts

    // AI knowledge base — free text → fault concept. Read-level: everyone who works tickets may
    // ask "which fault is this describing?", the same bar as seeing the keyword library at all.
    // Declared before /{findingKeyword} so "resolve" is never swallowed as a model binding.
    Route::post('/resolve', 'resolve')->middleware('permission:maintenance.view');

    // Continuous learning: a human's verdict on a match. Read-level on purpose — the people best
    // placed to say "that's the wrong fault" are the inspectors and workshop staff using it, not
    // the admins who curate the library, and gating it to managers would starve the loop.
    Route::post('/match-feedback', 'matchFeedback')->middleware('permission:maintenance.view');

    Route::post('/', 'store')->middleware('permission:maintenance.initiate');             // add a keyword (inspectors contribute)
    Route::get('/{findingKeyword}', 'show')->middleware('permission:maintenance.view');   // full concept: terms + profile + run log
    Route::post('/{findingKeyword}', 'update')->middleware('permission:maintenance.manage');  // edit / re-grade risk (POST, like Vendor)
    Route::delete('/{findingKeyword}', 'destroy')->middleware('permission:maintenance.manage'); // retire a keyword

    // Curating the ontology itself — generating it, and correcting what was generated. Both are
    // library curation, so both sit at maintenance.manage alongside re-grading a keyword's risk.
    Route::post('/{findingKeyword}/enrich', 'enrich')->middleware('permission:maintenance.manage');
    Route::post('/{findingKeyword}/terms', 'storeTerm')->middleware('permission:maintenance.manage');
    Route::post('/{findingKeyword}/terms/{term}', 'updateTerm')->middleware('permission:maintenance.manage');
    Route::delete('/{findingKeyword}/terms/{term}', 'destroyTerm')->middleware('permission:maintenance.manage');
});

// WHERE ON THE CAR — curation of the location vocabulary the fault picker offers, the sections it is
// grouped into, and the per-type policy that decides whether a fault must name a place at all. Reads
// at maintenance.view (anyone filing a fault may read the vocabulary they file into); every write is
// catalog curation and sits at maintenance.manage, like the keyword library above.
//
// Ordering matters: the literal segments (`groups`, `reorder`, `policy`, `settings`) are declared
// BEFORE `/{vehicleLocation}` so none of them is swallowed as a model binding.
Route::middleware('auth:sanctum')->prefix('vehicle-locations')->controller(VehicleLocationController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:maintenance.view');   // places + sections + policy + rails

    // Sections (the collapsible headings in the picker).
    Route::post('/groups', 'storeGroup')->middleware('permission:maintenance.manage');
    Route::post('/groups/reorder', 'reorderGroups')->middleware('permission:maintenance.manage');
    Route::post('/groups/{group}', 'updateGroup')->middleware('permission:maintenance.manage');
    Route::delete('/groups/{group}', 'destroyGroup')->middleware('permission:maintenance.manage');

    // "Does this fault type even HAVE a where?" — required | optional | none, per fault/damage type.
    Route::post('/policy', 'updatePolicy')->middleware('permission:maintenance.manage');
    Route::post('/policy/reset', 'resetPolicy')->middleware('permission:maintenance.manage');

    // The quantity rail (max "how many" on one fault row).
    Route::post('/settings', 'updateSettings')->middleware('permission:maintenance.manage');

    // Places.
    Route::post('/reorder', 'reorder')->middleware('permission:maintenance.manage');
    Route::post('/', 'store')->middleware('permission:maintenance.manage');
    Route::post('/{vehicleLocation}', 'update')->middleware('permission:maintenance.manage');           // POST, like Vendor
    Route::post('/{vehicleLocation}/toggle', 'toggle')->middleware('permission:maintenance.manage');    // retire / restore
    Route::delete('/{vehicleLocation}', 'destroy')->middleware('permission:maintenance.manage');        // unused places only
});

// Booking Readiness — the pickup-prep board. Lists upcoming bookings (type-R reservations) inside the
// look-ahead horizon, each with a live readiness checklist + working-days-to-pickup + verdict. Reads
// are gated to booking_readiness.view; the trigger settings (horizon / lead / inspection validity /
// excluded holidays) are edited under booking_readiness.manage.
Route::middleware('auth:sanctum')->prefix('booking-readiness')->controller(BookingReadinessController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:booking_readiness.view');                  // board: bookings + summary + settings
    Route::get('/settings', 'settings')->middleware('permission:booking_readiness.view');       // settings on their own
    Route::post('/settings', 'updateSettings')->middleware('permission:booking_readiness.manage'); // save trigger settings
});

// In-app notification centre (the bell). Every endpoint is scoped to the current
// user, so it only requires authentication — no extra permission.
Route::middleware('auth:sanctum')->prefix('notifications')->controller(NotificationController::class)->group(function () {
    Route::get('/', 'index');                  // paginated history (?filter=all|unread&page=N)
    Route::get('/poll', 'poll');               // lightweight realtime poll (badge + latest)
    Route::post('/read-all', 'markAllRead');   // mark everything read
    Route::post('/clear', 'clear');            // delete the whole history
    Route::post('/demo', 'demo');              // send myself a sample alert (test the pipeline)
    Route::post('/{id}/read', 'markRead');     // mark one read
    Route::delete('/{id}', 'destroy');         // dismiss one
});

// Demo / Simulation Panel (ADMIN-ONLY) — force a real live scenario on a real car so the team can
// watch the system react end-to-end: `oil-alert` forces a Service-Due oil condition + runs the real
// scanner; `fault-discovery` files a real complaint ticket. `status` is read-only; the mutating
// actions ALSO hard-refuse unless FEATURE_DEMO_MODE is on (SimulationController::assertDemoMode), and
// every change is journaled so `reset` restores the fleet exactly. Gated to users.manage (hidden).
Route::middleware(['auth:sanctum', 'permission:users.manage'])->prefix('simulation')->controller(SimulationController::class)->group(function () {
    Route::get('/status', 'status');
    Route::post('/oil-alert', 'oilAlert');
    Route::post('/fault-discovery', 'faultDiscovery');
    Route::post('/reset', 'reset');
});

// Vehicle Inspection Workflow — condition photos (stored in S3-compatible object storage)
// + rich manual damage flags (type/severity/note) per body zone, linked to a contract and
// vehicle. Powers the hotspot diagram, the before/after slider and the Fleet Health reports.
// Local upload: client compresses → POST multipart to /Inspections (photo stored on the local disk).
Route::middleware('auth:sanctum')->prefix('Inspections')->controller(InspectionController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:inspections.view');                 // list by ?contract_id= / ?vehicle_id=
    Route::post('/', 'store')->middleware('permission:inspections.manage');              // upload photo (multipart) + persist record
    Route::delete('/{inspection}', 'destroy')->middleware('permission:inspections.manage');
});

// Inspection Intelligence Center — read-only Mission Control over the AUTOMATIC inspection engine
// (the Proactive Diagnostic Monitor, inspections:generate-tasks). Health KPIs, the live trigger queue,
// the rule catalog + tallies, today's timeline, skipped-vehicle reasons, and the engine log — all
// derived live from DiagnosticGateService + the periodic maintenances/audit rows. Pure reads → gated to
// the oversight/maintenance viewer roles.
Route::middleware(['auth:sanctum', 'permission:insights.view|maintenance.view|maintenance.manage|inspections.view'])
    ->prefix('InspectionEngine')
    ->controller(\App\Http\Controllers\InspectionEngineController::class)
    ->group(function () {
        Route::get('/monitor', 'monitor');
        Route::get('/idle-watch', 'idleWatch');   // ?min_days=15 — cars sitting idle (no rental / unused) for ≥ N days
    });

// Inspection Schedules — recurring SAFETY / OPERATIONS inspection plans (Fleetio "Schedules").
// Separate prefix from `Inspections` so the {inspection} wildcard above never swallows these.
// `complete` (static) is registered before the {inspectionSchedule} wildcard. Reuses the
// inspections.* permissions (schedules + history are the inspection pillar).
Route::middleware('auth:sanctum')->prefix('InspectionSchedules')->controller(InspectionScheduleController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:inspections.view');                       // ?vehicle_id / ?status / ?active
    Route::post('/', 'store')->middleware('permission:inspections.manage');
    Route::get('/{inspectionSchedule}', 'show')->middleware('permission:inspections.view');
    Route::post('/{inspectionSchedule}/complete', 'complete')->middleware('permission:inspections.manage'); // log a done inspection, roll next-due
    Route::post('/{inspectionSchedule}', 'update')->middleware('permission:inspections.manage');
    Route::delete('/{inspectionSchedule}', 'destroy')->middleware('permission:inspections.manage');
});

// Service Reminders — recurring TECHNICAL maintenance due points (oil/filters/…). Auto rows
// are seeded by `service:sync-reminders`; a manual edit protects a row from the seeder. Gated
// by the Reminders section permission set.
Route::middleware('auth:sanctum')->prefix('ServiceReminders')->controller(ServiceReminderController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:reminders.view');                         // ?vehicle_id / ?source / ?status / ?active
    Route::post('/', 'store')->middleware('permission:reminders.manage');
    // Literal path — MUST stay above /{serviceReminder} or the binding swallows it.
    Route::get('/due-by-vehicle', 'dueByVehicle')->middleware('permission:reminders.view');  // dashboard: cars needing a check, folded per car
    Route::get('/{serviceReminder}', 'show')->middleware('permission:reminders.view');
    Route::post('/{serviceReminder}/complete', 'complete')->middleware('permission:reminders.manage'); // log the service, roll next-due
    Route::post('/{serviceReminder}/notify', 'notify')->middleware('permission:reminders.manage');     // alert drivers/technicians this car needs service
    Route::post('/{serviceReminder}', 'update')->middleware('permission:reminders.manage');
    Route::delete('/{serviceReminder}', 'destroy')->middleware('permission:reminders.manage');
});

// Contact Reminders — "call {vendor} about {subject}", optionally linked to an invoice /
// maintenance ticket for one-click drill-through. Gated by the Reminders section permission set.
Route::middleware('auth:sanctum')->prefix('ContactReminders')->controller(ContactReminderController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:reminders.view');                         // ?vendor_id / ?status / ?assigned_to
    Route::post('/', 'store')->middleware('permission:reminders.manage');
    Route::get('/{contactReminder}', 'show')->middleware('permission:reminders.view');
    Route::post('/{contactReminder}', 'update')->middleware('permission:reminders.manage');
    Route::delete('/{contactReminder}', 'destroy')->middleware('permission:reminders.manage');
});

// Fleet insights
Route::middleware('auth:sanctum')->controller(FleetController::class)->group(function () {
    Route::get('Fleet/expiring', 'expiring')->middleware('permission:dashboard.view');               // cars with registration/insurance expiring within ?days=N
});

// Sync Audit — read-only history of CMD sync runs + the auto-corrections they made.
// Static `Sync/audit` is registered before `Sync/audit/{syncRun}` so the {syncRun}
// binding resolves to a SyncRun model.
Route::middleware(['auth:sanctum', 'permission:sync.run'])->controller(SyncAuditController::class)->group(function () {
    Route::get('Sync/audit', 'index');
    Route::get('Sync/audit/{syncRun}', 'show');
});

// Maintenance/foresight + Maintenance/issue-history were retired with the /maintenance-foresight page.
// MaintenanceForesightService still runs behind the Swap board and the Ops Center; the per-car "keeps
// breaking down" evidence moved to Vehicle/{vehicle}/repeat-faults.
// Maintenance cost intelligence (service averages + vendor price comparison)
Route::middleware(['auth:sanctum', 'permission:maintenance.view'])->get('Maintenance/analytics', [MaintenanceController::class, 'analytics']);
// Recurring faults: cars repeatedly in for the same issue (scenario step 8)
Route::middleware(['auth:sanctum', 'permission:maintenance.view'])->get('Maintenance/recurring', [MaintenanceController::class, 'recurring']);
// Damage & accident log: every damage/accident record, colour-coded by stated fault
Route::middleware(['auth:sanctum', 'permission:maintenance.view'])->get('Maintenance/incidents', [MaintenanceController::class, 'incidents']);
// Bill approvals: jobs over the threshold awaiting manual approval
Route::middleware(['auth:sanctum', 'permission:maintenance.approve'])->get('Maintenance/approvals', [MaintenanceController::class, 'approvals']);
Route::middleware(['auth:sanctum', 'permission:maintenance.approve'])->post('Maintenance/{contract}/approve', [MaintenanceController::class, 'approve']);
// Live maintenance board: cars currently in the garage + traffic-light SLA status
Route::middleware(['auth:sanctum', 'permission:maintenance.view'])->get('Maintenance/board', [MaintenanceController::class, 'board']);
// Booking conflicts: cars in the workshop that also have an upcoming booking (/maintenance-bookings)
Route::middleware(['auth:sanctum', 'permission:maintenance.view'])->get('Maintenance/booking-conflicts', [MaintenanceController::class, 'bookingConflicts']);
// Controlled reason -> status vocabulary (for the issue-tag picker / classification)
Route::middleware(['auth:sanctum', 'permission:maintenance.view'])->get('Maintenance/reasons', [MaintenanceController::class, 'reasons']);
// Garage directory + performance (delays, on-time rate, spend, cars in now)
Route::middleware(['auth:sanctum', 'permission:maintenance.view'])->get('Maintenance/garages', [MaintenanceController::class, 'garages']);
// Garage scorecards — score, per-domain strengths and problems, and the leaderboard per repair area.
// Split from the directory above because it reads the whole repair corpus and is cached; the
// directory is live state and must stay fast. See GarageScorecardController.
Route::middleware(['auth:sanctum', 'permission:maintenance.view'])->get('Maintenance/garage-scorecards', [\App\Http\Controllers\GarageScorecardController::class, 'index']);

// ── FLEET INTELLIGENCE ──────────────────────────────────────────────────────────────────────────
//
// The evidence drawer: any figure the platform publishes, opened up to the repairs behind it. ONE
// endpoint for every metric — a card emits the evidence id for its own number and the drawer asks
// here, so the frontend never needs to know how a particular figure was computed.
//
// This platform grades suppliers. The first time a score goes against a garage somebody will dispute
// it, and the only acceptable answer is the repairs themselves, on screen.
Route::middleware(['auth:sanctum', 'permission:intelligence.view'])
    ->prefix('intelligence')
    ->group(function () {
        Route::get('evidence/{queryId}', [\App\Http\Controllers\Intelligence\EvidenceController::class, 'show'])
            ->where('queryId', '[A-Za-z0-9_.:-]+');

        // Garage Intelligence. `compare` is declared BEFORE `{vendor}` — a static segment behind a
        // wildcard resolves to the wildcard, and the bug is silent: /garages/compare would arrive as
        // a profile lookup for garage "compare".
        Route::get('garages/compare', [\App\Http\Controllers\Intelligence\GarageIntelligenceController::class, 'compare']);
        Route::get('garages/{vendor}', [\App\Http\Controllers\Intelligence\GarageIntelligenceController::class, 'show'])
            ->whereNumber('vendor');

        // Executive Home (Basem). Whole page in one payload so every panel shares one `as_of`.
        Route::get('executive', [\App\Http\Controllers\Intelligence\ExecutiveDashboardController::class, 'show']);
    });

// Workshop events CRUD — the dashboard owning the garage log (origin = 'manual'); the
// Google-Sheet import is now an optional, non-destructive sync. Reads return synced +
// hand-entered events; writes only touch hand-entered ones. {workshopEvent} binds a
// `maintenances` row by id. Registered before any `Maintenance/{x}` pattern so 'events'
// is never swallowed as a wildcard.
Route::middleware('auth:sanctum')->prefix('Maintenance/events')->controller(WorkshopEventController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:maintenance.view');                 // ?vehicle_id=
    Route::post('/', 'store')->middleware('permission:maintenance.manage');
    // Restore a tombstoned (deleted) sheet event — static segment, before the {workshopEvent} wildcard.
    Route::post('/tombstones/{tombstone}/restore', 'restore')->middleware('permission:maintenance.manage');
    Route::get('/{workshopEvent}', 'show')->middleware('permission:maintenance.view');
    Route::post('/{workshopEvent}', 'update')->middleware('permission:maintenance.manage');
    // DELETE a hand-entered event removes it; a sheet-synced event is tombstoned (hidden + skipped on sync).
    Route::delete('/{workshopEvent}', 'destroy')->middleware('permission:maintenance.manage');
});

// Quick Cost Input — recent repairs missing a cost + one-tap cost entry that re-computes the
// vehicle's Real-Net-Profit yield (closes the understated-spend gap behind Negative Yield).
// Static prefix, registered before any `Maintenance/{x}` wildcard.
Route::middleware('auth:sanctum')->prefix('Maintenance/cost-capture')->controller(CostCaptureController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:maintenance.view');
    Route::post('/{workshopEvent}', 'store')->middleware('permission:maintenance.manage');
});

// Workflow Oversight — the read-only accountability & data-integrity layer over the Maintenance
// Workflow: mileage discrepancies (odometer entered off from expected), per-stage accountability
// (who owned each stage + the mileage they recorded), the left-the-garage invoice-chase queue, and
// the fault-severity grade review (under-graded tickets). Pure reads → insights.view.
Route::middleware(['auth:sanctum', 'permission:insights.view'])->prefix('Oversight')->controller(\App\Http\Controllers\WorkflowOversightController::class)->group(function () {
    Route::get('/overview', 'overview');
    Route::get('/mileage-discrepancies', 'mileageDiscrepancies');
    Route::get('/stage-accountability', 'stageAccountability');
    Route::get('/left-garage', 'leftGarage');
    Route::get('/severity-review', 'severityReview');
    Route::get('/misdiagnoses', 'misdiagnoses'); // faults the inspector called that a supervisor overruled
    Route::get('/resolved-transfers', 'resolvedTransfers'); // car moved to another garage with all faults already fixed
    Route::get('/checkpoint-compliance', 'checkpointCompliance'); // supervisor reminded a car is due back, never answered
});

// Severity Review write action — a supervisor's Quality-Control decision on an under-graded ticket
// (upgrade the grade, or keep it and dismiss the recommendation). A grading change, so it needs the
// stronger maintenance.manage permission, not the read-only insights.view of the surface above.
Route::middleware(['auth:sanctum', 'permission:maintenance.manage'])->prefix('Oversight')
    ->controller(\App\Http\Controllers\WorkflowOversightController::class)->group(function () {
        Route::post('/severity-review/{ticket}/decide', 'decide');
    });

// Car Status — the enterprise "what is happening in my workshop right now?" command center: a KPI strip,
// one live row per vehicle inside the maintenance workflow, the six manager sections (repeat repairs,
// overdue, waiting-for-parts, waiting-for-approval, high-severity, recently-finished), and a per-vehicle
// Maintenance Intelligence Center. Pure reads over existing data → maintenance.view.
Route::middleware(['auth:sanctum', 'permission:maintenance.view'])->prefix('car-status')->controller(\App\Http\Controllers\CarStatusController::class)->group(function () {
    Route::get('/', 'dashboard');
    Route::get('/vehicle/{vehicle}', 'vehicle');
});

// Event Type layer — Classification Review queue: the human-in-the-loop for maintenance events the resolver
// was unsure about (needs_review). Confirm/correct kind (fault/service/inspection) → the classifier improves.
Route::middleware(['auth:sanctum', 'permission:maintenance.manage'])->prefix('event-classification')->controller(\App\Http\Controllers\EventClassificationReviewController::class)->group(function () {
    Route::get('/review', 'index');
    Route::post('/review/{task}/confirm', 'confirm');
});

// Garage Recommendation — the DATA-DRIVEN "which garage should this vehicle go to?" engine, learned from
// maintenance history (the complement to the rules-based Smart Routing). Free-form query by model/brand/
// fault; the ticket-scoped variant lives in the maintenance-tickets group (assign step). Pure reads.
Route::middleware(['auth:sanctum', 'permission:maintenance.view'])->prefix('garage-recommendations')->controller(\App\Http\Controllers\GarageRecommendationController::class)->group(function () {
    Route::get('/', 'index');
});

// Fleet Knowledge Engine (P0) — "Previous Similar Repairs + Recommendation Explanation". Read-only
// intelligence over maintenance history: tiered similar repairs (vehicle→model→make→fleet) + a composed
// recommendation (likely cause, best garage, expected parts/cost/duration, recurrence risk) with an honest
// confidence + the evidence/why behind it. No new tables. `preview` works before a fault row exists.
Route::middleware(['auth:sanctum', 'permission:maintenance.view'])->controller(\App\Http\Controllers\RepairIntelligenceController::class)->group(function () {
    Route::get('/maintenance-tasks/{task}/repair-intelligence', 'forTask');
    Route::post('/repair-intelligence/preview', 'preview');
});

// Maintenance Operations — the operational control center for every vehicle CURRENTLY IN THE WORKSHOP:
// a KPI summary + one rich card per in-shop vehicle (ownership, faults, progress, blockers, next action)
// and a per-vehicle detail (full checkpoint + workflow timeline). Pure reads → maintenance.view.
Route::middleware(['auth:sanctum', 'permission:maintenance.view'])->prefix('maintenance-operations')->controller(\App\Http\Controllers\MaintenanceOperationsController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/vehicle/{vehicle}', 'vehicle');
});

// Data health: incomplete/broken records (cars without VIN/mileage, contracts without a car, …)
Route::middleware(['auth:sanctum', 'permission:insights.view'])->get('DataHealth', [DataHealthController::class, 'index']);

// Fleet-wide operational profitability per car: rental income (type-R, ex-VAT) − maintenance cost
Route::middleware(['auth:sanctum', 'permission:insights.view'])->get('Profitability', [ProfitabilityController::class, 'index']);

// Intelligence Center — the platform's own operating state: evidence readiness, capability health,
// promotion history, QC throughput, feature flags, background-job health and data-quality problems.
// This was reachable only through `php artisan intelligence:evidence-health`, which was defensible
// while the audience was one engineer and stopped being defensible the moment the platform started
// making claims inside a supervisor's workflow.
//
// `evaluate` runs the promotion gate. It is DRY-RUN unless persist=1, and appending to an immutable
// decision history is a manage-level act, not a viewing one — hence the stricter permission.
Route::middleware(['auth:sanctum'])->prefix('intelligence/center')
    ->controller(\App\Http\Controllers\IntelligenceCenterController::class)->group(function () {
        Route::get('/', 'show')->middleware('permission:insights.view');
        Route::post('/evaluate', 'evaluate')->middleware('permission:maintenance.manage');
    });

// Fleet Intelligence (Phase 1) — read-only analytics surfaces. Pure reads → insights.view.
// Static prefix, no wildcards. Frontend visibility is gated by SHOW_FLEET_INTELLIGENCE.
Route::middleware(['auth:sanctum', 'permission:insights.view'])->prefix('intelligence')->controller(\App\Http\Controllers\IntelligenceController::class)->group(function () {
    Route::get('/cost', 'cost');                 // maintenance cost per km / day / rental, per vehicle + fleet
    // Is the garage recommendation actually being taken, and where is it overruled? Returns the
    // engine-judgeable acceptance rate SEPARATELY from the raw one — see the controller note.
    Route::get('/recommendation-learning', 'recommendationLearning');
    Route::get('/service-due', 'serviceDue');    // overdue + due-soon service board (reuses forecast engine)
    Route::get('/maintenance-ops', 'maintenanceOps');                    // Maintenance Operations Center board (priority score + recommended action)
    Route::get('/vehicle/{vehicle}/maintenance-detail', 'maintenanceOpsVehicle'); // one-car maintenance context drawer
    // Financial drill-down for one car — every Profitability number traced to its source rows.
    Route::get('/vehicle/{vehicle}/financial-breakdown', 'financialBreakdown');
    // Recursive explanation tree — every figure drillable to the original record (formula + reconciliation).
    Route::get('/vehicle/{vehicle}/financial-explain', 'financialExplain');
    // Explainability platform — full multi-module DAG (finance + service): formula, dependencies,
    // reverse dependencies, business rules, evidence, confidence, audit, snapshot (?as_of=&modules=).
    Route::get('/vehicle/{vehicle}/explain', 'explain');
});

// Service-Due snooze — a manager's "not now" on the Maintenance Operations Center. A write (hides the
// row, never mutates service data), so it's gated by a maintenance-action permission, not insights.view.
Route::middleware(['auth:sanctum', 'permission:maintenance.initiate|maintenance.manage'])
    ->controller(\App\Http\Controllers\ServiceDueSnoozeController::class)->group(function () {
        Route::post('service-due/snooze', 'store');
        Route::delete('service-due/{vehicle}/snooze', 'release');
    });

// Fuel & Mileage Reconciliation (live "fuel_data_plate_summary"): per car, actual odometer travel vs.
// km explained by contracts → out-of-contract (unlogged) km leakage, total fuel debit, odometer-rollback
// flags. FuelMileage/{vehicle} drills into the per-contract ledger with the off-contract gap per leg.
Route::middleware(['auth:sanctum', 'permission:insights.view'])->get('FuelMileage', [FuelMileageController::class, 'index']);
Route::middleware(['auth:sanctum', 'permission:insights.view'])->get('FuelMileage/{vehicle}', [FuelMileageController::class, 'vehicle']);

// Financial conflicts: broken invoices only — VAT math errors, invoice↔contract mismatches,
// overlapping/double billing. The accounting clean-up hub.
Route::middleware(['auth:sanctum', 'permission:insights.view'])->get('FinancialConflicts', [FinancialConflictController::class, 'index']);

// Financial Reconciliation (read-only MVP): bridge ONE contract to the official accounting system —
// Fleet ledger vs. real cash collected (/reports/balance) vs. booked vouchers (/accounts/vouchers),
// with a tolerance-aware verdict. Resolve by ?contract_no= or ?contract_id=. Hits the live API.
Route::middleware(['auth:sanctum', 'permission:insights.view'])->get('Reconciliation', [ReconciliationController::class, 'show']);
// Fleet-wide Net Profit for one month (cash basis): Σ net collected on rentals − Σ maintenance cost,
// computed from synced data (no live API hammering). ?month= & ?year= default to the current month.
Route::middleware(['auth:sanctum', 'permission:insights.view'])->get('Reconciliation/fleet', [ReconciliationController::class, 'fleet']);

// Vehicle status vs. contract reality mismatches (status out of step with open contracts)
Route::middleware(['auth:sanctum', 'permission:insights.view'])->get('StatusMismatch', [StatusMismatchController::class, 'index']);

// Mileage Chain Audit — per-vehicle contract-to-contract odometer handoff (match/mismatch/missing)
// + a non-destructive "Quick Fix" override on a single mis-typed reading. The read is gated by
// insights.view; the override writes by contracts.manage (they correct contract mileage data).
// {contract} model-binds to a Contract.
Route::middleware('auth:sanctum')->controller(MileageChainAuditController::class)->group(function () {
    Route::get('MileageChain/audit', 'index')->middleware('permission:insights.view');
    Route::post('MileageChain/audit/{contract}/override', 'storeOverride')->middleware('permission:contracts.manage');
    Route::delete('MileageChain/audit/{contract}/override', 'destroyOverride')->middleware('permission:contracts.manage');
});

// Odometer Modification Approval — significant manual odometer edits (raised by VehicleController::
// update) land here for admin review instead of applying silently. {odometerRequest} model-binds to
// an OdometerChangeRequest. Gated by vehicles.approve_odometer (admin-only review queue).
Route::middleware('auth:sanctum')->controller(OdometerChangeRequestController::class)->group(function () {
    Route::get('OdometerChangeRequests', 'index')->middleware('permission:vehicles.approve_odometer');
    Route::get('OdometerChangeRequests/stage-readings', 'stageReadings')->middleware('permission:vehicles.approve_odometer');
    Route::post('OdometerChangeRequests/{odometerRequest}/approve', 'approve')->middleware('permission:vehicles.approve_odometer');
    Route::post('OdometerChangeRequests/{odometerRequest}/reject', 'reject')->middleware('permission:vehicles.approve_odometer');
});

// Dashboard KPIs
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard', [DashboardController::class, 'summary']);
// Overdue rentals: open rentals past their estimated return date
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard/overdue-rentals', [DashboardController::class, 'overdueRentals']);
// Overdue maintenance: cars stuck in the garage past their expected return date
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard/overdue-maintenance', [DashboardController::class, 'overdueMaintenance']);
// Month-by-month maintenance trends (cost bar + downtime line) for the dashboard charts
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard/trends', [DashboardController::class, 'trends']);
// Fleet Pulse: per-car live status grid with maintenance-completion %
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard/fleet-pulse', [DashboardController::class, 'fleetPulse']);
// Proactive Flags: rentals expiring within ?days=N, concluded rentals with unpaid balance, inspections due
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard/proactive-flags', [DashboardController::class, 'proactiveFlags']);
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard/most-maintained', [DashboardController::class, 'mostMaintained']);
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard/most-maintained-models', [DashboardController::class, 'mostMaintainedModels']);
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard/most-maintained-cars', [DashboardController::class, 'mostMaintainedCars']);
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard/top-faults', [DashboardController::class, 'topFaults']);
// Maintenance Progress: the workshop monitoring centre (cars in maintenance + checkpoint status + ETA)
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard/maintenance-progress', [DashboardController::class, 'maintenanceProgress']);
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard/fault-cars', [DashboardController::class, 'faultCars']);
// Damage dashboard — externally-caused damage, the events deliberately excluded from every fault figure.
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard/damage', [DashboardController::class, 'damage']);
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard/maintenance-history', [DashboardController::class, 'maintenanceHistory']);
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard/maintenance-history/{vehicle}/visits', [DashboardController::class, 'maintenanceHistoryVisits']);

// Trip Dashboard: aggregated pickup/drop-off trip log (Main Trip Dashboard sheet) for the
// Delivery Command dashboard + Orders board. Cached read; ?refresh forces a re-read.
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('TripDashboard', [\App\Http\Controllers\TripDashboardController::class, 'index']);
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('TripDashboard/refresh', [\App\Http\Controllers\TripDashboardController::class, 'refresh']);

Route::middleware('auth:sanctum')->prefix('Registration')->controller(VehicleRegistrationController::class)->group(function () {

    Route::get('/', 'index')->middleware('permission:registration.view');
    Route::get('/coverage', 'coverage')->middleware('permission:registration.view');   // every car + its registration/insurance status (vehicle-anchored)
    Route::get('/{registration}', 'show')->middleware('permission:registration.view');
    Route::post('/', 'store')->middleware('permission:registration.manage');
    Route::post('/{registration}', 'update')->middleware('permission:registration.manage');
    Route::delete('/{registration}', 'destroy')->middleware('permission:registration.manage');
});

// Manual-to-Odoo bridge (read-only, downstream). Assembles the finalised Parts + Labor data into an
// Odoo-ready payload for review + manual push — nothing is synced automatically. Gated to the money
// owners (maintenance.manage). Standalone controller so the bridge is independent of the workflow
// state machine. See docs/maintenance-financial-architecture.md.
Route::middleware(['auth:sanctum', 'permission:maintenance.manage'])->prefix('odoo-export')->group(function () {
    Route::get('/ticket/{ticket}', [\App\Http\Controllers\OdooExportController::class, 'ticket']);
    Route::get('/contract/{contract}', [\App\Http\Controllers\OdooExportController::class, 'contract']);
});

// Concept Bridge Review — the human benchmark that decides whether legacy maintenance text may be
// enriched with ontology concepts. A reviewer answers what a real ticket segment says BEFORE the
// matcher's prediction is revealed (the UI enforces that order); human labels are the benchmark and
// the AI baseline is scored separately, never blended. See docs/gold-set/.
Route::middleware(['auth:sanctum', 'permission:maintenance.manage'])
    ->prefix('concept-bridge')
    ->controller(\App\Http\Controllers\ConceptBridgeReviewController::class)
    ->group(function () {
        Route::get('/review', 'index');
        Route::post('/review/{sample}', 'store');
        Route::get('/results', 'results');
    });
