<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * resolved_transfer_flags — the Oversight log of a specific accountability case: the Supervisor moved a
 * car to ANOTHER garage even though EVERY fault on the ticket was already fixed (tasksProgress.all_resolved).
 *
 * Normally a garage transfer means "work still needs doing at a different garage". When all faults are
 * already resolved, transferring the car is unusual — it may be a routine hand-off, a storage move, or a
 * mistake — so the move is gated behind a MANDATORY justification note and recorded here for review on the
 * /oversight/resolved-transfers board. Pure audit trail: writing a row never blocks the transfer (the note
 * gate does that, in the controller); this just captures who/what/why after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resolved_transfer_flags', function (Blueprint $table) {
            $table->id();
            // The ticket the transfer happened on. Cascade: if the ticket is deleted the flag goes with it.
            $table->foreignId('maintenance_id')->constrained('maintenances')->cascadeOnDelete();
            // Denormalised for a fast per-car view without joining back through the ticket.
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();

            // Where the car moved FROM → TO. Ids for the live link + snapshot names so the row still reads
            // correctly if a vendor is later renamed/removed.
            $table->foreignId('from_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('from_garage', 191)->nullable();
            $table->foreignId('to_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('to_garage', 191)->nullable();

            // The MANDATORY justification the Supervisor left explaining why an all-fixed car was moved.
            $table->text('note');
            // The current odometer captured on the transfer (the Mileage Gate reading), for context.
            $table->unsignedInteger('odometer')->nullable();

            // Who performed the transfer (the Supervisor). Nullable so a system move never fails to log.
            $table->foreignId('flagged_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('maintenance_id');
            $table->index('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resolved_transfer_flags');
    }
};
