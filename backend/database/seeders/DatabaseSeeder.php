<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Roles & permissions must exist before we can assign them.
        $this->call(RolesAndPermissionsSeeder::class);

        // A bootstrap super-admin so there's always one account that can do
        // everything (and promote others). Idempotent via updateOrCreate.
        $admin = User::updateOrCreate(
            ['email' => 'admin@fleet.local'],
            [
                'name' => 'Fleet Admin',
                'password' => Hash::make('password'),
                'status' => 'active',
            ],
        );
        $admin->syncRoles('super-admin');
    }
}
