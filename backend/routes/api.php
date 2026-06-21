<?php

use App\Http\Controllers\AnomalyController;
use App\Http\Controllers\DataHealthController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\ExchangeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\FleetController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\MaintenanceReturnController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OperationController;
use App\Http\Controllers\StatusMismatchController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\SyncAuditController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VehicleCsvSyncController;
use App\Http\Controllers\VehicleRegistrationController;
use App\Http\Controllers\VendorController;
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
    Route::post('signup',  'signup');
    Route::post('login',  'login');
    Route::post('logout',  'logout');
});
Route::middleware('auth:sanctum')->prefix('Vehicle')->controller(VehicleController::class)->group(function () {

    Route::get('/', 'index')->middleware('permission:vehicles.view');
    Route::get('/{vehicle}/profile', 'profile')->middleware('permission:vehicles.view');   // full car profile: registration, insurance, fines, contracts
    Route::get('/{vehicle}', 'show')->middleware('permission:vehicles.view');
    Route::post('/', 'store')->middleware('permission:vehicles.manage');
    Route::post('/{vehicle}', 'update')->middleware('permission:vehicles.manage');
    Route::delete('/{vehicle}', 'destroy')->middleware('permission:vehicles.manage');
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
Route::middleware('auth:sanctum')->prefix('Contract')->controller(ContractController::class)->group(function () {

    Route::get('/', 'index')->middleware('permission:contracts.view');
    Route::get('/next-no', 'nextNo')->middleware('permission:contracts.manage');   // auto contract number for the New form
    Route::get('/{contract}', 'show')->middleware('permission:contracts.view');
    Route::post('/', 'store')->middleware('permission:contracts.manage');
    Route::post('/{contract}', 'update')->middleware('permission:contracts.manage');
    Route::delete('/{contract}', 'destroy')->middleware('permission:contracts.manage');
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

// Fleet insights
Route::middleware('auth:sanctum')->controller(FleetController::class)->group(function () {
    Route::get('Fleet/expiring', 'expiring')->middleware('permission:dashboard.view');               // cars with registration/insurance expiring within ?days=N
});

// Manual data sync (the buttons on the Data Sync page)
Route::middleware('auth:sanctum')->controller(SyncController::class)->group(function () {
    Route::get('Sync/status', 'status')->middleware('permission:sync.run');
    Route::post('Sync/run', 'run')->middleware('permission:sync.run');
    Route::post('Sync/kill', 'kill')->middleware('permission:sync.run');   // stop a stuck background job by PID
    Route::get('Sync/preview', 'preview')->middleware('permission:sync.run'); // live raw API sample (cars/contracts/invoices)
});

// Sync Audit — read-only history of CMD sync runs + the auto-corrections they made.
// Static `Sync/audit` is registered before `Sync/audit/{syncRun}`; both sit outside the
// SyncController group above so the {syncRun} binding resolves to a SyncRun model.
Route::middleware(['auth:sanctum', 'permission:sync.run'])->controller(SyncAuditController::class)->group(function () {
    Route::get('Sync/audit', 'index');
    Route::get('Sync/audit/{syncRun}', 'show');
});

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
// Controlled reason -> status vocabulary (for the issue-tag picker / classification)
Route::middleware(['auth:sanctum', 'permission:maintenance.view'])->get('Maintenance/reasons', [MaintenanceController::class, 'reasons']);
// Garage directory + performance (delays, on-time rate, spend, cars in now)
Route::middleware(['auth:sanctum', 'permission:maintenance.view'])->get('Maintenance/garages', [MaintenanceController::class, 'garages']);

// Workshop events CRUD — the dashboard owning the garage log (origin = 'manual'); the
// Google-Sheet import is now an optional, non-destructive sync. Reads return synced +
// hand-entered events; writes only touch hand-entered ones. {workshopEvent} binds a
// `maintenances` row by id. Registered before any `Maintenance/{x}` pattern so 'events'
// is never swallowed as a wildcard.
Route::middleware('auth:sanctum')->prefix('Maintenance/events')->controller(WorkshopEventController::class)->group(function () {
    Route::get('/', 'index')->middleware('permission:maintenance.view');                 // ?vehicle_id=
    Route::post('/', 'store')->middleware('permission:maintenance.manage');
    Route::get('/{workshopEvent}', 'show')->middleware('permission:maintenance.view');
    Route::post('/{workshopEvent}', 'update')->middleware('permission:maintenance.manage');
    Route::delete('/{workshopEvent}', 'destroy')->middleware('permission:maintenance.manage');
});

// Fleet anomalies / exceptional cases (data conflicts + operational gaps)
Route::middleware(['auth:sanctum', 'permission:insights.view'])->get('Anomalies', [AnomalyController::class, 'index']);

// Data health: incomplete/broken records (cars without VIN/mileage, contracts without a car, …)
Route::middleware(['auth:sanctum', 'permission:insights.view'])->get('DataHealth', [DataHealthController::class, 'index']);

// Vehicle status vs. contract reality mismatches (status out of step with open contracts)
Route::middleware(['auth:sanctum', 'permission:insights.view'])->get('StatusMismatch', [StatusMismatchController::class, 'index']);

// Maintenance contract ↔ N-Maintenance sheet reconciliation: cars the sheet shows are
// back from the garage but whose maintenance contract is still open in OfficeManager.
Route::middleware(['auth:sanctum', 'permission:maintenance.view'])->get('MaintenanceReturns', [MaintenanceReturnController::class, 'index']);

// Read-only Sheet ↔ API vehicle comparison: match the Faster sheet to the live API by VIN
// and show every field that differs between the two sources.
Route::middleware(['auth:sanctum', 'permission:insights.view'])->get('VehicleCsvSync/diff', [VehicleCsvSyncController::class, 'diff']);

// Dashboard KPIs
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard', [DashboardController::class, 'summary']);
// Overdue rentals: open rentals past their estimated return date
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard/overdue-rentals', [DashboardController::class, 'overdueRentals']);
// Overdue maintenance: cars stuck in the garage past their expected return date
Route::middleware(['auth:sanctum', 'permission:dashboard.view'])->get('Dashboard/overdue-maintenance', [DashboardController::class, 'overdueMaintenance']);

Route::middleware('auth:sanctum')->prefix('Registration')->controller(VehicleRegistrationController::class)->group(function () {

    Route::get('/', 'index')->middleware('permission:registration.view');
    Route::get('/coverage', 'coverage')->middleware('permission:registration.view');   // every car + its registration/insurance status (vehicle-anchored)
    Route::get('/{registration}', 'show')->middleware('permission:registration.view');
    Route::post('/', 'store')->middleware('permission:registration.manage');
    Route::post('/{registration}', 'update')->middleware('permission:registration.manage');
    Route::delete('/{registration}', 'destroy')->middleware('permission:registration.manage');
});
