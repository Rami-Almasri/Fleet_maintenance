<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset Layer — shadow-launch trust markers (docs/Asset-Layer-Shadow-Launch-Plan.md §7.1).
 *
 * Shadow-created components are PROVISIONAL observations until the shadow gate passes and they are
 * explicitly promoted. Every row therefore carries:
 *   write_mode        — which flag regime wrote it (off | shadow | enforced; backfill later) —
 *                       the "created by the shadow asset layer" marker.
 *   validation_status — provisional (default) | validated | quarantined. Quarantined rows are
 *                       known-bad, KEPT for audit, excluded from every future read surface.
 *   validated_at/by   — the promotion stamp (components:shadow-audit --promote).
 *
 * Added BEFORE the first production deploy so no production row ever exists without the marker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_components', function (Blueprint $table) {
            $table->string('write_mode', 12)->nullable()->after('source');
            $table->string('validation_status', 12)->default('provisional')->index()->after('write_mode');
            $table->dateTime('validated_at')->nullable()->after('validation_status');
            $table->foreignId('validated_by')->nullable()->after('validated_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_components', function (Blueprint $table) {
            $table->dropConstrainedForeignId('validated_by');
            $table->dropColumn(['write_mode', 'validation_status', 'validated_at']);
        });
    }
};
