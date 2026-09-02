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

        // The automotive knowledge base, and the reason a fresh install can read free-text findings
        // before anyone configures an AI provider.
        //
        // ORDER IS LOAD-BEARING: the ontology links concepts to repair actions by slug, and warns
        // about every slug the catalogue cannot express — so the catalogue has to exist first.
        //
        // These three were previously run by hand, which meant a fresh database had the keyword list
        // but none of the vocabulary, none of the engineering profiles, and a knowledge-coverage
        // figure of zero. The matching engine is pure PHP over exactly these rows: seed them and
        // search works offline; skip them and it has nothing to match against. Idempotent, and
        // anything an admin has edited (source = human) is left alone.
        $this->call(ActionCatalogSeeder::class);
        $this->call(FaultOntologySeeder::class);
        $this->call(CustomerVocabularySeeder::class);

        // Smart Routing Engine — Garage Preference Rules matrix, seeded from config. Idempotent
        // (safe no-op until config('garage_routing.default_rules') names real garages).
        $this->call(GarageRoutingRuleSeeder::class);

        // Asset Layer — component TYPE dictionary, seeded from config. Idempotent, additive-only.
        $this->call(ComponentCatalogSeeder::class);

        // Event Type layer — the three maintenance-event catalogs (source of truth for `kind`).
        // Idempotent, additive-only; safe with features.event_kind = off.
        $this->call(ServiceCatalogSeeder::class);
        $this->call(FaultCatalogSeeder::class);
        $this->call(InspectionTypeSeeder::class);
        $this->call(DamageCatalogSeeder::class);

        // Location layer — the shared "where on the car" vocabulary, plus the location_mode policy it
        // stamps onto the fault/damage catalogs. Runs AFTER them: stampPolicy() reads their rows.
        $this->call(VehicleLocationSeeder::class);

        // Plate-code dictionary (OM PlateColorNo → plate letter), from the version-controlled CSV.
        // Both of these seeders existed and were simply never called here, so a from-zero rebuild
        // came up with an empty plate dictionary and an empty source registry while every other
        // catalogue was present — the kind of gap that only shows once the rebuild is the only copy.
        $this->call(PlateCodeSeeder::class);

        // Knowledge-source registry — which bodies of documentation exist and how each may lawfully
        // be used. The retriever reads `access` off these rows, so an empty table is not a smaller
        // knowledge base, it is a retriever with nothing to cite.
        $this->call(KnowledgeSourceSeeder::class);

        // Odoo financial integration — one row per canonical expense type, carrying the DEFAULT
        // document type from config/odoo.php. Deliberately seeds NO Odoo account id: that is a real
        // id in another database and inventing one would post money to the wrong account. Finance
        // resolves it on the mappings screen against pulled master data. Idempotent, and it never
        // overwrites a mapping somebody has already decided.
        $this->call(ExpenseTypeMappingSeeder::class);

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
