<?php

namespace Tests\Foundation;

use App\Models\ComponentCatalog;
use Database\Seeders\ComponentCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The schema the migrations promise, and the seeder's promise never to overwrite a human.
 */
class SchemaAndSeederTest extends FoundationTestCase
{
    // ── schema ───────────────────────────────────────────────────────────────────────────────────

    public function test_the_new_tables_exist_with_their_columns(): void
    {
        $expected = [
            'component_catalog' => ['name_ar', 'aliases', 'default_warranty_km', 'edited_in_app', 'edited_at', 'edited_by', 'edited_by_name'],
            'warranties' => ['kind', 'vehicle_id', 'part_purchase_id', 'vehicle_component_id', 'maintenance_task_id',
                'subject', 'component_catalog_id', 'provider_vendor_id', 'starts_on', 'start_odometer',
                'duration_months', 'duration_km', 'expires_on', 'expires_at_km', 'status', 'void_reason', 'deleted_at'],
            'warranty_claims' => ['warranty_id', 'claimed_on', 'claim_odometer', 'was_in_window',
                'window_evidence', 'outcome', 'outcome_reason', 'recovered_amount', 'remedy', 'deleted_at'],
            'maintenance_required_parts' => ['component_catalog_id', 'catalog_matched_by'],
        ];

        foreach ($expected as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "missing table {$table}");
            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "missing {$table}.{$column}");
            }
        }
    }

    /**
     * Delete rules are not cosmetic: they decide what a deletion DOES to the rest of the database.
     * Every one of these was chosen, so every one is pinned.
     */
    public function test_foreign_key_delete_rules_are_what_was_designed(): void
    {
        $rules = DB::select("
            SELECT k.table_name AS t, k.column_name AS c, r.delete_rule AS rule
            FROM information_schema.key_column_usage k
            JOIN information_schema.referential_constraints r
              ON r.constraint_schema = k.table_schema AND r.constraint_name = k.constraint_name
            WHERE k.table_schema = DATABASE() AND k.table_name IN ('warranties','warranty_claims','maintenance_required_parts')
        ");

        $actual = [];
        foreach ($rules as $r) {
            $actual["{$r->t}.{$r->c}"] = $r->rule;
        }

        $expected = [
            // The car owns its warranties; losing the car loses them with it.
            'warranties.vehicle_id'                        => 'CASCADE',
            // A claim cannot outlive the promise it was made against.
            'warranty_claims.warranty_id'                  => 'CASCADE',
            // Anchors and people are SET NULL: the warranty is evidence and must survive their removal.
            'warranties.part_purchase_id'                  => 'SET NULL',
            'warranties.vehicle_component_id'              => 'SET NULL',
            'warranties.maintenance_task_id'               => 'SET NULL',
            'warranties.maintenance_id'                    => 'SET NULL',
            'warranties.provider_vendor_id'                => 'SET NULL',
            'warranties.created_by'                        => 'SET NULL',
            // RESTRICT is what makes PartsCatalogController::destroy return 422 instead of a SQL error.
            'warranties.component_catalog_id'              => 'RESTRICT',
            'maintenance_required_parts.component_catalog_id' => 'RESTRICT',
        ];

        foreach ($expected as $key => $rule) {
            $this->assertSame($rule, $actual[$key] ?? null, "delete rule changed for {$key}");
        }
    }

    public function test_the_indexes_the_read_paths_depend_on_exist(): void
    {
        $indexed = fn (string $table, string $cols) => DB::selectOne("
            SELECT 1 AS ok FROM (
                SELECT table_name t, index_name i, GROUP_CONCAT(column_name ORDER BY seq_in_index) c
                FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ?
                GROUP BY table_name, index_name
            ) x WHERE x.c = ?", [$table, $cols]);

        // "What is live on this car" / "what expires soon, by kind" / "is this fault still covered".
        $this->assertNotNull($indexed('warranties', 'vehicle_id,status,expires_on'));
        $this->assertNotNull($indexed('warranties', 'kind,status,expires_on'));
        $this->assertNotNull($indexed('warranties', 'maintenance_task_id,status'));
        // "What has this car needed, by part type".
        $this->assertNotNull($indexed('maintenance_required_parts', 'component_catalog_id,status'));
    }

    public function test_the_catalog_slug_is_unique(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('component_catalog')->insert([
            'slug' => 'alternator', 'name' => 'Clone', 'category_key' => 'electrical',
            'tracking_mode' => 'batch', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── seeder ───────────────────────────────────────────────────────────────────────────────────

    public function test_the_catalog_seeds_every_configured_part_with_arabic(): void
    {
        $configured = count(config('component_catalog'));

        $this->assertSame($configured, ComponentCatalog::count());
        $this->assertSame(0, ComponentCatalog::whereNull('name_ar')->count(), 'every part needs an Arabic name');
        $this->assertSame(0, ComponentCatalog::whereNull('aliases')->count(), 'every part needs search aliases');
    }

    /** Re-seeding must not duplicate, and must not churn rows it does not change. */
    public function test_seeding_is_idempotent(): void
    {
        $before = ComponentCatalog::count();

        (new ComponentCatalogSeeder())->run();
        (new ComponentCatalogSeeder())->run();

        $this->assertSame($before, ComponentCatalog::count());
    }

    /**
     * THE GUARD THE WHOLE EDITABLE-CATALOG DESIGN RESTS ON. Without it a deploy silently discards
     * the corrections made by the people who know the right word.
     */
    public function test_reseeding_never_overwrites_a_row_edited_in_the_app(): void
    {
        $part = ComponentCatalog::where('slug', 'alternator')->first();
        $part->update([
            'name_ar'       => 'دينمو (تعديل يدوي)',
            'name'          => 'Alternator (renamed by hand)',
            'edited_in_app' => true,
            'edited_at'     => now(),
        ]);

        (new ComponentCatalogSeeder())->run();

        $after = $part->fresh();
        $this->assertSame('دينمو (تعديل يدوي)', $after->name_ar);
        $this->assertSame('Alternator (renamed by hand)', $after->name);
    }

    /** A clean row still tracks the config, so a shipped correction reaches every install. */
    public function test_reseeding_does_update_a_row_nobody_has_edited(): void
    {
        $part = ComponentCatalog::where('slug', 'alternator')->first();
        $part->update(['name_ar' => 'wrong', 'edited_in_app' => false]);

        (new ComponentCatalogSeeder())->run();

        $this->assertSame('دينمو', $part->fresh()->name_ar);
    }

    /** Retirement is a DB-side decision and must survive a deploy too. */
    public function test_reseeding_does_not_reactivate_a_retired_part(): void
    {
        $part = ComponentCatalog::where('slug', 'alternator')->first();
        $part->update(['is_active' => false, 'edited_in_app' => false]);

        (new ComponentCatalogSeeder())->run();

        $this->assertFalse($part->fresh()->is_active);
    }

    /** A new part shipped in config still lands on an install full of edited rows. */
    public function test_seeding_still_inserts_parts_that_are_new_to_the_config(): void
    {
        ComponentCatalog::where('slug', 'alternator')->delete();
        $before = ComponentCatalog::count();

        (new ComponentCatalogSeeder())->run();

        $this->assertSame($before + 1, ComponentCatalog::count());
        $this->assertNotNull(ComponentCatalog::where('slug', 'alternator')->first());
    }
}
