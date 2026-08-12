<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Single source of truth for the app's RBAC model.
 *
 * Idempotent: re-running only adds what's missing and re-syncs each role's
 * permission set, so it's safe to run on every deploy. Permissions follow a
 * `resource.action` convention; every route in routes/api.php is gated by one
 * of these (see the `permission:` middleware there).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Every permission in the system. Read = `.view`, write/delete = `.manage`.
     * Keep this list in lock-step with the middleware in routes/api.php.
     */
    public const PERMISSIONS = [
        'vehicles.view', 'vehicles.manage',
        'vehicles.approve_odometer', // admin-only: review/approve-or-reject significant manual odometer edits
        'drivers.view', 'drivers.manage',
        'vendors.view', 'vendors.manage',
        'customers.view', 'customers.manage',
        'contracts.view', 'contracts.manage',
        'booking_readiness.view', 'booking_readiness.manage', // pickup-prep board + its trigger settings (horizon / lead / inspection validity / holidays)
        'inspections.view', 'inspections.manage', // vehicle condition photos + damage flags (pre/post inspection) + inspection schedules
        'reminders.view', 'reminders.manage',     // Reminders section: service reminders (oil/filters) + contact reminders (call a garage)
        'billing.view', 'billing.manage',    // invoices + payments/receipts (website-native billing)
        'operations.manage',                 // start/close vehicle movements
        'operations.override',               // manager-only: override the "Rental-First" block (maintenance contract on a rented car)
        'logistics.view',                    // see the Logistics Dispatch board + My Queue; can be assigned a move + mark it delivered
        'logistics.dispatch',                // Coordinator: raise / cancel a Logistics Dispatch + oversee + ping (no Claim button)
        'logistics.claim',                   // Field driver: claim a pooled move + drive it (Picked Up → Delivered → Returned)
        'maintenance.view', 'maintenance.approve', 'maintenance.manage', // manage = create/edit/delete workshop events
        'maintenance.initiate',              // open a maintenance workflow ticket + file the test-drive report + re-inspect (Inspector)
        'maintenance.logistics',             // advance a ticket through dispatch → under-repair → ready (Logistics/Delivery)
        'maintenance.delegate',              // Supervisor: delegate a driver to pickup/dropoff, reassign + ping ("Where is the car?")
        'maintenance.recurring.view',        // see the Recurring Fault Reviews inbox (confirmed faults that came back after a fix)
        'maintenance.recurring.manage',      // record the management decision on a recurring-fault review case
        'maintenance.checkpoint.create',     // submit a Maintenance Checkpoint (workshop progress update + evidence) on an in-shop ticket
        'maintenance.checkpoint.manage',     // edit/delete checkpoints + set a ticket's responsible follow-up users (Waleed/Abdullah)
        'parts.view',                        // see part requests / purchases / vehicle part history
        'parts.request',                     // create a part request (customer walk-in or garage diagnosis)
        'parts.purchase',                    // record a purchase (garage or supplier) + install the part
        'parts.investigate',                 // admin: review duplicate/recurrence alerts + approve exceptions
        'components.view',                   // Asset Layer: see a car's installed components / history / warehouse inventory
        'components.manage',                 // Asset Layer: install / remove / transfer / dispose components + curate the catalog
        'components.backfill',               // Asset Layer: run the legacy-data backfill (super-admin/admin only)
        'registration.view', 'registration.manage',
        'insights.view',                     // anomalies, data-health, status-mismatch
        // Fleet Intelligence: the governed-metric surfaces (garage scorecards, profiles, comparison)
        // and the evidence drawer behind every figure on them. Separate from insights.view because
        // this grades SUPPLIERS — the drill-down names garages and the repairs they did.
        'intelligence.view',
        'dashboard.view',                    // dashboard KPIs + fleet expiring
        'sync.run',                          // run/monitor data syncs
        'users.manage',                      // manage users & role assignments (admin only)
    ];

    /**
     * Role -> permission grants. 'super-admin' is intentionally absent here:
     * it bypasses every check via Gate::before (see AppServiceProvider) and is
     * granted the full set below so the frontend sees all capabilities too.
     */
    public const ROLES = [
        // Full operational control of the fleet, minus user administration.
        'manager' => [
            'vehicles.view', 'vehicles.manage', // NOTE: vehicles.approve_odometer is admin-only — not granted here
            'drivers.view', 'drivers.manage',
            'vendors.view', 'vendors.manage',
            'customers.view', 'customers.manage',
            'contracts.view', 'contracts.manage',
            'booking_readiness.view', 'booking_readiness.manage',
            'inspections.view', 'inspections.manage',
            'reminders.view', 'reminders.manage',
            'billing.view', 'billing.manage',
            'operations.manage', 'operations.override',
            'logistics.view', 'logistics.dispatch', 'logistics.claim',
            'maintenance.view', 'maintenance.approve', 'maintenance.manage',
            'maintenance.initiate', 'maintenance.logistics', 'maintenance.delegate',
            'maintenance.recurring.view', 'maintenance.recurring.manage',
            'maintenance.checkpoint.create', 'maintenance.checkpoint.manage',
            'parts.view', 'parts.request', 'parts.purchase', 'parts.investigate',
            'components.view', 'components.manage', // Asset Layer: full operational control includes asset custody
            'registration.view', 'registration.manage',
            'insights.view', 'intelligence.view', 'dashboard.view', 'sync.run',
        ],
        // Day-to-day desk: rentals, customers, moving cars in/out, taking payments.
        'operations' => [
            'vehicles.view', 'drivers.view',
            'customers.view', 'customers.manage',
            'contracts.view', 'contracts.manage',
            'booking_readiness.view', 'booking_readiness.manage',
            'inspections.view', 'inspections.manage',
            'reminders.view', 'reminders.manage',
            'billing.view', 'billing.manage',
            'operations.manage',
            'logistics.view', 'logistics.dispatch', 'logistics.claim',
            'registration.view', 'maintenance.view', 'dashboard.view',
            'parts.view', 'parts.request', 'parts.purchase',
            'components.view', // Asset Layer: read-only (desk role — no asset custody)
            // Fleet analytics: utilization, maintenance↔rental overlaps, active shop stays, the swap
            // board, mileage-chain audit and the oversight surfaces. Operations was the ONLY senior role
            // without this — maintenance, finance and even the read-only viewer all had it — so an ops
            // manager holding billing.manage could not open the utilisation board a viewer could see.
            // That inverted ladder was an oversight in this list, not a policy.
            'insights.view',
        ],
        // Garage / workshop coordination and bill approvals.
        'maintenance' => [
            'vehicles.view', 'vendors.view',
            'inspections.view', 'inspections.manage',
            'reminders.view', 'reminders.manage',
            'maintenance.view', 'maintenance.approve', 'maintenance.manage',
            'maintenance.initiate', 'maintenance.logistics', 'maintenance.delegate',
            'maintenance.recurring.view', 'maintenance.recurring.manage',
            'maintenance.checkpoint.create', 'maintenance.checkpoint.manage',
            'parts.view', 'parts.request', 'parts.purchase', 'parts.investigate',
            'components.view', 'components.manage', // Asset Layer: the workshop-manager role owns install/remove/transfer
            'logistics.view',
            'registration.view', 'insights.view', 'intelligence.view', 'dashboard.view',
        ],
        // Supervisor / Coordinator (e.g. Waleed Medhat, Abdullah Asham): the DISPATCHER. After the
        // inspector files a report, the supervisor reviews the open ticket, picks the destination
        // garage and assigns a driver for pickup (maintenance.delegate drives the new Phase-2
        // assign-dispatch step). They also raise/oversee/ping logistics moves (logistics.dispatch) and
        // auto-watch any ticket the inspector prioritises. maintenance.logistics is granted too, so a
        // supervisor can handle the pickup/delivery themselves when needed.
        'supervisor' => [
            'vehicles.view', 'vendors.view', 'drivers.view',
            'maintenance.view', 'maintenance.delegate', 'maintenance.logistics',
            'maintenance.recurring.view',
            'maintenance.checkpoint.create', 'maintenance.checkpoint.manage',
            // Parts board: the supervisor works the whole lane — raise the request, buy the part,
            // mark it delivered, install it, send it back if it's wrong. What he does NOT get is the
            // decision at the top of the lane: approving (or rejecting) a request is the money gate and
            // stays with admin / maintenance manager (parts.investigate|maintenance.manage on the route).
            'parts.view', 'parts.request', 'parts.purchase',
            'components.view', 'components.manage', // Asset Layer: authorized maintenance delegates hold asset custody
            'logistics.view', 'logistics.dispatch',
            'intelligence.view',   // picks the destination garage — needs to see who is good at what
            'dashboard.view',
        ],
        // Inspector (e.g. Abu Maroof): opens tickets, files the test-drive report, re-inspects on return.
        'inspector' => [
            'vehicles.view', 'vendors.view',
            'inspections.view', 'inspections.manage',
            'maintenance.view', 'maintenance.initiate',
            'parts.view', 'parts.request',
            'components.view', // Asset Layer: read-only — technician-tier gets manage only by explicit per-user grant
            'logistics.view',
            'dashboard.view',
        ],
        // Logistics / Delivery — the field driver pool. Claims pooled moves and drives them through the
        // round trip (logistics.claim); receives the maintenance dispatch hand-off, captures odometer +
        // garage. Keeps logistics.dispatch so a driver can also raise an ad-hoc move from the grid.
        'logistics' => [
            'vehicles.view', 'vendors.view',
            'maintenance.view', 'maintenance.logistics',
            'parts.view', 'parts.request',
            'components.view', // Asset Layer: read-only — technician-tier gets manage only by explicit per-user grant
            'logistics.view', 'logistics.dispatch', 'logistics.claim',
            'dashboard.view',
        ],
        // Billing / accounts: customer financials, invoices and payments.
        'finance' => [
            'vehicles.view', 'customers.view', 'customers.manage',
            'contracts.view', 'booking_readiness.view', 'inspections.view', 'billing.view', 'billing.manage',
            'reminders.view', 'reminders.manage',
            'parts.view',
            'components.view', // Asset Layer: read-only (asset cost visibility)
            'insights.view', 'dashboard.view',
        ],
        // Read-only across the board.
        'viewer' => [
            'vehicles.view', 'drivers.view', 'vendors.view',
            'customers.view', 'contracts.view', 'booking_readiness.view', 'inspections.view', 'billing.view', 'registration.view',
            'reminders.view',
            'maintenance.view', 'logistics.view', 'parts.view', 'components.view', 'insights.view', 'dashboard.view',
        ],
    ];

    public function run(): void
    {
        // Reset the cached roles/permissions so changes take effect immediately.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Flush again so the just-created permissions are resolvable by name
        // when we sync them onto roles below.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // super-admin & admin both get the full permission set. super-admin also
        // bypasses checks entirely via Gate::before; admin is the assignable
        // "can do everything including manage users" role.
        $all = self::PERMISSIONS;
        foreach (['super-admin', 'admin'] as $name) {
            Role::findOrCreate($name, 'web')->syncPermissions($all);
        }

        foreach (self::ROLES as $name => $permissions) {
            Role::findOrCreate($name, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
