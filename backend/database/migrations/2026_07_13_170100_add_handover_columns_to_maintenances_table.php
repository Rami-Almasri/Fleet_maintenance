<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise Handover Workflow — the current-state pointers a paused ticket needs on top of the
 * existing pause bookkeeping (paused_from_status/paused_at/paused_by/paused_reason, kept as-is):
 * when the vehicle was physically handed back, who took it, the open discrepancy incident (if any),
 * and quick pointers to the most recent pause/resume handover rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->timestamp('vehicle_returned_at')->nullable()->after('paused_reason');
            $table->unsignedBigInteger('vehicle_returned_by')->nullable()->after('vehicle_returned_at');
            $table->unsignedBigInteger('active_incident_id')->nullable()->after('vehicle_returned_by');
            $table->unsignedBigInteger('last_pause_handover_id')->nullable()->after('active_incident_id');
            $table->unsignedBigInteger('last_resume_handover_id')->nullable()->after('last_pause_handover_id');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn([
                'vehicle_returned_at',
                'vehicle_returned_by',
                'active_incident_id',
                'last_pause_handover_id',
                'last_resume_handover_id',
            ]);
        });
    }
};
