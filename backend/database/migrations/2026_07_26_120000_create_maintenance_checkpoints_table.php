<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maintenance Checkpoint — one progress report submitted by a responsible user (Waleed/Abdullah, or
 * whoever a ticket assigns) while a car is in the workshop. It turns "the car is somewhere in the
 * garage" into a dated, evidence-backed timeline: an OUTCOME (on_track / delayed / critical) that
 * drives the dashboard colours, a workshop STATUS (waiting_parts / under_repair / …), a structured
 * DELAY REASON when things slip, a free-text summary, an optional pushed-back completion date, and
 * photos/videos (via maintenance_media.maintenance_checkpoint_id). The Checkpoint Scan command chases
 * these updates before a job goes overdue. See [[maintenance-workflow-engine]].
 *
 * Loose `maintenance_id` index (no hard FK), mirroring maintenance_media / maintenance_line_items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maintenance_id')->index();
            // Denormalised so the Vehicle Profile timeline + dashboard can read a car's checkpoints
            // without joining through the (possibly rotating) maintenance row.
            $table->unsignedBigInteger('vehicle_id')->nullable()->index();

            // The management-facing verdict that drives dashboard colour + reporting (never parsed from
            // free text): on_track | delayed | critical. Required at submit.
            $table->string('outcome', 20);

            // The workshop's current stage: waiting_parts | under_repair | painting | testing |
            // ready_today | delayed | other.
            $table->string('status', 30)->nullable();

            // Structured delay reason (required when outcome = delayed): waiting_parts | workshop_busy |
            // additional_damage | customer_approval | insurance_approval | vendor_delay | other.
            $table->string('delay_reason', 40)->nullable();
            // Free-text explanation, required only when delay_reason = other.
            $table->string('delay_reason_other', 255)->nullable();

            $table->text('summary')->nullable();

            // The (possibly pushed-back) date the garage now promises the car ready. When set, the ticket's
            // expected_completion_date is advanced to match, so the ETA gauge + escalation reset around it.
            $table->date('next_expected_date')->nullable();

            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->string('submitted_by_name')->nullable();

            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_checkpoints');
    }
};
