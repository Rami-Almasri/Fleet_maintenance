<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * fault_catalog — the menu of unplanned FAILURES / DEFECTS (Event Type layer, Phase 0).
 *
 * Every row is implicitly kind=fault. A maintenance_task pointing at a fault_catalog row is a real
 * failure that counts in Top Faults, recurrence, health/reliability/risk and fault KPIs. Evolves the
 * non-routine categories of config/maintenance_findings.php; `fault_causes.fault_catalog_id` (added in
 * a later migration) references this. `default_severity` is a prefill hint only. Retire via
 * is_active=false, never delete. See docs/Service-vs-Fault-Domain-Separation.md §3.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fault_catalog', function (Blueprint $table) {
            $table->id();

            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('name_ar', 120)->nullable();

            $table->string('category_key', 40)->index();

            // Suggested fault_severity prefill (Maintenance::FAULT_SEVERITIES) — descriptive, editable.
            $table->string('default_severity', 10)->nullable();
            // Mobile-fixable hint (migrated from the findings catalog on_site flag).
            $table->boolean('on_site')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fault_catalog');
    }
};
