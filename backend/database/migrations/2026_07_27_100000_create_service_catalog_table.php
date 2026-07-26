<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * service_catalog — the menu of PLANNED / PREVENTIVE work (Event Type layer, Phase 0).
 *
 * Every row is implicitly kind=service. A maintenance_task pointing at a service_catalog row is a
 * scheduled/preventive job (oil, filter, tyre rotation), NEVER a fault. `slug` is the stable machine
 * key (seeder upserts by it); `service_reminder_type` links back to ServiceReminder::TYPE_LABELS so
 * completing the service still rolls its reminder forward. Retire via is_active=false, never delete.
 * See docs/Service-vs-Fault-Domain-Separation.md §3.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_catalog', function (Blueprint $table) {
            $table->id();

            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('name_ar', 120)->nullable();

            // Same vocabulary as the findings/component catalogs for join-friendliness.
            $table->string('category_key', 40)->index();

            // Preventive cadence (either / both may be null for on-demand services).
            $table->unsignedInteger('interval_km')->nullable();
            $table->unsignedSmallInteger('interval_months')->nullable();

            // Link key into ServiceReminder::TYPE_LABELS (null = no reminder loop).
            $table->string('service_reminder_type', 40)->nullable();
            // Future Asset-Layer link to component_catalog.slug (P4).
            $table->string('component_slug', 120)->nullable();

            $table->decimal('default_labor_hours', 6, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_catalog');
    }
};
