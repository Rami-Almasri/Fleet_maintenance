<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The PRIMARY DOMAIN CLASSIFICATION for a maintenance event.
 *
 * `kind` (fault | service | inspection) becomes the single field the whole system reads to know what an
 * event IS. Its source of truth is the catalog the user picked — exactly one of fault_catalog_id /
 * service_catalog_id / inspection_type_id is set, matching `kind`. Legacy / unmatched rows keep all
 * three null (default kind='fault', flagged needs_review) so nothing breaks and behaviour is unchanged
 * while features.event_kind = off. See docs/Service-vs-Fault-Domain-Separation.md §2.
 *
 * Enforcement is the MaintenanceTask `saving` guard (first line) plus — where the database allows it —
 * the CHECK constraint below (last line of defence). The descriptive columns (category_key, severity,
 * symptom, and the ticket's maintenance_type/visit_context) are UNTOUCHED — they no longer decide type.
 *
 * PORTABILITY: the CHECK is best-effort. MariaDB (local/XAMPP) accepts it; MySQL 8 REFUSES it with
 * errno 3823 — it will not allow a column in a CHECK when that column also carries a foreign key with a
 * referential action, and our three catalog FKs are ON DELETE SET NULL (`nullOnDelete`). Rather than
 * weaken the FKs (the delete semantics matter more than the redundant check), we skip the constraint on
 * such platforms and rely on the model guard, which enforces the identical invariant. Each column is
 * also added independently so a half-applied table — MySQL DDL is not transactional, so an earlier
 * failure here leaves the columns behind uncommitted to `migrations` — can complete on a re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        // PRIMARY CLASSIFICATION. Default 'fault' = current behaviour (safe: legacy rows read as today).
        if (! Schema::hasColumn('maintenance_tasks', 'kind')) {
            Schema::table('maintenance_tasks', function (Blueprint $table) {
                $table->string('kind', 20)->default('fault')->index()->after('symptom');
            });
        }

        // Typed source-of-truth references — exactly one non-null, matching `kind`.
        if (! Schema::hasColumn('maintenance_tasks', 'fault_catalog_id')) {
            Schema::table('maintenance_tasks', function (Blueprint $table) {
                $table->foreignId('fault_catalog_id')->nullable()->after('kind')
                    ->constrained('fault_catalog')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('maintenance_tasks', 'service_catalog_id')) {
            Schema::table('maintenance_tasks', function (Blueprint $table) {
                $table->foreignId('service_catalog_id')->nullable()->after('fault_catalog_id')
                    ->constrained('service_catalog')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('maintenance_tasks', 'inspection_type_id')) {
            Schema::table('maintenance_tasks', function (Blueprint $table) {
                $table->foreignId('inspection_type_id')->nullable()->after('service_catalog_id')
                    ->constrained('inspection_types')->nullOnDelete();
            });
        }

        // Provenance of `kind`: catalog (user pick) | resolver (backfill) | import | manual.
        if (! Schema::hasColumn('maintenance_tasks', 'classification_source')) {
            Schema::table('maintenance_tasks', function (Blueprint $table) {
                $table->string('classification_source', 20)->default('resolver')->after('inspection_type_id');
            });
        }
        // Resolver/import could not confidently classify — surfaced to an admin review queue.
        if (! Schema::hasColumn('maintenance_tasks', 'needs_review')) {
            Schema::table('maintenance_tasks', function (Blueprint $table) {
                $table->boolean('needs_review')->default(false)->index()->after('classification_source');
            });
        }

        // Last-line enforcement: exactly the catalog FK matching `kind` is set, OR all null (legacy).
        // Best-effort — see PORTABILITY above. A refusal here is not a failed migration.
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
            Log::warning('[migration] chk_task_kind_catalog not applied — the model guard remains the enforcer.', [
                'reason' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        // Drop the CHECK first (columns it references cannot be dropped while it exists). It may never
        // have been created on this platform, so a failure here is expected and ignored.
        try {
            DB::statement('ALTER TABLE maintenance_tasks DROP CONSTRAINT chk_task_kind_catalog');
        } catch (\Throwable $e) {
            // no constraint to drop — nothing to undo
        }

        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fault_catalog_id');
            $table->dropConstrainedForeignId('service_catalog_id');
            $table->dropConstrainedForeignId('inspection_type_id');
            $table->dropColumn(['kind', 'classification_source', 'needs_review']);
        });
    }
};
