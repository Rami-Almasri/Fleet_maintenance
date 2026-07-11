<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Findings keyword library — now with a per-keyword RISK grade.
 *
 * The quick-pick issue keywords the Inspector / workshop tap used to live purely in
 * config/maintenance_findings.php as bare strings, with no notion of how serious each fault is.
 * This table promotes that catalog into an admin-editable list (mirroring the fault_causes design:
 * config seeds it, the DB is the runtime source of truth) and adds a `risk` classification per
 * keyword — critical / moderate / routine — reusing the exact same scale as a ticket's
 * `fault_severity` (Maintenance::FAULT_SEVERITY_META) so the keyword's baseline risk maps 1:1 onto
 * the severity a supervisor reads on the board.
 *
 * `risk` is the *default/expected* seriousness of that fault type (e.g. "Soft / spongy pedal" =
 * critical). It's a catalog attribute; the inspector still grades the actual ticket at report time.
 * Keywords still persist onto maintenances.findings exactly as before (a plain string) — this table
 * is the menu + its metadata, so nothing about storage or analytics changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finding_keywords', function (Blueprint $table) {
            $table->id();

            // Which findings category this keyword belongs to. `category_key` is the stable slug
            // (engine / brakes / …) carried from the config; `category_label` is what the UI prints.
            $table->string('category_key', 60)->index();
            $table->string('category_label', 80);

            // The keyword itself — exactly the string saved into maintenances.findings. Capped at 191
            // so the composite unique index stays within InnoDB's utf8mb4 key-length limit.
            $table->string('keyword', 191);

            // Baseline risk of this fault type. Same vocabulary as Maintenance::FAULT_SEVERITIES so a
            // keyword's risk can seed / colour a ticket's fault_severity. Indexed for the risk filter.
            $table->string('risk', 20)->default('moderate')->index();

            // Optional admin-facing detail: what the fault means / how it presents / what to check.
            $table->string('description', 500)->nullable();

            // Soft on/off so a keyword can be retired from the picker without losing historical rows.
            $table->boolean('is_active')->default(true)->index();

            // Manual ordering hint within a category (lower = first); ties break on keyword.
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // One row per (category, keyword): the seeder upserts on this pair, and a duplicate
            // admin entry is rejected rather than piling up.
            $table->unique(['category_key', 'keyword'], 'finding_keywords_category_keyword_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finding_keywords');
    }
};
