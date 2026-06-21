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
        'drivers.view', 'drivers.manage',
        'vendors.view', 'vendors.manage',
        'customers.view', 'customers.manage',
        'contracts.view', 'contracts.manage',
        'operations.manage',                 // start/close vehicle movements
        'maintenance.view', 'maintenance.approve', 'maintenance.manage', // manage = create/edit/delete workshop events
        'registration.view', 'registration.manage',
        'insights.view',                     // anomalies, data-health, status-mismatch, sheet↔api diff
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
            'vehicles.view', 'vehicles.manage',
            'drivers.view', 'drivers.manage',
            'vendors.view', 'vendors.manage',
            'customers.view', 'customers.manage',
            'contracts.view', 'contracts.manage',
            'operations.manage',
            'maintenance.view', 'maintenance.approve', 'maintenance.manage',
            'registration.view', 'registration.manage',
            'insights.view', 'dashboard.view', 'sync.run',
        ],
        // Day-to-day desk: rentals, customers, moving cars in/out.
        'operations' => [
            'vehicles.view', 'drivers.view',
            'customers.view', 'customers.manage',
            'contracts.view', 'contracts.manage',
            'operations.manage',
            'registration.view', 'maintenance.view', 'dashboard.view',
        ],
        // Garage / workshop coordination and bill approvals.
        'maintenance' => [
            'vehicles.view', 'vendors.view',
            'maintenance.view', 'maintenance.approve', 'maintenance.manage',
            'registration.view', 'insights.view', 'dashboard.view',
        ],
        // Billing / accounts: customer financials and contract values.
        'finance' => [
            'vehicles.view', 'customers.view', 'customers.manage',
            'contracts.view', 'insights.view', 'dashboard.view',
        ],
        // Read-only across the board.
        'viewer' => [
            'vehicles.view', 'drivers.view', 'vendors.view',
            'customers.view', 'contracts.view', 'registration.view',
            'maintenance.view', 'insights.view', 'dashboard.view',
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
