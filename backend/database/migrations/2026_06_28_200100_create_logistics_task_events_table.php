<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-Audit for Logistics Dispatch — an append-only trail of every status change on a move, so the
 * board can answer "who did what, when, and from where" without a WhatsApp scroll. One row per
 * transition (claimed / picked up / delivered / returned / cancelled / status update), each stamping
 * the driver's id + name snapshot and the moment it happened. The "Returned / Arrived" event also
 * carries the GPS fix that verifies the car is physically back at base.
 *
 * Mirrors vehicle_log_events: self-contained snapshots, loose ids (indexed, no hard FK) so a row stays
 * meaningful after a re-sync or a user rename.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_task_events', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('logistics_task_id')->index();
            $table->unsignedBigInteger('vehicle_id')->nullable()->index(); // snapshot for vehicle-anchored history

            // What happened: the lifecycle verb + the phases it moved between (for a clean timeline).
            $table->string('event', 30);                 // claimed | picked_up | delivered | returned | cancelled | status_update | dispatched
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();

            // Who did it (snapshot so the trail stands alone).
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();

            // GPS verification — only on the "Returned / Arrived" step (null everywhere else).
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('accuracy', 8, 2)->nullable(); // metres

            $table->string('note')->nullable();
            $table->timestamp('occurred_at')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_task_events');
    }
};
