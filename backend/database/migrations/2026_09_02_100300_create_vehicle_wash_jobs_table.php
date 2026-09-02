<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A WASH — THE RECORD BEHIND A CLEANING STATUS CHANGE.
 *
 * FleetView already has a cleaning workflow: CleaningController flips `vehicles.cleaning_status`,
 * before/after photos are captured as InspectionRecords, and Booking Readiness reads the status as a
 * rental blocker. What it did not have was a record of an INDIVIDUAL wash. `cleaning_status` is one
 * field holding the car's current state, so a car washed forty times has one field and no history —
 * and a wash somebody paid an external company for had nowhere to be recorded at all.
 *
 * This table is that record, and it is operational before it is financial:
 *
 *   washed_at + wash_type   the wash history a fleet manager asks for ("when was it last detailed?")
 *   sets the cleaning status via the EXISTING path, so Booking Readiness keeps working unchanged
 *   cost + vendor           present only when the wash was bought from someone
 *
 * An INTERNAL wash (our own staff, our own bay) costs nothing external and raises no obligation — its
 * `cost` stays null and {@see \App\Models\VehicleWashJob::financialExpenseType()} returns null, so the
 * row exists as history with no financial event. That is the honest outcome and it is why cost is
 * nullable rather than defaulted to zero: "nobody was billed" and "billed nothing" are different facts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_wash_jobs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('vehicle_id');
            $table->dateTime('washed_at');

            // exterior | interior | full | detailing — the operational description of what was done.
            $table->string('wash_type', 32)->default('exterior');
            // internal (our own bay) | external (bought from a car wash). Only an external wash can
            // carry a cost, and only a costed wash raises a financial obligation.
            $table->string('performed_by_kind', 16)->default('external');

            $table->unsignedBigInteger('vendor_id')->nullable();

            // Nullable, NOT default 0 — see the class docblock: an internal wash was not billed at all,
            // which is a different statement from "billed nothing".
            $table->decimal('cost', 10, 2)->nullable();
            $table->string('currency', 8)->nullable();

            $table->string('invoice_no', 128)->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('receipt_disk', 32)->nullable();
            $table->string('receipt_key')->nullable();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'washed_at'], 'wash_vehicle_date_idx');
            $table->index('vendor_id', 'wash_vendor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_wash_jobs');
    }
};
