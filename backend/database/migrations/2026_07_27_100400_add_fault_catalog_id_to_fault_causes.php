<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link the Symptom → Root-Cause KB to the Fault Catalog (Event Type layer, Phase 0).
 *
 * `fault_causes` gains a nullable FK to `fault_catalog`. The existing free-text `symptom_key` is kept
 * IN PARALLEL for this phase (cut-over to fault_catalog_id happens in a later PR) so nothing that reads
 * `symptom_key` today breaks. See docs/Service-vs-Fault-Domain-Separation.md §3.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fault_causes', function (Blueprint $table) {
            $table->foreignId('fault_catalog_id')->nullable()->after('category_key')
                ->constrained('fault_catalog')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fault_causes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fault_catalog_id');
        });
    }
};
