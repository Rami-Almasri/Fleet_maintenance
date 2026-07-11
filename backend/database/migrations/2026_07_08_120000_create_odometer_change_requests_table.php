<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Odometer Modification Approval — a review queue for SIGNIFICANT manual odometer edits.
 *
 * When someone edits a car's odometer and the change from the current stored value is larger than
 * OdometerChangeRequest::SIGNIFICANT_DELTA_KM (in either direction), the edit is NOT applied to the
 * vehicle straight away. Instead it lands here as a `pending` row carrying the reason note the editor
 * was forced to write, the before/after readings, and a snapshot of the car's workflow (operational)
 * stage at the time. An admin then approves it (which writes the reading onto the vehicle) or rejects
 * it (leaving the odometer untouched). Small edits still apply immediately and never touch this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odometer_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();

            // The readings: what the car was on before, what the editor wants it to be, and the signed gap.
            $table->unsignedBigInteger('previous_odometer')->nullable(); // null = car had no reading yet
            $table->unsignedBigInteger('requested_odometer');
            $table->bigInteger('delta'); // requested - previous (can be negative — a downward correction)

            // The mandatory reason the editor typed to justify the big jump/drop.
            $table->text('note');

            // Snapshot of the car's live workflow/operational stage when the change was requested, so the
            // reviewer sees the context (e.g. "In Maintenance") even if the car moves on before review.
            $table->string('workflow_stage')->nullable();

            // pending → approved | rejected. Pending rows are the review queue.
            $table->string('status')->default('pending')->index();

            // Who asked (name is denormalised so the queue reads cleanly without a users join).
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by')->nullable();

            // Who reviewed, when, and any note they left (e.g. why they rejected).
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odometer_change_requests');
    }
};
