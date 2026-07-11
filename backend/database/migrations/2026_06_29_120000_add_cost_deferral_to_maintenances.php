<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financial Decoupling (Deferred Cost). The final repair cost is no longer required to CLOSE a
 * maintenance workflow ticket — it is recorded LATER, once the invoice paperwork is processed
 * (see MaintenanceWorkflowService::recordCost + POST /maintenance-tickets/{ticket}/cost). These two
 * columns stamp WHO entered the deferred cost and WHEN, so the late entry stays fully auditable and
 * the dashboard can tell a "cost pending" ticket from one whose cost was filled in after the fact.
 *
 * The per-fault "Actual Repair Time" needs no column: it is stored as `repair_hours` inside each
 * object of the existing `findings` JSON, so a fault tag carries its own repair time in one place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->timestamp('cost_recorded_at')->nullable()->after('cost');
            $table->unsignedBigInteger('cost_recorded_by')->nullable()->after('cost_recorded_at');
            $table->index('cost_recorded_at');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropIndex(['cost_recorded_at']);
            $table->dropColumn(['cost_recorded_at', 'cost_recorded_by']);
        });
    }
};
