<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inspection_types — the menu of CHECKS (Event Type layer, Phase 0).
 *
 * Every row is implicitly kind=inspection. A maintenance_task pointing here is a check, not a fault or
 * service. An inspection that finds something creates a SEPARATE kind=fault task (linked via
 * derived_from_task_id); the inspection row never becomes a fault. Retire via is_active=false, never
 * delete. See docs/Service-vs-Fault-Domain-Separation.md §3.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_types', function (Blueprint $table) {
            $table->id();

            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('name_ar', 120)->nullable();

            // Which checklist template to render (null = free-form).
            $table->string('checklist_key', 60)->nullable();
            $table->boolean('expects_measurements')->default(false);
            $table->boolean('may_spawn_fault')->default(true);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_types');
    }
};
