<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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

        // Asset Layer — component TYPE dictionary, seeded from config. Idempotent, additive-only.
        $this->call(ComponentCatalogSeeder::class);

        // A bootstrap super-admin so there's always one account that can do
        // everything (and promote others). Credentials come from the environment,
        // NOT a hardcoded default, and re-seeding NEVER resets the password of an
        // account that already exists (so a rotated prod password is preserved).
        $adminEmail = env('BOOTSTRAP_ADMIN_EMAIL', 'admin@fleet.local');
        $admin = User::where('email', $adminEmail)->first();

        if (!$admin) {
            // First-ever create. Use the env password if provided, otherwise mint
            // a strong random one and print it ONCE so it can be captured.
            $envPassword = env('BOOTSTRAP_ADMIN_PASSWORD');
            $plainPassword = $envPassword ?: Str::password(16);

            $admin = User::create([
                'name' => 'Fleet Admin',
                'email' => $adminEmail,
                'password' => Hash::make($plainPassword),
                'status' => 'active',
            ]);

            if (!$envPassword) {
                $this->command?->warn("=================================================================");
                $this->command?->warn(" Bootstrap admin created: {$adminEmail}");
                $this->command?->warn(" Generated password (shown ONCE — store it now): {$plainPassword}");
                $this->command?->warn(" Set BOOTSTRAP_ADMIN_PASSWORD in .env to control this yourself.");
                $this->command?->warn("=================================================================");
            }
        } else {
            // Account already exists — do NOT touch its password. Just make sure it
            // is active. (Re-running the seeder must never re-open a known password.)
            if ($admin->status !== 'active') {
                $admin->update(['status' => 'active']);
            }
        }

        $admin->syncRoles('super-admin');
    }
}
