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

        // Diagnostic knowledge base — the curated Symptom → Root-Cause library. Idempotent.
        $this->call(FaultCauseSeeder::class);

        // Findings keyword library (+ per-keyword risk grade), seeded from config. Idempotent.
        $this->call(FindingKeywordSeeder::class);

        // Smart Routing Engine — Garage Preference Rules matrix, seeded from config. Idempotent
        // (safe no-op until config('garage_routing.default_rules') names real garages).
        $this->call(GarageRoutingRuleSeeder::class);

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
