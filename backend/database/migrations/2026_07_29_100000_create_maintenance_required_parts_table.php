<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * maintenance_required_parts — the INSPECTOR'S TECHNICAL LAYER, deliberately separate from procurement.
 *
 * When the inspector files a report he answers a technical question only: "what will this repair need?".
 * That answer lands here — a part name, a quantity, a note. It is NOT a purchase order, NOT an approval
 * request, and it starts NO procurement: the inspector cannot know which garage will do the work, whether
 * that garage supplies its own parts, which supplier we would buy from, or what the budget allows.
 *
 * Those are the maintenance coordinator's calls, made AFTER the garage is chosen. At that point the
 * coordinator converts the lines he still wants into real `part_requests` (the procurement spine), and the
 * link is kept in `part_request_required_part` so every purchased part traces back to the inspection that
 * asked for it. A line the coordinator does not need — the garage supplies it, it was wrong, it can wait —
 * is DISMISSED with a reason rather than deleted, so the inspector's judgement stays on the record.
 *
 * Bridging to the fault: at report time faults are still `findings` JSON on the ticket; they only become
 * first-class maintenance_tasks when the report is approved (MaintenanceTaskService::syncFromFindings).
 * So a line stores `finding_key` (the normalised symptom text, the same key that service matches on) and
 * its `maintenance_task_id` is backfilled the moment the matching fault row exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_required_parts', function (Blueprint $table) {
            $table->id();

            // The ticket the inspection belongs to. Required — a required part is always about one job.
            $table->foreignId('maintenance_id')->constrained('maintenances')->cascadeOnDelete();
            // Denormalised for vehicle-scoped history queries without a join through the ticket.
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            // The specific fault this part serves. Nullable: at report time the fault is still a finding,
            // so this is backfilled once findings are promoted to tasks. nullOnDelete keeps the line's
            // history if the fault row is later cleaned up.
            $table->foreignId('maintenance_task_id')->nullable()->constrained('maintenance_tasks')->nullOnDelete();
            // Normalised symptom text of the originating finding — the bridge used to resolve the FK above.
            // Lowercased purely so it matches; never show it to a user.
            $table->string('finding_key', 191)->nullable()->index();
            // The inspector's own wording of that finding, kept verbatim for display and for the reason
            // carried into the part request. Only the key is normalised; what he typed stays what he typed.
            $table->string('finding_text', 500)->nullable();

            // --- what the repair needs (technical, inspector's words) ---
            $table->string('part_name');                     // free text, e.g. "Front brake disc"
            $table->text('notes')->nullable();               // why / which side / what to watch for
            $table->decimal('quantity', 10, 2)->default(1);
            // How urgent the part is for THIS repair. Deliberately its own scale, not fault severity:
            // a routine fault can still need a part urgently, and vice versa.
            $table->string('priority', 12)->nullable();      // 'urgent' | 'high' | 'normal' | 'low'

            // --- lifecycle of the technical line itself (NOT the procurement lifecycle) ---
            //   pending   → awaiting the coordinator's decision (the default on save)
            //   requested → converted into one or more part_requests; procurement owns it now
            //   dismissed → the coordinator decided we do not source it (garage supplies it, not needed, deferred)
            $table->string('status', 12)->default('pending')->index();

            // Audit: the inspector who recorded it.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_by_name')->nullable();  // snapshot so a removed user never breaks history
            $table->timestamp('recorded_at')->nullable();

            // Audit: the coordinator who converted or dismissed it.
            $table->foreignId('actioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actioned_by_name')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->text('dismissal_reason')->nullable();

            $table->timestamps();

            $table->index(['maintenance_id', 'status']);
            $table->index(['vehicle_id', 'status']);
        });

        // A required-part line and a part request are MANY-TO-MANY on purpose. One line can be split across
        // several requests (part-sourced twice, different suppliers), and one request can cover several
        // lines — the coordinator merging the same part asked for by two different faults into a single buy.
        Schema::create('part_request_required_part', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_request_id')->constrained('part_requests')->cascadeOnDelete();
            $table->foreignId('maintenance_required_part_id')->constrained('maintenance_required_parts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['part_request_id', 'maintenance_required_part_id'], 'prrp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_request_required_part');
        Schema::dropIfExists('maintenance_required_parts');
    }
};
