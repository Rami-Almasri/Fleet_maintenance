<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Driver Handover Observation — the LIGHTWEIGHT, internal path. When our driver receives a car back from
 * a customer and notices something ("customer mentioned the steering vibrates", "I heard an engine noise
 * bringing it in"), that is NOT a customer complaint — nobody calls the customer, nobody escalates. It is
 * a recorded observation that MAY create an inspection request. The lifecycle is short:
 *
 *     observation → inspection review → inspection → maintenance (if needed) → closed
 *
 * Deliberately spartan next to `complaints`: no customer contact, no decision fork, no Abu-Maroof
 * complaint alerts. If it needs a look, it spawns an inspection request and we store the link in
 * `inspection_request_id` (a maintenance ticket in pending_review). See [[driver-observation-entity]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_observations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id')->index();

            // The driver who received the car and noticed / relayed the observation.
            $table->unsignedBigInteger('driver_id')->nullable()->index();

            // The rental the car just came back from, if known (context only — no customer follow-up).
            $table->unsignedBigInteger('contract_id')->nullable();

            $table->text('note');
            // Optional single photo path (S3 key / url) the driver snapped.
            $table->string('photo')->nullable();

            // open = logged, nothing raised yet | inspection_requested = spawned an inspection | dismissed.
            $table->string('status', 20)->default('open')->index();

            // The inspection request (a maintenance ticket in pending_review) this observation spawned, if any.
            $table->unsignedBigInteger('inspection_request_id')->nullable()->index();

            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_observations');
    }
};
