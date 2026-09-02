<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The specification layer — what a part IS, alongside which part it is.
 *
 * ONE column shape (`specs`, a flat JSON map) is added to every table that records a real part, so
 * a battery's voltage survives the whole journey: asked for on a required part → bought on a
 * purchase → fitted as a component → consumed on a service record. Same key, same values, same
 * renderer at every step. Previously the fact was typed into a description field, or lost.
 *
 * `component_catalog.spec_fields` is the declaration side: which fields a part TYPE carries. It is
 * seeded from config/component_catalog.php and, like every other column on that table, becomes the
 * app's to edit afterwards.
 *
 * `vehicle_part_specs` is the fitment side, and the reason this is a feature rather than a text
 * box: it holds what THIS CAR takes, so the oil-change screen can say "4.5 L of 5W-30" before
 * anybody types anything, and so fitting 10W-40 can be questioned at the moment it is entered
 * rather than discovered in a warranty argument.
 *
 * All columns are nullable and no data is backfilled. An unspecced part is the honest state of
 * every row that exists today, and inventing values for them would put guesses in the one place
 * that is supposed to hold facts.
 */
return new class extends Migration
{
    /** The tables that record a real part and therefore carry `specs`, with the column to sit after. */
    private const SPEC_TABLES = [
        'vehicle_components'         => 'label',       // the fitted thing
        'part_purchases'             => 'part_name',   // the money spent on it
        'maintenance_required_parts' => 'part_name',   // what was asked for, before any of it existed
        'service_records'            => 'description', // consumables: the oil that went in
        'store_items'                => 'name',        // what is on the shelf
    ];

    public function up(): void
    {
        Schema::table('component_catalog', function (Blueprint $table) {
            // Which spec fields this part TYPE carries — keys into config/part_specs.php.
            $table->json('spec_fields')->nullable()->after('identity_aliases');
        });

        foreach (self::SPEC_TABLES as $tableName => $after) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'specs')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $after) {
                $column = $table->json('specs')->nullable();

                if (Schema::hasColumn($tableName, $after)) {
                    $column->after($after);
                }
            });
        }

        Schema::create('vehicle_part_specs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_catalog_id')->constrained('component_catalog')->restrictOnDelete();

            /** What this car takes, in the shared shape. @see \App\Support\PartSpecs */
            $table->json('specs');

            /**
             * HOW WE KNOW. A confirmed figure and a figure copied from the last fitting are both
             * useful and are not the same claim, and the screen says which one it is showing.
             *
             *   observed — copied from the last part actually fitted. A record of what was done,
             *              not of what is correct: if the wrong oil went in last time, this is the
             *              wrong oil, and it is labelled as observed so nobody reads it as approved.
             *   manual   — a person entered it deliberately. Outranks observed and is never
             *              overwritten by it.
             *   manual_book — taken from the manufacturer's own documentation. The strongest claim.
             */
            $table->string('source', 20)->default('observed');

            // The fitting this was learned from — so "where did 5W-30 come from?" has an answer.
            $table->foreignId('learned_from_component_id')->nullable()
                ->constrained('vehicle_components')->nullOnDelete();
            $table->foreignId('learned_from_service_record_id')->nullable()
                ->constrained('service_records')->nullOnDelete();

            $table->text('notes')->nullable();

            // dateTime, not timestamp: MariaDB silently auto-updates the first TIMESTAMP column in a
            // table, which would rewrite the moment a spec was confirmed on every unrelated save.
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('confirmed_by_name')->nullable();

            $table->timestamps();

            // One answer per car per part type. Two rows would mean the oil screen has to choose,
            // and any rule for choosing is a place for them to disagree.
            $table->unique(['vehicle_id', 'component_catalog_id'], 'vehicle_part_specs_unique');
            $table->index('component_catalog_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_part_specs');

        foreach (array_keys(self::SPEC_TABLES) as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'specs')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('specs'));
            }
        }

        Schema::table('component_catalog', fn (Blueprint $table) => $table->dropColumn('spec_fields'));
    }
};
