<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise Handover Workflow — one immutable, insert-only row per custody-transfer EVENT (the
 * pause leg, when the car leaves; the resume leg, when it comes back). Never updated after creation —
 * the permanent evidence a HandoverComparisonService diff is built from. Odometer/damage photos reuse
 * the existing InspectionRecord pattern (see MaintenanceWorkflowController::storeOdometerPhoto()) —
 * this table only stores the typed reading + condition data, plus the signature capture.
 *
 * Loose unsignedBigInteger links (no FK constraint) — mirrors maintenance_media / maintenance_line_items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_handovers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maintenance_id')->index();
            $table->unsignedBigInteger('vehicle_id')->index();
            $table->string('type', 10); // 'pause' | 'resume'
            $table->unsignedInteger('odometer_reading');
            // Deferred: OCR is not implemented in this codebase yet (no provider chosen). The reading
            // above is captured manually today; this column lets a provider be wired in later without
            // a schema change.
            $table->unsignedInteger('odometer_ocr_reading')->nullable();
            $table->unsignedBigInteger('odometer_photo_inspection_record_id')->nullable();
            $table->string('fuel_level', 10); // E | 1/4 | 1/2 | 3/4 | F
            $table->string('exterior_condition', 50);
            $table->string('interior_condition', 50);
            $table->json('damage_findings')->nullable();     // [{location, severity, note, photo_inspection_record_id}]
            $table->json('missing_accessories')->nullable(); // [accessory_key, ...]
            $table->text('notes')->nullable();
            $table->string('signature_path', 1024)->nullable();
            $table->string('signature_disk', 30)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('occurred_at');
            $table->string('workflow_status_snapshot', 40)->nullable();
            $table->text('reason')->nullable(); // only meaningful on 'pause'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_handovers');
    }
};
