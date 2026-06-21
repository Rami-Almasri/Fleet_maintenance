<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * Assign (or replace) a user's role from the CLI.
 *
 *   php artisan user:role admin@fleet.local manager
 *   php artisan user:role someone@x.com viewer --add   # keep existing roles, add this one
 */
class AssignUserRole extends Command
{
    protected $signature = 'user:role
        {email : The user\'s email address}
        {role : The role to grant (super-admin, admin, manager, operations, maintenance, finance, viewer)}
        {--add : Add the role instead of replacing the user\'s existing roles}';

    protected $description = "Assign a Spatie role to a user by email";

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error("No user found with email {$this->argument('email')}");
            return self::FAILURE;
        }

        $role = $this->argument('role');
        if (! Role::where('name', $role)->where('guard_name', 'web')->exists()) {
            $this->error("Role '{$role}' does not exist. Run `php artisan db:seed --class=RolesAndPermissionsSeeder` first.");
            $this->line('Available roles: ' . Role::pluck('name')->implode(', '));
            return self::FAILURE;
        }

        if ($this->option('add')) {
            $user->assignRole($role);
        } else {
            $user->syncRoles([$role]);
        }

        $this->info("{$user->email} now has role(s): " . $user->getRoleNames()->implode(', '));
        return self::SUCCESS;
    }
}
