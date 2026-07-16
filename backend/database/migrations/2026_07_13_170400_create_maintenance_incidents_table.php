<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Handover Comparison that breaches its configured thresholds (config/maintenance_handover.php)
 * raises an Incident that must be ACKNOWLEDGED before the resume can actually finalize — no silent
 * continuation. See MaintenanceWorkflowService::resumeMaintenance()/acknowledgeIncident().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_incidents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maintenance_id')->index();
            $table->unsignedBigInteger('comparison_id')->nullable();
            $table->string('type', 40)->default('handover_discrepancy');
            $table->string('severity', 20)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('open'); // open | acknowledged
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('acknowledgement_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_incidents');
    }
};
