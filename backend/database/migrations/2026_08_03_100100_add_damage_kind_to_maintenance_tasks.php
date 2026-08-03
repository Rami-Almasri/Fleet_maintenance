<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Wires the DAMAGE kind into maintenance_tasks: a fourth typed catalog reference, and the
 * exactly-one-catalog CHECK widened to know about it.
 *
 * Additive and behaviour-neutral on its own. No existing row changes kind here — the reclassification
 * of history is a separate, reviewable, idempotent step (`events:reclassify-damage`), because moving
 * ~6,000 rows out of "fault" is a data decision that deserves a dry run and a backup, not a migration
 * nobody can preview.
 *
 * PORTABILITY. Same CHECK caveat as the original `kind` migration: MySQL 8 refuses a CHECK on a column
 * that also carries an FK with a referential action (errno 3823) and all four catalog FKs are
 * ON DELETE SET NULL. Where the constraint cannot be installed the model guard remains the enforcer and
 * `php artisan events:kind-integrity` verifies the same invariants from the outside.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('maintenance_tasks', 'damage_catalog_id')) {
            Schema::table('maintenance_tasks', function (Blueprint $table) {
                $table->foreignId('damage_catalog_id')->nullable()->after('inspection_type_id')
                    ->constrained('damage_catalog')->nullOnDelete();
            });
        }

        // The old CHECK knows only three kinds, so it would reject every damage row. Drop and rebuild.
        try {
            DB::statement('ALTER TABLE maintenance_tasks DROP CONSTRAINT chk_task_kind_catalog');
        } catch (\Throwable $e) {
            // Never existed on this platform (MySQL 8) — nothing to drop.
        }

        try {
            DB::statement(<<<'SQL'
                ALTER TABLE maintenance_tasks ADD CONSTRAINT chk_task_kind_catalog CHECK (
                     (kind = 'fault'      AND fault_catalog_id   IS NOT NULL AND service_catalog_id IS NULL AND inspection_type_id IS NULL AND damage_catalog_id IS NULL)
                  OR (kind = 'service'    AND service_catalog_id IS NOT NULL AND fault_catalog_id   IS NULL AND inspection_type_id IS NULL AND damage_catalog_id IS NULL)
                  OR (kind = 'inspection' AND inspection_type_id IS NOT NULL AND fault_catalog_id   IS NULL AND service_catalog_id IS NULL AND damage_catalog_id IS NULL)
                  OR (kind = 'damage'     AND damage_catalog_id  IS NOT NULL AND fault_catalog_id   IS NULL AND service_catalog_id IS NULL AND inspection_type_id IS NULL)
                  OR (fault_catalog_id IS NULL AND service_catalog_id IS NULL AND inspection_type_id IS NULL AND damage_catalog_id IS NULL)
                )
            SQL);
        } catch (\Throwable $e) {
            Log::warning('[migration] chk_task_kind_catalog (4-kind) not applied — the model guard remains the enforcer.', [
                'reason' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE maintenance_tasks DROP CONSTRAINT chk_task_kind_catalog');
        } catch (\Throwable $e) {
            // not present
        }

        // Any damage rows must go back to the safe default before the column disappears, or they would
        // be left carrying a kind nothing can resolve.
        DB::table('maintenance_tasks')->where('kind', 'damage')->update([
            'kind'              => 'fault',
            'damage_catalog_id' => null,
            'needs_review'      => true,
        ]);

        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('damage_catalog_id');
        });

        // Restore the three-kind constraint so a rollback lands on the previous shape exactly.
        try {
            DB::statement(<<<'SQL'
                ALTER TABLE maintenance_tasks ADD CONSTRAINT chk_task_kind_catalog CHECK (
                     (kind = 'fault'      AND fault_catalog_id   IS NOT NULL AND service_catalog_id IS NULL AND inspection_type_id IS NULL)
                  OR (kind = 'service'    AND service_catalog_id IS NOT NULL AND fault_catalog_id   IS NULL AND inspection_type_id IS NULL)
                  OR (kind = 'inspection' AND inspection_type_id IS NOT NULL AND fault_catalog_id   IS NULL AND service_catalog_id IS NULL)
                  OR (fault_catalog_id IS NULL AND service_catalog_id IS NULL AND inspection_type_id IS NULL)
                )
            SQL);
        } catch (\Throwable $e) {
            // best-effort, as on the way up
        }
    }
};
