<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
 * Enforcement is BOTH: the MariaDB CHECK constraint below (last line of defence) and the
 * MaintenanceTask `saving` guard (first line). The descriptive columns (category_key, severity,
 * symptom, and the ticket's maintenance_type/visit_context) are UNTOUCHED — they no longer decide type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            // PRIMARY CLASSIFICATION. Default 'fault' = current behaviour (safe: legacy rows read as today).
            $table->string('kind', 20)->default('fault')->index()->after('symptom');

            // Typed source-of-truth references — exactly one non-null, matching `kind`.
            $table->foreignId('fault_catalog_id')->nullable()->after('kind')
                ->constrained('fault_catalog')->nullOnDelete();
            $table->foreignId('service_catalog_id')->nullable()->after('fault_catalog_id')
                ->constrained('service_catalog')->nullOnDelete();
            $table->foreignId('inspection_type_id')->nullable()->after('service_catalog_id')
                ->constrained('inspection_types')->nullOnDelete();

            // Provenance of `kind`: catalog (user pick) | resolver (backfill) | import | manual.
            $table->string('classification_source', 20)->default('resolver')->after('inspection_type_id');
            // Resolver/import could not confidently classify — surfaced to an admin review queue.
            $table->boolean('needs_review')->default(false)->index()->after('classification_source');
        });

        // Last-line enforcement: exactly the catalog FK matching `kind` is set, OR all null (legacy).
        // MariaDB 10.4 enforces CHECK constraints (support since 10.2.1).
        DB::statement(<<<'SQL'
            ALTER TABLE maintenance_tasks ADD CONSTRAINT chk_task_kind_catalog CHECK (
                 (kind = 'fault'      AND fault_catalog_id   IS NOT NULL AND service_catalog_id IS NULL AND inspection_type_id IS NULL)
              OR (kind = 'service'    AND service_catalog_id IS NOT NULL AND fault_catalog_id   IS NULL AND inspection_type_id IS NULL)
              OR (kind = 'inspection' AND inspection_type_id IS NOT NULL AND fault_catalog_id   IS NULL AND service_catalog_id IS NULL)
              OR (fault_catalog_id IS NULL AND service_catalog_id IS NULL AND inspection_type_id IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        // Drop the CHECK first (columns it references cannot be dropped while it exists).
        DB::statement('ALTER TABLE maintenance_tasks DROP CONSTRAINT chk_task_kind_catalog');

        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fault_catalog_id');
            $table->dropConstrainedForeignId('service_catalog_id');
            $table->dropConstrainedForeignId('inspection_type_id');
            $table->dropColumn(['kind', 'classification_source', 'needs_review']);
        });
    }
};
