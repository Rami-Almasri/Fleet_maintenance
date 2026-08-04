<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * component_catalog becomes the EDITABLE parts vocabulary — the single list of part names the whole
 * app selects from, instead of every inspector typing his own wording into a free-text box.
 *
 * Until now the catalog was config-authored and read-only: config/component_catalog.php was the
 * source of truth and ComponentCatalogSeeder upserted it on every deploy. That inverts here. The DB
 * becomes the source of truth and the config becomes a STARTING SEED, because the people who know
 * the part names are the ones using the app, not the ones editing PHP files.
 *
 * The inversion needs a guard, or the next deploy silently overwrites every correction someone made
 * in the UI. `edited_in_app` is that guard: the controller stamps it on any write, and the seeder
 * skips the descriptive fields of a stamped row (it still INSERTS rows that are new to the config,
 * so shipping a new part type keeps working). One flag, checked in one place — see the seeder.
 *
 * The three new descriptive columns:
 *
 *   name_ar  — the Arabic name. A separate column, not a translation file, because this vocabulary
 *              is data the user maintains, not UI copy the developer ships.
 *
 *   aliases  — how people actually ASK for the part, in any language: other trade names ("dynamo"
 *              for the alternator), and the SYMPTOM wording they use instead of the part name ("AC
 *              not cooling" when they mean the compressor). Purely a SEARCH aid for the picker, so
 *              a technician who types the fault still lands on the right part.
 *              THIS IS NOT A FAULT VOCABULARY. The findings catalog owns what a fault IS
 *              (config/maintenance_findings.php); an alias here only helps someone find a row.
 *              Nothing counts, groups or reports on aliases — matching them is never evidence.
 *
 *   default_warranty_km — the mileage leg of a warranty. The catalog could only ever express
 *              warranty in months, so "12 months or 20,000 km, whichever comes first" was
 *              unrepresentable and the km half was simply lost. Both legs are defaults that a real
 *              warranty copies at install time; neither is a warranty by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('component_catalog', function (Blueprint $table) {
            $table->string('name_ar', 160)->nullable()->after('name');

            // Flat array of search synonyms, any language, any phrasing. Flat on purpose: the picker
            // asks one question ("does what he typed look like this row?"), so typing the entries
            // would add structure nothing reads.
            $table->json('aliases')->nullable()->after('name_ar');

            $table->unsignedInteger('default_warranty_km')->nullable()->after('default_warranty_months');

            // --- the seeder guard + its audit trail ---
            // Once true, the seeder treats this row as owned by the user and stops updating it.
            $table->boolean('edited_in_app')->default(false)->after('is_active');
            $table->timestamp('edited_at')->nullable()->after('edited_in_app');
            $table->foreignId('edited_by')->nullable()->after('edited_at')
                ->constrained('users')->nullOnDelete();
            // Snapshot, so a removed user never blanks the "who changed this part name" answer.
            $table->string('edited_by_name')->nullable()->after('edited_by');
        });
    }

    public function down(): void
    {
        Schema::table('component_catalog', function (Blueprint $table) {
            $table->dropConstrainedForeignId('edited_by');
            $table->dropColumn([
                'name_ar', 'aliases', 'default_warranty_km',
                'edited_in_app', 'edited_at', 'edited_by_name',
            ]);
        });
    }
};
