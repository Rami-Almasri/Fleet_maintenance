<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset Layer, Phase 1 — the ONLY existing-table touch: two nullable refs on maintenance_media so
 * before/after part photos ride the existing upload pipeline and viewers. Photos attach to the
 * component (portrait shots) or to a specific install/removal event (evidence for warranty claims
 * and liability). Purely additive; every existing media row is untouched (both columns NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_media', function (Blueprint $table) {
            $table->foreignId('vehicle_component_id')->nullable()
                ->constrained('vehicle_components')->nullOnDelete();
            $table->foreignId('component_event_id')->nullable()
                ->constrained('component_events')->nullOnDelete();
            // No explicit index calls: the FK constraints already index both columns.
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_media', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_component_id');
            $table->dropConstrainedForeignId('component_event_id');
        });
    }
};
